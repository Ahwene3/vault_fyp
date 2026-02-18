<?php
/**
 * Authentication and role-based access control
 */
require_once __DIR__ . '/init.php';

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function user_id(): ?int {
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

function user_role(): ?string {
    $u = current_user();
    return $u ? $u['role'] : null;
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function require_login(): void {
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect(base_url('index.php'));
    }
}

function require_role(string ...$roles): void {
    require_login();
    $role = user_role();
    if (!in_array($role, $roles, true)) {
        flash('error', 'You do not have permission to access this page.');
        redirect(base_url('dashboard.php'));
    }
}

function login_user(array $user): void {
    unset($user['password_hash']);
    $_SESSION['user'] = $user;
    regenerate_session();
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function regenerate_session(): void {
    session_regenerate_id(true);
}

function get_user_by_id(int $id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, email, full_name, role, department, reg_number, phone, is_active, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u ?: null;
}
