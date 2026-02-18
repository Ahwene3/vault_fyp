<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();

$stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.student_id = ? ORDER BY p.updated_at DESC LIMIT 1');
$stmt->execute([$uid]);
$project = $stmt->fetch();

$error = '';
$success = '';

// Submit new topic or update draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($action === 'submit_topic') {
        if (strlen($title) < 10) {
            $error = 'Project title must be at least 10 characters.';
        } else {
            if ($project && in_array($project['status'], ['draft', 'rejected'], true)) {
                $stmt = $pdo->prepare('UPDATE projects SET title = ?, description = ?, status = "submitted", submitted_at = NOW() WHERE id = ? AND student_id = ?');
                $stmt->execute([$title, $description ?: null, $project['id'], $uid]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO projects (student_id, title, description, status, submitted_at) VALUES (?, ?, ?, "submitted", NOW())');
                $stmt->execute([$uid, $title, $description ?: null]);
            }
            flash('success', 'Project topic submitted for approval.');
            redirect(base_url('student/project.php'));
        }
    }
}

// Re-fetch project after submit
if (!$project || ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error)) {
    $stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.student_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $stmt->execute([$uid]);
    $project = $stmt->fetch();
}

// List documents and assessments for this project
$documents = [];
$assessments = [];
if ($project) {
    $stmt = $pdo->prepare('SELECT pd.*, (SELECT comment FROM document_feedback WHERE document_id = pd.id ORDER BY created_at DESC LIMIT 1) AS latest_feedback FROM project_documents pd WHERE pd.project_id = ? ORDER BY pd.uploaded_at DESC');
    $stmt->execute([$project['id']]);
    $documents = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT assessment_type, score, max_score, comments, submitted_at FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC');
    $stmt->execute([$project['id']]);
    $assessments = $stmt->fetchAll();
}

// Allowed file types and max size (10MB)
$allowed_doc = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/zip'];
$allowed_ext = ['pdf', 'docx', 'doc', 'zip'];
$max_size = 10 * 1024 * 1024;
$upload_dir = dirname(__DIR__) . '/uploads/projects/' . ($project['id'] ?? 0);
if ($project && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['action']) && $_POST['action'] === 'upload_doc') {
    if (!in_array($project['status'], ['approved', 'in_progress', 'completed'], true)) {
        $error = 'You can upload documents only after your topic is approved.';
    } elseif (!empty($_FILES['doc_file']['name'])) {
        $file = $_FILES['doc_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true) || $file['size'] > $max_size) {
            $error = 'Invalid file type or size. Allowed: PDF, DOCX (max 10MB).';
        } else {
            $doc_type = $_POST['document_type'] ?? 'other';
            if (!in_array($doc_type, ['proposal', 'report', 'zip', 'other'], true)) $doc_type = 'other';
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $safe_name = date('Ymd_His') . '_' . $safe_name;
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $path = $upload_dir . '/' . $safe_name;
            if (move_uploaded_file($file['tmp_name'], $path)) {
                $rel = 'projects/' . $project['id'] . '/' . $safe_name;
                $stmt = $pdo->prepare('INSERT INTO project_documents (project_id, document_type, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$project['id'], $doc_type, $file['name'], $rel, $file['size'], $file['type']]);
                if ($project['supervisor_id']) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$project['supervisor_id'], 'new_upload', 'New document uploaded', 'A student uploaded a document.', base_url('supervisor/students.php?pid=' . $project['id'])]);
                }
                flash('success', 'Document uploaded successfully.');
                redirect(base_url('student/project.php'));
            }
            $error = 'Upload failed.';
        }
    }
}

$pageTitle = 'My Project';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">My Project</h1>

<?php if (!$project): ?>
    <div class="card">
        <div class="card-header">Submit Project Topic</div>
        <div class="card-body">
            <p class="text-muted">Submit your final year project topic for HOD approval.</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_topic">
                <div class="mb-3">
                    <label class="form-label" for="title">Project Title</label>
                    <input type="text" class="form-control" id="title" name="title" required minlength="10" value="<?= e($_POST['title'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Brief Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4"><?= e($_POST['description'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Project Details</span>
            <span class="badge bg-<?= $project['status'] === 'approved' || $project['status'] === 'in_progress' || $project['status'] === 'completed' ? 'success' : ($project['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e($project['status']) ?></span>
        </div>
        <div class="card-body">
            <h5><?= e($project['title']) ?></h5>
            <?php if ($project['description']): ?><p class="text-muted"><?= nl2br(e($project['description'])) ?></p><?php endif; ?>
            <?php if ($project['supervisor_name']): ?><p class="mb-0"><strong>Supervisor:</strong> <?= e($project['supervisor_name']) ?></p><?php endif; ?>
            <?php if ($project['rejection_reason']): ?><p class="text-danger mb-0"><strong>Rejection reason:</strong> <?= e($project['rejection_reason']) ?></p><?php endif; ?>
            <?php if (in_array($project['status'], ['draft', 'rejected'], true)): ?>
                <hr>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit_topic">
                    <div class="mb-3">
                        <label class="form-label" for="title">Project Title</label>
                        <input type="text" class="form-control" id="title" name="title" required minlength="10" value="<?= e($project['title']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Brief Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?= e($project['description'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Resubmit for Approval</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (in_array($project['status'], ['approved', 'in_progress', 'completed'], true)): ?>
    <div class="card">
        <div class="card-header">Project Documents</div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="mb-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_doc">
                <div class="row g-2">
                    <div class="col-md-4">
                        <select name="document_type" class="form-select" required>
                            <option value="proposal">Proposal</option>
                            <option value="report">Report</option>
                            <option value="zip">Zipped Project</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="file" name="doc_file" class="form-control" accept=".pdf,.doc,.docx,.zip" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </div>
                <small class="text-muted">Allowed: PDF, DOCX, DOC, ZIP. Max 10MB.</small>
            </form>
            <?php if (!empty($documents)): ?>
                <table class="table table-sm">
                    <thead><tr><th>File</th><th>Type</th><th>Uploaded</th><th>Feedback</th></tr></thead>
                    <tbody>
                        <?php foreach ($documents as $d): ?>
                            <tr>
                                <td><a href="<?= base_url('download.php?id=' . $d['id']) ?>"><?= e($d['file_name']) ?></a></td>
                                <td><?= e($d['document_type']) ?></td>
                                <td><?= e(date('M j, Y H:i', strtotime($d['uploaded_at']))) ?></td>
                                <td><?= $d['latest_feedback'] ? e($d['latest_feedback']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">No documents uploaded yet.</p>
            <?php endif; ?>
            <?php if (!empty($assessments)): ?>
                <hr>
                <h6>Assessment Scores</h6>
                <table class="table table-sm">
                    <thead><tr><th>Type</th><th>Score</th><th>Comments</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($assessments as $a): ?>
                            <tr>
                                <td><?= e($a['assessment_type']) ?></td>
                                <td><?= $a['score'] !== null ? e($a['score'] . ' / ' . $a['max_score']) : '—' ?></td>
                                <td><?= e($a['comments'] ?? '—') ?></td>
                                <td><?= e(date('M j, Y', strtotime($a['submitted_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
