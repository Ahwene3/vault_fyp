<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();

// Backfill legacy records where group creator submitted before project.group_id was set.
$stmt = $pdo->prepare('SELECT p.id AS project_id, g.id AS group_id
    FROM projects p
    JOIN `groups` g ON g.created_by = p.student_id AND g.is_active = 1
    WHERE p.supervisor_id = ? AND p.group_id IS NULL');
$stmt->execute([$uid]);
foreach ($stmt->fetchAll() as $candidate) {
    $check = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? LIMIT 1');
    $check->execute([(int) $candidate['group_id']]);
    $existing_group_project = (int) ($check->fetchColumn() ?: 0);
    if ($existing_group_project === 0) {
        $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND group_id IS NULL')->execute([(int) $candidate['group_id'], (int) $candidate['project_id']]);
    }
}

$stmt = $pdo->prepare('SELECT p.id, p.title, p.status, p.submitted_at, p.group_id, u.id AS student_id, u.full_name AS student_name, u.email, u.reg_number, g.name AS group_name,
    (SELECT COUNT(*) FROM `group_members` gm WHERE gm.group_id = p.group_id) AS group_size,
    (SELECT GROUP_CONCAT(u2.full_name ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
        FROM `group_members` gm2
        JOIN users u2 ON u2.id = gm2.student_id
        WHERE gm2.group_id = p.group_id) AS group_member_names,
    (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
        FROM `group_members` gm2
        JOIN users u2 ON u2.id = gm2.student_id
        WHERE gm2.group_id = p.group_id) AS group_member_directory,
    (SELECT COUNT(*) FROM project_documents pd WHERE pd.project_id = p.id) AS docs_count,
    (SELECT COUNT(*) FROM logbook_entries le WHERE le.project_id = p.id) AS logbook_count,
    (SELECT COUNT(*) FROM messages m WHERE m.project_id = p.id) AS message_count
    FROM projects p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN `groups` g ON g.id = p.group_id
    WHERE p.supervisor_id = ?
    ORDER BY p.updated_at DESC');
$stmt->execute([$uid]);
$students = $stmt->fetchAll();

$pageTitle = 'Group Vaults';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="dashboard-hero">
    <div>
        <div class="dashboard-hero__eyebrow">Supervisor Workspace</div>
        <h1 class="dashboard-hero__title mb-2">Group Vaults</h1>
        <p class="dashboard-hero__copy mb-0">Review assigned teams, inspect progress, and jump into feedback without leaving the workspace.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="<?= base_url('messages.php') ?>" class="btn btn-warning dashboard-hero__btn">Open Messages</a>
    </div>
</section>

<section class="dashboard-main-panels">
    <div class="card mb-4 supervisor-vaults-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Assigned Group Vaults</span>
            <a href="<?= base_url('dashboard.php') ?>" class="supervisor-view-all-link">Back to dashboard <span aria-hidden="true">→</span></a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($students)): ?>
                <p class="text-muted mb-0 px-3 py-4">No group vaults assigned to you yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle supervisor-vaults-table mb-0">
                        <thead>
                            <tr>
                                <th>Group Vault</th>
                                <th>Members / Index No.</th>
                                <th>Project Title</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($s['group_id'])): ?>
                                            <span class="supervisor-vault-badge">Group Vault: <?= e($s['group_name'] ?: ('#' . $s['group_id'])) ?></span>
                                            <small class="text-muted d-block">Lead: <?= e($s['student_name']) ?></small>
                                            <small class="text-muted d-block"><?= (int) ($s['group_size'] ?? 0) ?> member(s)</small>
                                        <?php else: ?>
                                            <span class="supervisor-vault-badge">Solo Vault</span>
                                            <small class="text-muted d-block"><?= e($s['student_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['group_id'])): ?>
                                            <small><?= e($s['group_member_directory'] ?: '—') ?></small>
                                        <?php else: ?>
                                            <?= e(($s['student_name'] ?? '—') . ' (' . ($s['reg_number'] ?: $s['email']) . ')') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="supervisor-project-title"><?= e($s['title']) ?></td>
                                    <td>
                                        <small class="text-muted">
                                            Docs: <?= (int) ($s['docs_count'] ?? 0) ?> |
                                            Logbook: <?= (int) ($s['logbook_count'] ?? 0) ?> |
                                            Messages: <?= (int) ($s['message_count'] ?? 0) ?>
                                        </small>
                                    </td>
                                    <td><span class="supervisor-status-pill"><?= e(ucfirst(str_replace('_', ' ', (string) $s['status'])) ?: 'Unknown') ?></span></td>
                                    <td>
                                        <a href="<?= base_url('supervisor/student_detail.php?pid=' . $s['id']) ?>" class="btn btn-sm btn-outline-primary">View &amp; Rate</a>
                                        <a href="<?= base_url('messages.php?pid=' . $s['id'] . '&with=' . $s['student_id']) ?>" class="btn btn-sm btn-outline-secondary">Message</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
