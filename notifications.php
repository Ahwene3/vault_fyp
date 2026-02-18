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

$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="mb-4">Notifications</h1>
<?php if (!empty($notifications)): ?>
    <form method="post" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="mark_read" value="1">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all as read</button>
    </form>
<?php endif; ?>
<div class="card">
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="list-group-item text-muted">No notifications.</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <a href="<?= $n['link'] ? htmlspecialchars($n['link']) : '#' ?>" class="list-group-item list-group-item-action <?= !$n['is_read'] ? 'message-item unread' : '' ?>">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1"><?= e($n['title']) ?></h6>
                        <small><?= e(date('M j, H:i', strtotime($n['created_at']))) ?></small>
                    </div>
                    <p class="mb-0 small text-muted"><?= e($n['message']) ?></p>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
