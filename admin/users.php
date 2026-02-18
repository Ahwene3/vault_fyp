<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();

$users = $pdo->query('SELECT id, email, full_name, role, department, reg_number, is_active, created_at FROM users ORDER BY role, full_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['user_id'] ?? 0);
    if ($target_id && $target_id !== user_id()) {
        if ($action === 'toggle_active') {
            $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$target_id]);
            flash('success', 'User status updated.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$target_id]);
            flash('success', 'User removed.');
        }
        redirect(base_url('admin/users.php'));
    }
}

// Add user (supervisor/HOD - students register themselves)
$add_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'add_user') {
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'supervisor';
    $password = $_POST['password'] ?? '';
    if (!$email || !$full_name || !$password) {
        $add_error = 'Fill all fields.';
    } elseif (!in_array($role, ['supervisor', 'hod', 'admin'], true)) {
        $add_error = 'Invalid role.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $add_error = 'Email already in use.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role) VALUES (?, ?, ?, ?)')->execute([$email, $hash, $full_name, $role]);
            flash('success', 'User added.');
            redirect(base_url('admin/users.php'));
        }
    }
}

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Manage Users</h1>

<div class="card mb-4">
    <div class="card-header">Add User (Supervisor / HOD / Admin)</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_user">
            <div class="col-md-3"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required minlength="8"></div>
            <div class="col-md-2"><label class="form-label">Role</label><select name="role" class="form-select"><option value="supervisor">Supervisor</option><option value="hod">HOD</option><option value="admin">Admin</option></select></div>
            <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary">Add</button></div>
        </form>
        <?php if ($add_error): ?><p class="text-danger mt-2 mb-0"><?= e($add_error) ?></p><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['full_name']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
                        <td><?= e($u['department'] ?? '—') ?></td>
                        <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
                        <td>
                            <?php if ($u['id'] != user_id()): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove this user?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
