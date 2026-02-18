<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$uid = user_id();
$role = user_role();
$pdo = getPDO();

$project_id = isset($_GET['pid']) ? (int) $_GET['pid'] : null;
$with_user = isset($_GET['with']) ? (int) $_GET['with'] : null;

// Ensure user can access this project's messages
function can_access_project_messages(PDO $pdo, int $uid, string $role, int $project_id): bool {
    $stmt = $pdo->prepare('SELECT student_id, supervisor_id FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    $p = $stmt->fetch();
    if (!$p) return false;
    if ($role === 'student') return (int) $p['student_id'] === $uid;
    if ($role === 'supervisor') return (int) $p['supervisor_id'] === $uid;
    if ($role === 'hod' || $role === 'admin') return true;
    return false;
}

// List conversations (projects for student/supervisor)
if ($role === 'student') {
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.supervisor_id, u.full_name AS other_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.student_id = ? AND p.supervisor_id IS NOT NULL ORDER BY p.updated_at DESC');
    $stmt->execute([$uid]);
    $conversations = $stmt->fetchAll();
} elseif ($role === 'supervisor') {
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.student_id, u.full_name AS other_name FROM projects p JOIN users u ON p.student_id = u.id WHERE p.supervisor_id = ? ORDER BY p.updated_at DESC');
    $stmt->execute([$uid]);
    $conversations = $stmt->fetchAll();
} else {
    $conversations = [];
}

if ($project_id && !can_access_project_messages($pdo, $uid, $role, $project_id)) {
    $project_id = null;
}

// Mark as read
if ($project_id && $with_user) {
    $pdo->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE project_id = ? AND recipient_id = ?')->execute([$project_id, $uid]);
}

// Send message
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $project_id) {
    $recipient_id = (int) ($_POST['recipient_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($body && $recipient_id && can_access_project_messages($pdo, $uid, $role, $project_id)) {
        $stmt = $pdo->prepare('SELECT student_id, supervisor_id FROM projects WHERE id = ?');
        $stmt->execute([$project_id]);
        $p = $stmt->fetch();
        $valid_recipient = ($p['student_id'] == $recipient_id || $p['supervisor_id'] == $recipient_id) && $recipient_id != $uid;
        if ($valid_recipient) {
            $stmt = $pdo->prepare('INSERT INTO messages (project_id, sender_id, recipient_id, subject, body) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$project_id, $uid, $recipient_id, null, $body]);
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$recipient_id, 'message', 'New message', 'You have a new message.', base_url('messages.php?pid=' . $project_id . '&with=' . $uid)]);
            $sent = true;
            redirect(base_url('messages.php?pid=' . $project_id . '&with=' . $recipient_id));
        }
    }
}

$messages = [];
$other_user = null;
if ($project_id) {
    $stmt = $pdo->prepare('SELECT student_id, supervisor_id FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    $proj = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.project_id = ? ORDER BY m.created_at ASC');
    $stmt->execute([$project_id]);
    $messages = $stmt->fetchAll();
    $other_id = ($role === 'student') ? (int) $proj['supervisor_id'] : (int) $proj['student_id'];
    if ($other_id) {
        $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = ?');
        $stmt->execute([$other_id]);
        $other_user = $stmt->fetch();
    }
}

$pageTitle = 'Messages';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="mb-4">Messages</h1>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Conversations</div>
            <div class="list-group list-group-flush">
                <?php if (empty($conversations)): ?>
                    <div class="list-group-item text-muted">No conversations yet.</div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <a href="<?= base_url('messages.php?pid=' . $c['id'] . '&with=' . ($role === 'student' ? $c['supervisor_id'] : $c['student_id'])) ?>" class="list-group-item list-group-item-action <?= $project_id == $c['id'] ? 'active' : '' ?>">
                            <strong><?= e($c['title']) ?></strong><br>
                            <small><?= e($c['other_name'] ?? '—') ?></small>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <?php if ($project_id && $other_user): ?>
            <div class="card">
                <div class="card-header"><?= e($other_user['full_name']) ?></div>
                <div class="card-body overflow-auto" style="max-height: 400px;">
                    <?php foreach ($messages as $m): ?>
                        <div class="mb-3 <?= $m['sender_id'] == $uid ? 'text-end' : '' ?>">
                            <div class="d-inline-block text-start p-2 rounded <?= $m['sender_id'] == $uid ? 'bg-primary text-white' : 'bg-light' ?>" style="max-width: 85%;">
                                <?= nl2br(e($m['body'])) ?>
                                <br><small class="opacity-75"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="recipient_id" value="<?= (int) $other_user['id'] ?>">
                        <div class="input-group">
                            <textarea name="body" class="form-control" rows="2" placeholder="Type a message..." required></textarea>
                            <button type="submit" class="btn btn-primary">Send</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">Select a conversation or wait for a supervisor to be assigned.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
