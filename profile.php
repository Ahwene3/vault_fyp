<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$uid = (int) $user['id'];
$pdo = getPDO();

function split_profile_name(string $name): array {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? '';
    $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    return [$first, $last];
}

$updated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $full_name = trim($first_name . ' ' . $last_name);

    if ($full_name !== '') {
        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$full_name, $phone ?: null, $uid]);
        $_SESSION['user']['full_name'] = $full_name;
        $_SESSION['user']['phone'] = $phone;
        $updated = true;
    }

    if (!empty($_POST['new_password'])) {
        $new = (string) $_POST['new_password'];
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
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
[$first_name, $last_name] = split_profile_name((string) ($profile['full_name'] ?? ''));
$profile_role = (string) ($profile['role'] ?? $user['role'] ?? 'student');
$is_student = $profile_role === 'student';
$student_id_display = (string) ($profile['reg_number'] ?: $uid);
$department_display = get_department_display_name($pdo, $profile['department'] ?? '');

$role_label_map = [
    'student'    => 'Student Portal',
    'supervisor' => 'Supervisor Portal',
    'hod'        => 'HOD Portal',
    'admin'      => 'Admin Portal',
];
$role_badge_map = [
    'student'    => ['bg-success', 'Active Student'],
    'supervisor' => ['bg-primary', 'Supervisor'],
    'hod'        => ['bg-warning text-dark', 'Head of Department'],
    'admin'      => ['bg-danger', 'Administrator'],
];
$portal_label  = $role_label_map[$profile_role] ?? ucfirst($profile_role) . ' Portal';
[$badge_class, $badge_text] = $role_badge_map[$profile_role] ?? ['bg-secondary', ucfirst($profile_role)];

$pageTitle = 'Profile';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow"><?= e($portal_label) ?></div>
        <h1 class="dashboard-hero__title mb-2">My Profile</h1>
        <p class="dashboard-hero__copy mb-0">Update your details and keep your vault identity current.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="#profile-form" class="btn dashboard-hero__btn">Save Changes</a>
    </div>
</section>

<div class="card mb-4 student-profile-card">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="student-avatar-xl"><?= e(strtoupper(substr((string) ($profile['full_name'] ?? 'U'), 0, 1))) ?></div>
        <div class="flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h2 class="h4 mb-0"><?= e($profile['full_name']) ?></h2>
                <span class="badge <?= e($badge_class) ?>"><?= e($badge_text) ?></span>
            </div>
            <?php if ($is_student): ?>
                <div class="text-muted">Student ID #<?= e($student_id_display) ?> · <?= e($department_display ?: 'No department') ?></div>
            <?php else: ?>
                <div class="text-muted"><?= e(ucfirst($profile_role)) ?> · <?= e($department_display ?: 'No department') ?></div>
            <?php endif; ?>
            <div class="text-muted small"><?= e($profile['email']) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Edit Profile</div>
            <div class="card-body">
                <form method="post" id="profile-form">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="first_name">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($first_name) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="last_name">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e($last_name) ?>">
                        </div>
                        <?php if ($is_student): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="student_id">Student ID</label>
                            <input type="text" class="form-control" id="student_id" value="<?= e($student_id_display) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="index_number">Index Number</label>
                            <input type="text" class="form-control" id="index_number" value="<?= e($profile['reg_number'] ?? '—') ?>" readonly>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control" id="email" value="<?= e($profile['email']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="department">Department</label>
                            <input type="text" class="form-control" id="department" value="<?= e($department_display ?: '—') ?>" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?= e($profile['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <hr class="my-4">
                    <h6>Change Password</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new_password_confirm">Confirm New Password</label>
                            <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Account Summary</div>
            <div class="card-body">
                <p class="mb-2"><strong>Role</strong><br><?= e(ucfirst((string) $profile['role'])) ?></p>
                <p class="mb-2"><strong>Member since</strong><br><?= e(date('M j, Y', strtotime((string) $profile['created_at']))) ?></p>
                <p class="mb-0 text-muted small">Use this profile to keep your vault identity and department details current.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
