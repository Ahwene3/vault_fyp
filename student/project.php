<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();

function fetch_group_project(PDO $pdo, int $group_id): ?array {
    $stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.group_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $stmt->execute([$group_id]);
    $project = $stmt->fetch();
    if ($project) {
        return $project;
    }

    // Backfill legacy records: if creator submitted before group linking, attach that project.
    $stmt = $pdo->prepare('SELECT created_by FROM `groups` WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$group_id]);
    $creator_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND (group_id IS NULL OR group_id = ?) ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$creator_id, $group_id]);
    $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
    if ($creator_project_id <= 0) {
        return null;
    }

    $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([$group_id, $creator_project_id, $group_id]);

    $stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.id = ? LIMIT 1');
    $stmt->execute([$creator_project_id]);
    $project = $stmt->fetch();

    return $project ?: null;
}

// Determine if student belongs to a group (max 5 members flow)
$stmt = $pdo->prepare('SELECT gm.group_id, g.name FROM `group_members` gm JOIN `groups` g ON g.id = gm.group_id WHERE gm.student_id = ? AND g.is_active = 1 LIMIT 1');
$stmt->execute([$uid]);
$current_group = $stmt->fetch();
$group_id = $current_group ? (int) $current_group['group_id'] : null;

$project = null;
if ($group_id) {
    $project = fetch_group_project($pdo, $group_id);
}
if (!$project && !$group_id) {
    $stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.student_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $stmt->execute([$uid]);
    $project = $stmt->fetch();
}

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
                if ($group_id && (int) ($project['group_id'] ?? 0) === $group_id) {
                    $stmt = $pdo->prepare('UPDATE projects SET title = ?, description = ?, status = "submitted", submitted_at = NOW() WHERE id = ? AND group_id = ?');
                    $stmt->execute([$title, $description ?: null, $project['id'], $group_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE projects SET title = ?, description = ?, status = "submitted", submitted_at = NOW() WHERE id = ? AND student_id = ?');
                    $stmt->execute([$title, $description ?: null, $project['id'], $uid]);
                }
            } else {
                if ($group_id) {
                    // Use group lead as project owner for group projects
                    $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ? ORDER BY CASE WHEN role = "lead" THEN 0 ELSE 1 END, id ASC LIMIT 1');
                    $stmt->execute([$group_id]);
                    $group_owner = (int) ($stmt->fetchColumn() ?: $uid);
                    $stmt = $pdo->prepare('INSERT INTO projects (student_id, group_id, title, description, status, submitted_at) VALUES (?, ?, ?, ?, "submitted", NOW())');
                    $stmt->execute([$group_owner, $group_id, $title, $description ?: null]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO projects (student_id, title, description, status, submitted_at) VALUES (?, ?, ?, "submitted", NOW())');
                    $stmt->execute([$uid, $title, $description ?: null]);
                }
            }
            flash('success', 'Project topic submitted for approval.');
            redirect(base_url('student/project.php'));
        }
    }
}

// Re-fetch project after submit
if (!$project || ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error)) {
    if ($group_id) {
        $project = fetch_group_project($pdo, $group_id);
    }
    if (!$project && !$group_id) {
        $stmt = $pdo->prepare('SELECT p.*, u.full_name AS supervisor_name FROM projects p LEFT JOIN users u ON p.supervisor_id = u.id WHERE p.student_id = ? ORDER BY p.updated_at DESC LIMIT 1');
        $stmt->execute([$uid]);
        $project = $stmt->fetch();
    }
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
            $doc_type_input = $_POST['document_type'] ?? 'other';
            $doc_type = $doc_type_input === 'documentation' ? 'proposal' : $doc_type_input;
            if (!in_array($doc_type, ['proposal', 'report', 'zip', 'other'], true)) $doc_type = 'other';
            
            // Require chapter when uploading documentation
            $chapter = null;
            if ($doc_type === 'proposal') {
                $chapter = $_POST['chapter'] ?? null;
                if (!$chapter || !in_array($chapter, ['chapter1', 'chapter2', 'chapter3', 'chapter4', 'chapter5'], true)) {
                    $error = 'Please select a chapter for documentation.';
                }
            }
            
            if (!$error) {
                // Calculate next version number for this document type
                $stmt = $pdo->prepare('SELECT MAX(version_number) as max_ver FROM project_documents WHERE project_id = ? AND document_type = ?');
                $stmt->execute([$project['id'], $doc_type]);
                $result = $stmt->fetch();
                $next_version = ($result['max_ver'] ?? 0) + 1;
                
                $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                $safe_name = date('Ymd_His') . '_' . $safe_name;
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $path = $upload_dir . '/' . $safe_name;
                if (move_uploaded_file($file['tmp_name'], $path)) {
                    $rel = 'projects/' . $project['id'] . '/' . $safe_name;
                    
                    // Mark previous versions as not latest
                    $pdo->prepare('UPDATE project_documents SET is_latest = 0 WHERE project_id = ? AND document_type = ? AND is_latest = 1')->execute([$project['id'], $doc_type]);
                    
                    // Insert new version with chapter if applicable
                    $stmt = $pdo->prepare('INSERT INTO project_documents (project_id, document_type, chapter, file_name, file_path, file_size, mime_type, uploader_id, version_number, is_latest) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                    $stmt->execute([$project['id'], $doc_type, $chapter, $file['name'], $rel, $file['size'], $file['type'], $uid, $next_version]);
                    if ($project['supervisor_id']) {
                        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$project['supervisor_id'], 'new_upload', 'New document uploaded', 'A student uploaded a document.', base_url('supervisor/students.php?pid=' . $project['id'])]);
                    }
                    flash('success', 'Document uploaded successfully.');
                    redirect(base_url('student/project.php'));
                } else {
                    $error = 'Upload failed. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'My Project';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">My Project</h1>

<?php if ($current_group): ?>
    <div class="alert alert-info">
        Working as group: <strong><?= e($current_group['name']) ?></strong> (max 5 members). All members can view updates, feedback, and assessments.
    </div>
<?php endif; ?>

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
        <div class="card-header">Project Documents & Documentation</div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><strong>Upload Error:</strong> <?= e($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="mb-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_doc">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><strong>Document Type</strong></label>
                        <select name="document_type" id="docType" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <option value="documentation">Documentation</option>
                            <option value="report">Report</option>
                            <option value="zip">Zipped Project</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="chapterDiv" style="display:none;">
                        <label class="form-label"><strong>Chapter/Section</strong></label>
                        <select name="chapter" id="chapter" class="form-select">
                            <option value="">-- Select Chapter --</option>
                            <option value="chapter1">Chapter 1: Introduction</option>
                            <option value="chapter2">Chapter 2: Literature Review</option>
                            <option value="chapter3">Chapter 3: Methodology</option>
                            <option value="chapter4">Chapter 4: Results & Analysis</option>
                            <option value="chapter5">Chapter 5: Conclusion</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><strong>File</strong></label>
                        <input type="file" name="doc_file" class="form-control" accept=".pdf,.doc,.docx,.zip" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Upload File</button>
                    </div>
                </div>
                <small class="text-muted d-block mt-2"><i class="bi bi-info-circle"></i> Allowed formats: PDF, DOCX, DOC, ZIP (Max 10MB)</small>
            </form>
            <script>
                document.getElementById('docType').addEventListener('change', function() {
                    const chapterDiv = document.getElementById('chapterDiv');
                    const chapterSelect = document.getElementById('chapter');
                    if (this.value === 'documentation') {
                        chapterDiv.style.display = 'block';
                        chapterSelect.required = true;
                    } else {
                        chapterDiv.style.display = 'none';
                        chapterSelect.required = false;
                        chapterSelect.value = '';
                    }
                });
            </script>
            <?php if (!empty($documents)): ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>File</th><th>Type</th><th>Chapter/Section</th><th>Uploaded</th><th>Feedback</th></tr></thead>
                    <tbody>
                        <?php foreach ($documents as $d): ?>
                            <tr>
                                <td><a href="<?= base_url('download.php?id=' . $d['id']) ?>" class="text-decoration-none"><i class="bi bi-file-pdf"></i> <?= e($d['file_name']) ?></a></td>
                                <td><span class="badge bg-info"><?= e($d['document_type'] === 'proposal' ? 'Documentation' : ucfirst($d['document_type'])) ?></span></td>
                                <td><?= isset($d['chapter']) && $d['chapter'] ? e(str_replace('chapter', 'Chapter ', ucfirst($d['chapter']))) : '—' ?></td>
                                <td><small><?= e(date('M j, Y H:i', strtotime($d['uploaded_at']))) ?></small></td>
                                <td><?= $d['latest_feedback'] ? '<small class="text-success"><i class="bi bi-check-circle"></i> ' . e(substr($d['latest_feedback'], 0, 30)) . '...</small>' : '<small class="text-muted">—</small>' ?></td>
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
