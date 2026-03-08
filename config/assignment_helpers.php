<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/notification_functions.php';

function normalize_assignment_position(?string $position): string {
    $normalized = strtolower(trim((string) $position));
    $normalized = str_replace(['-', ' '], '_', $normalized);
    return preg_replace('/_+/', '_', $normalized) ?? '';
}

function assignment_preferred_positions_for_order(array $order): array {
    $status = strtolower(trim((string) ($order['status'] ?? '')));
    $service_type = strtolower(trim((string) ($order['service_type'] ?? '')));
    $design_description = strtolower(trim((string) ($order['design_description'] ?? '')));

    $quote_details = [];
    if(!empty($order['quote_details'])) {
        $decoded = json_decode((string) $order['quote_details'], true);
        if(is_array($decoded)) {
            $quote_details = $decoded;
        }
    }

    $item_type = strtolower(trim((string) ($quote_details['item_type'] ?? $quote_details['product_type'] ?? '')));
    $design_related = str_contains($service_type, 'design')
        || str_contains($design_description, 'design')
        || str_contains($service_type, 'digit')
        || $status === STATUS_DIGITIZING;
    $is_cap_order = str_contains($item_type, 'cap')
        || str_contains($service_type, 'cap')
        || str_contains($design_description, 'cap');

    if($design_related || $status === STATUS_DIGITIZING) {
        return ['digitizer', 'designer', 'design_staff'];
    }

    if($status === STATUS_IN_PROGRESS || $is_cap_order) {
        return ['embroidery_operator', 'embroidery_technician', 'operator', 'production_staff', 'cap_operator'];
    }

    return ['embroidery_operator', 'operator', 'production_staff', 'staff'];
}

function get_assignable_staff_for_order(PDO $pdo, int $order_id): array {
    $order_stmt = $pdo->prepare("SELECT id, shop_id, status, service_type, design_description, quote_details FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return [];
    }

    $preferred_positions = assignment_preferred_positions_for_order($order);

    $staff_stmt = $pdo->prepare("
        SELECT
            ss.user_id,
            u.fullname,
            ss.position,
            ss.staff_role,
            ss.max_active_orders,
            (
                SELECT COUNT(*)
                FROM orders o2
                WHERE o2.shop_id = ss.shop_id
                  AND o2.assigned_to = ss.user_id
                  AND o2.status IN ('pending', 'accepted', 'digitizing', 'in_progress')
            ) AS active_workload
        FROM shop_staffs ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.shop_id = ?
          AND ss.status = 'active'
          AND ss.staff_role = 'staff'
          AND u.status = 'active'
        ORDER BY u.fullname ASC
    ");
    $staff_stmt->execute([(int) $order['shop_id']]);
    $staff_rows = $staff_stmt->fetchAll();

    $candidates = [];
    foreach($staff_rows as $staff) {
        $max_active_orders = (int) ($staff['max_active_orders'] ?? 0);
        $active_workload = (int) ($staff['active_workload'] ?? 0);

        if($max_active_orders > 0 && $active_workload >= $max_active_orders) {
            continue;
        }

        $position_key = normalize_assignment_position($staff['position'] ?? '');
        $position_match = in_array($position_key, $preferred_positions, true);

        $staff['active_workload'] = $active_workload;
        $staff['position_match'] = $position_match ? 1 : 0;
        $staff['preferred_positions'] = $preferred_positions;
        $staff['score'] = ($position_match ? 100 : 0) - ($active_workload * 10);
        $candidates[] = $staff;
    }

    usort($candidates, static function(array $a, array $b): int {
        if((int) $a['position_match'] !== (int) $b['position_match']) {
            return (int) $b['position_match'] <=> (int) $a['position_match'];
        }

        if((int) $a['active_workload'] !== (int) $b['active_workload']) {
            return (int) $a['active_workload'] <=> (int) $b['active_workload'];
        }

        return strcmp((string) $a['fullname'], (string) $b['fullname']);
    });

    return $candidates;
}

function choose_best_staff_assignee(PDO $pdo, int $order_id): ?array {
    $candidates = get_assignable_staff_for_order($pdo, $order_id);
    return $candidates[0] ?? null;
}

function assign_order_to_staff(PDO $pdo, int $order_id, int $staff_user_id, int $assigned_by): bool {
    if($order_id <= 0 || $staff_user_id <= 0 || $assigned_by <= 0) {
        return false;
    }

    $order_stmt = $pdo->prepare("SELECT id, order_number, shop_id, assigned_to FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return false;
    }

    $staff_stmt = $pdo->prepare("
        SELECT ss.user_id, ss.position, ss.status, ss.shop_id, ss.max_active_orders,
            (
                SELECT COUNT(*)
                FROM orders o2
                WHERE o2.shop_id = ss.shop_id
                  AND o2.assigned_to = ss.user_id
                  AND o2.status IN ('pending', 'accepted', 'digitizing', 'in_progress')
            ) AS active_workload
        FROM shop_staffs ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.user_id = ?
          AND ss.shop_id = ?
          AND ss.status = 'active'
          AND ss.staff_role = 'staff'
          AND u.status = 'active'
        LIMIT 1
    ");
    $staff_stmt->execute([$staff_user_id, (int) $order['shop_id']]);
    $staff = $staff_stmt->fetch();

    if(!$staff) {
        return false;
    }

    $is_same_assignee = (int) ($order['assigned_to'] ?? 0) === $staff_user_id;
    $max_active_orders = (int) ($staff['max_active_orders'] ?? 0);
    $active_workload = (int) ($staff['active_workload'] ?? 0);
    if($max_active_orders > 0 && $active_workload >= $max_active_orders && !$is_same_assignee) {
        return false;
    }

    $update_stmt = $pdo->prepare("UPDATE orders SET assigned_to = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?");
    $updated = $update_stmt->execute([$staff_user_id, $order_id, (int) $order['shop_id']]);

    if(!$updated) {
        return false;
    }

    create_notification(
        $pdo,
        $staff_user_id,
        $order_id,
        'order_status',
        'You have been assigned to order #' . $order['order_number'] . '.'
    );

    return true;
}
?>
