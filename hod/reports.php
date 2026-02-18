<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();

$by_status = $pdo->query('SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status')->fetchAll();
$total = $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$by_year = $pdo->query('SELECT academic_year, COUNT(*) AS cnt FROM projects WHERE academic_year IS NOT NULL GROUP BY academic_year ORDER BY academic_year DESC')->fetchAll();
$ongoing = $pdo->query('SELECT COUNT(*) FROM projects WHERE status IN ("submitted", "approved", "in_progress")')->fetchColumn();
$completed = $pdo->query('SELECT COUNT(*) FROM projects WHERE status IN ("completed", "archived")')->fetchColumn();

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Project Reports</h1>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h6 class="text-muted">Total Projects</h6>
                <h3 class="mb-0"><?= (int) $total ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h6 class="text-muted">Ongoing</h6>
                <h3 class="mb-0"><?= (int) $ongoing ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h6 class="text-muted">Completed / Archived</h6>
                <h3 class="mb-0"><?= (int) $completed ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">By Status</div>
    <div class="card-body">
        <table class="table table-sm">
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($by_status as $r): ?>
                    <tr><td><?= e($r['status']) ?></td><td><?= (int) $r['cnt'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($by_year)): ?>
<div class="card">
    <div class="card-header">By Academic Year</div>
    <div class="card-body">
        <table class="table table-sm">
            <thead><tr><th>Year</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($by_year as $r): ?>
                    <tr><td><?= e($r['academic_year']) ?></td><td><?= (int) $r['cnt'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
