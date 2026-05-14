<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
if (!csrf_verify()) {
    http_response_code(403); echo json_encode(['error' => 'CSRF check failed']); exit;
}

$uid = user_id();
$aid = (int) ($_POST['aid'] ?? 0);
if ($aid <= 0) {
    http_response_code(400); echo json_encode(['error' => 'Invalid id']); exit;
}

$pdo = getPDO();
ensure_announcements_tables($pdo);

$pdo->prepare(
    'INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)'
)->execute([$aid, $uid]);

echo json_encode(['ok' => true]);
