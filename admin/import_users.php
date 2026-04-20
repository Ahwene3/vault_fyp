<?php
/**
 * Bulk User Import via CSV/Excel - Admin feature
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
$uid = user_id();
ensure_departments_table($pdo);
$import_id = null;
$import_log = null;
$parsed_data = [];
$errors = [];
$total_rows = 0;
$valid_rows = 0;
$uploaded_file_name = '';

function import_random_password(): string {
    return 'Temp#' . strtoupper(bin2hex(random_bytes(4))) . 'a1';
}

function notify_import_issue(PDO $pdo, int $admin_id, string $title, string $message): void {
    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')
        ->execute([$admin_id, 'system_error', $title, $message, base_url('admin/reports.php')]);
}

function stream_csv_download(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $download = (string) $_GET['download'];

    if ($download === 'template') {
        stream_csv_download(
            'users_import_template.csv',
            ['Full Name', 'Email Address', 'Role', 'Department', 'Employee/Staff ID', 'Password'],
            [
                ['Dr. Ahmed Hassan', 'ahmed.hassan@rmu.edu', 'supervisor', 'Marine Engineering Department', 'EMP001', 'Temp#A1B2C3D4a1'],
                ['Prof. Zainab Ali', 'zainab.ali@rmu.edu', 'hod', 'Department of Transport', 'EMP002', ''],
            ]
        );
    }

    if ($download === 'credentials') {
        $payload = $_SESSION['last_import_credentials'] ?? null;
        if (!is_array($payload) || empty($payload['rows'])) {
            flash('error', 'No recent credentials file is available to download.');
            redirect(base_url('admin/import_users.php'));
        }

        $rows = [];
        foreach ($payload['rows'] as $r) {
            $rows[] = [
                $r['name'] ?? '',
                $r['email'] ?? '',
                $r['role'] ?? '',
                $r['department'] ?? '',
                $r['emp_id'] ?? '',
                $r['password'] ?? '',
            ];
        }

        stream_csv_download(
            (string) ($payload['file_name'] ?? ('import_credentials_' . date('Ymd_His') . '.csv')),
            ['Full Name', 'Email Address', 'Role', 'Department', 'Employee/Staff ID', 'Password'],
            $rows
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    // Handle file upload and parsing
    if ($action === 'parse_file' && !empty($_FILES['import_file']['name'])) {
        $file = $_FILES['import_file'];
        $uploaded_file_name = (string) $file['name'];
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
                $file_hod_departments = [];
                $file_seen_emails = [];
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
                    $password_plain = trim((string) (($data['Password'] ?? '') !== '' ? $data['Password'] : ($data['Temporary Password'] ?? '')));
                    $dept_info = resolve_department_info($pdo, $dept);
                    
                    $row_errors = [];
                    if (!$name) $row_errors[] = 'Missing full name';
                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $row_errors[] = 'Invalid email';
                    if (!in_array($role, ['supervisor', 'hod'])) $row_errors[] = 'Invalid role (must be supervisor or hod)';
                    if (!$dept) $row_errors[] = 'Missing department';
                    if (!$dept || empty($dept_info['variants'])) $row_errors[] = 'Invalid or inactive department';
                    if ($password_plain !== '' && strlen($password_plain) < 8) $row_errors[] = 'Password must be at least 8 characters when provided';

                    $email_key = strtolower($email);
                    if ($email && isset($file_seen_emails[$email_key])) {
                        $row_errors[] = 'Duplicate email in upload file';
                    }
                    
                    // Check for duplicate email
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $row_errors[] = 'Email already exists in system';
                    }

                    if ($role === 'hod' && !$row_errors) {
                        if (has_other_active_hod_in_department($pdo, $dept)) {
                            $row_errors[] = 'Department already has an active HOD';
                        }
                        $dept_key = strtolower((string) ($dept_info['name'] ?? $dept_info['raw']));
                        if (isset($file_hod_departments[$dept_key])) {
                            $row_errors[] = 'CSV includes multiple HOD rows for the same department';
                        }
                    }
                    
                    if (!empty($row_errors)) {
                        $errors[] = "Row $row_num: " . implode(', ', $row_errors);
                    } else {
                        $dept_to_store = $dept_info['id'] !== null ? (string) $dept_info['id'] : $dept;
                        $parsed_data[] = [
                            'row' => $row_num,
                            'name' => $name,
                            'email' => $email,
                            'role' => $role,
                            'dept' => $dept_to_store,
                            'emp_id' => $emp_id ?: null,
                            'password' => $password_plain !== '' ? $password_plain : null,
                        ];
                        if ($email) {
                            $file_seen_emails[$email_key] = true;
                        }
                        if ($role === 'hod') {
                            $dept_key = strtolower((string) ($dept_info['name'] ?? $dept_info['raw']));
                            $file_hod_departments[$dept_key] = true;
                        }
                        $valid_rows++;
                    }
                    $row_num++;
                }
                fclose($fp);

                if (!empty($errors)) {
                    notify_import_issue(
                        $pdo,
                        $uid,
                        'User import parsing encountered errors',
                        'File ' . ($uploaded_file_name !== '' ? $uploaded_file_name : 'upload') . ' has ' . count($errors) . ' validation issue(s).'
                    );
                }
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
            $import_hod_departments = [];
            $credential_exports = [];
            
            foreach ($import_data as $row) {
                try {
                    if (($row['role'] ?? '') === 'hod') {
                        $dept_info = resolve_department_info($pdo, (string) ($row['dept'] ?? ''));
                        $dept_key = strtolower((string) ($dept_info['name'] ?? $dept_info['raw']));
                        if ($dept_key === '') {
                            throw new RuntimeException('Invalid department for HOD row');
                        }
                        if (has_other_active_hod_in_department($pdo, (string) ($row['dept'] ?? ''))) {
                            throw new RuntimeException('Department already has an active HOD');
                        }
                        if (isset($import_hod_departments[$dept_key])) {
                            throw new RuntimeException('Import contains duplicate HOD for same department');
                        }
                        $import_hod_departments[$dept_key] = true;
                    }

                    $plain_password = trim((string) ($row['password'] ?? ''));
                    if ($plain_password === '') {
                        $plain_password = import_random_password();
                    }

                    $hash = password_hash($plain_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, department, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                    $stmt->execute([$row['email'], $hash, $row['name'], $row['role'], $row['dept']]);
                    $success_count++;

                    $credential_exports[] = [
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'role' => $row['role'],
                        'department' => get_department_display_name($pdo, (string) $row['dept']),
                        'emp_id' => $row['emp_id'] ?? '',
                        'password' => $plain_password,
                    ];
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
                'credentials_count' => count($credential_exports),
            ];

            if (!empty($credential_exports)) {
                $_SESSION['last_import_credentials'] = [
                    'file_name' => 'import_credentials_' . date('Ymd_His') . '.csv',
                    'rows' => $credential_exports,
                ];
            }

            if (!empty($import_errors)) {
                notify_import_issue(
                    $pdo,
                    $uid,
                    'User import completed with failures',
                    count($import_errors) . ' row(s) failed during import. Open admin reports for details.'
                );
            }
            
            flash('success', "Imported $success_count users successfully.");
            redirect(base_url('admin/import_users.php'));
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
        <?php if (!empty($import_summary['credentials_count'])): ?>
            <a href="<?= base_url('admin/import_users.php?download=credentials') ?>" class="btn btn-sm btn-outline-primary mt-2">Download Credentials CSV</a>
        <?php endif; ?>
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
                        <strong>Optional columns:</strong> Employee/Staff ID, Password (or Temporary Password)<br>
                        <strong>Role values:</strong> supervisor, hod<br>
                        <strong>Max file size:</strong> 5 MB
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Parse File</button>
                <a href="<?= base_url('admin/import_users.php?download=template') ?>" class="btn btn-outline-secondary ms-2">Download Template</a>
            </form>
            
            <div class="mt-4 p-3 bg-light rounded">
                <h6>Example CSV Format:</h6>
                <pre>Full Name,Email Address,Role,Department,Employee/Staff ID,Password
Dr. Ahmed Hassan,ahmed.hassan@rmu.edu,supervisor,Marine Engineering Department,EMP001,Temp#A1B2C3D4a1
Prof. Zainab Ali,zainab.ali@rmu.edu,hod,Department of Transport,EMP002,</pre>
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
                                <th>Password</th>
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
                                    <td>
                                        <?php if (!empty($row['password'])): ?>
                                            <span class="badge bg-secondary">Provided</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Auto-generate</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_import">
                    <input type="hidden" name="import_data" value="<?= e(json_encode($parsed_data)) ?>">
                    <input type="hidden" name="file_name" value="<?= e($uploaded_file_name !== '' ? $uploaded_file_name : 'users_bulk_import.csv') ?>">
                    <button type="submit" class="btn btn-success">Confirm Import</button>
                    <a href="<?= base_url('admin/import_users.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
