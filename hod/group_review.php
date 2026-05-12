<?php
/**
 * HOD — Manual Supervisor Assignment
 * Shows all approved projects (no supervisor yet) and allows the HOD to assign one.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

$hod_user            = get_user_by_id($uid);
$hod_dept_info       = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_dept_vars       = $hod_dept_info['variants'];
$hod_dept_label      = $hod_dept_info['name'] ?: $hod_dept_info['raw'];
$dept_scope_err      = empty($hod_dept_vars)
    ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

/* ─── Supervisors in this department ───────────────────────────────────────── */
$supervisors = [];
if (!empty($hod_dept_vars)) {
    $ph  = sql_placeholders(count($hod_dept_vars));
    $s   = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role='supervisor' AND is_active=1 AND LOWER(TRIM(COALESCE(department,''))) IN ($ph) ORDER BY full_name");
    $s->execute($hod_dept_vars);
    $supervisors = $s->fetchAll();
}

/* helper */
function gr_member_ids(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $proj = $stmt->fetch();
    if (!$proj) return [];
    $ids = [(int) $proj['student_id']];
    if (!empty($proj['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id = ?');
        $stmt->execute([(int) $proj['group_id']]);
        foreach ($stmt->fetchAll() as $m) $ids[] = (int) $m['student_id'];
    }
    return array_values(array_unique(array_filter($ids)));
}

/* ─── POST: assign supervisor manually ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($dept_scope_err) {
        flash('error', $dept_scope_err);
        redirect(base_url('hod/group_review.php'));
    }

    $project_id    = (int) ($_POST['project_id']    ?? 0);
    $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);

    if ($project_id && $supervisor_id) {
        $ph = sql_placeholders(count($hod_dept_vars));

        $chk_proj = $pdo->prepare(
            "SELECT p.id FROM projects p JOIN users u ON p.student_id = u.id
             WHERE p.id = ? AND p.status = 'approved' AND p.supervisor_id IS NULL
               AND LOWER(TRIM(COALESCE(u.department,''))) IN ($ph) LIMIT 1"
        );
        $chk_proj->execute(array_merge([$project_id], $hod_dept_vars));

        $chk_sup = $pdo->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'supervisor' AND is_active = 1
               AND LOWER(TRIM(COALESCE(department,''))) IN ($ph) LIMIT 1"
        );
        $chk_sup->execute(array_merge([$supervisor_id], $hod_dept_vars));

        if (!$chk_proj->fetch() || !$chk_sup->fetch()) {
            flash('error', 'Project or supervisor is outside your department scope.');
            redirect(base_url('hod/group_review.php'));
        }

        $pdo->prepare("UPDATE projects SET supervisor_id = ?, status = 'in_progress' WHERE id = ? AND status = 'approved' AND supervisor_id IS NULL")
            ->execute([$supervisor_id, $project_id]);

        // Also update the group's supervisor_id if group project
        $grp = $pdo->prepare('SELECT group_id, title FROM projects WHERE id = ? LIMIT 1');
        $grp->execute([$project_id]);
        $proj_row = $grp->fetch();
        if ($proj_row && $proj_row['group_id']) {
            $pdo->prepare("UPDATE `groups` SET supervisor_id = ? WHERE id = ?")->execute([$supervisor_id, $proj_row['group_id']]);
        }

        $sup_name = '';
        $sn = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $sn->execute([$supervisor_id]);
        $sup_name = $sn->fetchColumn() ?: 'your supervisor';

        foreach (gr_member_ids($pdo, $project_id) as $mid) {
            notify_user($mid, 'supervisor_assigned', 'Supervisor Assigned',
                "A supervisor has been assigned to your project: $sup_name.",
                base_url('student/project.php'));
        }
        notify_user($supervisor_id, 'supervisor_assigned', 'New Project Assigned',
            "You have been assigned to supervise \"" . ($proj_row['title'] ?? 'a project') . "\".",
            base_url('supervisor/students.php'));

        flash('success', "Supervisor \"$sup_name\" assigned successfully.");
        redirect(base_url('hod/group_review.php'));
    }
}

/* ─── Fetch approved projects awaiting supervisor ──────────────────────────── */
$awaiting = [];
$assigned = [];
if (!empty($hod_dept_vars)) {
    $ph = sql_placeholders(count($hod_dept_vars));

    // Projects approved but no supervisor
    $stmt = $pdo->prepare(
        "SELECT p.id, p.title, p.approved_at, p.group_id, p.keywords,
                u.full_name AS lead_name, u.index_number, u.email,
                g.name AS group_name,
                (SELECT GROUP_CONCAT(CONCAT(u2.full_name,' (',COALESCE(NULLIF(u2.index_number,''),u2.email),')')
                         ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name SEPARATOR ', ')
                 FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
                 WHERE gm2.group_id = p.group_id) AS member_directory,
                (SELECT pd.id FROM project_documents pd WHERE pd.project_id = p.id AND pd.document_type = 'proposal' AND pd.is_latest = 1 LIMIT 1) AS proposal_doc_id,
                (SELECT pd.file_name FROM project_documents pd WHERE pd.project_id = p.id AND pd.document_type = 'proposal' AND pd.is_latest = 1 LIMIT 1) AS proposal_doc_name,
                (SELECT gs.document_path FROM group_submissions gs WHERE gs.group_id = p.group_id AND gs.document_path IS NOT NULL ORDER BY gs.submitted_at DESC LIMIT 1) AS gs_doc_path
         FROM projects p
         JOIN users u ON p.student_id = u.id
         LEFT JOIN `groups` g ON g.id = p.group_id
         WHERE p.status = 'approved' AND p.supervisor_id IS NULL
           AND LOWER(TRIM(COALESCE(u.department,''))) IN ($ph)
         ORDER BY p.approved_at ASC"
    );
    $stmt->execute($hod_dept_vars);
    $awaiting = $stmt->fetchAll();

    // Recently assigned (in_progress, last 20)
    $stmt = $pdo->prepare(
        "SELECT p.id, p.title, p.group_id, g.name AS group_name,
                u.full_name AS lead_name, sv.full_name AS supervisor_name, sv.email AS supervisor_email
         FROM projects p
         JOIN users u ON p.student_id = u.id
         JOIN users sv ON sv.id = p.supervisor_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         WHERE p.status = 'in_progress'
           AND LOWER(TRIM(COALESCE(u.department,''))) IN ($ph)
         ORDER BY p.approved_at DESC LIMIT 20"
    );
    $stmt->execute($hod_dept_vars);
    $assigned = $stmt->fetchAll();
}

$pageTitle = 'Assign Supervisors';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-0">Assign Supervisors</h1>
        <p class="text-muted mb-0 small mt-1">Department: <strong><?= e($hod_dept_label ?: 'Unknown') ?></strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= base_url('hod/assign.php') ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-shuffle me-1"></i> Auto-Assign
        </a>
        <a href="<?= base_url('hod/proposals.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1"></i> View Proposals
        </a>
    </div>
</div>

<?php if ($dept_scope_err): ?>
    <div class="alert alert-danger"><?= e($dept_scope_err) ?></div>
<?php endif; ?>

<!-- Awaiting Assignment -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-split me-1"></i> Awaiting Supervisor Assignment</span>
        <span class="badge bg-warning text-dark"><?= count($awaiting) ?></span>
    </div>
    <div class="card-body <?= empty($awaiting) ? '' : 'p-0' ?>">
        <?php if (empty($awaiting)): ?>
            <p class="text-muted mb-0">All approved projects have a supervisor assigned.</p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($awaiting as $p): ?>
                    <div class="list-group-item px-4 py-3">
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-5">
                                <h6 class="mb-1"><?= e($p['title']) ?></h6>
                                <?php if ($p['group_id']): ?>
                                    <p class="mb-1 small text-muted">
                                        <i class="bi bi-people-fill me-1"></i>
                                        <strong><?= e($p['group_name'] ?: ('Group #' . $p['group_id'])) ?></strong>
                                    </p>
                                    <p class="mb-1 small text-muted"><?= e($p['member_directory'] ?: '—') ?></p>
                                <?php else: ?>
                                    <p class="mb-1 small text-muted">
                                        <i class="bi bi-person me-1"></i>
                                        <?= e($p['lead_name']) ?> (<?= e($p['index_number'] ?: $p['email']) ?>)
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($p['keywords'])): ?>
                                    <div class="mt-1">
                                        <?php foreach (array_filter(array_map('trim', explode(',', $p['keywords']))) as $kw): ?>
                                            <span class="badge bg-secondary me-1" style="font-size:.72em;"><?= e($kw) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($p['proposal_doc_id'] || $p['gs_doc_path']): ?>
                                    <div class="mt-2">
                                        <?php if ($p['proposal_doc_id']): ?>
                                            <a href="<?= base_url('download.php?id=' . (int)$p['proposal_doc_id']) ?>" class="btn btn-xs btn-outline-info btn-sm" target="_blank">
                                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Proposal Doc
                                            </a>
                                        <?php elseif ($p['gs_doc_path']): ?>
                                            <a href="<?= base_url(e($p['gs_doc_path'])) ?>" class="btn btn-sm btn-outline-info" target="_blank">
                                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Proposal Doc
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <p class="mb-0 mt-2 text-muted" style="font-size:.78em;">
                                    Approved <?= e(date('M j, Y', strtotime($p['approved_at']))) ?>
                                </p>
                            </div>
                            <div class="col-lg-7">
                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                                    <select name="supervisor_id" class="form-select form-select-sm" required style="min-width:200px;">
                                        <option value="">— Select Supervisor —</option>
                                        <?php foreach ($supervisors as $sv): ?>
                                            <option value="<?= (int) $sv['id'] ?>"><?= e($sv['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary"
                                            onclick="return confirm('Assign this supervisor?')">
                                        <i class="bi bi-person-check me-1"></i> Assign
                                    </button>
                                </form>
                                <?php if (empty($supervisors)): ?>
                                    <p class="text-danger small mt-1 mb-0">No active supervisors found in your department.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Already Assigned -->
<?php if (!empty($assigned)): ?>
<div class="card">
    <div class="card-header">
        <i class="bi bi-check-circle me-1 text-success"></i> Recently Assigned Projects
    </div>
    <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Project Title</th>
                    <th>Group</th>
                    <th>Lead</th>
                    <th>Supervisor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assigned as $a): ?>
                    <tr>
                        <td><?= e($a['title']) ?></td>
                        <td><?= e($a['group_name'] ?: '—') ?></td>
                        <td><?= e($a['lead_name']) ?></td>
                        <td>
                            <span class="badge bg-success"><?= e($a['supervisor_name']) ?></span>
                            <small class="text-muted d-block"><?= e($a['supervisor_email']) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
