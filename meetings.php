<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';
require_login();

$user = current_user();
$role = $user['role'];
$uid  = (int) $user['id'];
$pdo  = getPDO();

if (!in_array($role, ['student', 'supervisor'], true)) {
    flash('error', 'Meeting scheduling is only available to students and supervisors.');
    redirect(base_url('dashboard.php'));
}

ensure_meetings_table($pdo);

/* ─── Status meta ───────────────────────────────────────────────── */
$status_meta = [
    'pending'     => ['Pending',     '#f59e0b', 'bi-hourglass-split',   'rgba(245,158,11,.15)'],
    'approved'    => ['Approved',    '#22c55e', 'bi-check-circle-fill', 'rgba(34,197,94,.12)'],
    'rejected'    => ['Rejected',    '#ef4444', 'bi-x-circle-fill',     'rgba(239,68,68,.12)'],
    'rescheduled' => ['Rescheduled', '#3b82f6', 'bi-arrow-repeat',      'rgba(59,130,246,.12)'],
    'completed'   => ['Completed',   '#8b5cf6', 'bi-patch-check-fill',  'rgba(139,92,246,.1)'],
    'cancelled'   => ['Cancelled',   '#64748b', 'bi-slash-circle',      'rgba(100,116,139,.1)'],
];

/* ─── Resolve student's project + supervisor ─────────────────────── */
$student_project = null;
$supervisor_options = [];
if ($role === 'student') {
    $gq = $pdo->prepare('SELECT gm.group_id FROM group_members gm JOIN `groups` g ON g.id=gm.group_id WHERE gm.student_id=? AND g.is_active=1 LIMIT 1');
    $gq->execute([$uid]);
    $gid = (int)($gq->fetchColumn() ?: 0);

    if ($gid) {
        $pq = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON u.id=p.supervisor_id WHERE p.group_id=? ORDER BY p.updated_at DESC LIMIT 1');
        $pq->execute([$gid]);
    } else {
        $pq = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON u.id=p.supervisor_id WHERE p.student_id=? ORDER BY p.updated_at DESC LIMIT 1');
        $pq->execute([$uid]);
    }
    $student_project = $pq->fetch() ?: null;
} elseif ($role === 'supervisor') {
    $spq = $pdo->prepare(
        'SELECT p.id, p.title, p.student_id, p.group_id,
                u.full_name AS student_name,
                g.name AS group_name
         FROM projects p
         JOIN users u ON u.id = p.student_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         WHERE p.supervisor_id = ? AND p.status NOT IN ("archived","rejected")
         ORDER BY p.updated_at DESC'
    );
    $spq->execute([$uid]);
    $supervisor_projects = $spq->fetchAll();
}

/* ─── POST handler ──────────────────────────────────────────────── */
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $error = 'Security check failed.'; goto render; }
    $action = $_POST['action'] ?? '';

    /* ── Book new meeting (student) ── */
    if ($action === 'book' && $role === 'student') {
        if (!$student_project || empty($student_project['supervisor_id'])) {
            $error = 'No supervisor assigned to your project yet.'; goto render;
        }
        $title   = trim($_POST['title']   ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $mdate   = trim($_POST['meeting_date'] ?? '');
        $mtime   = trim($_POST['meeting_time'] ?? '');
        $mtype   = $_POST['meeting_type'] ?? 'physical';
        $venue   = trim($_POST['venue']        ?? '');
        $mlink   = trim($_POST['meeting_link'] ?? '');
        $notes   = trim($_POST['notes']        ?? '');

        if (!$title || !$purpose || !$mdate || !$mtime) {
            $error = 'Title, purpose, date and time are required.'; goto render;
        }
        if (strtotime($mdate) < strtotime(date('Y-m-d'))) {
            $error = 'Meeting date cannot be in the past.'; goto render;
        }
        if (!in_array($mtype, ['physical','online'], true)) $mtype = 'physical';

        $att_path = $att_name = null;
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $f   = $_FILES['attachment'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','ppt','pptx','jpg','jpeg','png','zip'];
            if (!in_array($ext, $allowed, true)) { $error = 'Unsupported file type.'; goto render; }
            if ($f['size'] > 10*1024*1024) { $error = 'File too large (max 10 MB).'; goto render; }
            $dir = __DIR__ . '/uploads/meetings/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'meet_' . $uid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                $att_path = 'uploads/meetings/' . $fname;
                $att_name = $f['name'];
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO meetings (project_id,requester_id,supervisor_id,title,purpose,meeting_date,meeting_time,meeting_type,venue,meeting_link,notes,attachment_path,attachment_name)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([(int)$student_project['id'], $uid, (int)$student_project['supervisor_id'],
            $title, $purpose, $mdate, $mtime, $mtype,
            $venue ?: null, $mlink ?: null, $notes ?: null, $att_path, $att_name]);
        $mid = (int)$pdo->lastInsertId();

        notify_user((int)$student_project['supervisor_id'], 'meeting',
            'New Meeting Request',
            $user['full_name'] . ' has requested a meeting: ' . $title,
            base_url('meetings.php#meet-' . $mid));
        audit_log($pdo, 'meeting_request', 'project', 'meeting', $mid, $title, 'Meeting requested');
        $success = 'Meeting request sent to your supervisor.';
        goto render;
    }

    /* ── Book new meeting (supervisor-initiated) ── */
    if ($action === 'book' && $role === 'supervisor') {
        $fpid  = (int)($_POST['project_id'] ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $mdate   = trim($_POST['meeting_date'] ?? '');
        $mtime   = trim($_POST['meeting_time'] ?? '');
        $mtype   = $_POST['meeting_type'] ?? 'physical';
        $venue   = trim($_POST['venue']        ?? '');
        $mlink   = trim($_POST['meeting_link'] ?? '');
        $notes   = trim($_POST['notes']        ?? '');

        if (!$fpid || !$title || !$purpose || !$mdate || !$mtime) {
            $error = 'Project, title, purpose, date and time are required.'; goto render;
        }
        if (strtotime($mdate) < strtotime(date('Y-m-d'))) {
            $error = 'Meeting date cannot be in the past.'; goto render;
        }
        /* verify supervisor owns this project */
        $vc = $pdo->prepare('SELECT student_id FROM projects WHERE id=? AND supervisor_id=?');
        $vc->execute([$fpid, $uid]);
        $proj_row = $vc->fetch();
        if (!$proj_row) { $error = 'Project not found or access denied.'; goto render; }
        if (!in_array($mtype, ['physical','online'], true)) $mtype = 'physical';

        $ins = $pdo->prepare(
            'INSERT INTO meetings (project_id,requester_id,supervisor_id,title,purpose,meeting_date,meeting_time,meeting_type,venue,meeting_link,notes,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,"approved")'
        );
        $ins->execute([$fpid, $uid, $uid, $title, $purpose, $mdate, $mtime, $mtype,
            $venue ?: null, $mlink ?: null, $notes ?: null]);
        $mid = (int)$pdo->lastInsertId();

        /* notify the student */
        notify_user((int)$proj_row['student_id'], 'meeting',
            'Meeting Scheduled',
            $user['full_name'] . ' has scheduled a meeting: ' . $title . ' on ' . date('d/m/Y', strtotime($mdate)) . ' at ' . date('g:i A', strtotime($mtime)) . '.',
            base_url('meetings.php#meet-' . $mid));

        /* notify all group members */
        $gmq = $pdo->prepare('SELECT gm.student_id FROM group_members gm JOIN projects p ON p.group_id=gm.group_id WHERE p.id=?');
        $gmq->execute([$fpid]);
        foreach ($gmq->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            if ((int)$sid !== (int)$proj_row['student_id']) {
                notify_user((int)$sid, 'meeting', 'Meeting Scheduled',
                    'A meeting has been scheduled: ' . $title . ' on ' . date('d/m/Y', strtotime($mdate)) . '.',
                    base_url('meetings.php#meet-' . $mid));
            }
        }

        audit_log($pdo, 'meeting_request', 'project', 'meeting', $mid, $title, 'Supervisor-initiated meeting');
        $success = 'Meeting scheduled and student notified.';
        goto render;
    }

    /* ── Supervisor: approve / reject / reschedule / complete ── */
    if (in_array($action, ['approve','reject','reschedule','complete','cancel'], true) && $role === 'supervisor') {
        $mid  = (int)($_POST['mid'] ?? 0);
        $resp = trim($_POST['response_notes'] ?? '');
        $chk  = $pdo->prepare('SELECT * FROM meetings WHERE id=? AND supervisor_id=? LIMIT 1');
        $chk->execute([$mid, $uid]);
        $mtg  = $chk->fetch();
        if (!$mtg) { $error = 'Meeting not found.'; goto render; }

        if ($action === 'approve') {
            $venue = trim($_POST['venue'] ?? $mtg['venue'] ?? '');
            $mlink = trim($_POST['meeting_link'] ?? $mtg['meeting_link'] ?? '');
            $pdo->prepare('UPDATE meetings SET status="approved",response_notes=?,venue=?,meeting_link=?,updated_at=NOW() WHERE id=?')
                ->execute([$resp ?: null, $venue ?: null, $mlink ?: null, $mid]);
            notify_user((int)$mtg['requester_id'], 'meeting', 'Meeting Approved',
                'Your meeting "' . $mtg['title'] . '" has been approved.',
                base_url('meetings.php#meet-' . $mid));
            // notify all group members
            if (!empty($mtg['project_id'])) {
                $gmq = $pdo->prepare('SELECT gm.student_id FROM group_members gm JOIN projects p ON p.group_id=gm.group_id WHERE p.id=?');
                $gmq->execute([(int)$mtg['project_id']]);
                foreach ($gmq->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    if ((int)$sid !== (int)$mtg['requester_id']) {
                        notify_user((int)$sid, 'meeting', 'Meeting Approved',
                            'Your group meeting "' . $mtg['title'] . '" has been approved.',
                            base_url('meetings.php#meet-' . $mid));
                    }
                }
            }
            $success = 'Meeting approved.';
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE meetings SET status="rejected",response_notes=?,updated_at=NOW() WHERE id=?')
                ->execute([$resp ?: null, $mid]);
            notify_user((int)$mtg['requester_id'], 'meeting', 'Meeting Rejected',
                'Your meeting "' . $mtg['title'] . '" was not approved.' . ($resp ? ' Note: ' . $resp : ''),
                base_url('meetings.php#meet-' . $mid));
            $success = 'Meeting rejected.';
        } elseif ($action === 'reschedule') {
            $rdate = trim($_POST['rescheduled_date'] ?? '');
            $rtime = trim($_POST['rescheduled_time'] ?? '');
            if (!$rdate || !$rtime) { $error = 'New date and time are required.'; goto render; }
            if (strtotime($rdate) < strtotime(date('Y-m-d'))) { $error = 'Rescheduled date cannot be in the past.'; goto render; }
            $pdo->prepare('UPDATE meetings SET status="rescheduled",response_notes=?,rescheduled_date=?,rescheduled_time=?,updated_at=NOW() WHERE id=?')
                ->execute([$resp ?: null, $rdate, $rtime, $mid]);
            notify_user((int)$mtg['requester_id'], 'meeting', 'Meeting Rescheduled',
                'Your meeting "' . $mtg['title'] . '" has been rescheduled to ' . date('d-m-y', strtotime($rdate)) . ' at ' . date('g:i A', strtotime($rtime)) . '.',
                base_url('meetings.php#meet-' . $mid));
            $success = 'Meeting rescheduled.';
        } elseif ($action === 'complete') {
            $pdo->prepare('UPDATE meetings SET status="completed",updated_at=NOW() WHERE id=?')->execute([$mid]);
            $success = 'Meeting marked as completed.';
        } elseif ($action === 'cancel') {
            $pdo->prepare('UPDATE meetings SET status="cancelled",updated_at=NOW() WHERE id=?')->execute([$mid]);
            $success = 'Meeting cancelled.';
        }
        audit_log($pdo, 'meeting_' . $action, 'project', 'meeting', $mid, $mtg['title'] ?? '', $resp);
        goto render;
    }

    /* ── Student: cancel a meeting ── */
    if ($action === 'cancel' && $role === 'student') {
        $mid = (int)($_POST['mid'] ?? 0);

        /* fetch the meeting + its project info */
        $ms = $pdo->prepare('SELECT m.*, p.student_id AS proj_student, p.group_id AS proj_group
                              FROM meetings m
                              JOIN projects p ON p.id = m.project_id
                              WHERE m.id = ? LIMIT 1');
        $ms->execute([$mid]);
        $mtg = $ms->fetch();

        /* check the student is part of this meeting */
        $can = false;
        if ($mtg) {
            if ((int)$mtg['requester_id'] === $uid) {
                $can = true;
            } elseif ((int)$mtg['proj_student'] === $uid) {
                $can = true;
            } elseif (!empty($mtg['proj_group'])) {
                $gm = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id=? AND student_id=? LIMIT 1');
                $gm->execute([(int)$mtg['proj_group'], $uid]);
                if ($gm->fetchColumn()) $can = true;
            }
        }

        if ($can && in_array($mtg['status'], ['pending','approved','rescheduled'], true)) {
            $pdo->prepare('UPDATE meetings SET status="cancelled", updated_at=NOW() WHERE id=?')
                ->execute([$mid]);
            notify_user((int)$mtg['supervisor_id'], 'meeting', 'Meeting Cancelled',
                $user['full_name'] . ' cancelled the meeting: ' . $mtg['title'],
                base_url('meetings.php'));
            $success = 'Meeting cancelled.';
        } else {
            $error = 'Meeting not found or you do not have permission to cancel it.';
        }
        goto render;
    }
}

render:

/* ─── Fetch meetings ─────────────────────────────────────────────── */
$today = date('Y-m-d');
if ($role === 'student') {
    /* fetch meetings where:
       - student is the requester (student-initiated), OR
       - the meeting belongs to a project the student is part of (supervisor-initiated) */
    $mq = $pdo->prepare(
        'SELECT m.*, u.full_name AS supervisor_name, u.email AS supervisor_email,
                p.title AS project_title
         FROM meetings m
         JOIN users u ON u.id = m.supervisor_id
         JOIN projects p ON p.id = m.project_id
         WHERE m.requester_id = ?
            OR p.student_id = ?
            OR p.group_id IN (
                SELECT group_id FROM group_members WHERE student_id = ?
            )
         GROUP BY m.id
         ORDER BY m.meeting_date ASC, m.meeting_time ASC'
    );
    $mq->execute([$uid, $uid, $uid]);
} else {
    $mq = $pdo->prepare(
        'SELECT m.*, u.full_name AS requester_name, u.email AS requester_email,
                p.title AS project_title, g.name AS group_name
         FROM meetings m
         JOIN users u ON u.id = m.requester_id
         JOIN projects p ON p.id = m.project_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         WHERE m.supervisor_id = ?
         ORDER BY FIELD(m.status,"pending","approved","rescheduled","rejected","completed","cancelled"),
                  m.meeting_date ASC, m.meeting_time ASC'
    );
    $mq->execute([$uid]);
}
$all_meetings = $mq->fetchAll();

/* ─── Partition meetings ─────────────────────────────────────────── */
$pending_meetings   = array_filter($all_meetings, fn($m) => $m['status'] === 'pending');
$upcoming_meetings  = array_filter($all_meetings, fn($m) => in_array($m['status'], ['approved','rescheduled']) && $m['meeting_date'] >= $today);
$past_meetings      = array_filter($all_meetings, fn($m) => in_array($m['status'], ['completed','cancelled','rejected']) || ($m['meeting_date'] < $today && !in_array($m['status'],['pending'])));

$stats = [
    'pending'   => count(array_filter($all_meetings, fn($m) => $m['status'] === 'pending')),
    'upcoming'  => count($upcoming_meetings),
    'completed' => count(array_filter($all_meetings, fn($m) => $m['status'] === 'completed')),
    'total'     => count($all_meetings),
];

/* ─── Calendar data ─────────────────────────────────────────────── */
$cal_year  = (int)($_GET['cy'] ?? date('Y'));
$cal_month = (int)($_GET['cm'] ?? date('n'));
if ($cal_month < 1) { $cal_month = 12; $cal_year--; }
if ($cal_month > 12){ $cal_month = 1;  $cal_year++; }
$cal_key   = sprintf('%04d-%02d', $cal_year, $cal_month);
$cal_days  = [];
foreach ($all_meetings as $m) {
    $d = $m['meeting_date'];
    if (str_starts_with($d, $cal_key)) {
        $day = (int)substr($d, 8, 2);
        $cal_days[$day][] = $m['status'];
    }
}

/* ─── Page meta ─────────────────────────────────────────────────── */
$pageTitle               = 'Meetings';
$topbarVariant           = $role === 'supervisor' ? 'supervisor-dashboard' : 'default';
$topbarBreadcrumbCurrent = 'Meetings';
require_once __DIR__ . '/includes/header.php';
?>
<!-- Flatpickr – custom date & time pickers -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<?php

/* ─── Helper: meeting card ───────────────────────────────────────── */
function render_meeting_card(array $m, string $role, array $status_meta): void {
    $st    = $m['status'];
    $smeta = $status_meta[$st] ?? ['Unknown','#64748b','bi-question','rgba(100,116,139,.1)'];
    $eff_date = ($st === 'rescheduled' && !empty($m['rescheduled_date'])) ? $m['rescheduled_date'] : $m['meeting_date'];
    $eff_time = ($st === 'rescheduled' && !empty($m['rescheduled_time'])) ? $m['rescheduled_time'] : $m['meeting_time'];
    $is_upcoming = in_array($st, ['approved','rescheduled']) && $eff_date >= date('Y-m-d');
    ?>
    <div class="meet-card meet-card--<?= e($st) ?>" id="meet-<?= (int)$m['id'] ?>">
        <div class="meet-card__accent" style="background:<?= $smeta[1] ?>;"></div>
        <div class="meet-card__body">
            <div class="meet-card__top">
                <div class="meet-card__icon" style="background:<?= $smeta[3] ?>;color:<?= $smeta[1] ?>;">
                    <i class="bi <?= $smeta[2] ?>"></i>
                </div>
                <div class="meet-card__info">
                    <h4 class="meet-card__title"><?= e($m['title']) ?></h4>
                    <div class="meet-card__sub">
                        <?php if ($role === 'student'): ?>
                            <i class="bi bi-person-badge me-1"></i><?= e($m['supervisor_name'] ?? 'Supervisor') ?>
                        <?php else: ?>
                            <i class="bi bi-person me-1"></i><?= e($m['requester_name'] ?? 'Student') ?>
                            <?php if (!empty($m['group_name'])): ?>
                                <span class="mx-1">·</span><i class="bi bi-people me-1"></i><?= e($m['group_name']) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ms-auto d-flex flex-column align-items-end gap-1">
                    <span class="meet-status-badge" style="background:<?= $smeta[3] ?>;color:<?= $smeta[1] ?>;border-color:<?= $smeta[1] ?>40;">
                        <i class="bi <?= $smeta[2] ?>"></i> <?= $smeta[0] ?>
                    </span>
                    <?php if ($is_upcoming): ?>
                        <span class="meet-countdown" id="cd-<?= (int)$m['id'] ?>"
                              data-date="<?= e($eff_date) ?>" data-time="<?= e($eff_time) ?>"></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="meet-card__details">
                <div class="meet-detail-grid">
                    <div class="meet-detail-item">
                        <i class="bi bi-calendar3"></i>
                        <div>
                            <span class="meet-detail-label">Date</span>
                            <span class="meet-detail-val"><?= date('d-m-y', strtotime($eff_date)) ?></span>
                            <?php if ($st === 'rescheduled' && !empty($m['rescheduled_date'])): ?>
                                <small class="text-muted d-block" style="font-size:.68rem;">Originally <?= date('d-m-y', strtotime($m['meeting_date'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="meet-detail-item">
                        <i class="bi bi-clock"></i>
                        <div>
                            <span class="meet-detail-label">Time</span>
                            <span class="meet-detail-val"><?= date('g:i A', strtotime($eff_time)) ?></span>
                        </div>
                    </div>
                    <div class="meet-detail-item">
                        <i class="bi bi-<?= $m['meeting_type'] === 'online' ? 'camera-video' : 'geo-alt' ?>"></i>
                        <div>
                            <span class="meet-detail-label"><?= $m['meeting_type'] === 'online' ? 'Online' : 'Physical' ?></span>
                            <span class="meet-detail-val">
                                <?php if ($m['meeting_type'] === 'online' && !empty($m['meeting_link'])): ?>
                                    <a href="<?= e($m['meeting_link']) ?>" target="_blank" rel="noopener" class="meet-link-btn">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Join
                                    </a>
                                <?php elseif (!empty($m['venue'])): ?>
                                    <?= e($m['venue']) ?>
                                <?php else: ?>
                                    <span class="text-muted">TBD</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="meet-detail-item">
                        <i class="bi bi-journal-text"></i>
                        <div>
                            <span class="meet-detail-label">Project</span>
                            <span class="meet-detail-val" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= e(mb_substr($m['project_title'] ?? '—', 0, 30)) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="meet-card__purpose">
                    <span class="meet-detail-label">Purpose</span>
                    <p class="mb-0"><?= nl2br(e($m['purpose'])) ?></p>
                </div>
                <?php if (!empty($m['notes'])): ?>
                    <div class="meet-card__notes"><i class="bi bi-sticky me-1"></i><?= nl2br(e($m['notes'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($m['response_notes'])): ?>
                    <div class="meet-card__response">
                        <i class="bi bi-chat-quote me-1"></i><strong>Supervisor note:</strong> <?= nl2br(e($m['response_notes'])) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($m['attachment_path'])): ?>
                    <a href="<?= e(base_url($m['attachment_path'])) ?>" target="_blank" class="meet-attach-btn">
                        <i class="bi bi-paperclip"></i><?= e($m['attachment_name'] ?: 'Attachment') ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <?php if ($role === 'supervisor' && $st === 'pending'): ?>
            <div class="meet-card__actions" id="actions-<?= (int)$m['id'] ?>">
                <button class="btn-meet-approve" onclick="openAction(<?= (int)$m['id'] ?>,'approve')">
                    <i class="bi bi-check-lg"></i> Approve
                </button>
                <button class="btn-meet-reschedule" onclick="openAction(<?= (int)$m['id'] ?>,'reschedule')">
                    <i class="bi bi-arrow-repeat"></i> Reschedule
                </button>
                <button class="btn-meet-reject" onclick="openAction(<?= (int)$m['id'] ?>,'reject')">
                    <i class="bi bi-x-lg"></i> Reject
                </button>
            </div>
            <?php elseif ($role === 'supervisor' && in_array($st,['approved','rescheduled']) && $eff_date <= date('Y-m-d')): ?>
            <div class="meet-card__actions">
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
                    <button class="btn-meet-complete"><i class="bi bi-patch-check"></i> Mark Complete</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<style>
/* ══ Meetings page ═════════════════════════════════════════════════ */
:root {
    --mt-bg:      rgba(10,18,35,.9);
    --mt-surface: rgba(15,25,45,.85);
    --mt-border:  rgba(30,60,100,.6);
    --mt-blue:    #3b82f6;
    --mt-green:   #22c55e;
    --mt-amber:   #f59e0b;
    --mt-red:     #ef4444;
    --mt-purple:  #8b5cf6;
}

.meet-page { padding:1.5rem 0; }

/* hero */
.meet-hero {
    background: linear-gradient(135deg,rgba(10,18,35,.97) 0%,rgba(15,30,60,.98) 100%);
    border:1px solid var(--mt-border); border-radius:16px;
    padding:1.8rem 2rem; margin-bottom:1.75rem; position:relative; overflow:hidden;
}
.meet-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background: radial-gradient(ellipse at 85% 0%,rgba(59,130,246,.1) 0%,transparent 55%),
                radial-gradient(ellipse at 5% 100%,rgba(34,197,94,.06) 0%,transparent 50%);
}
.meet-hero__title { font-size:1.5rem; font-weight:800; color:#f1f5f9; display:flex; align-items:center; gap:.6rem; }
.meet-hero__sub   { color:#475569; font-size:.83rem; margin:.3rem 0 0; }

/* stat cards */
.meet-stat {
    background:var(--mt-surface); border:1px solid var(--mt-border);
    border-radius:14px; padding:1.1rem 1.3rem; position:relative; overflow:hidden;
    transition:transform .18s,box-shadow .18s;
}
.meet-stat:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,0,0,.3); }
.meet-stat__val   { font-size:2rem; font-weight:800; color:#f1f5f9; line-height:1; }
.meet-stat__label { font-size:.71rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#475569; margin-top:.25rem; }
.meet-stat__icon  { position:absolute; right:1rem; top:50%; transform:translateY(-50%); font-size:2rem; opacity:.1; }

/* tabs */
.meet-tabs { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.meet-tab {
    padding:.4rem 1rem; border-radius:20px; font-size:.8rem; font-weight:600;
    border:1px solid var(--mt-border); background:transparent; color:#475569;
    cursor:pointer; transition:all .15s;
}
.meet-tab:hover  { background:rgba(59,130,246,.1); border-color:rgba(59,130,246,.3); color:#93c5fd; }
.meet-tab.active { background:rgba(59,130,246,.2); border-color:rgba(59,130,246,.5); color:#60a5fa; }
.meet-tab .badge { background:rgba(59,130,246,.25); color:#93c5fd; font-size:.65rem; padding:.1rem .4rem; border-radius:8px; margin-left:.3rem; }

/* meeting card */
.meet-card {
    background:var(--mt-surface); border:1px solid var(--mt-border);
    border-radius:14px; overflow:hidden; margin-bottom:1rem; position:relative;
    transition:transform .18s,box-shadow .18s;
}
.meet-card:hover { transform:translateY(-1px); box-shadow:0 6px 24px rgba(0,0,0,.35); }
.meet-card__accent { position:absolute; left:0; top:0; bottom:0; width:3px; }
.meet-card__body   { padding:1.1rem 1.2rem 1.1rem 1.4rem; }
.meet-card__top    { display:flex; align-items:flex-start; gap:.85rem; margin-bottom:.9rem; }
.meet-card__icon   { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.meet-card__info   { flex:1; min-width:0; }
.meet-card__title  { font-size:.95rem; font-weight:700; color:#f1f5f9; margin:0; }
.meet-card__sub    { font-size:.76rem; color:#64748b; margin:.2rem 0 0; }

.meet-status-badge {
    font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:6px;
    border:1px solid; display:inline-flex; align-items:center; gap:.3rem; white-space:nowrap;
}
.meet-countdown { font-size:.68rem; color:#94a3b8; font-family:monospace; text-align:right; }

/* detail grid */
.meet-detail-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.6rem .5rem; margin-bottom:.75rem; }
@media(min-width:640px){ .meet-detail-grid { grid-template-columns:repeat(4,1fr); } }
.meet-detail-item { display:flex; align-items:flex-start; gap:.45rem; font-size:.78rem; }
.meet-detail-item i { color:#475569; margin-top:.1rem; flex-shrink:0; }
.meet-detail-label { display:block; font-size:.65rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.06em; }
.meet-detail-val   { display:block; color:#cbd5e1; font-size:.79rem; font-weight:500; margin-top:.1rem; }
.meet-link-btn { color:#60a5fa; text-decoration:none; font-size:.76rem; font-weight:600; }
.meet-link-btn:hover { color:#93c5fd; }

.meet-card__purpose { background:rgba(10,18,35,.6); border:1px solid rgba(30,60,100,.4); border-radius:8px; padding:.65rem .85rem; margin-bottom:.55rem; font-size:.8rem; color:#94a3b8; }
.meet-card__purpose .meet-detail-label { margin-bottom:.25rem; }
.meet-card__notes   { font-size:.76rem; color:#64748b; background:rgba(245,158,11,.06); border:1px solid rgba(245,158,11,.15); border-radius:7px; padding:.5rem .75rem; margin-bottom:.5rem; }
.meet-card__response { font-size:.76rem; color:#86efac; background:rgba(34,197,94,.06); border:1px solid rgba(34,197,94,.15); border-radius:7px; padding:.5rem .75rem; margin-bottom:.5rem; }
.meet-attach-btn { display:inline-flex; align-items:center; gap:.3rem; font-size:.74rem; color:#93c5fd; text-decoration:none; background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.2); border-radius:6px; padding:.2rem .6rem; }
.meet-attach-btn:hover { background:rgba(59,130,246,.2); color:#bfdbfe; }

/* action buttons */
.meet-card__actions { display:flex; gap:.5rem; flex-wrap:wrap; padding-top:.75rem; border-top:1px solid rgba(30,60,100,.4); margin-top:.75rem; }
.btn-meet-approve    { background:rgba(34,197,94,.15);  color:#4ade80; border:1px solid rgba(34,197,94,.3); border-radius:8px; padding:.35rem .85rem; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:.35rem; }
.btn-meet-approve:hover    { background:rgba(34,197,94,.25); color:#86efac; }
.btn-meet-reject     { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.25); border-radius:8px; padding:.35rem .85rem; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:.35rem; }
.btn-meet-reject:hover     { background:rgba(239,68,68,.22); color:#fca5a5; }
.btn-meet-reschedule { background:rgba(59,130,246,.12); color:#93c5fd; border:1px solid rgba(59,130,246,.25); border-radius:8px; padding:.35rem .85rem; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:.35rem; }
.btn-meet-reschedule:hover { background:rgba(59,130,246,.22); color:#bfdbfe; }
.btn-meet-complete   { background:rgba(139,92,246,.12); color:#c4b5fd; border:1px solid rgba(139,92,246,.25); border-radius:8px; padding:.35rem .85rem; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:.35rem; }
.btn-meet-cancel     { background:rgba(100,116,139,.12);color:#94a3b8; border:1px solid rgba(100,116,139,.25); border-radius:8px; padding:.35rem .85rem; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:.35rem; }

/* empty state */
.meet-empty { text-align:center; padding:3.5rem 1rem; color:#334155; }
.meet-empty i { font-size:3rem; display:block; margin-bottom:.75rem; opacity:.3; }

/* ── Calendar ── */
.meet-cal-wrap { background:var(--mt-surface); border:1px solid var(--mt-border); border-radius:14px; overflow:hidden; }
.meet-cal-head { padding:.85rem 1.2rem; border-bottom:1px solid var(--mt-border);
    display:flex; align-items:center; justify-content:space-between; }
.meet-cal-title { font-size:.9rem; font-weight:700; color:#e2e8f0; }
.meet-cal-nav { background:transparent; border:1px solid var(--mt-border); color:#64748b;
    border-radius:6px; padding:.25rem .55rem; cursor:pointer; font-size:.85rem; text-decoration:none; transition:all .15s; }
.meet-cal-nav:hover { border-color:rgba(59,130,246,.4); color:#93c5fd; }

.meet-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); }
.meet-cal-dow { text-align:center; font-size:.65rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
    color:#334155; padding:.5rem 0; border-bottom:1px solid rgba(30,60,100,.4); }
.meet-cal-day { text-align:center; padding:.5rem .2rem; border-bottom:1px solid rgba(30,60,100,.25);
    border-right:1px solid rgba(30,60,100,.25); min-height:52px; position:relative;
    font-size:.78rem; color:#475569; cursor:default; transition:background .15s; }
.meet-cal-day:nth-child(7n) { border-right:none; }
.meet-cal-day:nth-last-child(-n+7) { border-bottom:none; }
.meet-cal-day--today { background:rgba(59,130,246,.08); color:#93c5fd; font-weight:700; }
.meet-cal-day--has-meet:hover { background:rgba(59,130,246,.06); }
.meet-cal-day__num { display:block; font-size:.78rem; margin-bottom:.2rem; }
.meet-cal-dots { display:flex; gap:2px; justify-content:center; flex-wrap:wrap; margin-top:2px; }
.meet-cal-dot  { width:5px; height:5px; border-radius:50%; }
.cal-dot--pending     { background:#f59e0b; }
.cal-dot--approved    { background:#22c55e; }
.cal-dot--rescheduled { background:#3b82f6; }
.cal-dot--completed   { background:#8b5cf6; }
.cal-dot--rejected    { background:#ef4444; }
.cal-dot--cancelled   { background:#475569; }

/* ── Action modal ── */
.meet-modal .modal-content {
    background:rgba(10,18,35,.97); backdrop-filter:blur(20px);
    border:1px solid rgba(59,130,246,.3); border-radius:16px; color:#e2e8f0;
}
.meet-modal .modal-header { border-bottom:1px solid rgba(30,60,100,.5); padding:1.1rem 1.4rem; }
.meet-modal .modal-footer { border-top:1px solid rgba(30,60,100,.5); padding:.9rem 1.4rem; }
.meet-modal .form-label { color:#64748b; font-size:.78rem; font-weight:600; }
.meet-modal .form-control, .meet-modal .form-select {
    background:rgba(15,25,45,.9); border:1px solid rgba(30,60,100,.8);
    color:#e2e8f0; border-radius:8px;
}
.meet-modal .form-control:focus, .meet-modal .form-select:focus {
    background:rgba(15,25,45,.98); border-color:rgba(59,130,246,.5);
    box-shadow:0 0 0 3px rgba(59,130,246,.12); color:#f1f5f9;
}
.meet-modal .form-control::placeholder { color:#1e3a5f; }
.meet-modal textarea { resize:vertical; min-height:80px; }
.meet-modal .form-select option { background:#0d1b2e; }

/* book form panel */
.meet-form-panel {
    background:var(--mt-surface); border:1px solid var(--mt-border); border-radius:14px;
    padding:1.4rem; margin-bottom:1.5rem;
}
.meet-form-panel .form-label { color:#64748b; font-size:.79rem; font-weight:600; }
.meet-form-panel .form-control, .meet-form-panel .form-select {
    background:rgba(10,18,35,.8); border:1px solid rgba(30,60,100,.7);
    color:#e2e8f0; border-radius:9px;
}
.meet-form-panel .form-control:focus, .meet-form-panel .form-select:focus {
    background:rgba(10,18,35,.95); border-color:rgba(59,130,246,.45);
    box-shadow:0 0 0 3px rgba(59,130,246,.1); color:#f1f5f9;
}
.meet-form-panel .form-control::placeholder { color:#1e3a5f; }
.meet-form-panel .form-select option { background:#0a1223; }

/* layout */
.meet-layout { display:grid; grid-template-columns:1fr; gap:1.25rem; }
@media(min-width:1024px) { .meet-layout { grid-template-columns:1fr 300px; } }
.meet-main  { min-width:0; }
.meet-side  { min-width:0; }
</style>

<div class="meet-page px-3 px-md-4">

<?php if ($error): ?><div class="alert alert-danger rounded-3 mb-3"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success rounded-3 mb-3"><?= e($success) ?></div><?php endif; ?>

<!-- ── Hero ── -->
<div class="meet-hero mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="meet-hero__title">
                <i class="bi bi-calendar2-week-fill" style="color:var(--mt-blue);"></i>
                Meetings
            </h1>
            <p class="meet-hero__sub">
                <?= $role === 'student'
                    ? 'Schedule meetings with your supervisor, track approvals, and stay organised.'
                    : 'Review meeting requests from your students and manage your schedule.' ?>
            </p>
        </div>
        <?php if ($role === 'student' && $student_project && !empty($student_project['supervisor_id'])): ?>
            <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#bookForm" style="border-radius:10px;">
                <i class="bi bi-plus-lg"></i> Book a Meeting
            </button>
        <?php elseif ($role === 'supervisor' && !empty($supervisor_projects)): ?>
            <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#bookFormSupervisor" style="border-radius:10px;">
                <i class="bi bi-plus-lg"></i> Schedule a Meeting
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Stats ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="meet-stat">
            <div class="meet-stat__val"><?= $stats['total'] ?></div>
            <div class="meet-stat__label">Total</div>
            <i class="bi bi-calendar2 meet-stat__icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="meet-stat" style="border-color:rgba(245,158,11,.3);">
            <div class="meet-stat__val" style="color:#fcd34d;"><?= $stats['pending'] ?></div>
            <div class="meet-stat__label">Pending</div>
            <i class="bi bi-hourglass-split meet-stat__icon" style="color:#f59e0b;opacity:.2;"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="meet-stat" style="border-color:rgba(34,197,94,.25);">
            <div class="meet-stat__val" style="color:#4ade80;"><?= $stats['upcoming'] ?></div>
            <div class="meet-stat__label">Upcoming</div>
            <i class="bi bi-check-circle meet-stat__icon" style="color:#22c55e;opacity:.2;"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="meet-stat" style="border-color:rgba(139,92,246,.25);">
            <div class="meet-stat__val" style="color:#c4b5fd;"><?= $stats['completed'] ?></div>
            <div class="meet-stat__label">Completed</div>
            <i class="bi bi-patch-check meet-stat__icon" style="color:#8b5cf6;opacity:.2;"></i>
        </div>
    </div>
</div>

<!-- ── Book form (student, collapsible) ── -->
<?php if ($role === 'student' && $student_project && !empty($student_project['supervisor_id'])): ?>
<div class="collapse mb-4" id="bookForm">
    <div class="meet-form-panel">
        <h5 class="mb-3" style="color:#e2e8f0;font-size:.95rem;font-weight:700;">
            <i class="bi bi-calendar-plus me-2" style="color:var(--mt-blue);"></i>Request a New Meeting
        </h5>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Meeting Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Chapter 2 Review" required maxlength="255">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Meeting Type</label>
                    <select name="meeting_type" class="form-select" id="meet-type-sel">
                        <option value="physical">Physical / In-Person</option>
                        <option value="online">Online / Virtual</option>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="text" name="meeting_date" class="form-control fp-date" placeholder="DD-MM-YY" autocomplete="off" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Time <span class="text-danger">*</span></label>
                    <input type="text" name="meeting_time" class="form-control fp-time" placeholder="HH:MM" autocomplete="off" required>
                </div>
                <div class="col-12" id="venue-wrap">
                    <label class="form-label">Venue / Location</label>
                    <input type="text" name="venue" class="form-control" placeholder="Office room, building…">
                </div>
                <div class="col-12 d-none" id="link-wrap">
                    <label class="form-label">Meeting Link</label>
                    <input type="url" name="meeting_link" class="form-control" placeholder="https://meet.google.com/…">
                </div>
                <div class="col-12">
                    <label class="form-label">Purpose / Agenda <span class="text-danger">*</span></label>
                    <textarea name="purpose" class="form-control" rows="3" placeholder="What do you need to discuss?" required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Additional Notes <small class="opacity-60">(optional)</small></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Any extra information…"></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Attachment <small class="opacity-60">(optional, max 10 MB)</small></label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.zip">
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-center gap-2">
                        <div class="meet-detail-item me-2">
                            <i class="bi bi-person-badge" style="color:#64748b;"></i>
                            <div><span class="meet-detail-label">Supervisor</span><span class="meet-detail-val"><?= e($student_project['supervisor_name'] ?? 'Assigned') ?></span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Send Request</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#bookForm">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php elseif ($role === 'student' && (!$student_project || empty($student_project['supervisor_id']))): ?>
<div class="alert rounded-3 mb-4" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:#fcd34d;">
    <i class="bi bi-exclamation-triangle me-2"></i>No supervisor is assigned to your project yet. You can book meetings once a supervisor is assigned.
</div>
<?php endif; ?>

<?php if ($role === 'supervisor'): ?>
<?php if (!empty($supervisor_projects)): ?>
<div class="collapse mb-4" id="bookFormSupervisor">
    <div class="meet-form-panel">
        <h5 class="mb-3" style="color:#e2e8f0;font-size:.95rem;font-weight:700;">
            <i class="bi bi-calendar-plus me-2" style="color:var(--mt-blue);"></i>Schedule a New Meeting
        </h5>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Project / Student <span class="text-danger">*</span></label>
                    <select name="project_id" class="form-select" required>
                        <option value="">— Select a project —</option>
                        <?php foreach ($supervisor_projects as $sp): ?>
                        <option value="<?= (int)$sp['id'] ?>">
                            <?= e($sp['group_name'] ?: $sp['student_name']) ?> — <?= e(mb_substr($sp['title'], 0, 45)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Meeting Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Chapter 2 Review" required maxlength="255">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Meeting Type</label>
                    <select name="meeting_type" class="form-select" id="sv-meet-type-sel">
                        <option value="physical">Physical / In-Person</option>
                        <option value="online">Online / Virtual</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="text" name="meeting_date" class="form-control fp-date" placeholder="DD-MM-YY" autocomplete="off" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Time <span class="text-danger">*</span></label>
                    <input type="text" name="meeting_time" class="form-control fp-time" placeholder="HH:MM" autocomplete="off" required>
                </div>
                <div class="col-12" id="sv-venue-wrap">
                    <label class="form-label">Venue / Location</label>
                    <input type="text" name="venue" class="form-control" placeholder="Office room, building…">
                </div>
                <div class="col-12 d-none" id="sv-link-wrap">
                    <label class="form-label">Meeting Link</label>
                    <input type="url" name="meeting_link" class="form-control" placeholder="https://meet.google.com/…">
                </div>
                <div class="col-12">
                    <label class="form-label">Purpose / Agenda <span class="text-danger">*</span></label>
                    <textarea name="purpose" class="form-control" rows="3" placeholder="What will be discussed?" required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Additional Notes <small class="opacity-60">(optional)</small></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Any extra information…"></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Schedule Meeting</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#bookFormSupervisor">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert rounded-3 mb-4" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:#fcd34d;">
    <i class="bi bi-exclamation-triangle me-2"></i>No active projects are assigned to you yet.
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Main layout ── -->
<div class="meet-layout">
    <div class="meet-main">

        <!-- Tabs -->
        <div class="meet-tabs" id="meet-tabs">
            <button class="meet-tab active" data-tab="upcoming">
                Upcoming <span class="badge"><?= $stats['upcoming'] ?></span>
            </button>
            <?php if ($role === 'supervisor'): ?>
            <button class="meet-tab" data-tab="pending">
                Pending Requests <span class="badge"><?= $stats['pending'] ?></span>
            </button>
            <?php endif; ?>
            <button class="meet-tab" data-tab="all">All Meetings <span class="badge"><?= $stats['total'] ?></span></button>
            <button class="meet-tab" data-tab="history">History</button>
        </div>

        <!-- Upcoming tab -->
        <div class="meet-tab-pane" id="tab-upcoming">
            <?php if (empty($upcoming_meetings)): ?>
                <div class="meet-empty"><i class="bi bi-calendar2-x"></i>No upcoming meetings.</div>
            <?php else: ?>
                <?php foreach ($upcoming_meetings as $m) render_meeting_card($m, $role, $status_meta); ?>
            <?php endif; ?>
        </div>

        <!-- Pending tab (supervisor) -->
        <?php if ($role === 'supervisor'): ?>
        <div class="meet-tab-pane d-none" id="tab-pending">
            <?php if (empty($pending_meetings)): ?>
                <div class="meet-empty"><i class="bi bi-inbox"></i>No pending requests.</div>
            <?php else: ?>
                <?php foreach ($pending_meetings as $m) render_meeting_card($m, $role, $status_meta); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- All tab -->
        <div class="meet-tab-pane d-none" id="tab-all">
            <?php if (empty($all_meetings)): ?>
                <div class="meet-empty"><i class="bi bi-calendar2"></i>No meetings yet.</div>
            <?php else: ?>
                <?php foreach ($all_meetings as $m) render_meeting_card($m, $role, $status_meta); ?>
            <?php endif; ?>
        </div>

        <!-- History tab -->
        <div class="meet-tab-pane d-none" id="tab-history">
            <?php $hist = array_filter($all_meetings, fn($m) => in_array($m['status'],['completed','cancelled','rejected'])); ?>
            <?php if (empty($hist)): ?>
                <div class="meet-empty"><i class="bi bi-clock-history"></i>No completed meetings yet.</div>
            <?php else: ?>
                <?php foreach ($hist as $m) render_meeting_card($m, $role, $status_meta); ?>
            <?php endif; ?>
        </div>

    </div><!-- /meet-main -->

    <!-- ── Sidebar: Calendar ── -->
    <div class="meet-side">
        <div class="meet-cal-wrap mb-3">
            <div class="meet-cal-head">
                <a href="?cm=<?= $cal_month-1 ?>&cy=<?= $cal_year ?>" class="meet-cal-nav"><i class="bi bi-chevron-left"></i></a>
                <span class="meet-cal-title"><?= date('F Y', mktime(0,0,0,$cal_month,1,$cal_year)) ?></span>
                <a href="?cm=<?= $cal_month+1 ?>&cy=<?= $cal_year ?>" class="meet-cal-nav"><i class="bi bi-chevron-right"></i></a>
            </div>
            <div class="meet-cal-grid">
                <?php foreach(['S','M','T','W','T','F','S'] as $d): ?>
                    <div class="meet-cal-dow"><?= $d ?></div>
                <?php endforeach; ?>
                <?php
                $first_dow = (int)date('w', mktime(0,0,0,$cal_month,1,$cal_year));
                $days_in   = (int)date('t', mktime(0,0,0,$cal_month,1,$cal_year));
                $today_day = (date('Y') == $cal_year && date('n') == $cal_month) ? (int)date('j') : 0;
                for ($i = 0; $i < $first_dow; $i++) echo '<div class="meet-cal-day"></div>';
                for ($d = 1; $d <= $days_in; $d++):
                    $is_today = ($d === $today_day);
                    $has_meet = isset($cal_days[$d]);
                ?>
                    <div class="meet-cal-day <?= $is_today ? 'meet-cal-day--today' : '' ?> <?= $has_meet ? 'meet-cal-day--has-meet' : '' ?>">
                        <span class="meet-cal-day__num"><?= $d ?></span>
                        <?php if ($has_meet): ?>
                        <div class="meet-cal-dots">
                            <?php foreach(array_unique($cal_days[$d]) as $cst): ?>
                                <span class="meet-cal-dot cal-dot--<?= e($cst) ?>" title="<?= e(ucfirst($cst)) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="meet-cal-wrap px-3 py-2 mb-3" style="border-radius:12px;">
            <div class="mb-1" style="font-size:.68rem;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.08em;">Legend</div>
            <?php foreach($status_meta as $sk => $sv): ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="meet-cal-dot cal-dot--<?= $sk ?>" style="width:8px;height:8px;flex-shrink:0;"></span>
                <span style="font-size:.75rem;color:#64748b;"><?= $sv[0] ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Quick upcoming list -->
        <?php $quick = array_slice(array_values($upcoming_meetings), 0, 4); ?>
        <?php if (!empty($quick)): ?>
        <div class="meet-cal-wrap overflow-hidden" style="border-radius:12px;">
            <div style="padding:.7rem 1rem;border-bottom:1px solid var(--mt-border);font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.08em;">
                Next Up
            </div>
            <?php foreach($quick as $q):
                $eff_d = ($q['status']==='rescheduled'&&$q['rescheduled_date']) ? $q['rescheduled_date'] : $q['meeting_date'];
                $eff_t = ($q['status']==='rescheduled'&&$q['rescheduled_time']) ? $q['rescheduled_time'] : $q['meeting_time'];
            ?>
            <a href="#meet-<?= (int)$q['id'] ?>" class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none" style="border-bottom:1px solid rgba(30,60,100,.3);transition:background .15s;" onmouseover="this.style.background='rgba(59,130,246,.06)'" onmouseout="this.style.background=''">
                <div style="width:6px;height:6px;border-radius:50%;background:<?= $status_meta[$q['status']][1] ?? '#64748b' ?>;flex-shrink:0;"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.77rem;font-weight:600;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($q['title']) ?></div>
                    <div style="font-size:.68rem;color:#475569;"><?= date('d-m-y', strtotime($eff_d)) ?> · <?= date('g:i A', strtotime($eff_t)) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /meet-page -->

<!-- ── Supervisor action modal ────────────────────────────────────── -->
<?php if ($role === 'supervisor'): ?>
<div class="modal fade meet-modal" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="actionModalTitle"><i class="bi bi-calendar-check me-2" style="color:var(--mt-blue);"></i>Respond to Meeting</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="actionForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="actionField">
                <input type="hidden" name="mid"    id="midField">
                <div class="modal-body px-4 py-3">
                    <div id="approve-fields">
                        <div class="row g-3">
                            <div class="col-12" id="venue-field-wrap">
                                <label class="form-label">Venue / Location</label>
                                <input type="text" name="venue" class="form-control" placeholder="Room, office, building…">
                            </div>
                            <div class="col-12" id="link-field-wrap">
                                <label class="form-label">Meeting Link <small class="opacity-60">(if online)</small></label>
                                <input type="url" name="meeting_link" class="form-control" placeholder="https://meet.google.com/…">
                            </div>
                        </div>
                    </div>
                    <div id="reschedule-fields" class="d-none row g-3">
                        <div class="col-6">
                            <label class="form-label">New Date <span class="text-danger">*</span></label>
                            <input type="text" name="rescheduled_date" class="form-control fp-date" id="reschedDateInp" placeholder="DD-MM-YY" autocomplete="off">
                        </div>
                        <div class="col-6">
                            <label class="form-label">New Time <span class="text-danger">*</span></label>
                            <input type="text" name="rescheduled_time" class="form-control fp-time" id="reschedTimeInp" placeholder="HH:MM" autocomplete="off">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" id="resp-label">Notes for student <small class="opacity-60">(optional)</small></label>
                        <textarea name="response_notes" class="form-control" rows="3" placeholder="Any message for the student…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="actionSubmitBtn">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Tab switching ── */
document.querySelectorAll('.meet-tab').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.meet-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.meet-tab-pane').forEach(p => p.classList.add('d-none'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab)?.classList.remove('d-none');
    });
});

/* ── Book form: toggle venue/link ── */
const meetTypeSel = document.getElementById('meet-type-sel');
const venueWrap   = document.getElementById('venue-wrap');
const linkWrap    = document.getElementById('link-wrap');
meetTypeSel?.addEventListener('change', function() {
    const online = this.value === 'online';
    venueWrap?.classList.toggle('d-none', online);
    linkWrap?.classList.toggle('d-none', !online);
});

/* ── Supervisor book form: toggle venue/link ── */
const svTypeSel   = document.getElementById('sv-meet-type-sel');
const svVenueWrap = document.getElementById('sv-venue-wrap');
const svLinkWrap  = document.getElementById('sv-link-wrap');
svTypeSel?.addEventListener('change', function() {
    const online = this.value === 'online';
    svVenueWrap?.classList.toggle('d-none', online);
    svLinkWrap?.classList.toggle('d-none', !online);
});

/* ── Flatpickr: custom date & time pickers ── */
const fpDateCfg = {
    dateFormat: 'Y-m-d',        /* value submitted to server */
    altInput: true,
    altFormat: 'd-m-y',         /* display format shown to user */
    minDate: 'today',
    disableMobile: false,
    theme: 'dark',
};
const fpTimeCfg = {
    enableTime: true,
    noCalendar: true,
    dateFormat: 'H:i',
    time_24hr: false,
    minuteIncrement: 5,
    disableMobile: false,
};
/* init pickers on form inputs (outside modal) */
document.querySelectorAll('.fp-date:not(#reschedDateInp)').forEach(el => flatpickr(el, fpDateCfg));
document.querySelectorAll('.fp-time:not(#reschedTimeInp)').forEach(el => flatpickr(el, fpTimeCfg));

/* modal reschedule pickers need appendTo:body so they appear above the backdrop */
const fpModalDateCfg = Object.assign({}, fpDateCfg, { appendTo: document.body });
const fpModalTimeCfg = Object.assign({}, fpTimeCfg, { appendTo: document.body });
const reschedDateEl = document.getElementById('reschedDateInp');
const reschedTimeEl = document.getElementById('reschedTimeInp');
if (reschedDateEl) flatpickr(reschedDateEl, fpModalDateCfg);
if (reschedTimeEl) flatpickr(reschedTimeEl, fpModalTimeCfg);

/* ── Supervisor: open action modal ── */
<?php if ($role === 'supervisor'): ?>
function openAction(mid, action) {
    const modal     = document.getElementById('actionModal');
    const af        = document.getElementById('actionField');
    const mf        = document.getElementById('midField');
    const title     = document.getElementById('actionModalTitle');
    const approveF  = document.getElementById('approve-fields');
    const reschedF  = document.getElementById('reschedule-fields');
    const submitBtn = document.getElementById('actionSubmitBtn');
    const respLabel = document.getElementById('resp-label');
    af.value = action; mf.value = mid;

    if (reschedDateEl?._flatpickr) reschedDateEl._flatpickr.set('minDate', 'today');
    if (reschedTimeEl?._flatpickr) reschedTimeEl._flatpickr.clear();

    approveF.classList.add('d-none');
    reschedF.classList.add('d-none');

    if (action === 'approve') {
        title.innerHTML = '<i class="bi bi-check-circle-fill me-2" style="color:#22c55e;"></i>Approve Meeting';
        approveF.classList.remove('d-none');
        submitBtn.className = 'btn btn-success';
        submitBtn.textContent = 'Approve';
        respLabel.textContent = 'Note for student (optional)';
    } else if (action === 'reschedule') {
        title.innerHTML = '<i class="bi bi-arrow-repeat me-2" style="color:#3b82f6;"></i>Reschedule Meeting';
        reschedF.classList.remove('d-none');
        submitBtn.className = 'btn btn-primary';
        submitBtn.textContent = 'Reschedule';
        respLabel.textContent = 'Reason for rescheduling (optional)';
    } else if (action === 'reject') {
        title.innerHTML = '<i class="bi bi-x-circle-fill me-2" style="color:#ef4444;"></i>Reject Meeting';
        submitBtn.className = 'btn btn-danger';
        submitBtn.textContent = 'Reject';
        respLabel.textContent = 'Reason for rejection (optional)';
    }

    new bootstrap.Modal(modal).show();
}
<?php endif; ?>

/* ── Countdown timers ── */
function updateCountdowns() {
    document.querySelectorAll('[data-date][data-time]').forEach(el => {
        const dt   = new Date(el.dataset.date + 'T' + el.dataset.time);
        const diff = dt - Date.now();
        if (diff <= 0) { el.textContent = 'Now / Overdue'; return; }
        const d = Math.floor(diff/86400000);
        const h = Math.floor((diff%86400000)/3600000);
        const m = Math.floor((diff%3600000)/60000);
        el.textContent = d > 0 ? `in ${d}d ${h}h` : h > 0 ? `in ${h}h ${m}m` : `in ${m}m`;
    });
}
updateCountdowns();
setInterval(updateCountdowns, 60000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
