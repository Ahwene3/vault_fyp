<?php
/**
 * Bulk Supervisor-Student Pairings Upload - HOD feature
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();
$parsed_data = [];
$errors = [];
$total_rows = 0;
$valid_rows = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    // Handle file upload and parsing
    if ($action === 'parse_file' && !empty($_FILES['pairings_file']['name'])) {
        $file = $_FILES['pairings_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            $errors[] = 'Invalid file format. Please upload CSV or Excel file.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File is too large. Maximum 5MB allowed.';
        } elseif (empty($errors)) {
            $fp = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($fp, 0, ',');
            
            $required_cols = ['Student Email', 'Supervisor Email'];
            $headers_normalized = array_map('strtolower', $headers);
            $missing = [];
            foreach ($required_cols as $col) {
                if (!in_array(strtolower($col), $headers_normalized)) {
                    $missing[] = $col;
                }
            }
            
            if (!empty($missing)) {
                $errors[] = 'Missing required columns: ' . implode(', ', $missing);
            } else {
                // Parse rows
                $row_num = 2;
                while (($row = fgetcsv($fp, 0, ',')) !== false) {
                    if (count($row) < 2) continue;
                    
                    $total_rows++;
                    $data = array_combine($headers, $row);
                    
                    $student_email = trim($data['Student Email'] ?? '');
                    $supervisor_email = trim($data['Supervisor Email'] ?? '');
                    
                    $row_errors = [];
                    if (!$student_email || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
                        $row_errors[] = 'Invalid student email';
                    } else {
                        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "student"');
                        $stmt->execute([$student_email]);
                        if (!$stmt->fetch()) $row_errors[] = 'Student not found';
                    }
                    
                    if (!$supervisor_email || !filter_var($supervisor_email, FILTER_VALIDATE_EMAIL)) {
                        $row_errors[] = 'Invalid supervisor email';
                    } else {
                        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "supervisor"');
                        $stmt->execute([$supervisor_email]);
                        if (!$stmt->fetch()) $row_errors[] = 'Supervisor not found';
                    }
                    
                    if (!empty($row_errors)) {
                        $errors[] = "Row $row_num: " . implode(', ', $row_errors);
                    } else {
                        $parsed_data[] = [
                            'row' => $row_num,
                            'student_email' => $student_email,
                            'supervisor_email' => $supervisor_email,
                        ];
                        $valid_rows++;
                    }
                    $row_num++;
                }
                fclose($fp);
            }
        }
    }
    
    // Confirm import
    elseif ($action === 'confirm_import' && !empty($_POST['import_data'])) {
        $import_data = json_decode($_POST['import_data'], true);
        if (!is_array($import_data) || empty($import_data)) {
            $errors[] = 'No valid data to import.';
        } else {
            $success_count = 0;
            $import_errors = [];
            
            foreach ($import_data as $row) {
                try {
                    // Get student and supervisor IDs
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "student"');
                    $stmt->execute([$row['student_email']]);
                    $student = $stmt->fetch();
                    
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "supervisor"');
                    $stmt->execute([$row['supervisor_email']]);
                    $supervisor = $stmt->fetch();
                    
                    if ($student && $supervisor) {
                        // Get the student's approved project
                        $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND status = "approved" AND supervisor_id IS NULL LIMIT 1');
                        $stmt->execute([$student['id']]);
                        $project = $stmt->fetch();
                        
                        if ($project) {
                            // Assign supervisor
                            $pdo->prepare('UPDATE projects SET supervisor_id = ?, status = "in_progress" WHERE id = ?')->execute([$supervisor['id'], $project['id']]);
                            
                            // Notify student
                            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([$student['id'], 'supervisor_assigned', 'Supervisor assigned', 'A supervisor has been assigned to your project.', base_url('student/project.php')]);
                            
                            $success_count++;
                        }
                    }
                } catch (Exception $e) {
                    $import_errors[] = "Row {$row['row']}: " . $e->getMessage();
                }
            }
            
            $_SESSION['import_summary'] = [
                'total' => count($import_data),
                'success' => $success_count,
                'failed' => count($import_data) - $success_count,
                'errors' => $import_errors,
            ];
            
            flash('success', "Assigned $success_count supervisor-student pairings.");
            redirect(base_url('hod/assign.php'));
        }
    }
}

$import_summary = $_SESSION['import_summary'] ?? null;
if ($import_summary) unset($_SESSION['import_summary']);

$pageTitle = 'Import Supervisor Pairings';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Bulk Import Supervisor Pairings</h1>

<?php if (!empty($errors) && empty($parsed_data)): ?>
    <div class="alert alert-danger">
        <strong>Errors Found:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($import_summary): ?>
    <div class="alert <?= $import_summary['failed'] > 0 ? 'alert-warning' : 'alert-success' ?>">
        <strong>Import Complete</strong>
        <p class="mb-1">Total: <?= $import_summary['total'] ?> | Success: <?= $import_summary['success'] ?> | Failed: <?= $import_summary['failed'] ?></p>
        <?php if (!empty($import_summary['errors'])): ?>
            <details class="mt-2">
                <summary>View errors</summary>
                <pre><?php foreach ($import_summary['errors'] as $err): echo e($err) . "\n"; endforeach; ?></pre>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (empty($parsed_data)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Step 1: Upload Pairings File</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="parse_file">
                <div class="mb-3">
                    <label class="form-label">CSV or Excel File (.csv, .xlsx, .xls) *</label>
                    <input type="file" class="form-control" name="pairings_file" required accept=".csv,.xlsx,.xls">
                    <small class="text-muted d-block mt-2">
                        <strong>Required columns:</strong> Student Email, Supervisor Email<br>
                        <strong>Note:</strong> Students must have approved topics and supervisors must be active<br>
                        <strong>Max file size:</strong> 5 MB
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Parse File</button>
            </form>
            
            <div class="mt-4 p-3 bg-light rounded">
                <h6>Example CSV Format:</h6>
                <pre>Student Email,Supervisor Email
john.student@rmu.edu,dr.ahmed.hassan@rmu.edu
mary.smith@rmu.edu,prof.zainab.ali@rmu.edu</pre>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Step 2: Review & Confirm</h5>
            <small class="text-muted d-block">Total rows: <?= $total_rows ?> | Valid: <?= $valid_rows ?> | Errors: <?= count($errors) ?></small>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-warning mb-3">
                    <strong>Validation Errors:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($valid_rows > 0): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Student Email</th>
                                <th>Supervisor Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parsed_data as $row): ?>
                                <tr>
                                    <td><?= $row['row'] ?></td>
                                    <td><?= e($row['student_email']) ?></td>
                                    <td><?= e($row['supervisor_email']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_import">
                    <input type="hidden" name="import_data" value="<?= e(json_encode($parsed_data)) ?>">
                    <button type="submit" class="btn btn-success">Confirm Import</button>
                    <a href="<?= base_url('hod/import_pairings.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
