<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

$hod_user = get_user_by_id($uid);
$hod_department_info = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error = empty($hod_department_variants) ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

// List in-progress and completed projects (not yet archived)
$completable = [];
if (!empty($hod_department_variants)) {
    $dept_placeholders = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.status, p.group_id, u.full_name AS student_name, u.reg_number, u.email, sup.full_name AS supervisor_name, g.name AS group_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_directory,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN users sup ON p.supervisor_id = sup.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status IN ("in_progress", "approved", "completed")
            AND p.id NOT IN (SELECT project_id FROM archive_metadata)
            AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY p.status = "completed" DESC, p.updated_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $completable = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (empty($hod_department_variants)) {
        flash('error', 'HOD department is not configured.');
        redirect(base_url('hod/archive.php'));
    }

    $action = $_POST['action'] ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id) {
        $dept_placeholders = sql_placeholders(count($hod_department_variants));
        $stmt = $pdo->prepare('SELECT p.id, p.status FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ') LIMIT 1');
        $stmt->execute(array_merge([$project_id], $hod_department_variants));
        $scoped_project = $stmt->fetch();
        if (!$scoped_project) {
            flash('error', 'Project is outside your department scope.');
            redirect(base_url('hod/archive.php'));
        }

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

$archived = [];
if (!empty($hod_department_variants)) {
    $dept_placeholders = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.updated_at, p.group_id, u.full_name AS student_name, u.reg_number, u.email, sup.full_name AS supervisor_name, g.name AS group_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_directory,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN users sup ON p.supervisor_id = sup.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        JOIN archive_metadata am ON am.project_id = p.id
        WHERE p.status = "archived" AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY am.archived_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $archived = $stmt->fetchAll();
}

$pageTitle = 'Archive';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Archive</h1>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Ongoing & Completed (Ready to Archive)</div>
    <div class="card-body">
        <?php if (empty($completable)): ?>
            <p class="text-muted mb-0">No projects to complete or archive.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Title</th><th>Input</th><th>Supervisor</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($completable as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <span class="badge bg-info text-dark">Group Vault: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                    <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Solo Vault</span>
                                    <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <small><?= e($p['member_directory'] ?: '—') ?></small>
                                <?php else: ?>
                                    <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><small class="text-muted">Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Logbook <?= (int) ($p['logbook_count'] ?? 0) ?> | Msg <?= (int) ($p['message_count'] ?? 0) ?></small></td>
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
                <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Title</th><th>Input</th><th>Supervisor</th><th>Archived</th></tr></thead>
                <tbody>
                    <?php foreach ($archived as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <span class="badge bg-info text-dark">Group Vault: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                    <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Solo Vault</span>
                                    <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <small><?= e($p['member_directory'] ?: '—') ?></small>
                                <?php else: ?>
                                    <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><small class="text-muted">Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Logbook <?= (int) ($p['logbook_count'] ?? 0) ?> | Msg <?= (int) ($p['message_count'] ?? 0) ?></small></td>
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
