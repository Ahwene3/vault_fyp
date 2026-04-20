<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

$hod_user = get_user_by_id($uid);
$hod_department_info = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error = empty($hod_department_variants) ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

$pending = [];
if (!empty($hod_department_variants)) {
    $dept_placeholders = sql_placeholders(count($hod_department_variants));
    $sql = 'SELECT p.id, p.title, p.description, p.status, p.submitted_at, p.group_id, u.full_name AS student_name, u.reg_number, u.email, g.name AS group_name,
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
        ORDER BY p.submitted_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $pending = $stmt->fetchAll();
}

function hod_project_member_ids(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) {
        return [];
    }

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

// Approve / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (empty($hod_department_variants)) {
        flash('error', 'HOD department is not configured.');
        redirect(base_url('hod/topics.php'));
    }

    $action = $_POST['action'] ?? '';
    $project_id = (int) ($_POST['project_id'] ?? 0);
    if ($project_id && in_array($action, ['approve', 'reject'], true)) {
        $dept_placeholders = sql_placeholders(count($hod_department_variants));
        $stmt = $pdo->prepare('SELECT p.id FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND p.status = "submitted" AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');
        $stmt->execute(array_merge([$project_id], $hod_department_variants));
        if ($stmt->fetch()) {
            if ($action === 'approve') {
                $pdo->prepare('UPDATE projects SET status = "approved", approved_at = NOW(), approved_by = ?, rejection_reason = NULL WHERE id = ?')->execute([$uid, $project_id]);
                $stmt = $pdo->prepare('SELECT student_id, title FROM projects WHERE id = ?');
                $stmt->execute([$project_id]);
                $proj = $stmt->fetch();
                if ($proj) {
                    $stmt = $pdo->prepare('SELECT email, first_name FROM users WHERE id = ?');
                    $stmt->execute([$proj['student_id']]);
                    $student = $stmt->fetch();
                    
                    // TODO: Email notification disabled for now - will integrate properly later
                    // if ($student) {
                    //     send_topic_approval_email($student['email'], $student['first_name'], $proj['title']);
                    // }
                    
                    foreach (hod_project_member_ids($pdo, $project_id) as $member_id) {
                        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                            $member_id,
                            'topic_approved',
                            'Topic approved',
                            'Your project topic has been approved.',
                            base_url('student/project.php')
                        ]);
                    }
                }
                flash('success', 'Topic approved.');
            } else {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $pdo->prepare('UPDATE projects SET status = "rejected", approved_by = ?, rejection_reason = ? WHERE id = ?')->execute([$uid, $reason ?: null, $project_id]);
                $stmt = $pdo->prepare('SELECT student_id, title FROM projects WHERE id = ?');
                $stmt->execute([$project_id]);
                $proj = $stmt->fetch();
                if ($proj) {
                    $stmt = $pdo->prepare('SELECT email, first_name FROM users WHERE id = ?');
                    $stmt->execute([$proj['student_id']]);
                    $student = $stmt->fetch();
                    
                    // TODO: Email notification disabled for now - will integrate properly later
                    // if ($student) {
                    //     send_topic_rejection_email($student['email'], $student['first_name'], $proj['title'], $reason);
                    // }
                    
                    foreach (hod_project_member_ids($pdo, $project_id) as $member_id) {
                        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                            $member_id,
                            'topic_rejected',
                            'Topic rejected',
                            'Your project topic was rejected. You may resubmit.',
                            base_url('student/project.php')
                        ]);
                    }
                }
                flash('success', 'Topic rejected.');
            }
            redirect(base_url('hod/topics.php'));
        }
    }
}

$pageTitle = 'Topic Approval';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Topic Approval</h1>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Pending Submissions</div>
    <div class="card-body">
        <?php if (empty($pending)): ?>
            <p class="text-muted mb-0">No topics pending approval.</p>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($pending as $p): ?>
                    <div class="list-group-item">
                        <h6><?= e($p['title']) ?></h6>
                        <?php if (!empty($p['group_id'])): ?>
                            <p class="mb-1 text-muted small">
                                <span class="badge bg-info text-dark">Group Vault: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                <span class="ms-1">Lead: <?= e($p['student_name']) ?></span>
                            </p>
                            <p class="mb-1 text-muted small">Members: <?= e($p['member_directory'] ?: '—') ?></p>
                        <?php else: ?>
                            <p class="mb-1 text-muted small">
                                <span class="badge bg-secondary">Solo Vault</span>
                                <span class="ms-1"><?= e($p['student_name']) ?> — <?= e($p['reg_number'] ?? $p['email']) ?></span>
                            </p>
                        <?php endif; ?>
                        <p class="mb-2 text-muted small">
                            Input: Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Logbook <?= (int) ($p['logbook_count'] ?? 0) ?> | Messages <?= (int) ($p['message_count'] ?? 0) ?>
                        </p>
                        <?php if ($p['description']): ?><p class="mb-2"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
                        <form method="post" class="d-inline me-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                            <input type="text" name="rejection_reason" placeholder="Reason (optional)" class="form-control form-control-sm d-inline-block w-auto">
                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
