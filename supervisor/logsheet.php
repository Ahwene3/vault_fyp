<?php
/**
 * Supervisor Log Sheet Management - Record meeting notes and action items
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
ensure_supervisor_logsheets_table($pdo);
$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare('SELECT p.*, u.full_name AS student_name, u.email, u.reg_number FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND p.supervisor_id = ?');
$stmt->execute([$pid, $uid]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect(base_url('supervisor/students.php'));
}

$is_archived = ($project['status'] ?? '') === 'archived';

$error = '';
$success = '';

// Add new log sheet entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($is_archived) {
        flash('error', 'This project is archived. Log sheet entries cannot be modified.');
        redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_logsheet') {
        $meeting_date = $_POST['meeting_date'] ?? '';
        $attendees = trim($_POST['student_attendees'] ?? '');
        $topics = trim($_POST['topics_discussed'] ?? '');
        $actions = trim($_POST['action_points'] ?? '');
        $next_date = $_POST['next_meeting_date'] ?? null;
        $notes = trim($_POST['supervisor_notes'] ?? '');
        
        if (!$meeting_date || !$topics) {
            $error = 'Meeting date and topics discussed are required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO supervisor_logsheets (project_id, supervisor_id, meeting_date, student_attendees, topics_discussed, action_points, next_meeting_date, supervisor_notes, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$pid, $uid, $meeting_date, $attendees, $topics, $actions ?: null, $next_date ?: null, $notes ?: null]);
            flash('success', 'Log sheet entry added and confirmed.');
            redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
        }
    }
    
    if ($action === 'delete_logsheet') {
        $log_id = (int) ($_POST['log_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM supervisor_logsheets WHERE id = ? AND supervisor_id = ?');
        $stmt->execute([$log_id, $uid]);
        flash('success', 'Log sheet entry deleted.');
        redirect(base_url('supervisor/logsheet.php?pid=' . $pid));
    }
}

// Get all log sheet entries for this project
$stmt = $pdo->prepare('SELECT * FROM supervisor_logsheets WHERE project_id = ? ORDER BY meeting_date DESC');
$stmt->execute([$pid]);
$logsheets = $stmt->fetchAll();

$pageTitle = 'Supervisor Log Sheet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <h1 class="mb-1"><?= e($project['student_name']) ?> — <?= e($project['title']) ?></h1>
    <p class="text-muted"><a href="<?= base_url('supervisor/student_detail.php?pid=' . $pid) ?>">← Back to student detail</a></p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Add New Log Sheet Entry</h5>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_logsheet">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="meeting_date">Meeting Date *</label>
                    <input type="date" class="form-control" id="meeting_date" name="meeting_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="next_meeting_date">Next Meeting Date</label>
                    <input type="date" class="form-control" id="next_meeting_date" name="next_meeting_date">
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label class="form-label" for="student_attendees">Student(s) Present *</label>
                    <input type="text" class="form-control" id="student_attendees" name="student_attendees" required placeholder="e.g., John Smith, Mary Johnson" value="<?= e($project['student_name']) ?>">
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label class="form-label" for="topics_discussed">Topics Discussed *</label>
                    <textarea class="form-control" id="topics_discussed" name="topics_discussed" rows="3" required placeholder="Summarize the discussion points..."></textarea>
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label class="form-label" for="action_points">Action Points</label>
                    <textarea class="form-control" id="action_points" name="action_points" rows="2" placeholder="Tasks assigned, deadlines, deliverables..."></textarea>
                </div>
            </div>
            
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label class="form-label" for="supervisor_notes">Supervisor Notes (Optional)</label>
                    <textarea class="form-control" id="supervisor_notes" name="supervisor_notes" rows="2" placeholder="Private observations or reminders..."></textarea>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Log Entry</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Log Sheet History</h5>
    </div>
    <div class="card-body">
        <?php if (empty($logsheets)): ?>
            <p class="text-muted mb-0">No log sheet entries yet.</p>
        <?php else: ?>
            <?php foreach ($logsheets as $log): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="flex: 1;">
                            <h6 class="mb-1"><?= e(date('M j, Y', strtotime($log['meeting_date']))) ?></h6>
                            <strong>Attendees:</strong> <?= e($log['student_attendees']) ?><br>
                            <strong>Topics:</strong> <?= nl2br(e($log['topics_discussed'])) ?><br>
                            <?php if ($log['action_points']): ?>
                                <strong>Action Points:</strong> <?= nl2br(e($log['action_points'])) ?><br>
                            <?php endif; ?>
                            <?php if ($log['next_meeting_date']): ?>
                                <strong>Next Meeting:</strong> <?= e(date('M j, Y', strtotime($log['next_meeting_date']))) ?><br>
                            <?php endif; ?>
                            <?php if ($log['supervisor_notes']): ?>
                                <em class="text-muted"><strong>Notes:</strong> <?= nl2br(e($log['supervisor_notes'])) ?></em>
                            <?php endif; ?>
                            <p class="mb-0 mt-2 small text-muted">✓ Confirmed at <?= e(date('M j, Y H:i', strtotime($log['confirmed_at']))) ?></p>
                        </div>
                        <form method="post" class="ms-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_logsheet">
                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this entry?');">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
