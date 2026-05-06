<?php
/**
 * Export Activity Log - Download combined log sheet + submission records
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
ensure_supervisor_logsheets_table($pdo);
$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare('SELECT p.*, u.full_name AS student_name, u.email, u.reg_number FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND (p.supervisor_id = ? OR p.status = "archived")');
$stmt->execute([$pid, $uid]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect(base_url('supervisor/students.php'));
}

// Fetch all group members (for group projects)
$group_members = [];
if (!empty($project['group_id'])) {
    $stmt = $pdo->prepare('SELECT u.full_name, u.reg_number, u.email, gm.role AS group_role FROM `group_members` gm JOIN users u ON u.id = gm.student_id WHERE gm.group_id = ? ORDER BY CASE WHEN gm.role = "lead" THEN 0 ELSE 1 END, u.full_name');
    $stmt->execute([(int) $project['group_id']]);
    $group_members = $stmt->fetchAll();
}
// Ensure project owner is always included
if (empty($group_members)) {
    $group_members = [[
        'full_name'  => $project['student_name'],
        'reg_number' => $project['reg_number'],
        'email'      => $project['email'],
        'group_role' => 'lead',
    ]];
}

// Fetch logbook entries (student entries + supervisor feedback)
$stmt = $pdo->prepare('SELECT le.entry_date, le.title, le.content, le.supervisor_approved, le.supervisor_comment, le.created_at, u.full_name AS author_name FROM logbook_entries le JOIN users u ON u.id = le.created_by WHERE le.project_id = ? ORDER BY le.entry_date ASC, le.created_at ASC');
$stmt->execute([$pid]);
$logsheets = $stmt->fetchAll();

// Fetch documents and assessments
$stmt = $pdo->prepare('SELECT * FROM project_documents WHERE project_id = ? ORDER BY uploaded_at DESC');
$stmt->execute([$pid]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC');
$stmt->execute([$pid]);
$assessments = $stmt->fetchAll();

// Generate PDF or HTML export
$format = $_GET['format'] ?? 'html';

if ($format === 'pdf') {
    // Generate PDF using a simple HTML output
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Activity_Log_' . $project['id'] . '.pdf"');
} else {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Activity_Log_' . $project['id'] . '.html"');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Activity Log - <?= e($project['title']) ?></title>
    <style>
        * { font-family: Arial, sans-serif; }
        body { padding: 20px; line-height: 1.6; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .section { margin-bottom: 30px; }
        .meta { background: #f8f9fa; padding: 10px; border-left: 3px solid #007bff; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .log-entry { margin-bottom: 15px; padding: 10px; border-left: 3px solid #28a745; background: #f0fff4; }
        .assessment { margin-bottom: 15px; padding: 10px; border-left: 3px solid #ffc107; background: #fff8e1; }
        .document { margin-bottom: 10px; padding: 8px; background: #f8f9fa; }
        .signature { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <h1>Activity Log Report</h1>
    
    <div class="meta">
        <p><strong>Project:</strong> <?= e($project['title']) ?></p>
        <p><strong>Student(s):</strong>
            <?php foreach ($group_members as $i => $m): ?>
                <?= e($m['full_name']) ?> (<?= e($m['reg_number'] ?? 'N/A') ?>)<?= $m['group_role'] === 'lead' ? ' <em>[Lead]</em>' : '' ?><?= $i < count($group_members) - 1 ? ', ' : '' ?>
            <?php endforeach; ?>
        </p>
        <p><strong>Supervisor:</strong> <?= e($_SESSION['user']['full_name'] ?? 'Unknown') ?></p>
        <p><strong>Status:</strong> <?= e($project['status']) ?></p>
        <p><strong>Report Generated:</strong> <?= date('M j, Y H:i') ?></p>
    </div>
    
    <!-- Logbook Section -->
    <div class="section">
        <h2>Logbook Entries</h2>
        <?php if (empty($logsheets)): ?>
            <p><em>No logbook entries recorded.</em></p>
        <?php else: ?>
            <?php foreach ($logsheets as $i => $log):
                $approved = $log['supervisor_approved'];
                $status_label = $approved === null ? 'Pending' : ($approved ? 'Approved' : 'Flagged');
                $status_color = $approved === null ? '#856404' : ($approved ? '#155724' : '#721c24');
                $status_bg    = $approved === null ? '#fff3cd' : ($approved ? '#d4edda' : '#f8d7da');
            ?>
                <div class="log-entry">
                    <p style="margin:0 0 4px;">
                        <strong>#<?= $i + 1 ?> — <?= e($log['title']) ?></strong>
                        &nbsp;
                        <span style="background:<?= $status_bg ?>;color:<?= $status_color ?>;padding:2px 8px;border-radius:4px;font-size:0.85em;"><?= $status_label ?></span>
                    </p>
                    <p style="margin:0 0 6px;font-size:0.88em;color:#555;">
                        <strong>Date:</strong> <?= e(date('M j, Y', strtotime($log['entry_date']))) ?>
                        &nbsp;|&nbsp;
                        <strong>By:</strong> <?= e($log['author_name']) ?>
                    </p>
                    <p><strong>Entry:</strong></p>
                    <p style="white-space:pre-wrap;"><?= nl2br(e($log['content'])) ?></p>
                    <?php if (!empty($log['supervisor_comment'])): ?>
                        <div style="margin-top:8px;padding:8px;background:#e8f4fd;border-left:3px solid #0069d9;">
                            <strong>Supervisor Feedback:</strong>
                            <p style="margin:4px 0 0;"><?= nl2br(e($log['supervisor_comment'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <p style="margin:6px 0 0;"><small style="color:#666;">Submitted: <?= e(date('M j, Y H:i', strtotime($log['created_at']))) ?></small></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Documents Section -->
    <div class="section">
        <h2>Submitted Documents</h2>
        <?php if (empty($documents)): ?>
            <p><em>No documents submitted.</em></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Type</th>
                        <th>Uploaded</th>
                        <th>Version</th>
                        <th>Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <?php
                            $dtype = $doc['document_type'] ?? '';
                            $dtype_label = match($dtype) {
                                'proposal', 'documentation' => 'Documentation',
                                'report'  => 'Report',
                                'zip'     => 'Zipped Project',
                                default   => ucfirst($dtype) ?: '—',
                            };
                        ?>
                        <tr>
                            <td><?= e($doc['file_name']) ?></td>
                            <td><?= e($dtype_label) ?></td>
                            <td><?= e(date('M j, Y H:i', strtotime($doc['uploaded_at']))) ?></td>
                            <td>v<?= $doc['version_number'] ?? $doc['version'] ?></td>
                            <td><?= number_format($doc['file_size'] / 1024, 2) ?> KB</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Assessments Section -->
    <div class="section">
        <h2>Assessment Records</h2>
        <?php if (empty($assessments)): ?>
            <p><em>No assessments submitted.</em></p>
        <?php else: ?>
            <?php foreach ($assessments as $a): ?>
                <div class="assessment">
                    <p><strong>Type:</strong> <?= e($a['assessment_type']) ?> | <strong>Date:</strong> <?= e(date('M j, Y', strtotime($a['submitted_at']))) ?></p>
                    <table style="font-size: 0.9em;">
                        <tr>
                            <th>Criteria</th>
                            <th>Score</th>
                        </tr>
                        <tr>
                            <td>Research Quality</td>
                            <td><?= $a['research_quality'] !== null ? $a['research_quality'] . '/100' : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Methodology</td>
                            <td><?= $a['methodology'] !== null ? $a['methodology'] . '/100' : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Collaboration</td>
                            <td><?= $a['collaboration'] !== null ? $a['collaboration'] . '/100' : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Presentation</td>
                            <td><?= $a['presentation'] !== null ? $a['presentation'] . '/100' : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Originality</td>
                            <td><?= $a['originality'] !== null ? $a['originality'] . '/100' : '—' ?></td>
                        </tr>
                        <tr style="background: #e7f3ff;">
                            <td><strong>Average Score</strong></td>
                            <td><strong><?= $a['score'] !== null ? $a['score'] . '/100' : '—' ?></strong></td>
                        </tr>
                    </table>
                    <p><strong style="margin-top: 10px; display: block;">Remarks:</strong> <?= nl2br(e($a['remarks'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="signature">
        <p>Supervisor Signature: _________________________ Date: _____________</p>
    </div>
</body>
</html>
<?php
