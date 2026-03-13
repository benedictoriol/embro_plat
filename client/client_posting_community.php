<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$form_error = '';
$form_success = '';
$comment_error = '';
$comment_success = '';
$client_posts = [];
$community_table_exists = table_exists($pdo, 'client_community_posts');
$community_price_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'preferred_price');
$community_comments_table_exists = table_exists($pdo, 'community_post_comments');
$reports_table_exists = table_exists($pdo, 'content_reports');
$report_error = '';
$report_success = '';

if ($community_table_exists || $community_comments_table_exists || $reports_table_exists) {
    try {
        ensure_content_moderation_schema($pdo);
    } catch (Throwable $e) {
        if (app_is_development()) {
            $report_error = 'Moderation schema patch failed: ' . $e->getMessage();
        }
    }
}

$post_is_hidden_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'is_hidden');
$post_is_removed_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'is_removed');
$post_moderation_note_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'moderation_note');

$comment_is_hidden_exists = $community_comments_table_exists && column_exists($pdo, 'community_post_comments', 'is_hidden');
$comment_is_removed_exists = $community_comments_table_exists && column_exists($pdo, 'community_post_comments', 'is_removed');
$comment_moderation_note_exists = $community_comments_table_exists && column_exists($pdo, 'community_post_comments', 'moderation_note');

function save_base64_design_preview(string $data_url, int $client_id): array {
    $parts = explode(',', $data_url, 2);
    if (count($parts) !== 2 || strpos($parts[0], 'data:image/') !== 0) {
        return ['success' => false, 'error' => 'Design preview format is invalid.'];
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg|gif);base64$/i', $parts[0], $matches)) {
        return ['success' => false, 'error' => 'Unsupported design preview image type.'];
    }

    $binary = base64_decode($parts[1], true);
    if ($binary === false) {
        return ['success' => false, 'error' => 'Unable to decode the design preview image.'];
    }

    if (strlen($binary) > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'Design preview exceeds the 5 MB upload limit.'];
    }

    $extension = strtolower($matches[1] === 'jpeg' ? 'jpg' : $matches[1]);
    $safe_prefix = preg_replace('/[^a-z0-9_-]+/i', '_', 'community_post');
    $filename = $client_id . '_' . uniqid($safe_prefix . '_', true) . '.' . $extension;
    $target_dir = media_upload_dir('community_posts');
    $destination = $target_dir . '/' . $filename;

    if (file_put_contents($destination, $binary) === false) {
        return ['success' => false, 'error' => 'Failed to save design preview image.'];
    }

    return ['success' => true, 'path' => media_public_path('community_posts', $filename)];
}

if (!$community_table_exists) {
    $form_error = 'Community posts are unavailable because the database schema is missing the client_community_posts table. Please import the latest embroidery_platform.sql.';
 } elseif (!$community_price_exists) {
    $form_error = 'Community posts need the preferred_price column so customers can set a project budget. Please import the latest embroidery_platform.sql.';
}

if (!$community_comments_table_exists && $form_error === '') {
    $comment_error = 'Comment features are unavailable because the community_post_comments table is missing. Please import the latest embroidery_platform.sql.';
}


if ($reports_table_exists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $target_type = sanitize($_POST['target_type'] ?? '');
    $target_id = (int) ($_POST['target_id'] ?? 0);
    $reason = sanitize($_POST['report_reason'] ?? '');
    $notes = trim($_POST['report_notes'] ?? '');

    if (!in_array($target_type, ['community_post', 'community_comment'], true) || $target_id <= 0 || $reason === '') {
        $report_error = 'Please choose a valid reason before submitting your report.';
    } else {
        $result = moderation_create_report($pdo, $client_id, $target_type, $target_id, $reason, $notes);
        if (!($result['ok'] ?? false)) {
            $report_error = $result['message'] ?? 'Unable to submit your report right now.';
        } else {
            $report_success = 'Report submitted. Moderators will review it shortly.';
        }
    }
}

if ($community_table_exists && $community_price_exists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $title = sanitize($_POST['title'] ?? '');
   $category = 'Request';
    $description = sanitize($_POST['description'] ?? '');
    $preferred_price = trim($_POST['preferred_price'] ?? '');
    $desired_quantity = trim($_POST['desired_quantity'] ?? '');
    $target_date = trim($_POST['target_date'] ?? '');
    $design_preview = trim($_POST['design_preview'] ?? '');
    $image_path = null;


     if ($title === '' || $description === '' || $preferred_price === '') {
        $form_error = 'Please complete the required fields to publish your post.';
     }

     if ($form_error === '' && (!is_numeric($preferred_price) || (float) $preferred_price <= 0)) {
        $form_error = 'Please enter a valid target budget greater than zero.';
    }

    if ($form_error === '' && isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = save_uploaded_media(
            $_FILES['reference_image'],
            ALLOWED_IMAGE_TYPES,
            MAX_FILE_SIZE,
            'community_posts',
            'community_post',
            (string) $client_id
        );
        if (!$upload['success']) {
            $form_error = $upload['error'];
        } else {
            $image_path = $upload['path'];
        }
    }

    if ($form_error === '' && $image_path === null && $design_preview !== '') {
        $preview_upload = save_base64_design_preview($design_preview, $client_id);
        if (!$preview_upload['success']) {
            $form_error = $preview_upload['error'];
        } else {
            $image_path = $preview_upload['path'];
        }
    }

    if ($form_error === '') {
        $insert_stmt = $pdo->prepare("
            INSERT INTO client_community_posts
                 (client_id, title, category, description, preferred_price, desired_quantity, target_date, image_path, status, created_at)
            VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())
        ");
        $insert_stmt->execute([
            $client_id,
            $title,
            $category,
            $description,
            (float) $preferred_price,
            $desired_quantity !== '' ? $desired_quantity : null,
            $target_date !== '' ? $target_date : null,
            $image_path,
        ]);
        $form_success = 'Your post is now live for shop owners to review.';
    }
}

if ($community_table_exists && $community_price_exists) {
    $post_select_fields = [
        'id',
        'title',
        'category',
        'description',
        'preferred_price',
        'desired_quantity',
        'target_date',
        'image_path',
        'status',
        $post_is_hidden_exists ? 'is_hidden' : '0 AS is_hidden',
        $post_is_removed_exists ? 'is_removed' : '0 AS is_removed',
        $post_moderation_note_exists ? 'moderation_note' : 'NULL AS moderation_note',
        'created_at',
    ];

    $client_posts_stmt = $pdo->prepare("
        SELECT " . implode(', ', $post_select_fields) . "
        FROM client_community_posts
        WHERE client_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $client_posts_stmt->execute([$client_id]);
    $client_posts = $client_posts_stmt->fetchAll();
}

$post_comments_map = [];
if ($community_comments_table_exists && !empty($client_posts)) {
    $post_ids = array_map(static function (array $post): int {
        return (int) $post['id'];
    }, $client_posts);

    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $comment_select_fields = [
        'c.id',
        'c.post_id',
        'c.commenter_role',
        'c.comment_text',
        $comment_is_hidden_exists ? 'c.is_hidden' : '0 AS is_hidden',
        $comment_is_removed_exists ? 'c.is_removed' : '0 AS is_removed',
        $comment_moderation_note_exists ? 'c.moderation_note' : 'NULL AS moderation_note',
        'c.created_at',
        'u.fullname AS commenter_name',
        's.id AS shop_id',
        's.shop_name',
    ];

    $comments_stmt = $pdo->prepare("\n        SELECT " . implode(",\n               ", $comment_select_fields) . "
        FROM community_post_comments c
        LEFT JOIN users u ON c.commenter_user_id = u.id
        LEFT JOIN shops s ON c.shop_id = s.id
        WHERE c.post_id IN ($placeholders)
        ORDER BY c.created_at DESC
    ");
    $comments_stmt->execute($post_ids);

    foreach ($comments_stmt->fetchAll(PDO::FETCH_ASSOC) as $comment_row) {
        $post_comments_map[(int) $comment_row['post_id']][] = $comment_row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Posting &amp; Community Interaction Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .community-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

         .post-form {
            display: grid;
            gap: 1rem;
        }

        .post-form textarea {
            min-height: 140px;
            resize: vertical;
        }

        .post-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray-500);
        }

        .post-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
            width: 345px;
            height: 450px;
            max-width: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

         .post-image {
            width: 100%;
            height: 180px;
            border-radius: var(--radius);
            object-fit: cover;
            margin-bottom: 0.75rem;
        }

        .post-card .comment-section {
            margin-top: auto;
            overflow-y: auto;
            max-height: 165px;
        }

        @media (max-width: 576px) {
            .post-card {
                width: 100%;
                height: auto;
                min-height: 450px;
            }
        }

        .comment-section {
            border-top: 1px solid var(--gray-200);
            margin-top: 1rem;
            padding-top: 1rem;
        }

        .comment-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .editor-design-preview {
            display: none;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-secondary);
            margin-bottom: 1rem;
        }

        .editor-design-preview img {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: #fff;
        }
        
        .upload-preview {
            display: none;
            margin-top: 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
        }

        .upload-preview img {
            width: 100%;
            max-height: 220px;
            object-fit: contain;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: #fff;
            margin-top: 0.5rem;
        }

        .upload-preview .file-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.6rem;
            border: 1px solid var(--gray-300);
            border-radius: 999px;
            font-size: 0.85rem;
            background: var(--bg-primary);
        }
        
        .price-estimate-box {
            display: none;
            margin-top: 0.75rem;
            border: 1px dashed var(--primary-color);
            border-radius: var(--radius);
            background: rgba(59, 130, 246, 0.06);
            padding: 0.75rem;
        }

        .price-estimate-box strong {
            display: block;
            margin-bottom: 0.25rem;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>


    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Client Posting &amp; Community Interaction</h2>
                    <p class="text-muted">Publish project requests and manage your recent community posts.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-comments"></i> Module 20</span>
            </div>
        </div>

        <div class="community-grid">
             <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-pen-nib text-primary"></i> Create a community post</h3>
                    <p class="text-muted mb-0">Let shop owners know what you need and when you need it.</p>
                </div>
                <?php if ($form_error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if ($form_success): ?>
                    <div class="alert alert-success mb-3"><?php echo htmlspecialchars($form_success); ?></div>
                <?php endif; ?>
                <?php if ($comment_error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($comment_error); ?></div>
                <?php endif; ?>
                <?php if ($comment_success): ?>
                    <div class="alert alert-success mb-3"><?php echo htmlspecialchars($comment_success); ?></div>
                <?php endif; ?>
                <?php if ($report_error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($report_error); ?></div>
                <?php endif; ?>
                <?php if ($report_success): ?>
                    <div class="alert alert-success mb-3"><?php echo htmlspecialchars($report_success); ?></div>
                <?php endif; ?>
                <form method="POST" class="post-form" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" id="design_preview" name="design_preview" value="">
                    <div id="editorDesignPreview" class="editor-design-preview">
                        <h4 class="mb-2"><i class="fas fa-wand-magic-sparkles text-primary"></i> Design imported from editor</h4>
                        <img id="editorDesignPreviewImage" src="" alt="Design editor preview">
                        <p class="text-muted mt-2 mb-1" id="editorDesignEstimateText"></p>
                        <small class="text-muted" id="editorDesignDetailsText"></small>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label for="title">Post title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g., Hoodie embroidery for fall launch" required>
                        </div>
                    </div>
                    <div>
                        <label for="description">Project details</label>
                        <textarea id="description" name="description" class="form-control" placeholder="Share the embroidery placement, materials, budget range, or any references." required></textarea>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label for="preferred_price">Target budget (₱)</label>
                            <input type="number" id="preferred_price" name="preferred_price" class="form-control" min="1" step="0.01" placeholder="e.g., 7500" required>
                        </div>
                        <div>
                            <label for="desired_quantity">Estimated quantity (optional)</label>
                            <input type="number" id="desired_quantity" name="desired_quantity" class="form-control" min="1" placeholder="e.g., 150">
                        </div>
                        <div>
                            <label for="target_date">Target delivery date (optional)</label>
                            <input type="date" id="target_date" name="target_date" class="form-control">
                        </div>
                    </div>
                    <div>
                        <label for="reference_image">Add a reference image (optional)</label>
                        <input type="file" id="reference_image" name="reference_image" class="form-control" accept="image/*">
                        <small class="text-muted">JPG, PNG, or GIF up to 5 MB. Price is auto-estimated from design size and color complexity.</small>
                        <div id="uploadPreview" class="upload-preview" aria-live="polite">
                            <small id="uploadFileName" class="file-pill"></small>
                            <img id="uploadPreviewImage" src="" alt="Selected upload preview">
                        </div>
                        <div id="uploadEstimate" class="price-estimate-box" aria-live="polite">
                            <strong id="uploadEstimateTitle"></strong>
                            <small class="text-muted" id="uploadEstimateDetails"></small>
                        </div>
                    </div>
                    <button type="submit" name="submit_post" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Post
                    </button>
                </form>
            </div>

            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-list text-primary"></i> Your recent posts</h3>
                    <p class="text-muted mb-0">Track what you have shared with shop owners.</p>
                </div>
                <?php if (empty($client_posts)): ?>
                    <p class="text-muted mb-0">You have not created any community posts yet.</p>
                <?php else: ?>
                    <div class="flow-list">
                        <?php foreach ($client_posts as $post): ?>
                            <div class="post-card">
                                <div class="d-flex justify-between align-center mb-2">
                                    <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                    <span class="badge badge-primary"><?php echo htmlspecialchars($post['category']); ?></span>
                                </div>
                                  <?php if (!empty($post['image_path'])): ?>
                                    <img class="post-image" src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="Post reference image">
                                <?php endif; ?>
                                <p class="text-muted mb-2"><?php echo htmlspecialchars(mb_strimwidth((string) $post['description'], 0, 95, '…')); ?></p>
                                <div class="post-meta">
                                    <span><i class="fas fa-peso-sign"></i> Est. Budget ₱<?php echo number_format((float) ($post['preferred_price'] ?? 0), 2); ?></span>
                                    <?php if (!empty($post['target_date'])): ?>
                                        <span><i class="fas fa-clock"></i> Turnaround <?php echo date('M d, Y', strtotime($post['target_date'])); ?></span>
                                    <?php endif; ?>
                                </div>

                                <details class="comment-section">
                                    <summary class="text-primary">Details & shop suggestions</summary>
                                    <div class="post-meta mt-2">
                                        <span><i class="fas fa-calendar"></i> Posted <?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                                        <?php if (!empty($post['desired_quantity'])): ?>
                                            <span><i class="fas fa-box"></i> Qty <?php echo htmlspecialchars($post['desired_quantity']); ?></span>
                                        <?php endif; ?>
                                        <span><i class="fas fa-signal"></i> <?php echo htmlspecialchars(ucfirst($post['status'])); ?></span>
                                        <?php if ((int) ($post['is_hidden'] ?? 0) === 1 || (int) ($post['is_removed'] ?? 0) === 1): ?>
                                            <span class="badge badge-warning">Moderated</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2">
                                        
                                    <?php if ($reports_table_exists): ?>
                                        <details class="mt-2">
                                            <summary class="text-danger small"><i class="fas fa-flag"></i> Report this post</summary>
                                            <form method="POST" class="mt-2">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="target_type" value="community_post">
                                                <input type="hidden" name="target_id" value="<?php echo (int) $post['id']; ?>">
                                                <select name="report_reason" class="form-control mb-2" required>
                                                    <option value="">Select reason</option>
                                                    <option>Spam</option><option>Harassment</option><option>Hate speech</option><option>Inappropriate content</option><option>Fraud / scam</option><option>Other</option>
                                                </select>
                                                <textarea name="report_notes" class="form-control mb-2" rows="2" maxlength="500" placeholder="Optional details"></textarea>
                                                <button type="submit" name="submit_report" class="btn btn-outline-danger btn-sm">Submit report</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                    <h5 class="mb-2"><i class="fas fa-store text-primary"></i> Shop comments (service suggestions)</h5>
                                    <?php
                                        $post_comments = $post_comments_map[(int) $post['id']] ?? [];
                                        $shop_comments = array_values(array_filter($post_comments, static function (array $item): bool {
                                            return ($item['commenter_role'] ?? '') === 'shop';
                                        }));
                                    ?>
                                    <?php if (empty($shop_comments)): ?>
                                        <p class="text-muted small">No shop suggestions yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($shop_comments as $shop_comment): ?>
                                            <div class="comment-item">
                                                <strong><?php echo htmlspecialchars($shop_comment['shop_name'] ?? 'Shop'); ?></strong>
                                                <?php if ((int) ($shop_comment['is_hidden'] ?? 0) === 1 || (int) ($shop_comment['is_removed'] ?? 0) === 1): ?>
                                                    <p class="mb-2 mt-1 text-muted"><em>This comment was moderated and is currently hidden.</em></p>
                                                <?php else: ?>
                                                    <p class="mb-2 mt-1"><?php echo nl2br(htmlspecialchars($shop_comment['comment_text'])); ?></p>
                                                    <?php if (!empty($shop_comment['shop_id'])): ?>
                                                        <a href="place_order.php?shop_id=<?php echo (int) $shop_comment['shop_id']; ?>" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-cart-plus"></i> Make Order
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($reports_table_exists): ?>
                                                    <details class="mt-2">
                                                        <summary class="text-danger small"><i class="fas fa-flag"></i> Report comment</summary>
                                                        <form method="POST" class="mt-2">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="target_type" value="community_comment">
                                                            <input type="hidden" name="target_id" value="<?php echo (int) ($shop_comment['id'] ?? 0); ?>">
                                                            <select name="report_reason" class="form-control mb-2" required><option value="">Select reason</option><option>Spam</option><option>Harassment</option><option>Hate speech</option><option>Inappropriate content</option><option>Fraud / scam</option><option>Other</option></select>
                                                            <textarea name="report_notes" class="form-control mb-2" rows="2" maxlength="500" placeholder="Optional details"></textarea>
                                                            <button type="submit" name="submit_report" class="btn btn-outline-danger btn-sm">Submit report</button>
                                                        </form>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                </details>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
(function hydrateDraftFromDesignEditor() {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('from_design_editor')) {
        return;
    }

    const draftRaw = localStorage.getItem('embroider_community_post_draft');
    if (!draftRaw) {
        return;
    }

    try {
        const draft = JSON.parse(draftRaw);
        const titleField = document.getElementById('title');
        const descriptionField = document.getElementById('description');
        const priceField = document.getElementById('preferred_price');
        const previewField = document.getElementById('design_preview');
        const previewPanel = document.getElementById('editorDesignPreview');
        const previewImage = document.getElementById('editorDesignPreviewImage');
        const estimateText = document.getElementById('editorDesignEstimateText');
        const detailsText = document.getElementById('editorDesignDetailsText');

        if (titleField && draft.title) {
            titleField.value = draft.title;
        }
        if (descriptionField && draft.description) {
            descriptionField.value = draft.description;
        }
        if (priceField && draft.preferred_price) {
            priceField.value = draft.preferred_price;
        }
        if (previewField && draft.design_preview) {
            previewField.value = draft.design_preview;
        }
        
        if (previewPanel && previewImage && draft.design_preview) {
            previewPanel.style.display = 'block';
            previewImage.src = draft.design_preview;

            if (estimateText && draft.estimated_price_label) {
                estimateText.textContent = `Estimated budget: ₱${draft.estimated_price_label} (not final price)`;
            }

            if (detailsText && draft.design_details) {
                detailsText.textContent = `Canvas: ${draft.design_details.canvas_type || '-'} (${draft.design_details.canvas_color || '-'}) • Placement: ${draft.design_details.placement_method || '-'} • Hoop: ${draft.design_details.hoop_preset || '-'} • Elements: ${draft.design_details.total_elements || 0}`;
            }
        }
        
        localStorage.removeItem('embroider_community_post_draft');
    } catch (error) {
        console.warn('Unable to prefill community draft from design editor.', error);
    }
})();

(function previewUploadedReference() {
    const fileInput = document.getElementById('reference_image');
    const previewPanel = document.getElementById('uploadPreview');
    const previewImage = document.getElementById('uploadPreviewImage');
    const fileName = document.getElementById('uploadFileName');
    const preferredPriceField = document.getElementById('preferred_price');
    const estimatePanel = document.getElementById('uploadEstimate');
    const estimateTitle = document.getElementById('uploadEstimateTitle');
    const estimateDetails = document.getElementById('uploadEstimateDetails');

    if (!fileInput || !previewPanel || !previewImage || !fileName || !estimatePanel || !estimateTitle || !estimateDetails) {
        return;
    }

     function estimateDesignPrice(width, height, uniqueColorCount) {
        const boundedColors = Math.max(1, Math.min(uniqueColorCount, 18));
        const megapixels = Math.max(0.2, (width * height) / 1000000);

        const basePrice = 350;
        const sizeCost = megapixels * 420;
        const colorCost = boundedColors * 95;
        const complexityBoost = megapixels > 1.2 && boundedColors > 10 ? 250 : 0;
        const estimatedPrice = Math.round(basePrice + sizeCost + colorCost + complexityBoost);

        return {
            estimatedPrice,
            boundedColors,
            megapixels,
        };
    }

    function renderEstimate(width, height, uniqueColorCount) {
        const estimate = estimateDesignPrice(width, height, uniqueColorCount);

        estimatePanel.style.display = 'block';
        estimateTitle.textContent = `Estimated design cost: ₱${estimate.estimatedPrice.toLocaleString()}`;
        estimateDetails.textContent = `${width}×${height}px (${estimate.megapixels.toFixed(2)} MP) • ~${estimate.boundedColors} dominant colors. This is an automated estimate and may change after shop review.`;

        if (preferredPriceField && !preferredPriceField.value) {
            preferredPriceField.value = estimate.estimatedPrice;
        }
    }

    function analyzeImageForEstimate(dataUrl) {
        return new Promise(function(resolve, reject) {
            const image = new Image();
            image.onload = function onImageLoad() {
                const analysisCanvas = document.createElement('canvas');
                const maxDimension = 320;
                const scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
                analysisCanvas.width = Math.max(1, Math.round(image.width * scale));
                analysisCanvas.height = Math.max(1, Math.round(image.height * scale));
                const ctx = analysisCanvas.getContext('2d', { willReadFrequently: true });
                if (!ctx) {
                    reject(new Error('Unable to initialize image analysis context.'));
                    return;
                }

                ctx.drawImage(image, 0, 0, analysisCanvas.width, analysisCanvas.height);
                const pixels = ctx.getImageData(0, 0, analysisCanvas.width, analysisCanvas.height).data;
                const colorBuckets = new Set();
                const step = 4;

                for (let index = 0; index < pixels.length; index += 4 * step) {
                    const alpha = pixels[index + 3];
                    if (alpha < 20) {
                        continue;
                    }

                    const red = Math.round(pixels[index] / 32);
                    const green = Math.round(pixels[index + 1] / 32);
                    const blue = Math.round(pixels[index + 2] / 32);
                    colorBuckets.add(`${red}-${green}-${blue}`);
                }

                resolve({
                    width: image.width,
                    height: image.height,
                    uniqueColorCount: Math.max(1, colorBuckets.size),
                });
            };

            image.onerror = function onImageError() {
                reject(new Error('Could not analyze the uploaded image.'));
            };
            image.src = dataUrl;
        });
    }

    fileInput.addEventListener('change', function handleUploadPreview(event) {
        const selectedFile = event.target.files && event.target.files[0];

        if (!selectedFile) {
            previewPanel.style.display = 'none';
            estimatePanel.style.display = 'none';
            previewImage.removeAttribute('src');
            fileName.textContent = '';
            return;
        }

        fileName.innerHTML = `<i class="fas fa-file-image text-primary"></i> ${selectedFile.name}`;
        previewPanel.style.display = 'block';

        if (selectedFile.type.startsWith('image/')) {
            previewImage.style.display = 'block';
            const fileReader = new FileReader();
            fileReader.onload = function onFileRead(loadEvent) {
                const dataUrl = loadEvent.target?.result || '';
                previewImage.src = dataUrl;
                analyzeImageForEstimate(dataUrl)
                    .then(function(result) {
                        renderEstimate(result.width, result.height, result.uniqueColorCount);
                    })
                    .catch(function(error) {
                        estimatePanel.style.display = 'none';
                        console.warn(error.message);
                    });
            };
            fileReader.readAsDataURL(selectedFile);
            return;
        }

        estimatePanel.style.display = 'none';
        previewImage.style.display = 'none';
        previewImage.removeAttribute('src');
    });
})();
</script>
</body>
</html>
