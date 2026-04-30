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
        $index_number = trim($_POST['index_number'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name) ?: null;
        
        if (!$first_name || !$last_name || !$password) {
            $error = 'Please fill in first name, last name, and password.';
        } elseif (!$email) {
            $error = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($index_number && !preg_match('/^[A-Z]{3}\d{7}$/', $index_number)) {
            $error = 'Index Number must be 3 uppercase letters followed by 7 numbers (e.g., ABC1234567).';
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
                $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $level ?: null, 'student', $department ?: null, $index_number ?: null]);
                
                // TODO: Email sending disabled for now - will integrate properly later
                // send_registration_email($email, $first_name);
                
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
            max-width: 980px;
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            gap: 2.5rem;
            align-items: center;
        }
        .register-visual {
            display: grid;
            gap: 1.5rem;
        }
        .register-visual img {
            width: 100%;
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.4);
        }
        .register-header {
            text-align: left;
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
        .form-group select option {
            color: #000;
            background: #fff;
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
        .pw-check span { display: flex; align-items: center; gap: 0.35rem; transition: color 0.2s; }
        .pw-check span.met { color: #10b981; }
        .pw-check i { font-size: 0.65rem; }
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
        @media (max-width: 900px) {
            .register-container {
                grid-template-columns: 1fr;
            }
            .register-header {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-visual">
            <img src="<?= base_url('assets/images/vault.svg') ?>" alt="Vault illustration">
            <div class="register-header">
                <h1>Join FYP Vault</h1>
                <p>Create your account to submit final year project</p>
            </div>
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
                    <input type="email" id="email" name="email" required placeholder="your.email@example.com" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="index_number">Index Number <small style="color: rgba(255,255,255,0.6);">(ABC1234567 - Optional)</small></label>
                        <input type="text" id="index_number" name="index_number" placeholder="ABC1234567" value="<?= e($_POST['index_number'] ?? '') ?>" pattern="[A-Z]{3}[0-9]{7}" title="Must be 3 uppercase letters followed by 7 numbers (e.g., ABC1234567)" maxlength="10">
                        <div id="index-check" style="font-size: 0.75rem; margin-top: 0.35rem;"></div>
                    </div>
                    <div class="form-group">
                        <label for="level">Level *</label>
                        <select id="level" name="level" required>
                            <option value="">Select level</option>
                            <option value="100" <?= ($_POST['level'] ?? '') === '100' ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= ($_POST['level'] ?? '') === '200' ? 'selected' : '' ?>>200</option>
                            <option value="300" <?= ($_POST['level'] ?? '') === '300' ? 'selected' : '' ?>>300</option>
                            <option value="400" <?= ($_POST['level'] ?? '') === '400' ? 'selected' : '' ?>>400</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="department">Department *</label>
                    <select id="department" name="department" required>
                        <option value="">Select department</option>
                        <?php
                        $pdo = getPDO();
                        $stmt = $pdo->prepare('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');
                        $stmt->execute();
                        while ($dept = $stmt->fetch()) {
                            $selected = ($_POST['department'] ?? '') === $dept['id'] ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($dept['id']) . '" ' . $selected . '>' . htmlspecialchars($dept['name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="password">Password * <small style="color: rgba(255,255,255,0.6);">(Show requirements below)</small></label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required minlength="8" placeholder="••••••••" autocomplete="new-password">
                        <button type="button" id="togglePw"><i class="bi bi-eye-fill"></i></button>
                    </div>
                    <div id="pw-check" class="pw-check">
                        <span id="req-length"><i class="bi bi-circle-fill"></i> <span>8+ chars</span></span>
                        <span id="req-upper"><i class="bi bi-circle-fill"></i> <span>Uppercase</span></span>
                        <span id="req-lower"><i class="bi bi-circle-fill"></i> <span>Lowercase</span></span>
                        <span id="req-number"><i class="bi bi-circle-fill"></i> <span>Number</span></span>
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
        // Index Number validation and formatting
        document.getElementById('index_number').addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (this.value.length > 3) {
                const letters = this.value.substring(0, 3);
                const numbers = this.value.substring(3).replace(/[^0-9]/g, '').substring(0, 7);
                this.value = letters + numbers;
            }
            
            // Show validation status
            const check = document.getElementById('index-check');
            if (!this.value) {
                check.textContent = '';
            } else if (/^[A-Z]{3}[0-9]{7}$/.test(this.value)) {
                check.innerHTML = '<span style="color: #10b981;"><i class="bi bi-check-circle-fill"></i> Valid format</span>';
            } else if (this.value.length < 10) {
                check.innerHTML = '<span style="color: #f97316;"><i class="bi bi-info-circle-fill"></i> Format: 3 letters + 7 numbers</span>';
            } else {
                check.innerHTML = '<span style="color: #fca5a5;"><i class="bi bi-x-circle-fill"></i> Invalid format</span>';
            }
        });
        document.getElementById('password').addEventListener('input', function() {
            const pwd = this.value;
            const reqLength = document.getElementById('req-length');
            const reqUpper = document.getElementById('req-upper');
            const reqLower = document.getElementById('req-lower');
            const reqNumber = document.getElementById('req-number');
            
            pwd.length >= 8 ? reqLength.classList.add('met') : reqLength.classList.remove('met');
            /[A-Z]/.test(pwd) ? reqUpper.classList.add('met') : reqUpper.classList.remove('met');
            /[a-z]/.test(pwd) ? reqLower.classList.add('met') : reqLower.classList.remove('met');
            /[0-9]/.test(pwd) ? reqNumber.classList.add('met') : reqNumber.classList.remove('met');
            
            // Update password confirm match
            const pwConfirm = document.getElementById('password_confirm');
            if (pwConfirm.value) {
                const match = document.getElementById('match-check');
                if (this.value === pwConfirm.value) {
                    match.innerHTML = '<span style="color: #10b981;"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
                } else {
                    match.innerHTML = '<span style="color: #fca5a5;"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
                }
            }
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
