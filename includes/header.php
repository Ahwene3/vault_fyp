<?php
if (!isset($pageTitle)) $pageTitle = 'Project Vault';
$user = current_user();
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
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?= base_url('dashboard.php') ?>"><i class="bi bi-shield-lock me-1"></i> FYP Vault</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto">
                    <?php if ($user): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('dashboard.php') ?>">Dashboard</a></li>
                        <?php if ($user['role'] === 'student'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('student/project.php') ?>">My Project</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('student/logbook.php') ?>">Logbook</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('messages.php') ?>">Messages</a></li>
                        <?php elseif ($user['role'] === 'supervisor'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('supervisor/students.php') ?>">Group Vaults</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('messages.php') ?>">Messages</a></li>
                        <?php elseif ($user['role'] === 'hod'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('hod/topics.php') ?>">Topic Approval</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('hod/assign.php') ?>">Assign Supervisors</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('hod/archive.php') ?>">Archive</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('hod/reports.php') ?>">Reports</a></li>
                        <?php elseif ($user['role'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/users.php') ?>">Users</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/reports.php') ?>">Reports</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('vault.php') ?>">Vault</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('vault.php') ?>">Project Vault</a></li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if ($user):
                        $pdo = getPDO();
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
                        $stmt->execute([$user['id']]);
                        $unread_count = (int) $stmt->fetchColumn();
                    ?>
                        <li class="nav-item">
                            <a class="nav-link position-relative notification-link" href="<?= base_url('notifications.php') ?>" aria-label="View notifications">
                                <svg class="notification-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                    <path d="M8 16a2 2 0 0 0 1.985-1.75h-3.97A2 2 0 0 0 8 16Zm.104-14.995a1 1 0 0 0-.208 0A2.5 2.5 0 0 0 5.5 3.5v.628c0 .54-.214 1.058-.595 1.439L4.12 6.352A2.5 2.5 0 0 0 3.5 8.12V10l-.809 1.213A.75.75 0 0 0 3.309 12.5h9.382a.75.75 0 0 0 .618-1.287L12.5 10V8.12a2.5 2.5 0 0 0-.62-1.768l-.785-.785A2.034 2.034 0 0 1 10.5 4.128V3.5a2.5 2.5 0 0 0-2.396-2.495Z"/>
                                </svg>
                                <?php if ($unread_count): ?><span class="notification-badge badge bg-danger rounded-pill"><?= $unread_count ?></span><?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle me-1"></i> <?= e($user['full_name']) ?> (<?= e(ucfirst($user['role'])) ?>)
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="<?= base_url('profile.php') ?>">Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?= base_url('logout.php') ?>">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('index.php') ?>">Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url('register.php') ?>">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <section class="quick-nav-bar border-bottom">
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="js-nav-back" title="Go to previous page">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="js-nav-forward" title="Go to next page">
                    Forward <i class="bi bi-arrow-right"></i>
                </button>
                <?php if ($user): ?>
                    <a href="<?= base_url('dashboard.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-house-door"></i> Dashboard
                    </a>
                    <a href="<?= base_url('vault.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-archive"></i> Vault
                    </a>
                <?php else: ?>
                    <a href="<?= base_url('index.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-in-right"></i> Login
                    </a>
                    <a href="<?= base_url('register.php') ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-person-plus"></i> Register
                    </a>
                <?php endif; ?>
            </div>
            <div class="quick-nav-tip small" id="js-fun-tip" aria-live="polite">Tip: Use Back and Forward to move quickly through your workflow.</div>
        </div>
    </section>
    <main class="container py-4">
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
