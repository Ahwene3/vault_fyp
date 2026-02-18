<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();

$stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND status IN ("approved", "in_progress", "completed") ORDER BY updated_at DESC LIMIT 1');
$stmt->execute([$uid]);
$project = $stmt->fetch();
$project_id = $project ? (int) $project['id'] : null;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_entry' && $project_id) {
        $entry_date = $_POST['entry_date'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (!$entry_date || !$title || !$content) {
            $error = 'Please fill date, title, and content.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO logbook_entries (project_id, entry_date, title, content, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$project_id, $entry_date, $title, $content, $uid]);
            if ($project_supervisor = get_project_supervisor($pdo, $project_id)) {
                require_once __DIR__ . '/../includes/notify.php';
                notify_user($project_supervisor, 'logbook_entry', 'New logbook entry', 'A student added a logbook entry.', base_url('supervisor/logbook.php?pid=' . $project_id));
            }
            flash('success', 'Logbook entry added.');
            redirect(base_url('student/logbook.php'));
        }
    }
}

function get_project_supervisor(PDO $pdo, int $project_id): ?int {
    $stmt = $pdo->prepare('SELECT supervisor_id FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    $r = $stmt->fetch();
    return $r && $r['supervisor_id'] ? (int) $r['supervisor_id'] : null;
}

$entries = [];
if ($project_id) {
    $stmt = $pdo->prepare('SELECT id, entry_date, title, content, supervisor_approved, supervisor_comment, approved_at, created_at FROM logbook_entries WHERE project_id = ? ORDER BY entry_date DESC, created_at DESC');
    $stmt->execute([$project_id]);
    $entries = $stmt->fetchAll();
}

$pageTitle = 'Logbook';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Logbook</h1>

<?php if (!$project_id): ?>
    <div class="alert alert-info">You need an approved project to maintain a logbook. Submit and get your topic approved first.</div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-header">Add Entry</div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_entry">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Content</label>
                        <textarea name="content" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Add Entry</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Entries</div>
        <div class="card-body">
            <?php if (empty($entries)): ?>
                <p class="text-muted mb-0">No logbook entries yet.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($entries as $e): ?>
                        <div class="list-group-item logbook-entry <?= $e['supervisor_approved'] === null ? 'pending' : ($e['supervisor_approved'] ? 'approved' : 'flagged') ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= e($e['title']) ?></strong>
                                    <span class="text-muted ms-2"><?= e($e['entry_date']) ?></span>
                                </div>
                                <span class="badge bg-<?= $e['supervisor_approved'] === null ? 'warning' : ($e['supervisor_approved'] ? 'success' : 'danger') ?>">
                                    <?= $e['supervisor_approved'] === null ? 'Pending' : ($e['supervisor_approved'] ? 'Approved' : 'Flagged') ?>
                                </span>
                            </div>
                            <p class="mb-1 mt-1"><?= nl2br(e($e['content'])) ?></p>
                            <?php if ($e['supervisor_comment']): ?><p class="small text-muted mb-0"><strong>Supervisor:</strong> <?= e($e['supervisor_comment']) ?></p><?php endif; ?>
                            <?php if ($e['approved_at']): ?><p class="small text-muted mb-0">Signed off: <?= e(date('M j, Y', strtotime($e['approved_at']))) ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
