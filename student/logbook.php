<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();

function resolve_group_project_id_for_logbook(PDO $pdo, int $group_id): ?int {
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? AND status IN ("approved", "in_progress", "completed") ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$group_id]);
    $project_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($project_id > 0) {
        return $project_id;
    }

    // Backfill legacy data where creator's project existed before group linkage.
    $stmt = $pdo->prepare('SELECT created_by FROM `groups` WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$group_id]);
    $creator_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND status IN ("approved", "in_progress", "completed") AND (group_id IS NULL OR group_id = ?) ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$creator_id, $group_id]);
    $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_project_id <= 0) {
        return null;
    }

    $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([$group_id, $creator_project_id, $group_id]);
    return $creator_project_id;
}

// Resolve group membership first so group members share a single project logbook
$stmt = $pdo->prepare('SELECT gm.group_id FROM `group_members` gm JOIN `groups` g ON g.id = gm.group_id WHERE gm.student_id = ? AND g.is_active = 1 LIMIT 1');
$stmt->execute([$uid]);
$group_id = (int) ($stmt->fetchColumn() ?: 0);

$project = null;
if ($group_id > 0) {
    $resolved_project_id = resolve_group_project_id_for_logbook($pdo, $group_id);
    if ($resolved_project_id) {
        $project = ['id' => $resolved_project_id];
    }
}
if (!$project && $group_id === 0) {
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND status IN ("approved", "in_progress", "completed") ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$uid]);
    $project = $stmt->fetch();
}
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
    $stmt = $pdo->prepare('SELECT le.id, le.entry_date, le.title, le.content, le.supervisor_approved, le.supervisor_comment, le.approved_at, le.created_at, le.created_by, sup.full_name AS supervisor_name FROM logbook_entries le LEFT JOIN projects p ON p.id = le.project_id LEFT JOIN users sup ON sup.id = p.supervisor_id WHERE le.project_id = ? ORDER BY le.entry_date DESC, le.created_at DESC');
    $stmt->execute([$project_id]);
    $entries = $stmt->fetchAll();
}

$total_entries = count($entries);
$approved_entries = 0;
$pending_entries = 0;
foreach ($entries as $entry) {
    if ($entry['supervisor_approved'] === null) {
        $pending_entries++;
    } elseif ($entry['supervisor_approved']) {
        $approved_entries++;
    }
}

$pageTitle = 'Logbook';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">My Logbook</h1>
        <p class="dashboard-hero__copy mb-0">Record progress, notes, and supervisor feedback in one place.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="#new-entry" class="btn dashboard-hero__btn">New Entry</a>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-journal-text"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Total Entries</h6>
                    <div class="student-stat-value"><?= (int) $total_entries ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-primary me-3"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Approved</h6>
                    <div class="student-stat-value"><?= (int) $approved_entries ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-warning me-3"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Pending Review</h6>
                    <div class="student-stat-value"><?= (int) $pending_entries ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($group_id > 0): ?>
    <div class="alert alert-info">This logbook is shared with your group members. Everyone in your group can add entries and view supervisor feedback.</div>
<?php endif; ?>

<?php if (!$project_id): ?>
    <div class="alert alert-info">You need an approved project to maintain a logbook. Submit and get your topic approved first.</div>
<?php else: ?>
    <div class="card mb-4" id="new-entry">
        <div class="card-header">Add New Entry</div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_entry">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Entry Title</label>
                        <input type="text" name="title" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Activity Summary</label>
                        <textarea name="content" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Submit Entry</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">All Logbook Entries</div>
        <div class="card-body p-0">
            <?php if (empty($entries)): ?>
                <p class="text-muted px-3 py-4 mb-0">No logbook entries yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Entry Title</th>
                                <th>Activity Summary</th>
                                <th>Supervisor</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $i => $e): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="fw-semibold"><?= e($e['title']) ?></td>
                                    <td><?= e(mb_substr((string) $e['content'], 0, 110)) ?></td>
                                    <td><?= e($e['supervisor_name'] ?: '—') ?></td>
                                    <td>
                                        <?php $status = $e['supervisor_approved'] === null ? 'Pending' : ($e['supervisor_approved'] ? 'Approved' : 'Flagged'); ?>
                                        <span class="badge <?= $e['supervisor_approved'] === null ? 'bg-warning text-dark' : ($e['supervisor_approved'] ? 'bg-success' : 'bg-danger') ?>"><?= e($status) ?></span>
                                    </td>
                                    <td><?= e(date('M j, Y', strtotime((string) $e['entry_date']))) ?></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-primary" disabled>View</button></td>
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
