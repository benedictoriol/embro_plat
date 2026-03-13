<?php
session_start();
require_once '../config/db.php';
require_once '../config/automation_helpers.php';
require_once '../config/scheduling_helpers.php';
require_once '../config/constants.php';
require_once '../config/qc_helpers.php';
require_once '../config/design_helpers.php';
require_once '../includes/media_manager.php';
require_role(['staff','hr']);

$staff_id = $_SESSION['user']['id'];
$staff_role = $_SESSION['user']['role'] ?? null;

$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo 
    FROM shop_staffs se 
    JOIN shops s ON se.shop_id = s.id 
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$staff_id]);
$staff = $emp_stmt->fetch();

if(!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$staff_permissions = fetch_staff_permissions($pdo, $staff_id);
$is_digitizer = user_has_position($_SESSION['user'], ['digitizer'], $pdo);
$is_embroidery_operator = user_has_position($_SESSION['user'], ['embroidery_operator'], $pdo);
$can_upload_proof = !empty($staff_permissions['update_status']) && $is_digitizer;
$can_update_status = !empty($staff_permissions['update_status']) && $is_embroidery_operator;
$can_upload_photos = !empty($staff_permissions['upload_photos']) && $is_embroidery_operator;

if(!$can_update_status && !$can_upload_photos && !$can_upload_proof) {
    http_response_code(403);
    die('Your position is not allowed to update production stages, upload output photos, or upload design proofs.');
}

// Get assigned jobs
$jobs_stmt = $pdo->prepare("
    SELECT 
        o.*,
        u.fullname as client_name,
        s.shop_name,
        COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
        js.scheduled_time as schedule_time
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    JOIN shops s ON o.shop_id = s.id 
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
    WHERE (o.assigned_to = ? OR js.staff_id = ?)
    AND o.status IN ('accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress')
    ORDER BY schedule_date ASC, js.scheduled_time ASC
");
$jobs_stmt->execute([$staff_id, $staff_id, $staff_id]);
$jobs = $jobs_stmt->fetchAll();
$exception_summary_map = [];
if(function_exists('order_exception_summaries') && !empty($jobs)) {
    $exception_summary_map = order_exception_summaries($pdo, array_column($jobs, 'id'));
}
$jobs_by_id = [];
foreach($jobs as $job_item) {
    $jobs_by_id[(int) $job_item['id']] = $job_item;
}

$selected_order_id = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$selected_job = $selected_order_id > 0 ? ($jobs_by_id[$selected_order_id] ?? null) : null;
$latest_digitized_design = null;
if($selected_order_id > 0) {
    $latest_digitized_stmt = $pdo->prepare("
        SELECT dd.*, u.fullname AS digitizer_name
        FROM digitized_designs dd
        LEFT JOIN users u ON u.id = dd.digitizer_id
        WHERE dd.order_id = ?
        ORDER BY dd.id DESC
        LIMIT 1
    " );
    $latest_digitized_stmt->execute([$selected_order_id]);
    $latest_digitized_design = $latest_digitized_stmt->fetch() ?: null;
}

$stage_options = [
    'digitizing_started' => [
        'label' => 'Digitizing Started',
        'status' => STATUS_DIGITIZING,
        'progress' => 40,
    ],
    'materials_ready' => [
        'label' => 'Materials Ready',
        'status' => STATUS_PRODUCTION,
        'progress' => 55,
    ],
    'in_process_of_making' => [
        'label' => 'In the Process of Making',
        'status' => STATUS_PRODUCTION,
        'progress' => 65,
    ],
    'order_complete' => [
        'label' => 'Order Complete',
        'status' => STATUS_QC_PENDING,
        'progress' => 75,
    ],
];
$photo_counts = [];
function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}

function fetch_order_info(PDO $pdo, int $staff_id, int $order_id): ?array {
    $order_info_stmt = $pdo->prepare("
        SELECT o.id, o.shop_id, o.status, o.progress, o.order_number, o.client_id, o.design_approved, o.quantity, o.service_type, s.shop_name, s.owner_id
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
        WHERE o.id = ? AND (o.assigned_to = ? OR js.staff_id = ?)
        LIMIT 1
    ");
    $order_info_stmt->execute([$staff_id, $order_id, $staff_id, $staff_id]);
    $order_info = $order_info_stmt->fetch();

    return $order_info ?: null;
}

function keep_progress_forward(int $current_progress, int $next_progress): int {
    return max($current_progress, $next_progress);
}

function format_bytes_human(?int $size): string {
    $safe_size = max(0, (int) ($size ?? 0));
    if($safe_size >= 1048576) {
        return number_format($safe_size / 1048576, 2) . ' MB';
    }
    if($safe_size >= 1024) {
        return number_format($safe_size / 1024, 2) . ' KB';
    }

    return number_format($safe_size) . ' B';
}

if(!empty($jobs)) {
    $job_ids = array_column($jobs, 'id');
    $placeholders = implode(',', array_fill(0, count($job_ids), '?'));
    $photo_stmt = $pdo->prepare("
        SELECT order_id, COUNT(*) as photo_count
        FROM order_photos
        WHERE staff_id = ?
          AND order_id IN ($placeholders)
        GROUP BY order_id
    ");
    $photo_stmt->execute(array_merge([$staff_id], $job_ids));
    $photo_counts = $photo_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Update status
if(isset($_POST['upload_proof'])) {
    if(!$can_upload_proof) {
        $error = 'You do not have permission to upload design proofs.';
    } else {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $design_file = sanitize($_POST['design_file'] ?? '');
        $provider_notes = sanitize($_POST['provider_notes'] ?? '');

        if($design_file === '' && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if($_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Please upload a valid proof image file.';
            } else {
                $upload = save_uploaded_media(
                    $_FILES['proof_file'],
                    ALLOWED_IMAGE_TYPES,
                    MAX_FILE_SIZE,
                    '',
                    'design-proof'
                );

                if(!$upload['success']) {
                    $error = $upload['error'];
                } else {
                    $design_file = 'assets/uploads/' . $upload['path'];
                }
            }
        }

        $order_info = fetch_order_info($pdo, $staff_id, $order_id);
         if(isset($error)) {
        } elseif(!$order_info) {
            $error = "Unable to upload proof for this order.";
        } elseif($design_file === '') {
            $error = "Please upload a proof file before submitting.";
        } else {
        $provider_stmt = $pdo->prepare("
            SELECT sp.id
            FROM shops s
            JOIN service_providers sp ON sp.user_id = s.owner_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $provider_stmt->execute([$staff['shop_id']]);
        $service_provider_id = $provider_stmt->fetchColumn();

         if(!$service_provider_id) {
                $error = "Unable to locate the shop's service provider profile.";
            } else {
            $existing_stmt = $pdo->prepare("SELECT id FROM design_approvals WHERE order_id = ? LIMIT 1");
            $existing_stmt->execute([$order_id]);
            $approval_id = $existing_stmt->fetchColumn();

            if($approval_id) {
                $update_stmt = $pdo->prepare("
                    UPDATE design_approvals
                    SET design_file = ?, provider_notes = ?, status = 'pending', approved_at = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$design_file, $provider_notes ?: null, $approval_id]);
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO design_approvals (order_id, service_provider_id, design_file, provider_notes, status)
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $insert_stmt->execute([$order_id, $service_provider_id, $design_file, $provider_notes ?: null]);
            }

            $reset_stmt = $pdo->prepare("
                UPDATE orders
                SET design_approved = 0, updated_at = NOW()
                WHERE id = ?
            ");
            $reset_stmt->execute([$order_id]);

            $message = sprintf(
                'A new design proof is ready for order #%s.',
                $order_info['order_number']
            );
            $owner_message = sprintf(
                'A new design proof was uploaded for order #%s.',
                $order_info['order_number']
            );
            automation_notify_order_parties($pdo, $order_id, 'design', $message, $owner_message);

            automation_log_audit_if_available(
                $pdo,
                $staff_id,
                $staff_role,
                'design_proof_uploaded',
                'orders',
                $order_id,
                [
                    'design_approved' => $order_info['design_approved'] ?? null,
                    'status' => $order_info['status'] ?? null,
                ],
                [
                    'design_approved' => 0,
                    'status' => $order_info['status'] ?? null,
                    'design_file' => $design_file,
                    'provider_notes' => $provider_notes ?: null,
                ]
            );

             $success = 'Proof uploaded and sent to the client for approval.';
            }
        }
    }
}

if(isset($_POST['upload_digitized_file'])) {
    if(!$can_upload_proof) {
        $error = 'You do not have permission to upload digitized files.';
    } else {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $digitized_notes = sanitize($_POST['digitized_notes'] ?? '');
        $order_info = fetch_order_info($pdo, $staff_id, $order_id);

        if(!$order_info) {
            $error = 'Unable to upload digitized file for this order.';
        } elseif(!isset($_FILES['digitized_file']) || $_FILES['digitized_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid digitized file.';
        } else {
            $upload = save_uploaded_media(
                $_FILES['digitized_file'],
                ALLOWED_DIGITIZED_UPLOAD_TYPES,
                MAX_FILE_SIZE,
                'digitized',
                'digitized',
                $order_id . '_' . $staff_id
            );

            if(!$upload['success']) {
                $error = $upload['error'];
            } else {
                $stitch_file_path = 'assets/uploads/' . $upload['path'];
                $absolute_path = dirname(__DIR__) . '/' . $stitch_file_path;
                $original_name = (string) ($_FILES['digitized_file']['name'] ?? $upload['filename']);
                $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $file_size = is_file($absolute_path) ? (int) filesize($absolute_path) : (int) ($_FILES['digitized_file']['size'] ?? 0);
                $file_mime = detect_uploaded_file_mime_type($absolute_path);

                $width_px = null;
                $height_px = null;
                $detected_width_mm = null;
                $detected_height_mm = null;
                $suggested_width_mm = null;
                $suggested_height_mm = null;
                $scale_ratio = null;

                if(in_array($file_ext, ALLOWED_IMAGE_TYPES, true)) {
                    $image_dimensions = get_uploaded_image_dimensions($absolute_path);
                    $width_px = isset($image_dimensions['width_px']) ? (int) $image_dimensions['width_px'] : null;
                    $height_px = isset($image_dimensions['height_px']) ? (int) $image_dimensions['height_px'] : null;

                    if(($width_px ?? 0) > 0 && ($height_px ?? 0) > 0) {
                        $detected_width_mm = px_to_mm_estimate((int) $width_px);
                        $detected_height_mm = px_to_mm_estimate((int) $height_px);

                        if(is_cap_service_type((string) ($order_info['service_type'] ?? ''))) {
                            $cap_fit = compute_cap_fit($detected_width_mm, $detected_height_mm);
                            $suggested_width_mm = isset($cap_fit['suggested_width_mm']) ? (float) $cap_fit['suggested_width_mm'] : null;
                            $suggested_height_mm = isset($cap_fit['suggested_height_mm']) ? (float) $cap_fit['suggested_height_mm'] : null;
                            $scale_ratio = isset($cap_fit['scale_ratio']) ? (float) $cap_fit['scale_ratio'] : null;
                        }
                    }
                }

                $existing_stmt = $pdo->prepare("SELECT id FROM digitized_designs WHERE order_id = ? ORDER BY id DESC LIMIT 1");
                $existing_stmt->execute([$order_id]);
                $existing_id = (int) ($existing_stmt->fetchColumn() ?: 0);

                if($existing_id > 0) {
                    $update_stmt = $pdo->prepare("
                        UPDATE digitized_designs
                        SET digitizer_id = ?,
                            stitch_file_path = ?,
                            stitch_file_name = ?,
                            stitch_file_ext = ?,
                            stitch_file_size_bytes = ?,
                            stitch_file_mime = ?,
                            width_px = COALESCE(?, width_px),
                            height_px = COALESCE(?, height_px),
                            detected_width_mm = COALESCE(?, detected_width_mm),
                            detected_height_mm = COALESCE(?, detected_height_mm),
                            suggested_width_mm = COALESCE(?, suggested_width_mm),
                            suggested_height_mm = COALESCE(?, suggested_height_mm),
                            scale_ratio = COALESCE(?, scale_ratio)
                        WHERE id = ?
                    " );
                    $update_stmt->execute([
                        $staff_id,
                        $stitch_file_path,
                        $original_name,
                        $file_ext !== '' ? $file_ext : null,
                        $file_size > 0 ? $file_size : null,
                        $file_mime,
                        $width_px,
                        $height_px,
                        $detected_width_mm,
                        $detected_height_mm,
                        $suggested_width_mm,
                        $suggested_height_mm,
                        $scale_ratio,
                        $existing_id,
                    ]);
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO digitized_designs (
                            order_id, digitizer_id, stitch_file_path, stitch_file_name, stitch_file_ext, stitch_file_size_bytes, stitch_file_mime,
                            width_px, height_px, detected_width_mm, detected_height_mm, suggested_width_mm, suggested_height_mm, scale_ratio
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    " );
                    $insert_stmt->execute([
                        $order_id,
                        $staff_id,
                        $stitch_file_path,
                        $original_name,
                        $file_ext !== '' ? $file_ext : null,
                        $file_size > 0 ? $file_size : null,
                        $file_mime,
                        $width_px,
                        $height_px,
                        $detected_width_mm,
                        $detected_height_mm,
                        $suggested_width_mm,
                        $suggested_height_mm,
                        $scale_ratio,
                    ]);
                }

                if(($order_info['status'] ?? '') === STATUS_ACCEPTED) {
                    automation_transition_order_status(
                        $pdo,
                        $order_id,
                        STATUS_DIGITIZING,
                        $staff_id,
                        $staff_role,
                        'Digitized design file uploaded and moved to digitizing stage.',
                        false,
                        'digitized_design_uploaded'
                    );
                }

                $client_message = sprintf('A digitized design file for order #%s has been uploaded.', $order_info['order_number']);
                $owner_message = sprintf('Digitized design file uploaded for order #%s.', $order_info['order_number']);
                automation_notify_order_parties($pdo, $order_id, 'design', $client_message, $owner_message);

                automation_log_audit_if_available(
                    $pdo,
                    $staff_id,
                    $staff_role,
                    'digitized_file_uploaded',
                    'orders',
                    $order_id,
                    [
                        'status' => $order_info['status'] ?? null,
                    ],
                    [
                        'stitch_file_path' => $stitch_file_path,
                        'stitch_file_ext' => $file_ext !== '' ? $file_ext : null,
                        'stitch_file_size_bytes' => $file_size > 0 ? $file_size : null,
                        'stitch_file_mime' => $file_mime,
                        'digitized_notes' => $digitized_notes !== '' ? $digitized_notes : null,
                    ]
                );

                cleanup_media($pdo);
                $success = 'Digitized file uploaded successfully and available metadata was stored.';
            }
        }
    }
}

if(isset($_POST['escalate_issue'])) {
    if(!$can_update_status) {
        $error = 'You do not have permission to escalate issues.';
    } else {
         $order_id = (int) ($_POST['order_id'] ?? 0);
        $escalation_type = $_POST['escalate_issue'] ?? '';
        $escalation_note = sanitize($_POST['escalation_note'] ?? '');
        $valid_escalations = [
            'needs_clarification' => 'Needs clarification',
            'blocked' => 'Blocked',
        ];
        $order_info = fetch_order_info($pdo, $staff_id, $order_id);

        if(!$order_info) {
            $error = "Unable to escalate this order.";
        } elseif(!array_key_exists($escalation_type, $valid_escalations)) {
            $error = "Invalid escalation option.";
        } elseif($escalation_note === '') {
            $error = "Please add details before escalating.";
        } else {
        if(function_exists('order_exception_open')) {
            $exception_type = $escalation_type === 'blocked' ? 'assignment_blocked' : 'customer_unresponsive';
            $severity = $escalation_type === 'blocked' ? 'high' : 'medium';
            order_exception_open($pdo, $order_id, $exception_type, $severity, $escalation_note, (int) ($order_info['owner_id'] ?? 0));
        }

        $label = $valid_escalations[$escalation_type];
        $note = sprintf('[%s] %s', $label, $escalation_note);
        $update_stmt = $pdo->prepare("
            UPDATE orders 
            SET shop_notes = CONCAT(COALESCE(shop_notes, ''), '\n', ?), updated_at = NOW()
            WHERE id = ? AND (assigned_to = ? OR EXISTS (
                SELECT 1 FROM job_schedule js WHERE js.order_id = orders.id AND js.staff_id = ?
            ))
        ");
        $update_stmt->execute([$note, $order_id, $staff_id, $staff_id]);

        $message = sprintf(
            'Order #%s needs attention: %s.',
            $order_info['order_number'],
            strtolower($label)
        );
        $client_message = $escalation_type === 'needs_clarification' ? $message : '';
        automation_notify_order_parties($pdo, $order_id, 'warning', $client_message, $message);

        if($escalation_type === 'blocked') {
            $qc_message = sprintf(
                'QC failed for order #%s. Reason: %s',
                $order_info['order_number'],
                $escalation_note
            );
            notify_business_event($pdo, 'qc_failed', $order_id, [
                'message' => $qc_message,
                'actor_id' => $staff_id,
            ]);
            automation_log_audit_if_available(
                $pdo,
                $staff_id,
                $staff_role,
                'qc_failed',
                'orders',
                $order_id,
                ['qc_stage' => 'in_progress'],
                ['qc_stage' => 'failed', 'reason' => $escalation_note]
            );
        }

         $success = $label . " request sent successfully.";
        }
    }
}

if(isset($_POST['upload_photo'])) {
    if(!$can_upload_photos) {
        $error = 'You do not have permission to upload photos.';
    } else {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $caption = sanitize($_POST['caption'] ?? '');
        $order_info = fetch_order_info($pdo, $staff_id, $order_id);

        if(!$order_info) {
            $error = 'Please select a valid job.';
        } elseif(!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid image file.';
        } else {
            $upload = save_uploaded_media(
                $_FILES['photo'],
                ALLOWED_IMAGE_TYPES,
                MAX_FILE_SIZE,
                'job_photos',
                'job',
                $order_id . '_' . $staff_id
            );

            if(!$upload['success']) {
                $error = $upload['error'] === 'File size exceeds the limit.'
                    ? 'File size exceeds the 5MB limit.'
                    : 'Only JPG, JPEG, PNG, and GIF files are allowed.';
            } else {
                $photo_path = media_public_path('job_photos', $upload['filename']);
                $photo_stmt = $pdo->prepare('
                    INSERT INTO order_photos (order_id, staff_id, photo_url, caption)
                    VALUES (?, ?, ?, ?)
                ');
                $photo_stmt->execute([$order_id, $staff_id, $photo_path, $caption]);
                $photo_counts[$order_id] = ($photo_counts[$order_id] ?? 0) + 1;
                cleanup_media($pdo);
                $success = 'Photo uploaded successfully! You can now update the job status.';
            }
        }
    }
}

if(isset($_POST['update_status'])) {
    if(!$can_update_status) {
        $error = 'You do not have permission to update job status.';
    } else {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $selected_stage = $_POST['workflow_stage'] ?? '';
        $staff_notes = sanitize($_POST['staff_notes'] ?? '');

    if(!isset($stage_options[$selected_stage])) {
            $error = 'Invalid production stage selected.';
        }

    $photo_check_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM order_photos
            WHERE order_id = ? AND staff_id = ?
        ");
        $photo_check_stmt->execute([$order_id, $staff_id]);
        $photo_count = (int) $photo_check_stmt->fetchColumn();

        $order_info = fetch_order_info($pdo, $staff_id, $order_id);
        $allowed_statuses = [STATUS_DIGITIZING, STATUS_PRODUCTION, STATUS_QC_PENDING];

    if(!isset($error) && !$order_info) {
            $error = "Unable to update this order.";
        } elseif(!isset($error) && $photo_count === 0 && $selected_stage !== 'digitizing_started') {
            $error = "Please upload a progress photo before updating the status.";
        }

    if(!isset($error)) {
            $target_status = $stage_options[$selected_stage]['status'];
            $target_progress = (int) $stage_options[$selected_stage]['progress'];
            $base_label = $stage_options[$selected_stage]['label'];
            $current_status = (string) ($order_info['status'] ?? '');
            $design_is_approved = order_workflow_is_design_approved($pdo, $order_id);

            if(!in_array($target_status, $allowed_statuses, true)) {
                $error = 'Invalid status selection.';
            } elseif(in_array($selected_stage, ['materials_ready', 'in_process_of_making'], true)) {
                if(!$design_is_approved && $current_status !== STATUS_PRODUCTION) {
                    $error = 'Design proof approval is required before production can begin.';
                }
            }

            if(!isset($error) && $selected_stage === 'materials_ready') {
                $can_start_materials = in_array($current_status, [STATUS_DIGITIZING, STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION], true)
                    || ($current_status === STATUS_ACCEPTED && $design_is_approved);
                if(!$can_start_materials) {
                    $error = 'Materials can be marked ready only after digitizing, or from accepted once the design is approved.';
                } else {
                    $target_status = STATUS_PRODUCTION;
                }
            }

            if(!isset($error) && $selected_stage === 'in_process_of_making') {
                $target_status = STATUS_PRODUCTION;
                $target_progress = max($target_progress, 60);
            }
            
            if(!isset($error) && $selected_stage === 'order_complete') {
                $target_status = STATUS_PRODUCTION;
            }
        }

            if(!isset($error)) {
            $stage_meta = [
                'digitizing_started' => [
                    'history' => 'Digitizing started.',
                    'client_message' => sprintf('Order #%s entered digitizing for stitch-file preparation.', $order_info['order_number']),
                    'owner_message' => sprintf('Order #%s is now in digitizing stage.', $order_info['order_number']),
                    'notification_type' => 'order_status',
                ],
                'materials_ready' => [
                    'history' => 'Materials ready.',
                    'client_message' => sprintf('Materials are ready for order #%s.', $order_info['order_number']),
                    'owner_message' => null,
                    'notification_type' => 'order_status',
                ],
                'in_process_of_making' => [
                    'history' => 'Production is in progress.',
                    'client_message' => sprintf('Production is in progress for order #%s.', $order_info['order_number']),
                    'owner_message' => null,
                    'notification_type' => 'order_status',
                ],
                'order_complete' => [
                    'history' => 'Production marked finished; queued for quality control.',
                    'client_message' => sprintf('Order #%s production is finished and queued for quality control.', $order_info['order_number']),
                    'owner_message' => sprintf('Order #%s has been marked as production finished and queued for QC.', $order_info['order_number']),
                    'notification_type' => 'success',
                ],
            ];

            $order_before = [
                'status' => $order_info['status'] ?? null,
                'progress' => (int) ($order_info['progress'] ?? 0),
            ];
            $history_note = $stage_meta[$selected_stage]['history'];
            $history_with_staff_note = trim($history_note . ($staff_notes !== '' ? ' ' . $staff_notes : ''));

            $order_state = $order_info;
            [$can_transition, $transition_error] = order_workflow_validate_order_status($pdo, $order_state, $target_status);
            if(!$can_transition) {
                $error = $transition_error ?: 'Status transition not allowed from the current state.';
            }

            if(!isset($error)) {
                try {
                    $current_progress = (int) ($order_info['progress'] ?? 0);
                    $next_progress = keep_progress_forward($current_progress, $target_progress);

                    $status_changed = (($order_info['status'] ?? '') !== $target_status);
                    if($selected_stage === 'order_complete') {
                        [$transition_ok, $transition_error] = automation_apply_order_event_transition(
                            $pdo,
                            $order_id,
                            'production_finished',
                            $staff_id,
                            $staff_role,
                            false,
                            'Production marked finished by operator.'
                        );
                        if(!$transition_ok) {
                            throw new RuntimeException($transition_error ?: 'Failed to move order to QC pending.');
                        }
                        $target_status = STATUS_QC_PENDING;
                        qc_create_pending_record($pdo, $order_id, 'Queued for quality control after production completion.');
                    } elseif($status_changed) {
                        $status_stmt = $pdo->prepare("
                            UPDATE orders
                            SET status = ?, updated_at = NOW()
                            WHERE id = ? AND (assigned_to = ? OR EXISTS (
                                SELECT 1 FROM job_schedule js WHERE js.order_id = orders.id AND js.staff_id = ?
                            ))
                        ");
                        $status_stmt->execute([$target_status, $order_id, $staff_id, $staff_id]);
                        if($status_stmt->rowCount() <= 0) {
                            throw new RuntimeException('Failed to update order status.');
                        }
                    }

                    $progress_stmt = $pdo->prepare("
                        UPDATE orders
                        SET progress = ?, shop_notes = CONCAT(COALESCE(shop_notes, ''), '\n', ?), updated_at = NOW()
                        WHERE id = ? AND (assigned_to = ? OR EXISTS (
                            SELECT 1 FROM job_schedule js WHERE js.order_id = orders.id AND js.staff_id = ?
                        ))
                    ");
                    $progress_stmt->execute([
                        $next_progress,
                        trim($base_label . ($staff_notes !== '' ? ': ' . $staff_notes : '')),
                        $order_id,
                        $staff_id,
                        $staff_id,
                    ]);

                    if($target_status === STATUS_COMPLETED || $target_status === STATUS_DELIVERED) {
                        $complete_stmt = $pdo->prepare("UPDATE orders SET completed_at = COALESCE(completed_at, NOW()) WHERE id = ?");
                        $complete_stmt->execute([$order_id]);
                    }

                    if(function_exists('update_order_estimated_completion')) {
                        update_order_estimated_completion($pdo, $order_id);
                    }

                    $order_qty = max(1, (int) ($order_info['quantity'] ?? 1));
                    $production_inventory_log = null;
                    $is_first_production_entry = ($order_before['status'] ?? '') !== STATUS_IN_PROGRESS
                        && $target_status === STATUS_PRODUCTION;
                    if($is_first_production_entry) {
                        [$production_inventory_ok, $production_inventory_error, $production_inventory_log] = automation_log_production_start_inventory(
                            $pdo,
                            (int) ($order_info['shop_id'] ?? 0),
                            $order_id,
                            $order_qty
                        );

                        if(!$production_inventory_ok) {
                            throw new RuntimeException($production_inventory_error ?: 'Unable to log production-start inventory transaction.');
                        }

                        $machineAssignment = auto_assign_order_to_machine(
                            $pdo,
                            (int) ($order_info['shop_id'] ?? 0),
                            $order_id
                        );

                        if(!($machineAssignment['assigned'] ?? false)) {
                            $success_note = $success_note ?? [];
                            $success_note[] = $machineAssignment['message'] ?? 'Machine scheduling skipped.';
                        }
                        
                        if(function_exists('update_order_estimated_completion')) {
                            update_order_estimated_completion($pdo, $order_id);
                        }
                        
                        notify_business_event($pdo, 'production_started', $order_id, [
                            'actor_id' => $staff_id,
                            'message' => sprintf('Production started for order #%s.', $order_info['order_number']),
                        ]);
                    }

                    record_order_status_history(
                        $pdo,
                        $order_id,
                        $target_status,
                        $next_progress,
                        $history_with_staff_note !== '' ? $history_with_staff_note : null,
                        $staff_id
                    );

                    automation_notify_order_parties(
                        $pdo,
                        $order_id,
                        $stage_meta[$selected_stage]['notification_type'],
                        $stage_meta[$selected_stage]['client_message'],
                        $stage_meta[$selected_stage]['owner_message']
                    );

                    $status_action = $selected_stage === 'digitizing_started'
                        ? 'digitizing_status_changed'
                        : 'production_status_changed';

                    automation_log_audit_if_available(
                        $pdo,
                        $staff_id,
                        $staff_role,
                        $status_action,
                        'orders',
                        $order_id,
                        [
                            'status' => $order_before['status'] ?? null,
                            'progress' => $order_before['progress'] ?? null,
                        ],
                        [
                            'status' => $target_status,
                            'progress' => $next_progress,
                            'stage' => $selected_stage,
                        ]
                    );

                    $success = 'Status updated successfully!';
                    if(!empty($success_note)) {
                        $success .= ' ' . implode(' ', $success_note);
                    }
                } catch(Throwable $e) {
                    $error = 'Failed to update status: ' . $e->getMessage();
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Job Status - staff Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .job-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .progress-slider {
            width: 100%;
            margin: 20px 0;
        }
        .status-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .status-option {
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .status-option:hover {
            border-color: #4361ee;
        }
        .status-option.selected {
            border-color: #4361ee;
            background: #4361ee;
            color: white;
        }
        .section-header {
            font-weight: 600;
            margin-bottom: 10px;
            color: #343a40;
        }
        .job-steps {
            display: grid;
            gap: 10px;
            margin-bottom: 20px;
        }
        .step-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
            color: #495057;
        }
        .step-item.completed {
            border-color: #16a34a;
            background: #ecfdf3;
            color: #14532d;
        }
        .step-index {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #e9ecef;
            color: #495057;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            margin-right: 10px;
        }
        .step-item.completed .step-index {
            background: #16a34a;
            color: white;
        }
        .escalation-panel {
            border: 1px dashed #f59e0b;
            border-radius: 10px;
            padding: 16px;
            background: #fffbeb;
        }
        .escalation-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .photo-upload {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
        }
        .design-preview {
            margin-top: 12px;
        }
        .design-preview img {
            width: 100%;
            max-width: 320px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .design-file {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/employee_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header">
            <h2>Update Job Status</h2>
            <p class="text-muted">Update progress and status of your assigned jobs</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if(!empty($jobs)): ?>
            <div class="card mb-4">
                <div class="job-details">
                    <h4 class="mb-2">Assigned Jobs</h4>
                    <p class="text-muted mb-3">Select a job to view full order details and update workflow status.</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Service</th>
                                    <th>Client</th>
                                    <th>Current Status</th>
                                    <th>Exceptions</th>
                                    <th>Progress</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($jobs as $job): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($job['order_number']); ?></td>
                                        <td><?php echo htmlspecialchars($job['service_type']); ?></td>
                                        <td><?php echo htmlspecialchars($job['client_name']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $job['status'])); ?></td>
                                        <td>
                                            <?php $exception_summary = $exception_summary_map[(int) $job['id']] ?? null; ?>
                                            <?php if($exception_summary && (int) ($exception_summary['open_count'] ?? 0) > 0): ?>
                                                <span class="badge badge-<?php echo !empty($exception_summary['has_blocking']) ? 'danger' : 'warning'; ?>">
                                                    <?php echo (int) $exception_summary['open_count']; ?> open
                                                    <?php if((int) ($exception_summary['escalated_count'] ?? 0) > 0): ?>
                                                        (<?php echo (int) $exception_summary['escalated_count']; ?> escalated)
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int) $job['progress']; ?>%</td>
                                        <td>
                                            <a class="btn btn-outline-primary btn-sm" href="update_status.php?order_id=<?php echo (int) $job['id']; ?>">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php if($selected_job): ?>
                <?php
                 $job = $selected_job;
                    $has_photo = !empty($photo_counts[$job['id']]);
                    $payment_hold = payment_hold_status($job['status'] ?? STATUS_PENDING, $job['payment_status'] ?? 'unpaid');
                    $selected_exception_summary = $exception_summary_map[(int) ($job['id'] ?? 0)] ?? ['open_count' => 0, 'escalated_count' => 0, 'has_blocking' => false];
                ?>
            <div class="card mb-4">
                    <div class="job-details">
                        <div class="d-flex justify-between align-center">
                            <div>
                                <h4><?php echo htmlspecialchars($job['service_type']); ?></h4>
                                <p class="mb-1">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($job['client_name']); ?> |
                                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($job['shop_name']); ?> |
                                    Order #<?php echo $job['order_number']; ?>
                                </p>
                                <p class="mb-0 text-muted"><?php echo htmlspecialchars($job['design_description']); ?></p>
                                <?php if(!empty($job['client_notes'])): ?>
                                    <p class="mb-0 text-muted"><strong>Client notes:</strong> <?php echo htmlspecialchars($job['client_notes']); ?></p>
                                <?php endif; ?>
                            <?php if(!empty($job['design_file'])): ?>
                                    <div class="design-file">
                                        <a href="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" target="_blank" rel="noopener noreferrer">
                                            <i class="fas fa-paperclip"></i> View design file
                                        </a>
                                    </div>
                                    <?php if(is_design_image($job['design_file'])): ?>
                                        <div class="design-preview">
                                            <img src="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" alt="Client design upload">
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if($latest_digitized_design): ?>
                                    <div class="mt-2"> 
                                        <p class="mb-1 text-muted"><strong>Latest digitized file:</strong>
                                            <?php if(!empty($latest_digitized_design['stitch_file_path'])): ?>
                                                <a href="../<?php echo ltrim((string) $latest_digitized_design['stitch_file_path'], '/'); ?>" target="_blank" rel="noopener">View file</a>
                                            <?php else: ?>
                                                <span>No file uploaded yet.</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-1 text-muted"><strong>Uploaded by:</strong> <?php echo htmlspecialchars((string) ($latest_digitized_design['digitizer_name'] ?? 'Unknown')); ?> on <?php echo !empty($latest_digitized_design['created_at']) ? date('M d, Y h:i A', strtotime((string) $latest_digitized_design['created_at'])) : 'N/A'; ?></p>
                                        <?php if(!empty($latest_digitized_design['stitch_file_ext']) || !empty($latest_digitized_design['stitch_file_size_bytes'])): ?>
                                            <p class="mb-0 text-muted">Type: <?php echo htmlspecialchars(strtoupper((string) ($latest_digitized_design['stitch_file_ext'] ?? ''))); ?> · Size: <?php echo format_bytes_human(isset($latest_digitized_design['stitch_file_size_bytes']) ? (int) $latest_digitized_design['stitch_file_size_bytes'] : 0); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if(!empty($job['schedule_date'])): ?>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-calendar"></i> Scheduled: <?php echo date('M d, Y', strtotime($job['schedule_date'])); ?>
                                        <?php if(!empty($job['schedule_time'])): ?>
                                            <span class="ml-1"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($job['schedule_time'])); ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <div class="stat-number"><?php echo $job['progress']; ?>%</div>
                                <div class="stat-label">Current Progress</div>
                                <div class="mt-2">
                                    <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                        Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                                    </span>
                                </div>
                                <?php if((int) ($selected_exception_summary['open_count'] ?? 0) > 0): ?>
                                    <div class="mt-2 text-danger small">
                                        Open exceptions: <?php echo (int) $selected_exception_summary['open_count']; ?>
                                        <?php if((int) ($selected_exception_summary['escalated_count'] ?? 0) > 0): ?>
                                            (<?php echo (int) $selected_exception_summary['escalated_count']; ?> escalated)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if($can_upload_proof): ?>
                        <form method="POST" enctype="multipart/form-data" class="photo-upload">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                            <div class="section-header">Digitized File Upload</div>
                            <div class="form-group">
                                <label>Upload Digitized/Design File</label>
                                <input type="file" name="digitized_file" class="form-control" accept=".dst,.pes,.emb,.jef,.exp,.vp3,.hus,.sew,.xxx,.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" required>
                                <small class="text-muted">Max 5MB. Supported: DST, PES, EMB, JEF, EXP, VP3, HUS, SEW, XXX, image, and document files.</small>
                            </div>
                            <div class="form-group">
                                <label>Digitizer Notes (Optional)</label>
                                <textarea name="digitized_notes" class="form-control" rows="2" placeholder="Add context for client/owner..."></textarea>
                            </div>
                            <div class="text-right">
                                <button type="submit" name="upload_digitized_file" class="btn btn-outline-primary">
                                    <i class="fas fa-file-upload"></i> Upload Digitized File
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                <?php if($can_upload_photos): ?>
                        <form method="POST" enctype="multipart/form-data" class="photo-upload">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                            <div class="section-header">Progress Photos</div>
                            <div class="form-group">
                                <label>Upload Photo</label>
                                <input type="file" name="photo" class="form-control" accept="image/*" required>
                                <small class="text-muted">Max 5MB, JPG/PNG/GIF.</small>
                            </div>
                            <div class="form-group">
                                <label>Caption (Optional)</label>
                                <textarea name="caption" class="form-control" rows="2" placeholder="Add a short update..."></textarea>
                            </div>
                            <div class="d-flex justify-between align-center">
                                <small class="text-muted">Uploaded photos for this job: <?php echo (int) ($photo_counts[$job['id']] ?? 0); ?></small>
                                <button type="submit" name="upload_photo" class="btn btn-outline-primary">
                                    <i class="fas fa-camera"></i> Upload Photo
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if($can_update_status): ?>
                    <form method="POST" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                         <div class="section-header">Update Order Stage</div>
                        <div class="form-group">
                            <label>Workflow Status</label>
                            <select name="workflow_stage" class="form-control" required>
                                <option value="">Select status update</option>
                                <?php foreach($stage_options as $stage_key => $stage): ?>
                                    <option value="<?php echo htmlspecialchars($stage_key); ?>"><?php echo htmlspecialchars($stage['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Choose: digitizing started, materials ready, in the process of making, order complete, or ready to pickup.</small>
                        </div>
                        <div class="form-group">
                           <label>Add Notes (Optional)</label>
                            <textarea name="staff_notes" class="form-control" rows="3" placeholder="Add any notes about this update..."></textarea>
                        </div>
                         <?php if(!$has_photo): ?>
                            <div class="alert alert-warning">Please upload a progress photo before most production updates. Digitizing start can be logged without a photo.</div>
                        <?php endif; ?>
                        <div class="text-right">
                            <button type="submit" name="update_status" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                        </div>
                    </form>

                    <form method="POST" class="mt-3">
                        <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                        <div class="section-header">Escalation Path</div>
                        <div class="escalation-panel">
                            <p class="text-muted mb-2">Use this when you need a decision or you're blocked.</p>
                            <div class="form-group">
                                <label>Escalation Details</label>
                                <textarea name="escalation_note" class="form-control" rows="3" placeholder="Describe what you need to move forward..." required></textarea>
                            </div>
                            <div class="escalation-actions">
                                <button type="submit" name="escalate_issue" value="needs_clarification" class="btn btn-warning">
                                    <i class="fas fa-question-circle"></i> Needs Clarification
                                </button>
                                <button type="submit" name="escalate_issue" value="blocked" class="btn btn-danger">
                                    <i class="fas fa-ban"></i> Blocked
                                </button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
 </div>
            <?php else: ?>
                <div class="card mb-4">
                    <div class="text-center p-4">
                        <i class="fas fa-eye fa-3x text-muted mb-3"></i>
                        <h4>Select a Job to View Details</h4>
                        <p class="text-muted">Click the View button from the Assigned Jobs list.</p>
                    </div>
                 </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h4>No Jobs to Update</h4>
                    <p class="text-muted">You don't have any assigned jobs that need status updates.</p>
                    <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>