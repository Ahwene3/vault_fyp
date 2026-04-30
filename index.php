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
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --dark: #0f172a;
            --dark-light: #1e293b;
            --accent: #ec4899;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--dark) 0%, var(--dark-light) 50%, #2d3e50 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
            top: -200px;
            left: -200px;
            border-radius: 50%;
        }
        body::after {
            content: '';
            position: absolute;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.3) 0%, transparent 70%);
            bottom: -150px;
            right: -100px;
            border-radius: 50%;
        }
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 900px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
            padding: 2rem;
        }
        .login-branding h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 1rem 0;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .login-branding img {
            width: 100%;
            max-width: 360px;
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.4);
        }
        .login-branding p {
            color: rgba(255, 255, 255, 0.7);
            margin: 1rem 0;
        }
        .login-form-wrapper {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 2rem;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .login-form-wrapper h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: rgba(255, 255, 255, 0.9);
        }
        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 0.75rem;
            color: white;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(99, 102, 241, 0.5);
        }
        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper button {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            margin-top: 0.875rem;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            font-size: 1.1rem;
        }
        .password-wrapper button:hover {
            color: rgba(255, 255, 255, 0.8);
        }
        .form-group input[type="password"] {
            padding-right: 2.75rem;
        }
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1rem;
            transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(99, 102, 241, 0.4);
        }
        .error-msg {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }
        .divider { text-align: center; margin: 2rem 0; color: rgba(255, 255, 255, 0.5); }
        .divider::before { content: ''; display: block; height: 1px; background: rgba(255, 255, 255, 0.1); margin-bottom: 1rem; }
        .link { text-align: center; }
        .link a { color: var(--primary); text-decoration: none; }
        @media (max-width: 768px) {
            .login-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-branding">
            <img src="<?= base_url('assets/images/vault.svg') ?>" alt="Vault illustration">
            <h1>FYP Vault</h1>
            <p>Final Year Project Collaboration Hub</p>
        </div>
        <div class="login-form-wrapper">
            <h2>Welcome Back</h2>
            <?php if ($error): ?>
                <div class="error-msg"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required placeholder="your@email.com" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required placeholder="••••••••">
                        <button type="button" id="togglePw"><i class="bi bi-eye-fill"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Sign In</button>
            </form>
            <div class="divider">New to FYP Vault?</div>
            <p class="link"><a href="<?= base_url('register.php') ?>">Create account</a></p>
        </div>
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
