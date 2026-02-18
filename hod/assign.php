<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();

$supervisors = $pdo->query('SELECT id, full_name, email FROM users WHERE role = "supervisor" AND is_active = 1 ORDER BY full_name')->fetchAll();
$unassigned = $pdo->query('SELECT p.id, p.title, u.full_name AS student_name, u.reg_number FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status = "approved" AND p.supervisor_id IS NULL ORDER BY p.approved_at ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
    if ($project_id && $supervisor_id) {
        $stmt = $pdo->prepare('UPDATE projects SET supervisor_id = ?, status = "in_progress" WHERE id = ? AND status = "approved"');
        $stmt->execute([$supervisor_id, $project_id]);
        if ($stmt->rowCount()) {
            $stmt = $pdo->prepare('SELECT student_id FROM projects WHERE id = ?');
            $stmt->execute([$project_id]);
            $row = $stmt->fetch();
            if ($row) {
                $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$row['student_id'], 'supervisor_assigned', 'Supervisor assigned', 'A supervisor has been assigned to your project.', base_url('student/project.php')]);
            }
            flash('success', 'Supervisor assigned.');
            redirect(base_url('hod/assign.php'));
        }
    }
}

$pageTitle = 'Assign Supervisors';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Assign Supervisors</h1>

<div class="card">
    <div class="card-header">Projects Without Supervisor</div>
    <div class="card-body">
        <?php if (empty($unassigned)): ?>
            <p class="text-muted mb-0">All approved projects have a supervisor assigned.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Project Title</th><th>Assign</th></tr></thead>
                <tbody>
                    <?php foreach ($unassigned as $p): ?>
                        <tr>
                            <td><?= e($p['student_name']) ?> (<?= e($p['reg_number'] ?? '—') ?>)</td>
                            <td><?= e($p['title']) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                    <select name="supervisor_id" class="form-select form-select-sm d-inline-block w-auto">
                                        <option value="">Select...</option>
                                        <?php foreach ($supervisors as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= e($s['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Assign</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
