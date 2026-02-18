<?php
function notify_user(int $user_id, string $type, string $title, string $message, ?string $link = null): void {
    if ($user_id <= 0) return;
    $pdo = getPDO();
    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$user_id, $type, $title, $message, $link]);
}
