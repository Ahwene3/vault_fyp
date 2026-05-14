<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
ensure_audit_logs_table($pdo);

/* ─── Action metadata ────────────────────────────────────────────── */
$action_meta = [
    'login'                  => ['Login',                 'bi-box-arrow-in-right', '#22d3ee',  'auth'],
    'logout'                 => ['Logout',                'bi-box-arrow-right',    '#94a3b8',  'auth'],
    'login_failed'           => ['Login Failed',          'bi-shield-x',           '#ef4444',  'auth'],
    'login_blocked'          => ['Login Blocked',         'bi-ban',                '#f97316',  'auth'],
    'user_archive'           => ['User Archived',         'bi-archive',            '#f59e0b',  'user_management'],
    'user_restore'           => ['User Restored',         'bi-arrow-counterclockwise','#22c55e','user_management'],
    'user_permanent_archive' => ['Permanently Archived',  'bi-trash3',             '#ef4444',  'user_management'],
    'user_update'            => ['User Updated',          'bi-pencil',             '#3b82f6',  'user_management'],
    'topic_approve'          => ['Topic Approved',        'bi-check-circle',       '#22c55e',  'project'],
    'topic_reject'           => ['Topic Rejected',        'bi-x-circle',           '#ef4444',  'project'],
    'supervisor_assign'      => ['Supervisor Assigned',   'bi-person-check',       '#8b5cf6',  'project'],
    'announcement_create'    => ['Announcement Posted',   'bi-megaphone',          '#3b82f6',  'announcement'],
    'announcement_delete'    => ['Announcement Deleted',  'bi-megaphone',          '#ef4444',  'announcement'],
    'document_upload'        => ['Document Uploaded',     'bi-cloud-upload',       '#22c55e',  'document'],
    'document_delete'        => ['Document Deleted',      'bi-file-x',             '#ef4444',  'document'],
];

$severity_meta = [
    'info'     => ['Info',     'bg-info',    '#22d3ee'],
    'warning'  => ['Warning',  'bg-warning', '#f59e0b'],
    'critical' => ['Critical', 'bg-danger',  '#ef4444'],
];

$category_labels = [
    'auth'            => 'Authentication',
    'user_management' => 'User Management',
    'project'         => 'Projects',
    'document'        => 'Documents',
    'announcement'    => 'Announcements',
    'security'        => 'Security',
    'system'          => 'System',
    'content'         => 'Content',
];

/* ─── CSV export ─────────────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Timestamp','User','Role','Department','Action','Category','Target','Details','IP Address','Browser','Severity']);
    $exp = $pdo->query(
        'SELECT id,created_at,user_name,user_role,user_dept,action,category,target_label,details,ip_address,user_agent,severity
         FROM audit_logs ORDER BY created_at DESC LIMIT 50000'
    );
    while ($row = $exp->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ─── Filters ────────────────────────────────────────────────────── */
$f_role     = trim($_GET['role']      ?? '');
$f_action   = trim($_GET['action']    ?? '');
$f_severity = trim($_GET['severity']  ?? '');
$f_from     = trim($_GET['date_from'] ?? '');
$f_to       = trim($_GET['date_to']   ?? '');
$f_dept     = trim($_GET['dept']      ?? '');
$f_uid      = (int) ($_GET['uid']     ?? 0);
$f_ip       = trim($_GET['ip']        ?? '');
$page       = max(1, (int) ($_GET['p'] ?? 1));
$per_page   = 50;

/* ─── Build WHERE clause ─────────────────────────────────────────── */
$where = []; $params = [];
if ($f_role)     { $where[] = 'user_role = ?';                      $params[] = $f_role; }
if ($f_action)   { $where[] = 'action = ?';                         $params[] = $f_action; }
if ($f_severity) { $where[] = 'severity = ?';                       $params[] = $f_severity; }
if ($f_from)     { $where[] = 'created_at >= ?';                    $params[] = $f_from . ' 00:00:00'; }
if ($f_to)       { $where[] = 'created_at <= ?';                    $params[] = $f_to   . ' 23:59:59'; }
if ($f_dept)     { $where[] = 'user_dept LIKE ?';                   $params[] = '%' . $f_dept . '%'; }
if ($f_uid)      { $where[] = 'user_id = ?';                        $params[] = $f_uid; }
if ($f_ip)       { $where[] = 'ip_address LIKE ?';                  $params[] = '%' . $f_ip . '%'; }
$w_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ─── Pagination count ───────────────────────────────────────────── */
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $w_sql");
$count_stmt->execute($params);
$total_rows = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
$offset = ($page - 1) * $per_page;

/* ─── Main logs query ────────────────────────────────────────────── */
$logs_stmt = $pdo->prepare(
    "SELECT id,created_at,user_id,user_name,user_role,user_dept,action,category,
            target_type,target_id,target_label,details,ip_address,user_agent,severity
     FROM audit_logs $w_sql
     ORDER BY created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$logs_stmt->execute($params);
$logs = $logs_stmt->fetchAll();

/* ─── Analytics stats ────────────────────────────────────────────── */
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

$stat_total   = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$stat_today   = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)='$today'")->fetchColumn();
$stat_failed  = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='login_failed'")->fetchColumn();
$stat_crit    = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE severity='critical'")->fetchColumn();

$top_user_row = $pdo->query(
    "SELECT user_name, COUNT(*) as c FROM audit_logs
     WHERE user_name IS NOT NULL GROUP BY user_name ORDER BY c DESC LIMIT 1"
)->fetch();

$top_action_row = $pdo->query(
    "SELECT action, COUNT(*) as c FROM audit_logs GROUP BY action ORDER BY c DESC LIMIT 1"
)->fetch();

/* ─── Security: failed logins by IP (last 24 h) ──────────────────── */
$failed_by_ip = $pdo->query(
    "SELECT ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_attempt,
            GROUP_CONCAT(DISTINCT target_label ORDER BY created_at DESC SEPARATOR ', ') AS emails
     FROM audit_logs
     WHERE action='login_failed'
       AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     GROUP BY ip_address
     ORDER BY attempts DESC
     LIMIT 10"
)->fetchAll();

/* ─── Security: accounts with 3+ failed logins (all time) ───────── */
$suspicious_accounts = $pdo->query(
    "SELECT target_label AS email, COUNT(*) AS attempts, MAX(created_at) AS last_attempt
     FROM audit_logs
     WHERE action='login_failed' AND target_label IS NOT NULL
     GROUP BY target_label
     HAVING attempts >= 3
     ORDER BY attempts DESC
     LIMIT 8"
)->fetchAll();

/* ─── Timeline: last 15 events ───────────────────────────────────── */
$timeline = $pdo->query(
    "SELECT id,created_at,action,user_name,user_role,target_label,severity,ip_address
     FROM audit_logs ORDER BY created_at DESC LIMIT 15"
)->fetchAll();

/* ─── Unique action types for filter dropdown ────────────────────── */
$action_options = $pdo->query(
    "SELECT DISTINCT action FROM audit_logs ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);

/* ─── Active users for filter dropdown ───────────────────────────── */
$user_options = $pdo->query(
    "SELECT DISTINCT user_id, user_name FROM audit_logs
     WHERE user_id IS NOT NULL AND user_name IS NOT NULL
     ORDER BY user_name LIMIT 100"
)->fetchAll();

/* ─── Page render ────────────────────────────────────────────────── */
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ══ Audit Logs Dashboard ══════════════════════════════════════════ */
:root {
    --al-bg:      #030a18;
    --al-surface: rgba(8,20,40,.85);
    --al-border:  rgba(30,58,95,.7);
    --al-glow:    rgba(34,211,238,.18);
    --al-blue:    #22d3ee;
    --al-red:     #ef4444;
    --al-amber:   #f59e0b;
    --al-green:   #22c55e;
}

.al-page { padding:1.5rem 0; }

/* hero */
.al-hero {
    background: linear-gradient(135deg,rgba(3,10,24,.97) 0%,rgba(8,20,48,.98) 100%);
    border:1px solid var(--al-border);
    border-radius:16px; padding:1.8rem 2rem; margin-bottom:1.75rem;
    position:relative; overflow:hidden;
}
.al-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background: radial-gradient(ellipse at 90% 0%,rgba(34,211,238,.08) 0%,transparent 55%),
                radial-gradient(ellipse at 10% 100%,rgba(99,102,241,.06) 0%,transparent 50%);
}
.al-hero__title { font-size:1.55rem; font-weight:800; color:#f1f5f9;
    letter-spacing:-.02em; display:flex; align-items:center; gap:.6rem; }
.al-hero__sub { color:#475569; font-size:.82rem; margin:.25rem 0 0; }
.al-scan-line {
    position:absolute; bottom:0; left:0; right:0; height:1px;
    background: linear-gradient(90deg,transparent 0%,var(--al-blue) 50%,transparent 100%);
    animation: scan-move 3s ease-in-out infinite;
}
@keyframes scan-move { 0%,100%{opacity:.3;transform:scaleX(.3)} 50%{opacity:1;transform:scaleX(1)} }

/* ── stat cards ── */
.al-stat {
    background:var(--al-surface); border:1px solid var(--al-border);
    border-radius:14px; padding:1.25rem 1.4rem; position:relative; overflow:hidden;
    transition:border-color .2s,box-shadow .2s;
}
.al-stat:hover { box-shadow:0 0 24px var(--al-glow); border-color:rgba(34,211,238,.3); }
.al-stat__label { font-size:.72rem; font-weight:700; letter-spacing:.08em;
    text-transform:uppercase; color:#475569; margin-bottom:.35rem; }
.al-stat__value { font-size:2.1rem; font-weight:800; color:#f1f5f9; line-height:1; }
.al-stat__sub   { font-size:.75rem; color:#334155; margin-top:.3rem; }
.al-stat__icon  { position:absolute; right:1.2rem; top:50%; transform:translateY(-50%);
    font-size:2rem; opacity:.12; }
.al-stat--danger { border-color:rgba(239,68,68,.3); }
.al-stat--danger:hover { box-shadow:0 0 24px rgba(239,68,68,.15); }
.al-stat--danger .al-stat__value { color:#f87171; }
.al-stat--warn   { border-color:rgba(245,158,11,.25); }
.al-stat--warn .al-stat__value { color:#fcd34d; }
.al-stat--ok .al-stat__value { color:#4ade80; }

/* ── security panel ── */
.al-sec-panel {
    background:rgba(8,15,30,.9); border:1px solid rgba(239,68,68,.25);
    border-radius:14px; overflow:hidden; height:100%;
}
.al-sec-panel__head {
    padding:.85rem 1.1rem; background:rgba(239,68,68,.08);
    border-bottom:1px solid rgba(239,68,68,.2);
    display:flex; align-items:center; gap:.5rem;
}
.al-sec-panel__title { font-size:.82rem; font-weight:700; color:#f87171;
    text-transform:uppercase; letter-spacing:.08em; }
.al-sec-row { padding:.65rem 1.1rem; border-bottom:1px solid rgba(30,58,95,.4);
    display:flex; align-items:center; gap:.75rem; font-size:.8rem; }
.al-sec-row:last-child { border-bottom:none; }
.al-sec-row:hover { background:rgba(239,68,68,.04); }
.al-sec-ip   { font-family:monospace; color:#fca5a5; font-size:.78rem; flex-shrink:0; }
.al-sec-cnt  { background:rgba(239,68,68,.2); color:#f87171; font-size:.68rem;
    font-weight:800; padding:.1rem .4rem; border-radius:4px; }
.al-sec-time { font-size:.68rem; color:#475569; white-space:nowrap; }
.al-sec-empty { padding:2rem; text-align:center; color:#1e3a5f; font-size:.83rem; }

/* ── timeline ── */
.al-timeline { position:relative; }
.al-tl-item {
    display:flex; gap:.75rem; padding:.55rem 0;
    border-bottom:1px solid rgba(30,58,95,.3); position:relative;
}
.al-tl-item:last-child { border-bottom:none; }
.al-tl-dot {
    width:28px; height:28px; border-radius:8px; display:flex; align-items:center;
    justify-content:center; font-size:.75rem; flex-shrink:0; margin-top:.1rem;
}
.al-tl-body  { flex:1; min-width:0; }
.al-tl-action { font-size:.8rem; font-weight:600; color:#e2e8f0; }
.al-tl-meta   { font-size:.7rem; color:#475569; margin-top:.1rem; }
.al-tl-ts     { font-size:.68rem; color:#334155; white-space:nowrap; flex-shrink:0; }

/* ── filter bar ── */
.al-filter-bar {
    background:var(--al-surface); border:1px solid var(--al-border);
    border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.25rem;
}
.al-filter-bar .form-control, .al-filter-bar .form-select {
    background:rgba(3,10,24,.8); border:1px solid rgba(30,58,95,.8);
    color:#e2e8f0; font-size:.8rem; border-radius:8px;
}
.al-filter-bar .form-control:focus, .al-filter-bar .form-select:focus {
    background:rgba(3,10,24,.95); border-color:rgba(34,211,238,.4);
    box-shadow:0 0 0 3px rgba(34,211,238,.1); color:#f1f5f9;
}
.al-filter-bar .form-control::placeholder { color:#1e3a5f; }
.al-filter-bar .form-select option { background:#0d1b2e; color:#e2e8f0; }
.al-filter-bar label { color:#475569; font-size:.73rem; font-weight:600;
    text-transform:uppercase; letter-spacing:.06em; }

/* ── main table ── */
.al-table-wrap {
    background:var(--al-surface); border:1px solid var(--al-border);
    border-radius:14px; overflow:hidden;
}
.al-table thead th {
    background:rgba(8,15,30,.95); color:#334155; font-size:.7rem;
    font-weight:700; letter-spacing:.1em; text-transform:uppercase;
    padding:.8rem 1rem; border-bottom:1px solid var(--al-border); white-space:nowrap;
}
.al-table tbody td {
    padding:.65rem 1rem; border-bottom:1px solid rgba(30,58,95,.3);
    font-size:.8rem; color:#94a3b8; vertical-align:middle;
}
.al-table tbody tr:last-child td { border-bottom:none; }
.al-table tbody tr { transition:background .12s; }
.al-table tbody tr:hover td { background:rgba(34,211,238,.03); }
.al-table tbody tr.row--critical td { background:rgba(239,68,68,.04); }
.al-table tbody tr.row--critical:hover td { background:rgba(239,68,68,.07); }
.al-table tbody tr.row--warning td { background:rgba(245,158,11,.03); }

/* inline badges */
.sev-badge { display:inline-block; font-size:.65rem; font-weight:700; letter-spacing:.05em;
    padding:.15rem .45rem; border-radius:4px; text-transform:uppercase; }
.sev-info     { background:rgba(34,211,238,.12); color:#67e8f9; border:1px solid rgba(34,211,238,.2); }
.sev-warning  { background:rgba(245,158,11,.15); color:#fcd34d; border:1px solid rgba(245,158,11,.25); }
.sev-critical { background:rgba(239,68,68,.18); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }

.role-badge { display:inline-block; font-size:.65rem; font-weight:700; letter-spacing:.04em;
    padding:.15rem .5rem; border-radius:4px; text-transform:uppercase; }
.role-admin      { background:rgba(139,92,246,.2); color:#c4b5fd; }
.role-hod        { background:rgba(59,130,246,.2); color:#93c5fd; }
.role-supervisor { background:rgba(34,197,94,.2);  color:#86efac; }
.role-student    { background:rgba(251,191,36,.2);  color:#fde68a; }

.action-pill { display:inline-flex; align-items:center; gap:.3rem; font-size:.75rem; font-weight:600; }

.ip-mono { font-family:monospace; font-size:.75rem; color:#64748b; }

/* pagination */
.al-page-nav .page-link {
    background:rgba(8,15,30,.9); border-color:var(--al-border); color:#475569;
    font-size:.78rem;
}
.al-page-nav .page-link:hover { background:rgba(34,211,238,.1); color:var(--al-blue); border-color:rgba(34,211,238,.3); }
.al-page-nav .page-item.active .page-link { background:rgba(34,211,238,.15); border-color:rgba(34,211,238,.4); color:var(--al-blue); }
.al-page-nav .page-item.disabled .page-link { opacity:.35; }

/* export buttons */
.al-export-btn {
    display:inline-flex; align-items:center; gap:.4rem; font-size:.78rem; font-weight:600;
    padding:.4rem .9rem; border-radius:8px; text-decoration:none; transition:all .15s;
}
.al-export-csv  { background:rgba(34,197,94,.15);  color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.al-export-csv:hover  { background:rgba(34,197,94,.25);  color:#86efac; }
.al-export-print { background:rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.3); }
.al-export-print:hover { background:rgba(59,130,246,.25); color:#bfdbfe; }

/* print styles */
@media print {
    .al-filter-bar, .al-export-btn, .al-hero, nav, .app-sidebar { display:none !important; }
    .al-table-wrap { border:none; }
    .al-table tbody td, .al-table thead th { color:#000 !important; background:#fff !important; }
}
</style>

<div class="al-page px-3 px-md-4">

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<div class="al-hero mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="al-hero__title">
                <i class="bi bi-shield-lock-fill" style="color:var(--al-blue);"></i>
                Audit Logs
                <span style="font-size:.75rem;font-weight:500;color:#475569;letter-spacing:.04em;margin-left:.25rem;">ADMIN CONSOLE</span>
            </h1>
            <p class="al-hero__sub">Full system activity trail — monitor, investigate, and export platform events in real time.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="?export=csv<?= $f_role ? '&role='.urlencode($f_role) : '' ?><?= $f_action ? '&action='.urlencode($f_action) : '' ?>" class="al-export-btn al-export-csv">
                <i class="bi bi-download"></i>Export CSV
            </a>
            <button onclick="window.print()" class="al-export-btn al-export-print">
                <i class="bi bi-printer"></i>Print
            </button>
            <span style="font-size:.72rem;color:#1e3a5f;font-family:monospace;"><?= date('Y-m-d H:i:s') ?></span>
        </div>
    </div>
    <div class="al-scan-line"></div>
</div>

<!-- ── Stats cards ───────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="al-stat">
            <div class="al-stat__label">Total Events</div>
            <div class="al-stat__value" id="cnt-total"><?= number_format($stat_total) ?></div>
            <div class="al-stat__sub"><?= number_format($stat_today) ?> today</div>
            <i class="bi bi-activity al-stat__icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="al-stat al-stat--danger">
            <div class="al-stat__label">Failed Logins</div>
            <div class="al-stat__value"><?= number_format($stat_failed) ?></div>
            <div class="al-stat__sub">all time</div>
            <i class="bi bi-shield-x al-stat__icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="al-stat al-stat--warn">
            <div class="al-stat__label">Critical Events</div>
            <div class="al-stat__value"><?= number_format($stat_crit) ?></div>
            <div class="al-stat__sub">permanent actions</div>
            <i class="bi bi-exclamation-triangle al-stat__icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="al-stat al-stat--ok">
            <div class="al-stat__label">Most Active User</div>
            <div class="al-stat__value" style="font-size:1.1rem;padding-top:.2rem;">
                <?= $top_user_row ? e(mb_substr($top_user_row['user_name'],0,18)) : '—' ?>
            </div>
            <div class="al-stat__sub">
                Top action: <strong style="color:#94a3b8;"><?= $top_action_row ? e($top_action_row['action']) : '—' ?></strong>
            </div>
            <i class="bi bi-person-badge al-stat__icon"></i>
        </div>
    </div>
</div>

<!-- ── Security Monitor + Timeline ──────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Security panel -->
    <div class="col-lg-5">
        <div class="al-sec-panel h-100">
            <div class="al-sec-panel__head">
                <i class="bi bi-radioactive" style="color:#ef4444;font-size:1rem;"></i>
                <span class="al-sec-panel__title">Security Monitor</span>
                <span class="ms-auto" style="font-size:.68rem;color:#7f1d1d;font-family:monospace;">LIVE · 24H</span>
            </div>

            <?php if (!empty($failed_by_ip)): ?>
                <div style="padding:.5rem 1.1rem .3rem; font-size:.68rem; color:#7f1d1d; text-transform:uppercase; letter-spacing:.08em;">
                    Failed Login IPs — Last 24 Hours
                </div>
                <?php foreach ($failed_by_ip as $fi): ?>
                <div class="al-sec-row">
                    <i class="bi bi-geo-alt-fill" style="color:#7f1d1d;font-size:.8rem;flex-shrink:0;"></i>
                    <span class="al-sec-ip"><?= e($fi['ip_address']) ?></span>
                    <span class="al-sec-cnt"><?= (int)$fi['attempts'] ?>×</span>
                    <div class="flex-grow-1 min-w-0">
                        <div style="font-size:.7rem;color:#7f1d1d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="<?= e($fi['emails']) ?>">
                            <?= e(mb_substr($fi['emails'] ?? '', 0, 40)) ?>
                        </div>
                    </div>
                    <span class="al-sec-time"><?= date('H:i', strtotime($fi['last_attempt'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="al-sec-empty">
                    <i class="bi bi-shield-check" style="font-size:1.6rem;color:#14532d;display:block;margin-bottom:.4rem;"></i>
                    No failed logins in the last 24 hours
                </div>
            <?php endif; ?>

            <?php if (!empty($suspicious_accounts)): ?>
                <div style="padding:.5rem 1.1rem .3rem; font-size:.68rem; color:#92400e; text-transform:uppercase; letter-spacing:.08em; border-top:1px solid rgba(239,68,68,.15);">
                    Accounts with 3+ Failed Attempts
                </div>
                <?php foreach ($suspicious_accounts as $sa): ?>
                <div class="al-sec-row">
                    <i class="bi bi-person-x-fill" style="color:#92400e;font-size:.8rem;flex-shrink:0;"></i>
                    <span style="font-size:.75rem;color:#fca5a5;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                          title="<?= e($sa['email']) ?>"><?= e(mb_substr($sa['email'],0,28)) ?></span>
                    <span class="al-sec-cnt" style="background:rgba(245,158,11,.2);color:#fcd34d;"><?= (int)$sa['attempts'] ?>×</span>
                    <span class="al-sec-time"><?= date('M j', strtotime($sa['last_attempt'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Activity timeline -->
    <div class="col-lg-7">
        <div class="al-sec-panel" style="border-color:rgba(34,211,238,.2);">
            <div class="al-sec-panel__head" style="background:rgba(34,211,238,.06);border-bottom-color:rgba(34,211,238,.15);">
                <i class="bi bi-clock-history" style="color:var(--al-blue);font-size:1rem;"></i>
                <span class="al-sec-panel__title" style="color:#67e8f9;">Recent Activity</span>
                <span class="ms-auto" style="font-size:.68rem;color:#164e63;font-family:monospace;">LAST 15 EVENTS</span>
            </div>
            <div style="padding:.5rem 1rem;">
                <div class="al-timeline">
                    <?php foreach ($timeline as $tl):
                        $tm  = $action_meta[$tl['action']] ?? ['Unknown','bi-question','#475569','system'];
                        $clr = $tm[2];
                        $ico = $tm[1];
                    ?>
                    <div class="al-tl-item">
                        <div class="al-tl-dot" style="background:<?= $clr ?>18;color:<?= $clr ?>;">
                            <i class="bi <?= $ico ?>"></i>
                        </div>
                        <div class="al-tl-body">
                            <div class="al-tl-action"><?= e($tm[0]) ?>
                                <?php if ($tl['severity'] === 'critical'): ?>
                                    <span class="sev-badge sev-critical ms-1">Critical</span>
                                <?php elseif ($tl['severity'] === 'warning'): ?>
                                    <span class="sev-badge sev-warning ms-1">Warning</span>
                                <?php endif; ?>
                            </div>
                            <div class="al-tl-meta">
                                <?php if ($tl['user_name']): ?>
                                    <i class="bi bi-person me-1"></i><?= e($tl['user_name']) ?>
                                    <?php if ($tl['user_role']): ?>
                                        <span class="role-badge role-<?= e($tl['user_role']) ?> ms-1"><?= e($tl['user_role']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="al-sec-ip"><?= e($tl['ip_address']) ?></span>
                                <?php endif; ?>
                                <?php if ($tl['target_label']): ?>
                                    <span class="mx-1">·</span><?= e(mb_substr($tl['target_label'],0,25)) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="al-tl-ts"><?= date('M j H:i', strtotime($tl['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($timeline)): ?>
                        <div class="al-sec-empty">No activity logged yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────── -->
<form method="get" class="al-filter-bar mb-3" id="filter-form">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label>Role</label>
            <select name="role" class="form-select form-select-sm">
                <option value="">All Roles</option>
                <?php foreach (['admin','hod','supervisor','student'] as $r): ?>
                    <option value="<?= $r ?>" <?= $f_role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label>Action</label>
            <select name="action" class="form-select form-select-sm">
                <option value="">All Actions</option>
                <?php foreach ($action_options as $ao): ?>
                    <option value="<?= e($ao) ?>" <?= $f_action === $ao ? 'selected' : '' ?>><?= e($ao) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label>Severity</label>
            <select name="severity" class="form-select form-select-sm">
                <option value="">All Severity</option>
                <option value="info"     <?= $f_severity==='info'     ? 'selected':'' ?>>Info</option>
                <option value="warning"  <?= $f_severity==='warning'  ? 'selected':'' ?>>Warning</option>
                <option value="critical" <?= $f_severity==='critical' ? 'selected':'' ?>>Critical</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label>From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label>To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label>IP Address</label>
            <input type="text" name="ip" class="form-control form-control-sm" placeholder="e.g. 192.168…" value="<?= e($f_ip) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label>Department</label>
            <input type="text" name="dept" class="form-control form-control-sm" placeholder="Department name…" value="<?= e($f_dept) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label>Specific User</label>
            <select name="uid" class="form-select form-select-sm">
                <option value="">All Users</option>
                <?php foreach ($user_options as $uo): ?>
                    <option value="<?= (int)$uo['user_id'] ?>" <?= $f_uid===(int)$uo['user_id'] ? 'selected':'' ?>>
                        <?= e($uo['user_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-grow-1" style="font-size:.78rem;">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
            <a href="admin/audit_logs.php" class="btn btn-sm btn-outline-secondary" style="font-size:.78rem;">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end align-items-end">
            <span style="font-size:.75rem;color:#334155;">
                <?= number_format($total_rows) ?> result<?= $total_rows !== 1 ? 's' : '' ?>
            </span>
        </div>
    </div>
</form>

<!-- ── Main logs table ─────────────────────────────────────────────── -->
<div class="al-table-wrap">
    <table class="table al-table mb-0">
        <thead>
            <tr>
                <th>#</th>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Target</th>
                <th>IP Address</th>
                <th>Browser</th>
                <th>Severity</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
            <tr><td colspan="9" class="text-center py-5" style="color:#1e3a5f;">
                <i class="bi bi-database-x" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No logs match the current filters.
            </td></tr>
        <?php else: ?>
            <?php foreach ($logs as $log):
                $am  = $action_meta[$log['action']] ?? ['', 'bi-circle', '#475569', ''];
                $row_cls = match($log['severity']) {
                    'critical' => 'row--critical',
                    'warning'  => 'row--warning',
                    default    => '',
                };
            ?>
            <tr class="<?= $row_cls ?>">
                <td style="color:#1e3a5f;font-size:.72rem;font-family:monospace;"><?= (int)$log['id'] ?></td>
                <td style="white-space:nowrap;">
                    <span style="color:#e2e8f0;font-size:.78rem;"><?= date('M j, Y', strtotime($log['created_at'])) ?></span>
                    <br>
                    <span style="color:#475569;font-size:.7rem;font-family:monospace;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                </td>
                <td>
                    <?php if ($log['user_name']): ?>
                        <span style="color:#e2e8f0;font-size:.8rem;font-weight:600;"><?= e($log['user_name']) ?></span>
                        <br>
                        <?php if ($log['user_role']): ?>
                            <span class="role-badge role-<?= e($log['user_role']) ?>"><?= e($log['user_role']) ?></span>
                        <?php endif; ?>
                        <?php if ($log['user_dept']): ?>
                            <span style="font-size:.65rem;color:#334155;display:block;margin-top:.15rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px;"
                                  title="<?= e($log['user_dept']) ?>"><?= e(mb_substr($log['user_dept'],0,14)) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#334155;font-size:.75rem;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="action-pill" style="color:<?= $am[2] ?>;">
                        <i class="bi <?= $am[1] ?>"></i>
                        <?= e($am[0] ?: $log['action']) ?>
                    </span>
                    <?php if ($log['category'] && isset($category_labels[$log['category']])): ?>
                        <br><span style="font-size:.65rem;color:#1e3a5f;"><?= $category_labels[$log['category']] ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($log['target_label']): ?>
                        <span style="color:#64748b;font-size:.78rem;"><?= e(mb_substr($log['target_label'],0,30)) ?></span>
                    <?php elseif ($log['target_type']): ?>
                        <span style="color:#334155;font-size:.75rem;"><?= e($log['target_type']) ?> #<?= (int)$log['target_id'] ?></span>
                    <?php else: ?>
                        <span style="color:#1e3a5f;">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="ip-mono"><?= e($log['ip_address'] ?? '—') ?></span></td>
                <td><span style="font-size:.75rem;color:#475569;"><?= e($log['user_agent'] ?? '—') ?></span></td>
                <td>
                    <span class="sev-badge sev-<?= $log['severity'] ?>"><?= $severity_meta[$log['severity']]['label'] ?></span>
                </td>
                <td style="max-width:180px;">
                    <?php if ($log['details']): ?>
                        <span style="font-size:.73rem;color:#475569;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                              title="<?= e($log['details']) ?>"><?= e(mb_substr($log['details'],0,40)) ?></span>
                    <?php else: ?>
                        <span style="color:#1e3a5f;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── Pagination ─────────────────────────────────────────────────── -->
<?php if ($total_pages > 1): ?>
<nav class="mt-3 d-flex align-items-center justify-content-between al-page-nav">
    <span style="font-size:.75rem;color:#334155;">
        Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> records
    </span>
    <ul class="pagination pagination-sm mb-0">
        <?php
        $base_qs = http_build_query(array_filter([
            'role'=>$f_role,'action'=>$f_action,'severity'=>$f_severity,
            'date_from'=>$f_from,'date_to'=>$f_to,'dept'=>$f_dept,
            'uid'=>$f_uid?:'','ip'=>$f_ip,
        ]));
        $pg_url = fn(int $pg) => '?' . $base_qs . ($base_qs ? '&' : '') . 'p=' . $pg;
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $pg_url(1) ?>"><i class="bi bi-chevron-double-left"></i></a>
        </li>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $pg_url($page-1) ?>"><i class="bi bi-chevron-left"></i></a>
        </li>
        <?php for ($pg = $start; $pg <= $end; $pg++): ?>
            <li class="page-item <?= $pg===$page?'active':'' ?>">
                <a class="page-link" href="<?= $pg_url($pg) ?>"><?= $pg ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
            <a class="page-link" href="<?= $pg_url($page+1) ?>"><i class="bi bi-chevron-right"></i></a>
        </li>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
            <a class="page-link" href="<?= $pg_url($total_pages) ?>"><i class="bi bi-chevron-double-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>

</div><!-- /al-page -->

<script>
/* Live clock in hero */
(function() {
    const el = document.querySelector('.al-hero [style*="monospace"]');
    if (!el) return;
    setInterval(() => {
        const d = new Date();
        el.textContent = d.toISOString().replace('T',' ').slice(0,19);
    }, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
