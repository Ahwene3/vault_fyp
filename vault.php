<?php
/**
 * Project Vault - searchable reference view of project records
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = getPDO();
$q = trim($_GET['q'] ?? '');
$year = trim($_GET['year'] ?? '');
$supervisor_id = isset($_GET['supervisor']) ? (int) $_GET['supervisor'] : 0;

$sql = 'SELECT p.id, p.title, p.description, p.academic_year, p.status, p.updated_at, u.full_name AS student_name, u.reg_number, g.name AS group_name, sup.full_name AS supervisor_name, sup.id AS supervisor_id
    FROM projects p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN `groups` g ON g.id = p.group_id
    LEFT JOIN users sup ON p.supervisor_id = sup.id
    WHERE p.status != "draft"';
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
$sql .= ' ORDER BY p.updated_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$years = $pdo->query('SELECT DISTINCT academic_year FROM projects WHERE academic_year IS NOT NULL ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$supervisors = $pdo->query('SELECT DISTINCT sup.id, sup.full_name FROM projects p LEFT JOIN users sup ON p.supervisor_id = sup.id WHERE sup.id IS NOT NULL ORDER BY sup.full_name')->fetchAll();

$total_projects = count($projects);
$completed = 0;
$in_progress = 0;
foreach ($projects as $p) {
    if (($p['status'] ?? '') === 'completed') {
        $completed++;
    }
    if (in_array(($p['status'] ?? ''), ['approved', 'in_progress'], true)) {
        $in_progress++;
    }
}

$pageTitle = 'Project Vault';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">Project Vault</h1>
        <p class="dashboard-hero__copy mb-0">Browse archived and active project references across groups.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="#vault-search" class="btn dashboard-hero__btn">Search Projects</a>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-folder2-open"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Total Projects</h6>
                    <div class="student-stat-value"><?= (int) $total_projects ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-primary me-3"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Completed</h6>
                    <div class="student-stat-value"><?= (int) $completed ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-warning me-3"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <h6 class="text-muted mb-1">In Progress</h6>
                    <div class="student-stat-value"><?= (int) $in_progress ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4" id="vault-search">
    <div class="card-header">Search Filters</div>
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
                        <option value="<?= (int) $s['id'] ?>" <?= $supervisor_id === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="<?= base_url('vault.php') ?>" class="btn btn-outline-primary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Projects</span>
        <span class="text-muted small"><?= (int) $total_projects ?> records</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($projects)): ?>
            <p class="text-muted px-3 py-4 mb-0">No projects match your criteria.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <colgroup>
                        <col style="width: 28%">
                        <col style="width: 18%">
                        <col style="width: 18%">
                        <col style="width: 12%">
                        <col style="width: 12%">
                        <col style="width: 12%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Project Title</th>
                            <th>Group</th>
                            <th>Supervisor</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $p): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($p['title']) ?></td>
                                <td><?= e($p['group_name'] ?: 'Solo Vault') ?></td>
                                <td><?= e($p['supervisor_name'] ?: '—') ?></td>
                                <td><?= e($p['academic_year'] ?: '—') ?></td>
                                <td>
                                    <?php
                                        $status = strtolower((string) $p['status']);
                                        $badge = 'bg-secondary';
                                        $label = ucfirst(str_replace('_', ' ', $status));
                                        if ($status === 'completed') {
                                            $badge = 'bg-success';
                                        } elseif (in_array($status, ['approved', 'in_progress'], true)) {
                                            $badge = 'bg-info text-dark';
                                            $label = $status === 'approved' ? 'Approved' : 'In Progress';
                                        } elseif ($status === 'submitted') {
                                            $badge = 'bg-warning text-dark';
                                        }
                                    ?>
                                    <span class="badge <?= $badge ?>"><?= e($label) ?></span>
                                </td>
                                <td>
                                    <?php if (user_role() === 'student'): ?>
                                        <a href="<?= base_url('student/project.php') ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
