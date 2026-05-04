<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$report_type = $_GET['type'] ?? 'overview';
$uid = user_id();

$hod_user = get_user_by_id($uid);
$hod_department_info = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error = empty($hod_department_variants) ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

$pdo->exec('CREATE TABLE IF NOT EXISTS project_member_ratings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NOT NULL,
    rating_score DECIMAL(5,2) NOT NULL,
    note TEXT NULL,
    rated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_project_student_supervisor (project_id, student_id, supervisor_id),
    INDEX idx_project (project_id),
    INDEX idx_student (student_id),
    INDEX idx_supervisor (supervisor_id),
    CONSTRAINT fk_pmr_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmr_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmr_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pending_topics = 0;
$ongoing = 0;
$completed = 0;
$rejected = 0;
$total = 0;
$total_vaults = 0;
$completed_vaults = 0;
$completion_rate = 0;
$supervisor_workload = [];
$duplicate_topics = [];
$monthly_submissions = [];
$pending_list = [];
$contribution_rows = [];
$by_status = [];
$by_year = [];
$ongoing_projects = [];

if (!empty($hod_department_variants)) {
    $dept_placeholders = sql_placeholders(count($hod_department_variants));
    $params = $hod_department_variants;

    $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');

    $count_stmt->execute(array_merge(['submitted'], $params));
    $pending_topics = (int) $count_stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status IN ("approved", "in_progress") AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');
    $stmt->execute($params);
    $ongoing = (int) $stmt->fetchColumn();

    $count_stmt->execute(array_merge(['completed'], $params));
    $completed = (int) $count_stmt->fetchColumn();

    $count_stmt->execute(array_merge(['rejected'], $params));
    $rejected = (int) $count_stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $total_vaults = $total;
    $completed_vaults = $completed;
    $completion_rate = $total_vaults > 0 ? round(($completed_vaults / $total_vaults) * 100, 2) : 0;

    $stmt = $pdo->prepare('SELECT
        su.id,
        su.full_name,
        COUNT(p.id) AS project_count,
        SUM(CASE WHEN p.status IN ("approved", "in_progress") THEN 1 ELSE 0 END) AS active_count
        FROM users su
        LEFT JOIN projects p ON su.id = p.supervisor_id
        LEFT JOIN users stu ON p.student_id = stu.id
        WHERE su.role = "supervisor"
            AND LOWER(TRIM(COALESCE(su.department, ""))) IN (' . $dept_placeholders . ')
            AND (p.id IS NULL OR LOWER(TRIM(COALESCE(stu.department, ""))) IN (' . $dept_placeholders . '))
        GROUP BY su.id, su.full_name
        ORDER BY active_count DESC');
    $stmt->execute(array_merge($params, $params));
    $supervisor_workload = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT p.title, COUNT(*) AS count,
        GROUP_CONCAT(CASE WHEN p.group_id IS NULL THEN CONCAT(u.full_name, " (", COALESCE(NULLIF(u.reg_number, ""), u.email), ")") ELSE CONCAT(COALESCE(g.name, CONCAT("Group #", p.group_id)), " [Lead: ", u.full_name, "]") END SEPARATOR "; ") AS vaults
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status IN ("submitted", "approved", "in_progress", "completed")
            AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        GROUP BY p.title
        HAVING count > 1
        ORDER BY count DESC');
    $stmt->execute($params);
    $duplicate_topics = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT MONTH(p.submitted_at) AS month, COUNT(*) AS submissions
        FROM projects p
        JOIN users u ON p.student_id = u.id
        WHERE p.submitted_at IS NOT NULL AND p.status != "draft"
            AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        GROUP BY MONTH(p.submitted_at)
        ORDER BY month');
    $stmt->execute($params);
    $monthly_submissions = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT p.id, p.title, p.group_id, u.full_name, u.email, u.reg_number, p.submitted_at, g.name AS group_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_directory,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status = "submitted" AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY p.submitted_at ASC');
    $stmt->execute($params);
    $pending_list = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT
        p.id AS project_id,
        p.title,
        COALESCE(g.name, CONCAT("Solo Vault - ", lead_u.full_name)) AS vault_name,
        member_u.full_name AS member_name,
        COALESCE(NULLIF(member_u.reg_number, ""), member_u.email) AS member_index,
        sup_u.full_name AS supervisor_name,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id AND pd.uploader_id = member_u.id) AS docs_uploaded,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id AND le.created_by = member_u.id) AS logbook_entries,
        (SELECT COUNT(*) FROM messages ms WHERE ms.project_id = p.id AND ms.sender_id = member_u.id) AS messages_sent,
        r.rating_score,
        r.note AS rating_note,
        r.rated_at
        FROM projects p
        JOIN users lead_u ON lead_u.id = p.student_id
        LEFT JOIN `groups` g ON g.id = p.group_id
        LEFT JOIN users sup_u ON sup_u.id = p.supervisor_id
        LEFT JOIN `group_members` gm ON gm.group_id = p.group_id
        JOIN users member_u ON member_u.id = COALESCE(gm.student_id, p.student_id)
        LEFT JOIN project_member_ratings r ON r.project_id = p.id AND r.student_id = member_u.id AND r.supervisor_id = p.supervisor_id
        WHERE p.status IN ("approved", "in_progress", "completed", "archived")
            AND LOWER(TRIM(COALESCE(lead_u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY p.updated_at DESC, vault_name ASC, member_u.full_name ASC');
    $stmt->execute($params);
    $contribution_rows = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT p.status, COUNT(*) AS cnt
        FROM projects p
        JOIN users u ON p.student_id = u.id
        WHERE LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        GROUP BY p.status');
    $stmt->execute($params);
    $by_status = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT p.academic_year, COUNT(*) AS cnt
        FROM projects p
        JOIN users u ON p.student_id = u.id
        WHERE p.academic_year IS NOT NULL AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        GROUP BY p.academic_year
        ORDER BY p.academic_year DESC');
    $stmt->execute($params);
    $by_year = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT
        p.id,
        p.title,
        p.status,
        p.updated_at,
        COALESCE(g.name, CONCAT("Solo Vault - ", lead_u.full_name)) AS vault_name,
        sup_u.full_name AS supervisor_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_directory
        FROM projects p
        JOIN users lead_u ON lead_u.id = p.student_id
        LEFT JOIN users sup_u ON sup_u.id = p.supervisor_id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status IN ("approved", "in_progress") AND LOWER(TRIM(COALESCE(lead_u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY p.updated_at DESC');
    $stmt->execute($params);
    $ongoing_projects = $stmt->fetchAll();
}

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if ($export_format !== '') {
    $export_type = $report_type ?: 'overview';
    $file_stamp = date('Ymd_His');
    $export_ext = 'html';
    $export_content_type = 'text/html; charset=utf-8';

    if ($export_format === 'pdf') {
        $export_ext = 'pdf';
        $export_content_type = 'application/pdf';
    } elseif (in_array($export_format, ['word', 'doc', 'docx'], true)) {
        $export_ext = 'doc';
        $export_content_type = 'application/msword';
    }

    header('Content-Type: ' . $export_content_type);
    header('Content-Disposition: attachment; filename="HOD_Report_' . $export_type . '_' . $file_stamp . '.' . $export_ext . '"');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Department Report - <?= e(ucfirst($export_type)) ?></title>
        <style>
            * { font-family: Arial, sans-serif; }
            body { padding: 20px; line-height: 1.6; color: #111827; }
            h1 { color: #0f172a; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 18px; }
            h2 { color: #1f2937; margin-top: 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
            .meta { background: #f8fafc; padding: 10px 12px; border-left: 4px solid #2563eb; margin-bottom: 18px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { padding: 8px 10px; text-align: left; border: 1px solid #e5e7eb; vertical-align: top; }
            th { background: #2563eb; color: #fff; }
            tr:nth-child(even) { background: #f8fafc; }
            .note { font-size: 0.9rem; color: #4b5563; }
            @media print { body { padding: 0; } }
        </style>
    </head>
    <body>
        <h1>Department Report - <?= e(ucfirst($export_type)) ?></h1>
        <div class="meta">
            <p><strong>Department:</strong> <?= e($hod_department_label ?: 'Unknown') ?></p>
            <p><strong>Generated:</strong> <?= e(date('M j, Y H:i')) ?></p>
        </div>

        <?php if ($department_scope_error): ?>
            <p><strong>Notice:</strong> <?= e($department_scope_error) ?></p>
        <?php elseif ($export_type === 'overview'): ?>
            <h2>Summary</h2>
            <table>
                <tbody>
                    <tr><th>Total Vaults</th><td><?= (int) $total_vaults ?></td></tr>
                    <tr><th>Completion Rate</th><td><?= e($completion_rate) ?>%</td></tr>
                    <tr><th>Ongoing Projects</th><td><?= (int) $ongoing ?></td></tr>
                    <tr><th>Pending Topics</th><td><?= (int) $pending_topics ?></td></tr>
                </tbody>
            </table>

            <h2>By Status</h2>
            <table>
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach ($by_status as $r): ?>
                        <tr><td><?= e($r['status']) ?></td><td><?= (int) $r['cnt'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Ongoing Projects</h2>
            <?php if (empty($ongoing_projects)): ?>
                <p>No ongoing projects in this department.</p>
            <?php else: ?>
                <table>
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
                        <?php foreach ($ongoing_projects as $op): ?>
                            <tr>
                                <td><?= e($op['vault_name']) ?></td>
                                <td><?= e($op['title']) ?></td>
                                <td><?= e($op['member_directory'] ?: '-') ?></td>
                                <td><?= e($op['supervisor_name'] ?: 'Not assigned') ?></td>
                                <td><?= e($op['status']) ?></td>
                                <td><?= e(date('M j, Y', strtotime($op['updated_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($export_type === 'supervisor'): ?>
            <h2>Supervisor Workload</h2>
            <table>
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
                        $pct = $total_active > 0 ? round(($s['active_count'] / $total_active) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><?= e($s['full_name']) ?></td>
                            <td><?= (int) $s['project_count'] ?></td>
                            <td><?= (int) $s['active_count'] ?></td>
                            <td><?= e($pct) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($export_type === 'duplicates'): ?>
            <h2>Duplicate Topics</h2>
            <?php if (empty($duplicate_topics)): ?>
                <p>No duplicate topics found.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Topic</th>
                            <th>Count</th>
                            <th>Vaults</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicate_topics as $dup): ?>
                            <tr>
                                <td><?= e($dup['title']) ?></td>
                                <td><?= (int) $dup['count'] ?></td>
                                <td><?= e($dup['vaults']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($export_type === 'pending'): ?>
            <h2>Pending Topic Approvals</h2>
            <?php if (empty($pending_list)): ?>
                <p>No pending topics.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Vault</th>
                            <th>Members / Index</th>
                            <th>Docs</th>
                            <th>Logbook</th>
                            <th>Messages</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_list as $p): ?>
                            <?php
                            $vault_label = !empty($p['group_id'])
                                ? ('Group: ' . ($p['group_name'] ?: ('#' . $p['group_id'])))
                                : ('Solo: ' . ($p['full_name'] ?? '-') . ' (' . ($p['reg_number'] ?? $p['email']) . ')');
                            ?>
                            <tr>
                                <td><?= e($p['title']) ?></td>
                                <td><?= e($vault_label) ?></td>
                                <td><?= e($p['member_directory'] ?: '-') ?></td>
                                <td><?= (int) ($p['docs_count'] ?? 0) ?></td>
                                <td><?= (int) ($p['logbook_count'] ?? 0) ?></td>
                                <td><?= (int) ($p['message_count'] ?? 0) ?></td>
                                <td><?= e(date('M j, Y', strtotime($p['submitted_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($export_type === 'contributions'): ?>
            <h2>Group Member Contributions</h2>
            <p class="note">Input score formula: documents x 3 + logbook entries x 2 + messages sent.</p>
            <?php if (empty($contribution_rows)): ?>
                <p>No contribution data yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Vault</th>
                            <th>Project</th>
                            <th>Member</th>
                            <th>Index</th>
                            <th>Supervisor</th>
                            <th>Docs</th>
                            <th>Logbook</th>
                            <th>Messages</th>
                            <th>Input Score</th>
                            <th>Supervisor Rating</th>
                            <th>Note</th>
                            <th>Rated At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contribution_rows as $row): ?>
                            <?php $input_score = ((int) $row['docs_uploaded'] * 3) + ((int) $row['logbook_entries'] * 2) + (int) $row['messages_sent']; ?>
                            <tr>
                                <td><?= e($row['vault_name']) ?></td>
                                <td><?= e($row['title']) ?></td>
                                <td><?= e($row['member_name']) ?></td>
                                <td><?= e($row['member_index']) ?></td>
                                <td><?= e($row['supervisor_name'] ?? '-') ?></td>
                                <td><?= (int) $row['docs_uploaded'] ?></td>
                                <td><?= (int) $row['logbook_entries'] ?></td>
                                <td><?= (int) $row['messages_sent'] ?></td>
                                <td><?= (int) $input_score ?></td>
                                <td><?= $row['rating_score'] !== null ? e(number_format((float) $row['rating_score'], 2)) : '-' ?></td>
                                <td><?= e($row['rating_note'] ?? '-') ?></td>
                                <td><?= !empty($row['rated_at']) ? e(date('M j, Y H:i', strtotime($row['rated_at']))) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'Department Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
    <h1 class="mb-0">Department Reports</h1>
    <div class="btn-group">
        <a class="btn btn-outline-primary btn-sm" href="<?= base_url('hod/reports.php?type=' . $report_type . '&export=pdf') ?>">Download PDF</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= base_url('hod/reports.php?type=' . $report_type . '&export=word') ?>">Download Word</a>
    </div>
</div>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

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
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $report_type === 'contributions' ? 'active' : '' ?>" href="<?= base_url('hod/reports.php?type=contributions') ?>">Contributions</a>
    </li>
</ul>

<?php if ($report_type === 'overview'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Total Vaults</h6>
                    <h2 class="text-primary mb-0"><?= $total_vaults ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Completion Rate</h6>
                    <h2 class="text-success mb-0"><?= $completion_rate ?>%</h2>
                    <small class="text-muted"><?= $completed_vaults ?> of <?= $total_vaults ?> completed</small>
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

    <div class="card mb-4">
        <div class="card-header">Ongoing Projects and Assigned Supervisors</div>
        <div class="card-body">
            <?php if (empty($ongoing_projects)): ?>
                <p class="text-muted mb-0">No ongoing projects in your department.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
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
                            <?php foreach ($ongoing_projects as $op): ?>
                                <tr>
                                    <td><?= e($op['vault_name']) ?></td>
                                    <td><?= e($op['title']) ?></td>
                                    <td><?= e($op['member_directory'] ?: '—') ?></td>
                                    <td><?= e($op['supervisor_name'] ?: 'Not assigned') ?></td>
                                    <td><span class="badge bg-secondary"><?= e($op['status']) ?></span></td>
                                    <td><?= e(date('M j, Y', strtotime($op['updated_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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
                            <p class="mb-1 text-danger"><strong>⚠️ <?= $dup['count'] ?> vaults submitted same topic</strong></p>
                            <small class="text-muted">Vaults: <?= e($dup['vaults']) ?></small>
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
                            <?php if (!empty($p['group_id'])): ?>
                                <small class="text-muted d-block">Group Vault: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></small>
                                <small class="text-muted d-block">Members: <?= e($p['member_directory'] ?: '—') ?></small>
                            <?php else: ?>
                                <small class="text-muted d-block">Solo Vault: <?= e(($p['full_name'] ?? '—') . ' (' . ($p['reg_number'] ?? $p['email']) . ')') ?></small>
                            <?php endif; ?>
                            <small class="text-muted">
                                Input: Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Logbook <?= (int) ($p['logbook_count'] ?? 0) ?> | Messages <?= (int) ($p['message_count'] ?? 0) ?> |
                                Submitted <?= e(date('M j, Y', strtotime($p['submitted_at']))) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($report_type === 'contributions'): ?>
    <div class="card">
        <div class="card-header">Group Vault Member Contributions</div>
        <div class="card-body">
            <?php if (empty($contribution_rows)): ?>
                <p class="text-muted mb-0">No contribution data yet.</p>
            <?php else: ?>
                <p class="text-muted small mb-3">Input score formula: documents x 3 + logbook entries x 2 + messages sent.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Vault</th>
                                <th>Project</th>
                                <th>Member</th>
                                <th>Index</th>
                                <th>Supervisor</th>
                                <th>Docs</th>
                                <th>Logbook</th>
                                <th>Messages</th>
                                <th>Input Score</th>
                                <th>Supervisor Rating</th>
                                <th>Note</th>
                                <th>Rated At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contribution_rows as $row): ?>
                                <?php $input_score = ((int) $row['docs_uploaded'] * 3) + ((int) $row['logbook_entries'] * 2) + (int) $row['messages_sent']; ?>
                                <tr>
                                    <td><?= e($row['vault_name']) ?></td>
                                    <td><?= e($row['title']) ?></td>
                                    <td><?= e($row['member_name']) ?></td>
                                    <td><?= e($row['member_index']) ?></td>
                                    <td><?= e($row['supervisor_name'] ?? '—') ?></td>
                                    <td><?= (int) $row['docs_uploaded'] ?></td>
                                    <td><?= (int) $row['logbook_entries'] ?></td>
                                    <td><?= (int) $row['messages_sent'] ?></td>
                                    <td><strong><?= $input_score ?></strong></td>
                                    <td><?= $row['rating_score'] !== null ? e(number_format((float) $row['rating_score'], 2)) : '—' ?></td>
                                    <td><?= e($row['rating_note'] ?? '—') ?></td>
                                    <td><?= !empty($row['rated_at']) ? e(date('M j, Y H:i', strtotime($row['rated_at']))) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
