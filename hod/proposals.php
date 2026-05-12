<?php
/**
 * HOD — Proposals Viewer
 * Lists every group/project proposal in the HOD's department.
 * HOD can download, preview, and save private notes on each proposal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
require_role('hod');

$pdo = getPDO();
$uid = user_id();

/* ─── Ensure hod_proposal_notes table exists ───────────────────────────── */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `hod_proposal_notes` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `hod_id`      INT UNSIGNED NOT NULL,
        `project_id`  INT UNSIGNED NULL DEFAULT NULL,
        `group_sub_id` INT UNSIGNED NULL DEFAULT NULL,
        `note`        TEXT NOT NULL,
        `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_hod_project`  (`hod_id`, `project_id`),
        UNIQUE KEY `uq_hod_group_sub` (`hod_id`, `group_sub_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ─── Department info ───────────────────────────────────────────────────── */
$hod_user            = get_user_by_id($uid);
$hod_dept_info       = resolve_department_info($pdo, (string) ($hod_user['department'] ?? ''));
$hod_dept_vars       = $hod_dept_info['variants'];
$hod_dept_label      = $hod_dept_info['name'] ?: $hod_dept_info['raw'];
$dept_scope_err      = empty($hod_dept_vars)
    ? 'Your HOD account does not have a valid department configured. Contact admin.' : '';

/* ─── POST: save note ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $note_text    = trim($_POST['note']         ?? '');
    $project_id   = (int) ($_POST['project_id']   ?? 0);
    $group_sub_id = (int) ($_POST['group_sub_id'] ?? 0);

    if ($note_text !== '' && ($project_id || $group_sub_id)) {
        if ($project_id) {
            $pdo->prepare(
                "INSERT INTO hod_proposal_notes (hod_id, project_id, note)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = NOW()"
            )->execute([$uid, $project_id, $note_text]);
        } elseif ($group_sub_id) {
            $pdo->prepare(
                "INSERT INTO hod_proposal_notes (hod_id, group_sub_id, note)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = NOW()"
            )->execute([$uid, $group_sub_id, $note_text]);
        }
        flash('success', 'Note saved.');
    } elseif ($note_text === '' && ($project_id || $group_sub_id)) {
        // Delete note if cleared
        if ($project_id) {
            $pdo->prepare("DELETE FROM hod_proposal_notes WHERE hod_id=? AND project_id=?")
                ->execute([$uid, $project_id]);
        } else {
            $pdo->prepare("DELETE FROM hod_proposal_notes WHERE hod_id=? AND group_sub_id=?")
                ->execute([$uid, $group_sub_id]);
        }
        flash('success', 'Note cleared.');
    }
    redirect(base_url('hod/proposals.php'));
}

/* ─── Fetch proposals ───────────────────────────────────────────────────── */
$proposals = [];

if (!empty($hod_dept_vars)) {
    $ph = sql_placeholders(count($hod_dept_vars));

    // 1. Individual / self-formed group: proposals in project_documents
    $stmt = $pdo->prepare(
        "SELECT 'project' AS source,
                p.id AS project_id, NULL AS group_sub_id,
                p.title, p.status AS proj_status,
                p.group_id,
                u.full_name AS lead_name, u.index_number, u.email,
                g.name AS group_name,
                (SELECT GROUP_CONCAT(CONCAT(u2.full_name,' (',COALESCE(NULLIF(u2.index_number,''),u2.email),')')
                         ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name SEPARATOR ', ')
                 FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
                 WHERE gm2.group_id = p.group_id) AS member_directory,
                pd.id AS doc_id, pd.file_name AS doc_name, pd.uploaded_at AS doc_at,
                NULL AS gs_doc_path, NULL AS gs_submitted_at,
                sv.full_name AS supervisor_name,
                n.note AS hod_note, n.updated_at AS note_at
         FROM projects p
         JOIN users u ON p.student_id = u.id
         LEFT JOIN `groups` g ON g.id = p.group_id
         JOIN project_documents pd ON pd.project_id = p.id AND pd.document_type = 'proposal' AND pd.is_latest = 1
         LEFT JOIN users sv ON sv.id = p.supervisor_id
         LEFT JOIN hod_proposal_notes n ON n.project_id = p.id AND n.hod_id = ?
         WHERE LOWER(TRIM(COALESCE(u.department,''))) IN ($ph)
           AND p.status NOT IN ('draft')
         ORDER BY pd.uploaded_at DESC"
    );
    $stmt->execute(array_merge([$uid], $hod_dept_vars));
    foreach ($stmt->fetchAll() as $row) $proposals[] = $row;

    // 2. HOD-formed group submissions with a document
    $stmt = $pdo->prepare(
        "SELECT 'group_sub' AS source,
                NULL AS project_id, gs.id AS group_sub_id,
                gs.title, gs.status AS proj_status,
                gs.group_id,
                u.full_name AS lead_name, u.index_number, u.email,
                g.name AS group_name,
                (SELECT GROUP_CONCAT(CONCAT(u2.full_name,' (',COALESCE(NULLIF(u2.index_number,''),u2.email),')')
                         ORDER BY CASE WHEN gm2.role='lead' THEN 0 ELSE 1 END, u2.full_name SEPARATOR ', ')
                 FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
                 WHERE gm2.group_id = g.id) AS member_directory,
                NULL AS doc_id, NULL AS doc_name, NULL AS doc_at,
                gs.document_path AS gs_doc_path, gs.submitted_at AS gs_submitted_at,
                NULL AS supervisor_name,
                n.note AS hod_note, n.updated_at AS note_at
         FROM group_submissions gs
         JOIN `groups` g ON g.id = gs.group_id
         LEFT JOIN users u ON u.id = gs.submitted_by
         LEFT JOIN hod_proposal_notes n ON n.group_sub_id = gs.id AND n.hod_id = ?
         WHERE gs.document_path IS NOT NULL
           AND LOWER(TRIM(COALESCE(g.department,''))) IN ($ph)
         ORDER BY gs.submitted_at DESC"
    );
    $stmt->execute(array_merge([$uid], $hod_dept_vars));
    foreach ($stmt->fetchAll() as $row) $proposals[] = $row;

    // Sort by most recent document
    usort($proposals, function($a, $b) {
        $ta = strtotime($a['doc_at'] ?? $a['gs_submitted_at'] ?? '0');
        $tb = strtotime($b['doc_at'] ?? $b['gs_submitted_at'] ?? '0');
        return $tb <=> $ta;
    });
}

$pageTitle = 'Proposals';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-0">Proposals</h1>
        <p class="text-muted mb-0 small mt-1">Department: <strong><?= e($hod_dept_label ?: 'Unknown') ?></strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= base_url('hod/topics.php') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-check2-circle me-1"></i> Topic Approval
        </a>
        <a href="<?= base_url('hod/group_review.php') ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-person-check me-1"></i> Assign Supervisors
        </a>
    </div>
</div>

<?php flash_messages(); ?>

<?php if ($dept_scope_err): ?>
    <div class="alert alert-danger"><?= e($dept_scope_err) ?></div>
<?php elseif (empty($proposals)): ?>
    <div class="card">
        <div class="card-body text-muted">No proposals have been submitted yet in your department.</div>
    </div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($proposals as $idx => $p):
        $is_gs   = $p['source'] === 'group_sub';
        $date    = $p['doc_at'] ?? $p['gs_submitted_at'] ?? null;
        $status  = $p['proj_status'] ?? 'pending';
        $s_class = match($status) {
            'approved', 'in_progress', 'completed' => 'bg-success',
            'rejected'                              => 'bg-danger',
            'submitted', 'under_review'             => 'bg-warning text-dark',
            default                                 => 'bg-secondary',
        };
        $s_label = match($status) {
            'approved'     => 'Approved',
            'in_progress'  => 'In Progress',
            'submitted'    => 'Submitted',
            'under_review' => 'Under Review',
            'rejected'     => 'Rejected',
            'completed'    => 'Completed',
            default        => ucfirst($status),
        };
        $note_id = $is_gs ? ('gs_' . $p['group_sub_id']) : ('proj_' . $p['project_id']);
    ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($p['group_id']): ?>
                        <span class="badge bg-info text-dark"><i class="bi bi-people-fill me-1"></i><?= e($p['group_name'] ?: ('Group #' . $p['group_id'])) ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="bi bi-person me-1"></i>Solo</span>
                    <?php endif; ?>
                    <strong><?= e($p['title']) ?></strong>
                </div>
                <span class="badge <?= $s_class ?>"><?= $s_label ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <!-- Left: info + download -->
                    <div class="col-md-6">
                        <?php if ($p['group_id'] && $p['member_directory']): ?>
                            <p class="mb-1 small text-muted">
                                <i class="bi bi-people me-1"></i> <?= e($p['member_directory']) ?>
                            </p>
                        <?php else: ?>
                            <p class="mb-1 small text-muted">
                                <i class="bi bi-person me-1"></i>
                                <?= e($p['lead_name']) ?>
                                <?= $p['index_number'] ? ' (' . e($p['index_number']) . ')' : '' ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($p['supervisor_name']): ?>
                            <p class="mb-1 small text-muted">
                                <i class="bi bi-person-check-fill text-success me-1"></i>
                                Supervisor: <strong><?= e($p['supervisor_name']) ?></strong>
                            </p>
                        <?php endif; ?>
                        <?php if ($date): ?>
                            <p class="mb-2 small text-muted">
                                <i class="bi bi-clock me-1"></i> <?= e(date('M j, Y H:i', strtotime($date))) ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($p['doc_id']): ?>
                            <a href="<?= base_url('download.php?id=' . (int)$p['doc_id']) ?>"
                               class="btn btn-sm btn-outline-info" target="_blank">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>
                                <?= e($p['doc_name'] ?: 'Download Proposal') ?>
                            </a>
                        <?php elseif ($p['gs_doc_path']): ?>
                            <a href="<?= base_url(e($p['gs_doc_path'])) ?>"
                               class="btn btn-sm btn-outline-info" target="_blank">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Proposal
                            </a>
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-file-earmark-x me-1"></i>No document uploaded</span>
                        <?php endif; ?>
                    </div>

                    <!-- Right: HOD notes -->
                    <div class="col-md-6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <?php if ($is_gs): ?>
                                <input type="hidden" name="group_sub_id" value="<?= (int)$p['group_sub_id'] ?>">
                            <?php else: ?>
                                <input type="hidden" name="project_id" value="<?= (int)$p['project_id'] ?>">
                            <?php endif; ?>
                            <label class="form-label small fw-semibold">
                                <i class="bi bi-journal-text me-1"></i> HOD Notes
                                <?php if ($p['note_at']): ?>
                                    <span class="fw-normal text-muted ms-1">(saved <?= e(date('M j, Y', strtotime($p['note_at']))) ?>)</span>
                                <?php endif; ?>
                            </label>
                            <textarea name="note" rows="3" class="form-control form-control-sm mb-2"
                                      placeholder="Add private notes about this proposal…"><?= e($p['hod_note'] ?? '') ?></textarea>
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-save me-1"></i> Save Note
                            </button>
                            <?php if ($p['hod_note']): ?>
                                <button type="submit" name="note" value="" class="btn btn-sm btn-outline-secondary ms-1"
                                        onclick="return confirm('Clear this note?')">
                                    <i class="bi bi-trash me-1"></i> Clear
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
