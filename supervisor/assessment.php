<?php
/**
 * RMU Project Work Score Sheet (Write-Up)
 * Regional Maritime University — Faculty of Engineering
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

$uid = user_id();
$pdo = getPDO();
ensure_rmu_assessment_columns($pdo);

$pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$stmt = $pdo->prepare(
    'SELECT p.*, u.full_name AS student_name, u.email, u.index_number,
            sv.full_name AS supervisor_name, sv.department AS supervisor_dept,
            g.name AS group_name,
            (SELECT GROUP_CONCAT(CONCAT(u2.full_name," (",COALESCE(NULLIF(u2.index_number,""),u2.email),")")
                     ORDER BY CASE WHEN gm2.role="lead" THEN 0 ELSE 1 END, u2.full_name SEPARATOR "; ")
             FROM group_members gm2 JOIN users u2 ON u2.id = gm2.student_id
             WHERE gm2.group_id = p.group_id) AS member_list
     FROM projects p
     JOIN users u  ON p.student_id  = u.id
     JOIN users sv ON sv.id         = p.supervisor_id
     LEFT JOIN `groups` g ON g.id  = p.group_id
     WHERE p.id = ? AND p.supervisor_id = ?'
);
$stmt->execute([$pid, $uid]);
$project = $stmt->fetch();

if (!$project) {
    flash('error', 'Project not found.');
    redirect(base_url('supervisor/students.php'));
}

$is_archived = ($project['status'] ?? '') === 'archived';

/* ─── Resolve department label ──────────────────────────────────────────── */
$dept_info  = resolve_department_info($pdo, (string) ($project['supervisor_dept'] ?? ''));
$dept_label = strtoupper($dept_info['name'] ?: $dept_info['raw'] ?: 'DEPARTMENT');

/* ─── Group member names + all index numbers ────────────────────────────── */
$students_display = $project['member_list'] ?: $project['student_name'];

if (!empty($project['group_id'])) {
    $ix = $pdo->prepare(
        'SELECT COALESCE(NULLIF(u.index_number,""), u.email) AS idx
         FROM group_members gm JOIN users u ON u.id = gm.student_id
         WHERE gm.group_id = ?
         ORDER BY CASE WHEN gm.role="lead" THEN 0 ELSE 1 END, u.full_name'
    );
    $ix->execute([(int) $project['group_id']]);
    $index_display = implode('; ', array_column($ix->fetchAll(), 'idx'));
} else {
    $index_display = $project['index_number'] ?? '';
}

/* ─── Supervisor initials ───────────────────────────────────────────────── */
$sup_initials = '';
foreach (preg_split('/\s+/', preg_replace('/\b(Mr|Mrs|Ms|Dr|Prof|Engr)\.?\s*/i', '', $project['supervisor_name'])) as $word) {
    if ($word !== '') $sup_initials .= strtoupper($word[0]) . '.';
}

/* ─── Helper: all member IDs ────────────────────────────────────────────── */
function assessment_recipient_ids(PDO $pdo, array $project): array {
    $ids = [(int) $project['student_id']];
    if (!empty($project['group_id'])) {
        $s = $pdo->prepare('SELECT student_id FROM `group_members` WHERE group_id = ?');
        $s->execute([(int) $project['group_id']]);
        foreach ($s->fetchAll() as $m) $ids[] = (int) $m['student_id'];
    }
    return array_values(array_unique(array_filter($ids)));
}

/* ─── Score sheet criteria (section => [label, max]) ────────────────────── */
$criteria = [
    'aims_objectives'            => ['Aims & Objectives',                                                      5],
    'literature_review'          => ['Literature Review (understanding the field of study)',                    5],
    'methodology_strength'       => ['Strength and Limitations of Methodology',                                5],
    'data_collection'            => ['Data Collection and Information',                                        5],
    'logical_arguments'          => ['Logical Arguments',                                                      5],
    'conclusions_recommendations'=> ['Conclusions and Recommendations',                                        5],
    'writing'                    => ['Writing',                                                                10],
    'presentation'               => ['Presentation',                                                           5],
    'safety_ethics'              => ['Understanding and Consideration of Safety, Ethics and Sustainability Issues Raised by Project', 5],
    'logbook_score'              => ['Logbook',                                                               10],
];
$grand_max = array_sum(array_column($criteria, 1)); // 60

$error = '';

/* ─── POST ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($is_archived) {
        flash('error', 'This project is archived. Assessments cannot be submitted.');
        redirect(base_url('supervisor/assessment.php?pid=' . $pid));
    }

    $scores  = [];
    $invalid = false;

    foreach ($criteria as $field => [$label, $max]) {
        $val = $_POST[$field] ?? '';
        if ($val === '' || $val === null) {
            $scores[$field] = null;
        } else {
            $v = (float) $val;
            if ($v < 0 || $v > $max) {
                $error   = "Score for \"$label\" must be between 0 and $max.";
                $invalid = true;
                break;
            }
            $scores[$field] = $v;
        }
    }

    $remarks = trim($_POST['remarks'] ?? '');
    if (!$invalid && !$remarks) {
        $error = 'Written remarks / feedback are required.';
        $invalid = true;
    }

    if (!$invalid) {
        $filled      = array_filter($scores, fn($v) => $v !== null);
        $total_score = !empty($filled) ? array_sum($filled) : null;

        $pdo->prepare(
            "INSERT INTO assessments
             (project_id, supervisor_id, assessment_type,
              aims_objectives, literature_review, methodology_strength,
              data_collection, logical_arguments, conclusions_recommendations,
              writing, presentation, safety_ethics, logbook_score,
              score, max_score, remarks, supervisor_confirmed, confirmed_at, submitted_at)
             VALUES (?, ?, 'final_evaluation', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
              aims_objectives = VALUES(aims_objectives),
              literature_review = VALUES(literature_review),
              methodology_strength = VALUES(methodology_strength),
              data_collection = VALUES(data_collection),
              logical_arguments = VALUES(logical_arguments),
              conclusions_recommendations = VALUES(conclusions_recommendations),
              writing = VALUES(writing),
              presentation = VALUES(presentation),
              safety_ethics = VALUES(safety_ethics),
              logbook_score = VALUES(logbook_score),
              score = VALUES(score),
              remarks = VALUES(remarks),
              supervisor_confirmed = 1,
              confirmed_at = NOW(),
              submitted_at = NOW()"
        )->execute([
            $pid, $uid,
            $scores['aims_objectives'],
            $scores['literature_review'],
            $scores['methodology_strength'],
            $scores['data_collection'],
            $scores['logical_arguments'],
            $scores['conclusions_recommendations'],
            $scores['writing'],
            $scores['presentation'],
            $scores['safety_ethics'],
            $scores['logbook_score'],
            $total_score, $grand_max, $remarks,
        ]);

        foreach (assessment_recipient_ids($pdo, $project) as $mid) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([$mid, 'assessment_submitted', 'Assessment Submitted',
                    'Your supervisor has submitted the project score sheet.', base_url('student/project.php')]);
        }

        flash('success', 'Score sheet submitted and confirmed.');
        redirect(base_url('supervisor/assessment.php?pid=' . $pid));
    }
}

/* ─── Current assessment ─────────────────────────────────────────────────── */
$stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? AND supervisor_id = ? ORDER BY submitted_at DESC LIMIT 1');
$stmt->execute([$pid, $uid]);
$cur = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM assessments WHERE project_id = ? ORDER BY submitted_at DESC LIMIT 10');
$stmt->execute([$pid]);
$history = $stmt->fetchAll();

$pageTitle = 'Score Sheet';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1 class="mb-1">Score Sheet</h1>
        <p class="text-muted mb-0"><a href="<?= base_url('supervisor/student_detail.php?pid=' . $pid) ?>">← Back to project</a></p>
    </div>
    <?php if ($cur && $cur['supervisor_confirmed']): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Score Sheet
        </button>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($is_archived): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-archive-fill fs-5"></i>
        <div>This project is <strong>archived</strong>. Score history is shown below, but new submissions are not allowed.</div>
    </div>
<?php endif; ?>

<?php if (!$is_archived): ?>
<div class="card mb-4" id="score-sheet-card">
    <!-- Institution header -->
    <div class="card-body text-center border-bottom pb-3">
        <p class="fw-bold mb-0" style="font-size:1.1rem; letter-spacing:.04em;">REGIONAL MARITIME UNIVERSITY</p>
        <p class="mb-0 small">FACULTY OF ENGINEERING</p>
        <p class="mb-2 fw-semibold small text-decoration-underline"><?= e($dept_label) ?> DEPARTMENT</p>
        <p class="fw-bold mb-0 text-decoration-underline">PROJECT WORK SCORE SHEET (Write-Up)</p>
    </div>

    <div class="card-body">
        <!-- Project / student identifiers -->
        <table class="table table-sm table-borderless mb-4" style="max-width:100%;">
            <tbody>
                <tr>
                    <td class="fw-semibold text-nowrap ps-0" style="width:200px;">Project Title</td>
                    <td class="text-muted"><?= e($project['title']) ?></td>
                </tr>
                <tr>
                    <td class="fw-semibold text-nowrap ps-0">Student(s) Name(s)</td>
                    <td class="text-muted"><?= e($students_display) ?></td>
                </tr>
                <tr>
                    <td class="fw-semibold text-nowrap ps-0">Student(s) Index No.</td>
                    <td class="text-muted"><?= e($index_display) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Score form -->
        <form method="post" id="score-form">
            <?= csrf_field() ?>

            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle mb-0" id="score-table">
                    <thead>
                        <tr class="text-center">
                            <th class="text-start" style="width:60%;">SECTIONS</th>
                            <th style="width:20%;">ACTUAL MARKS</th>
                            <th style="width:20%;">ACHIEVED MARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($criteria as $field => [$label, $max]):
                            $saved = $cur[$field] ?? null;
                        ?>
                        <tr>
                            <td class="small"><?= e($label) ?></td>
                            <td class="text-center fw-semibold"><?= $max ?></td>
                            <td>
                                <input type="number" name="<?= $field ?>" id="<?= $field ?>"
                                       class="form-control form-control-sm score-input text-center"
                                       min="0" max="<?= $max ?>" step="0.5"
                                       value="<?= $saved !== null ? $saved : '' ?>"
                                       placeholder="/ <?= $max ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td class="text-end">TOTAL</td>
                            <td class="text-center"><?= $grand_max ?></td>
                            <td class="text-center">
                                <input type="text" id="total_achieved" class="form-control form-control-sm text-center fw-bold" disabled
                                       value="<?= $cur && $cur['score'] !== null ? $cur['score'] : '' ?>">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Remarks -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Remarks / Feedback <span class="text-danger">*</span></label>
                <textarea name="remarks" class="form-control" rows="4" required
                          placeholder="Provide detailed feedback on the project's strengths, weaknesses, and overall performance..."><?= $cur ? e($cur['remarks'] ?? $cur['comments'] ?? '') : '' ?></textarea>
            </div>

            <!-- Lecturer signature row -->
            <div class="row g-3 mb-4 small">
                <div class="col-md-6">
                    <label class="form-label">Name of Lecturer</label>
                    <input type="text" class="form-control form-control-sm" value="<?= e($project['supervisor_name']) ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Signature</label>
                    <div class="border-bottom border-secondary d-flex align-items-end pb-1" style="height:38px;">
                        <span class="fw-bold fst-italic" style="font-size:1.1rem; letter-spacing:.06em;"><?= e($sup_initials) ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="text" class="form-control form-control-sm" value="<?= date('d / m / Y') ?>" disabled>
                </div>
            </div>

            <div class="alert alert-info d-flex gap-2 align-items-center py-2">
                <i class="bi bi-info-circle-fill"></i>
                <span>Submitting confirms this assessment. The student group will be notified.</span>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1"></i> Submit Score Sheet
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Assessment history -->
<?php if (!empty($history)): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1"></i> Submission History</span>
        <span class="badge bg-secondary"><?= count($history) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="text-center small">
                        <th class="text-start ps-3">Submitted</th>
                        <th>Aims</th>
                        <th>Lit. Rev.</th>
                        <th>Method.</th>
                        <th>Data</th>
                        <th>Logic</th>
                        <th>Concl.</th>
                        <th>Writing</th>
                        <th>Present.</th>
                        <th>Safety</th>
                        <th>Logbook</th>
                        <th>Total</th>
                        <th class="pe-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $a): ?>
                    <tr class="text-center small">
                        <td class="text-start ps-3 text-nowrap"><?= e(date('M j, Y', strtotime($a['submitted_at']))) ?></td>
                        <td><?= $a['aims_objectives']             !== null ? $a['aims_objectives']             : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['literature_review']           !== null ? $a['literature_review']           : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['methodology_strength']        !== null ? $a['methodology_strength']        : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['data_collection']             !== null ? $a['data_collection']             : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['logical_arguments']           !== null ? $a['logical_arguments']           : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['conclusions_recommendations'] !== null ? $a['conclusions_recommendations'] : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['writing']                     !== null ? $a['writing']                     : '—' ?> <span class="text-muted">/10</span></td>
                        <td><?= $a['presentation']                !== null ? $a['presentation']                : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['safety_ethics']               !== null ? $a['safety_ethics']               : '—' ?> <span class="text-muted">/5</span></td>
                        <td><?= $a['logbook_score']               !== null ? $a['logbook_score']               : '—' ?> <span class="text-muted">/10</span></td>
                        <td class="fw-bold"><?= $a['score'] !== null ? $a['score'] . '<span class="text-muted fw-normal">/60</span>' : '—' ?></td>
                        <td class="pe-3">
                            <?php if ($a['supervisor_confirmed']): ?>
                                <span class="badge bg-success">Confirmed</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Draft</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($a['remarks'] ?? $a['comments'])): ?>
                    <tr>
                        <td colspan="13" class="text-start ps-3 pb-2 text-muted small border-top-0">
                            <i class="bi bi-chat-left-quote me-1"></i><?= nl2br(e(mb_substr($a['remarks'] ?? $a['comments'] ?? '', 0, 300))) ?><?= mb_strlen($a['remarks'] ?? $a['comments'] ?? '') > 300 ? '…' : '' ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const maxes = <?= json_encode(array_map(fn($v) => $v[1], $criteria)) ?>;
    const fields = <?= json_encode(array_keys($criteria)) ?>;

    function recalc() {
        let total = 0, anyFilled = false;
        fields.forEach(function (f) {
            const el = document.getElementById(f);
            if (!el) return;
            const v = parseFloat(el.value);
            if (!isNaN(v) && v >= 0) { total += v; anyFilled = true; }
        });
        const out = document.getElementById('total_achieved');
        if (out) out.value = anyFilled ? total.toFixed(1) : '';
    }

    fields.forEach(function (f) {
        const el = document.getElementById(f);
        if (el) el.addEventListener('input', recalc);
    });
    recalc();
})();
</script>

<style>
@media print {
    nav, .sidebar, header, footer, .btn, .alert-info, #score-form button { display: none !important; }
    #score-sheet-card { border: none !important; }
    body { background: white !important; color: black !important; }
    .card { border: none !important; box-shadow: none !important; }
    table { color: black !important; }
    input, textarea { color: black !important; border: 1px solid #999 !important; background: white !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
