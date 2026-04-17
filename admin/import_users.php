<?php
/**
 * Bulk User Import via CSV/Excel - Admin feature
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
$uid = user_id();
$import_id = null;
$import_log = null;
$parsed_data = [];
$errors = [];
$total_rows = 0;
$valid_rows = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    // Handle file upload and parsing
    if ($action === 'parse_file' && !empty($_FILES['import_file']['name'])) {
        $file = $_FILES['import_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            $errors[] = 'Invalid file format. Please upload CSV or Excel file.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File is too large. Maximum 5MB allowed.';
        } elseif (empty($errors)) {
            // Parse CSV or Excel
            $fp = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($fp, 0, ',');
            
            // Validate headers
            $required_cols = ['Full Name', 'Email Address', 'Role', 'Department'];
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
                    if (count($row) < 3) continue;
                    
                    $total_rows++;
                    $data = array_combine($headers, $row);
                    
                    // Validate row
                    $name = trim($data['Full Name'] ?? '');
                    $email = trim($data['Email Address'] ?? '');
                    $role = trim($data['Role'] ?? '');
                    $dept = trim($data['Department'] ?? '');
                    $emp_id = trim($data['Employee/Staff ID'] ?? '');
                    
                    $row_errors = [];
                    if (!$name) $row_errors[] = 'Missing full name';
                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $row_errors[] = 'Invalid email';
                    if (!in_array($role, ['supervisor', 'hod'])) $row_errors[] = 'Invalid role (must be supervisor or hod)';
                    if (!$dept) $row_errors[] = 'Missing department';
                    
                    // Check for duplicate email
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $row_errors[] = 'Email already exists in system';
                    }
                    
                    if (!empty($row_errors)) {
                        $errors[] = "Row $row_num: " . implode(', ', $row_errors);
                    } else {
                        $parsed_data[] = [
                            'row' => $row_num,
                            'name' => $name,
                            'email' => $email,
                            'role' => $role,
                            'dept' => $dept,
                            'emp_id' => $emp_id ?: null,
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
            // Create import log record
            $stmt = $pdo->prepare('INSERT INTO bulk_import_logs (import_type, imported_by, file_name, total_rows, successful_rows, status) VALUES (?, ?, ?, ?, ?, "processing")');
            $stmt->execute(['users', $uid, $_POST['file_name'] ?? 'users_bulk_import.csv', count($import_data), 0]);
            $import_id = $pdo->lastInsertId();
            
            $success_count = 0;
            $import_errors = [];
            
            foreach ($import_data as $row) {
                try {
                    $hash = password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, department, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                    $stmt->execute([$row['email'], $hash, $row['name'], $row['role'], $row['dept']]);
                    $success_count++;
                } catch (Exception $e) {
                    $import_errors[] = "Row {$row['row']} ({$row['email']}): " . $e->getMessage();
                }
            }
            
            // Update import log
            $error_text = !empty($import_errors) ? implode("\n", $import_errors) : null;
            $pdo->prepare('UPDATE bulk_import_logs SET successful_rows = ?, failed_rows = ?, error_details = ?, status = "completed", completed_at = NOW() WHERE id = ?')
                ->execute([$success_count, count($import_data) - $success_count, $error_text, $import_id]);
            
            $_SESSION['import_summary'] = [
                'total' => count($import_data),
                'success' => $success_count,
                'failed' => count($import_data) - $success_count,
                'errors' => $import_errors,
            ];
            
            flash('success', "Imported $success_count users successfully.");
            redirect(base_url('admin/users.php'));
        }
    }
}

$import_summary = $_SESSION['import_summary'] ?? null;
if ($import_summary) unset($_SESSION['import_summary']);

$pageTitle = 'Bulk Import Users';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Bulk Import Users</h1>

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
    <!-- File Upload Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Step 1: Upload File</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="parse_file">
                <div class="mb-3">
                    <label class="form-label">CSV or Excel File (.csv, .xlsx, .xls) *</label>
                    <input type="file" class="form-control" name="import_file" required accept=".csv,.xlsx,.xls">
                    <small class="text-muted d-block mt-2">
                        <strong>Required columns:</strong> Full Name, Email Address, Role, Department<br>
                        <strong>Optional columns:</strong> Employee/Staff ID<br>
                        <strong>Role values:</strong> supervisor, hod<br>
                        <strong>Max file size:</strong> 5 MB
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Parse File</button>
            </form>
            
            <div class="mt-4 p-3 bg-light rounded">
                <h6>Example CSV Format:</h6>
                <pre>Full Name,Email Address,Role,Department,Employee/Staff ID
Dr. Ahmed Hassan,ahmed.hassan@rmu.edu,supervisor,Marine Engineering,EMP001
Prof. Zainab Ali,zainab.ali@rmu.edu,hod,Shipping & Logistics,EMP002</pre>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Preview & Confirmation -->
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
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Emp. ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parsed_data as $row): ?>
                                <tr>
                                    <td><?= $row['row'] ?></td>
                                    <td><?= e($row['name']) ?></td>
                                    <td><?= e($row['email']) ?></td>
                                    <td><span class="badge bg-info"><?= e($row['role']) ?></span></td>
                                    <td><?= e($row['dept']) ?></td>
                                    <td><?= e($row['emp_id'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_import">
                    <input type="hidden" name="import_data" value="<?= e(json_encode($parsed_data)) ?>">
                    <input type="hidden" name="file_name" value="users_bulk_import.csv">
                    <button type="submit" class="btn btn-success">Confirm Import</button>
                    <a href="<?= base_url('admin/import_users.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
