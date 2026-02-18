<?php
/**
 * Secure document download - only authorized users (student owner, supervisor, HOD, admin)
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT pd.id, pd.file_name, pd.file_path, pd.mime_type, p.student_id, p.supervisor_id FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    exit('Not found');
}

$uid = user_id();
$role = user_role();
$allowed = ($doc['student_id'] == $uid) || ($doc['supervisor_id'] == $uid) || $role === 'hod' || $role === 'admin';
if (!$allowed) {
    http_response_code(403);
    exit('Access denied');
}

$base = dirname(__DIR__);
$path = $base . '/uploads/' . $doc['file_path'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File not found');
}

$name = $doc['file_name'];
$mime = $doc['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\-]/', '_', $name) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
exit;
