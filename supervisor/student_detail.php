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

$student_id = (int) $project['student_id'];

// Documents with feedback form
$stmt = $pdo->prepare('SELECT pd.*, (SELECT comment FROM document_feedback WHERE document_id = pd.id ORDER BY created_at DESC LIMIT 1) AS latest_feedback FROM project_documents pd WHERE pd.project_id = ? ORDER BY pd.uploaded_at DESC');
$stmt->execute([$pid]);
$documents = $stmt->fetchAll();

// Submit document feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'feedback' && isset($_POST['document_id'])) {
        $doc_id = (int) $_POST['document_id'];
        $comment = trim($_POST['comment'] ?? '');
        if ($comment) {
            $stmt = $pdo->prepare('INSERT INTO document_feedback (document_id, supervisor_id, comment) VALUES (?, ?, ?)');
            $stmt->execute([$doc_id, $uid, $comment]);
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$student_id, 'feedback', 'Document feedback', 'Your supervisor left feedback on a document.', base_url('student/project.php')]);
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
        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$student_id, 'feedback', 'Assessment updated', 'Your supervisor submitted an assessment.', base_url('student/project.php')]);
        flash('success', 'Assessment saved.');
        redirect(base_url('supervisor/student_detail.php?pid=' . $pid));
    }
}

$assessments = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC');
$assessments->execute([$pid]);
$assessments = $assessments->fetchAll();

$pageTitle = 'Student Detail';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-2"><?= e($project['student_name']) ?></h1>
<p class="text-muted"><?= e($project['title']) ?> — <span class="badge bg-secondary"><?= e($project['status']) ?></span></p>

<ul class="nav nav-tabs mb-4" id="detailTabs" role="tablist">
    <li class="nav-item" role="presentation"><a class="nav-link active" data-bs-toggle="tab" href="#documents">Documents</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#assessments">Assessments</a></li>
    <li class="nav-item" role="presentation"><a class="nav-link" data-bs-toggle="tab" href="#logbook">Logbook</a></li>
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
                            <strong><a href="<?= base_url('download.php?id=' . $d['id']) ?>"><?= e($d['file_name']) ?></a></strong> (<?= e($d['document_type']) ?>) — <?= e(date('M j, Y H:i', strtotime($d['uploaded_at']))) ?>
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
</div>

<p class="mt-3"><a href="<?= base_url('supervisor/students.php') ?>" class="btn btn-outline-secondary">Back to My Students</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
