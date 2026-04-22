<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();

$status = trim((string) ($_GET['status'] ?? 'ongoing'));
$allowed_statuses = ['ongoing', 'completed', 'archived'];
if (!in_array($status, $allowed_statuses, true)) {
    $status = 'ongoing';
}

$q = trim((string) ($_GET['q'] ?? ''));

$counts_stmt = $pdo->query('SELECT
    SUM(CASE WHEN status IN ("approved", "in_progress") THEN 1 ELSE 0 END) AS ongoing_count,
    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_count,
    SUM(CASE WHEN status = "archived" THEN 1 ELSE 0 END) AS archived_count
    FROM projects');
$counts = $counts_stmt->fetch();
$status_counts = [
    'ongoing' => (int) ($counts['ongoing_count'] ?? 0),
    'completed' => (int) ($counts['completed_count'] ?? 0),
    'archived' => (int) ($counts['archived_count'] ?? 0),
];

$status_titles = [
    'ongoing' => 'Ongoing Projects',
    'completed' => 'Completed Projects',
    'archived' => 'Archived Projects',
];

$status_where = 'p.status IN ("approved", "in_progress")';
if ($status === 'completed') {
    $status_where = 'p.status = "completed"';
} elseif ($status === 'archived') {
    $status_where = 'p.status = "archived"';
}

$archived_select = ', NULL AS archived_at, NULL AS archived_by_name';
$archived_join = '';
$order_by = 'p.updated_at DESC';

if ($status === 'archived') {
    $archived_select = ', am.archived_at, archiver.full_name AS archived_by_name';
    $archived_join = 'JOIN archive_metadata am ON am.project_id = p.id
        LEFT JOIN users archiver ON archiver.id = am.archived_by';
    $order_by = 'am.archived_at DESC, p.updated_at DESC';
}

$sql = 'SELECT
    p.id,
    p.title,
    p.status,
    p.updated_at,
    p.academic_year,
    p.group_id,
    COALESCE(g.name, CONCAT("Solo Vault - ", lead_u.full_name)) AS vault_name,
    lead_u.full_name AS lead_name,
    lead_u.reg_number AS lead_reg_number,
    lead_u.email AS lead_email,
    sup_u.full_name AS supervisor_name,
    (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
        FROM `group_members` gm2
        JOIN users u2 ON u2.id = gm2.student_id
        WHERE gm2.group_id = p.group_id) AS member_directory'
    . $archived_select . '
    FROM projects p
    JOIN users lead_u ON lead_u.id = p.student_id
    LEFT JOIN users sup_u ON sup_u.id = p.supervisor_id
    LEFT JOIN `groups` g ON g.id = p.group_id
    ' . $archived_join . '
    WHERE ' . $status_where;
$params = [];

if ($q !== '') {
    $sql .= ' AND (p.title LIKE ? OR lead_u.full_name LIKE ? OR lead_u.reg_number LIKE ? OR sup_u.full_name LIKE ? OR g.name LIKE ?)';
    $term = '%' . $q . '%';
    $params = [$term, $term, $term, $term, $term];
}

$sql .= ' ORDER BY ' . $order_by;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

$status_badges = [
    'approved' => 'bg-primary',
    'in_progress' => 'bg-info text-dark',
    'completed' => 'bg-success',
    'archived' => 'bg-secondary',
];

$pageTitle = 'Project Status';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Project Status</h1>
<p class="text-muted">View ongoing, completed, and archived projects from one admin page.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="<?= base_url('admin/projects.php?status=ongoing') ?>" class="text-decoration-none text-reset d-block h-100">
            <div class="card stat-card h-100 <?= $status === 'ongoing' ? 'border border-primary' : '' ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="text-primary me-3"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Ongoing</h6>
                        <span class="fw-bold"><?= (int) $status_counts['ongoing'] ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="<?= base_url('admin/projects.php?status=completed') ?>" class="text-decoration-none text-reset d-block h-100">
            <div class="card stat-card h-100 <?= $status === 'completed' ? 'border border-success' : '' ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="text-success me-3"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Completed</h6>
                        <span class="fw-bold"><?= (int) $status_counts['completed'] ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="<?= base_url('admin/projects.php?status=archived') ?>" class="text-decoration-none text-reset d-block h-100">
            <div class="card stat-card h-100 <?= $status === 'archived' ? 'border border-secondary' : '' ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="text-secondary me-3"><i class="bi bi-folder"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Archived</h6>
                        <span class="fw-bold"><?= (int) $status_counts['archived'] ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <div class="col-md-8">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Project title, student, index number, supervisor, vault..." value="<?= e($q) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Search</button>
                <a href="<?= base_url('admin/projects.php?status=' . urlencode($status)) ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= e($status_titles[$status]) ?></span>
        <span class="badge bg-secondary"><?= count($projects) ?> result(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($projects)): ?>
            <p class="text-muted mb-0">No <?= e(strtolower($status_titles[$status])) ?> match the current filter.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Vault</th>
                            <th>Project</th>
                            <th>Members / Index</th>
                            <th>Supervisor</th>
                            <th>Status</th>
                            <?php if ($status === 'archived'): ?>
                                <th>Archived</th>
                            <?php endif; ?>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <?php
                                $member_text = !empty($project['group_id'])
                                    ? ($project['member_directory'] ?: '—')
                                    : (($project['lead_name'] ?? '—') . ' (' . (($project['lead_reg_number'] ?: $project['lead_email']) ?: '—') . ')');
                                $badge_class = $status_badges[$project['status']] ?? 'bg-secondary';
                            ?>
                            <tr>
                                <td>
                                    <?= e($project['vault_name']) ?>
                                    <?php if (!empty($project['academic_year'])): ?>
                                        <small class="text-muted d-block"><?= e((string) $project['academic_year']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($project['title']) ?></td>
                                <td><small><?= e($member_text) ?></small></td>
                                <td><?= e($project['supervisor_name'] ?: 'Not assigned') ?></td>
                                <td><span class="badge <?= e($badge_class) ?>"><?= e(str_replace('_', ' ', (string) $project['status'])) ?></span></td>
                                <?php if ($status === 'archived'): ?>
                                    <td>
                                        <?= !empty($project['archived_at']) ? e(date('M j, Y', strtotime((string) $project['archived_at']))) : '—' ?>
                                        <?php if (!empty($project['archived_by_name'])): ?>
                                            <small class="text-muted d-block">By <?= e($project['archived_by_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?= !empty($project['updated_at']) ? e(date('M j, Y', strtotime((string) $project['updated_at']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
