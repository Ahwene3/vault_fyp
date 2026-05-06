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

$stmt = $pdo->prepare('SELECT le.title AS entry_title, p.student_id, p.group_id, p.title AS project_title FROM logbook_entries le JOIN projects p ON p.id = le.project_id WHERE le.id = ?');
$stmt->execute([$entry_id]);
$entry_detail = $stmt->fetch();

$pdo->prepare('UPDATE logbook_entries SET supervisor_approved = ?, approved_by = ?, approved_at = NOW(), supervisor_comment = ? WHERE id = ?')->execute([$approve ? 1 : 0, $uid, $comment ?: null, $entry_id]);

if ($entry_detail) {
    $member_ids = [(int) $entry_detail['student_id']];
    if (!empty($entry_detail['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $entry_detail['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $member_ids[] = (int) $m['student_id'];
        }
    }
    $member_ids = array_values(array_unique($member_ids));

    $stmt_email = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ?');
    foreach ($member_ids as $member_id) {
        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
            $member_id,
            'logbook_approval',
            $approve ? 'Logbook entry approved' : 'Logbook entry flagged',
            $approve ? 'Your supervisor approved a logbook entry.' : 'Your supervisor flagged a logbook entry.',
            base_url('student/logbook.php')
        ]);
        $stmt_email->execute([$member_id]);
        $student = $stmt_email->fetch();
        if ($student) {
            send_logbook_feedback_email(
                $student['email'],
                $student['full_name'],
                (string) $entry_detail['project_title'],
                (string) $entry_detail['entry_title'],
                (bool) $approve,
                $comment
            );
        }
    }
}

flash('success', $approve ? 'Entry approved.' : 'Entry flagged.');
redirect(base_url('supervisor/student_detail.php?pid=' . $entry['project_id']));
