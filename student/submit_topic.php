<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$user = current_user();
$uid  = user_id();
$pdo  = getPDO();

ensure_project_keywords_column($pdo);

// Determine group membership
$stmt = $pdo->prepare('SELECT gm.group_id, g.name FROM `group_members` gm JOIN `groups` g ON g.id = gm.group_id WHERE gm.student_id = ? AND g.is_active = 1 LIMIT 1');
$stmt->execute([$uid]);
$current_group = $stmt->fetch();
$group_id = $current_group ? (int) $current_group['group_id'] : null;

// Fetch existing project
$project = null;
if ($group_id) {
    $stmt = $pdo->prepare('SELECT p.* FROM projects p WHERE p.group_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $stmt->execute([$group_id]);
    $project = $stmt->fetch();
}
if (!$project && !$group_id) {
    $stmt = $pdo->prepare('SELECT p.* FROM projects p WHERE p.student_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $stmt->execute([$uid]);
    $project = $stmt->fetch();
}

// If a non-draft/non-rejected project exists, no need to submit here
if ($project && !in_array($project['status'], ['draft', 'rejected'], true)) {
    flash('info', 'You already have an active project. Manage it here.');
    redirect(base_url('student/project.php'));
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $title    = trim($_POST['title']    ?? '');
    $keywords = trim($_POST['keywords'] ?? '');

    if (strlen($title) < 10) {
        $error = 'Project title must be at least 10 characters.';
    } else {
        $proposal_file = null;
        if (!empty($_FILES['proposal_file']['name'])) {
            $file          = $_FILES['proposal_file'];
            $ext           = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $detected_mime = $finfo->file($file['tmp_name']);
            $allowed_ext   = ['pdf', 'docx', 'doc'];
            $allowed_mime  = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];
            $max_size      = 15 * 1024 * 1024;

            if (!in_array($ext, $allowed_ext, true) || !in_array($detected_mime, $allowed_mime, true)) {
                $error = 'Invalid file type. Allowed: PDF, DOCX, DOC.';
            } elseif ($file['size'] > $max_size) {
                $error = 'File size exceeds 15MB limit.';
            } else {
                $proposal_file = $file;
            }
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();

                if ($group_id) {
                    $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ? ORDER BY CASE WHEN role = "lead" THEN 0 ELSE 1 END, id ASC LIMIT 1');
                    $stmt->execute([$group_id]);
                    $group_owner = (int) ($stmt->fetchColumn() ?: $uid);
                    $stmt = $pdo->prepare('INSERT INTO projects (student_id, group_id, title, keywords, status, submitted_at) VALUES (?, ?, ?, ?, "submitted", NOW())');
                    $stmt->execute([$group_owner, $group_id, $title, $keywords ?: null]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO projects (student_id, title, keywords, status, submitted_at) VALUES (?, ?, ?, "submitted", NOW())');
                    $stmt->execute([$uid, $title, $keywords ?: null]);
                }
                $project_id = (int) $pdo->lastInsertId();

                if ($proposal_file) {
                    $upload_dir = dirname(__DIR__) . '/uploads/projects/' . $project_id;
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $proposal_file['name']);
                    $safe_name = date('Ymd_His') . '_' . $safe_name;
                    $path      = $upload_dir . '/' . $safe_name;

                    if (move_uploaded_file($proposal_file['tmp_name'], $path)) {
                        $rel           = 'projects/' . $project_id . '/' . $safe_name;
                        $detected_mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

                        $pdo->prepare('UPDATE project_documents SET is_latest = 0 WHERE project_id = ? AND document_type = ? AND is_latest = 1')->execute([$project_id, 'proposal']);

                        $stmt = $pdo->prepare('INSERT INTO project_documents (project_id, document_type, file_name, file_path, file_size, mime_type, uploader_id, version_number, is_latest) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)');
                        $stmt->execute([$project_id, 'proposal', $proposal_file['name'], $rel, $proposal_file['size'], $detected_mime, $uid]);
                    } else {
                        throw new Exception('Failed to upload proposal file.');
                    }
                }

                $pdo->commit();
                flash('success', 'Project topic submitted for approval.' . ($proposal_file ? ' Proposal document uploaded.' : ''));
                redirect(base_url('student/project.php'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($proposal_file && isset($path) && is_file($path)) unlink($path);
                $error = 'Unable to submit project. Please try again.';
            }
        }
    }
}

$pageTitle = 'Submit Project Topic';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">Submit Project Topic</h1>
        <p class="dashboard-hero__copy mb-0">Submit your final year project topic and optional proposal for supervisor approval.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="<?= base_url('student/project.php') ?>" class="btn dashboard-hero__btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> My Project
        </a>
    </div>
</section>

<?php if ($current_group): ?>
    <div class="alert alert-info mb-3">
        <i class="bi bi-people-fill me-1"></i>
        Submitting as group: <strong><?= e($current_group['name']) ?></strong>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-send me-1"></i> Project Topic &amp; Proposal
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">Provide your project title, an optional proposal document, and keywords. Your submission will be reviewed and approved before work begins.</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold" for="title">Project Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required minlength="10"
                           placeholder="Enter your project title (min 10 characters)"
                           value="<?= e($_POST['title'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="proposal_file">Proposal Document <span class="text-muted fw-normal">(Recommended)</span></label>
                    <input type="file" class="form-control" id="proposal_file" name="proposal_file" accept=".pdf,.doc,.docx">
                    <small class="d-block text-muted mt-2"><i class="bi bi-info-circle"></i> Upload your proposal document (PDF, DOCX, or DOC). Max file size: 15MB</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="keywords">Keywords <span class="text-muted fw-normal">(comma-separated, e.g. machine learning, IoT)</span></label>
                    <input type="text" class="form-control" id="keywords" name="keywords"
                           placeholder="keyword1, keyword2, keyword3"
                           value="<?= e($_POST['keywords'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i> Submit for Approval
                    </button>
                    <a href="<?= base_url('student/project.php') ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
