<?php
session_start();
require_once '../config/db.php';
require_once '../config/automation_helpers.php';
require_once '../config/constants.php';
require_once '../config/scheduling_helpers.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$success = null;
$error = null;
$downpayment_rate = 0.20;
$payment_method_labels = payment_method_labels_map();

if(isset($_POST['submit_payment'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $proof_file = $_FILES['payment_proof'] ?? null;
    $payment_method = canonical_payment_method_code(sanitize($_POST['payment_method'] ?? ''));

    $order_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.price, o.payment_status, o.status, o.shop_id, s.shop_name, s.owner_id
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE o.id = ? AND o.client_id = ?
    ");
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Unable to find the order for payment submission.';
    } elseif (in_array($order['status'], ['pending', 'cancelled'], true)) {
        $error = 'Payments can only be submitted for accepted or in-progress orders.';
    } else {
        $latest_payment_stmt = $pdo->prepare("
            SELECT status FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $latest_payment_stmt->execute([$order_id]);
        $latest_payment = $latest_payment_stmt->fetch();
        $latest_status = $latest_payment['status'] ?? null;

        if(normalize_payment_status((string) ($order['payment_status'] ?? 'unpaid')) === 'paid' || normalize_payment_status((string) $latest_status) === 'paid') {
            $error = 'This order has already been marked as paid.';
        } elseif (normalize_payment_status((string) $latest_status) === 'pending_verification') {
            $error = 'A payment proof is already pending verification.';
            } elseif ($payment_method === '') {
            $error = 'Please choose a payment method before submitting proof.';
        } elseif (!array_key_exists($payment_method, array_column(payment_methods_for_submission(), null, 'code'))) {
            $error = 'Selected payment method is not available for downpayment submission.';
        } elseif (!payment_manual_proof_enabled()) {
            $error = 'Manual proof uploads are disabled. Please use secure checkout.';
        } elseif (!$proof_file || $proof_file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid payment proof file.';
        } else {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
            $upload = save_uploaded_media(
                $proof_file,
                $allowed_ext,
                MAX_FILE_SIZE,
                'payments',
                'payment',
                (string) $order_id
            );
            if (!$upload['success']) {
                $error = $upload['error'] === 'File size exceeds the limit.'
                    ? 'Payment proof files must be smaller than 5MB.'
                    : 'Payment proofs must be JPG, PNG, or PDF files.';
            } else {
                $downpayment_amount = round((float) $order['price'] * $downpayment_rate, 2);

                $payment_stmt = $pdo->prepare("
                    INSERT INTO payments (order_id, client_id, payer_user_id, shop_id, amount, expected_amount, paid_amount, proof_file, payment_method, reference_number, provider_transaction_id, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification', ?)
                ");
                $payment_stmt->execute([
                    $order_id,
                    $client_id,
                    $client_id,
                    $order['shop_id'],
                    $downpayment_amount,
                    $downpayment_amount,
                    $downpayment_amount,
                    $upload['filename'],
                    $payment_method,
                    strtoupper($order['order_number']) . '-' . date('YmdHis'),
                    null,
                    'Manual proof submitted by client.'
                ]);

                $order_update_stmt = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'pending_verification', payment_release_status = 'awaiting_confirmation', payment_released_at = NULL
                    WHERE id = ? AND client_id = ?
                ");
                $order_update_stmt->execute([$order_id, $client_id]);
                automation_sync_payment_hold_state($pdo, $order_id, $client_id, $_SESSION['user']['role'] ?? null, 'client_payment_submitted');

                notify_business_event($pdo, 'payment_awaiting_verification', $order_id, [
                    'actor_id' => $client_id,
                    'owner_message' => sprintf(
                        'Payment proof for order #%s (%s) is awaiting verification.',
                        $order['order_number'],
                        $order['shop_name']
                    ),
                    'notify_client' => true,
                    'client_message' => sprintf('Payment proof for order #%s was submitted and is awaiting verification.', $order['order_number']),
                ]);

                 $success = 'Downpayment proof submitted successfully. Awaiting verification.';
                cleanup_media($pdo);
            }
        }
    }
}
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$max_cancel_progress = 20;
$max_revision_count = 2;
$allowed_filters = ['to_pay', 'to_process', 'to_ship', 'to_receive', 'to_review', 'returns', 'cancellation'];
$filter = $_GET['filter'] ?? 'all';

$action = $_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $order_stmt = $pdo->prepare("
        SELECT o.id,
            o.status,
            o.progress,
            o.design_file,
            o.design_approved,
            o.order_number,
            o.revision_count,
            o.price,
            o.payment_status,
            o.assigned_to,
            s.owner_id,
            s.shop_name
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE o.id = ? AND o.client_id = ?
    ");
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Unable to locate the order for this action.';
    } elseif($action === 'cancel_order') {
        $reason = sanitize($_POST['cancellation_reason'] ?? '');
       if($reason === '') {
            $error = 'Please provide a reason before submitting a cancellation request.';
        } elseif((int) $order['progress'] > $max_cancel_progress) {
            $error = 'This order can no longer be cancelled.';
        } else {
            [$cancel_ok, $cancel_error] = order_workflow_apply_transition(
                $pdo,
                $order_id,
                STATUS_CANCELLED,
                ['id' => $client_id, 'role' => (string) ($_SESSION['user']['role'] ?? ROLE_CLIENT)],
                'Client cancellation request: ' . $reason,
                false
            );

            if(!$cancel_ok) {
                $error = $cancel_error ?: 'Unable to cancel this order right now. Please try again.';
            } else {
                $cancel_meta_stmt = $pdo->prepare("
                    UPDATE orders
                    SET cancellation_reason = ?, cancelled_at = COALESCE(cancelled_at, NOW()), updated_at = NOW()
                    WHERE id = ? AND client_id = ?
                ");
                $cancel_meta_stmt->execute([$reason, $order_id, $client_id]);

                automation_finalize_order_cancellation(
                    $pdo,
                    $order,
                    $client_id,
                    $reason,
                    $_SESSION['user']['role'] ?? null
                );

                if(($order['payment_status'] ?? 'unpaid') === 'paid') {
                    automation_request_refund_for_cancelled_paid_order($pdo, $order, $client_id, $reason);
                }

                $success = 'Your order has been cancelled.';
            }
        }
    } elseif($action === 'approve_design') {
        if(!in_array($order['status'], ['accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress'], true)) {
            $error = 'Design approval is only available once the shop accepts the order.';
        } elseif(empty($order['design_file'])) {
            $error = 'There is no design file to approve yet.';
        } elseif((int) $order['design_approved'] === 1) {
            $error = 'This design has already been approved.';
        } else {
            $approve_stmt = $pdo->prepare("
                UPDATE orders
                SET design_approved = 1, updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $approve_stmt->execute([$order_id, $client_id]);
            
            $approval_update_stmt = $pdo->prepare("
                UPDATE design_approvals
                SET status = 'approved', approved_at = NOW(), updated_at = NOW()
                WHERE order_id = ?
            ");
            $approval_update_stmt->execute([$order_id]);

            $owner_message = 'Client approved the design proof for order #' . $order['order_number'] . '.';
            if(!empty($order['owner_id'])) {
                create_notification($pdo, (int) $order['owner_id'], $order_id, 'design', $owner_message);
            }
            if(!empty($order['assigned_to'])) {
                create_notification($pdo, (int) $order['assigned_to'], $order_id, 'design', $owner_message);
            }

            automation_log_audit_if_available(
                $pdo,
                $client_id,
                $_SESSION['user']['role'] ?? null,
                'design_approved',
                'orders',
                $order_id,
                ['design_approved' => $order['design_approved'] ?? null],
                ['design_approved' => 1]
            );

            $success = 'Design approved. Production can begin once the shop starts work.';
        }
    } elseif($action === 'request_revision') {
        $notes = sanitize($_POST['revision_notes'] ?? '');
        if($notes === '') {
            $error = 'Please add revision notes so the shop knows what to adjust.';
        } elseif(empty($order['design_file'])) {
            $error = 'Revision requests require a shared design file.';
        } elseif(!in_array($order['status'], ['accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress'], true)) {
            $error = 'Revisions are only allowed while an order is accepted or in progress.';
        } elseif((int) $order['revision_count'] >= $max_revision_count) {
            $error = 'You have reached the maximum number of revision requests for this order.';
        } else {
            $revision_stmt = $pdo->prepare("
                UPDATE orders
                SET revision_count = revision_count + 1,
                    revision_notes = ?,
                    revision_requested_at = NOW(),
                    design_approved = 0,
                    updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $revision_stmt->execute([$notes, $order_id, $client_id]);
            
            $pending_status = order_workflow_design_pending_status($pdo);
            $approval_update_stmt = $pdo->prepare("
                UPDATE design_approvals
                SET status = ?, approved_at = NULL, customer_notes = ?, updated_at = NOW()
                WHERE order_id = ?
            ");
            $approval_update_stmt->execute([$pending_status, $notes, $order_id]);

            $revision_message = 'Client requested a design revision for order #' . $order['order_number'] . '.';
            if(!empty($order['owner_id'])) {
                create_notification($pdo, (int) $order['owner_id'], $order_id, 'design', $revision_message);
            }
            if(!empty($order['assigned_to'])) {
                create_notification($pdo, (int) $order['assigned_to'], $order_id, 'design', $revision_message);
            }

            automation_log_audit_if_available(
                $pdo,
                $client_id,
                $_SESSION['user']['role'] ?? null,
                'design_revision_requested',
                'orders',
                $order_id,
                [
                    'design_approved' => $order['design_approved'] ?? null,
                    'revision_count' => $order['revision_count'] ?? null,
                ],
                [
                    'design_approved' => 0,
                    'revision_notes' => $notes,
                    'revision_count' => ((int) ($order['revision_count'] ?? 0)) + 1,
                ]
            );

            $success = 'Revision request sent to the shop.';
        }
        } elseif($action === 'accept_price') {
        if(order_workflow_normalize_order_status((string) ($order['status'] ?? '')) !== STATUS_PENDING) {
            $error = 'Price acceptance is only available for pending orders.';
        } elseif($order['price'] === null) {
            $error = 'No price quote is available to accept yet.';
        } else {
            [$quote_updated, $quote_error] = quote_client_respond($pdo, $order_id, $client_id, 'approved');
            if(!$quote_updated) {
                $error = $quote_error ?: 'Quote could not be approved.';
            } else {
            
            [$status_updated, $status_error] = automation_transition_order_status(
                    $pdo,
                    $order_id,
                    STATUS_ACCEPTED,
                    $client_id,
                    $_SESSION['user']['role'] ?? null,
                    'Client approved quote and accepted order.',
                    false,
                    'order_quote_approved'
                );

            if(!$status_updated) {
                    $error = $status_error ?: 'Quote approved, but status update failed.';
                } else {
                    $success = 'Quote approved. Order is now accepted and assignable once payment requirements are satisfied.';
                }

            notify_business_event($pdo, 'order_accepted', $order_id, [
                    'actor_id' => $client_id,
                    'owner_message' => 'Client approved the quote for order #' . $order['order_number'] . ' (' . $order['shop_name'] . ').',
                    'client_message' => 'You approved the quote for order #' . $order['order_number'] . '. The shop can now proceed once payment requirements are met.',
                ]);
            }
        }
        } elseif($action === 'confirm_handoff') {
        $fulfillment_stmt = $pdo->prepare("SELECT * FROM order_fulfillments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $fulfillment_stmt->execute([$order_id]);
        $fulfillment = $fulfillment_stmt->fetch();

        if(!$fulfillment) {
            $error = 'No delivery or pickup handoff record was found for this order.';
        } elseif(!in_array((string) ($fulfillment['status'] ?? ''), [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_DELIVERED], true)) {
            $error = 'Handoff confirmation is only available after pickup readiness or delivery.';
        } else {
            [$saved, $save_error] = automation_upsert_order_fulfillment(
                $pdo,
                $order,
                [
                    'fulfillment_type' => (string) ($fulfillment['fulfillment_type'] ?? 'pickup'),
                    'status' => FULFILLMENT_CLAIMED,
                    'delivery_method' => (string) ($fulfillment['delivery_method'] ?? ''),
                    'courier' => $fulfillment['courier'] ?? null,
                    'tracking_number' => $fulfillment['tracking_number'] ?? null,
                    'pickup_location' => $fulfillment['pickup_location'] ?? null,
                    'notes' => trim((string) (($fulfillment['notes'] ?? '') !== '' ? $fulfillment['notes'] : 'Client confirmed final handoff.')),
                ],
                $client_id,
                $_SESSION['user']['role'] ?? null
            );

            if(!$saved) {
                $error = $save_error ?: 'Failed to confirm handoff.';
            } else {
                $success = 'Thank you. Your handoff confirmation closed this order successfully.';
            }
        }
    } elseif($action === 'reject_price') {
        if(order_workflow_normalize_order_status((string) ($order['status'] ?? '')) !== STATUS_PENDING) {
            $error = 'Price rejection is only available for pending orders.';
        } elseif($order['price'] === null) {
            $error = 'No price quote is available to reject yet.';
        } else {
            [$quote_updated, $quote_error] = quote_client_respond($pdo, $order_id, $client_id, 'rejected');
            if(!$quote_updated) {
                $error = $quote_error ?: 'Quote could not be rejected.';
            } else {
                $success = 'Quote rejected. The shop will send an updated quote.';

            create_notification(
                    $pdo,
                    (int) $order['owner_id'],
                    $order_id,
                    'warning',
                    'Client rejected the quote for order #' . $order['order_number'] . ' (' . $order['shop_name'] . '). Please send a new quote.'
                );
            }
        }
    }
}

$query = "
    SELECT o.*, s.shop_name, s.logo
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ?
";
$params = [$client_id];

$query .= " ORDER BY o.created_at DESC";

$orders_stmt = $pdo->prepare($query);
$orders_stmt->execute($params);
$all_orders = $orders_stmt->fetchAll();

if(function_exists('update_order_estimated_completion')) {
    foreach($all_orders as &$eta_order) {
        $eta_id = (int) ($eta_order['id'] ?? 0);
        if($eta_id <= 0) {
            continue;
        }

        if(empty($eta_order['estimated_completion'])) {
            $eta_order['estimated_completion'] = update_order_estimated_completion($pdo, $eta_id);
        }
    }
    unset($eta_order);
}

$order_photos = [];
$payment_by_order = [];
$invoice_by_order = [];
$refund_by_order = [];
$receipt_by_payment = [];
$fulfillment_by_order = [];
$fulfillment_history_by_id = [];
$status_history_by_order = [];
$progress_logs_by_order = [];
$claimed_fulfillment_by_order = [];
if(!empty($all_orders)) {
    $order_ids = array_column($all_orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $photos_stmt = $pdo->prepare("
        SELECT * FROM order_photos
        WHERE order_id IN ($placeholders)
        ORDER BY uploaded_at DESC
    ");
    $photos_stmt->execute($order_ids);
    $photos = $photos_stmt->fetchAll();

    foreach($photos as $photo) {
        $order_photos[$photo['order_id']][] = $photo;
    }
    
    $status_history_stmt = $pdo->prepare("
        SELECT * FROM order_status_history
        WHERE order_id IN ($placeholders)
        ORDER BY created_at ASC, id ASC
    ");
    $status_history_stmt->execute($order_ids);
    $status_histories = $status_history_stmt->fetchAll();
    foreach($status_histories as $history) {
        $status_history_by_order[$history['order_id']][] = $history;
    }
    
    $progress_logs_by_order = fetch_order_progress_logs($pdo, $order_ids);

    $payments_stmt = $pdo->prepare("
        SELECT p.*
        FROM payments p
        WHERE p.order_id IN ($placeholders)
        ORDER BY p.created_at DESC
    ");
    $payments_stmt->execute($order_ids);
    $payments = $payments_stmt->fetchAll();

    foreach($payments as $payment) {
        if(!isset($payment_by_order[$payment['order_id']])) {
            $payment_by_order[$payment['order_id']] = $payment;
        }
    }
    
    $invoice_stmt = $pdo->prepare("
        SELECT * FROM order_invoices
        WHERE order_id IN ($placeholders)
    ");
    $invoice_stmt->execute($order_ids);
    $invoices = $invoice_stmt->fetchAll();
    foreach($invoices as $invoice) {
        $invoice_by_order[$invoice['order_id']] = $invoice;
    }

    $refund_stmt = $pdo->prepare("
        SELECT * FROM payment_refunds
        WHERE order_id IN ($placeholders)
        ORDER BY requested_at DESC
    ");
    $refund_stmt->execute($order_ids);
    $refunds = $refund_stmt->fetchAll();
    foreach($refunds as $refund) {
        if(!isset($refund_by_order[$refund['order_id']])) {
            $refund_by_order[$refund['order_id']] = $refund;
        }
    }

    $fulfillment_stmt = $pdo->prepare("
        SELECT * FROM order_fulfillments
        WHERE order_id IN ($placeholders)
        ORDER BY created_at DESC, id DESC
    ");
    $fulfillment_stmt->execute($order_ids);
    $fulfillments = $fulfillment_stmt->fetchAll();
    foreach($fulfillments as $fulfillment) {
        if(!isset($fulfillment_by_order[$fulfillment['order_id']])) {
            $fulfillment_by_order[$fulfillment['order_id']] = $fulfillment;
            if(in_array(($fulfillment['status'] ?? null), [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
                $claimed_fulfillment_by_order[$fulfillment['order_id']] = true;
            }
        }
    }

    if(!empty($fulfillments)) {
        $fulfillment_ids = array_column($fulfillments, 'id');
        $fulfillment_placeholders = implode(',', array_fill(0, count($fulfillment_ids), '?'));
        $history_stmt = $pdo->prepare("
            SELECT * FROM order_fulfillment_history
            WHERE fulfillment_id IN ($fulfillment_placeholders)
            ORDER BY created_at DESC
        ");
        $history_stmt->execute($fulfillment_ids);
        $history_rows = $history_stmt->fetchAll();
        foreach($history_rows as $row) {
            $fulfillment_history_by_id[$row['fulfillment_id']][] = $row;
        }
    }

    if(!empty($payments)) {
        $payment_ids = array_column($payments, 'id');
        $payment_placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
        $receipt_stmt = $pdo->prepare("
            SELECT * FROM payment_receipts
            WHERE payment_id IN ($payment_placeholders)
        ");
        $receipt_stmt->execute($payment_ids);
        $receipts = $receipt_stmt->fetchAll();
        foreach($receipts as $receipt) {
            $receipt_by_payment[$receipt['payment_id']] = $receipt;
        }
    }
}

function is_order_for_review(array $order, array $claimed_fulfillment_by_order): bool {
    return $order['status'] === STATUS_COMPLETED
        && (empty($order['rating']) || (int) $order['rating'] === 0)
        && !empty($claimed_fulfillment_by_order[$order['id']]);
}
function get_order_overview_bucket(
    array $order,
    array $fulfillment_by_order,
    array $claimed_fulfillment_by_order
): ?string {
    $payment_status = $order['payment_status'] ?? 'unpaid';
    $fulfillment_status = $fulfillment_by_order[$order['id']]['status'] ?? null;

    if($order['status'] === STATUS_CANCELLED) {
        return 'cancellation';
    }

    if(in_array($payment_status, ['partially_paid', 'refunded'], true)) {
        return 'returns';
    }

    if(is_order_for_review($order, $claimed_fulfillment_by_order)) {
        return 'to_review';
    }

   if($fulfillment_status === FULFILLMENT_OUT_FOR_DELIVERY) {
        return 'to_receive';
    }

    if(in_array($fulfillment_status, [FULFILLMENT_PENDING, FULFILLMENT_READY_FOR_PICKUP], true)) {
        return 'to_ship';
    }

    if(in_array($order['status'], [STATUS_ACCEPTED, STATUS_IN_PROGRESS], true)
        && in_array($payment_status, ['unpaid', 'failed'], true)) {
        return 'to_pay';
    }

    if(in_array($order['status'], [STATUS_PENDING, STATUS_ACCEPTED, STATUS_IN_PROGRESS], true)) {
        return 'to_process';
    }

   return null;
}

function matches_order_filter(
    string $filter,
    array $order,
    array $fulfillment_by_order,
    array $claimed_fulfillment_by_order
): bool {
    return get_order_overview_bucket($order, $fulfillment_by_order, $claimed_fulfillment_by_order) === $filter;
}

$orders = $all_orders;
if(in_array($filter, $allowed_filters, true)) {
    $orders = array_values(array_filter(
        $all_orders,
        static function ($order) use ($filter, $payment_by_order, $fulfillment_by_order, $claimed_fulfillment_by_order) {
            return matches_order_filter($filter, $order, $fulfillment_by_order, $claimed_fulfillment_by_order);
        }
    ));
}

$filter_counts = [];
foreach($allowed_filters as $order_filter) {
    $filter_counts[$order_filter] = 0;
}
foreach($all_orders as $order) {
     $bucket = get_order_overview_bucket($order, $fulfillment_by_order, $claimed_fulfillment_by_order);
    if($bucket !== null && isset($filter_counts[$bucket])) {
        $filter_counts[$bucket]++;
    }
}
function status_pill($status) {
    $map = [
        'pending' => 'status-pending',
        'accepted' => 'status-accepted',
        'in_progress' => 'status-in_progress',
        'production_pending' => 'status-in_progress',
        'production' => 'status-in_progress',
        'production_rework' => 'status-in_progress',
        'qc_pending' => 'status-in_progress',
        'ready_for_delivery' => 'status-completed',
        'delivered' => 'status-completed',
        'digitizing' => 'status-digitizing',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled'
    ];
    $class = $map[$status] ?? 'status-pending';
    $label = order_current_stage_label(['status' => (string) $status], null);
    return '<span class="status-pill ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function payment_status_pill($status) {
    $map = [
        'unpaid' => 'payment-unpaid',
        'pending_verification' => 'payment-pending',
        'paid' => 'payment-paid',
        'partially_paid' => 'payment-pending',
        'failed' => 'payment-rejected',
        'refunded' => 'payment-refunded',
        'cancelled' => 'payment-rejected'
    ];
    $class = $map[$status] ?? 'payment-unpaid';
    return '<span class="status-pill ' . $class . '">' . payment_status_label((string) $status) . '</span>';

}

function fulfillment_status_pill(?string $status): string {
    $map = [
        FULFILLMENT_PENDING => 'fulfillment-pending',
        FULFILLMENT_READY_FOR_PICKUP => 'fulfillment-ready',
        FULFILLMENT_OUT_FOR_DELIVERY => 'fulfillment-out',
        FULFILLMENT_DELIVERED => 'fulfillment-delivered',
        FULFILLMENT_CLAIMED => 'fulfillment-claimed',
        FULFILLMENT_FAILED => 'fulfillment-failed',
    ];
    $label = $status ? ucfirst(str_replace('_', ' ', $status)) : 'Not scheduled';
    $class = $map[$status] ?? 'fulfillment-pending';
    return '<span class="status-pill ' . $class . '">' . $label . '</span>';
}

function delivery_method_label(?string $method): string {
    $map = [
        'courier_delivery' => 'Courier delivery',
        'same_day_delivery' => 'Same-day delivery',
        'store_pickup' => 'Store pickup',
        'meetup_pickup' => 'Meet-up pickup',
    ];

    $key = strtolower(trim((string) $method));
    if($key === '') {
        return 'Not set';
    }

    return $map[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function format_money_or_pending($value): string {
    if($value === null || $value === '') {
        return 'Awaiting quote';
    }

    return '₱' . number_format((float) $value, 2);
}

function order_filter_description(string $filter): string {
    $descriptions = [
        'to_pay' => 'Need to pay the 20% downpayment first before the order continues.',
        'to_process' => 'Orders waiting to be processed or currently in the process of making.',
        'to_ship' => 'Orders finished and ready for courier handling or ready for customer pick up.',
        'to_receive' => 'Orders currently on the way with courier and out for delivery.',
        'to_review' => 'Orders already received/delivered and now ready for review, rating, or return request.',
        'returns' => 'Returned items requested by customers after receiving the order.',
        'cancellation' => 'Cancelled orders.',
        'all' => 'Track your orders by payment, production, shipping, receiving, review, returns, or cancellation status.',
    ];

    return $descriptions[$filter] ?? $descriptions['all'];
}

function order_overview_label(array $order, array $fulfillment_by_order, array $claimed_fulfillment_by_order): string {
    $bucket = get_order_overview_bucket($order, $fulfillment_by_order, $claimed_fulfillment_by_order);
    $labels = [
        'to_pay' => 'To Pay',
        'to_process' => 'To Process',
        'to_ship' => 'To Ship',
        'to_receive' => 'To Receive',
        'to_review' => 'To Review',
        'returns' => 'Return',
        'cancellation' => 'Cancellation',
    ];

    return $labels[$bucket] ?? 'Processing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Orders</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .filter-tabs a {
            padding: 8px 16px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
        }
        .filter-tabs a.active {
            background: #4f46e5;
            color: white;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending { background: #fef9c3; color: #92400e; }
        .status-accepted { background: #e0f2fe; color: #0369a1; }
        .status-in_progress { background: #e0e7ff; color: #3730a3; }
        .status-digitizing { background: #ede9fe; color: #6d28d9; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .payment-refund-pending { background: #fef9c3; color: #92400e; }
        .payment-refunded { background: #e2e8f0; color: #475569; }
        .fulfillment-pending { background: #e2e8f0; color: #475569; }
        .fulfillment-ready { background: #fef9c3; color: #92400e; }
        .fulfillment-out { background: #e0f2fe; color: #0369a1; }
        .fulfillment-delivered { background: #dcfce7; color: #166534; }
        .fulfillment-claimed { background: #ccfbf1; color: #0f766e; }
        .fulfillment-failed { background: #fee2e2; color: #991b1b; }
        .order-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            margin-bottom: 16px;
        }
        .order-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 12px;
            color: #64748b;
            font-size: 0.9rem;
        }
        .status-timeline {
            border-left: 2px solid #e2e8f0;
            padding-left: 16px;
            margin-top: 12px;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 12px;
        }
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        .timeline-item::before {
            content: "";
            position: absolute;
            left: -21px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4f46e5;
        }
        .timeline-meta {
            font-size: 0.82rem;
            color: #64748b;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            margin-top: 12px;
        }
        .progress-fill {
            height: 100%;
            background: #4f46e5;
        }
        .photo-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .photo-row img {
            width: 100%;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .payment-form {
            margin-top: 16px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
        }
        .payment-form input[type="file"] {
            display: block;
            width: 100%;
        }
        .rating-reminder {
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-radius: 12px;
            padding: 14px;
            margin-top: 16px;
        }
        .rating-reminder .btn {
            margin-top: 10px;
        }
         .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px 18px;
            margin-top: 8px;
            color: #475569;
            font-size: 0.9rem;
        }
        .detail-section {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
        }
        .detail-section h5 {
            margin: 0;
            font-size: 0.98rem;
        }
        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .order-card {
            border-left: 4px solid #f97316;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        }
        .order-card-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
        }
        .order-overview-pill {
            display: inline-flex;
            margin-top: 6px;
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fdba74;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 600;
        }
        .order-detail-panel { display: none; }
        .order-detail-panel.is-open { display: block; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Track Your Orders</h2>
            <p class="text-muted">Stay updated on current progress, timelines, and shop updates.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <h3>Cancellation & Revision Rules</h3>
            <ul class="text-muted mb-0">
                <li>Orders can be cancelled while they are pending or accepted and before progress exceeds <?php echo $max_cancel_progress; ?>%.</li>
                <li>Design approval is required before production starts. Approve shared designs or request changes.</li>
                <li>Each order includes up to <?php echo $max_revision_count; ?> design revision requests while the order is accepted or in progress.</li>
                <li>Payment proofs are required once a shop accepts your order. Receipts are issued after verification.</li>
                <li>If a paid order is cancelled, the shop will process a refund and update the payment status.</li>
            </ul>
        </div>

        <div class="filter-tabs">
           <a href="track_order.php" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All (<?php echo count($all_orders); ?>)</a>
            <a href="track_order.php?filter=to_pay" class="<?php echo $filter === 'to_pay' ? 'active' : ''; ?>">To Pay (<?php echo $filter_counts['to_pay']; ?>)</a>
            <a href="track_order.php?filter=to_process" class="<?php echo $filter === 'to_process' ? 'active' : ''; ?>">To Process (<?php echo $filter_counts['to_process']; ?>)</a>
            <a href="track_order.php?filter=to_ship" class="<?php echo $filter === 'to_ship' ? 'active' : ''; ?>">To Ship (<?php echo $filter_counts['to_ship']; ?>)</a>
            <a href="track_order.php?filter=to_receive" class="<?php echo $filter === 'to_receive' ? 'active' : ''; ?>">To Receive (<?php echo $filter_counts['to_receive']; ?>)</a>
            <a href="track_order.php?filter=to_review" class="<?php echo $filter === 'to_review' ? 'active' : ''; ?>">To Review (<?php echo $filter_counts['to_review']; ?>)</a>
            <a href="track_order.php?filter=returns" class="<?php echo $filter === 'returns' ? 'active' : ''; ?>">Returns (<?php echo $filter_counts['returns']; ?>)</a>
            <a href="track_order.php?filter=cancellation" class="<?php echo $filter === 'cancellation' ? 'active' : ''; ?>">Cancellation (<?php echo $filter_counts['cancellation']; ?>)</a>
        </div>

        <p class="text-muted mb-3"><?php echo htmlspecialchars(order_filter_description($filter)); ?></p>

        <?php if(!empty($orders)): ?>
            <?php foreach($orders as $index => $order): ?>
                <?php $quote_details = !empty($order['quote_details']) ? json_decode($order['quote_details'], true) : null; ?>
                <?php $fulfillment = $fulfillment_by_order[$order['id']] ?? null; ?>
                <?php $fulfillment_status = $fulfillment['status'] ?? null; ?>
                <?php $status_history_raw = $status_history_by_order[$order['id']] ?? []; ?>
                <?php $status_history = ensure_status_history_with_fallback($status_history_raw, $order, 'Current order status.'); ?>
                <?php $progress_logs_raw = $progress_logs_by_order[$order['id']] ?? []; ?>
                <?php $progress_logs = ensure_progress_logs_with_fallback($progress_logs_raw, $status_history_raw, $order); ?>
                <?php $workflow_snapshot = order_workflow_snapshot($order, $status_history_raw, $fulfillment_status); ?>
                <?php $display_progress = (int) ($workflow_snapshot['display_progress'] ?? 0); ?>
                <?php $current_stage_label = (string) ($workflow_snapshot['stage_label'] ?? 'Order placed'); ?>
                <div class="order-card">
                   <div class="order-card-header" data-toggle-order="order-detail-<?php echo (int) $order['id']; ?>">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($order['service_type']); ?></h4>
                            <p class="text-muted mb-0">
                                <i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?>
                            </p>
                            <span class="order-overview-pill"><?php echo htmlspecialchars(order_overview_label($order, $fulfillment_by_order, $claimed_fulfillment_by_order)); ?></span>
                        </div>
                        <div class="text-right">
                            <?php echo status_pill($order['status']); ?>
                            <div class="text-muted mt-2">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                             <div class="text-muted small mt-1">Click to view details <i class="fas fa-angle-down"></i></div>
                        </div>
                    </div>
                    <?php if($quote_details): ?>
                        <div class="mt-2 text-muted small">
                            <strong>Quote preferences:</strong>
                            Complexity <?php echo htmlspecialchars($quote_details['complexity'] ?? 'Standard'); ?> •
                            Add-ons <?php echo htmlspecialchars(!empty($quote_details['add_ons']) ? implode(', ', $quote_details['add_ons']) : 'None'); ?> •
                            Rush <?php echo !empty($quote_details['rush']) ? 'Yes' : 'No'; ?>
                            <?php if(isset($quote_details['estimated_total'])): ?>
                                • Est. total ₱<?php echo number_format((float) $quote_details['estimated_total'], 2); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php
                        $payment = $payment_by_order[$order['id']] ?? null;
                        $payment_status = $order['payment_status'] ?? 'unpaid';
                        $latest_payment_status = normalize_payment_status((string) ($payment['status'] ?? 'unpaid'));
                        $invoice = $invoice_by_order[$order['id']] ?? null;
                        $refund = $refund_by_order[$order['id']] ?? null;
                        $receipt = $payment ? ($receipt_by_payment[$payment['id']] ?? null) : null;
                        $payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $payment_status, $order['payment_release_status'] ?? null);
                        $can_submit_payment = in_array($order['status'], ['accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress'], true)
                            && $payment_status !== 'paid'
                            && $payment_status !== 'partially_paid'
                            && $payment_status !== 'refunded'
                            && $latest_payment_status !== 'pending_verification';
                        $amount_total = $order['price'] !== null ? (float) $order['price'] : null;
                         $required_downpayment = $amount_total !== null ? round($amount_total * $downpayment_rate, 2) : null;
                        $amount_paid = in_array(normalize_payment_status((string) $payment_status), ['paid', 'partially_paid'], true)
                            ? (float) ($payment['paid_amount'] ?? ($required_downpayment ?? 0.0))
                            : 0.0;
                        $balance_due = $amount_total !== null ? max(0, $amount_total - (float) $amount_paid) : null;
                        $payment_method = $payment ? ($payment_method_labels[$payment['payment_method'] ?? ''] ?? 'Uploaded proof of payment') : 'Not provided';
                        $history = $fulfillment ? ($fulfillment_history_by_id[$fulfillment['id']] ?? []) : [];
                    ?>

                    <div class="order-detail-panel <?php echo $index === 0 ? 'is-open' : ''; ?>" id="order-detail-<?php echo (int) $order['id']; ?>"></div>
                    <div class="detail-section">
                        <h5>Order Overview</h5>
                        <div class="detail-grid">
                            <div><strong>Order Number:</strong> #<?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div><strong>Order Date:</strong> <?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                            <div><strong>Order Status:</strong> <?php echo status_pill($order['status']); ?></div>
                            <div><strong>Current Stage:</strong> <?php echo htmlspecialchars($current_stage_label); ?></div>
                            <div><strong>Estimated Completion:</strong>
                                <?php if(!empty($order['estimated_completion'])): ?>
                                    <?php echo date('M d, Y h:i A', strtotime($order['estimated_completion'])); ?> <span class="text-muted">(estimate)</span>
                                <?php else: ?>
                                    <span class="text-muted">Calculating estimate...</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h5>Delivery & Fulfillment</h5>
                        <div class="detail-grid">
                            <div><strong>Recipient Name:</strong> <?php echo htmlspecialchars($_SESSION['user']['fullname'] ?? 'Not provided'); ?></div>
                            <div><strong>Phone Number:</strong> <?php echo htmlspecialchars($_SESSION['user']['phone'] ?? 'Not provided'); ?></div>
                            <div><strong>Fulfillment type:</strong> <?php echo !empty($fulfillment['fulfillment_type']) ? htmlspecialchars($fulfillment['fulfillment_type'] === 'pickup' ? 'Pickup' : 'Delivery') : 'Not scheduled'; ?></div>
                            <div><strong>Delivery method:</strong> <?php echo htmlspecialchars(delivery_method_label($fulfillment['delivery_method'] ?? null)); ?></div>
                            <div><strong>Address / pickup location:</strong> <?php echo htmlspecialchars($fulfillment['pickup_location'] ?? 'Not provided'); ?></div>
                            <div><strong>Courier / service:</strong> <?php echo htmlspecialchars($fulfillment['courier'] ?? 'Not assigned'); ?></div>
                            <div><strong>Tracking / reference #:</strong> <?php echo htmlspecialchars($fulfillment['tracking_number'] ?? 'Not provided'); ?></div>
                            <div><strong>Shipped at:</strong> <?php echo !empty($fulfillment['shipped_at']) ? date('M d, Y H:i', strtotime($fulfillment['shipped_at'])) : '—'; ?></div>
                            <div><strong>Delivered at:</strong> <?php echo !empty($fulfillment['delivered_at']) ? date('M d, Y H:i', strtotime($fulfillment['delivered_at'])) : '—'; ?></div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h5>Order Items</h5>
                        <div class="detail-grid">
                            <div><strong>Product Name:</strong> <?php echo htmlspecialchars($order['service_type']); ?></div>
                            <div><strong>Quantity:</strong> <?php echo htmlspecialchars($order['quantity']); ?></div>
                            <div><strong>Price:</strong> <?php echo format_money_or_pending($order['price']); ?></div>
                        </div>
                        <?php if(!empty($order['design_file'])): ?>
                             <div class="mt-2"><i class="fas fa-paperclip"></i>
                                <a href="../assets/uploads/designs/<?php echo htmlspecialchars($order['design_file']); ?>" target="_blank">View design file</a>
                            </div>
                        <?php endif; ?>
                    </div>

                     <div class="detail-section">
                        <h5>Payment Information</h5>
                        <div class="detail-grid">
                            <div><strong>Payment Method:</strong> <?php echo htmlspecialchars($payment_method); ?></div>
                            <div><strong>Total Amount:</strong> <?php echo $amount_total !== null ? '₱' . number_format($amount_total, 2) : 'Awaiting quote'; ?></div>
                             <div><strong>Required Downpayment (20%):</strong> <?php echo $required_downpayment !== null ? '₱' . number_format($required_downpayment, 2) : 'Awaiting quote'; ?></div>
                            <div><strong>Amount Paid:</strong> <?php echo $amount_total !== null ? '₱' . number_format((float) $amount_paid, 2) : '₱0.00'; ?></div>
                            <div><strong>Balance Due:</strong> <?php echo $balance_due !== null ? '₱' . number_format($balance_due, 2) : 'Awaiting quote'; ?></div>
                        </div>
                        <div class="mt-2">
                            <?php echo payment_status_pill($payment_status); ?>
                            <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                            </span>
                        </div>
                    </div>
                    <?php if($order['status'] === 'cancelled' && !empty($order['cancellation_reason'])): ?>
                        <div class="mt-2 text-muted">
                            <strong>Cancellation reason:</strong>
                            <div><?php echo nl2br(htmlspecialchars($order['cancellation_reason'])); ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <strong>Status Timeline</strong>
                        <div class="status-timeline">
                            <?php if(!empty($progress_logs)): ?>
                                <?php foreach($progress_logs as $log_entry): ?>
                                    <div class="timeline-item">
                                        <div>
                                            <?php echo status_pill($log_entry['status']); ?>
                                            <span class="small ml-1"><strong><?php echo htmlspecialchars((string) ($log_entry['title'] ?? 'Progress update')); ?></strong></span>
                                        </div>
                                        <?php if(!empty($log_entry['description'])): ?>
                                            <div class="text-muted small mt-1"><?php echo nl2br(htmlspecialchars((string) $log_entry['description'])); ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-meta">
                                            <?php echo date('M d, Y H:i', strtotime((string) ($log_entry['created_at'] ?? 'now'))); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="timeline-item">
                                    <div class="text-muted small">Showing the current order status while detailed history is not yet available.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $needs_rating = is_order_for_review($order, $claimed_fulfillment_by_order); ?>
                    <?php if($needs_rating): ?>
                        <div class="rating-reminder">
                            <strong><i class="fas fa-star text-warning"></i> Rate this completed order</strong>
                            <p class="text-muted small mb-0">
                                Your feedback helps providers improve and keeps the marketplace accurate.
                            </p>
                            <a href="rate_provider.php" class="btn btn-sm btn-primary">
                                Leave a rating
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if($needs_rating): ?>
                        <div class="card mt-3" style="background: #f8fafc;">
                            <div class="d-flex justify-between align-center">
                                <strong>Return Request</strong>
                                <span class="badge badge-success">Available</span>
                            </div>
                            <p class="text-muted small mb-0 mt-2">Return is allowed because this order is now in <strong>To Review</strong> (received) state.</p>
                        </div>
                    <?php else: ?>
                        <div class="card mt-3" style="background: #f8fafc;">
                            <div class="d-flex justify-between align-center">
                                <strong>Return Request</strong>
                                <span class="badge badge-secondary">Locked</span>
                            </div>
                            <p class="text-muted small mb-0 mt-2">Return is only available when order status is <strong>To Review</strong> (received).</p>
                        </div>
                    <?php endif; ?>
                    <?php if($order['status'] === 'pending'): ?>
                        <?php $quote_status = strtolower((string) ($order['quote_status'] ?? 'draft')); ?>
                        <div class="mt-3">
                            <strong>Price Quote</strong>
                            <?php if($order['price'] === null || !in_array($quote_status, ['sent', 'approved', 'rejected', 'expired'], true)): ?>
                                <div class="text-muted small mt-2">Waiting for the shop to send a price quote.</div>
                            <?php elseif($quote_status === 'sent'): ?>
                                <div class="text-muted small mt-2">
                                    The shop issued a quote of ₱<?php echo number_format($order['price'], 2); ?>. Please approve or reject to continue the lifecycle.
                                </div>
                                <div class="mt-2">
                                    <form method="POST" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="accept_price">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Accept Price
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="reject_price">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-times"></i> Reject Price
                                        </button>
                                    </form>
                                </div>
                                <?php elseif($quote_status === 'approved'): ?>
                                <div class="alert alert-success mt-2 mb-0">You approved this quote. The order can proceed.</div>
                            <?php elseif($quote_status === 'expired'): ?>
                                <div class="alert alert-warning mt-2 mb-0">This quote is expired and is no longer actionable until reissued.</div>
                            <?php else: ?>
                                <div class="alert alert-warning mt-2 mb-0">You rejected this quote. Waiting for an updated quote from the shop.</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <?php if($latest_payment_status === 'pending_verification'): ?>
                            <div class="text-muted small mt-2">Payment proof is pending verification.</div>
                        <?php elseif($latest_payment_status === 'failed'): ?>
                            <div class="text-muted small mt-2">Payment proof failed verification. Please upload a new proof.</div>
                        <?php elseif($payment_status === 'paid'): ?>
                            <div class="text-muted small mt-2">Payment verified by the shop.</div>
                        <?php elseif($payment_status === 'partially_paid'): ?>
                            <div class="text-muted small mt-2">Partial payment is recorded. Remaining balance applies.</div>
                        <?php elseif($payment_status === 'refunded'): ?>
                            <div class="text-muted small mt-2">Refund completed.</div>
                        <?php else: ?>
                            <div class="text-muted small mt-2">No downpayment proof submitted yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-2 text-muted small">
                        <?php if($invoice): ?>
                            <div>Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> (<?php echo htmlspecialchars($invoice['status']); ?>)</div>
                            <a href="view_invoice.php?order_id=<?php echo $order['id']; ?>" class="text-primary">View invoice</a>
                        <?php else: ?>
                            <div>Invoice will be issued once the price is finalized.</div>
                        <?php endif; ?>
                        <?php if($receipt && $payment_status === 'paid'): ?>
                            <div class="mt-1">
                                Receipt #<?php echo htmlspecialchars($receipt['receipt_number']); ?>
                                <a href="view_receipt.php?order_id=<?php echo $order['id']; ?>" class="text-primary">View receipt</a>
                            </div>
                        <?php endif; ?>
                        <?php if($refund): ?>
                            <div class="mt-1">Refund status: <?php echo htmlspecialchars($refund['status']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3">
                        <strong>Delivery / Pickup:</strong>
                        <?php echo fulfillment_status_pill($fulfillment['status'] ?? null); ?>
                        <?php $can_confirm_handoff = $fulfillment && in_array(($fulfillment['status'] ?? ''), [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_DELIVERED], true); ?>
                        <div class="text-muted small mt-2">
                            <?php if($fulfillment): ?>
                                <div>Type: <?php echo ucfirst($fulfillment['fulfillment_type']); ?></div>
                                <div>Delivery method: <?php echo htmlspecialchars(delivery_method_label($fulfillment['delivery_method'] ?? null)); ?></div>
                                <div>Tracking: <?php echo htmlspecialchars($fulfillment['tracking_number'] ?? 'Not provided'); ?></div>
                                <div>Courier: <?php echo htmlspecialchars($fulfillment['courier'] ?? 'Not assigned'); ?></div>
                                <div>Shipped at: <?php echo !empty($fulfillment['shipped_at']) ? date('M d, Y H:i', strtotime($fulfillment['shipped_at'])) : '—'; ?></div>
                                <div>Delivered at: <?php echo !empty($fulfillment['delivered_at']) ? date('M d, Y H:i', strtotime($fulfillment['delivered_at'])) : '—'; ?></div>
                                <?php if(!empty($fulfillment['pickup_location'])): ?>
                                    <div>Pickup location: <?php echo htmlspecialchars($fulfillment['pickup_location']); ?></div>
                                <?php endif; ?>
                                <?php if(!empty($history)): ?>
                                    <div class="mt-1">
                                        Latest update: <?php echo ucfirst(str_replace('_', ' ', $history[0]['status'])); ?>
                                        (<?php echo date('M d, Y H:i', strtotime($history[0]['created_at'])); ?>)
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div>No delivery or pickup details yet. We'll notify you once the shop schedules the handoff.</div>
                            <?php endif; ?>
                        </div>
                        <?php if($can_confirm_handoff): ?>
                            <form method="POST" class="mt-2">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="confirm_handoff">
                                <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fas fa-check-circle"></i> Confirm pickup / delivery received
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if($can_submit_payment): ?>
                        <form method="POST" enctype="multipart/form-data" class="payment-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <div class="form-group">
                                <label>Payment Method</label>
                                <select name="payment_method" class="form-control js-payment-method-select" data-method-scope="payment_submission" required>
                                    <option value="">Loading methods...</option>
                                </select>
                            </div>
                            <?php if(payment_manual_proof_enabled()): ?>
                                <div class="form-group">
                                    <label>Upload Downpayment Proof (JPG, PNG, PDF)</label>
                                    <input type="file" name="payment_proof" class="form-control">
                                </div>
                            <?php endif; ?>
                             <div class="text-muted small mb-2">
                                Secure checkout is now available for your required 20% downpayment.
                            </div>
                            <div class="action-row">
                                <button type="button" class="btn btn-primary btn-sm js-start-checkout" data-order-id="<?php echo (int) $order['id']; ?>">
                                    <i class="fas fa-credit-card"></i> Pay 20% Downpayment
                                </button>
                                <?php if(payment_manual_proof_enabled()): ?>
                                    <button type="submit" name="submit_payment" class="btn btn-outline btn-sm">
                                        <i class="fas fa-upload"></i> Submit Manual Proof
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>


                    <div class="mt-3">
                        <strong>Progress: <?php echo (int) $display_progress; ?>%</strong>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo (int) $display_progress; ?>%;"></div>
                        </div>
                    </div>

                    <?php if(!empty($order['design_description'])): ?>
                        <p class="text-muted mt-3"><i class="fas fa-clipboard"></i> <?php echo htmlspecialchars($order['design_description']); ?></p>
                    <?php endif; ?>

                    <?php if(!empty($order['design_file'])): ?>
                        <div class="card mt-3" style="background: #f8fafc;">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <strong>Design Approval</strong>
                                    <p class="text-muted mb-0">Review the shared design before production starts.</p>
                                </div>
                                <div class="text-right">
                                    <?php if($order['design_approved']): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending Approval</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if(!$order['design_approved']): ?>
                                <div class="mt-3">
                                    <form method="POST" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="approve_design">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Approve Design
                                        </button>
                                    </form>
                                    <form method="POST" class="mt-2">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="request_revision">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <div class="form-group">
                                            <label class="text-muted">Request a revision (<?php echo (int) $order['revision_count']; ?>/<?php echo $max_revision_count; ?> used)</label>
                                            <textarea name="revision_notes" class="form-control" rows="2" placeholder="Share the updates you need..." required></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-pen"></i> Request Revision
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if(in_array($order['status'], ['pending', 'accepted'], true) && (int) $order['progress'] <= $max_cancel_progress): ?>
                        <div class="card mt-3" style="background: #fef2f2;">
                            <div class="d-flex justify-between align-center">
                                <strong>Cancel Order</strong>
                                <span class="text-muted small">Allowed before work exceeds <?php echo $max_cancel_progress; ?>%.</span>
                            </div>
                            <form method="POST" class="mt-2">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <div class="form-group">
                                    <label class="text-muted">Cancellation reason</label>
                                    <textarea name="cancellation_reason" class="form-control" rows="2" placeholder="Let the shop know why you are cancelling..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-times"></i> Cancel Order Request
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if(!((in_array($order['status'], ['pending', 'accepted'], true) && (int) $order['progress'] <= $max_cancel_progress))): ?>
                        <div class="card mt-3" style="background: #f8fafc;">
                            <div class="d-flex justify-between align-center">
                                <strong>Cancel Order</strong>
                                <span class="badge badge-secondary">Locked</span>
                            </div>
                            <p class="text-muted small mb-0 mt-2">Cancellation is only allowed while order progress is <?php echo (int) $max_cancel_progress; ?>% or lower.</p>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($order_photos[$order['id']])): ?>
                        <div class="mt-3">
                            <strong>Latest Photos</strong>
                            <div class="photo-row">
                                <?php foreach(array_slice($order_photos[$order['id']], 0, 3) as $photo): ?>
                                    <img src="../assets/uploads/<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="Order photo">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-route fa-3x text-muted mb-3"></i>
                    <h4>No Orders Found</h4>
                    <p class="text-muted">Orders matching this filter will appear here.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
     <script>
        document.querySelectorAll('[data-toggle-order]').forEach(function (header) {
            header.addEventListener('click', function () {
                var panelId = header.getAttribute('data-toggle-order');
                var panel = document.getElementById(panelId);
                if (!panel) {
                    return;
                }
                panel.classList.toggle('is-open');
            });
        });
        (function hydratePaymentMethods() {
            var selects = document.querySelectorAll('.js-payment-method-select');
            if (!selects.length) {
                return;
            }

            fetch('../api/payment_methods_api.php?scope=payment_submission')
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Failed to load payment methods');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    var methods = Array.isArray(payload.data) ? payload.data : [];
                    selects.forEach(function (selectEl) {
                        selectEl.innerHTML = '';
                        var defaultOption = document.createElement('option');
                        defaultOption.value = '';
                        defaultOption.textContent = 'Select a payment method';
                        selectEl.appendChild(defaultOption);

                        methods.forEach(function (method) {
                            var option = document.createElement('option');
                            option.value = method.code;
                            option.textContent = method.label;
                            selectEl.appendChild(option);
                        });
                    });
                })
                .catch(function () {
                    selects.forEach(function (selectEl) {
                        selectEl.innerHTML = '<option value="">Unable to load payment methods</option>';
                    });
                });
        })();
        
        document.querySelectorAll('.js-start-checkout').forEach(function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('form');
                if (!form) {
                    return;
                }

                var methodField = form.querySelector('select[name="payment_method"]');
                var csrfField = form.querySelector('input[name="csrf_token"]');
                var orderField = form.querySelector('input[name="order_id"]');
                if (!methodField || !csrfField || !orderField || !methodField.value) {
                    alert('Please select a payment method first.');
                    return;
                }

                var body = new URLSearchParams();
                body.append('csrf_token', csrfField.value);
                body.append('order_id', orderField.value);
                body.append('payment_method', methodField.value);

                button.disabled = true;
                fetch('../api/payment_checkout_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data.success) {
                            throw new Error(result.data.error || 'Unable to start checkout.');
                        }

                        if (result.data.checkout_url) {
                            window.location.href = result.data.checkout_url;
                            return;
                        }

                        alert('Checkout started but no redirect URL was returned.');
                    })
                    .catch(function (error) {
                        alert(error.message || 'Unable to start checkout.');
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            });
        });
    </script>
</body>
</html>
