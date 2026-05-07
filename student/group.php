<?php
/**
 * Student Group Management - Create, invite, and manage project groups
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();
$error = '';
$success = '';

// Get current group for this student
$stmt = $pdo->prepare('SELECT g.*, COUNT(gm.id) AS member_count FROM `groups` g LEFT JOIN `group_members` gm ON g.id = gm.group_id WHERE g.id IN (SELECT group_id FROM `group_members` WHERE student_id = ?) GROUP BY g.id LIMIT 1');
$stmt->execute([$uid]);
$current_group = $stmt->fetch();

// Resolve group project status early so the archived guard works before POST handlers
$group_project = null;
if ($current_group) {
    $gp_stmt = $pdo->prepare('SELECT p.id, p.title, p.status, u.full_name AS supervisor_name, u.email AS supervisor_email FROM projects p LEFT JOIN users u ON u.id = p.supervisor_id WHERE p.group_id = ? ORDER BY p.updated_at DESC LIMIT 1');
    $gp_stmt->execute([(int) $current_group['id']]);
    $group_project = $gp_stmt->fetch();
}
$is_archived = !empty($group_project) && ($group_project['status'] ?? '') === 'archived';

// Block all mutations when the group's project is archived
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $is_archived) {
    flash('error', 'This group is archived. No changes are permitted.');
    redirect(base_url('student/group.php'));
}

// Create new group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && (($_POST['action'] ?? '') === 'create_group')) {
    $group_name = trim($_POST['group_name'] ?? '');
    $group_desc = trim($_POST['group_description'] ?? '');
    
    if (strlen($group_name) < 3) {
        $error = 'Group name must be at least 3 characters.';
    } elseif (!$current_group) {
        try {
            $pdo->beginTransaction();

            // Only block if the student is in a non-archived active group.
            // repeat_required students may still appear in archived group_members — that's fine.
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM `group_members` gm
                JOIN `groups` g ON g.id = gm.group_id
                LEFT JOIN projects p ON p.group_id = g.id
                WHERE gm.student_id = ?
                  AND g.is_active = 1
                  AND (p.status IS NULL OR p.status NOT IN ("archived"))
            ');
            $stmt->execute([$uid]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                $error = 'You are already in an active group.';
            } else {
                // Clean up stale archived memberships and reset repeat flags before joining a new group
                reset_repeating_student($pdo, $uid);

                $stmt = $pdo->prepare('INSERT INTO `groups` (name, description, created_by, is_active) VALUES (?, ?, ?, 1)');
                $stmt->execute([$group_name, $group_desc ?: null, $uid]);
                $group_id = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO `group_members` (group_id, student_id, role) VALUES (?, ?, "lead")')->execute([$group_id, $uid]);

                $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1');
                $stmt->execute([$uid]);
                $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
                if ($creator_project_id > 0) {
                    $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([$group_id, $creator_project_id, $group_id]);
                }

                $pdo->commit();

                flash('success', 'Group created successfully. You are the group lead.');
                redirect(base_url('student/group.php'));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to create group right now. Please try again.';
        }
    }
}

// Join group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && (($_POST['action'] ?? '') === 'join_group')) {
    $error = 'Direct group joining is disabled. Ask a group creator to add/invite you.';
}

// Invite/add member (creator only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && (($_POST['action'] ?? '') === 'invite_member') && $current_group) {
    $member_id = (int) ($_POST['member_id'] ?? 0);

    if ((int) $current_group['created_by'] !== $uid) {
        $error = 'Only the group creator can invite members.';
    } elseif ($member_id <= 0) {
        $error = 'Please select a student to invite.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, created_by FROM `groups` WHERE id = ? AND is_active = 1 FOR UPDATE');
            $stmt->execute([(int) $current_group['id']]);
            $group_row = $stmt->fetch();

            if (!$group_row || (int) $group_row['created_by'] !== $uid) {
                $pdo->rollBack();
                $error = 'Only the group creator can invite members.';
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM `group_members` WHERE group_id = ?');
                $stmt->execute([(int) $current_group['id']]);
                $member_count = (int) $stmt->fetchColumn();

                if ($member_count >= 5) {
                    $pdo->rollBack();
                    $error = 'This group is full. Maximum is 5 members.';
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "student" AND is_active = 1');
                    $stmt->execute([$member_id]);
                    $target_student = $stmt->fetch();

                    if (!$target_student) {
                        $pdo->rollBack();
                        $error = 'Selected student is not available.';
                    } else {
                        // Block only if student is in a non-archived active group
                        $stmt = $pdo->prepare('
                            SELECT COUNT(*) FROM `group_members` gm
                            JOIN `groups` g ON g.id = gm.group_id
                            LEFT JOIN projects p ON p.group_id = g.id
                            WHERE gm.student_id = ?
                              AND g.is_active = 1
                              AND (p.status IS NULL OR p.status NOT IN ("archived"))
                        ');
                        $stmt->execute([$member_id]);
                        $already_in_active_group = (int) $stmt->fetchColumn() > 0;

                        if ($already_in_active_group) {
                            $pdo->rollBack();
                            $error = 'Selected student already belongs to an active group.';
                        } else {
                            // Clean up stale archived memberships + reset repeat status before adding
                            reset_repeating_student($pdo, $member_id);

                            $pdo->prepare('INSERT INTO `group_members` (group_id, student_id, role) VALUES (?, ?, "member")')->execute([(int) $current_group['id'], $member_id]);

                            // Ensure this group's shared project is linked so new members can see existing work.
                            $stmt = $pdo->prepare('SELECT id FROM projects WHERE group_id = ? ORDER BY updated_at DESC LIMIT 1');
                            $stmt->execute([(int) $current_group['id']]);
                            $group_project_id = (int) ($stmt->fetchColumn() ?: 0);
                            if ($group_project_id === 0) {
                                $stmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1');
                                $stmt->execute([$uid]);
                                $creator_project_id = (int) ($stmt->fetchColumn() ?: 0);
                                if ($creator_project_id > 0) {
                                    $pdo->prepare('UPDATE projects SET group_id = ? WHERE id = ? AND (group_id IS NULL OR group_id = ?)')->execute([(int) $current_group['id'], $creator_project_id, (int) $current_group['id']]);
                                }
                            }

                            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')->execute([
                                $member_id,
                                'group_invite',
                                'Added to project group',
                                'You were added to a project group by the group creator.',
                                base_url('student/group.php')
                            ]);

                            $pdo->commit();
                            flash('success', 'Member added to group successfully.');
                            redirect(base_url('student/group.php'));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to add member right now. Please try again.';
        }
    }
}

// Leave group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && (($_POST['action'] ?? '') === 'leave_group') && $current_group) {
    $stmt = $pdo->prepare('SELECT role FROM `group_members` WHERE group_id = ? AND student_id = ?');
    $stmt->execute([$current_group['id'], $uid]);
    $member = $stmt->fetch();
    
    if ($member && $member['role'] !== 'lead') {
        $pdo->prepare('DELETE FROM `group_members` WHERE group_id = ? AND student_id = ?')->execute([$current_group['id'], $uid]);
        flash('success', 'You have left the group.');
        redirect(base_url('student/group.php'));
    } else {
        $error = 'Group leads cannot leave. Transfer lead role first or delete the group.';
    }
}

// Get group members if in a group
$group_members = [];
if ($current_group) {
    $stmt = $pdo->prepare('SELECT gm.*, u.full_name, u.email, u.reg_number FROM `group_members` gm JOIN users u ON gm.student_id = u.id WHERE gm.group_id = ? ORDER BY gm.role DESC, u.full_name');
    $stmt->execute([$current_group['id']]);
    $group_members = $stmt->fetchAll();
}

$invite_candidates = [];
if ($current_group && (int) $current_group['created_by'] === $uid && (int) $current_group['member_count'] < 5) {
    // Exclude students already in a non-archived active group.
    // Students whose only memberships are in archived groups are eligible (repeat students).
    $stmt = $pdo->prepare('
        SELECT u.id, u.full_name, u.email, u.reg_number, u.repeat_required
        FROM users u
        WHERE u.role = "student"
          AND u.is_active = 1
          AND u.id <> ?
          AND u.id NOT IN (
              SELECT gm.student_id
              FROM `group_members` gm
              JOIN `groups` g ON g.id = gm.group_id
              LEFT JOIN projects p ON p.group_id = g.id
              WHERE g.is_active = 1
                AND (p.status IS NULL OR p.status NOT IN ("archived"))
          )
        ORDER BY u.repeat_required DESC, u.full_name
    ');
    $stmt->execute([$uid]);
    $invite_candidates = $stmt->fetchAll();
}

$pageTitle = 'My Group';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="dashboard-hero mb-4 d-flex align-items-center justify-content-between">
    <div>
        <div class="dashboard-hero__eyebrow">Student Portal</div>
        <h1 class="dashboard-hero__title mb-2">My Group<?= $current_group ? ' — ' . e($current_group['name']) : '' ?></h1>
        <p class="dashboard-hero__copy mb-0">Manage your vault members, keep contact with your supervisor, and stay aligned on the project plan.</p>
    </div>
    <div class="dashboard-hero__actions">
        <a href="<?= base_url('messages.php') ?>" class="btn dashboard-hero__btn">Message Group</a>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-success me-3"><i class="bi bi-people"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Group Members</h6>
                    <div class="student-stat-value"><?= (int) ($current_group['member_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-primary me-3"><i class="bi bi-person-badge"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Assigned Supervisor</h6>
                    <div class="student-stat-value"><?= e($group_project['supervisor_name'] ?? 'Not assigned') ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card student-stat-card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="student-stat-icon text-warning me-3"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <h6 class="text-muted mb-1">Group Status</h6>
                    <div class="student-stat-value">Active</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($is_archived): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-archive-fill fs-5"></i>
        <div>This group's project has been <strong>archived</strong>. Group history is preserved for your records, but no changes can be made.</div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($current_group): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><?= e($current_group['name']) ?></h5>
            <span class="badge <?= $is_archived ? 'bg-secondary' : 'bg-success student-badge-green' ?>"><?= $is_archived ? 'Archived' : 'Active' ?></span>
        </div>
        <div class="card-body">
            <p class="text-muted"><?= e($current_group['description'] ?? 'No description') ?></p>
            <p><small>Created: <?= e(date('M j, Y', strtotime($current_group['created_at']))) ?></small></p>
            
            <h6 class="mt-3">Group Members (<?= count($group_members) ?>)</h6>
            <div class="list-group">
                <?php foreach ($group_members as $m): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= e($m['full_name']) ?></strong>
                            <?php if ($m['student_id'] === $uid): ?>
                                <span class="badge bg-primary">You</span>
                            <?php endif; ?>
                            <?php if ($m['role'] === 'lead'): ?>
                                <span class="badge bg-warning text-dark">Lead</span>
                            <?php endif; ?>
                            <br><small class="text-muted"><?= e($m['reg_number'] ?? $m['email']) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$is_archived && (int) $current_group['created_by'] === $uid): ?>
                <hr>
                <h6>Add / Invite Members</h6>
                <?php if ((int) $current_group['member_count'] >= 5): ?>
                    <p class="text-muted mb-0">Group is full (5/5 members).</p>
                <?php elseif (empty($invite_candidates)): ?>
                    <p class="text-muted mb-0">No available students to invite right now.</p>
                <?php else: ?>
                    <form method="post" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="invite_member">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label" for="member_id">Select student</label>
                                <select class="form-select" id="member_id" name="member_id" required>
                                    <option value="">-- Choose student --</option>
                                    <?php foreach ($invite_candidates as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?> (<?= e($c['reg_number'] ?: $c['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100">Add Member</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$is_archived && $uid !== $current_group['created_by']): ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="leave_group">
                    <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Leave this group?');">Leave Group</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Supervisor Contact</div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= e($group_project['supervisor_name'] ?? 'Not assigned') ?></strong></p>
                    <p class="mb-1 text-muted small"><?= e($group_project['supervisor_email'] ?? 'No supervisor email available') ?></p>
                    <a href="<?= base_url('messages.php') ?>" class="btn btn-outline-primary mt-2">Message Supervisor</a>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Next Meeting</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Preferred Date</label>
                            <input type="date" class="form-control" value="<?= e(date('Y-m-d', strtotime('+3 days'))) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Time</label>
                            <input type="text" class="form-control" value="10:00 AM" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <textarea class="form-control" rows="3" readonly>Propose a meeting time in Messages.</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Create New Group (Become Creator)</div>
                <div class="card-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_group">
                        <div class="mb-3">
                            <label class="form-label" for="group_name">Group Name</label>
                            <input type="text" class="form-control" id="group_name" name="group_name" required placeholder="e.g., IoT Smart City">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="group_description">Description (optional)</label>
                            <textarea class="form-control" id="group_description" name="group_description" rows="3" placeholder="Brief overview of your project..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Create Group</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Invite-Only Membership</div>
                <div class="card-body">
                    <p class="mb-2">Students cannot self-join groups.</p>
                    <p class="text-muted mb-0">Only group creators can add/invite members (maximum 5 members per group).</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
