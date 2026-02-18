<?php
/**
 * Project Vault & Archive - searchable, read-only view of archived projects
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = getPDO();
$q = trim($_GET['q'] ?? '');
$year = trim($_GET['year'] ?? '');
$supervisor_id = isset($_GET['supervisor']) ? (int) $_GET['supervisor'] : 0;

$sql = 'SELECT p.id, p.title, p.description, p.academic_year, p.updated_at, u.full_name AS student_name, u.reg_number, sup.full_name AS supervisor_name, sup.id AS supervisor_id
    FROM projects p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN users sup ON p.supervisor_id = sup.id
    JOIN archive_metadata am ON am.project_id = p.id
    WHERE p.status = "archived"';
$params = [];
if ($q !== '') {
    $sql .= ' AND (p.title LIKE ? OR u.full_name LIKE ? OR u.reg_number LIKE ? OR sup.full_name LIKE ?)';
    $term = '%' . $q . '%';
    $params = array_merge($params, [$term, $term, $term, $term]);
}
if ($year !== '') {
    $sql .= ' AND p.academic_year = ?';
    $params[] = $year;
}
if ($supervisor_id > 0) {
    $sql .= ' AND p.supervisor_id = ?';
    $params[] = $supervisor_id;
}
$sql .= ' ORDER BY am.archived_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$years = $pdo->query('SELECT DISTINCT academic_year FROM projects WHERE status = "archived" AND academic_year IS NOT NULL ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$supervisors = $pdo->query('SELECT DISTINCT sup.id, sup.full_name FROM projects p JOIN users sup ON p.supervisor_id = sup.id WHERE p.status = "archived" ORDER BY sup.full_name')->fetchAll();

$pageTitle = 'Project Vault';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="mb-4">Project Vault</h1>
<p class="text-muted">Search and browse archived final year projects (read-only).</p>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Topic, student name, reg. number, supervisor..." value="<?= e($q) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Year</label>
                <select name="year" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Supervisor</label>
                <select name="supervisor" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($supervisors as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $supervisor_id === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Search</button>
                <a href="<?= base_url('vault.php') ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($projects)): ?>
            <p class="text-muted mb-0">No archived projects match your criteria.</p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($projects as $p): ?>
                    <div class="list-group-item vault-item">
                        <h6 class="mb-1"><?= e($p['title']) ?></h6>
                        <p class="mb-0 small text-muted">
                            <?= e($p['student_name']) ?>
                            <?= $p['reg_number'] ? ' (' . e($p['reg_number']) . ')' : '' ?>
                            — Supervisor: <?= e($p['supervisor_name'] ?? '—') ?>
                            <?= $p['academic_year'] ? ' — ' . e($p['academic_year']) : '' ?>
                        </p>
                        <?php if ($p['description']): ?><p class="mb-0 mt-1 small"><?= e(mb_substr($p['description'], 0, 200)) ?>...</p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
