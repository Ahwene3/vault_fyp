<?php
/**
 * Voice note upload endpoint
 * Accepts a WebM/OGG/MP4 audio blob, saves it, inserts a message row.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
if (!csrf_verify()) {
    http_response_code(403); echo json_encode(['error' => 'CSRF check failed']); exit;
}

$uid        = user_id();
$role       = user_role();
$pdo        = getPDO();
$project_id = (int) ($_POST['project_id'] ?? 0);
$duration   = (int) ($_POST['duration']   ?? 0);

if (!$project_id) {
    http_response_code(400); echo json_encode(['error' => 'Missing project_id']); exit;
}

/* ── Access check ── */
$proj = $pdo->prepare('SELECT id, student_id, supervisor_id, group_id, status FROM projects WHERE id = ? LIMIT 1');
$proj->execute([$project_id]);
$project = $proj->fetch();

if (!$project || $project['status'] === 'archived') {
    http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
}

/* ── File validation ── */
if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['error' => 'No audio file received']); exit;
}

$file     = $_FILES['audio'];
$max_size = 10 * 1024 * 1024; // 10 MB

if ($file['size'] > $max_size) {
    http_response_code(400); echo json_encode(['error' => 'File too large (max 10 MB)']); exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($file['tmp_name']);
$allowed  = ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'video/webm'];

if (!in_array($mime, $allowed, true)) {
    http_response_code(400); echo json_encode(['error' => 'Invalid audio format']); exit;
}

$ext_map  = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
             'audio/mp4' => 'm4a', 'audio/wav' => 'wav', 'video/webm' => 'webm'];
$ext      = $ext_map[$mime] ?? 'webm';

$upload_dir = __DIR__ . '/../uploads/voice/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$filename   = 'vn_' . $uid . '_' . $project_id . '_' . time() . '.' . $ext;
$filepath   = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500); echo json_encode(['error' => 'Could not save file']); exit;
}

$audio_path = 'uploads/voice/' . $filename;

/* ── Determine recipients ── */
$recipient_ids = [];
if ($role === 'student') {
    if (!empty($project['group_id'])) {
        $gm = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id = ?');
        $gm->execute([(int) $project['group_id']]);
        foreach ($gm->fetchAll() as $m) $recipient_ids[] = (int) $m['student_id'];
    } else {
        $recipient_ids[] = (int) $project['student_id'];
    }
    if (!empty($project['supervisor_id'])) $recipient_ids[] = (int) $project['supervisor_id'];
} elseif ($role === 'supervisor') {
    if (!empty($project['group_id'])) {
        $gm = $pdo->prepare('SELECT student_id FROM group_members WHERE group_id = ?');
        $gm->execute([(int) $project['group_id']]);
        foreach ($gm->fetchAll() as $m) $recipient_ids[] = (int) $m['student_id'];
    } else {
        $recipient_ids[] = (int) $project['student_id'];
    }
}

$recipient_ids = array_values(array_unique(array_filter($recipient_ids, fn($r) => (int)$r > 0 && (int)$r !== $uid)));

if (empty($recipient_ids)) {
    unlink($filepath);
    http_response_code(400); echo json_encode(['error' => 'No recipients found']); exit;
}

/* ── Insert messages ── */
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        'INSERT INTO messages (project_id, sender_id, recipient_id, subject, body, message_type, audio_path, audio_duration)
         VALUES (?, ?, ?, NULL, "", "voice", ?, ?)'
    );
    $noti = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)'
    );
    $first_id = null;
    $last_id  = null;
    foreach ($recipient_ids as $rid) {
        $ins->execute([$project_id, $uid, $rid, $audio_path, $duration]);
        $inserted_id = (int) $pdo->lastInsertId();
        if ($first_id === null) $first_id = $inserted_id;
        $last_id = $inserted_id;
        $noti->execute([$rid, 'message', 'New voice note',
            'You have a new voice message.', base_url('messages.php?pid=' . $project_id . '&with=' . $uid)]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    unlink($filepath);
    http_response_code(500); echo json_encode(['error' => 'DB error: ' . $e->getMessage()]); exit;
}

/* ── Fetch sender name ── */
$sn = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
$sn->execute([$uid]);
$sender_name = $sn->fetchColumn();

echo json_encode([
    'ok'             => true,
    'id'             => $first_id,
    'max_id'         => $last_id,
    'sender_id'      => $uid,
    'sender_name'    => $sender_name,
    'audio_url'      => base_url($audio_path),
    'audio_duration' => $duration,
    'created_at_fmt' => date('M j, H:i'),
]);
