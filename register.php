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
        $confirm = $_POST['password_confirm'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $level = trim($_POST['level'] ?? '');
        $reg_number = trim($_POST['reg_number'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name) ?: null;
        
        if (!$email || !$password || !$first_name || !$last_name) {
            $error = 'Please fill in email, first name, last name, and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password must contain uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = 'Password must contain lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain a number.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, first_name, last_name, level, role, department, reg_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $level ?: null, 'student', $department ?: null, $reg_number ?: null]);
                flash('success', 'Registration successful. Log in now.');
                redirect(base_url('index.php'));
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
    <title>Register | FYP Vault</title>
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
            overflow-y: auto;
            padding: 2rem 1rem;
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
        .register-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 520px;
        }
        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .register-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .register-header p {
            color: rgba(255, 255, 255, 0.7);
        }
        .register-form-wrapper {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 2rem;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .register-form-wrapper h2 {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: rgba(255, 255, 255, 0.9);
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 0.75rem;
            color: white;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(99, 102, 241, 0.5);
        }
        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        .password-wrapper { position: relative; }
        .password-wrapper button {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            margin-top: 0.75rem;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
        }
        .password-wrapper button:hover { color: rgba(255, 255, 255, 0.8); }
        .form-group input[type="password"] { padding-right: 2.75rem; }
        .pw-check { font-size: 0.75rem; margin-top: 0.5rem; color: rgba(255, 255, 255, 0.6); display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .pw-check.met { color: #10b981; }
        .btn-submit {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(99, 102, 241, 0.4); }
        .error-msg {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 0.875rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }
        .divider { text-align: center; margin: 1.5rem 0; color: rgba(255, 255, 255, 0.5); }
        .divider::before { content: ''; display: block; height: 1px; background: rgba(255, 255, 255, 0.1); margin-bottom: 0.75rem; }
        .link { text-align: center; }
        .link a { color: var(--primary); text-decoration: none; }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1>Join FYP Vault</h1>
            <p>Create your account to submit final year project</p>
        </div>
        <div class="register-form-wrapper">
            <h2>Create Account</h2>
            <?php if ($error): ?>
                <div class="error-msg"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" required placeholder="John" value="<?= e($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required placeholder="Smith" value="<?= e($_POST['last_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required placeholder="john@example.com" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="reg_number">Reg. Number</label>
                        <input type="text" id="reg_number" name="reg_number" placeholder="RMU/2024/001" value="<?= e($_POST['reg_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="level">Level</label>
                        <select id="level" name="level">
                            <option value="">Select level</option>
                            <option value="100" <?= ($_POST['level'] ?? '') === '100' ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= ($_POST['level'] ?? '') === '200' ? 'selected' : '' ?>>200</option>
                            <option value="300" <?= ($_POST['level'] ?? '') === '300' ? 'selected' : '' ?>>300</option>
                            <option value="400" <?= ($_POST['level'] ?? '') === '400' ? 'selected' : '' ?>>400</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department" placeholder="e.g. Marine Engineering" value="<?= e($_POST['department'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password *</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required minlength="8" placeholder="••••••••" autocomplete="new-password">
                        <button type="button" id="togglePw"><i class="bi bi-eye-fill"></i></button>
                    </div>
                    <div id="pw-check" class="pw-check">
                        <span id="req-length"><i class="bi bi-circle-fill"></i> 8+ chars</span>
                        <span id="req-upper"><i class="bi bi-circle-fill"></i> Uppercase</span>
                        <span id="req-lower"><i class="bi bi-circle-fill"></i> Lowercase</span>
                        <span id="req-number"><i class="bi bi-circle-fill"></i> Number</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm Password *</label>
                    <div class="password-wrapper">
                        <input type="password" id="password_confirm" name="password_confirm" required placeholder="••••••••" autocomplete="new-password">
                        <button type="button" id="togglePwConfirm"><i class="bi bi-eye-fill"></i></button>
                    </div>
                    <div id="match-check" style="font-size: 0.75rem; margin-top: 0.35rem;"></div>
                </div>
                <button type="submit" class="btn-submit">Create Account</button>
            </form>
            <div class="divider">Already have account?</div>
            <p class="link"><a href="<?= base_url('index.php') ?>">Back to Sign In</a></p>
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
        document.getElementById('togglePwConfirm').addEventListener('click', function() {
            const pw = document.getElementById('password_confirm');
            const icon = this.querySelector('i');
            if (pw.type === 'password') {
                pw.type = 'text';
                icon.className = 'bi bi-eye-slash-fill';
            } else {
                pw.type = 'password';
                icon.className = 'bi bi-eye-fill';
            }
        });
        document.getElementById('password').addEventListener('input', function() {
            const pwd = this.value;
            document.getElementById('req-length').className = pwd.length >= 8 ? 'met' : '';
            document.getElementById('req-upper').className = /[A-Z]/.test(pwd) ? 'met' : '';
            document.getElementById('req-lower').className = /[a-z]/.test(pwd) ? 'met' : '';
            document.getElementById('req-number').className = /[0-9]/.test(pwd) ? 'met' : '';
        });
        document.getElementById('password_confirm').addEventListener('input', function() {
            const pwd = document.getElementById('password').value;
            const match = document.getElementById('match-check');
            if (!this.value) {
                match.textContent = '';
            } else if (this.value === pwd) {
                match.innerHTML = '<span style="color: #10b981;"><i class="bi bi-check-circle-fill"></i> Match</span>';
            } else {
                match.innerHTML = '<span style="color: #fca5a5;"><i class="bi bi-x-circle-fill"></i> No match</span>';
            }
        });
    </script>
</body>
</html>
