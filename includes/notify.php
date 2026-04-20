<?php
function notify_user(int $user_id, string $type, string $title, string $message, ?string $link = null): void {
    if ($user_id <= 0) return;
    
    $pdo = getPDO();
    
    // Store in-app notification
    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$user_id, $type, $title, $message, $link]);
    
    // Optionally send email for important notifications
    if (in_array($type, ['message', 'assessment', 'approval', 'rejection', 'assignment'], true)) {
        $user = get_user_by_id($user_id);
        if ($user && !empty($user['email'])) {
            $email_title = ucfirst(str_replace('_', ' ', $type));
            send_email(
                $user['email'],
                $email_title . ' - FYP Vault',
                get_email_template($title, "<p>$message</p>")
            );
        }
    }
}
