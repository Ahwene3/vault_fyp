<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

// List in-progress and completed projects (not yet archived)
$stmt = $pdo->query('SELECT p.id, p.title, p.status, u.full_name AS student_name, u.reg_number, sup.full_name AS supervisor_name FROM projects p JOIN users u ON p.student_id = u.id LEFT JOIN users sup ON p.supervisor_id = sup.id WHERE p.status IN ("in_progress", "approved", "completed") AND p.id NOT IN (SELECT project_id FROM archive_metadata) ORDER BY p.status = "completed" DESC, p.updated_at DESC');
$completable = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id) {
        if ($action === 'complete') {
            $pdo->prepare('UPDATE projects SET status = "completed" WHERE id = ? AND status IN ("in_progress", "approved")')->execute([$project_id]);
            flash('success', 'Project marked as completed.');
        } elseif ($action === 'archive') {
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND status = "completed"');
            $stmt->execute([$project_id]);
            if ($stmt->fetch()) {
                $pdo->prepare('UPDATE projects SET status = "archived" WHERE id = ?')->execute([$project_id]);
                $pdo->prepare('INSERT INTO archive_metadata (project_id, archived_by) VALUES (?, ?)')->execute([$project_id, $uid]);
                flash('success', 'Project archived.');
            }
        }
        redirect(base_url('hod/archive.php'));
    }
}

$stmt = $pdo->query('SELECT p.id, p.title, p.updated_at, u.full_name AS student_name, sup.full_name AS supervisor_name FROM projects p JOIN users u ON p.student_id = u.id LEFT JOIN users sup ON p.supervisor_id = sup.id JOIN archive_metadata am ON am.project_id = p.id WHERE p.status = "archived" ORDER BY am.archived_at DESC');
$archived = $stmt->fetchAll();

$pageTitle = 'Archive';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Archive</h1>

<div class="card mb-4">
    <div class="card-header">Ongoing & Completed (Ready to Archive)</div>
    <div class="card-body">
        <?php if (empty($completable)): ?>
            <p class="text-muted mb-0">No projects to complete or archive.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Title</th><th>Supervisor</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($completable as $p): ?>
                        <tr>
                            <td><?= e($p['student_name']) ?></td>
                            <td><?= e($p['title']) ?></td>
                            <td><?= e($p['supervisor_name'] ?? '—') ?></td>
                            <td><span class="badge bg-secondary"><?= e($p['status']) ?></span></td>
                            <td>
                                <?php if ($p['status'] !== 'completed'): ?>
                                    <form method="post" class="d-inline me-1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Mark Completed</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'completed'): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="btn btn-sm btn-primary">Archive</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Archived Projects</div>
    <div class="card-body">
        <?php if (empty($archived)): ?>
            <p class="text-muted mb-0">No archived projects yet.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Title</th><th>Supervisor</th><th>Archived</th></tr></thead>
                <tbody>
                    <?php foreach ($archived as $p): ?>
                        <tr>
                            <td><?= e($p['student_name']) ?></td>
                            <td><?= e($p['title']) ?></td>
                            <td><?= e($p['supervisor_name'] ?? '—') ?></td>
                            <td><?= e(date('M j, Y', strtotime($p['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
