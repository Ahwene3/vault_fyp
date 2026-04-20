<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
$uid = user_id();

$pdo->exec('CREATE TABLE IF NOT EXISTS bulk_import_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM("users", "pairings", "topics") NOT NULL,
    imported_by INT UNSIGNED NOT NULL,
    file_name VARCHAR(255),
    total_rows INT UNSIGNED,
    successful_rows INT UNSIGNED,
    failed_rows INT UNSIGNED,
    error_details LONGTEXT,
    status ENUM("pending", "processing", "completed", "failed") DEFAULT "pending",
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_type (import_type),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $total_users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $active_users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $inactive_users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
    $total_projects = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $failed_imports_30d = (int) $pdo->query('SELECT COUNT(*) FROM bulk_import_logs WHERE failed_rows > 0 AND created_at >= (NOW() - INTERVAL 30 DAY)')->fetchColumn();

    $role_rows = $pdo->query('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY role')->fetchAll();
    $status_rows = $pdo->query('SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status ORDER BY status')->fetchAll();
    $import_rows = $pdo->query('SELECT id, import_type, file_name, total_rows, successful_rows, failed_rows, status, created_at, completed_at FROM bulk_import_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="admin_audit_report_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Key', 'Value', 'Details']);
    fputcsv($out, ['Overview', 'Generated At', date('Y-m-d H:i:s'), '']);
    fputcsv($out, ['Overview', 'Total Users', $total_users, '']);
    fputcsv($out, ['Overview', 'Active Users', $active_users, '']);
    fputcsv($out, ['Overview', 'Inactive Users', $inactive_users, '']);
    fputcsv($out, ['Overview', 'Total Projects', $total_projects, '']);
    fputcsv($out, ['Overview', 'Imports with Failures (30d)', $failed_imports_30d, '']);

    foreach ($role_rows as $r) {
        fputcsv($out, ['Users by Role', (string) $r['role'], (int) $r['cnt'], '']);
    }

    foreach ($status_rows as $s) {
        fputcsv($out, ['Projects by Status', (string) $s['status'], (int) $s['cnt'], '']);
    }

    foreach ($import_rows as $i) {
        $details = 'File=' . (string) ($i['file_name'] ?? '')
            . '; Total=' . (int) ($i['total_rows'] ?? 0)
            . '; Success=' . (int) ($i['successful_rows'] ?? 0)
            . '; Failed=' . (int) ($i['failed_rows'] ?? 0)
            . '; Created=' . (string) ($i['created_at'] ?? '')
            . '; Completed=' . (string) ($i['completed_at'] ?? '');
        fputcsv($out, ['Recent Imports', '#' . (int) $i['id'] . ' ' . (string) $i['import_type'], (string) $i['status'], $details]);
    }

    fclose($out);
    exit;
}

$total_users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$active_users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$inactive_users = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
$total_projects = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$failed_imports_30d = (int) $pdo->query('SELECT COUNT(*) FROM bulk_import_logs WHERE failed_rows > 0 AND created_at >= (NOW() - INTERVAL 30 DAY)')->fetchColumn();

$role_rows = $pdo->query('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY role')->fetchAll();
$role_labels = [];
$role_values = [];
foreach ($role_rows as $r) {
    $role_labels[] = ucfirst((string) $r['role']);
    $role_values[] = (int) $r['cnt'];
}

$status_rows = $pdo->query('SELECT status, COUNT(*) AS cnt FROM projects GROUP BY status ORDER BY status')->fetchAll();
$status_labels = [];
$status_values = [];
foreach ($status_rows as $s) {
    $status_labels[] = ucfirst(str_replace('_', ' ', (string) $s['status']));
    $status_values[] = (int) $s['cnt'];
}

$recent_imports = $pdo->query('SELECT id, import_type, file_name, total_rows, successful_rows, failed_rows, status, created_at, completed_at, error_details FROM bulk_import_logs ORDER BY created_at DESC LIMIT 20')->fetchAll();

$recent_alerts_stmt = $pdo->prepare('SELECT id, title, message, is_read, created_at, link FROM notifications WHERE user_id = ? AND type = "system_error" ORDER BY created_at DESC LIMIT 20');
$recent_alerts_stmt->execute([$uid]);
$recent_alerts = $recent_alerts_stmt->fetchAll();

$unread_alerts_stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = "system_error" AND is_read = 0');
$unread_alerts_stmt->execute([$uid]);
$unread_alerts = (int) $unread_alerts_stmt->fetchColumn();

$pageTitle = 'Admin Reports';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extraScripts .= '<script>';
$extraScripts .= 'const roleCtx = document.getElementById("usersByRoleChart");';
$extraScripts .= 'if (roleCtx) { new Chart(roleCtx, { type: "bar", data: { labels: ' . json_encode($role_labels) . ', datasets: [{ label: "Users", data: ' . json_encode($role_values) . ', backgroundColor: ["#2563eb", "#0ea5e9", "#16a34a", "#9333ea"] }] }, options: { responsive: true, plugins: { legend: { display: false } } } }); }';
$extraScripts .= 'const projectCtx = document.getElementById("projectsByStatusChart");';
$extraScripts .= 'if (projectCtx) { new Chart(projectCtx, { type: "doughnut", data: { labels: ' . json_encode($status_labels) . ', datasets: [{ data: ' . json_encode($status_values) . ', backgroundColor: ["#2563eb", "#16a34a", "#f59e0b", "#dc2626", "#7c3aed", "#0891b2", "#64748b"] }] }, options: { responsive: true } }); }';
$extraScripts .= '</script>';

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Admin Audit Reports</h1>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= base_url('admin/reports.php?export=csv') ?>" class="btn btn-primary"><i class="bi bi-download me-1"></i> Download Audit CSV</a>
    <a href="<?= base_url('admin/users.php') ?>" class="btn btn-outline-secondary">Manage Users (Bulk Add)</a>
</div>

<?php if ($unread_alerts > 0): ?>
    <div class="alert alert-warning">You have <?= $unread_alerts ?> unread system alert(s). Review the alerts table below.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card h-100"><div class="card-body"><h6 class="text-muted">Total Users</h6><h3><?= $total_users ?></h3></div></div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100"><div class="card-body"><h6 class="text-muted">Active Users</h6><h3><?= $active_users ?></h3></div></div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100"><div class="card-body"><h6 class="text-muted">Total Projects</h6><h3><?= $total_projects ?></h3></div></div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100"><div class="card-body"><h6 class="text-muted">Import Failures (30d)</h6><h3><?= $failed_imports_30d ?></h3></div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Users by Role</div>
            <div class="card-body"><canvas id="usersByRoleChart" height="140"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Projects by Status</div>
            <div class="card-body"><canvas id="projectsByStatusChart" height="140"></canvas></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Recent Import Jobs</div>
    <div class="card-body">
        <?php if (empty($recent_imports)): ?>
            <p class="text-muted mb-0">No import jobs yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead><tr><th>ID</th><th>Type</th><th>File</th><th>Total</th><th>Success</th><th>Failed</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_imports as $job): ?>
                            <tr>
                                <td>#<?= (int) $job['id'] ?></td>
                                <td><?= e($job['import_type']) ?></td>
                                <td><?= e($job['file_name'] ?: '—') ?></td>
                                <td><?= (int) ($job['total_rows'] ?? 0) ?></td>
                                <td><?= (int) ($job['successful_rows'] ?? 0) ?></td>
                                <td><?= (int) ($job['failed_rows'] ?? 0) ?></td>
                                <td>
                                    <?php if ((int) ($job['failed_rows'] ?? 0) > 0): ?>
                                        <span class="badge bg-warning text-dark">Needs review</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('M j, Y H:i', strtotime($job['created_at']))) ?></td>
                            </tr>
                            <?php if (!empty($job['error_details'])): ?>
                                <tr>
                                    <td colspan="8">
                                        <details>
                                            <summary>View errors for import #<?= (int) $job['id'] ?></summary>
                                            <pre class="small mb-0 mt-2"><?= e($job['error_details']) ?></pre>
                                        </details>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">System Alerts</div>
    <div class="card-body">
        <?php if (empty($recent_alerts)): ?>
            <p class="text-muted mb-0">No system alerts.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>When</th><th>Title</th><th>Message</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_alerts as $a): ?>
                            <tr>
                                <td><?= e(date('M j, Y H:i', strtotime($a['created_at']))) ?></td>
                                <td><?= e($a['title']) ?></td>
                                <td><?= e($a['message']) ?></td>
                                <td><?= (int) $a['is_read'] === 1 ? 'Read' : 'Unread' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
