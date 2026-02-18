<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$uid = (int) $user['id'];
$pdo = getPDO();

$updated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($full_name) {
        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$full_name, $phone ?: null, $uid]);
        $_SESSION['user']['full_name'] = $full_name;
        $_SESSION['user']['phone'] = $phone;
        $updated = true;
    }
    if (!empty($_POST['new_password'])) {
        $new = $_POST['new_password'];
        $confirm = $_POST['new_password_confirm'] ?? '';
        if (strlen($new) >= 8 && $new === $confirm) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
            $updated = true;
        }
    }
    if ($updated) {
        flash('success', 'Profile updated.');
        redirect(base_url('profile.php'));
    }
}

$stmt = $pdo->prepare('SELECT email, full_name, role, department, reg_number, phone, created_at FROM users WHERE id = ?');
$stmt->execute([$uid]);
$profile = $stmt->fetch();

$pageTitle = 'Profile';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="mb-4">My Profile</h1>
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Edit Profile</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="text" class="form-control" value="<?= e($profile['email']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($profile['full_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?= e($profile['phone'] ?? '') ?>">
                    </div>
                    <hr>
                    <h6>Change Password</h6>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password_confirm">Confirm New Password</label>
                        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <p class="mb-1"><strong>Role</strong> <?= e(ucfirst($profile['role'])) ?></p>
                <?php if ($profile['department']): ?><p class="mb-1"><strong>Department</strong> <?= e($profile['department']) ?></p><?php endif; ?>
                <?php if ($profile['reg_number']): ?><p class="mb-1"><strong>Reg. No.</strong> <?= e($profile['reg_number']) ?></p><?php endif; ?>
                <p class="mb-0 text-muted small">Member since <?= e(date('M j, Y', strtotime($profile['created_at']))) ?></p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
