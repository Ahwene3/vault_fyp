<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
ensure_departments_table($pdo);
ensure_user_archive_columns($pdo);
$departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['user_id'] ?? 0);
    if ($target_id && $target_id !== user_id()) {
        if (in_array($action, ['archive', 'restore', 'mark_permanent_archive'], true)) {
            $stmt = $pdo->prepare('SELECT id, role, department, is_active, archived_permanent FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$target_id]);
            $target_user = $stmt->fetch();

            if (!$target_user) {
                flash('error', 'User not found.');
                redirect(base_url('admin/users.php'));
            }

            if ($action === 'archive') {
                if ((int) $target_user['is_active'] !== 1) {
                    flash('error', 'User is already archived.');
                    redirect(base_url('admin/users.php'));
                }

                $pdo->prepare('UPDATE users SET is_active = 0, archived_permanent = 0, archived_at = NOW(), archived_by = ? WHERE id = ?')
                    ->execute([user_id(), $target_id]);
                flash('success', 'User archived.');
            } elseif ($action === 'restore') {
                if ((int) $target_user['is_active'] === 1) {
                    flash('error', 'User is already active.');
                    redirect(base_url('admin/users.php'));
                }

                if ((int) $target_user['archived_permanent'] === 1) {
                    flash('error', 'This account is permanently archived and cannot be restored.');
                    redirect(base_url('admin/users.php'));
                }

                if ($target_user['role'] === 'hod') {
                    $target_department = (string) ($target_user['department'] ?? '');
                    $dept_info = resolve_department_info($pdo, $target_department);
                    if (empty($dept_info['variants'])) {
                        flash('error', 'Cannot restore HOD account with invalid or missing department.');
                        redirect(base_url('admin/users.php'));
                    }
                    if (has_other_active_hod_in_department($pdo, $target_department, $target_id)) {
                        flash('error', 'Cannot restore this HOD. The department already has an active HOD.');
                        redirect(base_url('admin/users.php'));
                    }
                }

                $pdo->prepare('UPDATE users SET is_active = 1, archived_permanent = 0, archived_at = NULL, archived_by = NULL WHERE id = ?')
                    ->execute([$target_id]);
                flash('success', 'User restored.');
            } elseif ($action === 'mark_permanent_archive') {
                if ((int) $target_user['is_active'] === 1) {
                    flash('error', 'Only archived users can be permanently archived.');
                    redirect(base_url('admin/users.php'));
                }

                if ((int) $target_user['archived_permanent'] === 1) {
                    flash('error', 'User is already permanently archived.');
                    redirect(base_url('admin/users.php'));
                }

                $pdo->prepare('UPDATE users SET archived_permanent = 1, archived_at = COALESCE(archived_at, NOW()), archived_by = COALESCE(archived_by, ?) WHERE id = ?')
                    ->execute([user_id(), $target_id]);
                flash('success', 'Archived user marked as permanent.');
            }
        } elseif ($action === 'update_user') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'supervisor';
            $department = trim($_POST['department'] ?? '');
            $department_info = resolve_department_info($pdo, $department);
            $reg_number = trim($_POST['reg_number'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $new_password = $_POST['new_password'] ?? '';

            if (!$full_name || !$email) {
                flash('error', 'Full name and email are required.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Please provide a valid email address.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }
            if (!in_array($role, ['student', 'supervisor', 'hod', 'admin'], true)) {
                flash('error', 'Invalid role selected.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }
            if (in_array($role, ['supervisor', 'hod'], true) && empty($department_info['variants'])) {
                flash('error', 'A valid department is required for supervisors and HODs.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }
            if ($role === 'hod' && has_other_active_hod_in_department($pdo, $department, $target_id)) {
                flash('error', 'This department already has an active HOD. One active HOD per department is allowed.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $stmt->execute([$email, $target_id]);
            if ($stmt->fetch()) {
                flash('error', 'Another account already uses this email.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }

            if ($new_password !== '' && strlen($new_password) < 8) {
                flash('error', 'New password must be at least 8 characters.');
                redirect(base_url('admin/users.php?edit=' . $target_id));
            }

            $department_to_store = null;
            if ($department !== '') {
                $department_to_store = $department_info['id'] !== null ? (string) $department_info['id'] : $department;
            }

            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, department = ?, reg_number = ?, phone = ? WHERE id = ?')
                ->execute([
                    $full_name,
                    $email,
                    $role,
                    $department_to_store,
                    $reg_number ?: null,
                    $phone ?: null,
                    $target_id,
                ]);

            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $target_id]);
            }
            flash('success', 'User details updated.');
        }
        redirect(base_url('admin/users.php'));
    }
}

// Add user (supervisor/HOD/admin/student)
$add_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'add_user') {
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'supervisor';
    $department = trim($_POST['department'] ?? '');
    $department_info = resolve_department_info($pdo, $department);
    $password = $_POST['password'] ?? '';
    $reg_number = trim($_POST['reg_number'] ?? '');
    $level = trim($_POST['level'] ?? '');

    if (!$email || !$full_name || !$password) {
        $add_error = 'Fill all fields.';
    } elseif (!in_array($role, ['student', 'supervisor', 'hod', 'admin'], true)) {
        $add_error = 'Invalid role.';
    } elseif ($role === 'student' && !$reg_number) {
        $add_error = 'Index number is required for students.';
    } elseif (in_array($role, ['supervisor', 'hod'], true) && empty($department_info['variants'])) {
        $add_error = 'A valid department is required for supervisors and HODs.';
    } elseif ($role === 'hod' && has_other_active_hod_in_department($pdo, $department)) {
        $add_error = 'This department already has an active HOD. One active HOD per department is allowed.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $add_error = 'Email already in use.';
        } else {
            $stmt2 = $pdo->prepare('SELECT id FROM users WHERE reg_number = ?');
            $stmt2->execute([$reg_number]);
            if ($role === 'student' && $stmt2->fetch()) {
                $add_error = 'An account with this index number already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $department_to_store = null;
                if ($department !== '') {
                    $department_to_store = $department_info['id'] !== null ? (string) $department_info['id'] : $department;
                }
                $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, department, reg_number, level) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$email, $hash, $full_name, $role, $department_to_store, $reg_number ?: null, $level ?: null]);
                flash('success', 'User added.');
                redirect(base_url('admin/users.php'));
            }
        }
    }
}

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_user = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT id, email, full_name, role, department, reg_number, phone, is_active FROM users WHERE id = ?');
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch();
}

$active_hods = $pdo->query('SELECT id, full_name, department FROM users WHERE role = "hod" AND is_active = 1')->fetchAll();
$invalid_active_hods = [];
$normalized_hods = [];
foreach ($active_hods as $h) {
    $dept_info = resolve_department_info($pdo, (string) ($h['department'] ?? ''));
    if (empty($dept_info['variants'])) {
        $invalid_active_hods[] = [
            'id' => (int) $h['id'],
            'full_name' => $h['full_name'],
            'department' => trim((string) ($h['department'] ?? '')),
        ];
    }

    $normalized_hods[] = [
        'full_name' => $h['full_name'],
        'info' => $dept_info,
    ];
}

$hod_coverage = [];
foreach ($departments as $d) {
    $dept_id = (int) $d['id'];
    $dept_name = (string) $d['name'];
    $dept_id_key = strtolower((string) $dept_id);
    $dept_name_key = strtolower($dept_name);

    $matched_hods = [];
    foreach ($normalized_hods as $h) {
        $variants = $h['info']['variants'];
        if (in_array($dept_id_key, $variants, true) || in_array($dept_name_key, $variants, true)) {
            $matched_hods[] = $h['full_name'];
        }
    }

    $hod_coverage[] = [
        'id' => $dept_id,
        'name' => $dept_name,
        'active_hod_count' => count($matched_hods),
        'active_hods' => implode(', ', $matched_hods),
    ];
}

$edit_department_id = '';
$edit_department_legacy = '';
if ($edit_user) {
    $edit_dept_info = resolve_department_info($pdo, (string) ($edit_user['department'] ?? ''));
    if ($edit_dept_info['id'] !== null) {
        $edit_department_id = (string) $edit_dept_info['id'];
    } else {
        $edit_department_legacy = trim((string) ($edit_user['department'] ?? ''));
    }
}

$active_users = $pdo->query('SELECT id, email, full_name, role, department, reg_number, phone, is_active, created_at FROM users WHERE is_active = 1 ORDER BY role, full_name')->fetchAll();
$archived_users = $pdo->query('SELECT u.id, u.email, u.full_name, u.role, u.department, u.reg_number, u.phone, u.is_active, u.archived_permanent, u.archived_at, u.archived_by, u.created_at, ab.full_name AS archived_by_name FROM users u LEFT JOIN users ab ON ab.id = u.archived_by WHERE u.is_active = 0 ORDER BY COALESCE(u.archived_at, u.created_at) DESC, u.full_name')->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Manage Users</h1>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Bulk User Addition</span>
        <a href="<?= base_url('admin/import_users.php?download=template') ?>" class="btn btn-sm btn-outline-secondary">Download CSV Template</a>
    </div>
    <div class="card-body">
        <p class="mb-3">Add multiple supervisors and HOD accounts from this Users management flow using a CSV upload.</p>
        <a href="<?= base_url('admin/import_users.php') ?>" class="btn btn-primary">Open Bulk Add Users</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Add User</div>
    <div class="card-body">
        <?php if ($add_error): ?><div class="alert alert-danger"><?= e($add_error) ?></div><?php endif; ?>
        <form method="post" class="row g-3" id="addUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_user">
            <div class="col-md-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required value="<?= e($_POST['full_name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="col-md-2">
                <label class="form-label">Role</label>
                <select name="role" class="form-select" id="addRoleSelect" onchange="toggleAddUserFields()">
                    <option value="supervisor" <?= ($_POST['role'] ?? '') === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                    <option value="hod" <?= ($_POST['role'] ?? '') === 'hod' ? 'selected' : '' ?>>HOD</option>
                    <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="student" <?= ($_POST['role'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
                </select>
            </div>
            <div class="col-md-2" id="addDeptField">
                <label class="form-label">Department</label>
                <select name="department" class="form-select">
                    <option value="">Select...</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= ($_POST['department'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Student-only fields -->
            <div class="col-md-2" id="addIndexField" style="display:none;">
                <label class="form-label">Index Number <span class="text-danger">*</span></label>
                <input type="text" name="reg_number" class="form-control" placeholder="e.g. CS/2021/001" value="<?= e($_POST['reg_number'] ?? '') ?>">
            </div>
            <div class="col-md-2" id="addLevelField" style="display:none;">
                <label class="form-label">Level / Year</label>
                <select name="level" class="form-select">
                    <option value="">Select...</option>
                    <option value="100" <?= ($_POST['level'] ?? '') === '100' ? 'selected' : '' ?>>Level 100</option>
                    <option value="200" <?= ($_POST['level'] ?? '') === '200' ? 'selected' : '' ?>>Level 200</option>
                    <option value="300" <?= ($_POST['level'] ?? '') === '300' ? 'selected' : '' ?>>Level 300</option>
                    <option value="400" <?= ($_POST['level'] ?? '') === '400' ? 'selected' : '' ?>>Level 400</option>
                </select>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary">Add User</button></div>
        </form>
    </div>
</div>
<script>
function toggleAddUserFields() {
    var role = document.getElementById('addRoleSelect').value;
    var isStudent = role === 'student';
    document.getElementById('addIndexField').style.display = isStudent ? '' : 'none';
    document.getElementById('addLevelField').style.display = isStudent ? '' : 'none';
    document.getElementById('addDeptField').style.display = (role === 'supervisor' || role === 'hod') ? '' : 'none';
    document.querySelector('[name="reg_number"]').required = isStudent;
}
// Apply on page load in case of POST error repopulation
toggleAddUserFields();
</script>

<div class="card mb-4">
    <div class="card-header">Department HOD Coverage</div>
    <div class="card-body">
        <?php if (!empty($invalid_active_hods)): ?>
            <div class="alert alert-danger">
                <strong>Invalid active HOD assignment detected.</strong>
                <?php foreach ($invalid_active_hods as $bad_hod): ?>
                    <div>
                        <?= e($bad_hod['full_name']) ?> (ID <?= (int) $bad_hod['id'] ?>) has <?= e($bad_hod['department'] !== '' ? $bad_hod['department'] : 'no department') ?>.
                        Update this account with a valid department from the edit form.
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Department</th><th>Active HODs</th><th>Assigned HOD</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($hod_coverage as $hc): ?>
                        <?php $count = (int) ($hc['active_hod_count'] ?? 0); ?>
                        <tr>
                            <td><?= e($hc['name']) ?></td>
                            <td><?= $count ?></td>
                            <td><?= e($hc['active_hods'] ?: '—') ?></td>
                            <td>
                                <?php if ($count === 1): ?>
                                    <span class="badge bg-success">OK</span>
                                <?php elseif ($count === 0): ?>
                                    <span class="badge bg-warning text-dark">Missing HOD</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Multiple HODs</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($edit_user): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Edit User</span>
        <a href="<?= base_url('admin/users.php') ?>" class="btn btn-sm btn-outline-secondary">Close</a>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= (int) $edit_user['id'] ?>">

            <div class="col-md-4">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required value="<?= e($edit_user['full_name']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required value="<?= e($edit_user['email']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <option value="student" <?= $edit_user['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="supervisor" <?= $edit_user['role'] === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                    <option value="hod" <?= $edit_user['role'] === 'hod' ? 'selected' : '' ?>>HOD</option>
                    <option value="admin" <?= $edit_user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Department</label>
                <select name="department" class="form-select">
                    <option value="">Select...</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $edit_department_id === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($edit_department_legacy !== ''): ?>
                        <option value="<?= e($edit_department_legacy) ?>" selected><?= e($edit_department_legacy) ?> (legacy)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reg. Number</label>
                <input type="text" name="reg_number" class="form-control" value="<?= e($edit_user['reg_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e($edit_user['phone'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Reset Password (optional)</label>
                <input type="password" name="new_password" class="form-control" minlength="8" placeholder="Leave blank to keep current password">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Active Users</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($active_users as $u): ?>
                        <tr>
                            <td><?= e($u['full_name']) ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
                            <td><?= e(($u['department'] ?? '') !== '' ? get_department_display_name($pdo, (string) $u['department']) : '—') ?></td>
                            <td>
                                <?php if ($u['id'] != user_id()): ?>
                                    <a href="<?= base_url('admin/users.php?edit=' . $u['id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Archive</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Archived Users</span>
        <small class="text-muted">Permanently archived accounts are locked and cannot be restored.</small>
    </div>
    <div class="card-body">
        <?php if (empty($archived_users)): ?>
            <p class="text-muted mb-0">No archived users.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Archived On</th>
                            <th>Archived By</th>
                            <th>Permanent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archived_users as $u): ?>
                            <tr>
                                <td><?= e($u['full_name']) ?></td>
                                <td><?= e($u['email']) ?></td>
                                <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
                                <td><?= e(($u['department'] ?? '') !== '' ? get_department_display_name($pdo, (string) $u['department']) : '—') ?></td>
                                <td><?= e($u['archived_at'] ?? '—') ?></td>
                                <td><?= e($u['archived_by_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ((int) ($u['archived_permanent'] ?? 0) === 1): ?>
                                        <span class="badge bg-dark">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['id'] != user_id()): ?>
                                        <?php if ((int) ($u['archived_permanent'] ?? 0) === 1): ?>
                                            <span class="badge bg-dark">Locked</span>
                                        <?php else: ?>
                                            <form method="post" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Mark this account as permanently archived? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_permanent_archive">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Permanent</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
