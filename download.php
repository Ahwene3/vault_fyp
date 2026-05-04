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
$stmt = $pdo->prepare('SELECT pd.id, pd.file_name, pd.file_path, pd.mime_type, p.student_id, p.supervisor_id, p.group_id, p.status AS project_status FROM project_documents pd JOIN projects p ON pd.project_id = p.id WHERE pd.id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    exit('Not found');
}

$uid = user_id();
$role = user_role();
$is_group_member = false;
if (!empty($doc['group_id'])) {
    $stmt = $pdo->prepare('SELECT 1 FROM `group_members` WHERE group_id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([(int) $doc['group_id'], $uid]);
    $is_group_member = (bool) $stmt->fetchColumn();
}

$is_archived = ($doc['project_status'] === 'archived');
$allowed = ($doc['student_id'] == $uid) || ($doc['supervisor_id'] == $uid) || $is_group_member || $role === 'hod' || $role === 'admin' || $is_archived;
if (!$allowed) {
    http_response_code(403);
    exit('Access denied');
}

$base = __DIR__;
$path = $base . '/uploads/' . ltrim((string) $doc['file_path'], '/');
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File not found');
}

$name = $doc['file_name'];
$mime = $doc['mime_type'] ?: 'application/octet-stream';
$inline = isset($_GET['view']) && $_GET['view'] === '1';
// Allow browser preview for common document/media types; default stays download.
$dispositionType = $inline ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $dispositionType . '; filename="' . preg_replace('/[^\w.\-]/', '_', $name) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
exit;
