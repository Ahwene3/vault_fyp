<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $level = trim($_POST['level'] ?? '');
        $reg_number = trim($_POST['reg_number'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name) ?: null;
        if (!$email || !$password || !$first_name || !$last_name) {
            $error = 'Please fill in email, first name, last name, and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one number.';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $error = 'Password must contain at least one special character (e.g. !@#$%).';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, first_name, last_name, middle_name, level, role, department, reg_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$email, $hash, $full_name, $first_name, $last_name, $middle_name ?: null, $level ?: null, 'student', $department ?: null, $reg_number ?: null]);
                flash('success', 'Registration successful. You can now log in.');
                redirect(base_url('index.php'));
            }
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrapper">
    <div class="card auth-card shadow">
        <div class="card-body">
            <h2 class="text-center mb-2">Student Registration</h2>
            <p class="text-center text-muted mb-4">Create an account to submit your project</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="first_name">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required
                               value="<?= e($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="last_name">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required
                               value="<?= e($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="middle_name">Middle / Other Name</label>
                        <input type="text" class="form-control" id="middle_name" name="middle_name"
                               value="<?= e($_POST['middle_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="reg_number">Registration Number</label>
                        <input type="text" class="form-control" id="reg_number" name="reg_number"
                               value="<?= e($_POST['reg_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="level">Level</label>
                        <select class="form-select" id="level" name="level">
                            <option value="">Select level</option>
                            <option value="100" <?= ($_POST['level'] ?? '') === '100' ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= ($_POST['level'] ?? '') === '200' ? 'selected' : '' ?>>200</option>
                            <option value="300" <?= ($_POST['level'] ?? '') === '300' ? 'selected' : '' ?>>300</option>
                            <option value="400" <?= ($_POST['level'] ?? '') === '400' ? 'selected' : '' ?>>400</option>
                            <option value="500" <?= ($_POST['level'] ?? '') === '500' ? 'selected' : '' ?>>500</option>
                            <option value="Final Year" <?= ($_POST['level'] ?? '') === 'Final Year' ? 'selected' : '' ?>>Final Year</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="department">Department</label>
                    <input type="text" class="form-control" id="department" name="department"
                           value="<?= e($_POST['department'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password" aria-describedby="pw-requirements">
                        <button type="button" class="btn btn-outline-secondary" id="togglePassword" title="Show password" aria-label="Show password">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    <div id="pw-requirements" class="form-text mt-2">
                        <strong>Password must have:</strong>
                        <ul class="list-unstyled mb-0 mt-1 small">
                            <li id="req-length"><i class="bi bi-circle me-2"></i>At least 8 characters</li>
                            <li id="req-upper"><i class="bi bi-circle me-2"></i>One uppercase letter</li>
                            <li id="req-lower"><i class="bi bi-circle me-2"></i>One lowercase letter</li>
                            <li id="req-number"><i class="bi bi-circle me-2"></i>One number</li>
                            <li id="req-special"><i class="bi bi-circle me-2"></i>One special character (!@#$% etc.)</li>
                        </ul>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password_confirm">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary" id="togglePasswordConfirm" title="Show password" aria-label="Show password">
                            <i class="bi bi-eye" id="togglePasswordConfirmIcon"></i>
                        </button>
                    </div>
                    <p id="confirm-match" class="form-text small mb-0 mt-1 text-muted" aria-live="polite"></p>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="submitBtn">Register</button>
            </form>
            <p class="text-center mt-3 mb-0">
                <a href="<?= base_url('index.php') ?>">Already have an account? Sign in</a>
            </p>
        </div>
    </div>
</div>
<script>
(function() {
    var password = document.getElementById('password');
    var passwordConfirm = document.getElementById('password_confirm');
    var toggleBtn = document.getElementById('togglePassword');
    var toggleIcon = document.getElementById('togglePasswordIcon');
    var toggleConfirmBtn = document.getElementById('togglePasswordConfirm');
    var toggleConfirmIcon = document.getElementById('togglePasswordConfirmIcon');
    var submitBtn = document.getElementById('submitBtn');

    function setRequirement(id, met) {
        var el = document.getElementById(id);
        if (!el) return;
        var icon = el.querySelector('i');
        if (met) {
            icon.className = 'bi bi-check-circle-fill text-success me-2';
            el.classList.add('text-success');
            el.classList.remove('text-danger');
        } else {
            icon.className = 'bi bi-x-circle-fill text-danger me-2';
            el.classList.add('text-danger');
            el.classList.remove('text-success');
        }
    }

    function checkPassword() {
        var p = password.value;
        setRequirement('req-length', p.length >= 8);
        setRequirement('req-upper', /[A-Z]/.test(p));
        setRequirement('req-lower', /[a-z]/.test(p));
        setRequirement('req-number', /[0-9]/.test(p));
        setRequirement('req-special', /[^A-Za-z0-9]/.test(p));
        checkConfirm();
    }

    function checkConfirm() {
        var matchEl = document.getElementById('confirm-match');
        if (passwordConfirm.value.length === 0) {
            matchEl.textContent = '';
            return;
        }
        if (passwordConfirm.value === password.value) {
            matchEl.textContent = 'Passwords match.';
            matchEl.classList.remove('text-danger');
            matchEl.classList.add('text-success');
        } else {
            matchEl.textContent = 'Passwords do not match.';
            matchEl.classList.remove('text-success');
            matchEl.classList.add('text-danger');
        }
    }

    function allRequirementsMet() {
        var p = password.value;
        return p.length >= 8 && /[A-Z]/.test(p) && /[a-z]/.test(p) && /[0-9]/.test(p) && /[^A-Za-z0-9]/.test(p);
    }

    password.addEventListener('input', checkPassword);
    passwordConfirm.addEventListener('input', checkConfirm);

    toggleBtn.addEventListener('click', function() {
        var isPassword = password.type === 'password';
        password.type = isPassword ? 'text' : 'password';
        toggleIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        toggleBtn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
    });
    toggleConfirmBtn.addEventListener('click', function() {
        var isPassword = passwordConfirm.type === 'password';
        passwordConfirm.type = isPassword ? 'text' : 'password';
        toggleConfirmIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        toggleConfirmBtn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
    });

    document.querySelector('form').addEventListener('submit', function(e) {
        if (!allRequirementsMet()) {
            e.preventDefault();
            alert('Please meet all password requirements before registering.');
            return;
        }
        if (password.value !== passwordConfirm.value) {
            e.preventDefault();
            alert('Passwords do not match.');
            return;
        }
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
