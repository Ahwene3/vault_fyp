<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash('error', 'Invalid request.');
    redirect(base_url('supervisor/students.php'));
}

$uid = user_id();
$entry_id = (int) ($_POST['entry_id'] ?? 0);
$project_id = (int) ($_POST['project_id'] ?? 0);
$approve = (int) ($_POST['approve'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT le.id, le.project_id FROM logbook_entries le JOIN projects p ON le.project_id = p.id WHERE le.id = ? AND p.supervisor_id = ? AND le.supervisor_approved IS NULL');
$stmt->execute([$entry_id, $uid]);
$entry = $stmt->fetch();
if (!$entry) {
    flash('error', 'Entry not found or already reviewed.');
    redirect(base_url('supervisor/student_detail.php?pid=' . $project_id));
}

$pdo->prepare('UPDATE logbook_entries SET supervisor_approved = ?, approved_by = ?, approved_at = NOW(), supervisor_comment = ? WHERE id = ?')->execute([$approve ? 1 : 0, $uid, $comment ?: null, $entry_id]);

$stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ?');
$stmt->execute([$entry['project_id']]);
$pid = $stmt->fetch();
if ($pid) {
    $member_ids = [(int) $pid['student_id']];
    if (!empty($pid['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $pid['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $member_ids[] = (int) $m['student_id'];
        }
    }
    $member_ids = array_values(array_unique($member_ids));
    foreach ($member_ids as $member_id) {
        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
            $member_id,
            'logbook_approval',
            $approve ? 'Logbook entry approved' : 'Logbook entry flagged',
            $approve ? 'Your supervisor approved a logbook entry.' : 'Your supervisor flagged a logbook entry.',
            base_url('student/logbook.php')
        ]);
    }
}

flash('success', $approve ? 'Entry approved.' : 'Entry flagged.');
redirect(base_url('supervisor/student_detail.php?pid=' . $entry['project_id']));
