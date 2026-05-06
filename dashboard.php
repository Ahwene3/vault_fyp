<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$role = $user['role'];
$uid = (int) $user['id'];
$pdo = getPDO();

// Role-specific dashboard data
$stats = [];
if ($role === 'student') {
    ensure_student_tracking_columns($pdo);
    $tracking_stmt = $pdo->prepare('SELECT student_project_status, repeat_required FROM users WHERE id = ? LIMIT 1');
    $tracking_stmt->execute([$uid]);
    $student_tracking = $tracking_stmt->fetch() ?: ['student_project_status' => 'active', 'repeat_required' => 0];
    $stats['student_project_status'] = $student_tracking['student_project_status'] ?? 'active';
    $stats['repeat_required'] = (bool) ($student_tracking['repeat_required'] ?? 0);
}
if ($role === 'student') {
    ensure_group_submission_tables($pdo);
    // Get student's group — prefer HOD-formed (batch_ref set) then any active group
    $stmt = $pdo->prepare(
        'SELECT g.id, g.name, g.created_by, g.status AS group_status, g.workflow, g.batch_ref,
                g.supervisor_id, COUNT(gm.id) AS member_count
         FROM `groups` g
         LEFT JOIN `group_members` gm ON g.id = gm.group_id
         WHERE g.id IN (SELECT group_id FROM `group_members` WHERE student_id = ?)
           AND g.is_active = 1
         GROUP BY g.id
         ORDER BY (g.batch_ref IS NOT NULL) DESC, g.created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$uid]);
    $stats['group'] = $stmt->fetch();

    // HOD-formed group lifecycle info
    $stats['hod_group'] = null;
    if (!empty($stats['group']['batch_ref'])) {
        $stats['hod_group'] = $stats['group'];
        // Latest submission for this group
        $ls = $pdo->prepare(
            'SELECT type, title, status, rejection_reason, submitted_at
             FROM group_submissions WHERE group_id=? ORDER BY submitted_at DESC LIMIT 1'
        );
        $ls->execute([(int) $stats['group']['id']]);
        $stats['hod_group']['latest_submission'] = $ls->fetch() ?: null;
        // Supervisor name
        if (!empty($stats['group']['supervisor_id'])) {
            $sv = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
            $sv->execute([$stats['group']['supervisor_id']]);
            $stats['hod_group']['supervisor_name'] = (string) ($sv->fetchColumn() ?: '');
        }
    }

    if (!empty($stats['group']['id'])) {
        $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE group_id = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([(int) $stats['group']['id']]);
        $stats['project'] = $stmt->fetch();

        if (empty($stats['project']) && !empty($stats['group']['created_by'])) {
            $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? AND (group_id IS NULL OR group_id = ?) ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([(int) $stats['group']['created_by'], (int) $stats['group']['id']]);
            $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
            if ($creator_project_id > 0) {
                $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([(int) $stats['group']['id'], $creator_project_id, (int) $stats['group']['id']]);
                $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE id = ? LIMIT 1');
                $stmt->execute([$creator_project_id]);
                $stats['project'] = $stmt->fetch();
            }
        }
    }
    if (empty($stats['project']) && empty($stats['group']['id'])) {
        $stmt = $pdo->prepare('SELECT id, title, status, supervisor_id FROM projects WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$uid]);
        $stats['project'] = $stmt->fetch();
    }

    if (!empty($stats['project']['id'])) {
        ensure_project_milestones_table($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries WHERE project_id = ?');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['logbook_count'] = (int) $stmt->fetchColumn();

        // Chapter upload progress (5 chapters)
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT chapter) FROM project_documents WHERE project_id = ? AND document_type = "proposal" AND chapter IS NOT NULL AND is_latest = 1');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['chapters_uploaded'] = (int) $stmt->fetchColumn();

        // Milestone progress
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_milestones WHERE project_id = ?');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['ms_total'] = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_milestones WHERE project_id = ? AND completed_at IS NOT NULL');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['ms_done'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT
            le.id,
            le.title,
            le.content,
            le.supervisor_approved,
            le.entry_date,
            le.created_at,
            sup.full_name AS supervisor_name
            FROM logbook_entries le
            JOIN projects p ON p.id = le.project_id
            LEFT JOIN users sup ON sup.id = p.supervisor_id
            WHERE le.project_id = ?
            ORDER BY le.entry_date DESC, le.created_at DESC
            LIMIT 5');
        $stmt->execute([(int) $stats['project']['id']]);
        $stats['recent_logbook_entries'] = $stmt->fetchAll();
    } else {
        $stats['logbook_count'] = 0;
        $stats['recent_logbook_entries'] = [];
        $stats['chapters_uploaded'] = 0;
        $stats['ms_total'] = 0;
        $stats['ms_done'] = 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'supervisor') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE supervisor_id = ? AND status IN ("in_progress","submitted","approved")');
    $stmt->execute([$uid]);
    $stats['vault_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM logbook_entries le JOIN projects p ON le.project_id = p.id WHERE p.supervisor_id = ? AND le.supervisor_approved IS NULL');
    $stmt->execute([$uid]);
    $stats['pending_logbook'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $stats['unread_messages'] = (int) $stmt->fetchColumn();
} elseif ($role === 'hod') {
    ensure_group_submission_tables($pdo);
    $fresh_hod = get_user_by_id($uid);
    $hod_department_source = trim((string) ($fresh_hod['department'] ?? ($user['department'] ?? '')));
    $hod_department_info = resolve_department_info($pdo, $hod_department_source);
    $stats['department_label'] = $hod_department_info['name'] ?: $hod_department_info['raw'];
    $stats['department_error'] = empty($hod_department_info['variants'])
        ? 'Your HOD account does not have a valid department configured. Contact admin.'
        : '';
    $stats['ongoing_projects'] = [];

    if (!empty($hod_department_info['variants'])) {
        $dept_placeholders = sql_placeholders(count($hod_department_info['variants']));
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status = ? AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');

        $count_stmt->execute(array_merge(['submitted'], $hod_department_info['variants']));
        $stats['pending_topics'] = (int) $count_stmt->fetchColumn();

        // Pending group submissions (new workflow)
        $ph_dept = sql_placeholders(count($hod_department_info['variants']));
        $gs_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM group_submissions gs
             JOIN `groups` g ON g.id = gs.group_id
             WHERE gs.status = 'pending'
               AND LOWER(TRIM(COALESCE(g.department,''))) IN ($ph_dept)"
        );
        $gs_stmt->execute($hod_department_info['variants']);
        $stats['pending_group_subs'] = (int) $gs_stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN users u ON p.student_id = u.id WHERE p.status IN ("in_progress","approved") AND LOWER(TRIM(COALESCE(u.department, ""))) IN (' . $dept_placeholders . ')');
        $stmt->execute($hod_department_info['variants']);
        $stats['ongoing'] = (int) $stmt->fetchColumn();

        $count_stmt->execute(array_merge(['completed'], $hod_department_info['variants']));
        $stats['completed'] = (int) $count_stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT
            p.id,
            p.title,
            p.status,
            p.updated_at,
            p.group_id,
            COALESCE(g.name, CONCAT("Solo Vault - ", lead_u.full_name)) AS vault_name,
            lead_u.full_name AS lead_name,
            lead_u.reg_number AS lead_reg_number,
            lead_u.email AS lead_email,
            sup_u.full_name AS supervisor_name,
            (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
                FROM `group_members` gm2
                JOIN users u2 ON u2.id = gm2.student_id
                WHERE gm2.group_id = p.group_id) AS member_directory
            FROM projects p
            JOIN users lead_u ON lead_u.id = p.student_id
            LEFT JOIN users sup_u ON sup_u.id = p.supervisor_id
            LEFT JOIN `groups` g ON g.id = p.group_id
            WHERE p.status IN ("approved","in_progress")
                AND LOWER(TRIM(COALESCE(lead_u.department, ""))) IN (' . $dept_placeholders . ')
            ORDER BY p.updated_at DESC');
        $stmt->execute($hod_department_info['variants']);
        $stats['ongoing_projects'] = $stmt->fetchAll();
    } else {
        $stats['pending_topics'] = 0;
        $stats['ongoing'] = 0;
        $stats['completed'] = 0;
        $stats['ongoing_projects'] = [];
    }
} elseif ($role === 'admin') {
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $stats['users_count'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT COUNT(*) FROM projects');
    $stats['projects_count'] = (int) $stmt->fetchColumn();
}

$hero_title = 'Welcome back to your workspace';
$hero_copy = 'Track activity, jump into key actions, and keep your projects moving.';
$hero_link = 'vault.php';
$hero_button = 'Open Project Vault';
$topbarVariant = $role === 'supervisor' ? 'supervisor-dashboard' : 'default';
$topbarDepartment = '';
$topbarDate = '';
$appSidebarBrandName = 'FYP Vault';
$appSidebarBrandSubtitle = $role === 'supervisor' ? 'Collaboration Hub' : 'Workspace';
$appSidebarRoleLabel = $role === 'supervisor' ? 'Supervisor Portal' : strtoupper((string) $role) . ' Portal';
$hideFooter = true;

if ($role === 'student') {
    $student_archived_early = !empty($stats['student_project_status']) && $stats['student_project_status'] !== 'active';
    if ($student_archived_early) {
        $hero_title = 'Your project journey is complete';
        $hero_copy = 'Browse the project vault to explore archived final year projects from past cohorts.';
        $hero_link = 'vault.php';
        $hero_button = 'Browse Vault';
    } else {
        $hero_title = 'Build your project with confidence';
        $hero_copy = 'Access your group, update your project, and keep your logbook current from one dashboard.';
        $hero_link = 'student/project.php';
        $hero_button = 'Go to My Project';
    }
} elseif ($role === 'supervisor') {
    $hero_title = 'Monitor your assigned vaults';
    $hero_copy = 'Review student progress, clear pending logbook approvals, and reply to messages faster.';
    $hero_link = 'supervisor/students.php';
    $hero_button = 'View Assigned Vaults';
    $topbarBreadcrumbCurrent = 'Supervisor Dashboard';
    $stats['assigned_vaults'] = [];

    $stmt = $pdo->prepare('SELECT p.id, p.title, p.status, p.updated_at, p.group_id, u.id AS student_id, u.full_name AS student_name, u.reg_number, u.email, g.name AS group_name,
        (SELECT GROUP_CONCAT(CONCAT(u2.full_name, " (", COALESCE(NULLIF(u2.reg_number, ""), u2.email), ")") ORDER BY CASE WHEN gm2.role = "lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR ", ")
            FROM `group_members` gm2
            JOIN users u2 ON u2.id = gm2.student_id
            WHERE gm2.group_id = p.group_id) AS group_member_directory
        FROM projects p
        JOIN users u ON p.student_id = u.id
        LEFT JOIN `groups` g ON g.id = p.group_id
        WHERE p.supervisor_id = ? AND p.status IN ("in_progress","submitted","approved","completed")
        ORDER BY p.updated_at DESC');
    $stmt->execute([$uid]);
    $stats['assigned_vaults'] = $stmt->fetchAll();
    $stats['vault_count'] = count($stats['assigned_vaults']);
} elseif ($role === 'hod') {
    $hero_title = 'Oversee your department pipeline';
    $hero_copy = 'Approve topics, assign supervisors, and keep visibility on ongoing and completed projects.';
    $hero_link = 'hod/topics.php';
    $hero_button = 'Review Topics';
    $topbarVariant = 'hod-dashboard';
    $topbarDepartment = (string) ($stats['department_label'] ?: 'Unknown Department');
    $topbarDate = date('M j, Y');
    $appSidebarBrandSubtitle = 'Collaboration Hub';
    $appSidebarRoleLabel = 'Head of Department';
} elseif ($role === 'admin') {
    $hero_title = 'Manage the platform with clarity';
    $hero_copy = 'Keep user accounts healthy, track project totals, and access reports from a single control center.';
    $hero_link = 'admin/users.php';
    $hero_button = 'Manage Users';
}

$bodyClass = $role === 'hod' ? 'dashboard-page hod-dashboard' : ($role === 'supervisor' ? 'dashboard-page supervisor-dashboard' : 'dashboard-page');

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero">
    <div>
        <div class="dashboard-hero__eyebrow"><?= e(ucfirst($role)) ?> Workspace</div>
        <h1 class="dashboard-hero__title mb-2"><?= e($hero_title) ?></h1>
        <p class="dashboard-hero__copy mb-0"><?= e($hero_copy) ?></p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="<?= base_url($hero_link) ?>" class="btn btn-warning dashboard-hero__btn"><?= e($hero_button) ?></a>
    </div>
</section>
<section class="dashboard-main-panels">

<?php
$is_project_archived = !empty($stats['project']) && ($stats['project']['status'] ?? '') === 'archived';
?>

<?php if ($role === 'student' && $is_project_archived): ?>
    <?php
        $sps = $stats['student_project_status'] ?? 'completed';
        $repeat = !empty($stats['repeat_required']);
    ?>
    <div class="card mb-4">
        <div class="card-body text-center py-5">
            <div class="mb-3">
                <i class="bi bi-archive-fill" style="font-size: 3rem; color: var(--hod-accent, #4f46e5);"></i>
            </div>
            <h3 class="mb-2">
                <?php if ($sps === 'failed'): ?>Your project was not completed successfully.
                <?php else: ?>Your project has been completed and archived.
                <?php endif; ?>
            </h3>
            <p class="text-muted mb-1">
                <?php if ($repeat): ?>
                    You are required to repeat this project in the next session. You will be able to join a new group.
                <?php else: ?>
                    Congratulations! Your Final Year Project has been successfully archived in the vault.
                <?php endif; ?>
            </p>
            <?php if ($sps === 'failed'): ?>
                <span class="badge bg-danger mb-3">Project Status: Failed</span>
            <?php else: ?>
                <span class="badge bg-success mb-3">Project Status: Completed</span>
            <?php endif; ?>
            <div class="mt-4 d-flex gap-2 justify-content-center">
                <a href="<?= base_url('vault.php') ?>" class="btn btn-primary">
                    <i class="bi bi-folder2-open me-1"></i> Browse Archived Projects
                </a>
                <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-envelope-paper me-1"></i> Messages
                </a>
            </div>
        </div>
    </div>

<?php elseif ($role === 'student'): ?>
    <div class="student-scope-row mb-3">
        <span class="student-scope-label">Department scope:</span>
        <span class="student-scope-pill">Student View</span>
    </div>

    <?php if (!empty($stats['hod_group'])): ?>
    <?php
        $hg       = $stats['hod_group'];
        $hg_st    = $hg['group_status'] ?? 'formed';
        $hg_sub   = $hg['latest_submission'] ?? null;
        $hg_badge = match($hg_st) {
            'under_review' => ['bg-info text-dark',  'Under Review'],
            'approved'     => ['bg-success',          'Approved'],
            'rejected'     => ['bg-danger',           'Rejected — Resubmit'],
            default        => ['bg-secondary',        'Group Formed — Pending Submission'],
        };
    ?>
    <div class="card mb-4 border-2 <?= $hg_st === 'approved' ? 'border-success' : ($hg_st === 'under_review' ? 'border-info' : ($hg_st === 'rejected' ? 'border-danger' : 'border-secondary')) ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people-fill me-1"></i> <?= e($hg['name']) ?></span>
            <span class="badge <?= $hg_badge[0] ?>"><?= $hg_badge[1] ?></span>
        </div>
        <div class="card-body py-2">
            <div class="row align-items-center g-2">
                <div class="col-md-8">
                    <?php if ($hg_sub): ?>
                        <small class="text-muted">
                            Last <?= e($hg_sub['type']) ?> submitted:
                            <strong><?= e(mb_substr($hg_sub['title'], 0, 60)) ?></strong>
                            <span class="badge bg-light text-dark border ms-1"><?= e(ucfirst($hg_sub['status'])) ?></span>
                        </small>
                        <?php if ($hg_st === 'rejected' && $hg_sub['rejection_reason']): ?>
                            <div class="text-danger small mt-1">Reason: <?= e($hg_sub['rejection_reason']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <small class="text-muted">No submission yet. Submit your
                            <?= $hg['workflow'] === 'direct_proposal' ? 'project proposal' : 'project topic' ?> to begin.
                        </small>
                    <?php endif; ?>
                    <?php if (!empty($hg['supervisor_name'])): ?>
                        <div class="mt-1 small"><i class="bi bi-person-check text-success me-1"></i>Supervisor: <strong><?= e($hg['supervisor_name']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if (in_array($hg_st, ['formed', 'rejected'], true)): ?>
                        <a href="<?= base_url('student/group_submit.php') ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-send me-1"></i> Submit <?= $hg['workflow'] === 'direct_proposal' ? 'Proposal' : 'Topic' ?>
                        </a>
                    <?php elseif ($hg_st === 'approved'): ?>
                        <a href="<?= base_url('student/group_submit.php') ?>" class="btn btn-outline-success btn-sm">View Submission</a>
                    <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Awaiting HOD review</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('student/group.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card student-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="student-stat-icon text-success me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">My Group</h6>
                            <div class="student-stat-value"><?= e($stats['group']['name'] ?? 'No group yet') ?></div>
                            <small class="text-muted"><?= (int) ($stats['group']['member_count'] ?? 0) ?> members</small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('student/project.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card student-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="student-stat-icon text-primary me-3"><i class="bi bi-check2-circle"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">My Project</h6>
                            <div class="student-stat-value"><?= $stats['project'] ? e($stats['project']['title']) : 'FYP Vault' ?></div>
                            <?php if ($stats['project']): ?>
                                <span class="badge bg-info text-dark"><?= e(ucfirst(str_replace('_', ' ', (string) $stats['project']['status']))) ?></span>
                            <?php else: ?>
                                <small class="text-muted">Not submitted</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('student/logbook.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card student-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="student-stat-icon text-warning me-3"><i class="bi bi-journal-text"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Logbook Entries</h6>
                            <div class="student-stat-value"><?= (int) ($stats['logbook_count'] ?? 0) ?></div>
                            <small class="text-muted">Latest activity logged</small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('messages.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card student-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="student-stat-icon text-info me-3"><i class="bi bi-envelope-paper"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Unread Messages</h6>
                            <div class="student-stat-value"><?= (int) ($stats['unread_messages'] ?? 0) ?></div>
                            <small class="text-muted">Active conversations</small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <?php
        // Overall progress score
        $status_pct_map = ['draft'=>5,'submitted'=>15,'approved'=>30,'in_progress'=>50,'pending_completion'=>80,'completed'=>100,'archived'=>100];
        $status_pct = $status_pct_map[$stats['project']['status'] ?? 'draft'] ?? 5;
        $chapter_pct = min(100, (int)(($stats['chapters_uploaded'] / 5) * 100));
        $ms_pct = $stats['ms_total'] > 0 ? (int)(($stats['ms_done'] / $stats['ms_total']) * 100) : null;
    ?>
    <div class="card mb-4">
        <div class="card-header">Project Progress</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="fw-semibold">Project Status</small>
                        <small class="text-muted"><?= $status_pct ?>%</small>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar bg-primary" style="width:<?= $status_pct ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1"><?= e(ucfirst(str_replace('_',' ', $stats['project']['status'] ?? 'draft'))) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="fw-semibold">Chapters Submitted</small>
                        <small class="text-muted"><?= (int) $stats['chapters_uploaded'] ?>/5</small>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar bg-success" style="width:<?= $chapter_pct ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1"><?= $chapter_pct ?>% of documentation uploaded</div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="fw-semibold">Milestones</small>
                        <small class="text-muted"><?= $stats['ms_done'] ?>/<?= $stats['ms_total'] ?: '—' ?></small>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar bg-warning" style="width:<?= $ms_pct ?? 0 ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1"><?= $ms_pct !== null ? $ms_pct . '% milestones complete' : 'No milestones set yet' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Recent Logbook Entries</span>
            <a href="<?= base_url('student/logbook.php') ?>" class="text-decoration-none text-success fw-semibold">View all →</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($stats['recent_logbook_entries'])): ?>
                <p class="text-muted px-3 py-4 mb-0">No logbook entries yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <colgroup>
                            <col style="width: 7%">
                            <col style="width: 20%">
                            <col style="width: 34%">
                            <col style="width: 16%">
                            <col style="width: 11%">
                            <col style="width: 12%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Entry Title</th>
                                <th>Activity Summary</th>
                                <th>Supervisor</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_logbook_entries'] as $i => $entry):
                                $summary = trim((string) ($entry['content'] ?? ''));
                                $summary = $summary !== '' ? mb_substr($summary, 0, 96) . (mb_strlen($summary) > 96 ? '…' : '') : '—';
                                $status = $entry['supervisor_approved'] === null ? 'Pending' : ($entry['supervisor_approved'] ? 'Approved' : 'Flagged');
                                $status_class = $entry['supervisor_approved'] === null ? 'bg-warning text-dark' : ($entry['supervisor_approved'] ? 'bg-success' : 'bg-danger');
                            ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="fw-semibold"><?= e($entry['title']) ?></td>
                                    <td><?= e($summary) ?></td>
                                    <td><?= e($entry['supervisor_name'] ?: '—') ?></td>
                                    <td><span class="badge <?= $status_class ?>"><?= e($status) ?></span></td>
                                    <td><?= !empty($entry['entry_date']) ? e(date('M j, Y', strtotime((string) $entry['entry_date']))) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body d-flex flex-wrap gap-2">
            <?php if (!empty($stats['hod_group'])): ?>
                <a href="<?= base_url('student/group_submit.php') ?>" class="btn btn-primary">Submit Topic / Proposal</a>
            <?php endif; ?>
            <a href="<?= base_url('student/project.php') ?>" class="btn btn-primary">My Project</a>
            <a href="<?= base_url('student/group.php') ?>" class="btn btn-outline-primary">My Group</a>
            <a href="<?= base_url('student/logbook.php') ?>" class="btn btn-outline-primary">Logbook</a>
            <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'supervisor'): ?>
    <div class="supervisor-scope-row mb-3">
        <span class="supervisor-scope-label">Supervisor scope:</span>
        <span class="supervisor-scope-pill">Assigned Vaults</span>
    </div>

    <div class="row g-3 mb-4 supervisor-stats-row">
        <div class="col-xl-4 col-md-6">
            <a href="<?= base_url('supervisor/students.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card supervisor-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="supervisor-stat-icon text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Assigned Vaults</h6>
                            <div class="supervisor-stat-value"><?= (int)($stats['vault_count'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-4 col-md-6">
            <a href="<?= base_url('supervisor/students.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card supervisor-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="supervisor-stat-icon text-warning me-3"><i class="bi bi-journal-check"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Pending Logbook Reviews</h6>
                            <div class="supervisor-stat-value"><?= (int)($stats['pending_logbook'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-4 col-md-12">
            <a href="<?= base_url('messages.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card supervisor-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="supervisor-stat-icon text-info me-3"><i class="bi bi-chat-dots"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Unread Messages</h6>
                            <div class="supervisor-stat-value"><?= (int)($stats['unread_messages'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="card mb-4 supervisor-vaults-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>My Assigned Vaults</span>
            <a href="<?= base_url('supervisor/students.php') ?>" class="supervisor-view-all-link">View all <span aria-hidden="true">→</span></a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($stats['assigned_vaults'])): ?>
                <p class="text-muted px-3 py-4 mb-0">No assigned vaults yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle supervisor-vaults-table mb-0">
                        <colgroup>
                            <col style="width: 15%">
                            <col style="width: 23%">
                            <col style="width: 27%">
                            <col style="width: 15%">
                            <col style="width: 12%">
                            <col style="width: 8%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Project Title</th>
                                <th>Members</th>
                                <th>Progress</th>
                                <th>Last Activity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['assigned_vaults'] as $vault):
                                $progress_raw = strtolower((string) ($vault['status'] ?? ''));
                                $progress_label = match ($progress_raw) {
                                    'submitted' => 'Pending review',
                                    'approved', 'in_progress', 'in progress' => 'In progress',
                                    'completed' => 'Completed',
                                    default => ucfirst(str_replace('_', ' ', (string) ($vault['status'] ?? 'Unknown'))) ?: 'Unknown',
                                };
                            ?>
                                <tr>
                                    <td><span class="supervisor-vault-badge"><?= e($vault['group_name'] ?: ('Vault #' . $vault['group_id'])) ?></span></td>
                                    <td><span class="supervisor-project-title"><?= e($vault['title']) ?></span></td>
                                    <td>
                                        <?php
                                            $members = array_values(array_filter(array_map('trim', explode(',', (string) ($vault['group_member_directory'] ?? '')))));
                                            if (empty($members)) {
                                                $members = [($vault['student_name'] ?? '—') . ' (' . (($vault['reg_number'] ?: $vault['email']) ?: '—') . ')'];
                                            }
                                            $member_primary = $members[0] ?? '—';
                                            $member_secondary = $members[1] ?? '';
                                        ?>
                                        <div class="supervisor-member-primary"><?= e($member_primary) ?></div>
                                        <div class="supervisor-member-secondary"><?= e($member_secondary !== '' ? $member_secondary : '—') ?></div>
                                    </td>
                                    <td><span class="supervisor-status-pill"><?= e($progress_label) ?></span></td>
                                    <td><?= !empty($vault['updated_at']) ? e(date('M j, Y', strtotime((string) $vault['updated_at']))) : '—' ?></td>
                                    <td>
                                        <a href="<?= base_url('supervisor/student_detail.php?pid=' . $vault['id']) ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="<?= base_url('messages.php?pid=' . $vault['id'] . '&with=' . $vault['student_id']) ?>" class="btn btn-sm btn-outline-secondary">Message</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card supervisor-actions-card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body d-flex flex-wrap gap-2">
            <a href="<?= base_url('supervisor/students.php') ?>" class="btn supervisor-btn-primary">View Group Vaults</a>
            <a href="<?= base_url('messages.php') ?>" class="btn supervisor-btn-outline">Messages</a>
        </div>
    </div>

<?php elseif ($role === 'hod'): ?>
    <?php if (!empty($stats['department_error'])): ?>
        <div class="alert alert-danger"><?= e($stats['department_error']) ?></div>
    <?php else: ?>
        <div class="hod-scope-row mb-3">
            <span class="hod-scope-label">Department scope:</span>
            <span class="hod-scope-pill"><?= e($stats['department_label'] ?: 'Unknown') ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($stats['pending_group_subs'])): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-3 py-2">
        <i class="bi bi-inbox-fill fs-5"></i>
        <span><strong><?= (int) $stats['pending_group_subs'] ?></strong> group submission(s) awaiting your review.</span>
        <a href="<?= base_url('hod/group_review.php') ?>" class="btn btn-warning btn-sm ms-auto">Review Now</a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4 hod-stats-row">
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('hod/group_review.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card hod-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="hod-stat-icon text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Group Submissions</h6>
                            <div class="hod-stat-value"><?= (int)($stats['pending_group_subs'] ?? 0) ?></div>
                            <small class="text-muted">Pending review</small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('hod/topics.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card hod-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="hod-stat-icon text-warning me-3"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Topics Pending Approval</h6>
                            <div class="hod-stat-value"><?= (int)($stats['pending_topics'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('hod/reports.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card hod-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="hod-stat-icon text-primary me-3"><i class="bi bi-check2-circle"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Ongoing Projects</h6>
                            <div class="hod-stat-value"><?= (int)($stats['ongoing'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= base_url('hod/archive.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card hod-stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="hod-stat-icon text-success me-3"><i class="bi bi-check-circle"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Completed Projects</h6>
                            <div class="hod-stat-value"><?= (int)($stats['completed'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="card mb-4 hod-projects-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Ongoing Projects and Assigned Supervisors</span>
            <a href="<?= base_url('hod/reports.php') ?>" class="hod-view-all-link">View all <span aria-hidden="true">→</span></a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($stats['ongoing_projects'])): ?>
                <p class="text-muted px-3 py-4 mb-0">No ongoing projects in your department.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle hod-projects-table mb-0">
                        <colgroup>
                            <col style="width: 13%">
                            <col style="width: 25%">
                            <col style="width: 28%">
                            <col style="width: 15%">
                            <col style="width: 10%">
                            <col style="width: 9%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Vault</th>
                                <th>Project</th>
                                <th>Members / Index</th>
                                <th>Supervisor</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['ongoing_projects'] as $p):
                                $members = [];
                                if (!empty($p['group_id'])) {
                                    $members = array_values(array_filter(array_map('trim', explode(',', (string) ($p['member_directory'] ?? ''))), static function ($v) {
                                        return $v !== '';
                                    }));
                                }
                                if (empty($members)) {
                                    $members[] = (($p['lead_name'] ?? '—') . ' (' . (($p['lead_reg_number'] ?: $p['lead_email']) ?: '—') . ')');
                                }
                                $member_primary = $members[0] ?? '—';
                                $member_secondary = $members[1] ?? '';
                                $status_raw = strtolower((string) ($p['status'] ?? ''));
                                $status_label = in_array($status_raw, ['ongoing', 'in_progress', 'in progress', 'active'], true)
                                    ? 'In progress'
                                    : ucfirst(str_replace('_', ' ', (string) ($p['status'] ?? '')));
                            ?>
                                <tr>
                                    <td><span class="hod-vault-badge"><?= e($p['vault_name']) ?></span></td>
                                    <td><span class="hod-project-title"><?= e($p['title']) ?></span></td>
                                    <td>
                                        <div class="hod-member-primary"><?= e($member_primary) ?></div>
                                        <div class="hod-member-secondary"><?= e($member_secondary !== '' ? $member_secondary : '—') ?></div>
                                    </td>
                                    <td><?= e($p['supervisor_name'] ?: 'Not assigned') ?></td>
                                    <td><span class="hod-status-pill"><?= e($status_label) ?></span></td>
                                    <td><?= !empty($p['updated_at']) ? e(date('M j, Y', strtotime((string) $p['updated_at']))) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card hod-actions-card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body d-flex flex-wrap gap-2">
            <a href="<?= base_url('hod/group_import.php') ?>" class="btn hod-btn-primary">Import Groups</a>
            <a href="<?= base_url('hod/group_review.php') ?>" class="btn hod-btn-primary">Review Submissions</a>
            <a href="<?= base_url('hod/topics.php') ?>" class="btn hod-btn-outline">Review Topics</a>
            <a href="<?= base_url('hod/assign.php') ?>" class="btn hod-btn-outline">Assign Supervisors</a>
            <a href="<?= base_url('hod/archive.php') ?>" class="btn hod-btn-outline">Archive</a>
            <a href="<?= base_url('hod/reports.php') ?>" class="btn hod-btn-outline">Reports</a>
        </div>
    </div>

<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <a href="<?= base_url('admin/users.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-primary me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Total Users</h6>
                            <span class="fw-bold"><?= (int)($stats['users_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a href="<?= base_url('admin/projects.php') ?>" class="text-decoration-none text-reset d-block h-100">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="text-success me-3"><i class="bi bi-folder"></i></div>
                        <div>
                            <h6 class="text-muted mb-0">Total Projects</h6>
                            <span class="fw-bold"><?= (int)($stats['projects_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <a href="<?= base_url('admin/users.php') ?>" class="btn btn-primary me-2">Manage Users</a>
            <a href="<?= base_url('admin/reports.php') ?>" class="btn btn-outline-primary me-2">Audit Reports</a>
            <a href="<?= base_url('admin/projects.php') ?>" class="btn btn-outline-primary">Project Status</a>
        </div>
    </div>
<?php endif; ?>

        </section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
