<?php
/**
 * Formal Assessment Sheet - Comprehensive evaluation form
 */
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

$is_archived = ($project['status'] ?? '') === 'archived';

function assessment_recipient_ids(PDO $pdo, array $project): array {
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

$error = '';

// Submit assessment form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($is_archived) {
        flash('error', 'This project is archived. Assessments cannot be submitted.');
        redirect(base_url('supervisor/assessment.php?pid=' . $pid));
    }
    $assessment_type = trim($_POST['assessment_type'] ?? 'proposal_review');
    $research = isset($_POST['research_quality']) ? (float) $_POST['research_quality'] : null;
    $method = isset($_POST['methodology']) ? (float) $_POST['methodology'] : null;
    $collab = isset($_POST['collaboration']) ? (float) $_POST['collaboration'] : null;
    $present = isset($_POST['presentation']) ? (float) $_POST['presentation'] : null;
    $origin = isset($_POST['originality']) ? (float) $_POST['originality'] : null;
    $remarks = trim($_POST['remarks'] ?? '');
    
    $scores = [];
    if ($research !== null) $scores[] = $research;
    if ($method !== null) $scores[] = $method;
    if ($collab !== null) $scores[] = $collab;
    if ($present !== null) $scores[] = $present;
    if ($origin !== null) $scores[] = $origin;
    
    $total = !empty($scores) ? round(array_sum($scores) / count($scores), 2) : null;
    
    if (!$remarks) {
        $error = 'Written remarks are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO assessments (project_id, supervisor_id, assessment_type, research_quality, methodology, collaboration, presentation, originality, score, max_score, remarks, supervisor_confirmed, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, ?, 1, NOW()) ON DUPLICATE KEY UPDATE research_quality = VALUES(research_quality), methodology = VALUES(methodology), collaboration = VALUES(collaboration), presentation = VALUES(presentation), originality = VALUES(originality), score = VALUES(score), remarks = VALUES(remarks), supervisor_confirmed = 1, confirmed_at = NOW(), submitted_at = NOW()');
        $stmt->execute([$pid, $uid, $assessment_type, $research, $method, $collab, $present, $origin, $total, $remarks]);
        
        // Notify all project members (solo student or group members).
        foreach (assessment_recipient_ids($pdo, $project) as $member_id) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                $member_id,
                'assessment_submitted',
                'Assessment completed',
                'Your supervisor submitted a formal assessment for your project.',
                base_url('student/project.php')
            ]);
        }
        
        flash('success', 'Assessment sheet submitted and confirmed.');
        redirect(base_url('supervisor/assessment.php?pid=' . $pid));
    }
}

// Get current assessment
$stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? AND supervisor_id = ? ORDER BY submitted_at DESC LIMIT 1');
$stmt->execute([$pid, $uid]);
$current_assessment = $stmt->fetch();

// Get all assessments history
$stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC LIMIT 5');
$stmt->execute([$pid]);
$assessment_history = $stmt->fetchAll();

$pageTitle = 'Assessment Sheet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <h1 class="mb-1"><?= e($project['student_name']) ?> — <?= e($project['title']) ?></h1>
    <p class="text-muted"><a href="<?= base_url('supervisor/student_detail.php?pid=' . $pid) ?>">← Back to student detail</a></p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($is_archived): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-archive-fill fs-5"></i>
        <div>This project is <strong>archived</strong>. Assessment history is available below, but new assessments cannot be submitted.</div>
    </div>
<?php endif; ?>

<?php if (!$is_archived): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Formal Assessment Sheet</h5>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Assessment Type</label>
                    <select name="assessment_type" class="form-select">
                        <option value="proposal_review" <?= $current_assessment && $current_assessment['assessment_type'] === 'proposal_review' ? 'selected' : '' ?>>Proposal Review</option>
                        <option value="midterm" <?= $current_assessment && $current_assessment['assessment_type'] === 'midterm' ? 'selected' : '' ?>>Mid-term Evaluation</option>
                        <option value="final_evaluation" <?= $current_assessment && $current_assessment['assessment_type'] === 'final_evaluation' ? 'selected' : '' ?>>Final Evaluation</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assessment Date</label>
                    <input type="text" class="form-control" value="<?= date('M j, Y') ?>" disabled>
                </div>
            </div>
            
            <div class="card bg-light mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0">Evaluation Criteria (Score 0-100)</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="research">Research Quality</label>
                            <input type="number" class="form-control" id="research" name="research_quality" min="0" max="100" step="0.5" placeholder="e.g., 85" value="<?= $current_assessment ? $current_assessment['research_quality'] : '' ?>">
                            <small class="text-muted">Thoroughness, literature review, problem analysis</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="methodology">Methodology</label>
                            <input type="number" class="form-control" id="methodology" name="methodology" min="0" max="100" step="0.5" placeholder="e.g., 80" value="<?= $current_assessment ? $current_assessment['methodology'] : '' ?>">
                            <small class="text-muted">Approach, techniques, feasibility</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="collaboration">Collaboration</label>
                            <input type="number" class="form-control" id="collaboration" name="collaboration" min="0" max="100" step="0.5" placeholder="e.g., 90" value="<?= $current_assessment ? $current_assessment['collaboration'] : '' ?>">
                            <small class="text-muted">Team work, communication, engagement</small>
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <label class="form-label" for="presentation">Presentation</label>
                            <input type="number" class="form-control" id="presentation" name="presentation" min="0" max="100" step="0.5" placeholder="e.g., 88" value="<?= $current_assessment ? $current_assessment['presentation'] : '' ?>">
                            <small class="text-muted">Clarity, organization, documentation</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="originality">Originality</label>
                            <input type="number" class="form-control" id="originality" name="originality" min="0" max="100" step="0.5" placeholder="e.g., 92" value="<?= $current_assessment ? $current_assessment['originality'] : '' ?>">
                            <small class="text-muted">Innovation, creativity, uniqueness</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Average Score</label>
                            <input type="text" class="form-control" id="avg_score" disabled>
                            <small class="text-muted">Auto-calculated average of criteria</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label" for="remarks">Written Remarks & Feedback *</label>
                <textarea class="form-control" id="remarks" name="remarks" rows="4" required placeholder="Provide detailed feedback on the project, strengths, areas for improvement, and overall assessment..."><?= $current_assessment ? e($current_assessment['remarks']) : '' ?></textarea>
            </div>
            
            <div class="mb-3 p-3 bg-info bg-opacity-10 border border-info rounded">
                <i class="bi bi-info-circle"></i> <strong>Note:</strong> This assessment will be marked as confirmed by you upon submission. The student will receive a notification.
            </div>
            
            <button type="submit" class="btn btn-primary">Submit Assessment Sheet</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($assessment_history)): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Assessment History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Research</th>
                            <th>Method</th>
                            <th>Collab</th>
                            <th>Present</th>
                            <th>Origin</th>
                            <th>Avg Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessment_history as $a): ?>
                            <tr>
                                <td><?= e($a['assessment_type']) ?></td>
                                <td><?= e(date('M j, Y', strtotime($a['submitted_at']))) ?></td>
                                <td><?= $a['research_quality'] !== null ? $a['research_quality'] : '—' ?></td>
                                <td><?= $a['methodology'] !== null ? $a['methodology'] : '—' ?></td>
                                <td><?= $a['collaboration'] !== null ? $a['collaboration'] : '—' ?></td>
                                <td><?= $a['presentation'] !== null ? $a['presentation'] : '—' ?></td>
                                <td><?= $a['originality'] !== null ? $a['originality'] : '—' ?></td>
                                <td><strong><?= $a['score'] !== null ? $a['score'] . '/100' : '—' ?></strong></td>
                                <td><?= $a['supervisor_confirmed'] ? '<span class="badge bg-success">Confirmed</span>' : '<span class="badge bg-warning">Draft</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function updateAverage() {
    const research = parseFloat(document.getElementById('research').value) || 0;
    const method = parseFloat(document.getElementById('methodology').value) || 0;
    const collab = parseFloat(document.getElementById('collaboration').value) || 0;
    const present = parseFloat(document.getElementById('presentation').value) || 0;
    const origin = parseFloat(document.getElementById('originality').value) || 0;
    
    const scores = [research, method, collab, present, origin].filter(v => v > 0);
    const avg = scores.length > 0 ? (scores.reduce((a, b) => a + b) / scores.length).toFixed(2) : 0;
    document.getElementById('avg_score').value = avg + '/100';
}

document.getElementById('research').addEventListener('change', updateAverage);
document.getElementById('methodology').addEventListener('change', updateAverage);
document.getElementById('collaboration').addEventListener('change', updateAverage);
document.getElementById('presentation').addEventListener('change', updateAverage);
document.getElementById('originality').addEventListener('change', updateAverage);

// Calculate on page load
document.addEventListener('DOMContentLoaded', updateAverage);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
