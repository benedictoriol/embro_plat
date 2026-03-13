<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/queue_helpers.php';

function assignment_active_workload_statuses(): array {
    return [
        STATUS_PENDING,
        STATUS_ACCEPTED,
        STATUS_DIGITIZING,
        STATUS_PRODUCTION_PENDING,
        STATUS_PRODUCTION,
        STATUS_PRODUCTION_REWORK,
        STATUS_QC_PENDING,
        STATUS_IN_PROGRESS,
    ];
}

function assignment_status_placeholders(array $statuses): string {
    if(empty($statuses)) {
        return "'accepted'";
    }

    return implode(', ', array_fill(0, count($statuses), '?'));
}

function assignment_auto_enabled_for_shop(PDO $pdo, int $shop_id): bool {
    if($shop_id <= 0) {
        return false;
    }

    $shop_stmt = $pdo->prepare("SELECT service_settings FROM shops WHERE id = ? LIMIT 1");
    $shop_stmt->execute([$shop_id]);
    $service_settings_raw = $shop_stmt->fetchColumn();

    if(!is_string($service_settings_raw) || trim($service_settings_raw) === '') {
        return true;
    }

    $service_settings = json_decode($service_settings_raw, true);
    if(!is_array($service_settings)) {
        return true;
    }

    $flags = [
        $service_settings['auto_assignment_enabled'] ?? null,
        $service_settings['auto_assign_orders'] ?? null,
        $service_settings['workflow']['auto_assignment_enabled'] ?? null,
    ];

    foreach($flags as $flag) {
        if($flag === null) {
            continue;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    return true;
}

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

    if(in_array($status, [STATUS_PRODUCTION, STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION_REWORK, STATUS_IN_PROGRESS], true) || $is_cap_order) {
        return ['embroidery_operator', 'embroidery_technician', 'operator', 'production_staff', 'cap_operator'];
    }

    if($status === STATUS_QC_PENDING) {
        return ['quality_control', 'qc', 'qa', 'inspector'];
    }

    return ['embroidery_operator', 'operator', 'production_staff', 'staff'];
}


function assignment_eligible_queue_statuses(): array {
    return [
        STATUS_ACCEPTED,
        STATUS_DIGITIZING,
        STATUS_PRODUCTION_PENDING,
        STATUS_PRODUCTION,
        STATUS_PRODUCTION_REWORK,
        STATUS_QC_PENDING,
        STATUS_IN_PROGRESS,
    ];
}

function assignment_order_is_eligible(array $order): bool {
    $status = strtolower(trim((string) ($order['status'] ?? '')));

    if(in_array($status, [STATUS_COMPLETED, STATUS_CANCELLED], true)) {
        return false;
    }

    return in_array($status, assignment_eligible_queue_statuses(), true);
}

function assignment_availability_match(array $staff): bool {
    $availability_days = [];
    if(!empty($staff['availability_days'])) {
        $decoded = json_decode((string) $staff['availability_days'], true);
        if(is_array($decoded)) {
            $availability_days = array_map('intval', $decoded);
        }
    }

    $dayOfWeek = (int) date('N');
    if(!empty($availability_days) && !in_array($dayOfWeek, $availability_days, true)) {
        return false;
    }

    $start = trim((string) ($staff['availability_start'] ?? ''));
    $end = trim((string) ($staff['availability_end'] ?? ''));
    if($start !== '' && $end !== '') {
        $now = date('H:i:s');
        $startTime = strlen($start) === 5 ? $start . ':00' : $start;
        $endTime = strlen($end) === 5 ? $end . ':00' : $end;
        if($startTime > $endTime) {
            if(!($now >= $startTime || $now <= $endTime)) {
                return false;
            }
        } elseif($now < $startTime || $now > $endTime) {
            return false;
        }
    }

    return true;
}

function assignment_skill_score(array $order, array $staff): int {
    $orderSkillHints = [];
    $status = strtolower(trim((string) ($order['status'] ?? '')));
    $serviceType = strtolower(trim((string) ($order['service_type'] ?? '')));
    $designDescription = strtolower(trim((string) ($order['design_description'] ?? '')));

    if($status === STATUS_DIGITIZING || str_contains($serviceType, 'digit') || str_contains($designDescription, 'digit')) {
        $orderSkillHints[] = 'digitizing';
    }
    if(in_array($status, [STATUS_PRODUCTION, STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION_REWORK, STATUS_IN_PROGRESS], true)
        || str_contains($serviceType, 'embroider')) {
        $orderSkillHints[] = 'embroidery';
        $orderSkillHints[] = 'production';
    }
    if($status === STATUS_QC_PENDING) {
        $orderSkillHints[] = 'qc';
        $orderSkillHints[] = 'quality';
    }

    $staffSkillBlob = strtolower(trim((string) ($staff['skills_data'] ?? '')));
    if($staffSkillBlob === '') {
        return 0;
    }

    foreach($orderSkillHints as $hint) {
        if(str_contains($staffSkillBlob, $hint)) {
            return 1;
        }
    }

    return 0;
}

function get_assignable_staff_for_order(PDO $pdo, int $order_id): array {
    $order_stmt = $pdo->prepare("SELECT id, shop_id, status, service_type, design_description, quote_details, assigned_to FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return [];
    }

    if(!assignment_order_is_eligible($order)) {
        return [];
    }

    $preferred_positions = assignment_preferred_positions_for_order($order);
    $active_statuses = assignment_active_workload_statuses();
    $workload_placeholders = assignment_status_placeholders($active_statuses);

    $staff_stmt = $pdo->prepare("\n        SELECT
            ss.user_id,
            u.fullname,
            ss.position,
            ss.staff_role,
            ss.max_active_orders,
            ss.availability_days,
            ss.availability_start,
            ss.availability_end,
            ss.permissions,
            CONCAT_WS(' ', ss.position, ss.permissions) AS skills_data,
            (
                SELECT COUNT(*)
                FROM orders o2
                WHERE o2.shop_id = ss.shop_id
                  AND o2.assigned_to = ss.user_id
                  AND o2.status IN ($workload_placeholders)
            ) AS active_workload
        FROM shop_staffs ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.shop_id = ?
          AND ss.status = 'active'
          AND ss.staff_role = 'staff'
          AND u.status = 'active'
        ORDER BY u.fullname ASC
    ");
    $staff_stmt->execute(array_merge([(int) $order['shop_id']], $active_statuses));
    $staff_rows = $staff_stmt->fetchAll();

    $candidates = [];
    foreach($staff_rows as $staff) {
        $max_active_orders = (int) ($staff['max_active_orders'] ?? 0);
        $active_workload = (int) ($staff['active_workload'] ?? 0);

        if($max_active_orders > 0 && $active_workload >= $max_active_orders) {
            continue;
        }

        $available_now = assignment_availability_match($staff);
        if(!$available_now) {
            continue;
        }

        $position_key = normalize_assignment_position($staff['position'] ?? '');
        $position_match = in_array($position_key, $preferred_positions, true);
        $skill_match = assignment_skill_score($order, $staff) > 0;

        $staff['active_workload'] = $active_workload;
        $staff['position_match'] = $position_match ? 1 : 0;
        $staff['skill_match'] = $skill_match ? 1 : 0;
        $staff['available_now'] = $available_now ? 1 : 0;
        $staff['preferred_positions'] = $preferred_positions;
        $staff['score'] = ($position_match ? 100 : 0)
            + ($skill_match ? 30 : 0)
            + ($available_now ? 15 : 0)
            - ($active_workload * 10);
        $candidates[] = $staff;
    }

    usort($candidates, static function(array $a, array $b): int {
        if((int) ($a['score'] ?? 0) !== (int) ($b['score'] ?? 0)) {
            return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        }

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

    $actor_stmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ? LIMIT 1");
    $actor_stmt->execute([$assigned_by]);
    $actor = $actor_stmt->fetch();
    if(!$actor || ($actor['status'] ?? '') !== 'active') {
        return false;
    }

    $order_stmt = $pdo->prepare("SELECT id, order_number, shop_id, assigned_to FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return false;
    }

    if(!assignment_order_is_eligible($order)) {
        return false;
    }

    $actor_role = strtolower((string) ($actor['role'] ?? ''));
    if($actor_role === ROLE_OWNER) {
        $owner_stmt = $pdo->prepare("SELECT owner_id FROM shops WHERE id = ? LIMIT 1");
        $owner_stmt->execute([(int) $order['shop_id']]);
        $shop_owner_id = (int) ($owner_stmt->fetchColumn() ?: 0);
        if($shop_owner_id !== $assigned_by) {
            return false;
        }
    } elseif($actor_role === ROLE_HR) {
        $hr_stmt = $pdo->prepare("\n            SELECT 1
            FROM shop_staffs
            WHERE shop_id = ?
              AND user_id = ?
              AND status = 'active'
              AND (staff_role = 'hr' OR LOWER(REPLACE(position, ' ', '_')) = 'hr_staff')
            LIMIT 1
        ");
        $hr_stmt->execute([(int) $order['shop_id'], $assigned_by]);
        if(!$hr_stmt->fetchColumn()) {
            return false;
        }
    } else {
        return false;
    }

    $active_statuses = assignment_active_workload_statuses();
    $workload_placeholders = assignment_status_placeholders($active_statuses);

    $staff_stmt = $pdo->prepare("\n        SELECT ss.user_id, ss.position, ss.status, ss.shop_id, ss.max_active_orders,
            ss.availability_days,
            ss.availability_start,
            ss.availability_end,
            ss.permissions,
            CONCAT_WS(' ', ss.position, ss.permissions) AS skills_data,
            (
                SELECT COUNT(*)
                FROM orders o2
                WHERE o2.shop_id = ss.shop_id
                  AND o2.assigned_to = ss.user_id
                  AND o2.status IN ($workload_placeholders)
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
    $staff_stmt->execute(array_merge([$staff_user_id, (int) $order['shop_id']], $active_statuses));
    $staff = $staff_stmt->fetch();

    if(!$staff) {
        return false;
    }

    if(!assignment_availability_match($staff)) {
        return false;
    }

    $is_same_assignee = (int) ($order['assigned_to'] ?? 0) === $staff_user_id;
    $max_active_orders = (int) ($staff['max_active_orders'] ?? 0);
    $active_workload = (int) ($staff['active_workload'] ?? 0);
    if($max_active_orders > 0 && $active_workload >= $max_active_orders && !$is_same_assignee) {
        return false;
    }

    $current_assigned_to = isset($order['assigned_to']) ? (int) $order['assigned_to'] : null;
    $update_stmt = $pdo->prepare("
        UPDATE orders
        SET assigned_to = ?, updated_at = NOW()
        WHERE id = ?
          AND shop_id = ?
          AND (assigned_to <=> ?)
    " );
    $updated = $update_stmt->execute([$staff_user_id, $order_id, (int) $order['shop_id'], $current_assigned_to]);

    if(!$updated || $update_stmt->rowCount() <= 0) {
        return false;
    }

    notify_business_event($pdo, 'staff_assigned', $order_id, ['actor_id' => $assigned_by]);
    messaging_auto_thread_on_staff_assigned($pdo, $order_id, $assigned_by);

    if($assigned_by !== $staff_user_id) {
        create_notification(
            $pdo,
            $assigned_by,
            $order_id,
            'order_status',
            'Assignment saved for order #' . $order['order_number'] . '.'
        );
    }

    if(function_exists('sync_production_queue')) {
        sync_production_queue($pdo);
    }

    if(function_exists('log_audit')) {
        log_audit(
            $pdo,
            $assigned_by,
            $actor['role'] ?? null,
            'order_assignment_updated',
            'orders',
            $order_id,
            ['assigned_to' => $order['assigned_to'] ?? null],
            ['assigned_to' => $staff_user_id]
        );
    }

    return true;
}

function maybe_auto_assign_order(PDO $pdo, int $order_id, int $assigned_by): ?array {
    if($order_id <= 0 || $assigned_by <= 0) {
        return null;
    }

    $order_stmt = $pdo->prepare("SELECT id, shop_id, assigned_to, status FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();
    if(!$order || !empty($order['assigned_to'])) {
        return null;
    }

    if(!assignment_order_is_eligible($order)) {
        return ['assigned' => false, 'reason' => 'order_not_eligible'];
    }

    if(!assignment_auto_enabled_for_shop($pdo, (int) $order['shop_id'])) {
        return ['assigned' => false, 'reason' => 'auto_assignment_disabled'];
    }

    $candidates = get_assignable_staff_for_order($pdo, $order_id);
    if(empty($candidates)) {
        return ['assigned' => false, 'reason' => 'no_suitable_staff'];
    }

    $chosen = $candidates[0];
    $assignment_ok = assign_order_to_staff($pdo, $order_id, (int) $chosen['user_id'], $assigned_by);

    return [
        'assigned' => $assignment_ok,
        'reason' => $assignment_ok ? (count($candidates) === 1 ? 'single_candidate' : 'lowest_workload') : 'assignment_failed',
        'candidate_count' => count($candidates),
        'assignee' => $chosen,
    ];
}
?>
