<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user   = current_user();
$role   = $user['role'];
$uid    = (int) $user['id'];
$pdo    = getPDO();

ensure_announcements_tables($pdo);

$can_manage = in_array($role, ['admin', 'hod'], true);

/* ─── Category / Priority meta ─────────────────────────────────── */
$categories = [
    'academic'           => ['label' => 'Academic',          'icon' => 'bi-mortarboard-fill',   'color' => '#3b82f6'],
    'viva_notice'        => ['label' => 'Viva Notice',       'icon' => 'bi-calendar-event-fill', 'color' => '#8b5cf6'],
    'deadline_reminder'  => ['label' => 'Deadline Reminder', 'icon' => 'bi-clock-fill',          'color' => '#f59e0b'],
    'urgent_alert'       => ['label' => 'Urgent Alert',      'icon' => 'bi-exclamation-triangle-fill', 'color' => '#ef4444'],
    'general'            => ['label' => 'General',           'icon' => 'bi-megaphone-fill',      'color' => '#22c55e'],
];
$priorities = [
    'low'    => ['label' => 'Low',    'badge' => 'bg-secondary'],
    'medium' => ['label' => 'Medium', 'badge' => 'bg-primary'],
    'high'   => ['label' => 'High',   'badge' => 'bg-warning text-dark'],
    'urgent' => ['label' => 'Urgent', 'badge' => 'bg-danger'],
];
$audiences = [
    'all'             => 'All Users',
    'students'        => 'Students Only',
    'supervisors'     => 'Supervisors Only',
    'hod'             => 'HOD Only',
    'students_supervisors' => 'Students & Supervisors',
    'hod_supervisors' => 'HOD & Supervisors',
    'department'      => 'Department',
];

/* ─── Fetch departments for form ────────────────────────────────── */
ensure_departments_table($pdo);
$dept_rows = $pdo->query('SELECT id, name FROM departments WHERE is_active=1 ORDER BY name')->fetchAll();

/* ─── POST handler ──────────────────────────────────────────────── */
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $error = 'Security check failed.'; goto render; }

    $action = $_POST['action'] ?? '';

    /* ── Delete ── */
    if ($action === 'delete' && $can_manage) {
        $aid = (int) ($_POST['aid'] ?? 0);
        $chk = $pdo->prepare('SELECT id, attachment_path FROM announcements WHERE id = ? LIMIT 1');
        $chk->execute([$aid]);
        $row = $chk->fetch();
        if ($row) {
            if (!empty($row['attachment_path'])) {
                $fp = __DIR__ . '/' . ltrim($row['attachment_path'], '/');
                if (file_exists($fp)) unlink($fp);
            }
            $pdo->prepare('DELETE FROM announcement_reads WHERE announcement_id = ?')->execute([$aid]);
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$aid]);
            $success = 'Announcement deleted.';
        }
        goto render;
    }

    /* ── Toggle pin ── */
    if ($action === 'toggle_pin' && $can_manage) {
        $aid = (int) ($_POST['aid'] ?? 0);
        $pdo->prepare('UPDATE announcements SET is_pinned = NOT is_pinned WHERE id = ?')->execute([$aid]);
        goto render;
    }

    /* ── Toggle active ── */
    if ($action === 'toggle_active' && $can_manage) {
        $aid = (int) ($_POST['aid'] ?? 0);
        $pdo->prepare('UPDATE announcements SET is_active = NOT is_active WHERE id = ?')->execute([$aid]);
        goto render;
    }

    /* ── Create ── */
    if ($action === 'create' && $can_manage) {
        $title    = trim($_POST['title']    ?? '');
        $content  = trim($_POST['content']  ?? '');
        $category = $_POST['category']  ?? 'general';
        $priority = $_POST['priority']  ?? 'medium';
        $audience = $_POST['audience']  ?? 'all';
        $dept_val = trim($_POST['department'] ?? '');
        $link_url = trim($_POST['link_url']   ?? '');
        $link_lbl = trim($_POST['link_label'] ?? '');
        $sched_raw = trim($_POST['scheduled_at'] ?? '');
        $exp_raw   = trim($_POST['expires_at']   ?? '');
        $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;

        if (!$title || !$content) { $error = 'Title and content are required.'; goto render; }
        if (!array_key_exists($category, $categories)) $category = 'general';
        if (!array_key_exists($priority, $priorities)) $priority = 'medium';
        if (!array_key_exists($audience, $audiences))  $audience = 'all';

        $scheduled_at = ($sched_raw !== '') ? date('Y-m-d H:i:s', strtotime($sched_raw)) : null;
        $expires_at   = ($exp_raw   !== '') ? date('Y-m-d H:i:s', strtotime($exp_raw))   : null;

        if ($scheduled_at && strtotime($scheduled_at) < strtotime(date('Y-m-d'))) {
            $error = 'Schedule date cannot be in the past.'; goto render;
        }
        if ($expires_at && strtotime($expires_at) < strtotime(date('Y-m-d'))) {
            $error = 'Expiry date cannot be in the past.'; goto render;
        }

        /* file attachment */
        $att_path = null; $att_name = null;
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $f      = $_FILES['attachment'];
            $allowed_ext = ['pdf','doc','docx','ppt','pptx','xls','xlsx','jpg','jpeg','png','gif','zip'];
            $ext    = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                $error = 'Unsupported file type.'; goto render;
            }
            if ($f['size'] > 20 * 1024 * 1024) { $error = 'File too large (max 20 MB).'; goto render; }
            $dir = __DIR__ . '/uploads/announcements/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname    = 'ann_' . $uid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                $att_path = 'uploads/announcements/' . $fname;
                $att_name = $f['name'];
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO announcements
             (title,content,category,priority,audience,department,author_id,is_pinned,scheduled_at,expires_at,attachment_path,attachment_name,link_url,link_label)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$title, $content, $category, $priority, $audience,
            ($audience === 'department' ? $dept_val : null),
            $uid, $is_pinned, $scheduled_at, $expires_at, $att_path, $att_name, $link_url ?: null, $link_lbl ?: null]);
        $new_id = (int) $pdo->lastInsertId();

        /* Fan-out notifications if published immediately */
        if (!$scheduled_at || strtotime($scheduled_at) <= time()) {
            $noti_title = ($priority === 'urgent' ? '🚨 Urgent: ' : '') . $title;
            $noti_msg   = mb_substr(strip_tags($content), 0, 120) . (mb_strlen($content) > 120 ? '…' : '');
            $noti_link  = base_url('announcements.php#ann-' . $new_id);

            $where_role = match($audience) {
                'students'             => "AND role = 'student'",
                'supervisors'          => "AND role = 'supervisor'",
                'hod'                  => "AND role = 'hod'",
                'students_supervisors' => "AND role IN ('student','supervisor')",
                'hod_supervisors'      => "AND role IN ('hod','supervisor')",
                'department'           => "AND department = " . $pdo->quote($dept_val),
                default                => '',
            };
            $targets = $pdo->query("SELECT id FROM users WHERE is_active=1 {$where_role}")->fetchAll(PDO::FETCH_COLUMN);
            $nq = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)');
            foreach ($targets as $tid) {
                if ((int)$tid !== $uid) {
                    $nq->execute([$tid, 'announcement', $noti_title, $noti_msg, $noti_link]);
                }
            }
        }

        audit_log($pdo, 'announcement_create', 'announcement', 'announcement', $new_id, $title,
            "Category: $category | Priority: $priority | Audience: $audience");
        $success = 'Announcement published successfully.';
        goto render;
    }
}

render:

/* ─── Build visibility WHERE clause ──────────────────────────── */
$now = date('Y-m-d H:i:s');
$user_dept = (string) ($user['department'] ?? '');

if ($can_manage) {
    $vis_where = '1=1'; // admins/hods see everything including drafts & future
    $vis_params = [];
} else {
    /* Build the list of audience values this role can see */
    $aud_visible = ["'all'"];
    if ($role === 'student') {
        $aud_visible[] = "'students'";
        $aud_visible[] = "'students_supervisors'";
    } elseif ($role === 'supervisor') {
        $aud_visible[] = "'supervisors'";
        $aud_visible[] = "'students_supervisors'";
        $aud_visible[] = "'hod_supervisors'";
    } elseif ($role === 'hod') {
        $aud_visible[] = "'hod'";
        $aud_visible[] = "'hod_supervisors'";
    }
    $aud_in = implode(',', $aud_visible);
    $vis_where  = "(a.audience IN ($aud_in) OR (a.audience='department' AND a.department=?))
                   AND a.is_active=1
                   AND (a.scheduled_at IS NULL OR a.scheduled_at <= ?)
                   AND (a.expires_at IS NULL OR a.expires_at > ?)";
    $vis_params = [$user_dept, $now, $now];
}

/* ─── Fetch all visible announcements ─────────────────────────── */
$sql = "SELECT a.*, u.full_name AS author_name,
        (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id) AS read_count,
        (SELECT COUNT(*) FROM announcement_reads ar2 WHERE ar2.announcement_id = a.id AND ar2.user_id = ?) AS i_read
        FROM announcements a
        JOIN users u ON u.id = a.author_id
        WHERE $vis_where
        ORDER BY a.is_pinned DESC, a.created_at DESC";

$stmt = $pdo->prepare($sql);
$params = array_merge([$uid], $vis_params);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

/* ─── Stats for manage view ────────────────────────────────────── */
$stats_total  = count($announcements);
$stats_active = count(array_filter($announcements, fn($a) => $a['is_active']));
$stats_pinned = count(array_filter($announcements, fn($a) => $a['is_pinned']));
$stats_unread = count(array_filter($announcements, fn($a) => !$a['i_read'] && $a['is_active']));

/* ─── Page meta ─────────────────────────────────────────────────── */
$pageTitle = 'Announcements';
$topbarVariant = match($role) {
    'supervisor' => 'supervisor-dashboard',
    'hod'        => 'hod-dashboard',
    default      => 'default',
};
if ($role === 'hod') {
    $topbarDepartment = get_department_display_name($pdo, $user['department']);
    $topbarDate = date('M j, Y');
}
$topbarBreadcrumbCurrent = 'Announcements';
require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ── Announcements page ─────────────────────────────────────────────── */
.ann-page { padding: 1.5rem 0; }
.ann-hero {
    background: linear-gradient(135deg, rgba(30,41,59,.95) 0%, rgba(15,23,42,.98) 100%);
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 16px;
    padding: 2rem 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.ann-hero::before {
    content:'';
    position:absolute; inset:0;
    background: radial-gradient(ellipse at 80% 0%, rgba(99,102,241,.12) 0%, transparent 60%);
    pointer-events:none;
}
.ann-hero__title { font-size:1.7rem; font-weight:700; color:#f1f5f9; margin:0; }
.ann-hero__sub   { color:#94a3b8; font-size:.9rem; margin:.3rem 0 0; }
.ann-stat-badge  { background:rgba(99,102,241,.15); border:1px solid rgba(99,102,241,.3);
    border-radius:10px; padding:.4rem .9rem; font-size:.8rem; color:#a5b4fc; font-weight:600; }
.ann-stat-badge span { font-size:1.1rem; color:#fff; display:block; }

/* ── Cards ── */
.ann-card {
    background: rgba(15,23,42,.8);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(51,65,85,.7);
    border-radius: 14px;
    transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
    overflow: hidden;
    position: relative;
}
.ann-card:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(0,0,0,.4); }
.ann-card--pinned { border-color: rgba(251,191,36,.4); }
.ann-card--pinned::after {
    content:'📌';
    position:absolute; top:.8rem; right:.9rem;
    font-size:.85rem;
}
.ann-card--urgent  { border-left: 3px solid #ef4444; }
.ann-card--high    { border-left: 3px solid #f59e0b; }
.ann-card--medium  { border-left: 3px solid #3b82f6; }
.ann-card--low     { border-left: 3px solid #6b7280; }

.ann-card__header { padding: 1rem 1.2rem .6rem; display:flex; align-items:flex-start; gap:.75rem; }
.ann-cat-icon {
    width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center;
    font-size:1rem; flex-shrink:0;
}
.ann-card__title  { font-size:1rem; font-weight:700; color:#f1f5f9; margin:0; line-height:1.3; }
.ann-card__meta   { font-size:.75rem; color:#64748b; margin:.2rem 0 0; }
.ann-card__body   { padding:.2rem 1.2rem 1rem; color:#cbd5e1; font-size:.88rem; line-height:1.6; }
.ann-card__footer { padding:.6rem 1.2rem .9rem; border-top:1px solid rgba(51,65,85,.5);
    display:flex; align-items:center; flex-wrap:wrap; gap:.5rem; }

/* priority badges */
.ann-pri { font-size:.68rem; font-weight:700; letter-spacing:.04em; padding:.2rem .55rem; border-radius:5px; text-transform:uppercase; }
.ann-pri--urgent { background:rgba(239,68,68,.2); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }
.ann-pri--high   { background:rgba(245,158,11,.2); color:#fcd34d; border:1px solid rgba(245,158,11,.3); }
.ann-pri--medium { background:rgba(59,130,246,.2); color:#93c5fd; border:1px solid rgba(59,130,246,.3); }
.ann-pri--low    { background:rgba(107,114,128,.2); color:#9ca3af; border:1px solid rgba(107,114,128,.3); }

/* audience pill */
.ann-aud { font-size:.7rem; padding:.18rem .5rem; border-radius:4px;
    background:rgba(30,41,59,.9); color:#94a3b8; border:1px solid rgba(51,65,85,.6); }

/* read indicator */
.ann-read-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.ann-read-dot--read   { background:#22c55e; }
.ann-read-dot--unread { background:#f59e0b; animation:pulse-dot 1.5s infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.3)} }

/* attachment / link pills */
.ann-attach, .ann-link {
    font-size:.75rem; display:inline-flex; align-items:center; gap:.3rem;
    padding:.25rem .6rem; border-radius:6px; text-decoration:none; transition:background .15s;
}
.ann-attach { background:rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.25); }
.ann-attach:hover { background:rgba(59,130,246,.25); color:#bfdbfe; }
.ann-link { background:rgba(168,85,247,.15); color:#d8b4fe; border:1px solid rgba(168,85,247,.25); }
.ann-link:hover { background:rgba(168,85,247,.25); color:#ede9fe; }

/* read count */
.ann-views { font-size:.73rem; color:#64748b; display:flex; align-items:center; gap:.3rem; }

/* expiry */
.ann-expiry { font-size:.72rem; }
.ann-expiry--active  { color:#86efac; }
.ann-expiry--expired { color:#f87171; }
.ann-expiry--soon    { color:#fcd34d; }

/* ── Management table ── */
.ann-table-wrap { background:rgba(15,23,42,.8); border:1px solid rgba(51,65,85,.6);
    border-radius:12px; overflow:hidden; }
.ann-table thead th { background:rgba(30,41,59,.9); color:#94a3b8; font-size:.78rem;
    font-weight:600; letter-spacing:.05em; text-transform:uppercase; padding:.75rem 1rem;
    border-bottom:1px solid rgba(51,65,85,.5); }
.ann-table tbody td { padding:.7rem 1rem; border-bottom:1px solid rgba(51,65,85,.3);
    font-size:.84rem; color:#cbd5e1; vertical-align:middle; }
.ann-table tbody tr:last-child td { border-bottom:none; }
.ann-table tbody tr:hover td { background:rgba(30,41,59,.4); }

/* ── Create modal ── */
.ann-modal .modal-content {
    background: rgba(15,23,42,.97);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(99,102,241,.3);
    border-radius: 16px;
    color: #e2e8f0;
}
.ann-modal .modal-header { border-bottom:1px solid rgba(51,65,85,.5); padding:1.2rem 1.5rem; }
.ann-modal .modal-footer { border-top:1px solid rgba(51,65,85,.5); padding:1rem 1.5rem; }
.ann-modal .form-label { color:#94a3b8; font-size:.82rem; font-weight:600; }
.ann-modal .form-control, .ann-modal .form-select {
    background:rgba(30,41,59,.8); border:1px solid rgba(51,65,85,.7);
    color:#e2e8f0; border-radius:8px;
}
.ann-modal .form-control:focus, .ann-modal .form-select:focus {
    background:rgba(30,41,59,.9); border-color:rgba(99,102,241,.5);
    box-shadow:0 0 0 3px rgba(99,102,241,.15); color:#f1f5f9;
}
.ann-modal .form-control::placeholder { color:#475569; }
.ann-modal .form-select option { background:#1e293b; color:#e2e8f0; }
.ann-modal textarea { resize: vertical; min-height: 120px; }

/* empty state */
.ann-empty { text-align:center; padding:4rem 2rem; color:#475569; }
.ann-empty i { font-size:3.5rem; display:block; margin-bottom:1rem; opacity:.4; }

/* filter tabs */
.ann-filter-tabs { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.ann-filter-tab {
    padding:.35rem .85rem; border-radius:20px; font-size:.8rem; font-weight:600;
    border:1px solid rgba(51,65,85,.7); background:transparent; color:#64748b;
    cursor:pointer; transition:all .15s; text-decoration:none;
}
.ann-filter-tab:hover, .ann-filter-tab.active {
    background:rgba(99,102,241,.2); border-color:rgba(99,102,241,.5); color:#a5b4fc;
}
.ann-filter-tab[data-cat="urgent_alert"].active { background:rgba(239,68,68,.2); border-color:rgba(239,68,68,.4); color:#fca5a5; }
.ann-filter-tab[data-cat="deadline_reminder"].active { background:rgba(245,158,11,.2); border-color:rgba(245,158,11,.4); color:#fcd34d; }
.ann-filter-tab[data-cat="viva_notice"].active { background:rgba(139,92,246,.2); border-color:rgba(139,92,246,.4); color:#c4b5fd; }
.ann-filter-tab[data-cat="academic"].active { background:rgba(59,130,246,.2); border-color:rgba(59,130,246,.4); color:#93c5fd; }
.ann-filter-tab[data-cat="general"].active { background:rgba(34,197,94,.2); border-color:rgba(34,197,94,.4); color:#86efac; }
</style>

<div class="ann-page px-3 px-md-4">

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-3 mb-3"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success rounded-3 mb-3"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="ann-hero">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h1 class="ann-hero__title"><i class="bi bi-megaphone-fill me-2" style="color:#818cf8"></i>Announcements</h1>
                <p class="ann-hero__sub">Academic communication hub — stay informed with the latest updates and notices.</p>
            </div>
            <div class="d-flex gap-3 align-items-center flex-wrap">
                <div class="ann-stat-badge text-center">
                    <span><?= $stats_total ?></span>Total
                </div>
                <?php if ($can_manage): ?>
                <div class="ann-stat-badge text-center">
                    <span><?= $stats_active ?></span>Active
                </div>
                <div class="ann-stat-badge text-center">
                    <span><?= $stats_pinned ?></span>Pinned
                </div>
                <?php else: ?>
                <div class="ann-stat-badge text-center">
                    <span><?= $stats_unread ?></span>Unread
                </div>
                <?php endif; ?>
                <?php if ($can_manage): ?>
                    <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#createAnnModal" style="border-radius:10px;">
                        <i class="bi bi-plus-lg"></i> New Announcement
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($can_manage): ?>
    <!-- Management tabs -->
    <ul class="nav nav-tabs mb-4" id="annTabs" style="border-color:rgba(51,65,85,.5);">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-feed" style="color:#94a3b8;">
                <i class="bi bi-grid-3x3-gap me-1"></i>Feed
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-manage" style="color:#94a3b8;">
                <i class="bi bi-table me-1"></i>Manage
                <span class="badge bg-secondary ms-1" style="font-size:.7rem;"><?= $stats_total ?></span>
            </a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-feed">
    <?php endif; ?>

    <!-- Filter tabs -->
    <div class="ann-filter-tabs" id="ann-filters">
        <button class="ann-filter-tab active" data-cat="all">All</button>
        <?php foreach ($categories as $ckey => $cmeta): ?>
            <?php $cnt = count(array_filter($announcements, fn($a) => $a['category'] === $ckey)); ?>
            <?php if ($cnt > 0): ?>
            <button class="ann-filter-tab" data-cat="<?= $ckey ?>">
                <i class="bi <?= $cmeta['icon'] ?> me-1" style="color:<?= $cmeta['color'] ?>"></i>
                <?= $cmeta['label'] ?>
                <span class="ms-1 opacity-60"><?= $cnt ?></span>
            </button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Announcement cards -->
    <?php if (empty($announcements)): ?>
        <div class="ann-empty">
            <i class="bi bi-megaphone"></i>
            <p class="mb-0 fw-semibold" style="color:#475569;">No announcements yet.</p>
            <?php if ($can_manage): ?>
                <p class="small mt-1" style="color:#334155;">Click "New Announcement" to broadcast your first message.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3" id="ann-feed">
        <?php foreach ($announcements as $ann):
            $cat   = $ann['category'] ?? 'general';
            $pri   = $ann['priority'] ?? 'medium';
            $cmeta = $categories[$cat] ?? $categories['general'];
            $exp_label = '';
            $exp_cls   = '';
            if (!empty($ann['expires_at'])) {
                $diff = strtotime($ann['expires_at']) - time();
                if ($diff < 0) {
                    $exp_label = 'Expired'; $exp_cls = 'ann-expiry--expired';
                } elseif ($diff < 86400 * 3) {
                    $exp_label = 'Expires ' . date('M j', strtotime($ann['expires_at']));
                    $exp_cls = 'ann-expiry--soon';
                } else {
                    $exp_label = 'Until ' . date('M j, Y', strtotime($ann['expires_at']));
                    $exp_cls = 'ann-expiry--active';
                }
            }
            $is_read  = (bool)$ann['i_read'];
            $is_sched = !empty($ann['scheduled_at']) && strtotime($ann['scheduled_at']) > time();
            $is_draft = !$ann['is_active'];
        ?>
        <div class="col-12 col-md-6 col-xl-4 ann-item" data-cat="<?= $cat ?>"
             data-id="<?= (int)$ann['id'] ?>" id="ann-<?= (int)$ann['id'] ?>">
            <div class="ann-card ann-card--<?= $pri ?><?= $ann['is_pinned'] ? ' ann-card--pinned' : '' ?>">
                <?php if ($is_draft || $is_sched): ?>
                <div style="background:rgba(245,158,11,.1);padding:.3rem 1.2rem;font-size:.72rem;color:#fcd34d;border-bottom:1px solid rgba(245,158,11,.2);">
                    <i class="bi bi-<?= $is_draft ? 'eye-slash' : 'clock' ?> me-1"></i>
                    <?= $is_draft ? 'Draft (hidden from users)' : 'Scheduled: ' . date('M j, Y H:i', strtotime($ann['scheduled_at'])) ?>
                </div>
                <?php endif; ?>
                <div class="ann-card__header">
                    <div class="ann-cat-icon" style="background:<?= $cmeta['color'] ?>22;color:<?= $cmeta['color'] ?>;">
                        <i class="bi <?= $cmeta['icon'] ?>"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h3 class="ann-card__title"><?= e($ann['title']) ?></h3>
                        <div class="ann-card__meta">
                            <i class="bi bi-person me-1"></i><?= e($ann['author_name']) ?>
                            <span class="mx-1">·</span>
                            <?= date('M j, Y', strtotime($ann['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <div class="ann-card__body">
                    <p class="mb-0" style="display:-webkit-box;-webkit-line-clamp:3;line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                        <?= nl2br(e($ann['content'])) ?>
                    </p>
                    <?php if (mb_strlen($ann['content']) > 220): ?>
                        <a href="#" class="ann-expand-link" data-id="<?= (int)$ann['id'] ?>"
                           style="font-size:.78rem;color:#818cf8;text-decoration:none;" onclick="return annExpand(this)">
                            Read more <i class="bi bi-chevron-down"></i>
                        </a>
                        <div class="ann-full-content d-none"><?= nl2br(e($ann['content'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="ann-card__footer">
                    <span class="ann-pri ann-pri--<?= $pri ?>"><?= $priorities[$pri]['label'] ?></span>
                    <span class="ann-aud"><?= e($audiences[$ann['audience']] ?? $ann['audience']) ?>
                        <?php if ($ann['audience'] === 'department' && $ann['department']): ?>
                            : <?= e($ann['department']) ?>
                        <?php endif; ?>
                    </span>
                    <?php if (!$can_manage): ?>
                        <span class="ann-read-dot ann-read-dot--<?= $is_read ? 'read' : 'unread' ?>"
                              title="<?= $is_read ? 'Read' : 'Unread' ?>"></span>
                    <?php endif; ?>
                    <?php if (!empty($ann['attachment_path'])): ?>
                        <a href="<?= e(base_url($ann['attachment_path'])) ?>" target="_blank" class="ann-attach">
                            <i class="bi bi-paperclip"></i><?= e($ann['attachment_name'] ?: 'Attachment') ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($ann['link_url'])): ?>
                        <a href="<?= e($ann['link_url']) ?>" target="_blank" rel="noopener" class="ann-link">
                            <i class="bi bi-box-arrow-up-right"></i><?= e($ann['link_label'] ?: 'Link') ?>
                        </a>
                    <?php endif; ?>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <?php if ($exp_label): ?>
                            <span class="ann-expiry <?= $exp_cls ?>">
                                <i class="bi bi-hourglass-split me-1"></i><?= e($exp_label) ?>
                            </span>
                        <?php endif; ?>
                        <span class="ann-views">
                            <i class="bi bi-eye"></i> <?= (int)$ann['read_count'] ?>
                        </span>
                        <?php if ($can_manage): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                    style="font-size:.72rem;padding:.2rem .5rem;border-color:rgba(51,65,85,.7);">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" style="background:#1e293b;border-color:rgba(51,65,85,.7);">
                                <li>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="dropdown-item" style="color:#e2e8f0;">
                                            <i class="bi bi-<?= $ann['is_pinned'] ? 'pin-angle' : 'pin-fill' ?> me-2"></i>
                                            <?= $ann['is_pinned'] ? 'Unpin' : 'Pin to Top' ?>
                                        </button>
                                    </form>
                                </li>
                                <li>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="dropdown-item" style="color:#e2e8f0;">
                                            <i class="bi bi-<?= $ann['is_active'] ? 'eye-slash' : 'eye' ?> me-2"></i>
                                            <?= $ann['is_active'] ? 'Hide (Draft)' : 'Publish' ?>
                                        </button>
                                    </form>
                                </li>
                                <li><hr class="dropdown-divider" style="border-color:rgba(51,65,85,.5);"></li>
                                <li>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Delete this announcement?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="dropdown-item text-danger">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
        </div><!-- /tab-feed -->

        <!-- Manage tab -->
        <div class="tab-pane fade" id="tab-manage">
            <div class="ann-table-wrap">
                <table class="table ann-table mb-0">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Audience</th>
                            <th>Author</th>
                            <th>Posted</th>
                            <th>Expires</th>
                            <th>Views</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($announcements)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No announcements found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($announcements as $ann):
                            $cat   = $ann['category'] ?? 'general';
                            $pri   = $ann['priority'] ?? 'medium';
                            $cmeta = $categories[$cat] ?? $categories['general'];
                            $is_expired = !empty($ann['expires_at']) && strtotime($ann['expires_at']) < time();
                            $is_sched   = !empty($ann['scheduled_at']) && strtotime($ann['scheduled_at']) > time();
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold" style="color:#f1f5f9;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php if ($ann['is_pinned']): ?><i class="bi bi-pin-fill me-1" style="color:#fbbf24;font-size:.75rem;"></i><?php endif; ?>
                                    <?= e($ann['title']) ?>
                                </div>
                            </td>
                            <td>
                                <span style="color:<?= $cmeta['color'] ?>;font-size:.8rem;">
                                    <i class="bi <?= $cmeta['icon'] ?> me-1"></i><?= $cmeta['label'] ?>
                                </span>
                            </td>
                            <td><span class="ann-pri ann-pri--<?= $pri ?>"><?= $priorities[$pri]['label'] ?></span></td>
                            <td><span class="ann-aud"><?= e($audiences[$ann['audience']] ?? $ann['audience']) ?></span></td>
                            <td style="color:#94a3b8;"><?= e($ann['author_name']) ?></td>
                            <td style="color:#64748b;white-space:nowrap;"><?= date('M j, Y', strtotime($ann['created_at'])) ?></td>
                            <td>
                                <?php if (!empty($ann['expires_at'])): ?>
                                    <span class="<?= $is_expired ? 'text-danger' : 'text-success' ?>" style="font-size:.78rem;">
                                        <?= date('M j, Y', strtotime($ann['expires_at'])) ?>
                                        <?= $is_expired ? ' (Expired)' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ann-views"><i class="bi bi-eye"></i> <?= (int)$ann['read_count'] ?></span>
                            </td>
                            <td>
                                <?php if (!$ann['is_active']): ?>
                                    <span class="badge bg-secondary">Draft</span>
                                <?php elseif ($is_sched): ?>
                                    <span class="badge" style="background:rgba(245,158,11,.2);color:#fcd34d;border:1px solid rgba(245,158,11,.3);">Scheduled</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="badge bg-danger">Expired</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Live</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning" title="<?= $ann['is_pinned'] ? 'Unpin' : 'Pin' ?>"
                                                style="padding:.2rem .4rem;font-size:.75rem;">
                                            <i class="bi bi-pin<?= $ann['is_pinned'] ? '-angle' : '-fill' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" title="<?= $ann['is_active'] ? 'Hide' : 'Publish' ?>"
                                                style="padding:.2rem .4rem;font-size:.75rem;">
                                            <i class="bi bi-<?= $ann['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Delete this announcement?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="aid" value="<?= (int)$ann['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                                style="padding:.2rem .4rem;font-size:.75rem;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-content -->
    <?php endif; ?>

</div><!-- /ann-page -->

<?php if ($can_manage): ?>
<!-- ── Create Announcement Modal ─────────────────────────────────── -->
<div class="modal fade ann-modal" id="createAnnModal" tabindex="-1" aria-labelledby="createAnnModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createAnnModalLabel">
                    <i class="bi bi-broadcast me-2" style="color:#818cf8;"></i>New Announcement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data" id="createAnnForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-body px-4 py-3">
                    <div class="row g-3">
                        <!-- Title -->
                        <div class="col-12">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="Announcement title…" required maxlength="255">
                        </div>
                        <!-- Content -->
                        <div class="col-12">
                            <label class="form-label">Content <span class="text-danger">*</span></label>
                            <textarea name="content" class="form-control" placeholder="Write the announcement body…" required></textarea>
                        </div>
                        <!-- Category + Priority -->
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <?php foreach ($priorities as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Audience -->
                        <div class="col-md-6">
                            <label class="form-label">Target Audience</label>
                            <select name="audience" class="form-select" id="ann-audience-sel">
                                <?php foreach ($audiences as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="ann-dept-wrap" style="display:none;">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($dept_rows as $dr): ?>
                                    <option value="<?= e($dr['name']) ?>"><?= e($dr['name']) ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($dept_rows)): ?>
                                    <option value="<?= e($user['department'] ?? '') ?>"><?= e($user['department'] ?? 'My Department') ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <!-- Schedule + Expiry -->
                        <div class="col-md-6">
                            <label class="form-label">Schedule Post <small class="opacity-60">(optional)</small></label>
                            <input type="datetime-local" name="scheduled_at" class="form-control" id="ann-sched-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date <small class="opacity-60">(optional)</small></label>
                            <input type="datetime-local" name="expires_at" class="form-control" id="ann-expiry-input">
                        </div>
                        <!-- Link -->
                        <div class="col-md-8">
                            <label class="form-label">Link URL <small class="opacity-60">(optional)</small></label>
                            <input type="url" name="link_url" class="form-control" placeholder="https://…">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Link Label</label>
                            <input type="text" name="link_label" class="form-control" placeholder="e.g. View Schedule">
                        </div>
                        <!-- Attachment -->
                        <div class="col-12">
                            <label class="form-label">Attach File <small class="opacity-60">(PDF, DOC, image — max 20 MB)</small></label>
                            <input type="file" name="attachment" class="form-control"
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip">
                        </div>
                        <!-- Pin -->
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_pinned" class="form-check-input" id="annPinCheck" value="1">
                                <label class="form-check-label" for="annPinCheck" style="color:#94a3b8;">
                                    <i class="bi bi-pin-fill me-1" style="color:#fbbf24;"></i>Pin this announcement to top
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-send me-1"></i>Publish
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Category filter ── */
document.querySelectorAll('.ann-filter-tab').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.ann-filter-tab').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const cat = this.dataset.cat;
        document.querySelectorAll('.ann-item').forEach(item => {
            item.style.display = (cat === 'all' || item.dataset.cat === cat) ? '' : 'none';
        });
    });
});

/* ── Expand / collapse long content ── */
function annExpand(link) {
    const card = link.closest('.ann-card');
    const full  = card.querySelector('.ann-full-content');
    const short = card.querySelector('.ann-card__body > p');
    if (full.classList.contains('d-none')) {
        full.classList.remove('d-none');
        short.style.display = 'none';
        link.innerHTML = 'Show less <i class="bi bi-chevron-up"></i>';
    } else {
        full.classList.add('d-none');
        short.style.display = '';
        link.innerHTML = 'Read more <i class="bi bi-chevron-down"></i>';
    }
    return false;
}

<?php if ($can_manage): ?>
/* ── Department field toggle ── */
const audSel   = document.getElementById('ann-audience-sel');
const deptWrap = document.getElementById('ann-dept-wrap');
function toggleDept() {
    deptWrap.style.display = audSel.value === 'department' ? '' : 'none';
}
audSel?.addEventListener('change', toggleDept);

/* ── Set min date = now when modal opens ── */
function setDateMins() {
    const now = new Date();
    /* datetime-local min format: YYYY-MM-DDTHH:MM */
    const pad = n => String(n).padStart(2,'0');
    const minVal = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate())
                 + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    const sched  = document.getElementById('ann-sched-input');
    const expiry = document.getElementById('ann-expiry-input');
    if (sched)  sched.min  = minVal;
    if (expiry) expiry.min = minVal;
    /* expiry must also be >= schedule if schedule is set */
    sched?.addEventListener('change', () => {
        if (expiry && sched.value) expiry.min = sched.value;
    });
}
document.getElementById('createAnnModal')?.addEventListener('show.bs.modal', setDateMins);
setDateMins(); /* also run immediately in case modal is pre-open */
<?php endif; ?>

/* ── Mark announcements as read (AJAX) ── */
document.querySelectorAll('.ann-item[data-id]').forEach(card => {
    const aid = card.dataset.id;
    const dot = card.querySelector('.ann-read-dot--unread');
    if (!dot) return;
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                obs.disconnect();
                fetch('<?= base_url('api/announcement_read.php') ?>', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'csrf_token=<?= csrf_token() ?>&aid=' + aid,
                    credentials:'same-origin'
                }).then(r => r.json()).then(d => {
                    if (d.ok) { dot.classList.replace('ann-read-dot--unread', 'ann-read-dot--read'); dot.title='Read'; }
                });
            }
        });
    }, { threshold: 0.5 });
    obs.observe(card);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
