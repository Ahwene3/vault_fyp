<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare('SELECT p.*, u.full_name AS student_name, u.email, u.reg_number FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND p.supervisor_id = ?');
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
        $stmt = $pdo->prepare('SELECT u.id AS student_id, u.full_name, u.reg_number, u.email, gm.role FROM `group_members` gm JOIN users u ON u.id = gm.student_id WHERE gm.group_id = ? ORDER BY CASE WHEN gm.role = "lead" THEN 0 ELSE 1 END, u.full_name');
        $stmt->execute([(int) $project['group_id']]);
        $members = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT id AS student_id, full_name, reg_number, email, "lead" AS role FROM users WHERE id = ? LIMIT 1');
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
        if ($rating_score < 0 || $rating_score > 100) {
            flash('error', 'Contribution rating must be between 0 and 100.');
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

$assessments = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC');
$assessments->execute([$pid]);
$assessments = $assessments->fetchAll();

$pageTitle = 'Group Vault Detail';
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
    <a href="<?= base_url('supervisor/logsheet.php?pid=' . $pid) ?>" class="btn btn-outline-primary">
        <i class="bi bi-journal-text"></i> Log Sheet
    </a>
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
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#assessments">Assessments</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#logbook">Logbook</a></li>
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

    <div class="tab-pane fade" id="assessments">
        <div class="card mb-3">
            <div class="card-header">Submit Assessment</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assessment">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <select name="assessment_type" class="form-select">
                                <option value="proposal_review">Proposal Review</option>
                                <option value="progress">Progress</option>
                                <option value="final_grade">Final Grade</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Score</label>
                            <input type="number" name="score" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Max</label>
                            <input type="number" name="max_score" class="form-control" value="100" step="0.01">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Comments</label>
                            <textarea name="comments" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-primary">Save Assessment</button></div>
                    </div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h6>Previous assessments</h6>
                <?php if (empty($assessments)): ?><p class="text-muted mb-0">None yet.</p>
                <?php else: ?>
                    <table class="table table-sm">
                        <thead><tr><th>Type</th><th>Score</th><th>Comments</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($assessments as $a): ?>
                                <tr>
                                    <td><?= e($a['assessment_type']) ?></td>
                                    <td><?= $a['score'] !== null ? e($a['score'] . ' / ' . $a['max_score']) : '—' ?></td>
                                    <td><?= e($a['comments'] ?? '—') ?></td>
                                    <td><?= e(date('M j, Y', strtotime($a['submitted_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
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
                                    <input type="hidden" name="approve" value="1"><button type="submit" class="btn btn-sm btn-success">Approve</button>
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
        <div class="card">
            <div class="card-header">Group Vault Contribution Matrix</div>
            <div class="card-body">
                <?php if (empty($member_profiles)): ?>
                    <p class="text-muted mb-0">No members found for contribution tracking.</p>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        Input score formula: documents x 3 + logbook entries x 2 + messages sent.
                        Supervisor rating is set on a 0-100 scale per member.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Index No.</th>
                                    <th>Role</th>
                                    <th>Docs</th>
                                    <th>Logbook</th>
                                    <th>Messages</th>
                                    <th>Input Score</th>
                                    <th style="min-width: 200px;">Contribution Status</th>
                                    <th style="min-width: 320px;">Supervisor Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($member_profiles as $m): ?>
                                    <?php
                                        $cs = $m['contribution_status'] ?? 'partial';
                                        $cs_badge = $cs === 'contributed' ? 'bg-success' : ($cs === 'not_contributed' ? 'bg-danger' : 'bg-warning text-dark');
                                        $cs_label = $cs === 'contributed' ? 'Contributed' : ($cs === 'not_contributed' ? 'Not Contributed' : 'Partial');
                                    ?>
                                    <tr>
                                        <td>
                                            <?= e($m['full_name']) ?>
                                            <?php if (($m['role'] ?? '') === 'lead'): ?><span class="badge bg-warning text-dark ms-1">Lead</span><?php endif; ?>
                                        </td>
                                        <td><?= e($m['reg_number'] ?: $m['email']) ?></td>
                                        <td><?= e(ucfirst($m['role'] ?? 'member')) ?></td>
                                        <td><?= (int) $m['docs_uploaded'] ?></td>
                                        <td><?= (int) $m['logbook_entries'] ?></td>
                                        <td><?= (int) $m['messages_sent'] ?></td>
                                        <td><strong><?= (int) $m['activity_score'] ?></strong></td>
                                        <td>
                                            <span class="badge <?= $cs_badge ?> mb-1"><?= $cs_label ?></span>
                                            <form method="post" class="d-flex gap-1 flex-wrap align-items-center mt-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_contribution_status">
                                                <input type="hidden" name="member_id" value="<?= (int) $m['student_id'] ?>">
                                                <select name="contribution_status" class="form-select form-select-sm" style="max-width: 165px;">
                                                    <option value="partial" <?= $cs === 'partial' ? 'selected' : '' ?>>Partial</option>
                                                    <option value="contributed" <?= $cs === 'contributed' ? 'selected' : '' ?>>Contributed</option>
                                                    <option value="not_contributed" <?= $cs === 'not_contributed' ? 'selected' : '' ?>>Not Contributed</option>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Set</button>
                                            </form>
                                        </td>
                                        <td>
                                            <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="rate_contribution">
                                                <input type="hidden" name="member_id" value="<?= (int) $m['student_id'] ?>">
                                                <input type="number" class="form-control form-control-sm" name="rating_score" min="0" max="100" step="0.01" placeholder="0-100" style="max-width: 90px;" value="<?= $m['contribution_rating'] !== null ? e((string) $m['contribution_rating']) : '' ?>" required>
                                                <input type="text" class="form-control form-control-sm" name="rating_note" placeholder="Optional note" style="min-width: 170px;" value="<?= e($m['rating_note'] ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                            </form>
                                            <?php if (!empty($m['rated_at'])): ?>
                                                <small class="text-muted d-block mt-1">Last rated: <?= e(date('M j, Y H:i', strtotime($m['rated_at']))) ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<p class="mt-3"><a href="<?= base_url('supervisor/students.php') ?>" class="btn btn-outline-secondary">Back to Group Vaults</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
