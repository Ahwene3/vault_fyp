<?php
/**
 * HOD — Review group topic/proposal submissions with similarity detection.
 * Approve (+ assign supervisor) or Reject (with reason).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/similarity.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();
ensure_group_submission_tables($pdo);

$hod_user       = get_user_by_id($uid);
$hod_dept_info  = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_dept_vars  = $hod_dept_info['variants'];
$hod_dept_label = $hod_dept_info['name'] ?: $hod_dept_info['raw'];
$dept_scope_err = empty($hod_dept_vars)
    ? 'Your HOD account does not have a valid department configured. Contact admin.'
    : '';

$supervisors = [];
if (!empty($hod_dept_vars)) {
    $ph = sql_placeholders(count($hod_dept_vars));
    $s  = $pdo->prepare("SELECT id, full_name FROM users WHERE role='supervisor' AND is_active=1 AND LOWER(TRIM(COALESCE(department,''))) IN ($ph) ORDER BY full_name");
    $s->execute($hod_dept_vars);
    $supervisors = $s->fetchAll();
}

/* ─── POST: approve / reject ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($dept_scope_err) { flash('error', $dept_scope_err); redirect(base_url('hod/group_review.php')); }

    $action = $_POST['action'] ?? '';
    $sub_id = (int) ($_POST['sub_id'] ?? 0);

    if ($sub_id && in_array($action, ['approve', 'reject'], true)) {
        $stmt = $pdo->prepare(
            'SELECT gs.*, g.name AS group_name, g.workflow, g.department AS group_dept, g.academic_year
             FROM group_submissions gs
             JOIN `groups` g ON g.id = gs.group_id
             WHERE gs.id = ? AND gs.status = "pending" LIMIT 1'
        );
        $stmt->execute([$sub_id]);
        $sub = $stmt->fetch();

        if (!$sub) {
            flash('error', 'Submission not found or already processed.');
            redirect(base_url('hod/group_review.php'));
        }

        $sub_dept_info = resolve_department_info($pdo, (string) ($sub['group_dept'] ?? ''));
        if (empty(array_intersect($sub_dept_info['variants'], $hod_dept_vars))) {
            flash('error', 'This group is outside your department scope.');
            redirect(base_url('hod/group_review.php'));
        }

        if ($action === 'approve') {
            $sup_id = (int) ($_POST['supervisor_id'] ?? 0);
            if (!$sup_id) {
                flash('error', 'Please select a supervisor to assign.');
                redirect(base_url('hod/group_review.php'));
            }

            /* verify supervisor belongs to HOD dept — build $ph locally */
            $local_ph = sql_placeholders(count($hod_dept_vars));
            $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='supervisor' AND is_active=1 AND LOWER(TRIM(COALESCE(department,''))) IN ($local_ph) LIMIT 1");
            $chk->execute(array_merge([$sup_id], $hod_dept_vars));
            if (!$chk->fetch()) {
                flash('error', 'Selected supervisor is not in your department.');
                redirect(base_url('hod/group_review.php'));
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE group_submissions SET status="approved", reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                    ->execute([$uid, $sub_id]);

                $pdo->prepare('UPDATE `groups` SET status="approved", supervisor_id=? WHERE id=?')
                    ->execute([$sup_id, $sub['group_id']]);

                $lead_stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id=? AND role="lead" LIMIT 1');
                $lead_stmt->execute([$sub['group_id']]);
                $lead_row = $lead_stmt->fetch();
                $lead_id  = $lead_row ? (int) $lead_row['student_id'] : null;

                if ($lead_id) {
                    $ep = $pdo->prepare('SELECT id FROM projects WHERE group_id=? LIMIT 1');
                    $ep->execute([$sub['group_id']]);
                    $existing_proj = $ep->fetch();

                    if ($existing_proj) {
                        $pdo->prepare(
                            'UPDATE projects SET title=?, description=?, keywords=?, supervisor_id=?,
                             status="in_progress", approved_at=NOW(), approved_by=? WHERE id=?'
                        )->execute([
                            $sub['title'], $sub['abstract'] ?: null, $sub['keywords'] ?: null,
                            $sup_id, $uid, (int) $existing_proj['id'],
                        ]);
                        $proj_id = (int) $existing_proj['id'];
                    } else {
                        $pdo->prepare(
                            'INSERT INTO projects (student_id, group_id, supervisor_id, title, description, keywords,
                             status, approved_at, approved_by, academic_year)
                             VALUES (?, ?, ?, ?, ?, ?, "in_progress", NOW(), ?, ?)'
                        )->execute([
                            $lead_id, $sub['group_id'], $sup_id,
                            $sub['title'], $sub['abstract'] ?: null, $sub['keywords'] ?: null,
                            $uid, $sub['academic_year'] ?? date('Y'),
                        ]);
                        $proj_id = (int) $pdo->lastInsertId();
                    }

                    $sup_user  = get_user_by_id($sup_id);
                    $sup_name  = $sup_user['full_name'] ?? 'your supervisor';

                    $members_stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id=?');
                    $members_stmt->execute([$sub['group_id']]);
                    foreach ($members_stmt->fetchAll() as $m) {
                        notify_user(
                            (int) $m['student_id'],
                            'topic_approved',
                            'Submission Approved',
                            "Your project \"{$sub['title']}\" has been approved. Supervisor: $sup_name.",
                            base_url('student/project.php')
                        );
                    }
                    notify_user(
                        $sup_id,
                        'supervisor_assigned',
                        'New Project Assigned',
                        "You have been assigned to supervise \"{$sub['title']}\" ({$sub['group_name']}).",
                        base_url('supervisor/student_detail.php?pid=' . $proj_id)
                    );
                }

                $pdo->commit();
                flash('success', 'Submission approved and supervisor assigned.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'An error occurred: ' . $e->getMessage());
            }

        } else {
            $reason = trim($_POST['rejection_reason'] ?? '');
            $pdo->prepare('UPDATE group_submissions SET status="rejected", rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$reason ?: null, $uid, $sub_id]);
            $pdo->prepare('UPDATE `groups` SET status="formed" WHERE id=?')
                ->execute([$sub['group_id']]);

            $members_stmt = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id=?');
            $members_stmt->execute([$sub['group_id']]);
            $reason_suffix = $reason ? " Reason: $reason" : '';
            foreach ($members_stmt->fetchAll() as $m) {
                notify_user(
                    (int) $m['student_id'],
                    'topic_rejected',
                    'Submission Rejected',
                    "Your submission \"{$sub['title']}\" was rejected.$reason_suffix Please revise and resubmit.",
                    base_url('student/group_submit.php')
                );
            }
            flash('success', 'Submission rejected. Group has been notified to resubmit.');
        }
        redirect(base_url('hod/group_review.php'));
    }
}

/* ─── Fetch pending submissions (capped at 50) ───────────────────────────── */
$pending_subs = [];
$reviewed     = [];
if (!empty($hod_dept_vars)) {
    $ph   = sql_placeholders(count($hod_dept_vars));
    $sql  = "SELECT gs.id, gs.group_id, gs.type, gs.title, gs.abstract, gs.keywords,
                    gs.similarity_json, gs.similarity_top, gs.submitted_at,
                    g.name AS group_name, g.workflow, g.department AS group_dept,
                    u.full_name AS submitter_name,
                    (SELECT GROUP_CONCAT(
                        CONCAT(u2.full_name, ' (', COALESCE(NULLIF(u2.reg_number,''), u2.email), ')')
                        ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name
                        SEPARATOR ', ')
                     FROM group_members gm2
                     JOIN users u2 ON u2.id = gm2.student_id
                     WHERE gm2.group_id = g.id) AS members_list
             FROM group_submissions gs
             JOIN `groups` g ON g.id = gs.group_id
             JOIN users u ON u.id = gs.submitted_by
             WHERE gs.status = 'pending'
               AND LOWER(TRIM(COALESCE(g.department,''))) IN ($ph)
             ORDER BY gs.submitted_at ASC
             LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_dept_vars);
    $pending_subs = $stmt->fetchAll();

    /* history of reviewed submissions — reuses $ph from this block */
    $stmt = $pdo->prepare(
        "SELECT gs.id, gs.type, gs.title, gs.status, gs.reviewed_at,
                g.name AS group_name, rv.full_name AS reviewed_by_name
         FROM group_submissions gs
         JOIN `groups` g ON g.id = gs.group_id
         LEFT JOIN users rv ON rv.id = gs.reviewed_by
         WHERE gs.status != 'pending'
           AND LOWER(TRIM(COALESCE(g.department,''))) IN ($ph)
         ORDER BY gs.reviewed_at DESC LIMIT 20"
    );
    $stmt->execute($hod_dept_vars);
    $reviewed = $stmt->fetchAll();
}

/* decode or compute similarity; cache if not yet stored */
$level_badge = ['high' => 'bg-danger', 'moderate' => 'bg-warning text-dark', 'low' => 'bg-secondary'];
$level_row   = ['high' => 'table-danger', 'moderate' => 'table-warning', 'low' => ''];

foreach ($pending_subs as &$sub) {
    $json = $sub['similarity_json'];
    /* treat NULL or empty string as uncached; treat '[]' as cached-no-match */
    if ($json !== null && $json !== '') {
        $sub['similarity'] = json_decode($json, true) ?: [];
    } else {
        $sub['similarity'] = find_similar_projects(
            $pdo,
            $sub['title'],
            (string) ($sub['keywords'] ?? ''),
            (string) ($sub['abstract']  ?? '')
        );
        $pdo->prepare('UPDATE group_submissions SET similarity_json=?, similarity_top=? WHERE id=?')
            ->execute([
                json_encode($sub['similarity']),
                !empty($sub['similarity']) ? $sub['similarity'][0]['score'] : null,
                $sub['id'],
            ]);
    }

    $top = !empty($sub['similarity']) ? $sub['similarity'][0]['score'] : 0;
    $sub['_top']        = $top;
    $sub['_risk_class'] = $top >= 60 ? 'border-danger' : ($top >= 30 ? 'border-warning' : 'border-success');
    $sub['_risk_badge'] = $top >= 60 ? 'bg-danger'     : ($top >= 30 ? 'bg-warning text-dark' : 'bg-success');
    $sub['_risk_label'] = $top >= 60 ? 'High Similarity' : ($top >= 30 ? 'Moderate Similarity' : ($top > 0 ? 'Low Similarity' : 'No Match Found'));
}
unset($sub);

$pageTitle = 'Review Group Submissions';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-1">Review Group Submissions</h1>
<p class="text-muted mb-4">Department: <strong><?= e($hod_dept_label ?: 'Unknown') ?></strong></p>

<?php if ($dept_scope_err): ?>
    <div class="alert alert-danger"><?= e($dept_scope_err) ?></div>
<?php elseif (empty($pending_subs)): ?>
    <div class="alert alert-info">No pending group submissions in your department.</div>
<?php else: ?>
    <p class="mb-3 text-muted"><strong><?= count($pending_subs) ?></strong> submission(s) awaiting review.</p>

    <?php foreach ($pending_subs as $sub): ?>
        <div class="card mb-4 border-2 <?= $sub['_risk_class'] ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-primary me-2"><?= e(ucfirst($sub['type'])) ?></span>
                    <strong><?= e($sub['title']) ?></strong>
                    <span class="text-muted ms-2 small">— <?= e($sub['group_name']) ?></span>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($sub['_top'] > 0): ?>
                        <span class="badge <?= $sub['_risk_badge'] ?>"><?= $sub['_risk_label'] ?>: <?= $sub['_top'] ?>%</span>
                    <?php else: ?>
                        <span class="badge bg-success">No Similar Projects</span>
                    <?php endif; ?>
                    <small class="text-muted"><?= date('M j, Y', strtotime($sub['submitted_at'])) ?></small>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Group Members:</strong></p>
                        <p class="text-muted small mb-0"><?= e($sub['members_list'] ?: '—') ?></p>
                        <?php if ($sub['keywords']): ?>
                            <p class="mt-2 mb-0">
                                <strong>Keywords:</strong>
                                <?php foreach (explode(',', $sub['keywords']) as $kw): ?>
                                    <span class="badge bg-light text-dark border me-1"><?= e(trim($kw)) ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if ($sub['abstract']): ?>
                            <p class="mb-1"><strong>Abstract / Description:</strong></p>
                            <p class="text-muted small mb-0" style="max-height:80px;overflow:auto;"><?= nl2br(e($sub['abstract'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($sub['similarity'])): ?>
                    <div class="mb-3">
                        <p class="fw-semibold mb-2"><i class="bi bi-graph-up me-1"></i> Similarity Analysis</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr><th>Existing Project</th><th style="width:120px">Match Score</th><th style="width:100px">Risk Level</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sub['similarity'] as $match): ?>
                                        <tr class="<?= $level_row[$match['level']] ?? '' ?>">
                                            <td><?= e($match['title']) ?></td>
                                            <td>
                                                <div class="progress" style="height:16px;">
                                                    <div class="progress-bar <?= $level_badge[$match['level']] ?? 'bg-secondary' ?>"
                                                         style="width:<?= min(100, $match['score']) ?>%">
                                                        <?= $match['score'] ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="badge <?= $level_badge[$match['level']] ?? 'bg-secondary' ?>"><?= ucfirst($match['level']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2 small text-muted">
                            Score is a weighted average of title (50%), keywords (30%), and abstract (20%) Jaccard similarity.
                            <strong>60%+</strong> = high risk of duplication.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success py-2 mb-3">
                        <i class="bi bi-check-circle me-1"></i> No similar projects found — this appears to be an original topic.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-7">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="sub_id" value="<?= (int) $sub['id'] ?>">
                            <div class="input-group">
                                <select name="supervisor_id" class="form-select" required>
                                    <option value="">Select supervisor to assign…</option>
                                    <?php foreach ($supervisors as $sv): ?>
                                        <option value="<?= (int) $sv['id'] ?>"><?= e($sv['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-success"
                                    onclick="return confirm('Approve this submission and assign the selected supervisor?')">
                                    <i class="bi bi-check-circle me-1"></i> Approve
                                </button>
                            </div>
                            <?php if (empty($supervisors)): ?>
                                <div class="text-danger small mt-1">No active supervisors in your department. Add supervisors before approving.</div>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="col-md-5">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="sub_id" value="<?= (int) $sub['id'] ?>">
                            <div class="input-group">
                                <input type="text" name="rejection_reason" class="form-control form-control-sm" placeholder="Rejection reason (optional)">
                                <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Reject this submission? The group will be notified to revise and resubmit.')">
                                    <i class="bi bi-x-circle me-1"></i> Reject
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($reviewed)): ?>
    <div class="card mt-4">
        <div class="card-header">Recently Reviewed Submissions</div>
        <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Group</th><th>Title</th><th>Type</th><th>Decision</th><th>Reviewed</th></tr></thead>
                <tbody>
                    <?php foreach ($reviewed as $r): ?>
                        <tr>
                            <td><?= e($r['group_name']) ?></td>
                            <td><?= e(mb_substr($r['title'], 0, 60)) ?><?= mb_strlen($r['title']) > 60 ? '…' : '' ?></td>
                            <td><span class="badge bg-secondary"><?= e(ucfirst($r['type'])) ?></span></td>
                            <td><span class="badge <?= $r['status'] === 'approved' ? 'bg-success' : 'bg-danger' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                            <td><small class="text-muted"><?= $r['reviewed_at'] ? date('M j, Y', strtotime($r['reviewed_at'])) : '—' ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
