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
    $stats['ongoing_projects'] = [];

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

        $stmt = $pdo->prepare('SELECT
            p.id,
            p.title,
            p.status,
            p.updated_at,
            p.group_id,
            COALESCE(g.name, CONCAT("Solo Vault - ", lead_u.full_name)) AS vault_name,
            lead_u.full_name AS lead_name,
            lead_u.reg_number AS lead_reg_number,
            lead_u.email AS lead_email,
            sup_u.full_name AS supervisor_name,
            (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
                FROM `group_members` gm2
                JOIN users u2 ON u2.id = gm2.student_id
                WHERE gm2.group_id = p.group_id) AS member_directory
            FROM projects p
            JOIN users lead_u ON lead_u.id = p.student_id
            LEFT JOIN users sup_u ON sup_u.id = p.supervisor_id
            LEFT JOIN `groups` g ON g.id = p.group_id
            WHERE p.status IN ("approved","in_progress")
                AND LOWER(TRIM(COALESCE(lead_u.department, ""))) IN (' . $dept_placeholders . ')
            ORDER BY p.updated_at DESC');
        $stmt->execute($hod_department_info['variants']);
        $stats['ongoing_projects'] = $stmt->fetchAll();
    } else {
        $stats['pending_topics'] = 0;
        $stats['ongoing'] = 0;
        $stats['completed'] = 0;
        $stats['ongoing_projects'] = [];
    }
} elseif ($role === 'admin') {
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $stats['users_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects');
    $stats['projects_count'] = (int) $stmt->fetchColumn();
}

$sidebar_links = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'active' => true],
];

if ($role === 'student') {
    $sidebar_links[] = ['label' => 'My Group', 'href' => 'student/group.php', 'icon' => 'bi-people'];
    $sidebar_links[] = ['label' => 'My Project', 'href' => 'student/project.php', 'icon' => 'bi-journal-richtext'];
    $sidebar_links[] = ['label' => 'Logbook', 'href' => 'student/logbook.php', 'icon' => 'bi-book'];
    $sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
} elseif ($role === 'supervisor') {
    $sidebar_links[] = ['label' => 'Assigned Vaults', 'href' => 'supervisor/students.php', 'icon' => 'bi-people'];
    $sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
} elseif ($role === 'hod') {
    $sidebar_links[] = ['label' => 'Topics', 'href' => 'hod/topics.php', 'icon' => 'bi-clock-history'];
    $sidebar_links[] = ['label' => 'Assign Supervisors', 'href' => 'hod/assign.php', 'icon' => 'bi-person-check'];
    $sidebar_links[] = ['label' => 'Archive', 'href' => 'hod/archive.php', 'icon' => 'bi-archive'];
    $sidebar_links[] = ['label' => 'Reports', 'href' => 'hod/reports.php', 'icon' => 'bi-graph-up'];
} elseif ($role === 'admin') {
    $sidebar_links[] = ['label' => 'Manage Users', 'href' => 'admin/users.php', 'icon' => 'bi-people'];
    $sidebar_links[] = ['label' => 'Project Status', 'href' => 'admin/projects.php', 'icon' => 'bi-folder'];
    $sidebar_links[] = ['label' => 'Audit Reports', 'href' => 'admin/reports.php', 'icon' => 'bi-clipboard-data'];
}

$hero_title = 'Welcome back to your workspace';
$hero_copy = 'Track activity, jump into key actions, and keep your projects moving.';
$hero_link = 'vault.php';
$hero_button = 'Open Project Vault';

if ($role === 'student') {
    $hero_title = 'Build your project with confidence';
    $hero_copy = 'Access your group, update your project, and keep your logbook current from one dashboard.';
    $hero_link = 'student/project.php';
    $hero_button = 'Go to My Project';
} elseif ($role === 'supervisor') {
    $hero_title = 'Monitor your assigned vaults';
    $hero_copy = 'Review student progress, clear pending logbook approvals, and reply to messages faster.';
    $hero_link = 'supervisor/students.php';
    $hero_button = 'View Assigned Vaults';
} elseif ($role === 'hod') {
    $hero_title = 'Oversee your department pipeline';
    $hero_copy = 'Approve topics, assign supervisors, and keep visibility on ongoing and completed projects.';
    $hero_link = 'hod/topics.php';
    $hero_button = 'Review Topics';
} elseif ($role === 'admin') {
    $hero_title = 'Manage the platform with clarity';
    $hero_copy = 'Keep user accounts healthy, track project totals, and access reports from a single control center.';
    $hero_link = 'admin/users.php';
    $hero_button = 'Manage Users';
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="dashboard-shell">
    <aside class="dashboard-sidebar">
        <div class="dashboard-sidebar__card dashboard-sidebar__card--brand">
            <div class="dashboard-sidebar__brand-icon"><i class="bi bi-grid-1x2-fill"></i></div>
            <div>
                <div class="dashboard-sidebar__role">FYP Vault</div>
                <div class="dashboard-sidebar__name">Dashboard</div>
            </div>
        </div>
        <div class="dashboard-sidebar__card dashboard-sidebar__card--profile">
            <div class="dashboard-sidebar__avatar"><?= e(strtoupper(substr((string) $user['full_name'], 0, 1))) ?></div>
            <div>
                <div class="dashboard-sidebar__role"><?= e(strtoupper($role)) ?> PORTAL</div>
                <div class="dashboard-sidebar__name"><?= e($user['full_name']) ?></div>
            </div>
        </div>
        <nav class="dashboard-sidebar__nav">
            <?php foreach ($sidebar_links as $link): ?>
                <a class="dashboard-sidebar__link<?= !empty($link['active']) ? ' is-active' : '' ?>" href="<?= base_url($link['href']) ?>">
                    <span class="dashboard-sidebar__link-icon"><i class="bi <?= e($link['icon']) ?>"></i></span>
                    <span><?= e($link['label']) ?></span>
                </a>
            <?php endforeach; ?>
            <a class="dashboard-sidebar__link" href="<?= base_url('profile.php') ?>">
                <span class="dashboard-sidebar__link-icon"><i class="bi bi-person-circle"></i></span>
                <span>Profile</span>
            </a>
            <a class="dashboard-sidebar__link" href="<?= base_url('notifications.php') ?>">
                <span class="dashboard-sidebar__link-icon"><i class="bi bi-bell"></i></span>
                <span>Notifications</span>
            </a>
        </nav>
        <a class="dashboard-sidebar__logout" href="<?= base_url('logout.php') ?>">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </aside>
    <div class="dashboard-content">
        <section class="dashboard-hero">
            <div>
                <div class="dashboard-hero__eyebrow"><?= e(ucfirst($role)) ?> Workspace</div>
                <h1 class="dashboard-hero__title mb-2"><?= e($hero_title) ?></h1>
                <p class="dashboard-hero__copy mb-0"><?= e($hero_copy) ?></p>
            </div>
            <div class="dashboard-hero__actions">
                <a href="<?= base_url($hero_link) ?>" class="btn btn-warning dashboard-hero__btn"><?= e($hero_button) ?></a>
            </div>
        </section>
        <section class="dashboard-main-panels">

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
    <div class="card mb-4">
        <div class="card-header">Ongoing Projects and Assigned Supervisors</div>
        <div class="card-body">
            <?php if (empty($stats['ongoing_projects'])): ?>
                <p class="text-muted mb-0">No ongoing projects in your department.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Vault</th>
                                <th>Project</th>
                                <th>Members / Index</th>
                                <th>Supervisor</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['ongoing_projects'] as $p): ?>
                                <tr>
                                    <td><?= e($p['vault_name']) ?></td>
                                    <td><?= e($p['title']) ?></td>
                                    <td>
                                        <?php if (!empty($p['group_id'])): ?>
                                            <small><?= e($p['member_directory'] ?: '—') ?></small>
                                        <?php else: ?>
                                            <?= e(($p['lead_name'] ?? '—') . ' (' . (($p['lead_reg_number'] ?: $p['lead_email']) ?: '—') . ')') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($p['supervisor_name'] ?: 'Not assigned') ?></td>
                                    <td><span class="badge bg-secondary"><?= e(str_replace('_', ' ', (string) $p['status'])) ?></span></td>
                                    <td><?= !empty($p['updated_at']) ? e(date('M j, Y', strtotime((string) $p['updated_at']))) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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
            <a href="<?= base_url('admin/projects.php') ?>" class="text-decoration-none text-reset d-block h-100">
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
            <a href="<?= base_url('admin/projects.php') ?>" class="btn btn-outline-primary">Project Status</a>
        </div>
    </div>
<?php endif; ?>

        </section>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
