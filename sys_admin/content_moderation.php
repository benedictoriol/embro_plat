<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role(['sys_admin', 'content_moderator']);

$moderator_id = (int) ($_SESSION['user']['id'] ?? 0);
$feedback_error = '';
$feedback_success = '';

$status_filter = sanitize($_GET['status'] ?? '');
$entity_filter = sanitize($_GET['entity_type'] ?? '');
$reason_filter = sanitize($_GET['reason'] ?? '');
$search = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['moderate_report'])) {
    $report_id = (int) ($_POST['report_id'] ?? 0);
    $report_status = sanitize($_POST['report_status'] ?? 'pending');
    $content_action = sanitize($_POST['content_action'] ?? 'none');
    $moderator_notes = trim($_POST['moderator_notes'] ?? '');

    $allowed_statuses = ['pending', 'reviewing', 'resolved', 'dismissed'];
    $allowed_actions = ['none', 'hide', 'remove', 'restore'];

if ($report_id <= 0 || !in_array($report_status, $allowed_statuses, true) || !in_array($content_action, $allowed_actions, true)) {
        $feedback_error = 'Invalid moderation action payload.';
    } else {
        $report_stmt = $pdo->prepare('SELECT * FROM content_reports WHERE id = ? LIMIT 1');
        $report_stmt->execute([$report_id]);
        $report = $report_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            $feedback_error = 'Report not found.';
        } else {
            try {
                $pdo->beginTransaction();

        $before_status = $report['status'] ?? 'pending';
                $update_stmt = $pdo->prepare("\n                    UPDATE content_reports\n                    SET status = ?,\n                        reviewed_by = ?,\n                        reviewed_at = NOW(),\n                        updated_at = NOW(),\n                        notes = CASE WHEN ? <> '' THEN ? ELSE notes END\n                    WHERE id = ?\n                ");
                $update_stmt->execute([
                    $report_status,
                    $moderator_id,
                    $moderator_notes,
                    $moderator_notes,
                    $report_id,
                ]);

        if ($content_action !== 'none') {
                    moderation_apply_content_action(
                        $pdo,
                        (string) $report['target_entity_type'],
                        (int) $report['target_entity_id'],
                        $content_action,
                        $moderator_id,
                        $moderator_notes
                    );
                }

        if (function_exists('log_audit')) {
                    log_audit(
                        $pdo,
                        $moderator_id,
                        $_SESSION['user']['role'] ?? null,
                        'moderation_report_updated',
                        'content_report',
                        $report_id,
                        [
                            'status' => $before_status,
                        ],
                        [
                            'status' => $report_status,
                            'content_action' => $content_action,
                            'reviewed_by' => $moderator_id,
                            'reviewed_at' => date('Y-m-d H:i:s'),
                        ],
                        [
                            'target_entity_type' => $report['target_entity_type'],
                            'target_entity_id' => $report['target_entity_id'],
                            'moderator_notes' => $moderator_notes,
                        ]
                    );
                }

        $pdo->commit();
                $feedback_success = 'Moderation decision saved successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $feedback_error = 'Unable to save moderation changes right now.';
            }
        }
    }
}

$where = [];
$params = [];

if ($status_filter !== '' && in_array($status_filter, ['pending', 'reviewing', 'resolved', 'dismissed'], true)) {
    $where[] = 'r.status = :status';
    $params['status'] = $status_filter;
}
if ($entity_filter !== '' && in_array($entity_filter, ['community_post', 'community_comment'], true)) {
    $where[] = 'r.target_entity_type = :entity_type';
    $params['entity_type'] = $entity_filter;
}
if ($reason_filter !== '') {
    $where[] = 'r.reason = :reason';
    $params['reason'] = $reason_filter;
}
if ($search !== '') {
    $where[] = '(r.reason LIKE :search OR r.notes LIKE :search OR reporter.fullname LIKE :search OR reviewer.fullname LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$queue_stmt = $pdo->prepare("\n    SELECT r.*,\n           reporter.fullname AS reporter_name,\n           reviewer.fullname AS reviewer_name,\n           p.title AS post_title,\n           LEFT(p.description, 140) AS post_snippet,\n           LEFT(c.comment_text, 140) AS comment_snippet\n    FROM content_reports r\n    LEFT JOIN users reporter ON r.reporter_user_id = reporter.id\n    LEFT JOIN users reviewer ON r.reviewed_by = reviewer.id\n    LEFT JOIN client_community_posts p\n        ON r.target_entity_type = 'community_post'\n       AND r.target_entity_id = p.id\n    LEFT JOIN community_post_comments c\n        ON r.target_entity_type = 'community_comment'\n       AND r.target_entity_id = c.id\n    {$where_clause}\n    ORDER BY FIELD(r.status, 'pending', 'reviewing', 'resolved', 'dismissed'), r.created_at DESC\n    LIMIT 100\n");
$queue_stmt->execute($params);
$report_queue = $queue_stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi_stmt = $pdo->query("\n    SELECT\n        SUM(CASE WHEN status IN ('pending','reviewing') THEN 1 ELSE 0 END) AS open_reports,\n        SUM(CASE WHEN status = 'resolved' AND DATE(reviewed_at) = CURDATE() THEN 1 ELSE 0 END) AS resolved_today,\n        SUM(CASE WHEN status = 'dismissed' AND DATE(reviewed_at) = CURDATE() THEN 1 ELSE 0 END) AS dismissed_today\n    FROM content_reports\n");
$kpi = $kpi_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$auto_hidden_stmt = $pdo->query("\n    SELECT\n        (SELECT COUNT(*) FROM client_community_posts WHERE is_hidden = 1) +\n        (SELECT COUNT(*) FROM community_post_comments WHERE is_hidden = 1) AS hidden_items\n");
$hidden_count = (int) ($auto_hidden_stmt->fetchColumn() ?: 0);

$moderation_kpis = [
    ['label' => 'Open reports', 'value' => (int) ($kpi['open_reports'] ?? 0), 'icon' => 'fas fa-flag', 'tone' => 'warning'],
    ['label' => 'Resolved today', 'value' => (int) ($kpi['resolved_today'] ?? 0), 'icon' => 'fas fa-check-circle', 'tone' => 'success'],
    ['label' => 'Dismissed today', 'value' => (int) ($kpi['dismissed_today'] ?? 0), 'icon' => 'fas fa-ban', 'tone' => 'secondary'],
    ['label' => 'Currently hidden', 'value' => $hidden_count, 'icon' => 'fas fa-eye-slash', 'tone' => 'info'],
];

        $reason_options_stmt = $pdo->query('SELECT DISTINCT reason FROM content_reports ORDER BY reason');
$reason_options = $reason_options_stmt->fetchAll(PDO::FETCH_COLUMN);

function moderation_status_badge(string $status): string {
    return match ($status) {
        'pending' => 'warning',
        'reviewing' => 'info',
        'resolved' => 'success',
        'dismissed' => 'secondary',
        default => 'secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Moderation &amp; Reporting - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php sys_admin_nav('content_moderation'); ?>
<div class="container">
    <div class="dashboard-header fade-in">
        <div class="d-flex justify-between align-center">
            <div>
                <h2>Content Moderation &amp; Reporting</h2>
                <p class="text-muted">Review reported community posts/comments, enforce actions, and track moderation outcomes.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-shield-halved"></i> Module 21</span>
        </div>
    </div>

        <?php if ($feedback_error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($feedback_error); ?></div>
    <?php endif; ?>
    <?php if ($feedback_success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($feedback_success); ?></div>
    <?php endif; ?>

            <div class="grid" style="grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1rem;">
        <?php foreach ($moderation_kpis as $kpi_row): ?>
            <div class="card">
                <p class="text-muted mb-1"><?php echo htmlspecialchars($kpi_row['label']); ?></p>
                <div class="d-flex justify-between align-center">
                    <h3 class="mb-0"><?php echo (int) $kpi_row['value']; ?></h3>
                    <span class="badge badge-<?php echo htmlspecialchars($kpi_row['tone']); ?>"><i class="<?php echo htmlspecialchars($kpi_row['icon']); ?>"></i></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

            <div class="card mb-3">
        <form method="GET" class="d-flex" style="gap: 0.75rem; flex-wrap: wrap;">
            <select class="form-control" name="status">
                <option value="">All statuses</option>
                <?php foreach (['pending','reviewing','resolved','dismissed'] as $status): ?>
                    <option value="<?php echo $status; ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-control" name="entity_type">
                <option value="">All content types</option>
                <option value="community_post" <?php echo $entity_filter === 'community_post' ? 'selected' : ''; ?>>Community post</option>
                <option value="community_comment" <?php echo $entity_filter === 'community_comment' ? 'selected' : ''; ?>>Community comment</option>
            </select>
            <select class="form-control" name="reason">
                <option value="">All reasons</option>
                <?php foreach ($reason_options as $reason): ?>
                    <option value="<?php echo htmlspecialchars($reason); ?>" <?php echo $reason_filter === $reason ? 'selected' : ''; ?>><?php echo htmlspecialchars($reason); ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search reporter, notes, reason">
            <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

            <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Target</th>
                    <th>Reason</th>
                    <th>Reporter</th>
                    <th>Status</th>
                    <th>Reviewed by</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($report_queue)): ?>
                    <tr><td colspan="8" class="text-muted">No reports found for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_queue as $report): ?>
                        <tr>
                            <td>#<?php echo (int) $report['id']; ?></td>
                            <td>
                                <strong><?php echo $report['target_entity_type'] === 'community_post' ? 'Post' : 'Comment'; ?> #<?php echo (int) $report['target_entity_id']; ?></strong>
                                <div class="text-muted small">
                                    <?php echo htmlspecialchars($report['target_entity_type'] === 'community_post' ? ($report['post_title'] ?: $report['post_snippet'] ?: '[content unavailable]') : ($report['comment_snippet'] ?: '[content unavailable]')); ?>
                                </div>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($report['reason']); ?>
                                <?php if (!empty($report['notes'])): ?><div class="small text-muted"><?php echo nl2br(htmlspecialchars($report['notes'])); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($report['reporter_name'] ?? ('User #' . (int) $report['reporter_user_id'])); ?></td>
                            <td><span class="badge badge-<?php echo moderation_status_badge((string) $report['status']); ?>"><?php echo htmlspecialchars(ucfirst((string) $report['status'])); ?></span></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($report['reviewer_name'] ?? '-'); ?></td>
                            <td class="small text-muted"><?php echo date('M d, Y h:i A', strtotime((string) $report['created_at'])); ?></td>
                            <td>
                                <details>
                                    <summary class="btn btn-outline-primary btn-sm">Review</summary>
                                    <form method="POST" class="mt-2" style="min-width: 280px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                        <div class="mb-2">
                                            <label class="small">Report status</label>
                                            <select name="report_status" class="form-control" required>
                                                <?php foreach (['pending','reviewing','resolved','dismissed'] as $status): ?>
                                                    <option value="<?php echo $status; ?>" <?php echo $report['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="small">Content action</label>
                                            <select name="content_action" class="form-control">
                                                <option value="none">No content change</option>
                                                <option value="hide">Hide content</option>
                                                <option value="remove">Remove content</option>
                                                <option value="restore">Restore content</option>
                                            </select>
                                        </div>
                                        <textarea name="moderator_notes" class="form-control mb-2" rows="2" maxlength="1000" placeholder="Decision notes for audit trail"></textarea>
                                        <button type="submit" name="moderate_report" class="btn btn-primary btn-sm">Save</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php sys_admin_footer(); ?>
</body>
</html>
