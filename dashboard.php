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
    $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$uid]);
    $stats['project'] = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries WHERE project_id = (SELECT id FROM projects WHERE student_id = ? LIMIT 1)');
    $stmt->execute([$uid]);
    $stats['logbook_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'supervisor') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE supervisor_id = ? AND status IN ("in_progress","submitted","approved")');
    $stmt->execute([$uid]);
    $stats['students_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries le JOIN projects p ON le.project_id = p.id WHERE p.supervisor_id = ? AND le.supervisor_approved IS NULL');
    $stmt->execute([$uid]);
    $stats['pending_logbook'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'hod') {
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status = "submitted"');
    $stats['pending_topics'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status IN ("in_progress","approved")');
    $stats['ongoing'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status = "completed"');
    $stats['completed'] = (int) $stmt->fetchColumn();
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
        <div class="col-md-4">
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
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-success me-3"><i class="bi bi-book"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Logbook Entries</h6>
                        <span class="fw-bold"><?= (int)($stats['logbook_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-info me-3"><i class="bi bi-chat-dots"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Unread Messages</h6>
                        <span class="fw-bold"><?= (int)($stats['unread_messages'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('student/project.php') ?>" class="btn btn-primary me-2">My Project / Submit Topic</a>
            <a href="<?= base_url('student/logbook.php') ?>" class="btn btn-outline-primary me-2">Logbook</a>
            <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'supervisor'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Assigned Students</h6>
                        <span class="fw-bold"><?= (int)($stats['students_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-warning me-3"><i class="bi bi-journal-check"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Pending Logbook Reviews</h6>
                        <span class="fw-bold"><?= (int)($stats['pending_logbook'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-info me-3"><i class="bi bi-chat-dots"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Unread Messages</h6>
                        <span class="fw-bold"><?= (int)($stats['unread_messages'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('supervisor/students.php') ?>" class="btn btn-primary me-2">View My Students</a>
            <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'hod'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-warning me-3"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Topics Pending Approval</h6>
                        <span class="fw-bold"><?= (int)($stats['pending_topics'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-primary me-3"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Ongoing Projects</h6>
                        <span class="fw-bold"><?= (int)($stats['ongoing'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-success me-3"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Completed</h6>
                        <span class="fw-bold"><?= (int)($stats['completed'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
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
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Total Users</h6>
                        <span class="fw-bold"><?= (int)($stats['users_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="text-success me-3"><i class="bi bi-folder"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Total Projects</h6>
                        <span class="fw-bold"><?= (int)($stats['projects_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('admin/users.php') ?>" class="btn btn-primary me-2">Manage Users</a>
            <a href="<?= base_url('vault.php') ?>" class="btn btn-outline-primary">Project Vault</a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
