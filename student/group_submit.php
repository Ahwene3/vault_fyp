<?php
/**
 * Student — Submit project topic or proposal for a HOD-formed group.
 * Workflow depends on group.workflow:
 *   topic_first     → must submit topic first; proposal unlocked after approval
 *   direct_proposal → submit proposal directly
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/similarity.php';
require_role('student');

$pdo = getPDO();
$uid = user_id();
ensure_group_submission_tables($pdo);

/* ─── find student's HOD-formed group ──────────────────────────────────── */
$stmt = $pdo->prepare(
    'SELECT g.id, g.name, g.status, g.workflow, g.supervisor_id, g.academic_year, g.department,
            gm.role AS my_role
     FROM `groups` g
     JOIN `group_members` gm ON gm.group_id = g.id
     WHERE gm.student_id = ?
       AND g.batch_ref IS NOT NULL
       AND g.is_active = 1
     ORDER BY g.created_at DESC LIMIT 1'
);
$stmt->execute([$uid]);
$group = $stmt->fetch();

if (!$group) {
    flash('info', 'You have not been assigned to a project group by the HOD yet. Check back later.');
    redirect(base_url('dashboard.php'));
}

$group_id      = (int) $group['id'];
$group_status  = $group['status'];
$group_workflow = $group['workflow'];

/* group members */
$members_stmt = $pdo->prepare(
    'SELECT u.full_name, u.index_number, u.email, gm.role
     FROM group_members gm
     JOIN users u ON u.id = gm.student_id
     WHERE gm.group_id = ?
     ORDER BY CASE WHEN gm.role="lead" THEN 0 ELSE 1 END, u.full_name'
);
$members_stmt->execute([$group_id]);
$members = $members_stmt->fetchAll();

/* supervisor — check groups table first, fall back to projects table */
$supervisor = null;
$sup_id = (int) ($group['supervisor_id'] ?? 0);
if (!$sup_id) {
    $sp = $pdo->prepare('SELECT supervisor_id FROM projects WHERE group_id = ? AND supervisor_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1');
    $sp->execute([$group_id]);
    $sup_id = (int) ($sp->fetchColumn() ?: 0);
    if ($sup_id) {
        // backfill so future loads hit the fast path
        $pdo->prepare('UPDATE `groups` SET supervisor_id = ? WHERE id = ?')->execute([$sup_id, $group_id]);
    }
}
if ($sup_id) {
    $sv = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
    $sv->execute([$sup_id]);
    $supervisor = $sv->fetch();
}

/* latest submission for this group */
$latest_sub = $pdo->prepare(
    'SELECT id, type, title, abstract, keywords, status, rejection_reason, submitted_at FROM group_submissions WHERE group_id=? ORDER BY submitted_at DESC LIMIT 1'
);
$latest_sub->execute([$group_id]);
$latest_sub = $latest_sub->fetch();

/* approved project (if any) */
$project = $pdo->prepare('SELECT id, title, status FROM projects WHERE group_id=? ORDER BY updated_at DESC LIMIT 1');
$project->execute([$group_id]);
$project = $project->fetch();

/* students can submit when the group is formed (including after a rejection) */
$can_submit = ($group_status === 'formed');

$error   = '';
$success = '';

/* ─── POST: submit topic + proposal (combined) ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (!$can_submit) {
        $error = 'No submission is required at this stage.';
    } else {
        $title    = trim($_POST['title']    ?? '');
        $keywords = trim($_POST['keywords'] ?? '');

        if (strlen($title) < 5) {
            $error = 'Title must be at least 5 characters.';
        } else {
            $doc_path = null;
            $doc_mime = null;

            if (!empty($_FILES['document']['name'])) {
                $file = $_FILES['document'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'File upload error.';
                } elseif ($file['size'] > 15 * 1024 * 1024) {
                    $error = 'File exceeds 15 MB limit.';
                } else {
                    $finfo     = new finfo(FILEINFO_MIME_TYPE);
                    $mime      = $finfo->file($file['tmp_name']);
                    $allowed   = ['application/pdf', 'application/msword',
                                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allow_ext = ['pdf', 'doc', 'docx'];

                    if (!in_array($mime, $allowed, true) || !in_array($ext, $allow_ext, true)) {
                        $error = 'Only PDF, DOC, DOCX files are allowed.';
                    } else {
                        $upload_dir = __DIR__ . '/../uploads/submissions/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $filename = 'grp' . $group_id . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                            $doc_path = 'uploads/submissions/' . $filename;
                            $doc_mime = $mime;
                        } else {
                            $error = 'Could not save uploaded file. Check server permissions.';
                        }
                    }
                }
            }

            if (!$error) {
                $sim_results = find_similar_projects($pdo, $title, $keywords, '');
                $top_score   = !empty($sim_results) ? $sim_results[0]['score'] : null;

                $pdo->prepare(
                    'INSERT INTO group_submissions
                     (group_id, type, title, abstract, keywords, document_path, document_mime,
                      status, similarity_json, similarity_top, submitted_by)
                     VALUES (?, "proposal", ?, NULL, ?, ?, ?, "pending", ?, ?, ?)'
                )->execute([
                    $group_id, $title, $keywords ?: null,
                    $doc_path, $doc_mime,
                    $sim_results ? json_encode($sim_results) : null,
                    $top_score, $uid,
                ]);

                $pdo->prepare('UPDATE `groups` SET status="under_review" WHERE id=?')->execute([$group_id]);
                $group_status = 'under_review';
                $can_submit   = false;

                /* notify HOD(s) */
                $grp_dept_info = resolve_department_info($pdo, (string) ($group['department'] ?? ''));
                if (!empty($grp_dept_info['variants'])) {
                    $hod_ph   = sql_placeholders(count($grp_dept_info['variants']));
                    $hod_stmt = $pdo->prepare(
                        "SELECT id FROM users WHERE role='hod' AND is_active=1
                         AND LOWER(TRIM(COALESCE(department,''))) IN ($hod_ph) LIMIT 5"
                    );
                    $hod_stmt->execute($grp_dept_info['variants']);
                    foreach ($hod_stmt->fetchAll() as $h) {
                        notify_user(
                            (int) $h['id'], 'topic_submitted', 'Topic & Proposal Submitted',
                            "Group \"{$group['name']}\" submitted their topic and proposal: \"$title\". Review it now.",
                            base_url('hod/group_review.php')
                        );
                    }
                }

                $success = 'Topic and proposal submitted successfully. The HOD will review it shortly.';

                $s2 = $pdo->prepare('SELECT id, type, title, abstract, keywords, status, rejection_reason, submitted_at FROM group_submissions WHERE group_id=? ORDER BY submitted_at DESC LIMIT 1');
                $s2->execute([$group_id]);
                $latest_sub = $s2->fetch();
            }
        }
    }
}

/* status label / colour map */
$status_info = [
    'formed'       => ['label' => 'Group Formed — Pending Submission', 'class' => 'bg-secondary'],
    'under_review' => ['label' => 'Under Review',                       'class' => 'bg-info text-dark'],
    'approved'     => ['label' => 'Approved',                           'class' => 'bg-success'],
    'rejected'     => ['label' => 'Rejected',                           'class' => 'bg-danger'],
];
$current_status = $status_info[$group_status] ?? ['label' => ucfirst($group_status), 'class' => 'bg-secondary'];

$pageTitle = 'Project Submission';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Project Submission</h1>
    <span class="badge <?= $current_status['class'] ?> fs-6"><?= e($current_status['label']) ?></span>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- Group Info Card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people-fill me-1 text-primary"></i> <?= e($group['name']) ?></span>
        <small class="text-muted">Academic Year: <?= e($group['academic_year'] ?? '—') ?></small>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <p class="fw-semibold mb-2">Group Members</p>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($members as $m): ?>
                        <li class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi bi-person-circle text-secondary"></i>
                            <span><?= e($m['full_name']) ?></span>
                            <?php if ($m['index_number']): ?>
                                <small class="text-muted">(<?= e($m['index_number']) ?>)</small>
                            <?php endif; ?>
                            <?php if ($m['role'] === 'lead'): ?>
                                <span class="badge bg-primary" style="font-size:.7em;">Lead</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-md-6">
                <p class="fw-semibold mb-2">Supervisor</p>
                <?php if ($supervisor): ?>
                    <p class="mb-0">
                        <i class="bi bi-person-check-fill text-success me-1"></i>
                        <strong><?= e($supervisor['full_name']) ?></strong><br>
                        <small class="text-muted"><?= e($supervisor['email']) ?></small>
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-0"><i class="bi bi-clock me-1"></i> Not yet assigned</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Workflow Pipeline -->
<div class="card mb-4">
    <div class="card-body py-3">
        <?php
        $steps = ['Group Formed', 'Topic & Proposal Submitted', 'Under Review', 'Approved & Supervised', 'In Progress'];
        $step_map = [
            'formed'       => 0,
            'under_review' => 1,
            'approved'     => 3,
            'rejected'     => 0,
        ];
        $active_idx = $step_map[$group_status] ?? 0;
        if ($project && ($project['status'] ?? '') === 'in_progress') $active_idx = 4;
        ?>
        <div class="d-flex align-items-center gap-1 flex-wrap">
            <?php foreach ($steps as $i => $step): ?>
                <span class="badge <?= $i <= $active_idx ? 'bg-primary' : 'bg-secondary opacity-50' ?> px-2 py-1" style="font-size:.8em;"><?= e($step) ?></span>
                <?php if ($i < count($steps) - 1): ?>
                    <i class="bi bi-chevron-right text-muted" style="font-size:.75em;"></i>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Latest Submission Status -->
<?php if ($latest_sub): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-file-earmark-text me-1"></i> Latest Submission</span>
            <?php
                $sb = $latest_sub['status'];
                $sb_class = $sb === 'approved' ? 'bg-success' : ($sb === 'rejected' ? 'bg-danger' : 'bg-info text-dark');
            ?>
            <span class="badge <?= $sb_class ?>"><?= e(ucfirst($sb)) ?></span>
        </div>
        <div class="card-body">
            <h6><?= e($latest_sub['title']) ?></h6>
            <p class="text-muted small mb-1">Type: <?= e(ucfirst($latest_sub['type'])) ?> &nbsp;|&nbsp; Submitted: <?= date('M j, Y H:i', strtotime($latest_sub['submitted_at'])) ?></p>
            <?php if ($latest_sub['keywords']): ?>
                <p class="mb-1">
                    <?php foreach (explode(',', $latest_sub['keywords']) as $kw): ?>
                        <span class="badge bg-light text-dark border me-1"><?= e(trim($kw)) ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ($latest_sub['abstract']): ?>
                <p class="text-muted small mb-1"><?= nl2br(e(mb_substr($latest_sub['abstract'], 0, 300))) ?><?= mb_strlen($latest_sub['abstract']) > 300 ? '…' : '' ?></p>
            <?php endif; ?>
            <?php if ($sb === 'rejected' && $latest_sub['rejection_reason']): ?>
                <div class="alert alert-danger py-2 mt-2 mb-0">
                    <strong>Rejection Reason:</strong> <?= e($latest_sub['rejection_reason']) ?>
                </div>
            <?php endif; ?>
            <?php if ($sb === 'rejected'): ?>
                <div class="alert alert-warning py-2 mt-2 mb-0">
                    <i class="bi bi-arrow-clockwise me-1"></i> Your submission was rejected. Please revise your topic or proposal and resubmit below.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Approved Project Link -->
<?php if ($project && in_array($project['status'] ?? '', ['in_progress','submitted','completed'], true)): ?>
    <div class="alert alert-success mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Your project is active!</strong>
        <a href="<?= base_url('student/project.php') ?>" class="btn btn-sm btn-success ms-3">
            <i class="bi bi-journal-richtext me-1"></i> Go to My Project
        </a>
    </div>
<?php endif; ?>

<!-- Submission Form -->
<?php if ($can_submit): ?>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-send me-1"></i> Submit Project Topic &amp; Proposal
        </div>
        <div class="card-body">
            <?php if (!empty($latest_sub) && $latest_sub['status'] === 'rejected'): ?>
                <div class="alert alert-warning py-2 mb-3">
                    <i class="bi bi-arrow-clockwise me-1"></i>
                    <strong>Submission rejected.</strong> Revise and resubmit below.
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>

                <div class="col-12">
                    <label class="form-label fw-semibold">Project Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           value="<?= e($_POST['title'] ?? ($latest_sub['title'] ?? '')) ?>"
                           placeholder="Enter a clear and descriptive project title">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Keywords</label>
                    <input type="text" name="keywords" class="form-control"
                           value="<?= e($_POST['keywords'] ?? ($latest_sub['keywords'] ?? '')) ?>"
                           placeholder="e.g. machine learning, health, IoT (comma-separated)">
                    <div class="form-text text-white-50">Helps the HOD detect similar existing projects.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Proposal Document <span class="text-white-50 fw-normal">(optional)</span></label>
                    <input type="file" name="document" class="form-control" accept=".pdf,.doc,.docx">
                    <div class="form-text text-white-50">PDF, DOC or DOCX — max 15 MB.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i> Submit Topic &amp; Proposal
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($group_status === 'under_review'): ?>
    <div class="alert alert-info">
        <i class="bi bi-hourglass-split me-1"></i>
        <strong>Your submission is under review.</strong>
        The HOD will review it and notify you of the decision. No further action is required right now.
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
