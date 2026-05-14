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
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.status, p.student_id, p.supervisor_id, p.group_id, g.name AS group_name, su.full_name AS student_name, sp.full_name AS supervisor_name
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

    if (($project['status'] ?? '') === 'archived') {
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

// List conversations (archived projects excluded)
if ($role === 'student') {
    $stmt = $pdo->prepare('SELECT DISTINCT p.id, p.title, p.supervisor_id, u.full_name AS other_name, g.name AS group_name, p.updated_at
        FROM projects p
        LEFT JOIN users u ON p.supervisor_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        LEFT JOIN `group_members` gm ON gm.group_id = p.group_id
        WHERE p.supervisor_id IS NOT NULL AND p.status != "archived" AND (p.student_id = ? OR gm.student_id = ?)
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
        WHERE p.supervisor_id = ? AND p.status != "archived"
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

ensure_messages_deleted_columns($pdo);

/* ── Delete message (AJAX, JSON response) ────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'error'=>'Security check failed']); exit; }
    $del_id  = (int)($_POST['message_id'] ?? 0);
    $del_pid = (int)($_POST['project_id'] ?? 0);
    if (!$del_id || !$del_pid) { echo json_encode(['ok'=>false,'error'=>'Invalid params']); exit; }
    /* fetch the row so we can identify all fan-out copies */
    $mq = $pdo->prepare('SELECT sender_id, body, message_type, audio_path, created_at FROM messages WHERE id=? AND project_id=? LIMIT 1');
    $mq->execute([$del_id, $del_pid]);
    $dmsg = $mq->fetch();
    if (!$dmsg || (int)$dmsg['sender_id'] !== $uid) {
        echo json_encode(['ok'=>false,'error'=>'Message not found or not yours']); exit;
    }
    /* mark every fan-out row for this logical message as deleted */
    if ($dmsg['message_type'] === 'voice' && $dmsg['audio_path']) {
        $pdo->prepare('UPDATE messages SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE project_id=? AND sender_id=? AND audio_path=?')
            ->execute([$uid, $del_pid, $uid, $dmsg['audio_path']]);
    } else {
        $pdo->prepare('UPDATE messages SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE project_id=? AND sender_id=? AND body=? AND created_at=?')
            ->execute([$uid, $del_pid, $uid, $dmsg['body'], $dmsg['created_at']]);
    }
    echo json_encode(['ok'=>true, 'deleted_id'=>$del_id]);
    exit;
}

$project_is_archived = false;
if ($project_id) {
    $pre_check = fetch_project_context($pdo, $project_id);
    if ($pre_check && ($pre_check['status'] ?? '') === 'archived') {
        $project_is_archived = true;
    }
}

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

// Send text message
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

$messages    = [];
$max_msg_id  = 0;
if ($project_context) {
    $stmt = $pdo->prepare(
        'SELECT MIN(m.id) AS id, m.sender_id, u.full_name AS sender_name,
                m.body, m.message_type, m.audio_path, m.audio_duration, m.created_at
         FROM messages m
         JOIN users u ON m.sender_id = u.id
         WHERE m.project_id = ? AND m.is_deleted = 0
         GROUP BY m.sender_id, m.body, m.message_type, m.audio_path, m.audio_duration, m.created_at, u.full_name
         ORDER BY m.created_at ASC, MIN(m.id) ASC'
    );
    $stmt->execute([$project_id]);
    $messages = $stmt->fetchAll();
    /* true MAX id across ALL rows (including fan-out) so the poll baseline is correct */
    $maxq = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM messages WHERE project_id=?');
    $maxq->execute([$project_id]);
    $max_msg_id = (int)$maxq->fetchColumn();
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
            <div class="card h-100 vn-chat-card">
                <div class="card-header vn-chat-header">
                    <div class="fw-semibold"><?= e($chat_header) ?></div>
                    <?php if ($chat_subheader): ?><small class="text-muted"><?= e($chat_subheader) ?></small><?php endif; ?>
                </div>

                <!-- Message thread -->
                <div class="card-body overflow-auto vn-thread" id="vn-thread">
                    <?php if (empty($messages)): ?>
                        <p class="text-muted mb-0">No messages yet. Start the conversation below.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $m):
                            $mine  = (int)$m['sender_id'] === (int)$uid;
                            $mtype = $m['message_type'] ?? 'text';
                        ?>
                        <div class="mb-3 vn-msg-row <?= $mine ? 'vn-mine' : 'vn-theirs' ?>" data-id="<?= (int)$m['id'] ?>">
                            <?php if ($mtype === 'voice' && !empty($m['audio_path'])): ?>
                                <?php
                                    $audio_url = base_url($m['audio_path']);
                                    $dur       = (int)($m['audio_duration'] ?? 0);
                                    $dur_fmt   = sprintf('%d:%02d', intdiv($dur, 60), $dur % 60);
                                ?>
                                <div class="vn-bubble vn-voice-bubble">
                                    <?php if ($mine): ?><div class="vn-opts" data-msg-id="<?= (int)$m['id'] ?>"><button class="vn-opts-arrow" onclick="vnOpenMenu(this,event)"><i class="bi bi-chevron-down"></i></button></div><?php endif; ?>
                                    <?php if (!$mine): ?>
                                        <div class="vn-sender"><?= e($m['sender_name']) ?></div>
                                    <?php endif; ?>
                                    <div class="vn-voice-player" data-src="<?= e($audio_url) ?>" data-dur="<?= $dur ?>">
                                        <button class="vn-play-btn" aria-label="Play">
                                            <i class="bi bi-play-fill"></i>
                                        </button>
                                        <div class="vn-waveform">
                                            <?php for($b=0;$b<28;$b++): ?>
                                                <span class="vn-bar" style="height:<?= rand(30,100) ?>%"></span>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="vn-right-col">
                                            <input type="range" class="vn-seek" min="0" max="<?= $dur ?: 100 ?>" value="0" step="0.1">
                                            <div class="vn-meta">
                                                <span class="vn-time-current">0:00</span>
                                                <span class="vn-dur-label"><?= $dur_fmt ?></span>
                                                <div class="vn-speed-wrap">
                                                    <button class="vn-speed-btn">1×</button>
                                                </div>
                                                <a href="<?= e($audio_url) ?>" download class="vn-dl-btn" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="vn-ts"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></div>
                                </div>
                            <?php else: ?>
                                <div class="vn-bubble vn-text-bubble">
                                    <?php if ($mine): ?><div class="vn-opts" data-msg-id="<?= (int)$m['id'] ?>"><button class="vn-opts-arrow" onclick="vnOpenMenu(this,event)"><i class="bi bi-chevron-down"></i></button></div><?php endif; ?>
                                    <?php if (!$mine): ?>
                                        <div class="vn-sender"><?= e($m['sender_name']) ?></div>
                                    <?php endif; ?>
                                    <div class="vn-body"><?= nl2br(e($m['body'])) ?></div>
                                    <div class="vn-ts"><?= e(date('M j, H:i', strtotime($m['created_at']))) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Composer -->
                <div class="card-footer vn-composer" id="new-message">

                    <!-- Recording UI (hidden by default) -->
                    <div class="vn-rec-bar d-none" id="vn-rec-bar">
                        <div class="vn-rec-pulse"></div>
                        <span class="vn-rec-label">Recording</span>
                        <span class="vn-rec-timer" id="vn-rec-timer">0:00</span>
                        <div class="vn-rec-waves" id="vn-rec-waves">
                            <?php for($i=0;$i<20;$i++): ?>
                                <span class="vn-rbar"></span>
                            <?php endfor; ?>
                        </div>
                        <button class="vn-cancel-btn" id="vn-cancel-btn" type="button" title="Cancel recording">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <button class="vn-stop-btn" id="vn-stop-btn" type="button" title="Stop &amp; preview">
                            <i class="bi bi-stop-fill"></i>
                        </button>
                    </div>

                    <!-- Preview UI (hidden by default) -->
                    <div class="vn-preview-bar d-none" id="vn-preview-bar">
                        <div class="vn-preview-inner">
                            <div class="vn-preview-top">
                                <i class="bi bi-mic-fill" style="color:#4ade80;font-size:.9rem;"></i>
                                <span class="vn-preview-label">Preview your recording</span>
                                <span class="vn-preview-dur" id="vn-preview-dur"></span>
                            </div>
                            <audio id="vn-preview-audio" controls style="width:100%;height:32px;margin:.4rem 0 .5rem;accent-color:#3b82f6;"></audio>
                            <div class="vn-preview-actions">
                                <button class="btn btn-sm btn-outline-danger" id="vn-discard-btn" type="button"><i class="bi bi-trash me-1"></i>Discard</button>
                                <button class="btn btn-sm btn-success" id="vn-send-voice-btn" type="button"><i class="bi bi-send me-1"></i>Send</button>
                            </div>
                        </div>
                    </div>

                    <!-- Normal composer -->
                    <form method="post" id="vn-text-form" class="vn-text-form">
                        <?= csrf_field() ?>
                        <div class="vn-input-row">
                            <textarea name="body" class="form-control vn-textarea" rows="1"
                                      placeholder="Type a message…" id="vn-textarea"></textarea>
                            <button type="button" class="vn-mic-btn" id="vn-mic-btn" title="Record voice note">
                                <i class="bi bi-mic-fill"></i>
                            </button>
                            <button type="submit" class="vn-send-text-btn" title="Send">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </div>
                        <?php if ($role === 'student'): ?>
                            <small class="text-muted d-block mt-1" style="font-size:.74em;">
                                <?= !empty($project_context['group_id'])
                                    ? 'Shared with supervisor and all group members.'
                                    : 'Shared with your supervisor.' ?>
                            </small>
                        <?php elseif ($role === 'supervisor'): ?>
                            <small class="text-muted d-block mt-1" style="font-size:.74em;">
                                <?= !empty($project_context['group_id'])
                                    ? 'Shared with all students in this group.'
                                    : 'Shared with this student.' ?>
                            </small>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        <?php elseif ($project_is_archived): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-archive-fill mb-3 d-block" style="font-size:2.5rem;color:var(--hod-accent,#4f46e5);"></i>
                    <h5 class="mb-2">Project Archived</h5>
                    <p class="text-muted mb-0">Messaging is no longer available for archived projects.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">Select a conversation or wait for a supervisor to be assigned.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════ STYLES ════════════════════════════ -->
<style>
/* ── Chat card ── */
.vn-chat-card { display:flex; flex-direction:column; }
.vn-thread    { flex:1; max-height:440px; overflow-y:auto; padding:1rem; scroll-behavior:smooth; }

/* ── Message rows ── */
.vn-msg-row        { display:flex; }
.vn-mine           { justify-content:flex-end; }
.vn-theirs         { justify-content:flex-start; }

/* ── Bubbles ── */
.vn-bubble {
    max-width:82%;
    border-radius:18px;
    padding:.65rem 1rem;
    position:relative;
    backdrop-filter:blur(8px);
}
.vn-mine .vn-bubble {
    background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);
    border-bottom-right-radius:4px;
    box-shadow:0 0 12px rgba(13,110,253,.35);
    color:#fff;
}
.vn-theirs .vn-bubble {
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.1);
    border-bottom-left-radius:4px;
    color:#e2e8f0;
}
.vn-voice-bubble { min-width:260px; }
.vn-sender { font-size:.75rem; font-weight:600; opacity:.8; margin-bottom:.3rem; color:#38bdf8; }
.vn-body   { font-size:.92rem; line-height:1.5; word-break:break-word; }
.vn-ts     { font-size:.68rem; opacity:.6; margin-top:.35rem; text-align:right; }

/* ── WhatsApp-style message options ── */
.vn-bubble { position:relative; overflow:visible; }

.vn-opts {
    position:absolute; top:.3rem; right:.35rem;
    z-index:20;
}

.vn-opts-arrow {
    opacity:0; transition:opacity .15s;
    width:1.6rem; height:1.6rem; border-radius:50%; border:none;
    background:rgba(0,0,0,.55); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:.7rem; cursor:pointer; backdrop-filter:blur(4px);
    transition:opacity .15s, background .15s;
}
.vn-bubble:hover .vn-opts-arrow,
.vn-opts.is-open .vn-opts-arrow { opacity:1; }
.vn-opts-arrow:hover { background:rgba(0,0,0,.8); }

/* .vn-opts-menu lives in a body-level portal — see #vn-portal-menu CSS */
#vn-portal-menu {
    position:fixed;
    background:#1e293b; border:1px solid rgba(255,255,255,.15);
    border-radius:.6rem; min-width:180px; box-shadow:0 8px 32px rgba(0,0,0,.55);
    overflow:hidden; z-index:99999;
}

.vn-opts-delete {
    width:100%; background:none; border:none; text-align:left;
    padding:.6rem .9rem; font-size:.82rem; color:#f87171;
    cursor:pointer; display:flex; align-items:center; gap:.5rem;
    transition:background .12s;
}
.vn-opts-delete:hover { background:rgba(239,68,68,.12); }

/* deleted placeholder */
.vn-deleted-bubble {
    font-size:.82rem; color:rgba(255,255,255,.3); font-style:italic;
    padding:.45rem .75rem; border:1px dashed rgba(255,255,255,.12);
    border-radius:10px; display:flex; align-items:center; gap:.4rem;
}

/* ── Voice player ── */
.vn-voice-player {
    display:flex; align-items:center; gap:.5rem;
}
.vn-play-btn {
    width:38px; height:38px; flex-shrink:0;
    border-radius:50%; border:none; cursor:pointer;
    background:rgba(56,189,248,.2);
    color:#38bdf8;
    display:flex; align-items:center; justify-content:center;
    font-size:1rem;
    transition:all .2s;
}
.vn-play-btn:hover { background:rgba(56,189,248,.4); transform:scale(1.1); }
.vn-play-btn.playing { background:rgba(52,211,153,.2); color:#34d399; }

/* ── Waveform ── */
.vn-waveform {
    display:flex; align-items:center; gap:2px;
    height:30px; flex-shrink:0;
}
.vn-bar {
    width:3px; border-radius:2px; flex-shrink:0;
    background:rgba(56,189,248,.4);
    transition:height .1s, background .2s;
}
.playing .vn-bar { background:#38bdf8; animation:vn-wave-tick .6s ease-in-out infinite alternate; }
.playing .vn-bar:nth-child(odd)  { animation-delay:.1s; }
.playing .vn-bar:nth-child(3n)   { animation-delay:.2s; }
.playing .vn-bar:nth-child(4n)   { animation-delay:.05s; }
@keyframes vn-wave-tick {
    from { transform:scaleY(1); }
    to   { transform:scaleY(1.6); }
}

/* ── Seek + meta ── */
.vn-right-col { flex:1; min-width:0; }
.vn-seek {
    width:100%; height:4px; cursor:pointer;
    accent-color:#38bdf8;
    background:rgba(255,255,255,.15);
    border-radius:2px;
}
.vn-meta {
    display:flex; align-items:center; gap:.4rem;
    margin-top:.2rem; font-size:.7rem;
}
.vn-time-current { color:#38bdf8; font-variant-numeric:tabular-nums; min-width:26px; }
.vn-dur-label    { opacity:.5; }
.vn-speed-btn {
    background:rgba(255,255,255,.1); border:none; border-radius:4px;
    color:#94a3b8; font-size:.65rem; padding:1px 5px; cursor:pointer;
    transition:all .2s;
}
.vn-speed-btn:hover { background:rgba(56,189,248,.2); color:#38bdf8; }
.vn-dl-btn { color:#64748b; font-size:.8rem; margin-left:auto; text-decoration:none; }
.vn-dl-btn:hover { color:#38bdf8; }

/* ── Composer ── */
.vn-composer { padding:.75rem 1rem; }
.vn-input-row {
    display:flex; align-items:flex-end; gap:.5rem;
}
.vn-textarea {
    flex:1; resize:none; border-radius:14px;
    background:rgba(255,255,255,.05);
    border:1px solid rgba(255,255,255,.1);
    color:#e2e8f0; font-size:.9rem;
    padding:.5rem .9rem;
    transition:border-color .2s;
    max-height:120px;
}
.vn-textarea:focus { outline:none; border-color:#38bdf8; box-shadow:0 0 0 3px rgba(56,189,248,.15); }

/* ── Mic button ── */
.vn-mic-btn {
    width:40px; height:40px; flex-shrink:0;
    border-radius:50%; border:none; cursor:pointer;
    background:linear-gradient(135deg,rgba(52,211,153,.15),rgba(56,189,248,.15));
    border:1px solid rgba(52,211,153,.3);
    color:#34d399; font-size:1.05rem;
    display:flex; align-items:center; justify-content:center;
    transition:all .25s; position:relative;
}
.vn-mic-btn:hover {
    transform:scale(1.1);
    background:linear-gradient(135deg,rgba(52,211,153,.3),rgba(56,189,248,.3));
    box-shadow:0 0 14px rgba(52,211,153,.4);
}
.vn-mic-btn.active {
    background:linear-gradient(135deg,rgba(239,68,68,.2),rgba(220,38,38,.2));
    border-color:rgba(239,68,68,.5);
    color:#f87171;
    box-shadow:0 0 16px rgba(239,68,68,.4);
    animation:vn-mic-pulse 1.2s ease-in-out infinite;
}
@keyframes vn-mic-pulse {
    0%,100% { box-shadow:0 0 10px rgba(239,68,68,.4); }
    50%      { box-shadow:0 0 22px rgba(239,68,68,.7); }
}

/* ── Send text button ── */
.vn-send-text-btn {
    width:40px; height:40px; flex-shrink:0;
    border-radius:50%; border:none; cursor:pointer;
    background:linear-gradient(135deg,#0d6efd,#0a58ca);
    color:#fff; font-size:1rem;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s;
    box-shadow:0 0 10px rgba(13,110,253,.35);
}
.vn-send-text-btn:hover { transform:scale(1.08); box-shadow:0 0 18px rgba(13,110,253,.55); }

/* ── Recording bar ── */
.vn-rec-bar {
    display:flex; align-items:center; gap:.6rem;
    background:rgba(239,68,68,.08);
    border:1px solid rgba(239,68,68,.25);
    border-radius:12px; padding:.5rem .8rem;
    margin-bottom:.5rem;
}
.vn-rec-pulse {
    width:10px; height:10px; flex-shrink:0;
    background:#ef4444; border-radius:50%;
    animation:vn-rec-blink 1s ease-in-out infinite;
}
@keyframes vn-rec-blink { 0%,100%{opacity:1;} 50%{opacity:.2;} }
.vn-rec-label  { font-size:.8rem; color:#f87171; font-weight:600; }
.vn-rec-timer  { font-size:.8rem; color:#fca5a5; font-variant-numeric:tabular-nums; min-width:28px; }
.vn-rec-waves  { display:flex; align-items:center; gap:2px; height:22px; flex:1; }
.vn-rbar       { width:3px; border-radius:2px; background:rgba(239,68,68,.4); height:40%; }
.vn-cancel-btn {
    background:transparent; border:none; color:#f87171;
    font-size:1rem; cursor:pointer; flex-shrink:0;
    padding:2px 4px; transition:color .2s;
}
.vn-cancel-btn:hover { color:#ef4444; }
.vn-stop-btn {
    background:#22c55e; border:none; color:#fff;
    font-size:1rem; cursor:pointer; flex-shrink:0;
    padding:3px 8px; border-radius:6px; transition:background .2s;
    display:flex; align-items:center; gap:4px;
}
.vn-stop-btn:hover { background:#16a34a; }

/* ── Preview bar ── */
.vn-preview-bar {
    background:rgba(52,211,153,.07);
    border:1px solid rgba(52,211,153,.25);
    border-radius:12px; padding:.6rem .85rem;
    margin-bottom:.5rem;
}
.vn-preview-inner { display:flex; flex-direction:column; gap:0; width:100%; }
.vn-preview-top { display:flex; align-items:center; gap:.5rem; font-size:.82rem; color:#86efac; }
.vn-preview-top .vn-preview-label { flex:1; font-weight:600; }
.vn-preview-dur { color:#34d399; font-variant-numeric:tabular-nums; font-size:.78rem; }
.vn-preview-actions { display:flex; gap:.5rem; }
#vn-preview-audio::-webkit-media-controls-panel { background:rgba(15,23,42,.6); }

/* ── Scrollbar ── */
.vn-thread::-webkit-scrollbar       { width:5px; }
.vn-thread::-webkit-scrollbar-track { background:transparent; }
.vn-thread::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12); border-radius:3px; }

@media(max-width:576px) {
    .vn-bubble { max-width:95%; }
    .vn-waveform { display:none; }
    .vn-voice-player { flex-wrap:wrap; }
}
</style>

<!-- ── Delete confirmation modal ── -->
<div class="modal fade" id="deleteMsgModal" tabindex="-1" aria-labelledby="deleteMsgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content" style="background:#1e293b;border:1px solid rgba(255,255,255,.1);border-radius:1rem;">
            <div class="modal-body text-center py-4 px-3">
                <div style="width:3.5rem;height:3.5rem;border-radius:50%;background:rgba(239,68,68,.15);display:flex;align-items:center;justify-content:center;margin:0 auto .9rem;">
                    <i class="bi bi-trash3-fill" style="font-size:1.5rem;color:#f87171;"></i>
                </div>
                <h6 style="color:#f1f5f9;font-weight:700;margin-bottom:.4rem;">Delete message?</h6>
                <p style="color:#94a3b8;font-size:.82rem;margin:0;">This message will be deleted for <strong style="color:#cbd5e1;">everyone</strong> in the chat.</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0 pb-3 gap-2">
                <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,.08);color:#cbd5e1;border:1px solid rgba(255,255,255,.12);border-radius:.6rem;padding:.45rem 1.2rem;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="confirm-delete-btn" style="border-radius:.6rem;padding:.45rem 1.2rem;">
                    <i class="bi bi-trash3 me-1"></i>Delete for everyone
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Toast ── -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="msg-action-toast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true" style="border-radius:.75rem;min-width:220px;">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="msg-toast-body" style="font-size:.85rem;"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<!-- ════════════════════════════ SCRIPTS ════════════════════════════ -->
<?php if ($project_context): ?>
<script>
(function () {
'use strict';

/* ── Constants ── */
const CSRF    = <?= json_encode(csrf_token()) ?>;
const PID     = <?= (int)$project_id ?>;
const UID     = <?= (int)$uid ?>;
const POLL_URL  = <?= json_encode(base_url('api/messages_poll.php')) ?>;
const VOICE_URL = <?= json_encode(base_url('api/voice_upload.php')) ?>;
const SPEEDS    = [1, 1.5, 2, 0.75];

/* ── DOM refs ── */
const thread    = document.getElementById('vn-thread');
const recBar    = document.getElementById('vn-rec-bar');
const prevBar   = document.getElementById('vn-preview-bar');
const textForm  = document.getElementById('vn-text-form');
const textarea  = document.getElementById('vn-textarea');
const micBtn    = document.getElementById('vn-mic-btn');
const cancelBtn = document.getElementById('vn-cancel-btn');
const stopBtn   = document.getElementById('vn-stop-btn');
const discardBtn= document.getElementById('vn-discard-btn');
const sendVoice = document.getElementById('vn-send-voice-btn');
const recTimer  = document.getElementById('vn-rec-timer');
const prevDur    = document.getElementById('vn-preview-dur');
const prevAudio  = document.getElementById('vn-preview-audio');
const recWaves   = document.getElementById('vn-rec-waves');
let   prevObjUrl = null;

/* ── Recording state ── */
let mediaRec = null, recChunks = [], recInterval = null, recSecs = 0, voiceBlob = null, voiceDur = 0;

/* ── Auto-resize textarea ── */
textarea?.addEventListener('input', () => {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
});

/* ── Waveform animation while recording ── */
function animateRecWaves() {
    const bars = recWaves?.querySelectorAll('.vn-rbar');
    if (!bars) return;
    bars.forEach(b => {
        b.style.height = (20 + Math.random() * 80) + '%';
    });
}

/* ── Format seconds → m:ss ── */
function fmt(s) {
    s = Math.round(s);
    return Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
}

/* ── Start recording ── */
async function startRec() {
    if (!navigator.mediaDevices?.getUserMedia) {
        alert('Microphone access is not supported in this browser.');
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recChunks = []; recSecs = 0;
        const mimeType = ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/ogg']
            .find(m => MediaRecorder.isTypeSupported(m)) || '';
        mediaRec = new MediaRecorder(stream, mimeType ? { mimeType } : {});
        mediaRec.ondataavailable = e => { if (e.data.size > 0) recChunks.push(e.data); };
        mediaRec.onstop = () => {
            stream.getTracks().forEach(t => t.stop());
            voiceBlob = new Blob(recChunks, { type: mediaRec.mimeType || 'audio/webm' });
            voiceDur  = recSecs;
            showPreview();
        };
        mediaRec.start(200);
        micBtn.classList.add('active');
        recBar.classList.remove('d-none');
        textForm.classList.add('d-none');
        recInterval = setInterval(() => {
            recSecs++;
            recTimer.textContent = fmt(recSecs);
            animateRecWaves();
        }, 1000);
        animateRecWaves();
    } catch(e) {
        alert('Could not access microphone: ' + e.message);
    }
}

/* ── Stop recording ── */
function stopRec() {
    if (mediaRec && mediaRec.state !== 'inactive') mediaRec.stop();
    clearInterval(recInterval);
    recBar.classList.add('d-none');
    micBtn.classList.remove('active');
}

/* ── Cancel recording ── */
function cancelRec() {
    if (mediaRec && mediaRec.state !== 'inactive') {
        mediaRec.ondataavailable = null;
        mediaRec.onstop = null;
        mediaRec.stop();
        mediaRec.stream?.getTracks().forEach(t => t.stop());
    }
    clearInterval(recInterval);
    recBar.classList.add('d-none');
    textForm.classList.remove('d-none');
    micBtn.classList.remove('active');
    voiceBlob = null;
}

/* ── Show preview ── */
function showPreview() {
    prevDur.textContent = fmt(voiceDur);
    /* revoke any old object URL */
    if (prevObjUrl) { URL.revokeObjectURL(prevObjUrl); prevObjUrl = null; }
    if (prevAudio && voiceBlob) {
        prevObjUrl = URL.createObjectURL(voiceBlob);
        prevAudio.src = prevObjUrl;
        prevAudio.load();
    }
    prevBar.classList.remove('d-none');
    textForm.classList.add('d-none');
}

/* ── Discard voice note ── */
function discardVoice() {
    voiceBlob = null; voiceDur = 0;
    if (prevAudio) { prevAudio.pause(); prevAudio.src = ''; }
    if (prevObjUrl) { URL.revokeObjectURL(prevObjUrl); prevObjUrl = null; }
    prevBar.classList.add('d-none');
    textForm.classList.remove('d-none');
}

/* ── Upload + send voice note ── */
async function sendVoiceNote() {
    if (!voiceBlob) return;
    sendVoice.disabled = true;
    sendVoice.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    const fd = new FormData();
    fd.append('audio', voiceBlob, 'voice_note.webm');
    fd.append('project_id', PID);
    fd.append('duration', voiceDur);
    fd.append('csrf_token', CSRF);
    try {
        const res  = await fetch(VOICE_URL, { method:'POST', body:fd, credentials:'same-origin' });
        const data = await res.json();
        if (data.ok) {
            appendVoiceBubble(data, true);
            discardVoice();
            thread.scrollTop = thread.scrollHeight;
            /* use max_id so the poll skips ALL fan-out rows, preventing duplicates */
            lastId = Math.max(lastId, data.max_id ?? data.id);
        } else {
            alert('Error: ' + (data.error || 'Upload failed'));
        }
    } catch(e) {
        alert('Network error. Please try again.');
    }
    sendVoice.disabled = false;
    sendVoice.innerHTML = '<i class="bi bi-send me-1"></i>Send';
}

/* ── Build a voice bubble DOM node ── */
function buildVoiceBubble(data, mine) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3 vn-msg-row ' + (mine ? 'vn-mine' : 'vn-theirs');
    wrap.dataset.id = data.id;

    const dur     = data.audio_duration || 0;
    const durFmt  = fmt(dur);
    const bars    = Array.from({length:28}, () =>
        `<span class="vn-bar" style="height:${20+Math.floor(Math.random()*80)}%"></span>`).join('');

    wrap.innerHTML = `
    <div class="vn-bubble vn-voice-bubble">
        ${!mine ? `<div class="vn-sender">${escHtml(data.sender_name)}</div>` : ''}
        <div class="vn-voice-player" data-src="${escHtml(data.audio_url)}" data-dur="${dur}">
            <button class="vn-play-btn" aria-label="Play"><i class="bi bi-play-fill"></i></button>
            <div class="vn-waveform">${bars}</div>
            <div class="vn-right-col">
                <input type="range" class="vn-seek" min="0" max="${dur||100}" value="0" step="0.1">
                <div class="vn-meta">
                    <span class="vn-time-current">0:00</span>
                    <span class="vn-dur-label">${durFmt}</span>
                    <div class="vn-speed-wrap"><button class="vn-speed-btn">1×</button></div>
                    <a href="${escHtml(data.audio_url)}" download class="vn-dl-btn" title="Download"><i class="bi bi-download"></i></a>
                </div>
            </div>
        </div>
        <div class="vn-ts">${escHtml(data.created_at_fmt)}</div>
    </div>
    ${mine ? `<div class="vn-opts" data-msg-id="${data.id}">
        <button class="vn-opts-arrow" onclick="vnOpenMenu(this,event)"><i class="bi bi-chevron-down"></i></button>
    </div>` : ''}`;

    initVoicePlayer(wrap.querySelector('.vn-voice-player'));
    return wrap;
}

function appendVoiceBubble(data, mine) {
    thread.appendChild(buildVoiceBubble(data, mine));
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Initialise a voice player widget ── */
function initVoicePlayer(player) {
    if (!player) return;
    const audio     = new Audio(player.dataset.src);
    const playBtn   = player.querySelector('.vn-play-btn');
    const seekEl    = player.querySelector('.vn-seek');
    const timeCur   = player.querySelector('.vn-time-current');
    const durLabel  = player.querySelector('.vn-dur-label');
    const speedBtn  = player.querySelector('.vn-speed-btn');
    const waveform  = player.querySelector('.vn-waveform');
    let   speedIdx  = 0;

    audio.preload = 'metadata';
    audio.onloadedmetadata = () => {
        const d = audio.duration;
        if (d && isFinite(d)) {
            seekEl.max  = d;
            durLabel.textContent = fmt(d);
        }
    };

    /* play / pause */
    playBtn.addEventListener('click', () => {
        if (audio.paused) {
            /* stop any other playing audio */
            document.querySelectorAll('.vn-voice-player audio').forEach(a => { if (a !== audio) a.pause(); });
            document.querySelectorAll('.vn-play-btn.playing').forEach(b => {
                b.classList.remove('playing');
                b.innerHTML = '<i class="bi bi-play-fill"></i>';
                b.closest('.vn-voice-player')?.querySelector('.vn-waveform')?.classList.remove('playing');
            });
            audio.play();
            playBtn.classList.add('playing');
            playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
            waveform?.classList.add('playing');
        } else {
            audio.pause();
            playBtn.classList.remove('playing');
            playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
            waveform?.classList.remove('playing');
        }
    });

    audio.ontimeupdate = () => {
        timeCur.textContent = fmt(audio.currentTime);
        if (audio.duration && isFinite(audio.duration)) {
            seekEl.value = audio.currentTime;
        }
    };

    audio.onended = () => {
        playBtn.classList.remove('playing');
        playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
        waveform?.classList.remove('playing');
        seekEl.value = 0;
        timeCur.textContent = '0:00';
    };

    seekEl.addEventListener('input', () => { audio.currentTime = seekEl.value; });

    /* playback speed */
    speedBtn.addEventListener('click', () => {
        speedIdx = (speedIdx + 1) % SPEEDS.length;
        audio.playbackRate = SPEEDS[speedIdx];
        speedBtn.textContent = SPEEDS[speedIdx] + '×';
    });

    /* store audio element on the player for external stop */
    player._audio = audio;
}

/* ── Wire up all existing voice players on page load ── */
document.querySelectorAll('.vn-voice-player').forEach(p => initVoicePlayer(p));

/* ── Mic button toggle ── */
micBtn?.addEventListener('click', () => {
    if (!mediaRec || mediaRec.state === 'inactive') {
        startRec();
    } else {
        stopRec();
    }
});
cancelBtn?.addEventListener('click', cancelRec);
stopBtn?.addEventListener('click', stopRec);
discardBtn?.addEventListener('click', discardVoice);
sendVoice?.addEventListener('click', sendVoiceNote);

/* ── Message options (WhatsApp-style) ── */
let _pendingDeleteId = null;
const confirmDelBtn  = document.getElementById('confirm-delete-btn');
const toastEl        = document.getElementById('msg-action-toast');
const toastBody      = document.getElementById('msg-toast-body');

/* lazy-init Bootstrap instances so they're created after Bootstrap JS loads (footer.php) */
let _deleteModal = null, _bsToast = null;
function getDeleteModal() {
    if (!_deleteModal) _deleteModal = new bootstrap.Modal(document.getElementById('deleteMsgModal'));
    return _deleteModal;
}
function getBsToast() {
    if (!_bsToast) _bsToast = new bootstrap.Toast(toastEl, { delay: 3500 });
    return _bsToast;
}

/* receive delete trigger from global vnDelete() outside IIFE */
document.addEventListener('vn:delete', e => {
    _pendingDeleteId = e.detail.id;
    getDeleteModal().show();
});

function showToast(msg, type) {
    toastEl.className = 'toast align-items-center text-white border-0';
    toastEl.classList.add('bg-' + (type || 'success'));
    toastBody.textContent = msg;
    getBsToast().show();
}

/* close portal menu on outside click */
document.addEventListener('click', () => {
    document.getElementById('vn-portal-menu')?.remove();
});

/* confirm delete button in modal */
confirmDelBtn?.addEventListener('click', () => {
    if (!_pendingDeleteId) return;
    confirmDelBtn.disabled = true;
    confirmDelBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting…';
    const fd = new FormData();
    fd.append('action', 'delete_message');
    fd.append('message_id', _pendingDeleteId);
    fd.append('project_id', PID);
    fd.append('csrf_token', CSRF);
    fetch(window.location.pathname + window.location.search, { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            getDeleteModal().hide();
            if (data.ok) {
                removeBubble(_pendingDeleteId);
                showToast('Message deleted for everyone.', 'success');
            } else {
                showToast(data.error || 'Could not delete message.', 'danger');
            }
        })
        .catch(() => { getDeleteModal().hide(); showToast('Network error. Please try again.', 'danger'); })
        .finally(() => {
            confirmDelBtn.disabled = false;
            confirmDelBtn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Delete for everyone';
            _pendingDeleteId = null;
        });
});

function removeBubble(id) {
    const row = document.querySelector('.vn-msg-row[data-id="'+id+'"]');
    if (!row) return;
    const bubble = row.querySelector('.vn-bubble');
    if (bubble) {
        bubble.innerHTML = '<span class="vn-deleted-bubble"><i class="bi bi-slash-circle"></i> This message was deleted</span>';
        bubble.className = 'vn-bubble';
    }
}

/* ── Poll for new messages ── */
/* Use MAX(id) across ALL fan-out rows so poll baseline is correct, preventing duplicates */
let lastId = <?= $max_msg_id ?>;

function buildTextBubble(m, mine) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3 vn-msg-row ' + (mine ? 'vn-mine' : 'vn-theirs');
    wrap.dataset.id = m.id;
    const bubble = document.createElement('div');
    bubble.className = 'vn-bubble vn-text-bubble';
    if (!mine) {
        const sn = document.createElement('div');
        sn.className = 'vn-sender'; sn.textContent = m.sender_name;
        bubble.appendChild(sn);
    }
    const bd = document.createElement('div');
    bd.className = 'vn-body'; bd.textContent = m.body;
    bubble.appendChild(bd);
    const ts = document.createElement('div');
    ts.className = 'vn-ts'; ts.textContent = m.created_at_fmt;
    bubble.appendChild(ts);
    wrap.appendChild(bubble);
    if (mine) {
        const opts = document.createElement('div');
        opts.className = 'vn-opts'; opts.dataset.msgId = m.id;
        opts.innerHTML = `<button class="vn-opts-arrow" onclick="vnOpenMenu(this,event)"><i class="bi bi-chevron-down"></i></button>`;
        wrap.appendChild(opts);
    }
    return wrap;
}

function poll() {
    fetch(POLL_URL + '?pid=' + PID + '&after=' + lastId, { credentials:'same-origin' })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data) return;
            /* process deletions first */
            if (data.deleted?.length) {
                data.deleted.forEach(id => removeBubble(id));
            }
            if (!data.messages?.length) return;
            data.messages.forEach(m => {
                if (document.querySelector('.vn-msg-row[data-id="'+m.id+'"]')) return;
                const mine = m.sender_id == UID;
                const row = m.message_type === 'voice' && m.audio_url
                    ? buildVoiceBubble(m, mine)
                    : buildTextBubble(m, mine);
                thread.appendChild(row);
                lastId = Math.max(lastId, m.id);
            });
            thread.scrollTop = thread.scrollHeight;
        })
        .catch(() => {});
}

setInterval(poll, 15000);
thread.scrollTop = thread.scrollHeight;

})();

/* ── Global handlers for inline onclick (outside IIFE, body-level portal) ── */
function vnOpenMenu(btn, e) {
    e.stopPropagation();
    const existing = document.getElementById('vn-portal-menu');
    const forId = btn.closest('.vn-opts').dataset.msgId;
    if (existing) {
        const same = existing.dataset.forMsgId === forId;
        existing.remove();
        if (same) return; /* toggle off */
    }
    const r = btn.getBoundingClientRect();
    const menu = document.createElement('div');
    menu.id = 'vn-portal-menu';
    menu.dataset.forMsgId = forId;
    menu.style.top  = (r.bottom + 6) + 'px';
    menu.style.left = Math.max(4, r.right - 182) + 'px';
    menu.innerHTML = `<button class="vn-opts-delete" onclick="vnDelete(this,event)"><i class="bi bi-trash3"></i> Delete for everyone</button>`;
    document.body.appendChild(menu);
}
function vnDelete(btn, e) {
    e.stopPropagation();
    const mid = parseInt(document.getElementById('vn-portal-menu').dataset.forMsgId, 10);
    document.getElementById('vn-portal-menu')?.remove();
    document.dispatchEvent(new CustomEvent('vn:delete', { detail: { id: mid } }));
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
