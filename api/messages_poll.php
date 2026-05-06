<?php
/**
 * Long-poll endpoint: returns new messages for a project thread after a given message id.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

$uid  = user_id();
$role = user_role();
$pdo  = getPDO();
$pid  = isset($_GET['pid'])   ? (int) $_GET['pid']   : 0;
$after = isset($_GET['after']) ? (int) $_GET['after'] : 0;

if ($pid <= 0) {
    echo json_encode(['messages' => []]);
    exit;
}

// Reuse access check
function api_can_access(PDO $pdo, int $uid, string $role, int $pid): bool {
    $stmt = $pdo->prepare('SELECT p.id, p.status, p.student_id, p.supervisor_id, p.group_id FROM projects p WHERE p.id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $p = $stmt->fetch();
    if (!$p || ($p['status'] ?? '') === 'archived') return false;
    if ($role === 'student') {
        $ids = [(int) $p['student_id']];
        if (!empty($p['group_id'])) {
            $s = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
            $s->execute([(int) $p['group_id']]);
            foreach ($s->fetchAll() as $m) $ids[] = (int) $m['student_id'];
        }
        return in_array($uid, $ids, true);
    }
    if ($role === 'supervisor') return (int) ($p['supervisor_id'] ?? 0) === $uid;
    return in_array($role, ['hod', 'admin'], true);
}

if (!api_can_access($pdo, $uid, $role, $pid)) {
    echo json_encode(['messages' => []]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT MIN(m.id) AS id, m.sender_id, u.full_name AS sender_name, m.body, m.created_at
     FROM messages m
     JOIN users u ON m.sender_id = u.id
     WHERE m.project_id = ? AND m.id > ?
     GROUP BY m.sender_id, m.body, m.created_at, u.full_name
     ORDER BY m.created_at ASC, MIN(m.id) ASC
     LIMIT 50'
);
$stmt->execute([$pid, $after]);
$rows = $stmt->fetchAll();

// Mark new messages as read for this user
if (!empty($rows)) {
    $pdo->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE project_id = ? AND recipient_id = ? AND is_read = 0')
        ->execute([$pid, $uid]);
}

$out = [];
foreach ($rows as $m) {
    $out[] = [
        'id'          => (int) $m['id'],
        'sender_id'   => (int) $m['sender_id'],
        'sender_name' => $m['sender_name'],
        'body'        => $m['body'],
        'created_at_fmt' => date('M j, H:i', strtotime($m['created_at'])),
    ];
}

echo json_encode(['messages' => $out]);
