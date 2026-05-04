<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

ensure_supervisor_logsheets_table($pdo);
ensure_pending_completion_status($pdo);
ensure_student_tracking_columns($pdo);
ensure_project_contribution_status_table($pdo);

$hod_user = get_user_by_id($uid);
$hod_department_info = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error = empty($hod_department_variants) ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

// ── GET export handlers ─────────────────────────────────────────────────────
if (isset($_GET['export'], $_GET['pid']) && !empty($hod_department_variants)) {
    $export_pid = (int) $_GET['pid'];
    $export_type = $_GET['export'];

    $dept_ph = sql_placeholders(count($hod_department_variants));
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.status, u.full_name AS student_name, u.reg_number, sup.full_name AS supervisor_name FROM projects p JOIN users u ON p.student_id = u.id LEFT JOIN users sup ON sup.id = p.supervisor_id WHERE p.id = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_ph . ') LIMIT 1');
    $stmt->execute(array_merge([$export_pid], $hod_department_variants));
    $export_project = $stmt->fetch();

    if (!$export_project) {
        flash('error', 'Project not found or outside your department scope.');
        redirect(base_url('hod/archive.php'));
    }

    if ($export_type === 'logsheet') {
        $stmt = $pdo->prepare('SELECT * FROM supervisor_logsheets WHERE project_id = ? ORDER BY meeting_date ASC');
        $stmt->execute([$export_pid]);
        $logsheets = $stmt->fetchAll();

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="logsheet_project_' . $export_pid . '.html"');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Logsheet - ' . htmlspecialchars($export_project['title']) . '</title>';
        echo '<style>*{font-family:Arial,sans-serif}body{margin:20px;line-height:1.6}h1{border-bottom:2px solid #007bff;padding-bottom:8px}';
        echo '.meta{background:#f8f9fa;padding:10px;border-left:3px solid #007bff;margin-bottom:20px}';
        echo 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#007bff;color:#fff}tr:nth-child(even){background:#f9f9f9}</style>';
        echo '</head><body>';
        echo '<h1>Supervisor Logsheet</h1>';
        echo '<div class="meta"><p><strong>Project:</strong> ' . htmlspecialchars($export_project['title']) . '</p>';
        echo '<p><strong>Student:</strong> ' . htmlspecialchars($export_project['student_name']) . ' (' . htmlspecialchars($export_project['reg_number'] ?? '') . ')</p>';
        echo '<p><strong>Supervisor:</strong> ' . htmlspecialchars($export_project['supervisor_name'] ?? 'N/A') . '</p>';
        echo '<p><strong>Generated:</strong> ' . date('M j, Y H:i') . '</p></div>';
        if (empty($logsheets)) {
            echo '<p><em>No logsheet entries recorded.</em></p>';
        } else {
            echo '<table><thead><tr><th>Date</th><th>Attendees</th><th>Topics Discussed</th><th>Action Points</th><th>Next Meeting</th><th>Notes</th></tr></thead><tbody>';
            foreach ($logsheets as $ls) {
                $attendees = $ls['student_attendees'] ?? $ls['attendees'] ?? '';
                $topics = $ls['topics_discussed'] ?? $ls['topics'] ?? '';
                $notes = $ls['supervisor_notes'] ?? '';
                $confirmed = $ls['confirmed_at'] ?? $ls['created_at'] ?? '';
                echo '<tr>';
                echo '<td>' . htmlspecialchars((string) ($ls['meeting_date'] ?? '')) . '</td>';
                echo '<td>' . nl2br(htmlspecialchars((string) $attendees)) . '</td>';
                echo '<td>' . nl2br(htmlspecialchars((string) $topics)) . '</td>';
                echo '<td>' . nl2br(htmlspecialchars((string) ($ls['action_points'] ?? ''))) . '</td>';
                echo '<td>' . htmlspecialchars((string) ($ls['next_meeting_date'] ?? '')) . '</td>';
                echo '<td>' . nl2br(htmlspecialchars((string) $notes)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p style="margin-top:30px;border-top:1px solid #ddd;padding-top:10px;">Supervisor Signature: _________________________ &nbsp; Date: _____________</p>';
        echo '</body></html>';
        exit;
    }

    if ($export_type === 'assessment') {
        $stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC');
        $stmt->execute([$export_pid]);
        $assessments_export = $stmt->fetchAll();

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="assessment_project_' . $export_pid . '.html"');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Assessment - ' . htmlspecialchars($export_project['title']) . '</title>';
        echo '<style>*{font-family:Arial,sans-serif}body{margin:20px;line-height:1.6}h1{border-bottom:2px solid #28a745;padding-bottom:8px}';
        echo '.meta{background:#f8f9fa;padding:10px;border-left:3px solid #28a745;margin-bottom:20px}';
        echo '.assessment{margin-bottom:20px;padding:12px;border-left:3px solid #ffc107;background:#fff8e1}';
        echo 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#28a745;color:#fff}tr:nth-child(even){background:#f9f9f9}</style>';
        echo '</head><body>';
        echo '<h1>Supervisor Assessment Records</h1>';
        echo '<div class="meta"><p><strong>Project:</strong> ' . htmlspecialchars($export_project['title']) . '</p>';
        echo '<p><strong>Student:</strong> ' . htmlspecialchars($export_project['student_name']) . ' (' . htmlspecialchars($export_project['reg_number'] ?? '') . ')</p>';
        echo '<p><strong>Supervisor:</strong> ' . htmlspecialchars($export_project['supervisor_name'] ?? 'N/A') . '</p>';
        echo '<p><strong>Generated:</strong> ' . date('M j, Y H:i') . '</p></div>';
        if (empty($assessments_export)) {
            echo '<p><em>No assessments recorded.</em></p>';
        } else {
            foreach ($assessments_export as $a) {
                echo '<div class="assessment">';
                echo '<p><strong>Type:</strong> ' . htmlspecialchars($a['assessment_type']) . ' &nbsp;|&nbsp; <strong>Date:</strong> ' . htmlspecialchars(date('M j, Y', strtotime($a['submitted_at']))) . '</p>';
                echo '<table><thead><tr><th>Criteria</th><th>Score</th></tr></thead><tbody>';
                if (isset($a['research_quality'])) {
                    echo '<tr><td>Research Quality</td><td>' . ($a['research_quality'] !== null ? $a['research_quality'] . '/100' : '—') . '</td></tr>';
                    echo '<tr><td>Methodology</td><td>' . ($a['methodology'] !== null ? $a['methodology'] . '/100' : '—') . '</td></tr>';
                    echo '<tr><td>Collaboration</td><td>' . ($a['collaboration'] !== null ? $a['collaboration'] . '/100' : '—') . '</td></tr>';
                    echo '<tr><td>Presentation</td><td>' . ($a['presentation'] !== null ? $a['presentation'] . '/100' : '—') . '</td></tr>';
                    echo '<tr><td>Originality</td><td>' . ($a['originality'] !== null ? $a['originality'] . '/100' : '—') . '</td></tr>';
                }
                echo '<tr style="background:#e7f3ff"><td><strong>Score / Max</strong></td><td><strong>' . htmlspecialchars((string) ($a['score'] ?? '—')) . ' / ' . htmlspecialchars((string) ($a['max_score'] ?? '100')) . '</strong></td></tr>';
                echo '</tbody></table>';
                $remarks = $a['remarks'] ?? $a['comments'] ?? '';
                if ($remarks) {
                    echo '<p><strong>Remarks:</strong> ' . nl2br(htmlspecialchars((string) $remarks)) . '</p>';
                }
                echo '</div>';
            }
        }
        echo '<p style="margin-top:30px;border-top:1px solid #ddd;padding-top:10px;">Supervisor Signature: _________________________ &nbsp; Date: _____________</p>';
        echo '</body></html>';
        exit;
    }

    redirect(base_url('hod/archive.php'));
}

// ── Data queries ─────────────────────────────────────────────────────────────
function hod_get_member_ids(PDO $pdo, array $project): array {
    $ids = [(int) $project['student_id']];
    if (!empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $project['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $ids[] = (int) $m['student_id'];
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

$member_dir_subquery = '(SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ") FROM `group_members` gm2 JOIN users u2 ON u2.id = gm2.student_id WHERE gm2.group_id = p.group_id) AS member_directory';

$pending_review = [];
if (!empty($hod_department_variants)) {
    $dept_ph = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.updated_at, p.group_id, p.student_id,
        u.full_name AS student_name, u.reg_number, u.email,
        sup.full_name AS supervisor_name, g.name AS group_name,
        ' . $member_dir_subquery . ',
        (SELECT COUNT(*) FROM supervisor_logsheets sl WHERE sl.project_id = p.id) AS logsheet_count,
        (SELECT COUNT(*) FROM assessments a WHERE a.project_id = p.id) AS assessment_count,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN users sup ON sup.id = p.supervisor_id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status = "pending_completion"
          AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_ph . ')
        ORDER BY p.updated_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $pending_review = $stmt->fetchAll();
}

$completable = [];
if (!empty($hod_department_variants)) {
    $dept_ph = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.status, p.group_id, p.student_id,
        u.full_name AS student_name, u.reg_number, u.email,
        sup.full_name AS supervisor_name, g.name AS group_name,
        ' . $member_dir_subquery . ',
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN users sup ON sup.id = p.supervisor_id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status IN ("in_progress", "approved", "completed")
          AND p.id NOT IN (SELECT project_id FROM archive_metadata)
          AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_ph . ')
        ORDER BY p.status = "completed" DESC, p.updated_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $completable = $stmt->fetchAll();
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (empty($hod_department_variants)) {
        flash('error', 'HOD department is not configured.');
        redirect(base_url('hod/archive.php'));
    }

    $action = $_POST['action'] ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);

    if ($project_id) {
        $dept_ph = sql_placeholders(count($hod_department_variants));
        $stmt = $pdo->prepare('SELECT p.id, p.status, p.group_id, p.student_id FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_ph . ') LIMIT 1');
        $stmt->execute(array_merge([$project_id], $hod_department_variants));
        $scoped_project = $stmt->fetch();
        if (!$scoped_project) {
            flash('error', 'Project is outside your department scope.');
            redirect(base_url('hod/archive.php'));
        }

        if ($action === 'complete') {
            $pdo->prepare('UPDATE projects SET status = "completed" WHERE id = ? AND status IN ("in_progress", "approved")')->execute([$project_id]);
            flash('success', 'Project marked as completed.');
        } elseif ($action === 'archive') {
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND status = "completed"');
            $stmt->execute([$project_id]);
            if ($stmt->fetch()) {
                $pdo->prepare('UPDATE projects SET status = "archived" WHERE id = ?')->execute([$project_id]);
                $pdo->prepare('INSERT INTO archive_metadata (project_id, archived_by) VALUES (?, ?) ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = NOW()')->execute([$project_id, $uid]);
                // Set all members to completed via old path (no contribution status)
                foreach (hod_get_member_ids($pdo, $scoped_project) as $mid) {
                    $pdo->prepare('UPDATE users SET student_project_status = "completed", repeat_required = 0 WHERE id = ?')->execute([$mid]);
                }
                flash('success', 'Project archived.');
            }
        } elseif ($action === 'archive_pending') {
            // Archive a project submitted by supervisor (pending_completion)
            if ($scoped_project['status'] === 'pending_completion') {
                $member_ids = hod_get_member_ids($pdo, $scoped_project);

                // Process contribution_status → student_project_status + repeat_required
                $contrib_stmt = $pdo->prepare('SELECT contribution_status FROM project_contribution_status WHERE project_id = ? AND student_id = ? LIMIT 1');
                foreach ($member_ids as $mid) {
                    $contrib_stmt->execute([$project_id, $mid]);
                    $contrib = $contrib_stmt->fetchColumn() ?: 'partial';
                    if ($contrib === 'not_contributed') {
                        $pdo->prepare('UPDATE users SET student_project_status = "failed", repeat_required = 1 WHERE id = ?')->execute([$mid]);
                    } else {
                        $pdo->prepare('UPDATE users SET student_project_status = "completed", repeat_required = 0 WHERE id = ?')->execute([$mid]);
                    }
                }

                $pdo->prepare('UPDATE projects SET status = "archived" WHERE id = ?')->execute([$project_id]);
                $pdo->prepare('INSERT INTO archive_metadata (project_id, archived_by) VALUES (?, ?) ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = NOW()')->execute([$project_id, $uid]);

                foreach ($member_ids as $mid) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                        $mid, 'project_archived', 'Project archived',
                        'Your project has been reviewed and archived by the HOD.',
                        base_url('vault.php')
                    ]);
                }
                flash('success', 'Project archived and student records updated.');
            } else {
                flash('error', 'Project is not in pending review state.');
            }
        }
        redirect(base_url('hod/archive.php'));
    }
}

$archived = [];
if (!empty($hod_department_variants)) {
    $dept_ph = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.updated_at, p.group_id,
        u.full_name AS student_name, u.reg_number, u.email,
        sup.full_name AS supervisor_name, g.name AS group_name,
        ' . $member_dir_subquery . ',
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        am.archived_at
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN users sup ON sup.id = p.supervisor_id
        LEFT JOIN `groups` g ON g.id = p.group_id
        JOIN archive_metadata am ON am.project_id = p.id
        WHERE p.status = "archived" AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_ph . ')
        ORDER BY am.archived_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $archived = $stmt->fetchAll();
}

$pageTitle = 'Archive';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Archive</h1>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

<?php if (!empty($pending_review)): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark d-flex align-items-center justify-content-between">
        <span><i class="bi bi-hourglass-split me-2"></i>Pending HOD Review (<?= count($pending_review) ?>)</span>
        <small>Submitted by supervisors — review logsheet &amp; assessment before archiving</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Title</th><th>Supervisor</th><th>Records</th><th>Downloads</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($pending_review as $p): ?>
                    <tr>
                        <td>
                            <?php if (!empty($p['group_id'])): ?>
                                <span class="badge bg-info text-dark">Group: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                            <?php else: ?>
                                <span class="badge bg-secondary">Solo</span>
                                <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['group_id'])): ?>
                                <small><?= e($p['member_directory'] ?: '—') ?></small>
                            <?php else: ?>
                                <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= e($p['title']) ?></td>
                        <td><?= e($p['supervisor_name'] ?? '—') ?></td>
                        <td>
                            <small class="text-muted">
                                Logsheet: <?= (int) ($p['logsheet_count'] ?? 0) ?><br>
                                Assessments: <?= (int) ($p['assessment_count'] ?? 0) ?><br>
                                Docs: <?= (int) ($p['docs_count'] ?? 0) ?>
                            </small>
                        </td>
                        <td>
                            <a href="<?= base_url('hod/archive.php?export=logsheet&pid=' . $p['id']) ?>" class="btn btn-sm btn-outline-primary d-block mb-1">
                                <i class="bi bi-journal-text"></i> Logsheet
                            </a>
                            <a href="<?= base_url('hod/archive.php?export=assessment&pid=' . $p['id']) ?>" class="btn btn-sm btn-outline-success d-block">
                                <i class="bi bi-award"></i> Assessment
                            </a>
                        </td>
                        <td>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="action" value="archive_pending">
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Archive this project? This will update student records based on contribution statuses set by the supervisor.')">
                                    <i class="bi bi-archive"></i> Archive
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Ongoing &amp; Completed (Ready to Archive)</div>
    <div class="card-body">
        <?php if (empty($completable)): ?>
            <p class="text-muted mb-0">No projects to complete or archive.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Title</th><th>Input</th><th>Supervisor</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($completable as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <span class="badge bg-info text-dark">Group: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                    <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Solo</span>
                                    <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <small><?= e($p['member_directory'] ?: '—') ?></small>
                                <?php else: ?>
                                    <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><small class="text-muted">Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Log <?= (int) ($p['logbook_count'] ?? 0) ?> | Msg <?= (int) ($p['message_count'] ?? 0) ?></small></td>
                            <td><?= e($p['supervisor_name'] ?? '—') ?></td>
                            <td><span class="badge bg-secondary"><?= e($p['status']) ?></span></td>
                            <td>
                                <?php if ($p['status'] !== 'completed'): ?>
                                    <form method="post" class="d-inline me-1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Mark Completed</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'completed'): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="btn btn-sm btn-primary">Archive</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Archived Projects</div>
    <div class="card-body">
        <?php if (empty($archived)): ?>
            <p class="text-muted mb-0">No archived projects yet.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Title</th><th>Docs</th><th>Supervisor</th><th>Archived</th></tr></thead>
                <tbody>
                    <?php foreach ($archived as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <span class="badge bg-info text-dark">Group: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                    <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Solo</span>
                                    <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <small><?= e($p['member_directory'] ?: '—') ?></small>
                                <?php else: ?>
                                    <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><small class="text-muted"><?= (int) ($p['docs_count'] ?? 0) ?> document(s)</small></td>
                            <td><?= e($p['supervisor_name'] ?? '—') ?></td>
                            <td><?= !empty($p['archived_at']) ? e(date('M j, Y', strtotime($p['archived_at']))) : e(date('M j, Y', strtotime($p['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
