<?php
/**
 * Project Vault - searchable archive of completed/archived projects
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = getPDO();
ensure_project_keywords_column($pdo);

$q = trim($_GET['q'] ?? '');
$year = trim($_GET['year'] ?? '');
$supervisor_id = isset($_GET['supervisor']) ? (int) $_GET['supervisor'] : 0;
$dept_filter = trim($_GET['dept'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$show_all = isset($_GET['all']);

$base_status_filter = $show_all ? 'p.status != "draft"' : 'p.status = "archived"';

$sql = 'SELECT p.id, p.title, p.description, p.keywords, p.academic_year, p.status, p.updated_at,
    u.full_name AS student_name, u.reg_number, u.department,
    g.name AS group_name,
    sup.full_name AS supervisor_name, sup.id AS supervisor_id,
    am.archived_at
    FROM projects p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN `groups` g ON g.id = p.group_id
    LEFT JOIN users sup ON p.supervisor_id = sup.id
    LEFT JOIN archive_metadata am ON am.project_id = p.id
    WHERE ' . $base_status_filter;
$params = [];

if ($q !== '') {
    $sql .= ' AND (p.title LIKE ? OR p.description LIKE ? OR p.keywords LIKE ? OR u.full_name LIKE ? OR u.reg_number LIKE ? OR sup.full_name LIKE ?)';
    $term = '%' . $q . '%';
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
}
if ($year !== '') {
    $sql .= ' AND p.academic_year = ?';
    $params[] = $year;
}
if ($supervisor_id > 0) {
    $sql .= ' AND p.supervisor_id = ?';
    $params[] = $supervisor_id;
}
if ($dept_filter !== '') {
    $sql .= ' AND LOWER(TRIM(COALESCE(u.department, ""))) = LOWER(?)';
    $params[] = $dept_filter;
}
if ($date_from !== '') {
    $sql .= ' AND (am.archived_at >= ? OR (am.archived_at IS NULL AND p.updated_at >= ?))';
    $params[] = $date_from;
    $params[] = $date_from;
}
if ($date_to !== '') {
    $sql .= ' AND (am.archived_at <= ? OR (am.archived_at IS NULL AND p.updated_at <= ?))';
    $params[] = $date_to . ' 23:59:59';
    $params[] = $date_to . ' 23:59:59';
}
$sql .= ' ORDER BY COALESCE(am.archived_at, p.updated_at) DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Load documents for all returned projects (for preview modal)
$doc_map = [];
if (!empty($projects)) {
    $proj_ids = array_column($projects, 'id');
    $d_stmt = $pdo->prepare('SELECT pd.id, pd.project_id, pd.file_name, pd.document_type, pd.uploaded_at FROM project_documents pd WHERE pd.project_id IN (' . sql_placeholders(count($proj_ids)) . ') ORDER BY pd.uploaded_at DESC');
    $d_stmt->execute($proj_ids);
    foreach ($d_stmt->fetchAll() as $d) {
        $doc_map[(int) $d['project_id']][] = $d;
    }
}

$years = $pdo->query('SELECT DISTINCT academic_year FROM projects WHERE academic_year IS NOT NULL ORDER BY academic_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$supervisors = $pdo->query('SELECT DISTINCT sup.id, sup.full_name FROM projects p LEFT JOIN users sup ON p.supervisor_id = sup.id WHERE sup.id IS NOT NULL ORDER BY sup.full_name')->fetchAll();
$departments = $pdo->query('SELECT DISTINCT TRIM(department) AS dept FROM users WHERE department IS NOT NULL AND department != "" ORDER BY dept')->fetchAll(PDO::FETCH_COLUMN);

$total = count($projects);
$archived_count = 0;
foreach ($projects as $p) {
    if ($p['status'] === 'archived') {
        $archived_count++;
    }
}

$pageTitle = 'Project Vault';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">Project Vault</h1>
        <p class="dashboard-hero__copy mb-0">
            <?= $show_all ? 'Browsing all project records.' : 'Browsing archived project references — completed and preserved.' ?>
        </p>
    </div>
    <div class="dashboard-hero__actions">
        <?php if ($show_all): ?>
            <a href="<?= base_url('vault.php') ?>" class="btn dashboard-hero__btn">Archived Only</a>
        <?php else: ?>
            <a href="<?= base_url('vault.php?all=1') ?>" class="btn dashboard-hero__btn">Show All Projects</a>
        <?php endif; ?>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-archive-fill"></i></div>
                <div>
                    <h6 class="text-muted mb-1"><?= $show_all ? 'Total Projects' : 'Archived Projects' ?></h6>
                    <div class="student-stat-value"><?= (int) $total ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-primary me-3"><i class="bi bi-calendar3"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Academic Years</h6>
                    <div class="student-stat-value"><?= count($years) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-warning me-3"><i class="bi bi-people"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Supervisors</h6>
                    <div class="student-stat-value"><?= count($supervisors) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4" id="vault-search">
    <div class="card-header">Search &amp; Filter</div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <?php if ($show_all): ?><input type="hidden" name="all" value="1"><?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Topic, keyword, student name, reg. number, supervisor…" value="<?= e($q) ?>">
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
                <label class="form-label">Department</label>
                <select name="dept" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d) ?>" <?= $dept_filter === $d ? 'selected' : '' ?>><?= e(get_department_display_name($pdo, $d)) ?></option>
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
            <div class="col-md-2">
                <label class="form-label">Archived From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Archived To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="<?= base_url('vault.php' . ($show_all ? '?all=1' : '')) ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><?= $show_all ? 'All Projects' : 'Archived Projects' ?></span>
        <span class="text-muted small"><?= (int) $total ?> records</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($projects)): ?>
            <p class="text-muted px-3 py-4 mb-0">No projects match your criteria.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Project Title</th>
                            <th>Group</th>
                            <th>Supervisor</th>
                            <th>Year</th>
                            <th>Archived</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $p):
                            $status = strtolower((string) $p['status']);
                            $badge = 'bg-secondary';
                            $label = ucfirst(str_replace('_', ' ', $status));
                            if ($status === 'archived') {
                                $badge = 'bg-success';
                                $label = 'Archived';
                            } elseif (in_array($status, ['approved', 'in_progress'], true)) {
                                $badge = 'bg-info text-dark';
                                $label = $status === 'approved' ? 'Approved' : 'In Progress';
                            } elseif ($status === 'submitted') {
                                $badge = 'bg-warning text-dark';
                            } elseif ($status === 'completed') {
                                $badge = 'bg-primary';
                            }
                            $archived_date = !empty($p['archived_at']) ? date('M j, Y', strtotime($p['archived_at'])) : ($status === 'archived' ? date('M j, Y', strtotime($p['updated_at'])) : '—');
                            $docs = $doc_map[(int) $p['id']] ?? [];
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($p['title']) ?></div>
                                    <?php if (!empty($p['keywords'])): ?>
                                        <div class="mt-1">
                                            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $p['keywords']))), 0, 4) as $kw): ?>
                                                <span class="badge bg-light text-secondary border me-1" style="font-size:0.7em;"><?= e($kw) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($p['group_name'] ?: 'Solo Vault') ?></td>
                                <td><?= e($p['supervisor_name'] ?: '—') ?></td>
                                <td><?= e($p['academic_year'] ?: '—') ?></td>
                                <td><small class="text-muted"><?= $archived_date ?></small></td>
                                <td><span class="badge <?= $badge ?>"><?= e($label) ?></span></td>
                                <td>
                                    <button type="button"
                                        class="btn btn-sm btn-outline-primary vault-preview-btn"
                                        data-pid="<?= (int) $p['id'] ?>"
                                        data-title="<?= e($p['title']) ?>"
                                        data-group="<?= e($p['group_name'] ?: 'Solo Vault') ?>"
                                        data-supervisor="<?= e($p['supervisor_name'] ?: '—') ?>"
                                        data-student="<?= e($p['student_name']) ?>"
                                        data-department="<?= e($p['department'] ?: '—') ?>"
                                        data-year="<?= e($p['academic_year'] ?: '—') ?>"
                                        data-status="<?= e($label) ?>"
                                        data-archived="<?= $archived_date ?>"
                                        data-description="<?= e(mb_substr((string) ($p['description'] ?? ''), 0, 400)) ?>"
                                        data-docs='<?= htmlspecialchars(json_encode(array_map(static function ($d) {
                                            return ['id' => (int) $d['id'], 'file_name' => $d['file_name'], 'document_type' => $d['document_type']];
                                        }, $docs)), ENT_QUOTES) ?>'
                                        data-bs-toggle="modal" data-bs-target="#vaultPreviewModal">
                                        <i class="bi bi-eye"></i> Preview
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="vaultPreviewModal" tabindex="-1" aria-labelledby="vaultPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vaultPreviewModalLabel">Project Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-3" id="vaultPreviewMeta">
                    <dt class="col-sm-3">Group</dt><dd class="col-sm-9" id="vp-group"></dd>
                    <dt class="col-sm-3">Lead Student</dt><dd class="col-sm-9" id="vp-student"></dd>
                    <dt class="col-sm-3">Department</dt><dd class="col-sm-9" id="vp-department"></dd>
                    <dt class="col-sm-3">Supervisor</dt><dd class="col-sm-9" id="vp-supervisor"></dd>
                    <dt class="col-sm-3">Academic Year</dt><dd class="col-sm-9" id="vp-year"></dd>
                    <dt class="col-sm-3">Status</dt><dd class="col-sm-9" id="vp-status"></dd>
                    <dt class="col-sm-3">Archived</dt><dd class="col-sm-9" id="vp-archived"></dd>
                </dl>
                <div id="vp-description-wrap" class="mb-3" style="display:none">
                    <h6>Project Description</h6>
                    <p id="vp-description" class="text-muted small"></p>
                </div>
                <h6>Documents</h6>
                <div id="vp-docs">
                    <p class="text-muted small">No documents uploaded.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var baseUrl = <?= json_encode(base_url('download.php')) ?>;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.vault-preview-btn');
        if (!btn) return;
        document.getElementById('vaultPreviewModalLabel').textContent = btn.dataset.title;
        document.getElementById('vp-group').textContent = btn.dataset.group;
        document.getElementById('vp-student').textContent = btn.dataset.student;
        document.getElementById('vp-department').textContent = btn.dataset.department;
        document.getElementById('vp-supervisor').textContent = btn.dataset.supervisor;
        document.getElementById('vp-year').textContent = btn.dataset.year;
        document.getElementById('vp-status').textContent = btn.dataset.status;
        document.getElementById('vp-archived').textContent = btn.dataset.archived;

        var desc = btn.dataset.description || '';
        var descWrap = document.getElementById('vp-description-wrap');
        if (desc) {
            document.getElementById('vp-description').textContent = desc;
            descWrap.style.display = '';
        } else {
            descWrap.style.display = 'none';
        }

        var docs = [];
        try { docs = JSON.parse(btn.dataset.docs || '[]'); } catch (err) {}
        var docsEl = document.getElementById('vp-docs');
        if (!docs.length) {
            docsEl.innerHTML = '<p class="text-muted small">No documents uploaded.</p>';
        } else {
            var html = '<ul class="list-group list-group-flush">';
            docs.forEach(function (d) {
                var typeLabel = d.document_type === 'proposal' ? 'Documentation' : (d.document_type.charAt(0).toUpperCase() + d.document_type.slice(1));
                html += '<li class="list-group-item d-flex justify-content-between align-items-center px-0">'
                    + '<span><i class="bi bi-file-earmark me-2 text-muted"></i>' + escHtml(d.file_name) + ' <span class="badge bg-light text-dark ms-1">' + escHtml(typeLabel) + '</span></span>'
                    + '<span class="d-flex gap-1">'
                    + '<a href="' + baseUrl + '?id=' + d.id + '&view=1" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a>'
                    + '<a href="' + baseUrl + '?id=' + d.id + '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>'
                    + '</span>'
                    + '</li>';
            });
            html += '</ul>';
            docsEl.innerHTML = html;
        }
    });
    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
