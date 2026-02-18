<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$email || !$password) {
            $error = 'Please enter email and password.';
        } else {
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, department, reg_number, phone, is_active FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
                login_user($user);
                redirect(base_url('dashboard.php'));
            }
            $error = 'Invalid email or password.';
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrapper">
    <div class="card auth-card shadow">
        <div class="card-body">
            <h2 class="text-center mb-4"><i class="bi bi-shield-lock text-primary"></i> FYP Vault</h2>
            <p class="text-center text-muted mb-4">Sign in to your account</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>
            <p class="text-center mt-3 mb-0">
                <a href="<?= base_url('register.php') ?>">Register as Student</a>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
