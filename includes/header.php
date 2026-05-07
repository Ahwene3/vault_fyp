<?php
if (!isset($pageTitle)) $pageTitle = 'Project Vault';
$user = current_user();
$hidePrimaryNav = !empty($hidePrimaryNav);
$disableAppSidebar = !empty($disableAppSidebar);
$renderAppSidebar = !empty($user) && !$disableAppSidebar;
$mainContainerClass = $mainContainerClass ?? ($renderAppSidebar ? 'container-fluid app-main-wrap' : 'container py-4');
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
$topbarVariant = isset($topbarVariant) ? trim((string) $topbarVariant) : 'default';
$topbarDepartment = isset($topbarDepartment) ? trim((string) $topbarDepartment) : '';
$topbarDate = isset($topbarDate) ? trim((string) $topbarDate) : '';
$topbarBreadcrumbCurrent = isset($topbarBreadcrumbCurrent) ? trim((string) $topbarBreadcrumbCurrent) : '';
$appSidebarBrandName = isset($appSidebarBrandName) ? trim((string) $appSidebarBrandName) : 'FYP Vault';
$appSidebarBrandSubtitle = isset($appSidebarBrandSubtitle) ? trim((string) $appSidebarBrandSubtitle) : 'Workspace';
$appSidebarRoleLabel = isset($appSidebarRoleLabel) ? trim((string) $appSidebarRoleLabel) : '';
$notification_count = 0;
if (!empty($user)) {
    $pdo_header = getPDO();
    $stmt = $pdo_header->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([(int) ($user['id'] ?? 0)]);
    $notification_count = (int) $stmt->fetchColumn();
}

$current_path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$base_path = trim((string) (defined('BASE_PATH') ? BASE_PATH : ''), '/');
if ($base_path !== '') {
    if ($current_path === $base_path) {
        $current_path = '';
    } elseif (strpos($current_path, $base_path . '/') === 0) {
        $current_path = substr($current_path, strlen($base_path) + 1);
    }
}
if ($current_path === '') {
    $current_path = 'dashboard.php';
}

$student_theme_paths = ['dashboard.php', 'vault.php', 'profile.php', 'notifications.php', 'messages.php', 'student/group.php', 'student/project.php', 'student/logbook.php'];
$supervisor_theme_paths = ['dashboard.php', 'vault.php', 'profile.php', 'notifications.php', 'messages.php', 'supervisor/students.php', 'supervisor/student_detail.php', 'supervisor/assessment.php', 'supervisor/logsheet.php', 'supervisor/view_document.php', 'supervisor/export_log.php'];
$hod_theme_paths = ['dashboard.php', 'vault.php', 'profile.php', 'notifications.php', 'messages.php'];
$is_student_area = !empty($user)
    && ($user['role'] ?? '') === 'student'
    && (in_array($current_path, $student_theme_paths, true) || strpos($current_path, 'student/') === 0);
$is_supervisor_area = !empty($user)
    && ($user['role'] ?? '') === 'supervisor'
    && (in_array($current_path, $supervisor_theme_paths, true) || strpos($current_path, 'supervisor/') === 0);
$is_hod_area = !empty($user)
    && ($user['role'] ?? '') === 'hod'
    && (in_array($current_path, $hod_theme_paths, true) || strpos($current_path, 'hod/') === 0);

if ($is_student_area) {
    if ($topbarVariant === 'default' || $topbarVariant === '') {
        $topbarVariant = 'student-dashboard';
    }
    if ($topbarBreadcrumbCurrent === '') {
        $topbarBreadcrumbCurrent = (string) ($pageTitle ?: 'Dashboard');
    }
    if ($appSidebarBrandSubtitle === '' || $appSidebarBrandSubtitle === 'Workspace') {
        $appSidebarBrandSubtitle = 'Collaboration Hub';
    }
    if ($appSidebarRoleLabel === '') {
        $appSidebarRoleLabel = 'Student Portal';
    }
    if (strpos(' ' . $bodyClass . ' ', ' student-dashboard ') === false) {
        $bodyClass = trim($bodyClass . ' student-dashboard');
    }
    if (!isset($hideFooter)) {
        $hideFooter = true;
    }
}

if ($is_supervisor_area) {
    if ($topbarVariant === 'default' || $topbarVariant === '') {
        $topbarVariant = 'supervisor-dashboard';
    }
    if ($topbarBreadcrumbCurrent === '') {
        $topbarBreadcrumbCurrent = (string) ($pageTitle ?: 'Supervisor Dashboard');
    }
    if ($appSidebarBrandSubtitle === '' || $appSidebarBrandSubtitle === 'Workspace') {
        $appSidebarBrandSubtitle = 'Collaboration Hub';
    }
    if ($appSidebarRoleLabel === '') {
        $appSidebarRoleLabel = 'Supervisor Portal';
    }
    if (strpos(' ' . $bodyClass . ' ', ' supervisor-dashboard ') === false) {
        $bodyClass = trim($bodyClass . ' supervisor-dashboard');
    }
    if (!isset($hideFooter)) {
        $hideFooter = true;
    }
}

if ($is_hod_area) {
    if ($topbarVariant === 'default' || $topbarVariant === '') {
        $topbarVariant = 'hod-dashboard';
    }
    if ($topbarDepartment === '') {
        $hod_department_source = (string) ($user['department'] ?? '');
        $fresh_user = get_user_by_id((int) ($user['id'] ?? 0));
        if ($fresh_user) {
            $hod_department_source = (string) ($fresh_user['department'] ?? $hod_department_source);
        }
        $hod_department_info = resolve_department_info(getPDO(), $hod_department_source);
        $topbarDepartment = (string) ($hod_department_info['name'] ?: $hod_department_info['raw'] ?: 'Department');
    }
    if ($topbarDate === '') {
        $topbarDate = date('M j, Y');
    }
    if ($topbarBreadcrumbCurrent === '') {
        $topbarBreadcrumbCurrent = $current_path === 'dashboard.php' ? 'HOD Dashboard' : (string) ($pageTitle ?: 'HOD');
    }
    if ($appSidebarBrandSubtitle === '' || $appSidebarBrandSubtitle === 'Workspace') {
        $appSidebarBrandSubtitle = 'Collaboration Hub';
    }
    if ($appSidebarRoleLabel === '') {
        $appSidebarRoleLabel = 'Head of Department';
    }
    if (strpos(' ' . $bodyClass . ' ', ' hod-dashboard ') === false) {
        $bodyClass = trim($bodyClass . ' hod-dashboard');
    }
    if (!isset($hideFooter)) {
        $hideFooter = true;
    }
}

$app_sidebar_links = [];
if ($renderAppSidebar) {
    $app_sidebar_links[] = ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-speedometer2'];
    if ($user['role'] === 'student') {
        $app_sidebar_links[] = ['label' => 'My Group', 'href' => 'student/group.php', 'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'Submit Topic/Proposal', 'href' => 'student/group_submit.php', 'icon' => 'bi-send'];
        $app_sidebar_links[] = ['label' => 'My Project', 'href' => 'student/project.php', 'icon' => 'bi-journal-richtext'];
        $app_sidebar_links[] = ['label' => 'Logbook', 'href' => 'student/logbook.php', 'icon' => 'bi-book'];
        $app_sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
    } elseif ($user['role'] === 'supervisor') {
        $app_sidebar_links[] = ['label' => 'Group Vaults', 'href' => 'supervisor/students.php', 'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
    } elseif ($user['role'] === 'hod') {
        $app_sidebar_links[] = ['label' => 'Form Groups', 'href' => 'hod/group_import.php', 'icon' => 'bi-file-arrow-up'];
        $app_sidebar_links[] = ['label' => 'Review Submissions', 'href' => 'hod/group_review.php', 'icon' => 'bi-clipboard-check'];
        $app_sidebar_links[] = ['label' => 'Topics', 'href' => 'hod/topics.php', 'icon' => 'bi-clock-history'];
        $app_sidebar_links[] = ['label' => 'Assign Supervisors', 'href' => 'hod/assign.php', 'icon' => 'bi-person-check'];
        $app_sidebar_links[] = ['label' => 'Archive', 'href' => 'hod/archive.php', 'icon' => 'bi-archive'];
        $app_sidebar_links[] = ['label' => 'Reports', 'href' => 'hod/reports.php', 'icon' => 'bi-graph-up'];
    } elseif ($user['role'] === 'admin') {
        $app_sidebar_links[] = ['label' => 'Users',            'href' => 'admin/users.php',            'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'Projects',         'href' => 'admin/projects.php',         'icon' => 'bi-folder'];
        $app_sidebar_links[] = ['label' => 'Moderate Reviews', 'href' => 'admin/moderate_reviews.php', 'icon' => 'bi-shield-check'];
        $app_sidebar_links[] = ['label' => 'Reports',          'href' => 'admin/reports.php',          'icon' => 'bi-clipboard-data'];
    }
    $app_sidebar_links[] = ['label' => 'Discover Projects', 'href' => 'vault.php', 'icon' => 'bi-search'];
    $app_sidebar_links[] = ['label' => 'Profile', 'href' => 'profile.php', 'icon' => 'bi-person-circle'];
    $app_sidebar_links[] = ['label' => 'Notifications', 'href' => 'notifications.php', 'icon' => 'bi-bell', 'badge' => $notification_count > 0 ? $notification_count : null];
}

$user_initial = strtoupper(substr(trim((string) ($user['full_name'] ?? 'U')), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | FYP Vault</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/modern.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
    <?php if (!$hidePrimaryNav): ?>
    <nav class="navbar navbar-dark bg-primary app-topbar<?= $topbarVariant === 'hod-dashboard' ? ' app-topbar--hod' : ($topbarVariant === 'student-dashboard' ? ' app-topbar--student' : ($topbarVariant === 'supervisor-dashboard' ? ' app-topbar--supervisor' : '')) ?>">
        <div class="container-fluid app-topbar__inner">
            <?php if ($topbarVariant === 'supervisor-dashboard' && $user): ?>
                <div class="app-topbar-supervisor__left">
                    <div class="navbar-quick-actions">
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-back" title="Go to previous page">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-forward" title="Go to next page">
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="app-topbar-supervisor__breadcrumb">
                    <span>FYP Vault</span>
                    <i class="bi bi-chevron-right"></i>
                    <span class="is-current"><?= e($topbarBreadcrumbCurrent ?: 'Supervisor Dashboard') ?></span>
                </div>
                <div class="app-topbar-supervisor__right">
                    <a class="app-topbar-supervisor__bell" href="<?= base_url('notifications.php') ?>" aria-label="View notifications">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($notification_count): ?><span class="app-topbar-supervisor__badge"><?= (int) $notification_count ?></span><?php endif; ?>
                    </a>
                    <div class="dropdown">
                        <a class="app-topbar-supervisor__profile dropdown-toggle" href="#" id="supervisorUserDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="app-topbar-supervisor__avatar"><?= e($user_initial) ?></span>
                            <span class="app-topbar-supervisor__meta">
                                <span class="app-topbar-supervisor__name"><?= e($user['full_name']) ?></span>
                                <span class="app-topbar-supervisor__role">Supervisor Portal</span>
                            </span>
                            <i class="bi bi-chevron-down"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="supervisorUserDropdown">
                            <li><a class="dropdown-item" href="<?= base_url('profile.php') ?>">Profile</a></li>
                            <li><a class="dropdown-item" href="<?= base_url('notifications.php') ?>">Notifications</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= base_url('logout.php') ?>">Logout</a></li>
                        </ul>
                    </div>
                </div>
            <?php elseif ($topbarVariant === 'student-dashboard' && $user): ?>
                <div class="app-topbar-student__left">
                    <div class="navbar-quick-actions">
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-back" title="Go to previous page">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-forward" title="Go to next page">
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="app-topbar-student__breadcrumb">
                    <span>FYP Vault</span>
                    <i class="bi bi-chevron-right"></i>
                    <span class="is-current"><?= e($topbarBreadcrumbCurrent ?: 'Dashboard') ?></span>
                </div>
                <div class="app-topbar-student__right">
                    <a class="app-topbar-student__bell" href="<?= base_url('notifications.php') ?>" aria-label="View notifications">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($notification_count): ?><span class="app-topbar-student__badge"><?= (int) $notification_count ?></span><?php endif; ?>
                    </a>
                    <div class="dropdown">
                        <a class="app-topbar-student__profile dropdown-toggle" href="#" id="studentUserDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="app-topbar-student__avatar"><?= e($user_initial) ?></span>
                            <span class="app-topbar-student__meta">
                                <span class="app-topbar-student__name"><?= e($user['full_name']) ?></span>
                                <span class="app-topbar-student__role">Student Portal</span>
                            </span>
                            <i class="bi bi-chevron-down"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="studentUserDropdown">
                            <li><a class="dropdown-item" href="<?= base_url('profile.php') ?>">Profile</a></li>
                            <li><a class="dropdown-item" href="<?= base_url('notifications.php') ?>">Notifications</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= base_url('logout.php') ?>">Logout</a></li>
                        </ul>
                    </div>
                </div>
            <?php elseif ($topbarVariant === 'hod-dashboard' && $user): ?>
                <div class="app-topbar-hod__left">
                    <div class="navbar-quick-actions">
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-back" title="Go to previous page">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-forward" title="Go to next page">
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                    <div class="app-topbar-hod__breadcrumb">
                        <span>FYP Vault</span>
                        <i class="bi bi-chevron-right"></i>
                        <span class="is-current"><?= e($topbarBreadcrumbCurrent ?: 'HOD Dashboard') ?></span>
                    </div>
                </div>
                <div class="app-topbar-hod__right">
                    <span class="app-topbar-hod__department"><?= e($topbarDepartment ?: 'Department') ?></span>
                    <span class="app-topbar-hod__date"><?= e($topbarDate ?: date('M j, Y')) ?></span>
                </div>
            <?php else: ?>
                <a class="navbar-brand fw-bold app-topbar__brand" href="<?= base_url('dashboard.php') ?>">
                    <i class="bi bi-shield-lock"></i><span>FYP Vault</span>
                </a>
                <div class="d-flex align-items-center ms-auto app-topbar__actions">
                    <?php if ($user): ?>
                        <div class="navbar-quick-actions me-2">
                            <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-back" title="Go to previous page">
                                <i class="bi bi-arrow-left"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-light" id="js-nav-forward" title="Go to next page">
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                        <a class="nav-link position-relative notification-link me-2" href="<?= base_url('notifications.php') ?>" aria-label="View notifications">
                            <svg class="notification-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                <path d="M8 16a2 2 0 0 0 1.985-1.75h-3.97A2 2 0 0 0 8 16Zm.104-14.995a1 1 0 0 0-.208 0A2.5 2.5 0 0 0 5.5 3.5v.628c0 .54-.214 1.058-.595 1.439L4.12 6.352A2.5 2.5 0 0 0 3.5 8.12V10l-.809 1.213A.75.75 0 0 0 3.309 12.5h9.382a.75.75 0 0 0 .618-1.287L12.5 10V8.12a2.5 2.5 0 0 0-.62-1.768l-.785-.785A2.034 2.034 0 0 1 10.5 4.128V3.5a2.5 2.5 0 0 0-2.396-2.495Z"/>
                                </svg>
                            <?php if ($notification_count): ?><span class="notification-badge badge bg-danger rounded-pill"><?= $notification_count ?></span><?php endif; ?>
                        </a>
                        <div class="dropdown">
                            <a class="nav-link dropdown-toggle app-topbar__profile" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle me-1"></i> <?= e($user['full_name']) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="<?= base_url('profile.php') ?>">Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?= base_url('logout.php') ?>">Logout</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-light me-2" href="<?= base_url('index.php') ?>">Login</a>
                        <a class="btn btn-sm btn-light" href="<?= base_url('register.php') ?>">Register</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    <main class="<?= e($mainContainerClass) ?>">
        <?php if ($renderAppSidebar): ?>
            <div class="app-shell">
                <aside class="app-sidebar">
                    <div class="app-sidebar__card app-sidebar__card--brand">
                        <div class="app-sidebar__icon"><i class="bi bi-shield-lock-fill"></i></div>
                        <div>
                            <div class="app-sidebar__label"><?= e($appSidebarBrandName) ?></div>
                            <div class="app-sidebar__title"><?= e($appSidebarBrandSubtitle) ?></div>
                        </div>
                    </div>
                    <div class="app-sidebar__card app-sidebar__card--profile">
                        <div class="app-sidebar__avatar"><?= e($user_initial) ?></div>
                        <div>
                            <div class="app-sidebar__title"><?= e($user['full_name']) ?></div>
                            <div class="app-sidebar__label"><?= e($appSidebarRoleLabel !== '' ? $appSidebarRoleLabel : (strtoupper((string) $user['role']) . ' Portal')) ?></div>
                        </div>
                    </div>
                    <nav class="app-sidebar__nav">
                        <?php foreach ($app_sidebar_links as $link):
                            $link_path = ltrim($link['href'], '/');
                            $is_active = ($current_path === $link_path) || ($link_path === 'dashboard.php' && $current_path === '');
                        ?>
                            <a class="app-sidebar__link<?= $is_active ? ' is-active' : '' ?>" href="<?= base_url($link['href']) ?>">
                                <span class="app-sidebar__link-icon"><i class="bi <?= e($link['icon']) ?>"></i></span>
                                <span><?= e($link['label']) ?></span>
                                <?php if (!empty($link['badge'])): ?><span class="app-sidebar__badge"><?= (int) $link['badge'] ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <a class="app-sidebar__logout" href="<?= base_url('logout.php') ?>">
                        <i class="bi bi-box-arrow-right"></i><span>Logout</span>
                    </a>
                </aside>
                <section class="app-content">
        <?php endif; ?>
        <?php
        $success = flash('success');
        $error = flash('error');
        if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif;
        if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
