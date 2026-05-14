<?php
/**
 * Dashboard announcement widget — include after getPDO() and $uid/$role are set.
 * Shows up to 4 active, visible announcements (pinned first).
 */
if (!isset($pdo) || !isset($uid) || !isset($role)) return;

ensure_announcements_tables($pdo);

$now_w = date('Y-m-d H:i:s');
$aw_dept = (string) ($user['department'] ?? '');

if (in_array($role, ['admin', 'hod'], true)) {
    $aw_where  = '1=1';
    $aw_params = [];
} else {
    $aw_aud = ["'all'"];
    if ($role === 'student')     { $aw_aud[] = "'students'"; $aw_aud[] = "'students_supervisors'"; }
    if ($role === 'supervisor')  { $aw_aud[] = "'supervisors'"; $aw_aud[] = "'students_supervisors'"; $aw_aud[] = "'hod_supervisors'"; }
    if ($role === 'hod')         { $aw_aud[] = "'hod'"; $aw_aud[] = "'hod_supervisors'"; }
    $aw_in    = implode(',', $aw_aud);
    $aw_where = "(a.audience IN ($aw_in) OR (a.audience='department' AND a.department=?))
                  AND a.is_active=1
                  AND (a.scheduled_at IS NULL OR a.scheduled_at <= ?)
                  AND (a.expires_at IS NULL OR a.expires_at > ?)";
    $aw_params = [$aw_dept, $now_w, $now_w];
}

$aw_stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.content, a.category, a.priority, a.is_pinned,
            a.created_at, a.expires_at, u.full_name AS author_name,
            (SELECT 1 FROM announcement_reads ar WHERE ar.announcement_id=a.id AND ar.user_id=? LIMIT 1) AS i_read
     FROM announcements a
     JOIN users u ON u.id = a.author_id
     WHERE $aw_where
     ORDER BY a.is_pinned DESC, a.created_at DESC
     LIMIT 4"
);
$aw_stmt->execute(array_merge([$uid], $aw_params));
$aw_items = $aw_stmt->fetchAll();

$aw_unread = count(array_filter($aw_items, fn($x) => !$x['i_read']));

$aw_cat_color = [
    'academic'          => ['#3b82f6', 'bi-mortarboard-fill'],
    'viva_notice'       => ['#8b5cf6', 'bi-calendar-event-fill'],
    'deadline_reminder' => ['#f59e0b', 'bi-clock-fill'],
    'urgent_alert'      => ['#ef4444', 'bi-exclamation-triangle-fill'],
    'general'           => ['#22c55e', 'bi-megaphone-fill'],
];
$aw_pri_cls = [
    'urgent' => 'rgba(239,68,68,.2)',
    'high'   => 'rgba(245,158,11,.15)',
    'medium' => 'rgba(59,130,246,.1)',
    'low'    => 'rgba(107,114,128,.1)',
];
?>
<style>
.aw-widget { background:rgba(15,23,42,.85); border:1px solid rgba(51,65,85,.6);
    border-radius:14px; overflow:hidden; }
.aw-header { padding:.85rem 1.1rem; border-bottom:1px solid rgba(51,65,85,.4);
    display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.aw-header__title { font-size:.88rem; font-weight:700; color:#e2e8f0;
    display:flex; align-items:center; gap:.5rem; }
.aw-header__badge { background:rgba(239,68,68,.2); color:#fca5a5; font-size:.68rem;
    padding:.12rem .45rem; border-radius:10px; font-weight:700;
    border:1px solid rgba(239,68,68,.3); }
.aw-header__link { font-size:.75rem; color:#818cf8; text-decoration:none; }
.aw-header__link:hover { color:#a5b4fc; }
.aw-item { display:flex; align-items:flex-start; gap:.75rem;
    padding:.75rem 1.1rem; border-bottom:1px solid rgba(51,65,85,.3);
    transition:background .15s; text-decoration:none; }
.aw-item:last-child { border-bottom:none; }
.aw-item:hover { background:rgba(30,41,59,.5); }
.aw-dot { width:34px; height:34px; border-radius:9px; display:flex; align-items:center;
    justify-content:center; font-size:.85rem; flex-shrink:0; }
.aw-body { flex:1; min-width:0; }
.aw-title { font-size:.82rem; font-weight:600; color:#f1f5f9; white-space:nowrap;
    overflow:hidden; text-overflow:ellipsis; }
.aw-meta  { font-size:.7rem; color:#64748b; margin-top:.1rem; }
.aw-unread-pip { width:6px; height:6px; border-radius:50%; background:#f59e0b;
    flex-shrink:0; margin-top:.4rem; }
.aw-empty { text-align:center; padding:2rem 1rem; color:#475569; font-size:.83rem; }
</style>

<div class="aw-widget mb-4">
    <div class="aw-header">
        <span class="aw-header__title">
            <i class="bi bi-megaphone-fill" style="color:#818cf8;"></i>
            Announcements
            <?php if ($aw_unread > 0): ?>
                <span class="aw-header__badge"><?= $aw_unread ?> new</span>
            <?php endif; ?>
        </span>
        <a href="<?= base_url('announcements.php') ?>" class="aw-header__link">
            View all <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <?php if (empty($aw_items)): ?>
        <div class="aw-empty"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:.4rem;opacity:.4;"></i>No announcements right now.</div>
    <?php else: ?>
        <?php foreach ($aw_items as $aw):
            $cat   = $aw['category'] ?? 'general';
            $pri   = $aw['priority'] ?? 'medium';
            [$acolor, $aicon] = $aw_cat_color[$cat] ?? ['#64748b', 'bi-bell'];
            $bg = $aw_pri_cls[$pri] ?? 'rgba(30,41,59,.5)';
        ?>
        <a href="<?= base_url('announcements.php#ann-' . (int)$aw['id']) ?>" class="aw-item"
           style="background:<?= $bg ?>;">
            <div class="aw-dot" style="background:<?= $acolor ?>22;color:<?= $acolor ?>;">
                <i class="bi <?= $aicon ?>"></i>
            </div>
            <div class="aw-body">
                <div class="aw-title">
                    <?php if ($aw['is_pinned']): ?><i class="bi bi-pin-fill me-1" style="color:#fbbf24;font-size:.65rem;"></i><?php endif; ?>
                    <?= e($aw['title']) ?>
                </div>
                <div class="aw-meta">
                    <?= e($aw['author_name']) ?> · <?= date('M j', strtotime($aw['created_at'])) ?>
                    <?php if (!empty($aw['expires_at'])): ?>
                        <?php $diff = strtotime($aw['expires_at']) - time(); ?>
                        <?php if ($diff > 0 && $diff < 86400 * 3): ?>
                            <span style="color:#fbbf24;"> · Expires <?= date('M j', strtotime($aw['expires_at'])) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$aw['i_read']): ?><div class="aw-unread-pip" title="Unread"></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
