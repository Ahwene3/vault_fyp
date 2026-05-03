<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$uid = user_id();
$pdo = getPDO();

if (isset($_POST['mark_read']) && csrf_verify()) {
    $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?')->execute([$uid]);
    flash('success', 'All notifications marked as read.');
    redirect(base_url('notifications.php'));
}

$stmt = $pdo->prepare('SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$uid]);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$stmt->execute([$uid]);
$total_notifications = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmt->execute([$uid]);
$unread_count = (int) $stmt->fetchColumn();
$read_count = max($total_notifications - $unread_count, 0);

$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">Notifications</h1>
        <p class="dashboard-hero__copy mb-0">Stay on top of approvals, messages, and project updates.</p>
    </div>
    <div class="dashboard-hero__actions">
        <?php if (!empty($notifications)): ?>
            <form method="post" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="mark_read" value="1">
                <button type="submit" class="btn dashboard-hero__btn">Mark All Read</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-danger me-3"><i class="bi bi-bell-fill"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Unread</h6>
                    <div class="student-stat-value"><?= (int) $unread_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Read</h6>
                    <div class="student-stat-value"><?= (int) $read_count ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Notification Feed</div>
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="list-group-item text-muted">No notifications.</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <?php $is_unread = empty($n['is_read']); ?>
                <a href="<?= $n['link'] ? htmlspecialchars($n['link']) : '#' ?>" class="list-group-item list-group-item-action <?= $is_unread ? 'student-inbox-row is-unread' : 'student-inbox-row' ?>">
                    <span class="student-inbox-row__avatar">
                        <span class="student-notification-dot <?= $is_unread ? 'is-unread' : 'is-read' ?>"></span>
                    </span>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($n['title']) ?></div>
                                <div class="small text-muted"><?= e($n['message']) ?></div>
                            </div>
                            <small class="text-muted text-nowrap"><?= e(date('M j, H:i', strtotime($n['created_at']))) ?></small>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
