<?php
/**
 * Supervisor Logbook — meeting records & action points
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
ensure_supervisor_logsheets_table($pdo);

$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare(
    'SELECT p.*, u.full_name AS student_name, u.email, u.index_number,
            sv.full_name AS supervisor_name, sv.department AS supervisor_dept,
            g.name AS group_name,
            (SELECT GROUP_CONCAT(CONCAT(u2.full_name," (",COALESCE(NULLIF(u2.index_number,""),u2.email),")")
                     ORDER BY CASE WHEN gm2.role="lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR "; ")
             FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
             WHERE gm2.group_id = p.group_id) AS member_list
     FROM projects p
     JOIN users u  ON p.student_id  = u.id
     JOIN users sv ON sv.id         = p.supervisor_id
     LEFT JOIN `groups` g ON g.id  = p.group_id
     WHERE p.id = ? AND p.supervisor_id = ?'
);
$stmt->execute([$pid, $uid]);
$project = $stmt->fetch();

if (!$project) {
    flash('error', 'Project not found.');
    redirect(base_url('supervisor/students.php'));
}

$is_archived = ($project['status'] ?? '') === 'archived';
$students_display = $project['member_list'] ?: $project['student_name'];

/* ─── Resolve department label ──────────────────────────────────────────── */
$dept_info  = resolve_department_info($pdo, (string) ($project['supervisor_dept'] ?? ''));
$dept_label = strtoupper($dept_info['name'] ?: $dept_info['raw'] ?: 'DEPARTMENT');

$error = '';

/* ─── POST ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($is_archived) {
        flash('error', 'Project is archived. Logbook cannot be modified.');
        redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_logsheet') {
        $meeting_date = $_POST['meeting_date'] ?? '';
        $attendees    = trim($_POST['student_attendees'] ?? '');
        $topics       = trim($_POST['topics_discussed']  ?? '');
        $actions      = trim($_POST['action_points']     ?? '');
        $next_date    = $_POST['next_meeting_date']      ?? null;
        $notes        = trim($_POST['supervisor_notes']  ?? '');

        if (!$meeting_date || !$topics) {
            $error = 'Meeting date and topics discussed are required.';
        } else {
            $pdo->prepare(
                'INSERT INTO supervisor_logsheets
                 (project_id, supervisor_id, meeting_date, student_attendees,
                  topics_discussed, action_points, next_meeting_date, supervisor_notes, confirmed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$pid, $uid, $meeting_date, $attendees,
                        $topics, $actions ?: null, $next_date ?: null, $notes ?: null]);
            flash('success', 'Logbook entry saved.');
            redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
        }
    }

    if ($action === 'delete_logsheet') {
        $log_id = (int) ($_POST['log_id'] ?? 0);
        $pdo->prepare('DELETE FROM supervisor_logsheets WHERE id = ? AND supervisor_id = ?')
            ->execute([$log_id, $uid]);
        flash('success', 'Entry deleted.');
        redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
    }
}

/* ─── Fetch entries ─────────────────────────────────────────────────────── */
$stmt = $pdo->prepare('SELECT * FROM supervisor_logsheets WHERE project_id = ? ORDER BY meeting_date DESC');
$stmt->execute([$pid]);
$logsheets = $stmt->fetchAll();

$session_count = count($logsheets);

$pageTitle = 'Supervisor Log Book';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1 class="mb-1">Supervisor Logbook</h1>
        <p class="text-muted mb-0"><a href="<?= base_url('supervisor/student_detail.php?pid=' . $pid) ?>">← Back to project</a></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($session_count > 0): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Logbook
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Project / institution info strip -->
<div class="card mb-4 border-start border-primary border-3" id="logbook-header">
    <div class="card-body py-2">
        <div class="row g-1 small">
            <div class="col-md-4"><span class="text-muted">Institution:</span> <strong>Regional Maritime University</strong></div>
            <div class="col-md-4"><span class="text-muted">Department:</span> <strong><?= e($dept_info['name'] ?: $dept_info['raw'] ?: '—') ?></strong></div>
            <div class="col-md-4"><span class="text-muted">Supervisor:</span> <strong><?= e($project['supervisor_name']) ?></strong></div>
            <div class="col-md-8"><span class="text-muted">Project Title:</span> <strong><?= e($project['title']) ?></strong></div>
            <div class="col-md-4"><span class="text-muted">Student(s):</span> <strong><?= e($students_display) ?></strong></div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($is_archived): ?>
    <div class="alert alert-secondary d-flex gap-2 align-items-center">
        <i class="bi bi-archive-fill fs-5"></i>
        <div>Project is <strong>archived</strong>. Logbook is read-only.</div>
    </div>
<?php endif; ?>

<!-- Stats bar -->
<?php if ($session_count > 0): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center py-2">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold text-primary"><?= $session_count ?></div>
                <div class="small text-muted">Total Sessions</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-2">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold text-success"><?= e(date('M j, Y', strtotime($logsheets[count($logsheets)-1]['meeting_date']))) ?></div>
                <div class="small text-muted">First Session</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-2">
            <div class="card-body py-1">
                <div class="fs-3 fw-bold text-info"><?= e(date('M j, Y', strtotime($logsheets[0]['meeting_date']))) ?></div>
                <div class="small text-muted">Latest Session</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add new entry form -->
<?php if (!$is_archived): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-plus-circle me-1 text-success"></i> New Logbook Entry</span>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#logbook-form">
            Toggle
        </button>
    </div>
    <div class="collapse show" id="logbook-form">
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_logsheet">

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Meeting Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="meeting_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Next Meeting Date</label>
                    <input type="date" class="form-control" name="next_meeting_date">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Students Present <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="student_attendees"
                           value="<?= e($students_display) ?>"
                           placeholder="Names of students who attended">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Topics Discussed <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="topics_discussed" rows="3" required
                              placeholder="Summarise the key discussion points of this session..."></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Action Points / Tasks Assigned</label>
                    <textarea class="form-control" name="action_points" rows="3"
                              placeholder="List tasks, deadlines, and deliverables assigned to the student(s)..."></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Supervisor Notes <span class="text-white-50 fw-normal">(private)</span></label>
                    <textarea class="form-control" name="supervisor_notes" rows="3"
                              placeholder="Private observations, concerns, or reminders for your own reference..."></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Save Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Logbook entries timeline -->
<div class="card" id="logbook-entries">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-journal-richtext me-1"></i> Logbook Entries</span>
        <span class="badge bg-primary"><?= $session_count ?> session<?= $session_count !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body <?= empty($logsheets) ? '' : 'p-0' ?>">
        <?php if (empty($logsheets)): ?>
            <p class="text-muted mb-0">No logbook entries yet. Add the first meeting record above.</p>
        <?php else: ?>
            <div class="list-group list-group-flush" id="logbook-list">
                <?php foreach ($logsheets as $i => $log):
                    $is_future = strtotime($log['meeting_date']) > time();
                    $session_no = $session_count - $i;
                ?>
                <div class="list-group-item px-4 py-3 logbook-entry">
                    <div class="row g-3 align-items-start">
                        <!-- Left column: session number + date -->
                        <div class="col-md-3 col-lg-2">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary rounded-pill">Session <?= $session_no ?></span>
                            </div>
                            <div class="fw-semibold"><?= e(date('l', strtotime($log['meeting_date']))) ?></div>
                            <div class="text-muted small"><?= e(date('M j, Y', strtotime($log['meeting_date']))) ?></div>
                            <?php if ($log['next_meeting_date']): ?>
                                <div class="mt-2 small">
                                    <i class="bi bi-calendar-check text-success me-1"></i>
                                    Next: <?= e(date('M j, Y', strtotime($log['next_meeting_date']))) ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-muted mt-2" style="font-size:.73em;">
                                Recorded <?= e(date('M j, Y', strtotime($log['confirmed_at']))) ?>
                            </div>
                        </div>

                        <!-- Right column: details -->
                        <div class="col-md-9 col-lg-10">
                            <div class="mb-2">
                                <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em;">Attendees</span>
                                <p class="mb-0 small"><?= e($log['student_attendees'] ?: '—') ?></p>
                            </div>

                            <div class="mb-2">
                                <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em;">Topics Discussed</span>
                                <p class="mb-0 small"><?= nl2br(e($log['topics_discussed'])) ?></p>
                            </div>

                            <?php if ($log['action_points']): ?>
                            <div class="mb-2">
                                <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em;">Action Points</span>
                                <ul class="mb-0 ps-3 small">
                                    <?php foreach (array_filter(preg_split('/\r?\n/', trim($log['action_points']))) as $ap): ?>
                                        <li><?= e($ap) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if ($log['supervisor_notes']): ?>
                            <div class="mb-2">
                                <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em;">
                                    <i class="bi bi-lock-fill me-1" style="font-size:.8em;"></i> Supervisor Notes
                                </span>
                                <p class="mb-0 small text-muted fst-italic"><?= nl2br(e($log['supervisor_notes'])) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if (!$is_archived): ?>
                            <form method="post" class="mt-2 no-print">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_logsheet">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Delete this logbook entry?')">
                                    <i class="bi bi-trash me-1"></i> Delete Entry
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    nav, .sidebar, header, footer, .no-print, .btn,
    #logbook-form, .collapse { display: none !important; }
    #logbook-header { border: 1px solid #ccc !important; }
    body { background: white !important; color: black !important; }
    .card { border: none !important; box-shadow: none !important; }
    .list-group-item { border-bottom: 1px solid #ccc !important; }
    .badge { background: none !important; color: black !important; border: 1px solid #ccc !important; }
    .text-muted { color: #444 !important; }
}

.logbook-entry:hover { background: rgba(255,255,255,.03); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
