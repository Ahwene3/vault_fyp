<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();
ensure_group_submission_tables($pdo);

$hod_user                = get_user_by_id($uid);
$hod_department_info     = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label    = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error  = empty($hod_department_variants)
    ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

/* ─── Fetch all pending submissions (both sources) ─────────────────────────── */
$pending = [];
if (!empty($hod_department_variants)) {
    $ph = sql_placeholders(count($hod_department_variants));

    // 1. Individual / self-formed group projects (projects table, status=submitted)
    $stmt = $pdo->prepare(
        "SELECT p.id, p.title, p.status, p.submitted_at, p.group_id,
                u.full_name AS student_name, u.index_number, u.email,
                g.name AS group_name,
                (SELECT GROUP_CONCAT(CONCAT(u2.full_name, ' (', COALESCE(NULLIF(u2.index_number,''), u2.email), ')')
                         ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name SEPARATOR ', ')
                 FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
                 WHERE gm2.group_id = p.group_id) AS member_directory,
                'project' AS source, NULL AS sub_id
         FROM projects p
         JOIN users u ON p.student_id = u.id
         LEFT JOIN `groups` g ON g.id = p.group_id
         WHERE p.status = 'submitted'
           AND LOWER(TRIM(COALESCE(u.department,''))) IN ($ph)
         ORDER BY p.submitted_at ASC"
    );
    $stmt->execute($hod_department_variants);
    foreach ($stmt->fetchAll() as $row) $pending[] = $row;

    // 2. HOD-formed group submissions (group_submissions table, status=pending)
    $stmt = $pdo->prepare(
        "SELECT gs.group_id, gs.title, gs.status, gs.submitted_at,
                NULL AS group_name_proj,
                g.name AS group_name,
                (SELECT GROUP_CONCAT(CONCAT(u2.full_name, ' (', COALESCE(NULLIF(u2.index_number,''), u2.email), ')')
                         ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name SEPARATOR ', ')
                 FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
                 WHERE gm2.group_id = g.id) AS member_directory,
                u.full_name AS student_name, u.index_number, u.email,
                gs.keywords, gs.document_path,
                'group_sub' AS source, gs.id AS sub_id,
                NULL AS id
         FROM group_submissions gs
         JOIN `groups` g ON g.id = gs.group_id
         LEFT JOIN users u ON u.id = gs.submitted_by
         WHERE gs.status = 'pending'
           AND LOWER(TRIM(COALESCE(g.department,''))) IN ($ph)
         ORDER BY gs.submitted_at ASC"
    );
    $stmt->execute($hod_department_variants);
    foreach ($stmt->fetchAll() as $row) $pending[] = $row;

    // Sort merged list by submitted_at
    usort($pending, fn($a, $b) => strtotime($a['submitted_at']) <=> strtotime($b['submitted_at']));
}

function hod_project_member_ids(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) return [];
    $ids = [(int) $project['student_id']];
    if (!empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $project['group_id']]);
        foreach ($stmt->fetchAll() as $m) $ids[] = (int) $m['student_id'];
    }
    return array_values(array_unique(array_filter($ids)));
}

/* ─── POST: approve / reject ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (empty($hod_department_variants)) {
        flash('error', 'HOD department is not configured.');
        redirect(base_url('hod/topics.php'));
    }

    $action     = $_POST['action']     ?? '';
    $source     = $_POST['source']     ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $sub_id     = (int) ($_POST['sub_id']     ?? 0);

    if (!in_array($action, ['approve', 'reject'], true)) {
        redirect(base_url('hod/topics.php'));
    }

    /* ── Individual project (projects table) ── */
    if ($source === 'project' && $project_id) {
        $ph   = sql_placeholders(count($hod_department_variants));
        $stmt = $pdo->prepare(
            "SELECT p.id FROM projects p JOIN users u ON p.student_id = u.id
             WHERE p.id = ? AND p.status = 'submitted'
               AND LOWER(TRIM(COALESCE(u.department,''))) IN ($ph)"
        );
        $stmt->execute(array_merge([$project_id], $hod_department_variants));

        if ($stmt->fetch()) {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE projects SET status='approved', approved_at=NOW(), approved_by=?, rejection_reason=NULL WHERE id=?")
                    ->execute([$uid, $project_id]);
                foreach (hod_project_member_ids($pdo, $project_id) as $mid) {
                    notify_user($mid, 'topic_approved', 'Topic & Proposal Approved',
                        'Your project topic and proposal have been approved. A supervisor will be assigned shortly.',
                        base_url('student/project.php'));
                }
                flash('success', 'Topic approved. Assign a supervisor from the Assign Supervisors page.');
            } else {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare("UPDATE projects SET status='rejected', approved_by=?, rejection_reason=? WHERE id=?")
                    ->execute([$uid, $reason ?: null, $project_id]);
                foreach (hod_project_member_ids($pdo, $project_id) as $mid) {
                    notify_user($mid, 'topic_rejected', 'Topic Rejected',
                        'Your project topic was rejected.' . ($reason ? " Reason: $reason" : '') . ' You may resubmit.',
                        base_url('student/project.php'));
                }
                flash('success', 'Topic rejected.');
            }
        }
        redirect(base_url('hod/topics.php'));
    }

    /* ── HOD-formed group submission (group_submissions table) ── */
    if ($source === 'group_sub' && $sub_id) {
        $stmt = $pdo->prepare(
            "SELECT gs.*, g.name AS group_name, g.department AS group_dept, g.academic_year
             FROM group_submissions gs
             JOIN `groups` g ON g.id = gs.group_id
             WHERE gs.id = ? AND gs.status = 'pending' LIMIT 1"
        );
        $stmt->execute([$sub_id]);
        $sub = $stmt->fetch();

        if (!$sub) {
            flash('error', 'Submission not found or already processed.');
            redirect(base_url('hod/topics.php'));
        }

        $sub_dept_info = resolve_department_info($pdo, (string) ($sub['group_dept'] ?? ''));
        if (empty(array_intersect($sub_dept_info['variants'], $hod_department_variants))) {
            flash('error', 'This group is outside your department scope.');
            redirect(base_url('hod/topics.php'));
        }

        if ($action === 'approve') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE group_submissions SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$uid, $sub_id]);

                $pdo->prepare("UPDATE `groups` SET status='approved' WHERE id=?")
                    ->execute([$sub['group_id']]);

                // Find or create project record (no supervisor yet — assigned separately)
                $lead_stmt = $pdo->prepare("SELECT student_id FROM group_members WHERE group_id=? AND role='lead' LIMIT 1");
                $lead_stmt->execute([$sub['group_id']]);
                $lead_row = $lead_stmt->fetch();
                $lead_id  = $lead_row ? (int) $lead_row['student_id'] : null;

                if ($lead_id) {
                    $ep = $pdo->prepare('SELECT id FROM projects WHERE group_id=? LIMIT 1');
                    $ep->execute([$sub['group_id']]);
                    $existing = $ep->fetch();

                    if ($existing) {
                        $pdo->prepare(
                            "UPDATE projects SET title=?, keywords=?, status='approved', approved_at=NOW(), approved_by=? WHERE id=?"
                        )->execute([$sub['title'], $sub['keywords'] ?: null, $uid, (int) $existing['id']]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO projects (student_id, group_id, title, keywords, status, approved_at, approved_by, academic_year)
                             VALUES (?, ?, ?, ?, 'approved', NOW(), ?, ?)"
                        )->execute([
                            $lead_id, $sub['group_id'], $sub['title'],
                            $sub['keywords'] ?: null, $uid, $sub['academic_year'] ?? date('Y'),
                        ]);
                    }
                }

                $members_stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id=?');
                $members_stmt->execute([$sub['group_id']]);
                foreach ($members_stmt->fetchAll() as $m) {
                    notify_user((int) $m['student_id'], 'topic_approved', 'Topic & Proposal Approved',
                        "Your submission \"{$sub['title']}\" has been approved. A supervisor will be assigned shortly.",
                        base_url('student/project.php'));
                }

                $pdo->commit();
                flash('success', 'Submission approved. Assign a supervisor from the Assign Supervisors page.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'An error occurred: ' . $e->getMessage());
            }
        } else {
            $reason = trim($_POST['rejection_reason'] ?? '');
            $pdo->prepare("UPDATE group_submissions SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$reason ?: null, $uid, $sub_id]);
            $pdo->prepare("UPDATE `groups` SET status='formed' WHERE id=?")
                ->execute([$sub['group_id']]);

            $members_stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id=?');
            $members_stmt->execute([$sub['group_id']]);
            foreach ($members_stmt->fetchAll() as $m) {
                notify_user((int) $m['student_id'], 'topic_rejected', 'Submission Rejected',
                    "Your submission \"{$sub['title']}\" was rejected." . ($reason ? " Reason: $reason" : '') . ' Please revise and resubmit.',
                    base_url('student/group_submit.php'));
            }
            flash('success', 'Submission rejected. Group notified to resubmit.');
        }
        redirect(base_url('hod/topics.php'));
    }
}

$pageTitle = 'Topic & Proposal Approval';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="mb-0">Topic &amp; Proposal Approval</h1>
    <span class="badge bg-warning text-dark fs-6"><?= count($pending) ?> pending</span>
</div>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Pending Submissions</div>
    <div class="card-body">
        <?php if (empty($pending)): ?>
            <p class="text-muted mb-0">No topics or proposals pending approval.</p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($pending as $p):
                    $is_group_sub = $p['source'] === 'group_sub';
                ?>
                    <div class="list-group-item px-0 py-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h6 class="mb-0"><?= e($p['title']) ?></h6>
                            <span class="badge <?= $is_group_sub ? 'bg-primary' : 'bg-secondary' ?> ms-2">
                                <?= $is_group_sub ? 'Group Submission' : 'Solo / Self-formed' ?>
                            </span>
                        </div>

                        <?php if (!empty($p['group_id']) || $is_group_sub): ?>
                            <p class="mb-1 text-muted small">
                                <i class="bi bi-people-fill me-1"></i>
                                <strong><?= e($p['group_name'] ?: ('Group #' . $p['group_id'])) ?></strong>
                                &nbsp;|&nbsp; Members: <?= e($p['member_directory'] ?: '—') ?>
                            </p>
                        <?php else: ?>
                            <p class="mb-1 text-muted small">
                                <i class="bi bi-person me-1"></i>
                                <?= e($p['student_name']) ?> — <?= e($p['index_number'] ?? $p['email']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($p['keywords'])): ?>
                            <p class="mb-2">
                                <?php foreach (array_filter(array_map('trim', explode(',', $p['keywords']))) as $kw): ?>
                                    <span class="badge bg-secondary me-1"><?= e($kw) ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>

                        <p class="mb-2 text-muted small">
                            <i class="bi bi-clock me-1"></i> Submitted: <?= e(date('M j, Y H:i', strtotime($p['submitted_at']))) ?>
                        </p>

                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                                <?php if ($is_group_sub): ?>
                                    <input type="hidden" name="sub_id" value="<?= (int) $p['sub_id'] ?>">
                                <?php else: ?>
                                    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-check-circle me-1"></i> Approve
                                </button>
                            </form>
                            <form method="post" class="d-inline d-flex gap-2 align-items-center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                                <?php if ($is_group_sub): ?>
                                    <input type="hidden" name="sub_id" value="<?= (int) $p['sub_id'] ?>">
                                <?php else: ?>
                                    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                                <?php endif; ?>
                                <input type="text" name="rejection_reason" placeholder="Rejection reason (optional)"
                                       class="form-control form-control-sm" style="width:220px;">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="bi bi-x-circle me-1"></i> Reject
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
