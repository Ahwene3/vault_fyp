<?php
if (!isset($pageTitle)) $pageTitle = 'Project Vault';
$user = current_user();
$hidePrimaryNav = !empty($hidePrimaryNav);
$disableAppSidebar = !empty($disableAppSidebar);
$renderAppSidebar = !empty($user) && !$disableAppSidebar;
$mainContainerClass = $mainContainerClass ?? ($renderAppSidebar ? 'container-fluid app-main-wrap' : 'container py-4');
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';

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

$app_sidebar_links = [];
if ($renderAppSidebar) {
    $app_sidebar_links[] = ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-speedometer2'];
    if ($user['role'] === 'student') {
        $app_sidebar_links[] = ['label' => 'My Group', 'href' => 'student/group.php', 'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'My Project', 'href' => 'student/project.php', 'icon' => 'bi-journal-richtext'];
        $app_sidebar_links[] = ['label' => 'Logbook', 'href' => 'student/logbook.php', 'icon' => 'bi-book'];
        $app_sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
    } elseif ($user['role'] === 'supervisor') {
        $app_sidebar_links[] = ['label' => 'Group Vaults', 'href' => 'supervisor/students.php', 'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots'];
    } elseif ($user['role'] === 'hod') {
        $app_sidebar_links[] = ['label' => 'Topics', 'href' => 'hod/topics.php', 'icon' => 'bi-clock-history'];
        $app_sidebar_links[] = ['label' => 'Assign Supervisors', 'href' => 'hod/assign.php', 'icon' => 'bi-person-check'];
        $app_sidebar_links[] = ['label' => 'Archive', 'href' => 'hod/archive.php', 'icon' => 'bi-archive'];
        $app_sidebar_links[] = ['label' => 'Reports', 'href' => 'hod/reports.php', 'icon' => 'bi-graph-up'];
    } elseif ($user['role'] === 'admin') {
        $app_sidebar_links[] = ['label' => 'Users', 'href' => 'admin/users.php', 'icon' => 'bi-people'];
        $app_sidebar_links[] = ['label' => 'Projects', 'href' => 'admin/projects.php', 'icon' => 'bi-folder'];
        $app_sidebar_links[] = ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'bi-clipboard-data'];
    }
    $app_sidebar_links[] = ['label' => 'Project Vault', 'href' => 'vault.php', 'icon' => 'bi-archive'];
    $app_sidebar_links[] = ['label' => 'Profile', 'href' => 'profile.php', 'icon' => 'bi-person-circle'];
    $app_sidebar_links[] = ['label' => 'Notifications', 'href' => 'notifications.php', 'icon' => 'bi-bell'];
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
    <nav class="navbar navbar-dark bg-primary app-topbar">
        <div class="container-fluid app-topbar__inner">
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
                    <?php
                    $pdo = getPDO();
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
                    $stmt->execute([$user['id']]);
                    $unread_count = (int) $stmt->fetchColumn();
                    ?>
                    <a class="nav-link position-relative notification-link me-2" href="<?= base_url('notifications.php') ?>" aria-label="View notifications">
                        <svg class="notification-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                            <path d="M8 16a2 2 0 0 0 1.985-1.75h-3.97A2 2 0 0 0 8 16Zm.104-14.995a1 1 0 0 0-.208 0A2.5 2.5 0 0 0 5.5 3.5v.628c0 .54-.214 1.058-.595 1.439L4.12 6.352A2.5 2.5 0 0 0 3.5 8.12V10l-.809 1.213A.75.75 0 0 0 3.309 12.5h9.382a.75.75 0 0 0 .618-1.287L12.5 10V8.12a2.5 2.5 0 0 0-.62-1.768l-.785-.785A2.034 2.034 0 0 1 10.5 4.128V3.5a2.5 2.5 0 0 0-2.396-2.495Z"/>
                        </svg>
                        <?php if ($unread_count): ?><span class="notification-badge badge bg-danger rounded-pill"><?= $unread_count ?></span><?php endif; ?>
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
        </div>
    </nav>
    <?php endif; ?>
    <main class="<?= e($mainContainerClass) ?>">
        <?php if ($renderAppSidebar): ?>
            <div class="app-shell">
                <aside class="app-sidebar">
                    <div class="app-sidebar__card app-sidebar__card--brand">
                        <div class="app-sidebar__icon"><i class="bi bi-grid-1x2-fill"></i></div>
                        <div>
                            <div class="app-sidebar__label">FYP Vault</div>
                            <div class="app-sidebar__title">Workspace</div>
                        </div>
                    </div>
                    <div class="app-sidebar__card app-sidebar__card--profile">
                        <div class="app-sidebar__avatar"><?= e($user_initial) ?></div>
                        <div>
                            <div class="app-sidebar__label"><?= e(strtoupper((string) $user['role'])) ?> Portal</div>
                            <div class="app-sidebar__title"><?= e($user['full_name']) ?></div>
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
