<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();

$stmt = $pdo->prepare('SELECT p.id, p.title, p.status, p.submitted_at, u.id AS student_id, u.full_name AS student_name, u.email, u.reg_number
    FROM projects p
    JOIN users u ON p.student_id = u.id
    WHERE p.supervisor_id = ?
    ORDER BY p.updated_at DESC');
$stmt->execute([$uid]);
$students = $stmt->fetchAll();

$pageTitle = 'My Students';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">My Students</h1>

<div class="card">
    <div class="card-body">
        <?php if (empty($students)): ?>
            <p class="text-muted mb-0">No students assigned to you yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Reg. No.</th>
                            <th>Project Title</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><?= e($s['student_name']) ?><br><small class="text-muted"><?= e($s['email']) ?></small></td>
                                <td><?= e($s['reg_number'] ?? '—') ?></td>
                                <td><?= e($s['title']) ?></td>
                                <td><span class="badge bg-secondary"><?= e($s['status']) ?></span></td>
                                <td>
                                    <a href="<?= base_url('supervisor/student_detail.php?pid=' . $s['id']) ?>" class="btn btn-sm btn-outline-primary">View &amp; Assess</a>
                                    <a href="<?= base_url('messages.php?pid=' . $s['id'] . '&with=' . $s['student_id']) ?>" class="btn btn-sm btn-outline-secondary">Message</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
