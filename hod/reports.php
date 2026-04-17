<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$report_type = $_GET['type'] ?? 'overview';

// Overview stats
$stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status = "submitted"');
$pending_topics = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status IN ("approved", "in_progress")');
$ongoing = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status = "completed"');
$completed = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) FROM projects WHERE status = "rejected"');
$rejected = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) FROM projects');
$total = (int) $stmt->fetchColumn();

// Completion rates
$stmt = $pdo->query('SELECT COUNT(DISTINCT student_id) FROM projects');
$total_students = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(DISTINCT student_id) FROM projects WHERE status = "completed"');
$completed_students = (int) $stmt->fetchColumn();

$completion_rate = $total_students > 0 ? round(($completed_students / $total_students) * 100, 2) : 0;

// Supervisor workload
$stmt = $pdo->query('SELECT 
    u.id, u.full_name, 
    COUNT(p.id) as project_count,
    SUM(CASE WHEN p.status IN ("approved", "in_progress") THEN 1 ELSE 0 END) as active_count
    FROM users u 
    LEFT JOIN projects p ON u.id = p.supervisor_id
    WHERE u.role = "supervisor"
    GROUP BY u.id, u.full_name
    ORDER BY active_count DESC');
$supervisor_workload = $stmt->fetchAll();

// Duplicate topics (same title)
$stmt = $pdo->query('SELECT title, COUNT(*) as count, GROUP_CONCAT(u.full_name) as students
    FROM projects p
    JOIN users u ON p.student_id = u.id
    WHERE p.status IN ("submitted", "approved", "in_progress", "completed")
    GROUP BY title
    HAVING count > 1
    ORDER BY count DESC');
$duplicate_topics = $stmt->fetchAll();

// Timeline stats
$stmt = $pdo->query('SELECT 
    MONTH(submitted_at) as month,
    COUNT(*) as submissions
    FROM projects
    WHERE submitted_at IS NOT NULL AND status != "draft"
    GROUP BY MONTH(submitted_at)
    ORDER BY month');
$monthly_submissions = $stmt->fetchAll();

// Pending topics (awaiting approval)
$stmt = $pdo->query('SELECT p.id, p.title, u.full_name, u.email, u.reg_number, p.submitted_at
    FROM projects p
    JOIN users u ON p.student_id = u.id
    WHERE p.status = "submitted"
    ORDER BY p.submitted_at ASC');
$pending_list = $stmt->fetchAll();

$by_status = $pdo->query('SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status')->fetchAll();
$by_year = $pdo->query('SELECT academic_year, COUNT(*) AS cnt FROM projects WHERE academic_year IS NOT NULL GROUP BY academic_year ORDER BY academic_year DESC')->fetchAll();

$pageTitle = 'Department Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Department Reports</h1>

<ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $report_type === 'overview' ? 'active' : '' ?>" href="<?= base_url('hod/reports.php?type=overview') ?>">Overview</a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $report_type === 'supervisor' ? 'active' : '' ?>" href="<?= base_url('hod/reports.php?type=supervisor') ?>">Supervisor Workload</a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $report_type === 'duplicates' ? 'active' : '' ?>" href="<?= base_url('hod/reports.php?type=duplicates') ?>">Duplicate Topics</a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $report_type === 'pending' ? 'active' : '' ?>" href="<?= base_url('hod/reports.php?type=pending') ?>">Pending Approvals</a>
    </li>
</ul>

<?php if ($report_type === 'overview'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Total Students</h6>
                    <h2 class="text-primary mb-0"><?= $total_students ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Completion Rate</h6>
                    <h2 class="text-success mb-0"><?= $completion_rate ?>%</h2>
                    <small class="text-muted"><?= $completed_students ?> of <?= $total_students ?> completed</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Ongoing Projects</h6>
                    <h2 class="text-info mb-0"><?= $ongoing ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Pending Topics</h6>
                    <h2 class="text-warning mb-0"><?= $pending_topics ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">By Status</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach ($by_status as $r): ?>
                        <tr><td><?= e($r['status']) ?></td><td><?= (int) $r['cnt'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($report_type === 'supervisor'): ?>
    <div class="card">
        <div class="card-header">Supervisor Project Assignments</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Supervisor</th>
                            <th>Total Projects</th>
                            <th>Active Projects</th>
                            <th>Workload %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_active = array_sum(array_column($supervisor_workload, 'active_count'));
                        foreach ($supervisor_workload as $s): 
                        ?>
                            <tr>
                                <td><?= e($s['full_name']) ?></td>
                                <td><?= $s['project_count'] ?></td>
                                <td><span class="badge bg-info"><?= $s['active_count'] ?></span></td>
                                <td>
                                    <?php $pct = $total_active > 0 ? round(($s['active_count'] / $total_active) * 100, 1) : 0; ?>
                                    <?= $pct ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($report_type === 'duplicates'): ?>
    <div class="card">
        <div class="card-header">Flagged Duplicate Topics</div>
        <div class="card-body">
            <?php if (empty($duplicate_topics)): ?>
                <p class="text-muted mb-0">No duplicate topics found. ✓</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($duplicate_topics as $dup): ?>
                        <div class="list-group-item">
                            <h6><?= e($dup['title']) ?></h6>
                            <p class="mb-1 text-danger"><strong>⚠️ <?= $dup['count'] ?> students submitted same topic</strong></p>
                            <small class="text-muted">Students: <?= e($dup['students']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($report_type === 'pending'): ?>
    <div class="card">
        <div class="card-header">Pending Topic Approvals (<?= count($pending_list) ?>)</div>
        <div class="card-body">
            <?php if (empty($pending_list)): ?>
                <p class="text-muted mb-0">No pending topics. ✓</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($pending_list as $p): ?>
                        <div class="list-group-item">
                            <h6><?= e($p['title']) ?></h6>
                            <small class="text-muted">
                                <?= e($p['full_name']) ?> (<?= e($p['reg_number'] ?? $p['email']) ?>) — 
                                Submitted <?= e(date('M j, Y', strtotime($p['submitted_at']))) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
