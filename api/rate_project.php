<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

$pdo  = getPDO();
$uid  = user_id();
$user = current_user();
$role = $user['role'] ?? '';

$action = trim($_POST['action'] ?? '');

function json_ok(array $data = []): never {
    echo json_encode(['ok' => true] + $data);
    exit;
}
function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── SUBMIT / UPDATE rating ──────────────────────────────────────────────────
if ($action === 'submit') {
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $rating     = (int) ($_POST['rating']     ?? 0);
    $comment    = trim($_POST['comment']      ?? '');

    if (!$project_id)         json_err('Invalid project.');
    if ($rating < 1 || $rating > 5) json_err('Rating must be between 1 and 5 stars.');

    // Verify project exists and is visible
    $ps = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND status IN ('approved','in_progress','completed','archived') LIMIT 1");
    $ps->execute([$project_id]);
    if (!$ps->fetch()) json_err('Project not found or not rateable.', 404);

    // Upsert: one rating per user per project
    $pdo->prepare("INSERT INTO project_ratings (project_id, user_id, rating, comment, status)
        VALUES (?, ?, ?, ?, 'visible')
        ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), status = 'visible', updated_at = NOW()")
        ->execute([$project_id, $uid, $rating, $comment ?: null]);

    // Refresh aggregate on projects table
    refresh_project_rating($pdo, $project_id);

    json_ok(['message' => 'Your review has been saved.']);
}

// ── DELETE rating ───────────────────────────────────────────────────────────
if ($action === 'delete') {
    $project_id = (int) ($_POST['project_id'] ?? 0);
    if (!$project_id) json_err('Invalid project.');

    $pdo->prepare('DELETE FROM project_ratings WHERE project_id = ? AND user_id = ?')->execute([$project_id, $uid]);
    refresh_project_rating($pdo, $project_id);

    json_ok(['message' => 'Review removed.']);
}

// ── FLAG review (admin / hod only) ──────────────────────────────────────────
if ($action === 'flag') {
    if (!in_array($role, ['admin', 'hod'], true)) json_err('Not authorised.', 403);

    $review_id = (int) ($_POST['review_id'] ?? 0);
    if (!$review_id) json_err('Invalid review.');

    $ps = $pdo->prepare('SELECT id, project_id FROM project_ratings WHERE id = ? LIMIT 1');
    $ps->execute([$review_id]);
    $row = $ps->fetch();
    if (!$row) json_err('Review not found.', 404);

    $pdo->prepare('UPDATE project_ratings SET status = "flagged", flagged_by = ?, flagged_at = NOW() WHERE id = ?')
        ->execute([$uid, $review_id]);

    refresh_project_rating($pdo, (int) $row['project_id']);
    json_ok(['message' => 'Review flagged for moderation.']);
}

json_err('Unknown action.');
