<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$role = $user['role'];
$uid = (int) $user['id'];
$pdo = getPDO();

// Role-specific dashboard data
$stats = [];
if ($role === 'student') {
    // Get student's group
    $stmt = $pdo->prepare('SELECT g.id, g.name, g.created_by, COUNT(gm.id) AS member_count FROM `groups` g LEFT JOIN `group_members` gm ON g.id = gm.group_id WHERE g.id IN (SELECT group_id FROM `group_members` WHERE student_id = ?) GROUP BY g.id LIMIT 1');
    $stmt->execute([$uid]);
    $stats['group'] = $stmt->fetch();

    if (!empty($stats['group']['id'])) {
        $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE group_id = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([(int) $stats['group']['id']]);
        $stats['project'] = $stmt->fetch();

        if (empty($stats['project']) && !empty($stats['group']['created_by'])) {
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND (group_id IS NULL OR group_id = ?) ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([(int) $stats['group']['created_by'], (int) $stats['group']['id']]);
            $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
            if ($creator_project_id > 0) {
                $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([(int) $stats['group']['id'], $creator_project_id, (int) $stats['group']['id']]);
                $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE id = ? LIMIT 1');
                $stmt->execute([$creator_project_id]);
                $stats['project'] = $stmt->fetch();
            }
        }
    }
    if (empty($stats['project']) && empty($stats['group']['id'])) {
        $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$uid]);
        $stats['project'] = $stmt->fetch();
    }

    if (!empty($stats['project']['id'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries WHERE project_id = ?');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['logbook_count'] = (int) $stmt->fetchColumn();
    } else {
        $stats['logbook_count'] = 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'supervisor') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE supervisor_id = ? AND status IN ("in_progress","submitted","approved")');
    $stmt->execute([$uid]);
    $stats['vault_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries le JOIN projects p ON le.project_id = p.id WHERE p.supervisor_id = ? AND le.supervisor_approved IS NULL');
    $stmt->execute([$uid]);
    $stats['pending_logbook'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'hod') {
    $hod_department_source = trim((string) ($user['department'] ?? ''));
    if ($hod_department_source === '') {
        $fresh_hod = get_user_by_id($uid);
        $hod_department_source = trim((string) ($fresh_hod['department'] ?? ''));
    }
    $hod_department_info = resolve_department_info($pdo, $hod_department_source);
    $stats['department_label'] = $hod_department_info['name'] ?: $hod_department_info['raw'];
    $stats['department_error'] = empty($hod_department_info['variants'])
        ? 'Your HOD account does not have a valid department configured. Contact admin.'
        : '';

    if (!empty($hod_department_info['variants'])) {
        $dept_placeholders = sql_placeholders(count($hod_department_info['variants']));
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');

        $count_stmt->execute(array_merge(['submitted'], $hod_department_info['variants']));
        $stats['pending_topics'] = (int) $count_stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status IN ("in_progress","approved") AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');
        $stmt->execute($hod_department_info['variants']);
        $stats['ongoing'] = (int) $stmt->fetchColumn();

        $count_stmt->execute(array_merge(['completed'], $hod_department_info['variants']));
        $stats['completed'] = (int) $count_stmt->fetchColumn();
    } else {
        $stats['pending_topics'] = 0;
        $stats['ongoing'] = 0;
        $stats['completed'] = 0;
    }
} elseif ($role === 'admin') {
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $stats['users_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects');
    $stats['projects_count'] = (int) $stmt->fetchColumn();
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="mb-4">Dashboard</h1>
<p class="text-muted">Welcome back, <?= e($user['full_name']) ?>.</p>

<?php if ($role === 'student'): ?>
    <div class="row g-3 mb-4">
        <?php if ($stats['group']): ?>
        <div class="col-md-3">
            <a href="<?= base_url('student/group.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">My Group</h6>
                            <span class="fw-bold"><?= e($stats['group']['name']) ?></span>
                            <br><small class="text-muted"><?= $stats['group']['member_count'] ?> members</small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $stats['group'] ? '3' : '4' ?>">
            <a href="<?= base_url('student/project.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-journal-richtext"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">My Project</h6>
                            <span class="fw-bold"><?= $stats['project'] ? e($stats['project']['title']) : 'Not submitted' ?></span>
                            <?php if ($stats['project']): ?>
                                <span class="badge bg-secondary ms-1"><?= e($stats['project']['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-<?= $stats['group'] ? '3' : '4' ?>">
            <a href="<?= base_url('student/logbook.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-success me-3"><i class="bi bi-book"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Logbook Entries</h6>
                            <span class="fw-bold"><?= (int)($stats['logbook_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-<?= $stats['group'] ? '3' : '4' ?>">
            <a href="<?= base_url('messages.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-info me-3"><i class="bi bi-chat-dots"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Unread Messages</h6>
                            <span class="fw-bold"><?= (int)($stats['unread_messages'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <?php if (!$stats['group']): ?>
            <a href="<?= base_url('student/group.php') ?>" class="btn btn-outline-primary me-2">Create Group / Await Invite</a>
            <?php endif; ?>
            <a href="<?= base_url('student/project.php') ?>" class="btn btn-primary me-2">My Project / Submit Topic</a>
            <a href="<?= base_url('student/logbook.php') ?>" class="btn btn-outline-primary me-2">Logbook</a>
            <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'supervisor'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="<?= base_url('supervisor/students.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Assigned Vaults</h6>
                            <span class="fw-bold"><?= (int)($stats['vault_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= base_url('supervisor/students.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-warning me-3"><i class="bi bi-journal-check"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Pending Logbook Reviews</h6>
                            <span class="fw-bold"><?= (int)($stats['pending_logbook'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= base_url('messages.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-info me-3"><i class="bi bi-chat-dots"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Unread Messages</h6>
                            <span class="fw-bold"><?= (int)($stats['unread_messages'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('supervisor/students.php') ?>" class="btn btn-primary me-2">View Group Vaults</a>
            <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'hod'): ?>
    <?php if (!empty($stats['department_error'])): ?>
        <div class="alert alert-danger"><?= e($stats['department_error']) ?></div>
    <?php else: ?>
        <div class="alert alert-info">Department scope: <strong><?= e($stats['department_label'] ?: 'Unknown') ?></strong></div>
    <?php endif; ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="<?= base_url('hod/topics.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-warning me-3"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Topics Pending Approval</h6>
                            <span class="fw-bold"><?= (int)($stats['pending_topics'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= base_url('hod/reports.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-arrow-repeat"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Ongoing Projects</h6>
                            <span class="fw-bold"><?= (int)($stats['ongoing'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= base_url('hod/archive.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-success me-3"><i class="bi bi-check-circle"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Completed</h6>
                            <span class="fw-bold"><?= (int)($stats['completed'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('hod/topics.php') ?>" class="btn btn-primary me-2">Review Topics</a>
            <a href="<?= base_url('hod/assign.php') ?>" class="btn btn-outline-primary me-2">Assign Supervisors</a>
            <a href="<?= base_url('hod/archive.php') ?>" class="btn btn-outline-primary me-2">Archive</a>
            <a href="<?= base_url('hod/reports.php') ?>" class="btn btn-outline-primary">Reports</a>
        </div>
    </div>

<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <a href="<?= base_url('admin/users.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Total Users</h6>
                            <span class="fw-bold"><?= (int)($stats['users_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a href="<?= base_url('vault.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-success me-3"><i class="bi bi-folder"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Total Projects</h6>
                            <span class="fw-bold"><?= (int)($stats['projects_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('admin/users.php') ?>" class="btn btn-primary me-2">Manage Users</a>
            <a href="<?= base_url('admin/reports.php') ?>" class="btn btn-outline-primary me-2">Audit Reports</a>
            <a href="<?= base_url('vault.php') ?>" class="btn btn-outline-primary">Project Vault</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
