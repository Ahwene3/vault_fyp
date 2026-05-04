<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$uid = user_id();
$role = user_role();
$pdo = getPDO();

$project_id = isset($_GET['pid']) ? (int) $_GET['pid'] : null;
$with_user = isset($_GET['with']) ? (int) $_GET['with'] : null;

function ensure_group_project_link(PDO $pdo, int $group_id): void {
    if ($group_id <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? LIMIT 1');
    $stmt->execute([$group_id]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare('SELECT created_by FROM `groups` WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$group_id]);
    $creator_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_id <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND (group_id IS NULL OR group_id = ?) ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$creator_id, $group_id]);
    $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_project_id <= 0) {
        return;
    }

    $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([$group_id, $creator_project_id, $group_id]);
}

function fetch_project_context(PDO $pdo, int $project_id): ?array {
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.student_id, p.supervisor_id, p.group_id, g.name AS group_name, su.full_name AS student_name, sp.full_name AS supervisor_name
        FROM projects p
        LEFT JOIN `groups` g ON g.id = p.group_id
        LEFT JOIN users su ON su.id = p.student_id
        LEFT JOIN users sp ON sp.id = p.supervisor_id
        WHERE p.id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) {
        return null;
    }

    if (empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT id, name FROM `groups` WHERE created_by = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $project['student_id']]);
        $fallback_group = $stmt->fetch();
        if ($fallback_group) {
            $fallback_group_id = (int) $fallback_group['id'];
            $check = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? LIMIT 1');
            $check->execute([$fallback_group_id]);
            $existing_group_project = (int) ($check->fetchColumn() ?: 0);
            if ($existing_group_project === 0 || $existing_group_project === (int) $project['id']) {
                $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND group_id IS NULL')->execute([$fallback_group_id, (int) $project['id']]);
                $project['group_id'] = $fallback_group_id;
                $project['group_name'] = $fallback_group['name'];
            }
        }
    }

    return $project;
}

function get_project_student_participants(PDO $pdo, array $project): array {
    $member_ids = [(int) $project['student_id']];

    if (!empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $project['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $member_ids[] = (int) $m['student_id'];
        }
    }

    $member_ids = array_values(array_unique(array_filter($member_ids, static function ($id) {
        return (int) $id > 0;
    })));

    return $member_ids;
}

function can_access_project_messages(PDO $pdo, int $uid, string $role, int $project_id): bool {
    $project = fetch_project_context($pdo, $project_id);
    if (!$project) {
        return false;
    }

    if ($role === 'student') {
        $participants = get_project_student_participants($pdo, $project);
        return in_array($uid, $participants, true);
    }

    if ($role === 'supervisor') {
        return (int) ($project['supervisor_id'] ?? 0) === $uid;
    }

    if ($role === 'hod' || $role === 'admin') {
        return true;
    }

    return false;
}

// For students, proactively link group project if they were invited after creator submission.
if ($role === 'student') {
    $stmt = $pdo->prepare('SELECT gm.group_id FROM `group_members` gm JOIN `groups` g ON g.id = gm.group_id AND g.is_active = 1 WHERE gm.student_id = ?');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $row) {
        ensure_group_project_link($pdo, (int) $row['group_id']);
    }
}

// List conversations
if ($role === 'student') {
    $stmt = $pdo->prepare('SELECT DISTINCT p.id, p.title, p.supervisor_id, u.full_name AS other_name, g.name AS group_name, p.updated_at
        FROM projects p
        LEFT JOIN users u ON p.supervisor_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        LEFT JOIN `group_members` gm ON gm.group_id = p.group_id
        WHERE p.supervisor_id IS NOT NULL AND (p.student_id = ? OR gm.student_id = ?)
        ORDER BY p.updated_at DESC');
    $stmt->execute([$uid, $uid]);
    $conversations = $stmt->fetchAll();
} elseif ($role === 'supervisor') {
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.student_id, u.full_name AS other_name, g.name AS group_name,
        (SELECT GROUP_CONCAT(u2.full_name ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_names,
        p.updated_at
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.supervisor_id = ?
        ORDER BY p.updated_at DESC');
    $stmt->execute([$uid]);
    $conversations = $stmt->fetchAll();
} else {
    $conversations = [];
}

$unread_message_count = 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
$stmt->execute([$uid]);
$unread_message_count = (int) $stmt->fetchColumn();
$active_contacts = count($conversations);

if ($project_id && !can_access_project_messages($pdo, $uid, $role, $project_id)) {
    $project_id = null;
}

$project_context = null;
$student_participants = [];
$participant_names = [];

if ($project_id) {
    $project_context = fetch_project_context($pdo, $project_id);
    if (!$project_context || !can_access_project_messages($pdo, $uid, $role, $project_id)) {
        $project_context = null;
        $project_id = null;
    }
}

if ($project_context) {
    $student_participants = get_project_student_participants($pdo, $project_context);

    if (!empty($project_context['group_id'])) {
        $stmt = $pdo->prepare('SELECT u.full_name FROM `group_members` gm JOIN users u ON u.id = gm.student_id WHERE gm.group_id = ? ORDER BY CASE WHEN gm.role = "lead" THEN 0 ELSE 1 END, u.full_name');
        $stmt->execute([(int) $project_context['group_id']]);
        foreach ($stmt->fetchAll() as $row) {
            $participant_names[] = $row['full_name'];
        }
    } elseif (!empty($project_context['student_name'])) {
        $participant_names[] = $project_context['student_name'];
    }
}

// Mark as read for current user on this project
if ($project_context) {
    $pdo->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE project_id = ? AND recipient_id = ? AND is_read = 0')->execute([$project_id, $uid]);
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $project_context) {
    $body = trim($_POST['body'] ?? '');

    if ($body !== '') {
        $recipient_ids = [];

        if ($role === 'student') {
            $recipient_ids = $student_participants;
            if (!empty($project_context['supervisor_id'])) {
                $recipient_ids[] = (int) $project_context['supervisor_id'];
            }
        } elseif ($role === 'supervisor') {
            $recipient_ids = $student_participants;
        } elseif ($role === 'hod' || $role === 'admin') {
            $single_recipient = (int) ($_POST['recipient_id'] ?? 0);
            if ($single_recipient > 0) {
                $recipient_ids[] = $single_recipient;
            }
        }

        $recipient_ids = array_values(array_unique(array_filter($recipient_ids, static function ($rid) use ($uid) {
            return (int) $rid > 0 && (int) $rid !== (int) $uid;
        })));

        if (!empty($recipient_ids)) {
            $pdo->beginTransaction();
            try {
                $insert_message = $pdo->prepare('INSERT INTO messages (project_id, sender_id, recipient_id, subject, body) VALUES (?, ?, ?, ?, ?)');
                $insert_notification = $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)');

                foreach ($recipient_ids as $recipient_id) {
                    $insert_message->execute([$project_id, $uid, $recipient_id, null, $body]);
                    $insert_notification->execute([
                        $recipient_id,
                        'message',
                        'New message',
                        'You have a new group/project message.',
                        base_url('messages.php?pid=' . $project_id . '&with=' . $uid)
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Unable to send message right now. Please try again.');
                redirect(base_url('messages.php?pid=' . $project_id));
            }

            $with_hint = 0;
            if ($role === 'student') {
                $with_hint = (int) ($project_context['supervisor_id'] ?? 0);
            } elseif (!empty($student_participants)) {
                $with_hint = (int) $student_participants[0];
            }

            redirect(base_url('messages.php?pid=' . $project_id . ($with_hint > 0 ? '&with=' . $with_hint : '')));
        }
    }
}

$messages = [];
if ($project_context) {
    // Collapse duplicated rows produced by fan-out delivery so one sent text appears once in the thread.
    $stmt = $pdo->prepare('SELECT MIN(m.id) AS id, m.sender_id, u.full_name AS sender_name, m.body, m.created_at
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.project_id = ?
        GROUP BY m.sender_id, m.body, m.created_at, u.full_name
        ORDER BY m.created_at ASC, MIN(m.id) ASC');
    $stmt->execute([$project_id]);
    $messages = $stmt->fetchAll();
}

$chat_header = '';
$chat_subheader = '';
if ($project_context) {
    if ($role === 'student') {
        $chat_header = !empty($project_context['supervisor_name']) ? ('Supervisor: ' . $project_context['supervisor_name']) : 'Supervisor not assigned yet';
        $chat_subheader = !empty($project_context['group_name']) ? ('Group chat - ' . $project_context['group_name']) : ('Project: ' . $project_context['title']);
    } else {
        if (!empty($project_context['group_name'])) {
            $chat_header = 'Group: ' . $project_context['group_name'];
            $chat_subheader = !empty($participant_names) ? ('Members: ' . implode(', ', $participant_names)) : ('Project: ' . $project_context['title']);
        } else {
            $chat_header = 'Student: ' . ($project_context['student_name'] ?? 'Unknown');
            $chat_subheader = 'Project: ' . $project_context['title'];
        }
    }
}

$pageTitle = 'Messages';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">Messages</h1>
        <p class="dashboard-hero__copy mb-0">Keep your supervisor and group members in sync from one inbox.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="#new-message" class="btn dashboard-hero__btn">New Message</a>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-danger me-3"><i class="bi bi-envelope-fill"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Unread Messages</h6>
                    <div class="student-stat-value"><?= (int) $unread_message_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-person-lines-fill"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Active Contacts</h6>
                    <div class="student-stat-value"><?= (int) $active_contacts ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">Conversations</div>
            <div class="list-group list-group-flush">
                <?php if (empty($conversations)): ?>
                    <div class="list-group-item text-muted">No conversations yet.</div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <?php
                            $target_id = ($role === 'student') ? (int) ($c['supervisor_id'] ?? 0) : (int) ($c['student_id'] ?? 0);
                            $subtitle = !empty($c['group_name']) ? ('Group: ' . $c['group_name']) : ($c['other_name'] ?? '—');
                            if ($role === 'supervisor' && !empty($c['group_name']) && !empty($c['member_names'])) {
                                $subtitle = 'Group members: ' . $c['member_names'];
                            }
                            $thread_unread = 0;
                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE project_id = ? AND recipient_id = ? AND is_read = 0');
                            $stmt->execute([(int) $c['id'], $uid]);
                            $thread_unread = (int) $stmt->fetchColumn();
                        ?>
                        <a href="<?= base_url('messages.php?pid=' . $c['id'] . '&with=' . $target_id) ?>" class="list-group-item list-group-item-action <?= $project_id == $c['id'] ? 'active' : '' ?> student-inbox-row <?= $thread_unread > 0 ? 'is-unread' : '' ?>">
                            <span class="student-inbox-row__avatar"><?= e(strtoupper(substr((string) ($c['other_name'] ?? $c['title']), 0, 1))) ?></span>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div>
                                        <strong><?= e($c['title']) ?></strong>
                                        <?php if ($thread_unread > 0): ?><span class="badge bg-danger ms-2"><?= (int) $thread_unread ?></span><?php endif; ?>
                                        <br>
                                        <small><?= e($subtitle) ?></small>
                                    </div>
                                    <small class="text-muted text-nowrap"><?= e(date('M j', strtotime((string) $c['updated_at']))) ?></small>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <?php if ($project_context): ?>
            <div class="card h-100">
                <div class="card-header">
                    <div class="fw-semibold"><?= e($chat_header) ?></div>
                    <?php if ($chat_subheader): ?><small class="text-muted"><?= e($chat_subheader) ?></small><?php endif; ?>
                </div>
                <div class="card-body overflow-auto" style="max-height: 420px;">
                    <?php if (empty($messages)): ?>
                        <p class="text-muted mb-0">No messages yet. Start the conversation below.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="mb-3 <?= $m['sender_id'] == $uid ? 'text-end' : '' ?>">
                                <div class="d-inline-block text-start p-3 rounded <?= $m['sender_id'] == $uid ? 'bg-success text-white' : 'bg-dark text-light' ?>" style="max-width: 85%;">
                                    <?php if ((int) $m['sender_id'] !== (int) $uid): ?>
                                        <div class="small fw-semibold mb-1"><?= e($m['sender_name']) ?></div>
                                    <?php endif; ?>
                                    <div><?= nl2br(e($m['body'])) ?></div>
                                    <br><small class="opacity-75"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer" id="new-message">
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="input-group">
                            <textarea name="body" class="form-control" rows="2" placeholder="Type a message..." required></textarea>
                            <button type="submit" class="btn btn-primary">Send</button>
                        </div>
                        <?php if ($role === 'student'): ?>
                            <small class="text-muted d-block mt-2">
                                <?= !empty($project_context['group_id'])
                                    ? 'Your message is shared with your supervisor and all group members.'
                                    : 'Your message is shared with your supervisor.' ?>
                            </small>
                        <?php elseif ($role === 'supervisor'): ?>
                            <small class="text-muted d-block mt-2">
                                <?= !empty($project_context['group_id'])
                                    ? 'Your message is shared with all students in this group project.'
                                    : 'Your message is shared with this student.' ?>
                            </small>
                        <?php endif; ?>
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
