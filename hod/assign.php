<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('hod');

$pdo = getPDO();

$uid = user_id();
$hod_user = get_user_by_id($uid);
$hod_department_info = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_department_variants = $hod_department_info['variants'];
$hod_department_label = $hod_department_info['name'] ?: $hod_department_info['raw'];
$department_scope_error = empty($hod_department_variants) ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

$supervisors = [];
$unassigned = [];
if (!empty($hod_department_variants)) {
    $dept_placeholders = sql_placeholders(count($hod_department_variants));

    $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE role = "supervisor" AND is_active = 1 AND LOWER(TRIM(COALESCE(department, ""))) IN (' . $dept_placeholders . ') ORDER BY full_name');
    $stmt->execute($hod_department_variants);
    $supervisors = $stmt->fetchAll();

    $sql = 'SELECT p.id, p.title, p.group_id, u.full_name AS student_name, u.reg_number, u.email, g.name AS group_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS member_directory,
        (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
        (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
        (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.status = "approved" AND p.supervisor_id IS NULL AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')
        ORDER BY p.approved_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hod_department_variants);
    $unassigned = $stmt->fetchAll();
}

function assign_member_ids(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare('SELECT student_id, group_id FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) {
        return [];
    }

    $ids = [(int) $project['student_id']];
    if (!empty($project['group_id'])) {
        $stmt = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $stmt->execute([(int) $project['group_id']]);
        foreach ($stmt->fetchAll() as $m) {
            $ids[] = (int) $m['student_id'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (empty($hod_department_variants)) {
        flash('error', 'HOD department is not configured.');
        redirect(base_url('hod/assign.php'));
    }

    if (($_POST['action'] ?? '') === 'auto_assign') {
        if (empty($supervisors) || empty($unassigned)) {
            flash('error', empty($supervisors) ? 'No active supervisors in your department.' : 'No unassigned projects to process.');
            redirect(base_url('hod/assign.php'));
        }

        // Build current assignment counts to balance the round-robin
        $sup_ids = array_column($supervisors, 'id');
        $count_stmt = $pdo->prepare('SELECT supervisor_id, COUNT(*) AS cnt FROM projects WHERE supervisor_id IN (' . sql_placeholders(count($sup_ids)) . ') AND status NOT IN ("archived") GROUP BY supervisor_id');
        $count_stmt->execute($sup_ids);
        $load = [];
        foreach ($count_stmt->fetchAll() as $row) {
            $load[(int) $row['supervisor_id']] = (int) $row['cnt'];
        }

        // Sort supervisors by workload ascending for initial order
        $sorted_sups = $supervisors;
        usort($sorted_sups, static function (array $a, array $b) use ($load): int {
            return ($load[$a['id']] ?? 0) <=> ($load[$b['id']] ?? 0);
        });

        $sup_count = count($sorted_sups);
        $assigned_count = 0;
        foreach ($unassigned as $i => $p) {
            $chosen = $sorted_sups[$i % $sup_count];
            $dept_ph = sql_placeholders(count($hod_department_variants));
            $chk = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND status = "approved" AND supervisor_id IS NULL LIMIT 1');
            $chk->execute([$p['id']]);
            if (!$chk->fetchColumn()) {
                continue;
            }
            $upd = $pdo->prepare('UPDATE projects SET supervisor_id = ?, status = "in_progress" WHERE id = ? AND status = "approved" AND supervisor_id IS NULL');
            $upd->execute([$chosen['id'], $p['id']]);
            if ($upd->rowCount()) {
                $assigned_count++;
                foreach (assign_member_ids($pdo, (int) $p['id']) as $member_id) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                        $member_id, 'supervisor_assigned', 'Supervisor assigned',
                        'A supervisor has been assigned to your project: ' . $chosen['full_name'],
                        base_url('student/project.php')
                    ]);
                }
            }
        }
        flash('success', "Round-robin auto-assignment complete. {$assigned_count} project(s) assigned.");
        redirect(base_url('hod/assign.php'));
    }

    $project_id = (int) ($_POST['project_id'] ?? 0);
    $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
    if ($project_id && $supervisor_id) {
        $dept_placeholders = sql_placeholders(count($hod_department_variants));

        // Ensure project belongs to HOD department.
        $stmt = $pdo->prepare('SELECT p.id FROM projects p JOIN users u ON p.student_id = u.id WHERE p.id = ? AND p.status = "approved" AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ') LIMIT 1');
        $stmt->execute(array_merge([$project_id], $hod_department_variants));
        $project_ok = (bool) $stmt->fetchColumn();

        // Ensure selected supervisor belongs to same department scope.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "supervisor" AND is_active = 1 AND LOWER(TRIM(COALESCE(department, ""))) IN (' . $dept_placeholders . ') LIMIT 1');
        $stmt->execute(array_merge([$supervisor_id], $hod_department_variants));
        $supervisor_ok = (bool) $stmt->fetchColumn();

        if (!$project_ok || !$supervisor_ok) {
            flash('error', 'Project or supervisor is outside your department scope.');
            redirect(base_url('hod/assign.php'));
        }

        $stmt = $pdo->prepare('UPDATE projects SET supervisor_id = ?, status = "in_progress" WHERE id = ? AND status = "approved"');
        $stmt->execute([$supervisor_id, $project_id]);
        if ($stmt->rowCount()) {
            $stmt = $pdo->prepare('SELECT student_id FROM projects WHERE id = ?');
            $stmt->execute([$project_id]);
            $row = $stmt->fetch();
            if ($row) {
                foreach (assign_member_ids($pdo, $project_id) as $member_id) {
                    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                        $member_id,
                        'supervisor_assigned',
                        'Supervisor assigned',
                        'A supervisor has been assigned to your project.',
                        base_url('student/project.php')
                    ]);
                }
            }
            flash('success', 'Supervisor assigned.');
            redirect(base_url('hod/assign.php'));
        }
    }
}

$pageTitle = 'Assign Supervisors';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">Assign Supervisors</h1>

<?php if ($department_scope_error): ?>
    <div class="alert alert-danger"><?= e($department_scope_error) ?></div>
<?php else: ?>
    <div class="alert alert-info">Department scope: <strong><?= e($hod_department_label ?: 'Unknown') ?></strong></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Projects Without Supervisor</span>
        <?php if (!empty($unassigned) && !empty($supervisors)): ?>
            <form method="post" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="auto_assign">
                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Auto-assign all <?= count($unassigned) ?> unassigned project(s) to <?= count($supervisors) ?> supervisor(s) using round-robin?')">
                    <i class="bi bi-shuffle"></i> Auto-Assign All (Round-Robin)
                </button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($unassigned)): ?>
            <p class="text-muted mb-0">All approved projects have a supervisor assigned.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Group Vault</th><th>Members / Index</th><th>Project Title</th><th>Input</th><th>Assign</th></tr></thead>
                <tbody>
                    <?php foreach ($unassigned as $p): ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <span class="badge bg-info text-dark">Group Vault: <?= e($p['group_name'] ?: ('#' . $p['group_id'])) ?></span>
                                    <small class="text-muted d-block">Lead: <?= e($p['student_name']) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Solo Vault</span>
                                    <small class="text-muted d-block"><?= e($p['student_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['group_id'])): ?>
                                    <small><?= e($p['member_directory'] ?: '—') ?></small>
                                <?php else: ?>
                                    <?= e(($p['student_name'] ?? '—') . ' (' . ($p['reg_number'] ?: $p['email']) . ')') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><small class="text-muted">Docs <?= (int) ($p['docs_count'] ?? 0) ?> | Logbook <?= (int) ($p['logbook_count'] ?? 0) ?> | Msg <?= (int) ($p['message_count'] ?? 0) ?></small></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                    <select name="supervisor_id" class="form-select form-select-sm d-inline-block w-auto">
                                        <option value="">Select...</option>
                                        <?php foreach ($supervisors as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= e($s['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Assign</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
