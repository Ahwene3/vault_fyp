<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/otp.php';

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

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $pdo = getPDO();
            ensure_user_archive_columns($pdo);
            ensure_email_verification_columns($pdo);
            $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, department, is_active, archived_permanent, is_verified FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ((int) ($user['is_active'] ?? 1) !== 1) {
                    $error = ((int) ($user['archived_permanent'] ?? 0) === 1)
                        ? 'Your account has been permanently archived and cannot be restored.'
                        : 'Your account is archived. Contact admin for restoration.';
                } elseif (should_require_otp_for_role((string) ($user['role'] ?? '')) && (int) ($user['is_verified'] ?? 1) !== 1) {
                    $_SESSION['pending_verification_email'] = $user['email'];
                    flash('error', 'Please verify your email before signing in.');
                    redirect(base_url('verify_otp.php'));
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

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FYP Vault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: #050a18;
            color: #e2e8f0;
        }

        .login-container {
            display: grid;
            grid-template-columns: minmax(0, 0.6fr) minmax(0, 0.4fr);
            min-height: 100vh;
            overflow: hidden;
            background: #050a18 url('assets/images/img1.png') center right/cover no-repeat;
            position: relative;
        }

        .login-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 18% 20%, rgba(59, 130, 246, 0.2), transparent 55%),
                linear-gradient(110deg, rgba(3, 8, 24, 0.4) 0%, rgba(4, 8, 22, 0.65) 45%, rgba(2, 6, 18, 0.88) 100%);
        }

        .login-left {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 64px 72px 56px;
        }

        .left-top {
            max-width: 560px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            box-shadow: 0 12px 28px rgba(59, 130, 246, 0.35);
        }

        .brand-mark img {
            width: 26px;
            height: 26px;
        }

        .brand-text strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.2em;
            color: #f8fafc;
        }

        .brand-text span {
            display: block;
            font-size: 11px;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: #a5b4fc;
        }

        .welcome-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(59, 130, 246, 0.35);
            background: rgba(15, 23, 42, 0.6);
            color: #c7d2fe;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        .left-top h1 {
            font-size: clamp(2.6rem, 3.4vw, 3.6rem);
            font-weight: 700;
            line-height: 1.08;
            margin-bottom: 24px;
            color: #ffffff;
        }

        .left-top p {
            font-size: 16px;
            line-height: 1.7;
            color: #cbd5f5;
            margin-bottom: 32px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .feature-item {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #cbd5f5;
        }

        .feature-item i {
            color: #60a5fa;
            font-size: 16px;
        }

        .left-illustration {
            margin-top: 32px;
            display: flex;
            align-items: flex-end;
            gap: 18px;
        }

        .left-illustration img {
            width: min(340px, 70%);
            height: auto;
            opacity: 0.85;
            filter: drop-shadow(0 18px 30px rgba(2, 6, 18, 0.55));
        }

        .illustration-icons {
            display: flex;
            gap: 12px;
        }

        .illustration-icons span {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.25);
            color: #93c5fd;
            font-size: 18px;
            box-shadow: 0 12px 26px rgba(2, 6, 18, 0.5);
        }

        .login-right {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 64px 64px 64px 32px;
        }

        .login-form-wrapper {
            position: relative;
            width: min(520px, 92%);
            background: rgba(10, 16, 30, 0.82);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 28px;
            padding: 48px 44px 40px;
            box-shadow: 0 32px 80px rgba(2, 6, 18, 0.75);
            backdrop-filter: blur(26px);
        }

        .login-form-body {
            padding-top: 8px;
        }

        .form-header {
            text-align: center;
            margin-bottom: 26px;
        }

        .form-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(30, 64, 175, 0.2);
            color: #8ab4ff;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .form-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .form-header p {
            font-size: 14px;
            color: #9aa6c3;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #b6c0d9;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6677a3;
            font-size: 18px;
        }

        .form-group input {
            width: 100%;
            padding: 18px 18px 18px 50px;
            background: rgba(17, 24, 39, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 14px;
            color: #e2e8f0;
            font-size: 15px;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input::placeholder {
            color: #5c6a8f;
        }

        .form-group input:focus {
            outline: none;
            border-color: #5b8cff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8b9ab8;
            cursor: pointer;
            font-size: 16px;
            padding: 4px 6px;
            transition: color 0.2s ease;
        }

        .password-toggle:hover {
            color: #60a5fa;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .submit-btn {
            width: 100%;
            padding: 16px 20px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border: none;
            border-radius: 14px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 12px 0 20px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 36px rgba(56, 189, 248, 0.35);
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .form-footer a {
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .form-footer a:first-child {
            color: #8b9ab8;
        }

        .form-footer a:first-child:hover {
            color: #60a5fa;
        }

        .form-footer a:last-child {
            color: #60a5fa;
            font-weight: 600;
        }

        .form-footer a:last-child:hover {
            color: #ffffff;
        }

        .form-note {
            font-size: 12px;
            color: rgba(148, 163, 184, 0.85);
            line-height: 1.5;
        }

        @media (max-width: 1024px) {
            .login-container {
                grid-template-columns: 1fr;
            }

            .login-left {
                padding: 48px 32px 32px;
            }

            .feature-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .login-right {
                justify-content: center;
                padding: 32px 24px 48px;
            }
        }

        @media (max-width: 640px) {
            .login-right {
                padding: 24px;
            }

            .login-form-wrapper {
                padding: 36px 24px 30px;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .left-illustration {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Side - Branding/Content -->
        <div class="login-left">
            <div class="left-top">
                <div class="brand-row">
                    <div class="brand-mark">
                        <img src="assets/images/vault.svg" alt="FYP Vault">
                    </div>
                    <div class="brand-text">
                        <strong>FYP VAULT</strong>
                        <span>Final Year Project Hub</span>
                    </div>
                </div>
                <div class="welcome-badge">Welcome back</div>
                <h1>Your project vault opens with one secure sign in.</h1>
                <p>Manage your projects, messages, documents, and approvals in a secure workspace designed for students, supervisors, and HODs.</p>
                <div class="feature-grid">
                    <div class="feature-item">
                        <i class="bi bi-shield-check"></i>
                        Encrypted Access
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-people"></i>
                        Team Collaboration
                    </div>
                    <div class="feature-item">
                        <i class="bi bi-graph-up"></i>
                        Real-time Progress
                    </div>
                </div>
            </div>

            <div class="left-illustration">
                <img src="assets/images/image_0.png" alt="" onerror="this.style.display='none'">
                <div class="illustration-icons">
                    <span><i class="bi bi-folder2-open"></i></span>
                    <span><i class="bi bi-shield-lock"></i></span>
                    <span><i class="bi bi-kanban"></i></span>
                </div>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-right">
            <div class="login-form-wrapper">
                <div class="form-header">
                    <div class="form-badge">
                        <i class="bi bi-lock-fill"></i>
                        Secure Sign In
                    </div>
                    <h2>Welcome back</h2>
                    <p>Sign in to continue where you left off.</p>
                </div>

                <div class="login-form-body">
                    <?php if ($error): ?>
                        <div class="error-message">
                            <i class="bi bi-exclamation-circle"></i>
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="space-y-6">
                        <?= csrf_field() ?>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <div class="input-wrapper">
                                <i class="bi bi-envelope"></i>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    required
                                    autocomplete="email"
                                    value="<?= e($_POST['email'] ?? '') ?>"
                                    placeholder="your.email@example.com"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-wrapper input-wrapper">
                                <i class="bi bi-lock"></i>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                >
                                <button type="button" id="togglePassword" class="password-toggle" aria-label="Show or hide password">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="submit-btn">
                            Sign In
                            <i class="bi bi-arrow-right-short"></i>
                        </button>
                    </form>

                    <div class="form-footer">
                        <a href="#" onclick="return false;">Forgot Password?</a>
                        <a href="<?= base_url('register.php') ?>">Create account</a>
                    </div>

                    <p class="form-note">
                        Registration is only for students without an existing account. Supervisor, HOD, and admin accounts are created by the department.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword.addEventListener('click', (e) => {
            e.preventDefault();
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.innerHTML = isHidden
                ? '<i class="bi bi-eye-slash-fill"></i>'
                : '<i class="bi bi-eye-fill"></i>';
        });
    </script>
</body>
</html>
