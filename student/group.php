<?php
/**
 * Student Group Management - Create, join, and manage project groups
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

$uid = user_id();
$pdo = getPDO();
$error = '';
$success = '';

// Get current group for this student
$stmt = $pdo->prepare('SELECT g.*, COUNT(gm.id) AS member_count FROM groups g LEFT JOIN group_members gm ON g.id = gm.group_id WHERE g.id IN (SELECT group_id FROM group_members WHERE student_id = ?) GROUP BY g.id LIMIT 1');
$stmt->execute([$uid]);
$current_group = $stmt->fetch();

// Get available groups to join
$available_groups = [];
if (!$current_group) {
    $stmt = $pdo->query('SELECT g.id, g.name, g.description, COUNT(gm.id) AS member_count FROM groups g LEFT JOIN group_members gm ON g.id = gm.group_id WHERE g.is_active = 1 GROUP BY g.id HAVING member_count < 5');
    $available_groups = $stmt->fetchAll();
}

// Create new group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $_POST['action'] === 'create_group') {
    $group_name = trim($_POST['group_name'] ?? '');
    $group_desc = trim($_POST['group_description'] ?? '');
    
    if (strlen($group_name) < 3) {
        $error = 'Group name must be at least 3 characters.';
    } elseif (!$current_group) {
        $stmt = $pdo->prepare('INSERT INTO groups (name, description, created_by, is_active) VALUES (?, ?, ?, 1)');
        $stmt->execute([$group_name, $group_desc ?: null, $uid]);
        $group_id = $pdo->lastInsertId();
        
        // Add creator as group lead
        $pdo->prepare('INSERT INTO group_members (group_id, student_id, role) VALUES (?, ?, "lead")')->execute([$group_id, $uid]);
        
        flash('success', 'Group created successfully. You are the group lead.');
        redirect(base_url('student/group.php'));
    }
}

// Join group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $_POST['action'] === 'join_group') {
    $group_id = (int) ($_POST['group_id'] ?? 0);
    
    if (!$current_group && $group_id > 0) {
        $stmt = $pdo->prepare('SELECT id FROM groups WHERE id = ? AND is_active = 1');
        $stmt->execute([$group_id]);
        if ($stmt->fetch()) {
            $pdo->prepare('INSERT INTO group_members (group_id, student_id, role) VALUES (?, ?, "member")')->execute([$group_id, $uid]);
            flash('success', 'Joined group successfully.');
            redirect(base_url('student/group.php'));
        }
    }
}

// Leave group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $_POST['action'] === 'leave_group' && $current_group) {
    $stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND student_id = ?');
    $stmt->execute([$current_group['id'], $uid]);
    $member = $stmt->fetch();
    
    if ($member && $member['role'] !== 'lead') {
        $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND student_id = ?')->execute([$current_group['id'], $uid]);
        flash('success', 'You have left the group.');
        redirect(base_url('student/group.php'));
    } else {
        $error = 'Group leads cannot leave. Transfer lead role first or delete the group.';
    }
}

// Get group members if in a group
$group_members = [];
if ($current_group) {
    $stmt = $pdo->prepare('SELECT gm.*, u.full_name, u.email, u.reg_number FROM group_members gm JOIN users u ON gm.student_id = u.id WHERE gm.group_id = ? ORDER BY gm.role DESC, u.full_name');
    $stmt->execute([$current_group['id']]);
    $group_members = $stmt->fetchAll();
}

$pageTitle = 'My Group';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="mb-4">My Project Group</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($current_group): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= e($current_group['name']) ?></h5>
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
            
            <?php if ($uid !== $current_group['created_by']): ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="leave_group">
                    <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Leave this group?');">Leave Group</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Create New Group</div>
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
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Join Existing Group</div>
                <div class="card-body">
                    <?php if (empty($available_groups)): ?>
                        <p class="text-muted mb-0">No available groups at the moment.</p>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="join_group">
                            <div class="mb-3">
                                <label class="form-label" for="group_id">Select Group</label>
                                <select class="form-select" id="group_id" name="group_id" required>
                                    <option value="">-- Choose a group --</option>
                                    <?php foreach ($available_groups as $g): ?>
                                        <option value="<?= $g['id'] ?>"><?= e($g['name']) ?> (<?= $g['member_count'] ?>/5 members)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Join Group</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
