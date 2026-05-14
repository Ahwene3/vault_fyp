<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare('SELECT p.*, u.full_name AS student_name, u.email, u.index_number FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND p.supervisor_id = ?');
$stmt->execute([$pid, $uid]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect(base_url('supervisor/students.php'));
}

if (empty($project['group_id'])) {
    $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE created_by = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) $project['student_id']]);
    $fallback_group_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($fallback_group_id > 0) {
        $check = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? LIMIT 1');
        $check->execute([$fallback_group_id]);
        $existing_group_project = (int) ($check->fetchColumn() ?: 0);
        if ($existing_group_project === 0 || $existing_group_project === (int) $project['id']) {
            $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND group_id IS NULL')->execute([$fallback_group_id, (int) $project['id']]);
            $project['group_id'] = $fallback_group_id;
        }
    }
}

$group_info = null;
if (!empty($project['group_id'])) {
    $stmt = $pdo->prepare('SELECT g.id, g.name,
        (SELECT COUNT(*) FROM `group_members` gm WHERE gm.group_id = g.id) AS member_count,
        (SELECT GROUP_CONCAT(u.full_name ORDER BY CASE WHEN gm.role = "lead" THEN 0 ELSE 1 END, u.full_name SEPARATOR ", ")
            FROM `group_members` gm
            JOIN users u ON u.id = gm.student_id
            WHERE gm.group_id = g.id) AS member_names
        FROM `groups` g
        WHERE g.id = ? LIMIT 1');
    $stmt->execute([(int) $project['group_id']]);
    $group_info = $stmt->fetch();
}

$student_id = (int) $project['student_id'];

function ensure_member_rating_table(PDO $pdo): void {
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
}

function get_member_input_metrics(PDO $pdo, int $project_id, int $student_id): array {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_documents WHERE project_id = ? AND uploader_id = ?');
    $stmt->execute([$project_id, $student_id]);
    $docs_uploaded = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries WHERE project_id = ? AND created_by = ?');
    $stmt->execute([$project_id, $student_id]);
    $logbook_entries = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE project_id = ? AND sender_id = ?');
    $stmt->execute([$project_id, $student_id]);
    $messages_sent = (int) $stmt->fetchColumn();

    // Weighted visibility metric to quickly highlight active contributors.
    $activity_score = ($docs_uploaded * 3) + ($logbook_entries * 2) + $messages_sent;

    return [
        'docs_uploaded' => $docs_uploaded,
        'logbook_entries' => $logbook_entries,
        'messages_sent' => $messages_sent,
        'activity_score' => $activity_score,
    ];
}

function get_project_member_profiles(PDO $pdo, array $project, int $supervisor_id): array {
    if (!empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT u.id AS student_id, u.full_name, u.index_number, u.email, gm.role FROM `group_members` gm JOIN users u ON u.id = gm.student_id WHERE gm.group_id = ? ORDER BY CASE WHEN gm.role = "lead" THEN 0 ELSE 1 END, u.full_name');
        $stmt->execute([(int) $project['group_id']]);
        $members = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT id AS student_id, full_name, index_number, email, "lead" AS role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $project['student_id']]);
        $members = $stmt->fetchAll();
    }

    $rating_stmt = $pdo->prepare('SELECT rating_score, note, rated_at FROM project_member_ratings WHERE project_id = ? AND student_id = ? AND supervisor_id = ? LIMIT 1');
    foreach ($members as &$member) {
        $member_id = (int) $member['student_id'];
        $metrics = get_member_input_metrics($pdo, (int) $project['id'], $member_id);
        $member = array_merge($member, $metrics);

        $rating_stmt->execute([(int) $project['id'], $member_id, $supervisor_id]);
        $rating = $rating_stmt->fetch();
        $member['contribution_rating'] = $rating ? (float) $rating['rating_score'] : null;
        $member['rating_note'] = $rating['note'] ?? null;
        $member['rated_at'] = $rating['rated_at'] ?? null;
    }
    unset($member);

    return $members;
}

function get_project_member_ids(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }

    if (empty($row['group_id'])) {
        $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE created_by = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $row['student_id']]);
        $fallback_group_id = (int) ($stmt->fetchColumn() ?: 0);
        if ($fallback_group_id > 0) {
            $check = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? LIMIT 1');
            $check->execute([$fallback_group_id]);
            $existing_group_project = (int) ($check->fetchColumn() ?: 0);
            if ($existing_group_project === 0 || $existing_group_project === $project_id) {
                $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND group_id IS NULL')->execute([$fallback_group_id, $project_id]);
                $row['group_id'] = $fallback_group_id;
            }
        }
    }

    $member_ids = [(int) $row['student_id']];
    if (!empty($row['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $row['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $member_ids[] = (int) $m['student_id'];
        }
}

    return array_values(array_unique($member_ids));
}

$is_archived = ($project['status'] ?? '') === 'archived';

ensure_member_rating_table($pdo);
ensure_project_contribution_status_table($pdo);
ensure_pending_completion_status($pdo);
ensure_project_milestones_table($pdo);

// Handle milestone actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && !$is_archived) {
    $ms_action = $_POST['action'] ?? '';
    if ($ms_action === 'add_milestone') {
        $ms_title  = trim($_POST['ms_title'] ?? '');
        $ms_desc   = trim($_POST['ms_desc'] ?? '');
        $ms_due    = trim($_POST['ms_due'] ?? '');
        $ms_chap   = trim($_POST['ms_chapter'] ?? '');
        if ($ms_title !== '' && $ms_due !== '') {
            $pdo->prepare('INSERT INTO project_milestones (project_id, title, description, chapter_ref, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$pid, $ms_title, $ms_desc ?: null, $ms_chap ?: null, $ms_due, $uid]);
            flash('success', 'Milestone added.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#tab-milestones'));
        }
    } elseif ($ms_action === 'complete_milestone') {
        $ms_id = (int) ($_POST['ms_id'] ?? 0);
        if ($ms_id > 0) {
            $pdo->prepare('UPDATE project_milestones SET completed_at = NOW(), completed_by = ? WHERE id = ? AND project_id = ? AND completed_at IS NULL')
                ->execute([$uid, $ms_id, $pid]);
            flash('success', 'Milestone marked complete.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#tab-milestones'));
        }
    } elseif ($ms_action === 'delete_milestone') {
        $ms_id = (int) ($_POST['ms_id'] ?? 0);
        if ($ms_id > 0) {
            $pdo->prepare('DELETE FROM project_milestones WHERE id = ? AND project_id = ?')->execute([$ms_id, $pid]);
            flash('success', 'Milestone removed.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#tab-milestones'));
        }
    }
}
$member_profiles = get_project_member_profiles($pdo, $project, $uid);

// Load contribution_status for each member from the separate tracking table
$contrib_stmt = $pdo->prepare('SELECT contribution_status FROM project_contribution_status WHERE project_id = ? AND student_id = ? LIMIT 1');
foreach ($member_profiles as &$mp) {
    $contrib_stmt->execute([$pid, (int) $mp['student_id']]);
    $mp['contribution_status'] = $contrib_stmt->fetchColumn() ?: 'partial';
}
unset($mp);
$member_profile_ids = array_map(static function ($m) {
    return (int) $m['student_id'];
}, $member_profiles);

// Documents with feedback form
$stmt = $pdo->prepare('SELECT pd.*, (SELECT comment FROM document_feedback WHERE document_id = pd.id ORDER BY created_at DESC LIMIT 1) AS latest_feedback FROM project_documents pd WHERE pd.project_id = ? ORDER BY pd.uploaded_at DESC');
$stmt->execute([$pid]);
$documents = $stmt->fetchAll();

// Submit document feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($is_archived) {
        flash('error', 'This project is archived and cannot be modified.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'rate_contribution') {
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $rating_score = isset($_POST['rating_score']) ? (float) $_POST['rating_score'] : -1;
        $rating_note = trim($_POST['rating_note'] ?? '');

        if (!in_array($member_id, $member_profile_ids, true)) {
            flash('error', 'Invalid member selected for contribution rating.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
        }
        if ($rating_score < 0 || $rating_score > 10) {
            flash('error', 'Contribution rating must be between 0 and 10.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
        }

        $stmt = $pdo->prepare('INSERT INTO project_member_ratings (project_id, student_id, supervisor_id, rating_score, note) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating_score = VALUES(rating_score), note = VALUES(note), rated_at = NOW()');
        $stmt->execute([$pid, $member_id, $uid, $rating_score, $rating_note ?: null]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
            $member_id,
            'contribution_rating',
            'Contribution rating updated',
            'Your supervisor updated your contribution rating for the group project.',
            base_url('student/project.php')
        ]);

        flash('success', 'Member contribution rating saved.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
    }
    if ($action === 'feedback' && isset($_POST['document_id'])) {
        $doc_id = (int) $_POST['document_id'];
        $comment = trim($_POST['comment'] ?? '');
        if ($comment) {
            $stmt = $pdo->prepare('INSERT INTO document_feedback (document_id, supervisor_id, comment) VALUES (?, ?, ?)');
            $stmt->execute([$doc_id, $uid, $comment]);
            foreach (get_project_member_ids($pdo, $pid) as $member_id) {
                $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$member_id, 'feedback', 'Document feedback', 'Your supervisor left feedback on a document.', base_url('student/project.php')]);
            }
            flash('success', 'Feedback submitted.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
        }
    }
    if ($action === 'assessment') {
        $score = isset($_POST['score']) ? (float) $_POST['score'] : null;
        $comments = trim($_POST['comments'] ?? '');
        $type = trim($_POST['assessment_type'] ?? 'proposal_review');
        $max = (float) ($_POST['max_score'] ?? 100);
        $stmt = $pdo->prepare('INSERT INTO assessments (project_id, supervisor_id, assessment_type, score, max_score, comments) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score), comments = VALUES(comments), submitted_at = NOW()');
        $stmt->execute([$pid, $uid, $type, $score, $max, $comments]);
        foreach (get_project_member_ids($pdo, $pid) as $member_id) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$member_id, 'feedback', 'Assessment updated', 'Your supervisor submitted an assessment.', base_url('student/project.php')]);
        }
        flash('success', 'Assessment saved.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
    }
    if ($action === 'update_contribution_status') {
        $member_id = (int) ($_POST['member_id'] ?? 0);
        $status = $_POST['contribution_status'] ?? '';
        if (!in_array($member_id, $member_profile_ids, true)) {
            flash('error', 'Invalid member selected.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
        }
        if (!in_array($status, ['contributed', 'partial', 'not_contributed'], true)) {
            flash('error', 'Invalid contribution status.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
        }
        $pdo->prepare('INSERT INTO project_contribution_status (project_id, student_id, contribution_status, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE contribution_status = VALUES(contribution_status), updated_by = VALUES(updated_by), updated_at = NOW()')->execute([$pid, $member_id, $status, $uid]);
        flash('success', 'Contribution status updated.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid . '#contributions'));
    }
    if ($action === 'submit_completion') {
        if (!in_array($project['status'], ['in_progress', 'approved'], true)) {
            flash('error', 'Project cannot be submitted for HOD review at this stage.');
            redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
        }
        $pdo->prepare('UPDATE projects SET status = "pending_completion" WHERE id = ? AND supervisor_id = ?')->execute([$pid, $uid]);

        // Notify HOD of the department
        $dept_stmt = $pdo->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
        $dept_stmt->execute([(int) $project['student_id']]);
        $dept = (string) ($dept_stmt->fetchColumn() ?: '');
        if ($dept !== '') {
            $hod_info = resolve_department_info($pdo, $dept);
            if (!empty($hod_info['variants'])) {
                $hod_ph = sql_placeholders(count($hod_info['variants']));
                $hod_stmt = $pdo->prepare('SELECT id FROM users WHERE role = "hod" AND is_active = 1 AND LOWER(TRIM(COALESCE(department, ""))) IN (' . $hod_ph . ') LIMIT 1');
                $hod_stmt->execute($hod_info['variants']);
                $hod_id = (int) ($hod_stmt->fetchColumn() ?: 0);
                if ($hod_id > 0) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                        $hod_id, 'pending_completion', 'Project ready for HOD review',
                        'A supervisor has marked a project complete and submitted it for your review: ' . ($project['title'] ?? ''),
                        base_url('hod/archive.php')
                    ]);
                }
            }
        }

        foreach (get_project_member_ids($pdo, $pid) as $member_id) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                $member_id, 'pending_completion', 'Project submitted for final review',
                'Your supervisor has submitted your project to the HOD for final review.',
                base_url('student/project.php')
            ]);
        }
        flash('success', 'Project submitted to HOD for final review.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
    }
}


$pageTitle = 'Group Vault Detail';
/* Capture flash before header.php consumes it — displayed as toasts below */
$_toast_success = flash('success');
$_toast_error   = flash('error');
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($group_info): ?>
    <h1 class="mb-2"><?= e($group_info['name']) ?> <small class="text-muted fs-6">(Group Project)</small></h1>
    <p class="text-muted mb-1"><?= e($project['title']) ?> — <span class="badge bg-secondary"><?= e($project['status']) ?></span></p>
    <p class="text-muted">Lead: <?= e($project['student_name']) ?> | Members (<?= (int) ($group_info['member_count'] ?? 0) ?>): <?= e($group_info['member_names'] ?: $project['student_name']) ?></p>
<?php else: ?>
    <h1 class="mb-2"><?= e($project['student_name']) ?></h1>
    <p class="text-muted"><?= e($project['title']) ?> — <span class="badge bg-secondary"><?= e($project['status']) ?></span></p>
<?php endif; ?>

<?php if ($is_archived): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-archive-fill fs-5"></i>
        <div>This project is <strong>archived</strong>. All history is visible, but no modifications are permitted.</div>
    </div>
<?php endif; ?>

<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <a href="<?= base_url('supervisor/assessment.php?pid=' . $pid) ?>" class="btn btn-outline-success">
        <i class="bi bi-award"></i> Assessment Sheet
    </a>
    <a href="<?= base_url('supervisor/export_log.php?pid=' . $pid) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-download"></i> Export Activity Log
    </a>
    <?php if (!$is_archived && in_array($project['status'], ['in_progress', 'approved'], true)): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_completion">
            <button type="submit" class="btn btn-warning" onclick="return confirm('Submit this project to HOD for final review? Make sure contribution statuses are set correctly.')">
                <i class="bi bi-send-check"></i> Submit for HOD Review
            </button>
        </form>
    <?php elseif (!$is_archived && $project['status'] === 'pending_completion'): ?>
        <span class="btn btn-success disabled"><i class="bi bi-hourglass-split"></i> Awaiting HOD Review</span>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-4" id="detailTabs" role="tablist">
    <li class="nav-item" role="presentation"><a class="nav-link active" data-bs-toggle="tab" href="#documents">Documents</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#milestones" id="tab-milestones">Milestones</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#logbook">Supervisor Log Book</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#contributions">Contributions</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="documents">
        <div class="card">
            <div class="card-body">
                <?php if (empty($documents)): ?>
                    <p class="text-muted mb-0">No documents uploaded yet.</p>
                <?php else: ?>
                    <?php foreach ($documents as $d): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <strong><?= e($d['file_name']) ?></strong>
                                    <span class="text-muted">(<?= e($d['document_type'] === 'proposal' ? 'documentation' : $d['document_type']) ?>) — <?= e(date('M j, Y H:i', strtotime($d['uploaded_at']))) ?></span>
                                </div>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Document actions">
                                    <a href="<?= base_url('supervisor/view_document.php?id=' . $d['id']) ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <a href="<?= base_url('download.php?id=' . $d['id']) ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            </div>
                            <?php if ($d['latest_feedback']): ?><br><em class="text-muted">Your feedback: <?= e($d['latest_feedback']) ?></em><?php endif; ?>
                            <form method="post" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="feedback">
                                <input type="hidden" name="document_id" value="<?= $d['id'] ?>">
                                <div class="input-group">
                                    <input type="text" name="comment" class="form-control" placeholder="Add or update feedback...">
                                    <button type="submit" class="btn btn-primary">Submit Feedback</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="milestones">
        <?php
        $ms_stmt = $pdo->prepare('SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date ASC');
        $ms_stmt->execute([$pid]);
        $milestones = $ms_stmt->fetchAll();
        $ms_total = count($milestones);
        $ms_done  = count(array_filter($milestones, fn($m) => $m['completed_at'] !== null));
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Milestone Tracker</span>
                <?php if ($ms_total > 0): ?>
                    <span class="badge bg-secondary"><?= $ms_done ?>/<?= $ms_total ?> complete</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($ms_total > 0): ?>
                    <div class="progress mb-4" style="height:12px;" title="<?= $ms_done ?>/<?= $ms_total ?> milestones done">
                        <div class="progress-bar bg-success" style="width:<?= round($ms_done / $ms_total * 100) ?>%"></div>
                    </div>
                    <?php foreach ($milestones as $ms):
                        $overdue = $ms['completed_at'] === null && $ms['due_date'] < date('Y-m-d');
                        $done    = $ms['completed_at'] !== null;
                    ?>
                    <div class="d-flex align-items-start gap-3 mb-3 p-3 rounded border <?= $done ? 'border-success' : ($overdue ? 'border-danger' : 'border-secondary') ?>">
                        <div class="mt-1 fs-5">
                            <?php if ($done): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                            <?php elseif ($overdue): ?>
                                <i class="bi bi-exclamation-circle-fill text-danger"></i>
                            <?php else: ?>
                                <i class="bi bi-circle text-secondary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= e($ms['title']) ?></div>
                            <?php if ($ms['description']): ?><div class="text-muted small"><?= e($ms['description']) ?></div><?php endif; ?>
                            <div class="small mt-1">
                                <?php if ($ms['chapter_ref']): ?><span class="badge bg-info text-dark me-1"><?= e(str_replace('chapter', 'Chapter ', $ms['chapter_ref'])) ?></span><?php endif; ?>
                                <span class="<?= $overdue ? 'text-danger fw-semibold' : 'text-muted' ?>">Due: <?= e(date('d/m/Y', strtotime($ms['due_date']))) ?><?= $overdue ? ' — Overdue' : '' ?></span>
                                <?php if ($done): ?><span class="text-success ms-2">Completed <?= e(date('d/m/Y', strtotime($ms['completed_at']))) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$is_archived): ?>
                        <div class="d-flex flex-column gap-1">
                            <?php if (!$done): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="complete_milestone">
                                <input type="hidden" name="ms_id" value="<?= $ms['id'] ?>">
                                <button class="btn btn-sm btn-success" title="Mark complete"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Remove this milestone?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_milestone">
                                <input type="hidden" name="ms_id" value="<?= $ms['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">No milestones yet. Add one below to track progress.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$is_archived): ?>
        <div class="card">
            <div class="card-header">Add Milestone</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_milestone">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" name="ms_title" class="form-control" placeholder="e.g. Submit Chapter 1 draft" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="ms_due" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Chapter (optional)</label>
                            <select name="ms_chapter" class="form-select">
                                <option value="">— None —</option>
                                <option value="chapter1">Chapter 1</option>
                                <option value="chapter2">Chapter 2</option>
                                <option value="chapter3">Chapter 3</option>
                                <option value="chapter4">Chapter 4</option>
                                <option value="chapter5">Chapter 5</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" name="ms_desc" class="form-control" placeholder="Additional instructions or criteria...">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Milestone</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="logbook">
        <?php
        $stmt = $pdo->prepare('SELECT id, entry_date, title, content, supervisor_approved, supervisor_comment, created_at FROM logbook_entries WHERE project_id = ? ORDER BY entry_date DESC');
        $stmt->execute([$pid]);
        $entries = $stmt->fetchAll();
        ?>
        <div class="card">
            <div class="card-body">
                <?php if (empty($entries)): ?>
                    <p class="text-muted mb-0">No logbook entries yet.</p>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <div class="border rounded p-3 mb-3 logbook-entry <?= $e['supervisor_approved'] === null ? 'pending' : ($e['supervisor_approved'] ? 'approved' : 'flagged') ?>">
                            <strong><?= e($e['title']) ?></strong> — <?= e($e['entry_date']) ?>
                            <span class="badge bg-<?= $e['supervisor_approved'] === null ? 'warning' : ($e['supervisor_approved'] ? 'success' : 'danger') ?> ms-2">
                                <?= $e['supervisor_approved'] === null ? 'Pending' : ($e['supervisor_approved'] ? 'Approved' : 'Flagged') ?>
                            </span>
                            <p class="mb-2 mt-1"><?= nl2br(e($e['content'])) ?></p>
                            <?php if ($e['supervisor_approved'] === null): ?>
                                <form method="post" action="<?= base_url('supervisor/logbook_action.php') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <input type="hidden" name="approve" value="1">
                                    <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                </form>
                                <form method="post" action="<?= base_url('supervisor/logbook_action.php') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <input type="hidden" name="approve" value="0">
                                    <input type="text" name="comment" placeholder="Comment (optional)" class="form-control form-control-sm d-inline-block w-auto">
                                    <button type="submit" class="btn btn-sm btn-danger">Flag</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="contributions">
        <?php if (empty($member_profiles)): ?>
            <div class="card"><div class="card-body text-muted">No members found for contribution tracking.</div></div>
        <?php else: ?>
        <?php
            /* ── Prepare data arrays for charts ── */
            $c_names   = [];
            $c_docs    = [];
            $c_logbook = [];
            $c_msgs    = [];
            $c_scores  = [];
            $c_ratings = [];
            $c_status  = [];
            foreach ($member_profiles as $m) {
                $short = explode(' ', trim($m['full_name']));
                $c_names[]   = count($short) > 1 ? $short[0] . ' ' . $short[count($short)-1] : $m['full_name'];
                $c_docs[]    = (int) $m['docs_uploaded'];
                $c_logbook[] = (int) $m['logbook_entries'];
                $c_msgs[]    = (int) $m['messages_sent'];
                $c_scores[]  = (int) $m['activity_score'];
                $c_ratings[] = $m['contribution_rating'] !== null ? (float) $m['contribution_rating'] : 0;
                $cs = $m['contribution_status'] ?? 'partial';
                $c_status[]  = $cs === 'contributed' ? 'Contributed' : ($cs === 'not_contributed' ? 'Not Contributed' : 'Partial');
            }
            $palette = ['#4e79d6','#38bdf8','#34d399','#fb923c','#f472b6','#a78bfa','#facc15'];
        ?>

        <!-- ── Charts row ── -->
        <div class="row g-3 mb-4">
            <!-- Activity breakdown grouped bar -->
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header small fw-semibold">Activity Breakdown per Member</div>
                    <div class="card-body d-flex align-items-center justify-content-center" style="min-height:260px;">
                        <canvas id="activityBarChart"></canvas>
                    </div>
                </div>
            </div>
            <!-- Activity score doughnut -->
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header small fw-semibold">Activity Score Distribution</div>
                    <div class="card-body d-flex align-items-center justify-content-center" style="min-height:260px;">
                        <canvas id="activityDoughnut" style="max-height:230px;"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supervisor rating bar -->
        <?php if (array_sum($c_ratings) > 0): ?>
        <div class="card mb-4">
            <div class="card-header small fw-semibold">Supervisor Rating (0 – 10)</div>
            <div class="card-body" style="min-height:160px;">
                <canvas id="ratingBar"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Per-member status + rating forms ── -->
        <div class="row g-3">
            <?php foreach ($member_profiles as $idx => $m):
                $cs       = $m['contribution_status'] ?? 'partial';
                $cs_color = $cs === 'contributed' ? 'success' : ($cs === 'not_contributed' ? 'danger' : 'warning');
                $cs_label = $cs === 'contributed' ? 'Contributed' : ($cs === 'not_contributed' ? 'Not Contributed' : 'Partial');
                $bar_pct  = min(100, (int) $m['activity_score']);
                $color    = $palette[$idx % count($palette)];
            ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body pb-2">
                        <!-- Member header -->
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0"
                                 style="width:40px;height:40px;background:<?= $color ?>;font-size:.95rem;">
                                <?= strtoupper(substr($m['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold lh-1"><?= e($m['full_name']) ?>
                                    <?php if (($m['role'] ?? '') === 'lead'): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.65em;">Lead</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small"><?= e($m['index_number'] ?: $m['email']) ?></div>
                            </div>
                            <span class="badge bg-<?= $cs_color ?> ms-auto"><?= $cs_label ?></span>
                        </div>

                        <!-- Mini stats row -->
                        <div class="row g-2 text-center mb-3">
                            <div class="col-4">
                                <div class="fw-bold fs-5"><?= (int) $m['docs_uploaded'] ?></div>
                                <div class="text-muted" style="font-size:.72em;">DOCS</div>
                            </div>
                            <div class="col-4">
                                <div class="fw-bold fs-5"><?= (int) $m['logbook_entries'] ?></div>
                                <div class="text-muted" style="font-size:.72em;">LOGBOOK</div>
                            </div>
                            <div class="col-4">
                                <div class="fw-bold fs-5"><?= (int) $m['messages_sent'] ?></div>
                                <div class="text-muted" style="font-size:.72em;">MESSAGES</div>
                            </div>
                        </div>

                        <!-- Activity score progress bar -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Activity Score</span>
                                <strong><?= (int) $m['activity_score'] ?></strong>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar" role="progressbar"
                                     style="width:<?= $bar_pct ?>%;background:<?= $color ?>;"
                                     aria-valuenow="<?= $bar_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>

                        <!-- Supervisor rating display -->
                        <?php if ($m['contribution_rating'] !== null): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Supervisor Rating</span>
                                <strong><?= number_format((float)$m['contribution_rating'], 1) ?> / 10</strong>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar bg-info" role="progressbar"
                                     style="width:<?= min(100, (float)$m['contribution_rating'] * 10) ?>%;"
                                     aria-valuenow="<?= $m['contribution_rating'] ?>" aria-valuemin="0" aria-valuemax="10"></div>
                            </div>
                            <?php if ($m['rating_note']): ?>
                                <div class="text-muted mt-1" style="font-size:.75em;"><i class="bi bi-quote me-1"></i><?= e($m['rating_note']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Forms footer -->
                    <div class="card-footer py-2 d-flex flex-wrap gap-2 align-items-center">
                        <!-- Status -->
                        <form method="post" class="d-flex gap-1 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_contribution_status">
                            <input type="hidden" name="member_id" value="<?= (int) $m['student_id'] ?>">
                            <select name="contribution_status" class="form-select form-select-sm" style="width:auto;">
                                <option value="partial"          <?= $cs === 'partial'          ? 'selected' : '' ?>>Partial</option>
                                <option value="contributed"      <?= $cs === 'contributed'      ? 'selected' : '' ?>>Contributed</option>
                                <option value="not_contributed"  <?= $cs === 'not_contributed'  ? 'selected' : '' ?>>Not Contributed</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Set</button>
                        </form>
                        <!-- Rating -->
                        <form method="post" class="d-flex gap-1 align-items-center flex-grow-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="rate_contribution">
                            <input type="hidden" name="member_id" value="<?= (int) $m['student_id'] ?>">
                            <input type="number" class="form-control form-control-sm" name="rating_score"
                                   min="0" max="10" step="0.1" placeholder="0 – 10" style="width:90px;"
                                   oninput="if(+this.value>10)this.value=10;if(+this.value<0)this.value=0;"
                                   value="<?= $m['contribution_rating'] !== null ? e((string) $m['contribution_rating']) : '' ?>" required>
                            <input type="text" class="form-control form-control-sm" name="rating_note"
                                   placeholder="Note" style="min-width:80px;flex:1;"
                                   value="<?= e($m['rating_note'] ?? '') ?>">
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            const names   = <?= json_encode($c_names) ?>;
            const docs    = <?= json_encode($c_docs) ?>;
            const logbook = <?= json_encode($c_logbook) ?>;
            const msgs    = <?= json_encode($c_msgs) ?>;
            const scores  = <?= json_encode($c_scores) ?>;
            const ratings = <?= json_encode($c_ratings) ?>;
            const palette = <?= json_encode($palette) ?>;

            const gridColor  = 'rgba(255,255,255,.08)';
            const tickColor  = 'rgba(255,255,255,.5)';
            const legendOpts = { labels: { color: tickColor, boxWidth: 12, padding: 12 } };

            /* ── Activity grouped bar ── */
            new Chart(document.getElementById('activityBarChart'), {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [
                        { label: 'Documents',       data: docs,    backgroundColor: '#4e79d6' },
                        { label: 'Logbook Entries', data: logbook, backgroundColor: '#34d399' },
                        { label: 'Messages',        data: msgs,    backgroundColor: '#fb923c' },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    plugins: { legend: legendOpts },
                    scales: {
                        x: { ticks: { color: tickColor }, grid: { color: gridColor } },
                        y: { beginAtZero: true, ticks: { color: tickColor, stepSize: 1 }, grid: { color: gridColor } }
                    }
                }
            });

            /* ── Activity score doughnut ── */
            new Chart(document.getElementById('activityDoughnut'), {
                type: 'doughnut',
                data: {
                    labels: names,
                    datasets: [{ data: scores, backgroundColor: palette, borderWidth: 2 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    plugins: {
                        legend: legendOpts,
                        tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed } }
                    },
                    cutout: '62%'
                }
            });

            /* ── Supervisor rating horizontal bar ── */
            const ratingEl = document.getElementById('ratingBar');
            if (ratingEl) {
                new Chart(ratingEl, {
                    type: 'bar',
                    data: {
                        labels: names,
                        datasets: [{
                            label: 'Supervisor Rating',
                            data: ratings,
                            backgroundColor: palette.map(c => c + 'cc'),
                            borderColor: palette,
                            borderWidth: 1,
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { min: 0, max: 10, ticks: { color: tickColor }, grid: { color: gridColor } },
                            y: { ticks: { color: tickColor }, grid: { color: gridColor } }
                        }
                    }
                });
            }
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<p class="mt-3"><a href="<?= base_url('supervisor/students.php') ?>" class="btn btn-outline-secondary">Back to Group Vaults</a></p>

<!-- Toast notification -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="mainToast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="mainToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
(function () {
    /* ── Toast ── */
    const toastMsg  = <?= json_encode($_toast_success ?: $_toast_error ?: '') ?>;
    const toastType = <?= json_encode($_toast_success ? 'success' : ($_toast_error ? 'danger' : '')) ?>;
    if (toastMsg) {
        const el = document.getElementById('mainToast');
        el.classList.add('bg-' + toastType);
        document.getElementById('mainToastMsg').textContent = toastMsg;
        new bootstrap.Toast(el, { delay: 4500 }).show();
    }

    /* ── Activate tab from URL hash ── */
    const hash = location.hash;
    if (hash) {
        const tabEl = document.getElementById(hash.slice(1));
        if (tabEl && tabEl.getAttribute('data-bs-toggle') === 'tab') {
            new bootstrap.Tab(tabEl).show();
        }
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
