<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

$stmt = $pdo->query('SELECT p.id, p.title, p.description, p.status, p.submitted_at, u.full_name AS student_name, u.reg_number, u.email FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status = "submitted" ORDER BY p.submitted_at ASC');
$pending = $stmt->fetchAll();

// Approve / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id && in_array($action, ['approve', 'reject'], true)) {
        $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND status = "submitted"');
        $stmt->execute([$project_id]);
        if ($stmt->fetch()) {
            if ($action === 'approve') {
                $pdo->prepare('UPDATE projects SET status = "approved", approved_at = NOW(), approved_by = ?, rejection_reason = NULL WHERE id = ?')->execute([$uid, $project_id]);
                $stmt = $pdo->prepare('SELECT student_id FROM projects WHERE id = ?');
                $stmt->execute([$project_id]);
                $row = $stmt->fetch();
                if ($row) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$row['student_id'], 'topic_approved', 'Topic approved', 'Your project topic has been approved.', base_url('student/project.php')]);
                }
                flash('success', 'Topic approved.');
            } else {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare('UPDATE projects SET status = "rejected", approved_by = ?, rejection_reason = ? WHERE id = ?')->execute([$uid, $reason ?: null, $project_id]);
                $stmt = $pdo->prepare('SELECT student_id FROM projects WHERE id = ?');
                $stmt->execute([$project_id]);
                $row = $stmt->fetch();
                if ($row) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$row['student_id'], 'topic_rejected', 'Topic rejected', 'Your project topic was rejected. You may resubmit.', base_url('student/project.php')]);
                }
                flash('success', 'Topic rejected.');
            }
            redirect(base_url('hod/topics.php'));
        }
    }
}

$pageTitle = 'Topic Approval';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Topic Approval</h1>

<div class="card">
    <div class="card-header">Pending Submissions</div>
    <div class="card-body">
        <?php if (empty($pending)): ?>
            <p class="text-muted mb-0">No topics pending approval.</p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($pending as $p): ?>
                    <div class="list-group-item">
                        <h6><?= e($p['title']) ?></h6>
                        <p class="mb-1 text-muted small"><?= e($p['student_name']) ?> — <?= e($p['reg_number'] ?? $p['email']) ?></p>
                        <?php if ($p['description']): ?><p class="mb-2"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
                        <form method="post" class="d-inline me-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                            <input type="text" name="rejection_reason" placeholder="Reason (optional)" class="form-control form-control-sm d-inline-block w-auto">
                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
