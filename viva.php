<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';
require_login();

$user = current_user();
$role = $user['role'];
$uid  = (int)$user['id'];
$pdo  = getPDO();

if (!in_array($role, ['student', 'supervisor'], true)) {
    flash('error', 'The Viva Preparation Hub is for students and supervisors.');
    redirect(base_url('dashboard.php'));
}

ensure_viva_tables($pdo);

/* ── Upload dirs ─────────────────────────────────────────────────── */
$viva_base = __DIR__ . '/uploads/viva';
if (!is_dir($viva_base)) mkdir($viva_base, 0775, true);

/* ── Resolve project ─────────────────────────────────────────────── */
$project      = null;
$is_owner     = false;
$viewed_pid   = 0;

if ($role === 'student') {
    $gq = $pdo->prepare('SELECT gm.group_id FROM group_members gm JOIN `groups` g ON g.id=gm.group_id WHERE gm.student_id=? AND g.is_active=1 LIMIT 1');
    $gq->execute([$uid]);
    $gid = (int)($gq->fetchColumn() ?: 0);
    if ($gid) {
        $pq = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name, u.email AS supervisor_email FROM projects p LEFT JOIN users u ON u.id=p.supervisor_id WHERE p.group_id=? ORDER BY p.updated_at DESC LIMIT 1');
        $pq->execute([$gid]);
    } else {
        $pq = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name, u.email AS supervisor_email FROM projects p LEFT JOIN users u ON u.id=p.supervisor_id WHERE p.student_id=? ORDER BY p.updated_at DESC LIMIT 1');
        $pq->execute([$uid]);
    }
    $project  = $pq->fetch() ?: null;
    $is_owner = true;
} elseif ($role === 'supervisor') {
    $viewed_pid = (int)($_GET['pid'] ?? 0);
    if ($viewed_pid) {
        $pq = $pdo->prepare(
            'SELECT p.*, u.full_name AS student_name FROM projects p
             JOIN users u ON u.id=p.student_id
             WHERE p.id=? AND p.supervisor_id=? LIMIT 1'
        );
        $pq->execute([$viewed_pid, $uid]);
        $project = $pq->fetch() ?: null;
    }
    if (!$project) {
        /* show list of assigned projects to pick from */
        $sq = $pdo->prepare(
            'SELECT p.id, p.title, p.status, u.full_name AS student_name
             FROM projects p JOIN users u ON u.id=p.student_id
             WHERE p.supervisor_id=? AND p.status NOT IN ("archived","rejected")
             ORDER BY p.updated_at DESC'
        );
        $sq->execute([$uid]);
        $supervisor_projects = $sq->fetchAll();
    }
}

$no_project = ($project === null && $role === 'student');
$pid        = $project ? (int)$project['id'] : 0;

/* ── Checklist master items ──────────────────────────────────────── */
$checklist_items = [
    'slides_completed'   => ['Presentation Slides Completed',   'bi-file-earmark-slides'],
    'report_finalized'   => ['Final Report Submitted',          'bi-file-earmark-text'],
    'demo_prepared'      => ['System Demo Prepared',            'bi-display'],
    'rehearsal_done'     => ['Full Rehearsal Completed',        'bi-mic'],
    'materials_uploaded' => ['All Materials Uploaded',          'bi-cloud-upload'],
    'supervisor_approved'=> ['Supervisor Approval Received',    'bi-patch-check'],
];

/* ── POST handler ────────────────────────────────────────────────── */
$err = $suc = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $err = 'Security check failed.'; goto render; }
    $action = trim($_POST['action'] ?? '');

    /* ── Upload material ── */
    if ($action === 'upload_material' && $role === 'student' && $pid) {
        $ftype = $_POST['file_type'] ?? 'other';
        $allowed_types = ['slides','pdf','poster','video','screenshot','other'];
        if (!in_array($ftype, $allowed_types, true)) $ftype = 'other';

        if (empty($_FILES['material_file']['name'])) {
            $err = 'No file selected.'; goto render;
        }
        $f       = $_FILES['material_file'];
        $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['pptx','ppt','key','pdf','png','jpg','jpeg','gif','webp','mp4','webm','mov','avi'];
        if (!in_array($ext, $allowed, true)) { $err = 'File type not allowed.'; goto render; }
        $max_bytes = ($ftype === 'video') ? 150 * 1024 * 1024 : 50 * 1024 * 1024;
        if ($f['size'] > $max_bytes) { $err = 'File too large.'; goto render; }

        $mat_dir = "$viva_base/{$pid}/materials";
        if (!is_dir($mat_dir)) mkdir($mat_dir, 0775, true);
        $fname = uniqid('mat_', true) . '.' . $ext;
        $fpath = "$mat_dir/$fname";
        if (!move_uploaded_file($f['tmp_name'], $fpath)) { $err = 'Upload failed.'; goto render; }

        $rel = "uploads/viva/{$pid}/materials/$fname";
        $stmt = $pdo->prepare('INSERT INTO viva_materials (project_id,student_id,file_name,file_path,file_type,file_size) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$pid, $uid, $f['name'], $rel, $ftype, $f['size']]);
        $suc = 'Material uploaded successfully.';
        goto render;
    }

    /* ── Delete material ── */
    if ($action === 'delete_material' && $role === 'student' && $pid) {
        $mid = (int)($_POST['material_id'] ?? 0);
        $mq  = $pdo->prepare('SELECT file_path FROM viva_materials WHERE id=? AND student_id=? AND project_id=?');
        $mq->execute([$mid, $uid, $pid]);
        $mat = $mq->fetch();
        if ($mat) {
            $full = __DIR__ . '/' . $mat['file_path'];
            if (is_file($full)) unlink($full);
            $pdo->prepare('DELETE FROM viva_materials WHERE id=?')->execute([$mid]);
            $suc = 'Material removed.';
        }
        goto render;
    }

    /* ── Toggle checklist item ── */
    if ($action === 'toggle_checklist' && $pid) {
        $key    = $_POST['item_key'] ?? '';
        $chk    = (int)(($_POST['is_checked'] ?? '0') === '1');
        $sid_cl = $role === 'student' ? $uid : (int)($_POST['student_id'] ?? 0);
        if ($sid_cl && array_key_exists($key, $checklist_items)) {
            $pdo->prepare(
                'INSERT INTO viva_checklist (project_id,student_id,item_key,is_checked,checked_at)
                 VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_checked=?, checked_at=?'
            )->execute([$pid, $sid_cl, $key, $chk, $chk ? date('Y-m-d H:i:s') : null, $chk, $chk ? date('Y-m-d H:i:s') : null]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ── Save practice recording ── */
    if ($action === 'save_recording' && $role === 'student' && $pid) {
        if (empty($_FILES['recording']['name'])) { $err = 'No recording received.'; goto render; }
        $rf      = $_FILES['recording'];
        $ext     = strtolower(pathinfo($rf['name'], PATHINFO_EXTENSION));
        $allowed = ['webm','ogg','mp3','mp4','wav'];
        if (!in_array($ext, $allowed, true)) $ext = 'webm';
        $dur     = max(0, (int)($_POST['duration'] ?? 0));
        $label   = mb_substr(trim($_POST['session_label'] ?? 'Practice Session'), 0, 200);
        $mime    = in_array($rf['type'], ['audio/webm','audio/ogg','audio/mp4','video/webm'], true) ? $rf['type'] : 'audio/webm';

        $rec_dir = "$viva_base/{$pid}/recordings";
        if (!is_dir($rec_dir)) mkdir($rec_dir, 0775, true);
        $rname = uniqid('rec_', true) . '.' . $ext;
        $rpath = "$rec_dir/$rname";
        if (!move_uploaded_file($rf['tmp_name'], $rpath)) { $err = 'Failed to save recording.'; goto render; }

        $rel = "uploads/viva/{$pid}/recordings/$rname";
        $pdo->prepare('INSERT INTO viva_recordings (project_id,student_id,file_path,file_name,duration_seconds,session_label,mime_type) VALUES (?,?,?,?,?,?,?)')
            ->execute([$pid, $uid, $rel, $label, $dur, $label, $mime]);
        $suc = 'Recording saved.';
        goto render;
    }

    /* ── Delete recording ── */
    if ($action === 'delete_recording' && $role === 'student' && $pid) {
        $rid = (int)($_POST['recording_id'] ?? 0);
        $rq  = $pdo->prepare('SELECT file_path FROM viva_recordings WHERE id=? AND student_id=? AND project_id=?');
        $rq->execute([$rid, $uid, $pid]);
        $rec = $rq->fetch();
        if ($rec) {
            $full = __DIR__ . '/' . $rec['file_path'];
            if (is_file($full)) unlink($full);
            $pdo->prepare('DELETE FROM viva_recordings WHERE id=?')->execute([$rid]);
            $suc = 'Recording deleted.';
        }
        goto render;
    }

    /* ── Supervisor: leave feedback ── */
    if ($action === 'supervisor_feedback' && $role === 'supervisor') {
        $fpid    = (int)($_POST['project_id'] ?? 0);
        $content = trim($_POST['feedback_content'] ?? '');
        $approve = (int)(($_POST['mark_approved'] ?? '0') === '1');

        /* verify ownership */
        $vc = $pdo->prepare('SELECT id FROM projects WHERE id=? AND supervisor_id=?');
        $vc->execute([$fpid, $uid]);
        if (!$vc->fetch()) { $err = 'Access denied.'; goto render; }

        $att_path = $att_name = null;
        if (!empty($_FILES['feedback_file']['name'])) {
            $ff  = $_FILES['feedback_file'];
            $ext = strtolower(pathinfo($ff['name'], PATHINFO_EXTENSION));
            $ok  = ['pdf','doc','docx','txt','png','jpg','jpeg','pptx','ppt'];
            if (in_array($ext, $ok, true) && $ff['size'] <= 20 * 1024 * 1024) {
                $fb_dir = "$viva_base/{$fpid}/feedback";
                if (!is_dir($fb_dir)) mkdir($fb_dir, 0775, true);
                $fn = uniqid('fb_', true) . '.' . $ext;
                move_uploaded_file($ff['tmp_name'], "$fb_dir/$fn");
                $att_path = "uploads/viva/{$fpid}/feedback/$fn";
                $att_name = $ff['name'];
            }
        }

        /* voice feedback */
        $vp = null;
        if (!empty($_FILES['feedback_voice']['name'])) {
            $vf  = $_FILES['feedback_voice'];
            $ext = strtolower(pathinfo($vf['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['webm','ogg','mp3','wav'], true) && $vf['size'] <= 20 * 1024 * 1024) {
                $fb_dir = "$viva_base/{$fpid}/feedback";
                if (!is_dir($fb_dir)) mkdir($fb_dir, 0775, true);
                $vn = uniqid('vfb_', true) . '.' . $ext;
                move_uploaded_file($vf['tmp_name'], "$fb_dir/$vn");
                $vp = "uploads/viva/{$fpid}/feedback/$vn";
            }
        }

        /* upsert feedback row per supervisor+project */
        $existing = $pdo->prepare('SELECT id FROM viva_feedback WHERE project_id=? AND supervisor_id=?');
        $existing->execute([$fpid, $uid]);
        $fid = $existing->fetchColumn();

        if ($fid) {
            $upd = 'UPDATE viva_feedback SET content=?, is_approved=?, approved_at=?';
            $params = [$content, $approve, $approve ? date('Y-m-d H:i:s') : null];
            if ($att_path) { $upd .= ', attachment_path=?, attachment_name=?'; $params[] = $att_path; $params[] = $att_name; }
            if ($vp)       { $upd .= ', voice_path=?'; $params[] = $vp; }
            $upd .= ', updated_at=NOW() WHERE id=?';
            $params[] = $fid;
            $pdo->prepare($upd)->execute($params);
        } else {
            $pdo->prepare('INSERT INTO viva_feedback (project_id,supervisor_id,content,attachment_path,attachment_name,voice_path,is_approved,approved_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$fpid, $uid, $content, $att_path, $att_name, $vp, $approve, $approve ? date('Y-m-d H:i:s') : null]);
        }

        if ($approve) {
            /* notify the student */
            $sn = $pdo->prepare('SELECT student_id FROM projects WHERE id=?');
            $sn->execute([$fpid]);
            $stu_id = (int)($sn->fetchColumn() ?: 0);
            if ($stu_id) {
                notify_user($pdo, $stu_id, 'Your supervisor has approved your presentation readiness!', base_url("viva.php"));
            }
        }
        $suc = 'Feedback saved successfully.';
        redirect(base_url("viva.php?pid={$fpid}"));
    }

    /* ── Set / update viva details ── */
    if ($action === 'set_viva_details' && $role === 'supervisor') {
        $fpid    = (int)($_POST['project_id'] ?? 0);
        $vtype   = in_array($_POST['viva_type'] ?? '', ['proposal','final'], true) ? $_POST['viva_type'] : 'final';
        $vdate   = trim($_POST['viva_date']  ?? '');
        $vtime   = trim($_POST['viva_time']  ?? '');
        $venue   = mb_substr(trim($_POST['venue'] ?? ''), 0, 500);
        $panel   = mb_substr(trim($_POST['panel_members'] ?? ''), 0, 2000);
        $notes   = mb_substr(trim($_POST['viva_notes']   ?? ''), 0, 2000);
        $conf    = (int)(($_POST['is_confirmed'] ?? '0') === '1');

        $vc = $pdo->prepare('SELECT id FROM projects WHERE id=? AND supervisor_id=?');
        $vc->execute([$fpid, $uid]);
        if (!$vc->fetch()) { $err = 'Access denied.'; goto render; }

        if ($vdate && strtotime($vdate) < strtotime(date('Y-m-d'))) {
            $err = 'Viva date cannot be in the past.'; goto render;
        }

        $pdo->prepare(
            'INSERT INTO viva_details (project_id,viva_type,viva_date,viva_time,venue,panel_members,notes,is_confirmed,set_by)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE viva_type=?,viva_date=?,viva_time=?,venue=?,panel_members=?,notes=?,is_confirmed=?,set_by=?,updated_at=NOW()'
        )->execute([
            $fpid, $vtype, $vdate ?: null, $vtime ?: null, $venue, $panel, $notes, $conf, $uid,
            $vtype, $vdate ?: null, $vtime ?: null, $venue, $panel, $notes, $conf, $uid,
        ]);

        $sn = $pdo->prepare('SELECT student_id FROM projects WHERE id=?');
        $sn->execute([$fpid]);
        $stu_id = (int)($sn->fetchColumn() ?: 0);
        if ($stu_id && $vdate) {
            notify_user($pdo, $stu_id, "Your viva has been scheduled for {$vdate}. Check the Viva Hub for details.", base_url("viva.php"));
        }
        $suc = 'Viva details updated.';
        redirect(base_url("viva.php?pid={$fpid}"));
    }
}

render:
/* ── Query data ──────────────────────────────────────────────────── */
$viva_detail   = [];
$materials     = [];
$recordings    = [];
$checklist_map = [];
$feedback_list = [];
$student_uid   = 0;

if ($pid) {
    $vd = $pdo->prepare('SELECT * FROM viva_details WHERE project_id=?');
    $vd->execute([$pid]);
    $viva_detail = $vd->fetch() ?: [];

    $mq = $pdo->prepare('SELECT * FROM viva_materials WHERE project_id=? ORDER BY uploaded_at DESC');
    $mq->execute([$pid]);
    $materials = $mq->fetchAll();

    $rq = $pdo->prepare('SELECT * FROM viva_recordings WHERE project_id=? ORDER BY recorded_at DESC');
    $rq->execute([$pid]);
    $recordings = $rq->fetchAll();

    $student_uid = $role === 'student' ? $uid : (int)($project['student_id'] ?? 0);

    $clq = $pdo->prepare('SELECT item_key, is_checked FROM viva_checklist WHERE project_id=? AND student_id=?');
    $clq->execute([$pid, $student_uid]);
    foreach ($clq->fetchAll() as $cl) $checklist_map[$cl['item_key']] = (bool)$cl['is_checked'];

    $fbq = $pdo->prepare('SELECT vf.*, u.full_name AS supervisor_name FROM viva_feedback vf JOIN users u ON u.id=vf.supervisor_id WHERE vf.project_id=? ORDER BY vf.updated_at DESC');
    $fbq->execute([$pid]);
    $feedback_list = $fbq->fetchAll();
}

/* ── Progress calculation ── */
$checked_count = count(array_filter($checklist_map));
$total_items   = count($checklist_items);
$progress_pct  = $total_items > 0 ? round(($checked_count / $total_items) * 100) : 0;
$is_approved   = array_reduce($feedback_list, fn($c, $f) => $c || (bool)$f['is_approved'], false);

/* ── Countdown data ── */
$countdown_ts   = 0;
$viva_date_str  = '';
$viva_time_str  = '';
if (!empty($viva_detail['viva_date'])) {
    $vt = $viva_detail['viva_time'] ?: '09:00:00';
    $countdown_ts  = strtotime($viva_detail['viva_date'] . ' ' . $vt);
    $viva_date_str = date('l, F j, Y', strtotime($viva_detail['viva_date']));
    $viva_time_str = date('g:i A', strtotime($vt));
}

/* ── Mock questions ── */
$mock_questions = [
    'Introduction' => [
        'Why did you choose this research topic?',
        'What is the significance or novelty of your project?',
        'Who are the intended users or beneficiaries of your system?',
        'How does your project contribute to the existing body of knowledge?',
    ],
    'Methodology' => [
        'What research methodology did you use and why?',
        'Why did you choose this methodology over alternatives?',
        'How did you validate your research approach?',
        'What data collection methods were employed?',
    ],
    'Technical' => [
        'What technologies and frameworks did you use, and why?',
        'How does your system architecture work?',
        'What are the key algorithms or logic in your implementation?',
        'How does your system handle security and edge cases?',
    ],
    'Challenges' => [
        'What were the major challenges you faced during development?',
        'How did you overcome the technical difficulties encountered?',
        'What would you do differently if you started again?',
        'Were there any scope changes during the project, and why?',
    ],
    'Results' => [
        'What are the key findings or results of your project?',
        'How do you evaluate or measure your system\'s performance?',
        'How does your system compare to existing solutions?',
        'What testing strategies did you employ?',
    ],
    'Future Work' => [
        'What limitations does your current system have?',
        'What improvements or extensions would you make in future?',
        'Is your system scalable? How would it handle increased load?',
        'What real-world deployment considerations exist?',
    ],
];

/* ── Page setup ── */
$pageTitle                = 'Viva Preparation Hub';
$bodyClass                = $role === 'student' ? 'student-dashboard viva-hub' : 'supervisor-dashboard viva-hub';
$topbarBreadcrumbCurrent  = 'Viva Preparation Hub';
$appSidebarBrandSubtitle  = 'Collaboration Hub';
$appSidebarRoleLabel      = $role === 'student' ? 'Student Portal' : 'Supervisor Portal';

/* type hints */
$file_type_meta = [
    'slides'     => ['Slides',      '#3b82f6', 'bi-file-earmark-slides'],
    'pdf'        => ['PDF',         '#ef4444', 'bi-file-earmark-pdf'],
    'poster'     => ['Poster',      '#8b5cf6', 'bi-image'],
    'video'      => ['Video',       '#f59e0b', 'bi-camera-video'],
    'screenshot' => ['Screenshot',  '#06b6d4', 'bi-image-fill'],
    'other'      => ['Other',       '#64748b', 'bi-file-earmark'],
];

require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ── Viva Hub theme ──────────────────────────────────────────── */
.viva-hub .app-content,
.viva-hub main { background: transparent; }

.viva-hub body,
body.viva-hub { background: #080c14; }

.viva-bg {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 80% 60% at 10% 0%,   rgba(59,130,246,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 90% 20%,  rgba(139,92,246,.15) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 50% 100%, rgba(6,182,212,.10)  0%, transparent 50%),
        #080c14;
}
.viva-wrap { position: relative; z-index: 1; padding: 1.5rem 1.75rem 3rem; }

/* ── Glass card ──────────────────────────────────────────────── */
.viva-card {
    background: rgba(255,255,255,.04);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 1.25rem;
    padding: 1.5rem;
    transition: border-color .25s, box-shadow .25s;
}
.viva-card:hover { border-color: rgba(59,130,246,.3); box-shadow: 0 0 0 1px rgba(59,130,246,.1), 0 8px 40px rgba(0,0,0,.35); }
.viva-card__title {
    font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
    color: #94a3b8; margin-bottom: 1.1rem; display: flex; align-items: center; gap: .5rem;
}
.viva-card__title i { font-size: .9rem; }

/* ── Hero ────────────────────────────────────────────────────── */
.viva-hero {
    background: linear-gradient(135deg, rgba(59,130,246,.12) 0%, rgba(139,92,246,.1) 50%, rgba(6,182,212,.08) 100%);
    border: 1px solid rgba(59,130,246,.25);
    border-radius: 1.5rem; padding: 2rem 2.25rem; margin-bottom: 1.75rem;
    display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;
}
.viva-hero__badge {
    display: inline-flex; align-items: center; gap: .4rem; font-size: .7rem;
    font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    background: rgba(59,130,246,.18); border: 1px solid rgba(59,130,246,.35);
    color: #93c5fd; border-radius: 2rem; padding: .3rem .85rem; margin-bottom: .75rem;
}
.viva-hero__title { font-size: 1.85rem; font-weight: 700; color: #f1f5f9; margin: 0; line-height: 1.2; }
.viva-hero__subtitle { color: #94a3b8; margin: .35rem 0 0; font-size: .9rem; }
.viva-hero__meta { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .9rem; }
.viva-hero__meta-item { display: flex; align-items: center; gap: .45rem; color: #cbd5e1; font-size: .85rem; }
.viva-hero__meta-item i { color: #60a5fa; }
.viva-hero__right { margin-left: auto; text-align: center; min-width: 160px; }

/* ── Countdown ───────────────────────────────────────────────── */
.viva-countdown {
    display: flex; gap: .75rem; justify-content: center; margin-bottom: .5rem;
}
.viva-cd-unit {
    background: rgba(0,0,0,.35); border: 1px solid rgba(59,130,246,.25);
    border-radius: .75rem; padding: .6rem .8rem; min-width: 62px; text-align: center;
}
.viva-cd-val { font-size: 1.6rem; font-weight: 700; color: #60a5fa; line-height: 1; }
.viva-cd-lbl { font-size: .58rem; color: #64748b; text-transform: uppercase; letter-spacing: .08em; margin-top: .2rem; }
.viva-cd-note { font-size: .75rem; color: #94a3b8; }

/* ── Progress bar ────────────────────────────────────────────── */
.viva-progress { margin-top: .9rem; }
.viva-progress__label { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; }
.viva-progress__label span { font-size: .78rem; color: #94a3b8; }
.viva-progress__label strong { font-size: .85rem; color: #f1f5f9; }
.viva-progress__bar { height: 8px; background: rgba(255,255,255,.07); border-radius: 99px; overflow: hidden; }
.viva-progress__fill { height: 100%; border-radius: 99px; transition: width .6s ease; background: linear-gradient(90deg, #3b82f6, #06b6d4); }
.viva-progress__fill.is-done { background: linear-gradient(90deg, #22c55e, #06b6d4); }

/* ── Approved badge ─────────────────────────────────────────── */
.viva-approved {
    display: inline-flex; align-items: center; gap: .45rem;
    background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.4);
    color: #4ade80; font-size: .78rem; font-weight: 600;
    border-radius: 2rem; padding: .35rem .85rem; margin-top: .6rem;
}

/* ── Upload area ─────────────────────────────────────────────── */
.viva-dropzone {
    border: 2px dashed rgba(59,130,246,.3); border-radius: 1rem;
    padding: 2rem 1.5rem; text-align: center; cursor: pointer;
    transition: border-color .2s, background .2s;
    background: rgba(59,130,246,.03);
}
.viva-dropzone:hover, .viva-dropzone.is-drag { border-color: #3b82f6; background: rgba(59,130,246,.08); }
.viva-dropzone__icon { font-size: 2rem; color: #3b82f6; margin-bottom: .5rem; }
.viva-dropzone p { color: #64748b; font-size: .85rem; margin: 0; }
.viva-dropzone p strong { color: #93c5fd; }

.viva-mat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap: .75rem; margin-top: 1rem; }
.viva-mat-card {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: .85rem; padding: .9rem; position: relative;
    transition: border-color .2s, transform .2s;
}
.viva-mat-card:hover { border-color: rgba(59,130,246,.3); transform: translateY(-2px); }
.viva-mat-card__icon { font-size: 1.6rem; margin-bottom: .5rem; }
.viva-mat-card__name { font-size: .75rem; color: #cbd5e1; font-weight: 600; word-break: break-all; margin-bottom: .25rem; }
.viva-mat-card__meta { font-size: .68rem; color: #64748b; }
.viva-mat-card__type { font-size: .63rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: .18rem .5rem; border-radius: 99px; display: inline-block; margin-bottom: .35rem; }
.viva-mat-card__del {
    position: absolute; top: .45rem; right: .45rem; background: rgba(239,68,68,.18);
    border: none; color: #f87171; border-radius: .4rem; width: 1.5rem; height: 1.5rem;
    font-size: .65rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s;
}
.viva-mat-card:hover .viva-mat-card__del { opacity: 1; }

/* ── Questions ───────────────────────────────────────────────── */
.viva-q-category { margin-bottom: 1.1rem; }
.viva-q-cat-label {
    font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    padding: .25rem .7rem; border-radius: 99px; display: inline-block; margin-bottom: .6rem;
}
.viva-q-item {
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06);
    border-radius: .75rem; margin-bottom: .4rem; overflow: hidden;
    transition: border-color .2s;
}
.viva-q-item:hover { border-color: rgba(59,130,246,.25); }
.viva-q-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: .7rem 1rem; cursor: pointer; user-select: none; gap: .75rem;
}
.viva-q-header span { font-size: .83rem; color: #e2e8f0; flex: 1; }
.viva-q-header i { color: #64748b; font-size: .7rem; transition: transform .25s; flex-shrink: 0; }
.viva-q-item.is-open .viva-q-header i { transform: rotate(180deg); }
.viva-q-body {
    max-height: 0; overflow: hidden; transition: max-height .3s ease;
    padding: 0 1rem;
}
.viva-q-item.is-open .viva-q-body { max-height: 200px; }
.viva-q-body-inner { padding: .5rem 0 .9rem; border-top: 1px solid rgba(255,255,255,.06); }
.viva-q-body-inner p { color: #94a3b8; font-size: .8rem; margin: 0 0 .5rem; }
.viva-q-status {
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    padding: .2rem .6rem; border-radius: 99px; display: inline-flex; align-items: center; gap: .3rem; cursor: pointer; border: none;
    transition: background .2s;
}
.viva-q-status.unpracticed { background: rgba(100,116,139,.2); color: #94a3b8; }
.viva-q-status.practiced   { background: rgba(34,197,94,.15); color: #4ade80; }

/* ── Recordings ──────────────────────────────────────────────── */
.viva-rec-bar {
    background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3);
    border-radius: .75rem; padding: .75rem 1rem; display: none;
    align-items: center; gap: .75rem; margin-bottom: 1rem;
}
.viva-rec-bar.is-active { display: flex; }
.viva-rec-dot { width: 10px; height: 10px; border-radius: 50%; background: #ef4444; animation: rec-pulse 1.2s infinite; }
@keyframes rec-pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.75)} }
.viva-rec-timer { font-size: .85rem; color: #fca5a5; font-variant-numeric: tabular-nums; flex: 1; }
.viva-rec-list { display: flex; flex-direction: column; gap: .5rem; margin-top: 1rem; }
.viva-rec-item {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.07);
    border-radius: .75rem; padding: .7rem .9rem; display: flex; align-items: center; gap: .75rem;
}
.viva-rec-item__icon { font-size: 1.2rem; color: #8b5cf6; flex-shrink: 0; }
.viva-rec-item__info { flex: 1; min-width: 0; }
.viva-rec-item__label { font-size: .82rem; color: #e2e8f0; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.viva-rec-item__meta  { font-size: .7rem; color: #64748b; }
.viva-rec-item audio  { height: 28px; flex: 1; }
.viva-btn {
    border: none; border-radius: .6rem; padding: .45rem .9rem; font-size: .8rem;
    font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem;
    transition: opacity .2s, transform .15s;
}
.viva-btn:hover { opacity: .85; transform: translateY(-1px); }
.viva-btn--blue   { background: #3b82f6; color: #fff; }
.viva-btn--cyan   { background: #06b6d4; color: #fff; }
.viva-btn--purple { background: #8b5cf6; color: #fff; }
.viva-btn--red    { background: rgba(239,68,68,.18); color: #f87171; border: 1px solid rgba(239,68,68,.3); }
.viva-btn--ghost  { background: rgba(255,255,255,.06); color: #cbd5e1; border: 1px solid rgba(255,255,255,.1); }

/* ── Feedback panel ──────────────────────────────────────────── */
.viva-fb-item {
    background: rgba(139,92,246,.07); border: 1px solid rgba(139,92,246,.2);
    border-radius: 1rem; padding: 1rem 1.2rem; margin-bottom: .75rem;
}
.viva-fb-item__header { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; }
.viva-fb-item__avatar {
    width: 2rem; height: 2rem; border-radius: 50%; background: linear-gradient(135deg,#8b5cf6,#3b82f6);
    display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.viva-fb-item__name { font-size: .82rem; font-weight: 600; color: #e2e8f0; }
.viva-fb-item__date { font-size: .7rem; color: #64748b; }
.viva-fb-item__content { font-size: .82rem; color: #cbd5e1; line-height: 1.55; }
.viva-fb-approved { display: inline-flex; align-items: center; gap: .35rem; background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.3); color: #4ade80; font-size: .7rem; font-weight: 700; padding: .2rem .6rem; border-radius: 99px; margin-left: auto; }

/* ── Checklist ───────────────────────────────────────────────── */
.viva-cl-item {
    display: flex; align-items: center; gap: .9rem; padding: .8rem 1rem;
    border: 1px solid rgba(255,255,255,.07); border-radius: .75rem; margin-bottom: .45rem;
    cursor: pointer; transition: border-color .2s, background .2s;
}
.viva-cl-item:hover { border-color: rgba(59,130,246,.3); background: rgba(59,130,246,.04); }
.viva-cl-item.is-checked { border-color: rgba(34,197,94,.3); background: rgba(34,197,94,.05); }
.viva-cl-icon { font-size: 1.1rem; width: 2rem; text-align: center; color: #64748b; flex-shrink: 0; }
.viva-cl-item.is-checked .viva-cl-icon { color: #4ade80; }
.viva-cl-label { flex: 1; font-size: .85rem; color: #cbd5e1; }
.viva-cl-item.is-checked .viva-cl-label { color: #4ade80; text-decoration: line-through; text-decoration-color: rgba(74,222,128,.4); }
.viva-cl-chk {
    width: 1.3rem; height: 1.3rem; border-radius: .35rem; border: 2px solid rgba(255,255,255,.2);
    background: transparent; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    transition: border-color .2s, background .2s; font-size: .65rem; color: transparent;
}
.viva-cl-item.is-checked .viva-cl-chk { background: #22c55e; border-color: #22c55e; color: #fff; }

/* ── Section headings ────────────────────────────────────────── */
.viva-section-title { font-size: 1rem; font-weight: 700; color: #f1f5f9; margin-bottom: 1.1rem; display: flex; align-items: center; gap: .6rem; }
.viva-section-title i { color: #60a5fa; }

/* ── Supervisor projects list (when no pid) ──────────────────── */
.viva-proj-list { display: flex; flex-direction: column; gap: .5rem; }
.viva-proj-item {
    display: flex; align-items: center; gap: 1rem; padding: .9rem 1.1rem;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: .85rem; text-decoration: none; transition: border-color .2s, transform .15s;
}
.viva-proj-item:hover { border-color: rgba(59,130,246,.35); transform: translateX(4px); }
.viva-proj-item__title { font-size: .88rem; color: #e2e8f0; font-weight: 600; }
.viva-proj-item__student { font-size: .75rem; color: #64748b; }
.viva-proj-item i { color: #3b82f6; margin-left: auto; }

/* ── Upload form inline ──────────────────────────────────────── */
.viva-upload-form { display: none; margin-top: 1rem; }
.viva-upload-form.is-visible { display: block; }
.viva-form-group { margin-bottom: .85rem; }
.viva-form-group label { font-size: .75rem; color: #94a3b8; margin-bottom: .35rem; display: block; font-weight: 600; }
.viva-form-group select, .viva-form-group input[type=text], .viva-form-group textarea {
    width: 100%; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    border-radius: .6rem; padding: .5rem .75rem; color: #f1f5f9; font-size: .85rem;
}
.viva-form-group select option { background: #1e293b; color: #f1f5f9; }
.viva-form-group select:focus, .viva-form-group input:focus, .viva-form-group textarea:focus {
    outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.viva-form-group textarea { resize: vertical; min-height: 80px; }

/* ── Feedback form ───────────────────────────────────────────── */
.viva-fb-form {
    background: rgba(139,92,246,.05); border: 1px solid rgba(139,92,246,.15);
    border-radius: 1rem; padding: 1.2rem;
}

/* ── Empty state ─────────────────────────────────────────────── */
.viva-empty { text-align: center; padding: 2.5rem 1rem; color: #64748b; }
.viva-empty i { font-size: 2.5rem; opacity: .35; margin-bottom: .75rem; display: block; }
.viva-empty p { font-size: .85rem; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 767px) {
    .viva-wrap { padding: 1rem; }
    .viva-hero { gap: 1rem; }
    .viva-hero__right { margin: 0 auto; }
    .viva-countdown { gap: .4rem; }
    .viva-cd-val { font-size: 1.2rem; }
    .viva-cd-unit { min-width: 50px; padding: .5rem .6rem; }
}
</style>

<div class="viva-bg"></div>
<div class="viva-wrap">

<?php if (!empty($err)): ?>
<div class="alert" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.85rem;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($err) ?>
</div>
<?php endif; ?>
<?php if (!empty($suc)): ?>
<div class="alert" style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.85rem;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($suc) ?>
</div>
<?php endif; ?>

<?php /* ────────── SUPERVISOR: No project selected ────────── */ ?>
<?php if ($role === 'supervisor' && !$project): ?>
<div class="viva-hero" style="flex-direction:column;align-items:flex-start;">
    <div>
        <div class="viva-hero__badge"><i class="bi bi-mortarboard-fill"></i> Viva Preparation Hub</div>
        <h1 class="viva-hero__title">Select a Project</h1>
        <p class="viva-hero__subtitle">Choose a student project to view or manage viva preparation.</p>
    </div>
</div>
<?php if (empty($supervisor_projects ?? [])): ?>
    <div class="viva-card"><div class="viva-empty"><i class="bi bi-people"></i><p>No active projects assigned to you yet.</p></div></div>
<?php else: ?>
<div class="viva-card">
    <div class="viva-card__title"><i class="bi bi-folder2-open"></i> Your Projects</div>
    <div class="viva-proj-list">
        <?php foreach ($supervisor_projects as $sp): ?>
        <a href="<?= base_url('viva.php?pid=' . (int)$sp['id']) ?>" class="viva-proj-item">
            <div>
                <div class="viva-proj-item__title"><?= e(mb_substr($sp['title'], 0, 60)) ?></div>
                <div class="viva-proj-item__student"><i class="bi bi-person-fill me-1"></i><?= e($sp['student_name']) ?></div>
            </div>
            <i class="bi bi-arrow-right-circle-fill"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ────────── STUDENT: No project yet ────────── */ ?>
<?php elseif ($no_project): ?>
<div class="viva-hero" style="flex-direction:column;align-items:flex-start;">
    <div>
        <div class="viva-hero__badge"><i class="bi bi-mortarboard-fill"></i> Viva Preparation Hub</div>
        <h1 class="viva-hero__title">No Project Found</h1>
        <p class="viva-hero__subtitle">You don't have an active project yet. Join a group or create a project to access the Viva Hub.</p>
    </div>
    <a href="<?= base_url('student/group.php') ?>" class="viva-btn viva-btn--blue mt-2"><i class="bi bi-people-fill"></i> Go to My Group</a>
</div>

<?php /* ────────── MAIN PAGE ────────── */ ?>
<?php else: ?>

<!-- ══ HERO ══════════════════════════════════════════════════════ -->
<div class="viva-hero">
    <div style="flex:1;min-width:0;">
        <div class="viva-hero__badge">
            <i class="bi bi-mortarboard-fill"></i>
            <?= ($viva_detail['viva_type'] ?? 'final') === 'proposal' ? 'Proposal Defense' : 'Final Viva' ?>
        </div>
        <h1 class="viva-hero__title"><?= e(mb_substr($project['title'] ?? 'Untitled Project', 0, 60)) ?></h1>
        <p class="viva-hero__subtitle">Viva Preparation Hub <?= $role === 'supervisor' ? '&mdash; Student: <strong style="color:#e2e8f0">' . e($project['student_name'] ?? '') . '</strong>' : '' ?></p>
        <div class="viva-hero__meta">
            <?php if (!empty($viva_detail['viva_date'])): ?>
            <span class="viva-hero__meta-item"><i class="bi bi-calendar-event"></i> <?= e($viva_date_str) ?></span>
            <?php endif; ?>
            <?php if (!empty($viva_detail['viva_time'])): ?>
            <span class="viva-hero__meta-item"><i class="bi bi-clock"></i> <?= e($viva_time_str) ?></span>
            <?php endif; ?>
            <?php if (!empty($viva_detail['venue'])): ?>
            <span class="viva-hero__meta-item"><i class="bi bi-geo-alt"></i> <?= e($viva_detail['venue']) ?></span>
            <?php endif; ?>
            <?php if (!empty($viva_detail['panel_members'])): ?>
            <span class="viva-hero__meta-item"><i class="bi bi-person-badge"></i> <?= e(mb_substr($viva_detail['panel_members'], 0, 80)) ?></span>
            <?php endif; ?>
            <?php if (empty($viva_detail['viva_date']) && $role === 'student'): ?>
            <span class="viva-hero__meta-item" style="color:#f59e0b;"><i class="bi bi-hourglass-split"></i> Viva date not yet scheduled</span>
            <?php endif; ?>
        </div>
        <?php if ($is_approved): ?>
        <div class="viva-approved"><i class="bi bi-patch-check-fill"></i> Supervisor Approved</div>
        <?php endif; ?>
        <div class="viva-progress mt-3">
            <div class="viva-progress__label">
                <span>Preparation Progress</span>
                <strong><?= $progress_pct ?>%</strong>
            </div>
            <div class="viva-progress__bar">
                <div class="viva-progress__fill<?= $progress_pct >= 100 ? ' is-done' : '' ?>" style="width:<?= $progress_pct ?>%"></div>
            </div>
        </div>
    </div>
    <?php if ($countdown_ts > time()): ?>
    <div class="viva-hero__right">
        <div style="font-size:.7rem;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.6rem;">Viva Countdown</div>
        <div class="viva-countdown" id="viva-countdown" data-ts="<?= $countdown_ts ?>">
            <div class="viva-cd-unit"><div class="viva-cd-val" id="cd-d">--</div><div class="viva-cd-lbl">Days</div></div>
            <div class="viva-cd-unit"><div class="viva-cd-val" id="cd-h">--</div><div class="viva-cd-lbl">Hrs</div></div>
            <div class="viva-cd-unit"><div class="viva-cd-val" id="cd-m">--</div><div class="viva-cd-lbl">Min</div></div>
            <div class="viva-cd-unit"><div class="viva-cd-val" id="cd-s">--</div><div class="viva-cd-lbl">Sec</div></div>
        </div>
        <div class="viva-cd-note">Until your <?= ($viva_detail['viva_type'] ?? 'final') === 'proposal' ? 'proposal defense' : 'final viva' ?></div>
    </div>
    <?php elseif ($countdown_ts && $countdown_ts <= time()): ?>
    <div class="viva-hero__right">
        <div class="viva-cd-unit" style="min-width:auto;padding:1rem 1.5rem;">
            <div style="font-size:1.5rem;">🎓</div>
            <div style="font-size:.75rem;color:#4ade80;font-weight:700;margin-top:.3rem;">Viva Day!</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">

<!-- ══ COLUMN LEFT ══════════════════════════════════════════════ -->
<div class="col-lg-4">

    <!-- ── Viva Checklist ── -->
    <div class="viva-card mb-4">
        <div class="viva-card__title"><i class="bi bi-check2-all" style="color:#22c55e;"></i> Viva Checklist</div>
        <div id="viva-checklist">
            <?php foreach ($checklist_items as $key => [$label, $icon]):
                $checked = $checklist_map[$key] ?? false;
            ?>
            <div class="viva-cl-item<?= $checked ? ' is-checked' : '' ?>" data-key="<?= e($key) ?>" data-pid="<?= $pid ?>" data-sid="<?= $student_uid ?>" onclick="toggleChecklist(this)">
                <div class="viva-cl-icon"><i class="bi <?= e($icon) ?>"></i></div>
                <div class="viva-cl-label"><?= e($label) ?></div>
                <div class="viva-cl-chk"><i class="bi bi-check2"></i></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-3" style="font-size:.78rem;color:#64748b;text-align:center;">
            <?= $checked_count ?> of <?= $total_items ?> items completed
        </div>
    </div>

    <!-- ── Supervisor: Set viva details form ── -->
    <?php if ($role === 'supervisor'): ?>
    <div class="viva-card mb-4">
        <div class="viva-card__title"><i class="bi bi-calendar2-check" style="color:#06b6d4;"></i> Viva Details</div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_viva_details">
            <input type="hidden" name="project_id" value="<?= $pid ?>">
            <div class="viva-form-group">
                <label>Viva Type</label>
                <select name="viva_type">
                    <option value="proposal" <?= ($viva_detail['viva_type'] ?? '') === 'proposal' ? 'selected' : '' ?>>Proposal Defense</option>
                    <option value="final"    <?= ($viva_detail['viva_type'] ?? 'final') === 'final' ? 'selected' : '' ?>>Final Viva</option>
                </select>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-7">
                    <div class="viva-form-group mb-0">
                        <label>Date</label>
                        <input type="date" name="viva_date" value="<?= e($viva_detail['viva_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-5">
                    <div class="viva-form-group mb-0">
                        <label>Time</label>
                        <input type="time" name="viva_time" value="<?= e(substr($viva_detail['viva_time'] ?? '', 0, 5)) ?>">
                    </div>
                </div>
            </div>
            <div class="viva-form-group">
                <label>Venue</label>
                <input type="text" name="venue" placeholder="e.g. Room 401, Engineering Block" value="<?= e($viva_detail['venue'] ?? '') ?>">
            </div>
            <div class="viva-form-group">
                <label>Panel Members</label>
                <textarea name="panel_members" placeholder="List panel members, one per line..."><?= e($viva_detail['panel_members'] ?? '') ?></textarea>
            </div>
            <div class="viva-form-group">
                <label>Additional Notes</label>
                <textarea name="viva_notes" placeholder="Any notes for the student..."><?= e($viva_detail['notes'] ?? '') ?></textarea>
            </div>
            <div class="viva-form-group mb-0" style="display:flex;align-items:center;gap:.5rem;">
                <input type="checkbox" name="is_confirmed" value="1" id="viva_confirmed" <?= !empty($viva_detail['is_confirmed']) ? 'checked' : '' ?> style="accent-color:#3b82f6;">
                <label for="viva_confirmed" style="margin:0;font-size:.8rem;color:#cbd5e1;cursor:pointer;">Mark as confirmed</label>
            </div>
            <button type="submit" class="viva-btn viva-btn--blue w-100 mt-3"><i class="bi bi-save"></i> Save Viva Details</button>
        </form>
    </div>
    <?php endif; ?>

</div><!-- /col-left -->

<!-- ══ COLUMN RIGHT ═════════════════════════════════════════════ -->
<div class="col-lg-8">

    <!-- ── Presentation Materials ── -->
    <div class="viva-card mb-4">
        <div class="viva-card__title"><i class="bi bi-folder2-open" style="color:#3b82f6;"></i> Presentation Materials</div>

        <?php if ($role === 'student'): ?>
        <div class="viva-dropzone" id="viva-dropzone" onclick="document.getElementById('viva-file-input').click()">
            <div class="viva-dropzone__icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
            <p><strong>Click or drag files here</strong> to upload</p>
            <p class="mt-1" style="font-size:.75rem;">Slides, PDFs, Posters, Videos, Screenshots &mdash; up to 150 MB</p>
        </div>
        <div class="viva-upload-form" id="upload-form-wrap">
            <form method="post" enctype="multipart/form-data" id="upload-mat-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_material">
                <input type="file" name="material_file" id="viva-file-input" style="display:none" accept=".pptx,.ppt,.key,.pdf,.png,.jpg,.jpeg,.gif,.webp,.mp4,.webm,.mov,.avi">
                <div class="row g-2 align-items-end mt-1">
                    <div class="col-sm-5">
                        <div class="viva-form-group mb-0">
                            <label>Selected File</label>
                            <input type="text" id="viva-file-name" placeholder="No file chosen" readonly style="cursor:pointer;" onclick="document.getElementById('viva-file-input').click()">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="viva-form-group mb-0">
                            <label>Type</label>
                            <select name="file_type" id="viva-file-type">
                                <option value="slides">Slides (PPTX/PPT)</option>
                                <option value="pdf">PDF Document</option>
                                <option value="poster">Research Poster</option>
                                <option value="video">Demo Video</option>
                                <option value="screenshot">Screenshot</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="viva-btn viva-btn--blue w-100"><i class="bi bi-upload"></i> Upload</button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($materials)): ?>
        <div class="viva-empty" style="padding:1.5rem 1rem;">
            <i class="bi bi-folder2" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
            <p style="font-size:.82rem;">No materials uploaded yet<?= $role === 'student' ? '. Use the upload area above.' : '.' ?></p>
        </div>
        <?php else: ?>
        <div class="viva-mat-grid">
            <?php foreach ($materials as $mat):
                $ftm   = $file_type_meta[$mat['file_type']] ?? $file_type_meta['other'];
                $fsize = $mat['file_size'] > 1048576 ? round($mat['file_size']/1048576, 1).' MB' : round($mat['file_size']/1024).' KB';
            ?>
            <div class="viva-mat-card">
                <?php if ($role === 'student'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this file?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_material">
                    <input type="hidden" name="material_id" value="<?= (int)$mat['id'] ?>">
                    <button type="submit" class="viva-mat-card__del" title="Remove"><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
                <div class="viva-mat-card__icon" style="color:<?= e($ftm[1]) ?>"><i class="bi <?= e($ftm[2]) ?>"></i></div>
                <span class="viva-mat-card__type" style="background:<?= e($ftm[1]) ?>22;color:<?= e($ftm[1]) ?>;border:1px solid <?= e($ftm[1]) ?>44;"><?= e($ftm[0]) ?></span>
                <div class="viva-mat-card__name"><?= e($mat['file_name']) ?></div>
                <div class="viva-mat-card__meta"><?= e($fsize) ?> &middot; <?= date('M j', strtotime($mat['uploaded_at'])) ?></div>
                <a href="<?= base_url($mat['file_path']) ?>" target="_blank" class="viva-btn viva-btn--ghost mt-2" style="font-size:.7rem;padding:.25rem .6rem;"><i class="bi bi-eye"></i> View</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div><!-- /materials -->

    <!-- ── Mock Questions + Practice Recording (2 cols) ── -->
    <div class="row g-4 mb-4">

        <!-- Mock Questions -->
        <div class="col-md-6">
            <div class="viva-card h-100">
                <div class="viva-card__title"><i class="bi bi-patch-question-fill" style="color:#8b5cf6;"></i> Mock Viva Questions</div>
                <div id="viva-questions" style="max-height:420px;overflow-y:auto;padding-right:.25rem;">
                    <?php
                    $cat_colors = ['Introduction'=>'#3b82f6','Methodology'=>'#06b6d4','Technical'=>'#8b5cf6','Challenges'=>'#f59e0b','Results'=>'#22c55e','Future Work'=>'#ec4899'];
                    $qi = 0;
                    foreach ($mock_questions as $cat => $qs):
                        $cc = $cat_colors[$cat] ?? '#64748b';
                    ?>
                    <div class="viva-q-category">
                        <span class="viva-q-cat-label" style="background:<?= $cc ?>22;color:<?= $cc ?>;border:1px solid <?= $cc ?>44;"><?= e($cat) ?></span>
                        <?php foreach ($qs as $q): $qi++; ?>
                        <div class="viva-q-item" id="q<?= $qi ?>">
                            <div class="viva-q-header" onclick="toggleQ(<?= $qi ?>)">
                                <span><?= e($q) ?></span>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                            <div class="viva-q-body">
                                <div class="viva-q-body-inner">
                                    <p>Prepare a concise, confident answer. Practice speaking it aloud 2–3 times before your viva.</p>
                                    <button class="viva-q-status unpracticed" onclick="toggleQPracticed(this,event)" title="Mark as practiced">
                                        <i class="bi bi-circle"></i> Mark as practiced
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div><!-- /questions -->

        <!-- Practice Recording -->
        <div class="col-md-6">
            <div class="viva-card h-100">
                <div class="viva-card__title"><i class="bi bi-mic-fill" style="color:#ef4444;"></i> Practice Recordings</div>

                <?php if ($role === 'student'): ?>
                <!-- Recording controls -->
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.85rem;">
                    <button class="viva-btn viva-btn--red" id="rec-start-btn" onclick="startRec()"><i class="bi bi-record-circle"></i> Start Recording</button>
                    <input type="text" id="rec-label-input" placeholder="Session label..." style="flex:1;min-width:120px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:.6rem;padding:.45rem .75rem;color:#f1f5f9;font-size:.8rem;">
                </div>
                <!-- Recording bar -->
                <div class="viva-rec-bar" id="rec-bar">
                    <div class="viva-rec-dot"></div>
                    <span class="viva-rec-timer" id="rec-timer">0:00</span>
                    <button class="viva-btn viva-btn--ghost" onclick="stopRec()" style="font-size:.75rem;padding:.3rem .65rem;"><i class="bi bi-stop-fill"></i> Stop &amp; Save</button>
                    <button class="viva-btn viva-btn--ghost" onclick="cancelRec()" style="font-size:.75rem;padding:.3rem .65rem;color:#f87171;"><i class="bi bi-x-lg"></i></button>
                </div>
                <!-- Preview area -->
                <div id="rec-preview" style="display:none;margin-bottom:.75rem;">
                    <audio id="rec-audio" controls style="width:100%;height:32px;"></audio>
                    <div style="display:flex;gap:.5rem;margin-top:.5rem;">
                        <button class="viva-btn viva-btn--blue" onclick="saveRec()" style="font-size:.75rem;"><i class="bi bi-cloud-upload"></i> Save</button>
                        <button class="viva-btn viva-btn--ghost" onclick="discardRec()" style="font-size:.75rem;"><i class="bi bi-trash"></i> Discard</button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Saved recordings -->
                <?php if (empty($recordings)): ?>
                <div class="viva-empty" style="padding:1.2rem .5rem;">
                    <i class="bi bi-mic-mute" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.4rem;"></i>
                    <p style="font-size:.8rem;">No practice recordings yet.</p>
                </div>
                <?php else: ?>
                <div class="viva-rec-list" style="max-height:280px;overflow-y:auto;">
                    <?php foreach ($recordings as $rec):
                        $dur = $rec['duration_seconds'];
                        $ds  = sprintf('%d:%02d', intdiv($dur,60), $dur%60);
                    ?>
                    <div class="viva-rec-item">
                        <div class="viva-rec-item__icon"><i class="bi bi-mic-fill"></i></div>
                        <div class="viva-rec-item__info">
                            <div class="viva-rec-item__label"><?= e($rec['session_label'] ?: 'Practice Session') ?></div>
                            <div class="viva-rec-item__meta"><?= e($ds) ?> &middot; <?= date('M j, g:i A', strtotime($rec['recorded_at'])) ?></div>
                        </div>
                        <audio src="<?= base_url($rec['file_path']) ?>" controls style="height:26px;width:100px;flex-shrink:0;"></audio>
                        <?php if ($role === 'student'): ?>
                        <form method="post" onsubmit="return confirm('Delete this recording?')" style="flex-shrink:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_recording">
                            <input type="hidden" name="recording_id" value="<?= (int)$rec['id'] ?>">
                            <button type="submit" class="viva-btn viva-btn--red" style="padding:.25rem .5rem;font-size:.7rem;" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /recording -->

    </div><!-- /row 2 -->

    <!-- ── Supervisor Feedback Panel ── -->
    <div class="viva-card mb-4">
        <div class="viva-card__title"><i class="bi bi-chat-square-dots-fill" style="color:#8b5cf6;"></i> Supervisor Feedback</div>

        <!-- Existing feedback -->
        <?php if (empty($feedback_list)): ?>
        <div class="viva-empty" style="padding:1.2rem .5rem;">
            <i class="bi bi-chat-dots" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.4rem;"></i>
            <p style="font-size:.82rem;">No feedback from your supervisor yet. Check back soon.</p>
        </div>
        <?php else: ?>
        <div style="margin-bottom:1rem;">
            <?php foreach ($feedback_list as $fb): ?>
            <div class="viva-fb-item">
                <div class="viva-fb-item__header">
                    <div class="viva-fb-item__avatar"><?= strtoupper(substr($fb['supervisor_name'] ?? 'S', 0, 1)) ?></div>
                    <div>
                        <div class="viva-fb-item__name"><?= e($fb['supervisor_name']) ?></div>
                        <div class="viva-fb-item__date"><?= date('M j, Y g:i A', strtotime($fb['updated_at'])) ?></div>
                    </div>
                    <?php if ($fb['is_approved']): ?>
                    <span class="viva-fb-approved" style="margin-left:auto;"><i class="bi bi-patch-check-fill"></i> Approved</span>
                    <?php endif; ?>
                </div>
                <?php if ($fb['content']): ?>
                <div class="viva-fb-item__content"><?= nl2br(e($fb['content'])) ?></div>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem;">
                    <?php if ($fb['attachment_path']): ?>
                    <a href="<?= base_url($fb['attachment_path']) ?>" target="_blank" class="viva-btn viva-btn--ghost" style="font-size:.72rem;padding:.25rem .6rem;"><i class="bi bi-file-earmark-arrow-down"></i> <?= e($fb['attachment_name'] ?: 'Attachment') ?></a>
                    <?php endif; ?>
                    <?php if ($fb['voice_path']): ?>
                    <audio src="<?= base_url($fb['voice_path']) ?>" controls style="height:26px;"></audio>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Supervisor feedback form -->
        <?php if ($role === 'supervisor'): ?>
        <div class="viva-fb-form">
            <div class="viva-card__title" style="margin-bottom:.85rem;"><i class="bi bi-pencil-fill" style="color:#8b5cf6;"></i> Leave Feedback</div>
            <form method="post" enctype="multipart/form-data" id="fb-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="supervisor_feedback">
                <input type="hidden" name="project_id" value="<?= $pid ?>">
                <div class="viva-form-group">
                    <label>Feedback Comments</label>
                    <textarea name="feedback_content" placeholder="Write your feedback, suggestions, or corrections..." rows="4"><?= e($feedback_list[0]['content'] ?? '') ?></textarea>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <div class="viva-form-group mb-0">
                            <label><i class="bi bi-paperclip"></i> Attach File (PDF, DOC, Images &mdash; max 20 MB)</label>
                            <input type="file" name="feedback_file" id="fb-file" accept=".pdf,.doc,.docx,.txt,.png,.jpg,.jpeg,.pptx,.ppt" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:.6rem;padding:.4rem .6rem;color:#f1f5f9;width:100%;font-size:.78rem;">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="viva-form-group mb-0">
                            <label><i class="bi bi-mic"></i> Voice Note Feedback</label>
                            <div style="display:flex;gap:.5rem;align-items:center;">
                                <button type="button" class="viva-btn viva-btn--red" id="fb-rec-btn" onclick="startFbRec()" style="font-size:.75rem;padding:.4rem .75rem;"><i class="bi bi-record-circle"></i> Record</button>
                                <span id="fb-rec-status" style="font-size:.75rem;color:#64748b;"></span>
                                <input type="file" name="feedback_voice" id="fb-voice-input" style="display:none" accept=".webm,.ogg,.mp3,.wav">
                            </div>
                            <audio id="fb-audio-preview" controls style="display:none;height:28px;width:100%;margin-top:.4rem;"></audio>
                        </div>
                    </div>
                </div>
                <div class="viva-form-group" style="display:flex;align-items:center;gap:.6rem;">
                    <input type="checkbox" name="mark_approved" value="1" id="fb-approve" style="accent-color:#22c55e;width:1rem;height:1rem;" <?= ($feedback_list[0]['is_approved'] ?? 0) ? 'checked' : '' ?>>
                    <label for="fb-approve" style="margin:0;font-size:.82rem;color:#cbd5e1;cursor:pointer;"><i class="bi bi-patch-check-fill" style="color:#4ade80;"></i> Mark student as presentation-ready</label>
                </div>
                <button type="submit" class="viva-btn viva-btn--purple"><i class="bi bi-send-fill"></i> Submit Feedback</button>
            </form>
        </div>
        <?php endif; ?>
    </div><!-- /feedback -->

</div><!-- /col-right -->
</div><!-- /row -->

<?php endif; /* end $project check */ ?>

</div><!-- /viva-wrap -->

<script>
/* ── Countdown ────────────────────────────────────────────────── */
(function() {
    const el = document.getElementById('viva-countdown');
    if (!el) return;
    const target = parseInt(el.dataset.ts, 10) * 1000;
    function tick() {
        const diff = target - Date.now();
        if (diff <= 0) { el.innerHTML = '<span style="color:#4ade80;font-weight:700;">Viva Day!</span>'; return; }
        const d = Math.floor(diff / 86400000);
        const h = Math.floor((diff % 86400000) / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        const s = Math.floor((diff % 60000) / 1000);
        document.getElementById('cd-d').textContent = String(d).padStart(2,'0');
        document.getElementById('cd-h').textContent = String(h).padStart(2,'0');
        document.getElementById('cd-m').textContent = String(m).padStart(2,'0');
        document.getElementById('cd-s').textContent = String(s).padStart(2,'0');
    }
    tick(); setInterval(tick, 1000);
})();

/* ── Toggle mock question ─────────────────────────────────────── */
function toggleQ(id) {
    const el = document.getElementById('q' + id);
    if (el) el.classList.toggle('is-open');
}
function toggleQPracticed(btn, e) {
    e.stopPropagation();
    const p = btn.classList.contains('practiced');
    btn.classList.toggle('practiced', !p);
    btn.classList.toggle('unpracticed', p);
    btn.innerHTML = p ? '<i class="bi bi-circle"></i> Mark as practiced' : '<i class="bi bi-check-circle-fill"></i> Practiced';
}

/* ── Checklist toggle ─────────────────────────────────────────── */
const _csrf = <?= json_encode(csrf_token()) ?>;
function toggleChecklist(el) {
    const key  = el.dataset.key;
    const pid  = el.dataset.pid;
    const sid  = el.dataset.sid;
    const now  = !el.classList.contains('is-checked');
    el.classList.toggle('is-checked', now);
    const fd = new FormData();
    fd.append('action', 'toggle_checklist');
    fd.append('_csrf_token', _csrf);
    fd.append('item_key', key);
    fd.append('is_checked', now ? '1' : '0');
    fd.append('project_id', pid);
    fd.append('student_id', sid);
    fetch(window.location.href, { method: 'POST', body: fd }).catch(() => {});
    /* update progress */
    const total   = document.querySelectorAll('.viva-cl-item').length;
    const checked = document.querySelectorAll('.viva-cl-item.is-checked').length;
    const pct     = Math.round(checked / total * 100);
    const fill    = document.querySelector('.viva-progress__fill');
    const lbl     = document.querySelector('.viva-progress__label strong');
    if (fill) { fill.style.width = pct + '%'; fill.classList.toggle('is-done', pct >= 100); }
    if (lbl)  lbl.textContent = pct + '%';
}

/* ── Drag-drop upload ─────────────────────────────────────────── */
(function() {
    const dz   = document.getElementById('viva-dropzone');
    const inp  = document.getElementById('viva-file-input');
    const fw   = document.getElementById('upload-form-wrap');
    const fn   = document.getElementById('viva-file-name');
    if (!dz || !inp) return;
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('is-drag'); }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('is-drag'); }));
    dz.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length) { inp.files = files; showUploadForm(files[0].name); }
    });
    inp.addEventListener('change', () => { if (inp.files.length) showUploadForm(inp.files[0].name); });
    function showUploadForm(name) {
        if (fn)  fn.value = name;
        if (fw)  fw.classList.add('is-visible');
        /* auto-guess type */
        const ext = name.split('.').pop().toLowerCase();
        const sel = document.getElementById('viva-file-type');
        if (!sel) return;
        if (['ppt','pptx','key'].includes(ext))        sel.value = 'slides';
        else if (ext === 'pdf')                         sel.value = 'pdf';
        else if (['mp4','webm','mov','avi'].includes(ext)) sel.value = 'video';
        else if (['png','jpg','jpeg','gif','webp'].includes(ext)) sel.value = 'screenshot';
    }
})();

/* ── Practice recording ───────────────────────────────────────── */
let recMR = null, recChunks = [], recStream = null, recInterval = null, recStart = 0, recBlob = null;

function startRec() {
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        recStream = stream;
        recMR = new MediaRecorder(stream);
        recChunks = [];
        recMR.ondataavailable = e => { if (e.data.size) recChunks.push(e.data); };
        recMR.onstop = () => {
            recBlob = new Blob(recChunks, { type: 'audio/webm' });
            const url = URL.createObjectURL(recBlob);
            const audio = document.getElementById('rec-audio');
            if (audio) { audio.src = url; }
            document.getElementById('rec-preview').style.display = 'block';
        };
        recMR.start();
        recStart = Date.now();
        document.getElementById('rec-bar').classList.add('is-active');
        document.getElementById('rec-start-btn').disabled = true;
        recInterval = setInterval(() => {
            const s = Math.floor((Date.now() - recStart) / 1000);
            document.getElementById('rec-timer').textContent = Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
        }, 500);
    }).catch(() => alert('Microphone access denied.'));
}

function stopRec() {
    if (recMR && recMR.state !== 'inactive') recMR.stop();
    if (recStream) recStream.getTracks().forEach(t => t.stop());
    clearInterval(recInterval);
    document.getElementById('rec-bar').classList.remove('is-active');
    document.getElementById('rec-start-btn').disabled = false;
}

function cancelRec() {
    stopRec();
    recBlob = null;
    document.getElementById('rec-preview').style.display = 'none';
}

function discardRec() { cancelRec(); }

function saveRec() {
    if (!recBlob) return;
    const dur   = Math.floor((Date.now() - recStart) / 1000);
    const label = document.getElementById('rec-label-input')?.value || 'Practice Session';
    const fd    = new FormData();
    fd.append('action', 'save_recording');
    fd.append('_csrf_token', _csrf);
    fd.append('recording', recBlob, 'recording.webm');
    fd.append('duration', dur);
    fd.append('session_label', label);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(() => location.reload())
        .catch(() => alert('Save failed. Please try again.'));
}

/* ── Supervisor feedback voice note ──────────────────────────── */
let fbMR = null, fbChunks = [], fbStream = null, fbBlob = null, fbRec = false;

function startFbRec() {
    const btn = document.getElementById('fb-rec-btn');
    const st  = document.getElementById('fb-rec-status');
    if (!fbRec) {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
            fbStream  = stream;
            fbMR      = new MediaRecorder(stream);
            fbChunks  = [];
            fbMR.ondataavailable = e => { if (e.data.size) fbChunks.push(e.data); };
            fbMR.onstop = () => {
                fbBlob = new Blob(fbChunks, { type: 'audio/webm' });
                const url = URL.createObjectURL(fbBlob);
                const ap  = document.getElementById('fb-audio-preview');
                ap.src = url; ap.style.display = 'block';
                /* inject as file into hidden input */
                const dt = new DataTransfer();
                dt.items.add(new File([fbBlob], 'voice_feedback.webm', { type: 'audio/webm' }));
                document.getElementById('fb-voice-input').files = dt.files;
            };
            fbMR.start();
            fbRec = true;
            btn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop';
            btn.className = 'viva-btn viva-btn--ghost';
            if (st) st.textContent = 'Recording...';
        }).catch(() => alert('Microphone access denied.'));
    } else {
        if (fbMR && fbMR.state !== 'inactive') fbMR.stop();
        if (fbStream) fbStream.getTracks().forEach(t => t.stop());
        fbRec = false;
        btn.innerHTML = '<i class="bi bi-record-circle"></i> Record';
        btn.className = 'viva-btn viva-btn--red';
        if (st) st.textContent = 'Recorded ✓';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
