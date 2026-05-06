<?php
/**
 * HOD — Bulk Group Formation via CSV upload.
 *
 * CSV format (columns may be in any order):
 *   Group ID | Group Name | Student Name | Index Number | Email
 * At minimum: Group ID + (Index Number OR Email).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();
ensure_group_submission_tables($pdo);

$hod_user       = get_user_by_id($uid);
$hod_dept_info  = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_dept_label = $hod_dept_info['name'] ?: $hod_dept_info['raw'];
$dept_store     = $hod_dept_info['id'] !== null ? (string) $hod_dept_info['id'] : ($hod_dept_label ?: null);

$errors       = [];
$preview_data = $_SESSION['hod_group_import_preview'] ?? null;
$summary      = $_SESSION['hod_group_import_summary'] ?? null;
if ($summary) unset($_SESSION['hod_group_import_summary']);

/* ─── POST handlers ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    /* STEP 1 — parse file */
    if ($action === 'parse_file') {
        unset($_SESSION['hod_group_import_preview']);

        $workflow      = in_array($_POST['workflow'] ?? '', ['topic_first', 'direct_proposal'], true)
                         ? $_POST['workflow'] : 'topic_first';
        $academic_year = trim($_POST['academic_year'] ?? date('Y'));
        $batch_ref     = trim($_POST['batch_ref'] ?? '');

        if (empty($_FILES['group_file']['name'])) {
            $errors[] = 'Select a CSV file to upload.';
        } elseif ($_FILES['group_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error. Please try again.';
        } elseif ($_FILES['group_file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File exceeds 5 MB limit.';
        } elseif (strtolower(pathinfo($_FILES['group_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'Only CSV files are accepted. Download the template below.';
        } else {
            $fp = fopen($_FILES['group_file']['tmp_name'], 'r');
            $raw_headers = fgetcsv($fp, 0, ',');

            if (!$raw_headers) {
                $errors[] = 'Cannot read the CSV file.';
                fclose($fp);
            } else {
                $headers = array_map(fn($h) => strtolower(trim((string) $h)), $raw_headers);

                /* find column indices by alias */
                $col = static function (string ...$aliases) use ($headers): int {
                    foreach ($aliases as $alias) {
                        $i = array_search($alias, $headers, true);
                        if ($i !== false) return (int) $i;
                    }
                    return -1;
                };

                $ci_gid   = $col('group id', 'group_id', 'groupid', 'group');
                $ci_gname = $col('group name', 'group_name', 'groupname', 'team', 'team name');
                $ci_sname = $col('student name', 'student_name', 'name', 'full name', 'full_name', 'student');
                $ci_idx   = $col('index number', 'index_number', 'index', 'reg number', 'reg_number',
                                 'registration number', 'student id', 'student_id', 'id number');
                $ci_email = $col('email', 'student email', 'student_email', 'institutional email', 'e-mail');

                if ($ci_gid < 0) {
                    $errors[] = 'Missing required "Group ID" column. Download the template for the correct format.';
                } elseif ($ci_idx < 0 && $ci_email < 0) {
                    $errors[] = 'At least one of "Index Number" or "Email" is required.';
                } else {
                    $groups_map  = []; // [csv_group_id => ['name', 'members'=>[...]]]
                    $row_errors  = [];
                    $total_rows  = 0;
                    $valid_rows  = 0;
                    $row_num     = 2;

                    while (($row = fgetcsv($fp, 0, ',')) !== false) {
                        if (!array_filter(array_map('trim', $row))) { $row_num++; continue; }
                        $total_rows++;

                        $csv_gid   = $ci_gid >= 0   ? trim($row[$ci_gid]   ?? '') : '';
                        $csv_gname = $ci_gname >= 0 ? trim($row[$ci_gname] ?? '') : '';
                        $csv_idx   = $ci_idx >= 0   ? trim($row[$ci_idx]   ?? '') : '';
                        $csv_email = $ci_email >= 0 ? trim($row[$ci_email] ?? '') : '';

                        if (!$csv_gid) {
                            $row_errors[] = "Row $row_num: Missing Group ID."; $row_num++; continue;
                        }
                        if (!$csv_idx && !$csv_email) {
                            $row_errors[] = "Row $row_num: No index number or email."; $row_num++; continue;
                        }

                        /* look up student */
                        $student = null;
                        if ($csv_idx) {
                            $s = $pdo->prepare('SELECT id, full_name, email, reg_number, role, is_active FROM users WHERE reg_number = ? LIMIT 1');
                            $s->execute([$csv_idx]);
                            $student = $s->fetch() ?: null;
                        }
                        if (!$student && $csv_email) {
                            $s = $pdo->prepare('SELECT id, full_name, email, reg_number, role, is_active FROM users WHERE email = ? LIMIT 1');
                            $s->execute([$csv_email]);
                            $student = $s->fetch() ?: null;
                        }

                        if (!$student) {
                            $id = $csv_idx ?: $csv_email;
                            $row_errors[] = "Row $row_num: Student not found ($id). Add the account first.";
                            $row_num++; continue;
                        }
                        if ($student['role'] !== 'student') {
                            $row_errors[] = "Row $row_num: {$student['full_name']} is not a student account.";
                            $row_num++; continue;
                        }

                        $valid_rows++;
                        if (!isset($groups_map[$csv_gid])) {
                            $groups_map[$csv_gid] = [
                                'name'    => $csv_gname ?: ('Group ' . $csv_gid),
                                'members' => [],
                            ];
                        } elseif ($csv_gname && $groups_map[$csv_gid]['name'] === 'Group ' . $csv_gid) {
                            $groups_map[$csv_gid]['name'] = $csv_gname;
                        }

                        /* avoid duplicate student in same group */
                        $already = array_filter($groups_map[$csv_gid]['members'], fn($m) => (int) $m['id'] === (int) $student['id']);
                        if ($already) { $row_num++; continue; }

                        $groups_map[$csv_gid]['members'][] = [
                            'id'         => (int) $student['id'],
                            'full_name'  => $student['full_name'],
                            'email'      => $student['email'],
                            'reg_number' => $student['reg_number'] ?? '',
                            'is_active'  => (bool) $student['is_active'],
                        ];
                        $row_num++;
                    }
                    fclose($fp);

                    $errors = array_merge($errors, $row_errors);

                    if ($valid_rows > 0) {
                        $_SESSION['hod_group_import_preview'] = [
                            'groups'        => $groups_map,
                            'workflow'      => $workflow,
                            'academic_year' => $academic_year,
                            'batch_ref'     => $batch_ref,
                            'total_rows'    => $total_rows,
                            'valid_rows'    => $valid_rows,
                        ];
                        $preview_data = $_SESSION['hod_group_import_preview'];
                    }
                }
            }
        }

    /* STEP 2 — confirm import */
    } elseif ($action === 'confirm_import' && $preview_data) {
        unset($_SESSION['hod_group_import_preview']);

        $workflow      = $preview_data['workflow'];
        $academic_year = $preview_data['academic_year'];
        $batch_ref     = $preview_data['batch_ref'];

        $created = $updated = $members_added = $skipped = 0;
        $import_errors = [];

        $action_label = $workflow === 'direct_proposal' ? 'submit your project proposal' : 'submit your project topic';

        foreach ($preview_data['groups'] as $csv_gid => $gdata) {
            try {
                $group_id = null;
                if ($batch_ref) {
                    $s = $pdo->prepare('SELECT id FROM `groups` WHERE batch_ref = ? LIMIT 1');
                    $s->execute([$batch_ref . '::' . $csv_gid]);
                    $row = $s->fetch();
                    if ($row) $group_id = (int) $row['id'];
                }

                if (!$group_id) {
                    $pdo->prepare(
                        'INSERT INTO `groups` (name, created_by, academic_year, status, workflow, batch_ref, department, is_active)
                         VALUES (?, ?, ?, "formed", ?, ?, ?, 1)'
                    )->execute([
                        $gdata['name'],
                        $uid,
                        $academic_year,
                        $workflow,
                        $batch_ref ? $batch_ref . '::' . $csv_gid : null,
                        $dept_store,
                    ]);
                    $group_id = (int) $pdo->lastInsertId();
                    $created++;
                } else {
                    $updated++;
                }

                foreach ($gdata['members'] as $m) {
                    /* skip if already a member */
                    $chk = $pdo->prepare('SELECT id FROM `group_members` WHERE group_id = ? AND student_id = ? LIMIT 1');
                    $chk->execute([$group_id, $m['id']]);
                    if ($chk->fetch()) { $skipped++; continue; }

                    /* first member = lead */
                    $cnt_s = $pdo->prepare('SELECT COUNT(*) FROM `group_members` WHERE group_id = ?');
                    $cnt_s->execute([$group_id]);
                    $role = (int) $cnt_s->fetchColumn() === 0 ? 'lead' : 'member';

                    $pdo->prepare('INSERT INTO `group_members` (group_id, student_id, role) VALUES (?, ?, ?)')
                        ->execute([$group_id, $m['id'], $role]);
                    $members_added++;

                    notify_user(
                        $m['id'],
                        'group_assigned',
                        'Group Assigned',
                        "You have been assigned to group \"{$gdata['name']}\". Log in to $action_label.",
                        base_url('student/group_submit.php')
                    );
                }
            } catch (Throwable $e) {
                $import_errors[] = "Group {$csv_gid}: " . $e->getMessage();
            }
        }

        $_SESSION['hod_group_import_summary'] = compact(
            'created', 'updated', 'members_added', 'skipped', 'import_errors'
        );
        $preview_data = null;
        flash('success', "Import complete: $created group(s) created, $members_added student(s) assigned.");
        redirect(base_url('hod/group_import.php'));

    } elseif ($action === 'cancel_preview') {
        unset($_SESSION['hod_group_import_preview']);
        $preview_data = null;
        redirect(base_url('hod/group_import.php'));
    }
}

/* ─── Download CSV template ──────────────────────────────────────────────── */
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="group_formation_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Group ID', 'Group Name', 'Student Name', 'Index Number', 'Email']);
    fputcsv($out, ['GRP-001', 'Team Alpha', 'John Doe',   'CS/2021/001', 'john.doe@university.edu']);
    fputcsv($out, ['GRP-001', 'Team Alpha', 'Jane Smith', 'CS/2021/002', 'jane.smith@university.edu']);
    fputcsv($out, ['GRP-002', 'Team Beta',  'Bob Johnson','CS/2021/003', 'bob.j@university.edu']);
    fclose($out);
    exit;
}

/* ─── Recent imports (last 10 groups created by this HOD) ───────────────── */
$recent_groups = $pdo->prepare(
    'SELECT g.id, g.name, g.status, g.workflow, g.academic_year, g.created_at,
            COUNT(gm.id) AS member_count
     FROM `groups` g
     LEFT JOIN `group_members` gm ON gm.group_id = g.id
     WHERE g.created_by = ?
     GROUP BY g.id
     ORDER BY g.created_at DESC
     LIMIT 10'
);
$recent_groups->execute([$uid]);
$recent_groups = $recent_groups->fetchAll();

$pageTitle = 'Group Formation Import';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Group Formation Import</h1>
    <a href="<?= base_url('hod/group_import.php?download=template') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download me-1"></i> Download CSV Template
    </a>
</div>

<?php if ($summary): ?>
    <div class="alert alert-<?= empty($summary['import_errors']) ? 'success' : 'warning' ?> mb-4">
        <strong>Import Complete</strong>
        <div class="mt-1">
            Groups created: <strong><?= $summary['created'] ?></strong> &nbsp;|&nbsp;
            Updated: <strong><?= $summary['updated'] ?></strong> &nbsp;|&nbsp;
            Students assigned: <strong><?= $summary['members_added'] ?></strong> &nbsp;|&nbsp;
            Already in group (skipped): <strong><?= $summary['skipped'] ?></strong>
        </div>
        <?php if (!empty($summary['import_errors'])): ?>
            <details class="mt-2"><summary>View errors</summary>
                <ul class="mb-0 mt-1"><?php foreach ($summary['import_errors'] as $e): ?>
                    <li><?= e($e) ?></li>
                <?php endforeach; ?></ul>
            </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($preview_data): ?>
    <!-- ── STEP 2: PREVIEW & CONFIRM ── -->
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white d-flex justify-content-between">
            <span><i class="bi bi-eye me-1"></i> Step 2: Review & Confirm</span>
            <small>
                <?= $preview_data['total_rows'] ?> rows parsed &nbsp;|&nbsp;
                <?= $preview_data['valid_rows'] ?> valid &nbsp;|&nbsp;
                <?= count($preview_data['groups']) ?> group(s) &nbsp;|&nbsp;
                Workflow: <strong><?= $preview_data['workflow'] === 'direct_proposal' ? 'Direct Proposal' : 'Topic → Proposal' ?></strong>
            </small>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-warning mb-3">
                    <strong>Rows with errors were skipped:</strong>
                    <ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php foreach ($preview_data['groups'] as $csv_gid => $gdata): ?>
                <div class="mb-3 p-3 rounded border">
                    <div class="fw-semibold mb-2">
                        <i class="bi bi-people-fill text-primary me-1"></i>
                        <?= e($gdata['name']) ?>
                        <span class="text-muted fw-normal ms-2 small">(ID: <?= e($csv_gid) ?>)</span>
                        <span class="badge bg-info text-dark ms-2"><?= count($gdata['members']) ?> members</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($gdata['members'] as $m): ?>
                            <span class="badge bg-light text-dark border">
                                <?= e($m['full_name']) ?>
                                <?php if ($m['reg_number']): ?>
                                    <span class="text-muted">(<?= e($m['reg_number']) ?>)</span>
                                <?php endif; ?>
                                <?php if (!$m['is_active']): ?>
                                    <span class="text-warning ms-1" title="Account inactive"><i class="bi bi-exclamation-triangle-fill"></i></span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-flex gap-2 mt-3">
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_import">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i> Confirm & Import
                    </button>
                </form>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_preview">
                    <button type="submit" class="btn btn-outline-secondary">Cancel</button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ── STEP 1: UPLOAD FORM ── -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
            <strong>Upload Errors:</strong>
            <ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-upload me-1"></i> Step 1: Upload Group File</div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="parse_file">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                    <input type="file" name="group_file" class="form-control" accept=".csv" required>
                    <div class="form-text">Required columns: <code>Group ID</code> + (<code>Index Number</code> or <code>Email</code>). Optional: <code>Group Name</code>, <code>Student Name</code>.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Submission Workflow</label>
                    <select name="workflow" class="form-select">
                        <option value="topic_first">Topic → Approval → Proposal</option>
                        <option value="direct_proposal">Direct Proposal Submission</option>
                    </select>
                    <div class="form-text">Applies to all groups in this import.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <input type="text" name="academic_year" class="form-control" value="<?= e(date('Y')) ?>" placeholder="2025">
                </div>

                <div class="col-md-1 d-flex align-items-end">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Batch Reference <span class="text-muted">(optional)</span></label>
                    <input type="text" name="batch_ref" class="form-control" placeholder="e.g. SEM1-2025">
                    <div class="form-text">Helps identify re-imports of the same cohort.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Parse & Preview</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4 bg-light border-0">
        <div class="card-body py-3">
            <h6 class="fw-semibold mb-2">Expected CSV format</h6>
            <pre class="mb-1" style="font-size:.85em;">Group ID,Group Name,Student Name,Index Number,Email
GRP-001,Team Alpha,John Doe,CS/2021/001,john.doe@university.edu
GRP-001,Team Alpha,Jane Smith,CS/2021/002,jane.smith@university.edu
GRP-002,Team Beta,Bob Johnson,CS/2021/003,bob.j@university.edu</pre>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($recent_groups)): ?>
    <div class="card">
        <div class="card-header">Recently Imported Groups</div>
        <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Group Name</th><th>Members</th><th>Status</th><th>Workflow</th><th>Year</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_groups as $g):
                        $status_cls = match($g['status'] ?? 'formed') {
                            'approved'     => 'bg-success',
                            'under_review' => 'bg-info text-dark',
                            'rejected'     => 'bg-danger',
                            default        => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= e($g['name']) ?></td>
                            <td><?= (int) $g['member_count'] ?></td>
                            <td><span class="badge <?= $status_cls ?>"><?= e(ucfirst(str_replace('_',' ',$g['status'] ?? 'formed'))) ?></span></td>
                            <td><small><?= $g['workflow'] === 'direct_proposal' ? 'Direct Proposal' : 'Topic → Proposal' ?></small></td>
                            <td><?= e($g['academic_year'] ?? '—') ?></td>
                            <td><small class="text-muted"><?= $g['created_at'] ? date('M j, Y', strtotime($g['created_at'])) : '—' ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
