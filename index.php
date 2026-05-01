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
            ensure_user_archive_columns($pdo);
            $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, department, is_active, archived_permanent FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                if ((int) ($user['is_active'] ?? 1) !== 1) {
                    $error = ((int) ($user['archived_permanent'] ?? 0) === 1)
                        ? 'Your account has been permanently archived and cannot be restored.'
                        : 'Your account is archived. Contact admin for restoration.';
                } else {
                    login_user($user);
                    redirect(base_url('dashboard.php'));
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FYP Vault</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="auth-page login-page">
    <div class="auth-split">
        <section class="auth-showcase">
            <div class="auth-showcase__content">
                <div class="auth-badge"><i class="bi bi-shield-lock"></i> FYP Vault</div>
                <h1>Your project vault opens with one secure sign in.</h1>
                <p>Collaborate with your supervisor, manage documents, and track your final year project progress in one place.</p>
            </div>
            <div class="vault-illustration" aria-hidden="true">
                <div class="vault-shadow"></div>
                <div class="vault-body">
                    <div class="vault-core">
                        <div class="vault-slot"></div>
                        <div class="vault-files">
                            <div class="vault-file vault-file--one"></div>
                            <div class="vault-file vault-file--two"></div>
                            <div class="vault-file vault-file--three"></div>
                        </div>
                    </div>
                    <div class="vault-door">
                        <div class="vault-door-ring"></div>
                        <div class="vault-wheel">
                            <span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <div class="vault-handle"></div>
                    </div>
                </div>
            </div>
        </section>
        <section class="auth-panel">
            <div class="auth-card-shell auth-card-shell--compact">
                <h2 class="auth-form-title">Welcome back</h2>
                <p class="auth-form-subtitle">Sign in to continue to your workspace.</p>
                <?php if ($error): ?>
                    <div class="auth-error-msg"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="auth-form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required placeholder="your@email.com" value="<?= e($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="auth-form-group">
                        <label for="password">Password</label>
                        <div class="auth-password-wrap">
                            <input type="password" id="password" name="password" required placeholder="••••••••">
                            <button type="button" class="auth-password-toggle" id="togglePw" aria-label="Show or hide password"><i class="bi bi-eye-fill"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="auth-submit-btn">Sign In</button>
                </form>
                <p class="auth-switch">New to FYP Vault? <a href="<?= base_url('register.php') ?>">Create account</a></p>
            </div>
        </section>
    </div>
    <script>
        document.getElementById('togglePw').addEventListener('click', function() {
            const pw = document.getElementById('password');
            const icon = this.querySelector('i');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.className = 'bi bi-eye-slash-fill';
            } else {
                pw.type = 'password';
                icon.className = 'bi bi-eye-fill';
            }
        });
    </script>
</body>
</html>
