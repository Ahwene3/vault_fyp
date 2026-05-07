<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo  = getPDO();
$user = current_user();
$uid  = user_id();
$role = $user['role'] ?? '';

ensure_project_keywords_column($pdo);
ensure_discovery_tables($pdo);

$project_id = (int) ($_GET['id'] ?? 0);
if (!$project_id) {
    redirect(base_url('vault.php'));
}

// Fetch project (must be publicly visible)
$stmt = $pdo->prepare("SELECT p.*,
    stu.full_name AS student_name, stu.department, stu.email AS student_email, stu.reg_number,
    sup.full_name AS supervisor_name, sup.email AS supervisor_email
    FROM projects p
    LEFT JOIN users stu ON stu.id = p.student_id
    LEFT JOIN users sup ON sup.id = p.supervisor_id
    WHERE p.id = ? AND p.status IN ('approved','in_progress','completed','archived')
    LIMIT 1");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    redirect(base_url('vault.php'));
}

// Record view + build interest signals
record_project_view($pdo, $project_id, $uid ?: null);

if ($uid) {
    // Interest from tags of this project
    $tg = $pdo->prepare('SELECT pt.slug, pt.domain FROM project_tag_map tm JOIN project_tags pt ON pt.id=tm.tag_id WHERE tm.project_id=?');
    $tg->execute([$project_id]);
    foreach ($tg->fetchAll() as $t) {
        upsert_interest($pdo, $uid, 'tag',    $t['slug']);
        upsert_interest($pdo, $uid, 'domain', $t['domain']);
    }
    // Interest from keywords
    foreach (array_filter(array_map('trim', explode(',', $project['keywords'] ?? ''))) as $kw) {
        if (strlen($kw) >= 3) upsert_interest($pdo, $uid, 'keyword', strtolower($kw));
    }
}

// Tags for this project
$tags_stmt = $pdo->prepare('SELECT pt.name, pt.slug, pt.color, pt.icon, pt.domain FROM project_tag_map tm JOIN project_tags pt ON pt.id=tm.tag_id WHERE tm.project_id=? ORDER BY pt.name');
$tags_stmt->execute([$project_id]);
$project_tags = $tags_stmt->fetchAll();

// Documents
$docs_stmt = $pdo->prepare("SELECT id, document_type, chapter, file_name, file_size, mime_type, uploaded_at FROM project_documents WHERE project_id=? AND is_latest=1 ORDER BY uploaded_at DESC");
$docs_stmt->execute([$project_id]);
$documents = $docs_stmt->fetchAll();

// Ratings — aggregated
$agg_stmt = $pdo->prepare('SELECT AVG(rating) AS avg_r, COUNT(*) AS cnt, SUM(rating=5) AS s5, SUM(rating=4) AS s4, SUM(rating=3) AS s3, SUM(rating=2) AS s2, SUM(rating=1) AS s1 FROM project_ratings WHERE project_id=? AND status="visible"');
$agg_stmt->execute([$project_id]);
$agg = $agg_stmt->fetch();
$avg_rating  = $agg['avg_r'] ? (float) $agg['avg_r'] : 0.0;
$rating_cnt  = (int) $agg['cnt'];

// Current user's existing rating
$my_rating = null;
if ($uid) {
    $mr = $pdo->prepare('SELECT rating, comment FROM project_ratings WHERE project_id=? AND user_id=? LIMIT 1');
    $mr->execute([$project_id, $uid]);
    $my_rating = $mr->fetch();
}

// Recent visible reviews (max 8)
$rev_stmt = $pdo->prepare('SELECT pr.id, pr.rating, pr.comment, pr.created_at, pr.updated_at, u.full_name, u.role
    FROM project_ratings pr JOIN users u ON u.id=pr.user_id
    WHERE pr.project_id=? AND pr.status="visible"
    ORDER BY pr.updated_at DESC LIMIT 8');
$rev_stmt->execute([$project_id]);
$reviews = $rev_stmt->fetchAll();

// Related projects (by shared tags, excluding this one)
$related = [];
if ($project_tags) {
    $tag_ids = array_map(fn($t) => (int)$pdo->query("SELECT id FROM project_tags WHERE slug='".$pdo->quote($t['slug'])."' LIMIT 1")->fetchColumn(), $project_tags);
    $tag_ids = array_filter($tag_ids);
    if ($tag_ids) {
        $in = sql_placeholders(count($tag_ids));
        $rel_stmt = $pdo->prepare("SELECT DISTINCT p.id, p.title, p.avg_rating, p.view_count, u.full_name AS student_name
            FROM projects p JOIN project_tag_map tm ON tm.project_id=p.id LEFT JOIN users u ON u.id=p.student_id
            WHERE tm.tag_id IN ($in) AND p.id != ? AND p.status IN ('approved','in_progress','completed','archived')
            ORDER BY p.avg_rating DESC, p.view_count DESC LIMIT 4");
        $rel_stmt->execute(array_merge($tag_ids, [$project_id]));
        $related = $rel_stmt->fetchAll();
    }
}

// Logbook count (public stat)
$lb_count = (int) $pdo->prepare('SELECT COUNT(*) FROM logbook_entries WHERE project_id=?')->execute([$project_id]) ? $pdo->query("SELECT COUNT(*) FROM logbook_entries WHERE project_id=$project_id")->fetchColumn() : 0;

$pageTitle = e(mb_strimwidth($project['title'], 0, 50, '…')) . ' — Project Detail';
require_once __DIR__ . '/includes/header.php';

function star_bar(int $n, int $total): string {
    $pct = $total > 0 ? round($n / $total * 100) : 0;
    return '<div class="d-flex align-items-center gap-2" style="font-size:.78rem;">
        <div class="progress flex-grow-1" style="height:7px;border-radius:4px;background:#f0f0f0;">
            <div class="progress-bar bg-warning" style="width:' . $pct . '%;border-radius:4px;"></div>
        </div>
        <span style="min-width:28px;color:#6c757d;">' . $n . '</span>
    </div>';
}

function star_html_lg(float $r, string $size = '1.1rem'): string {
    $h = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($r >= $i)        $h .= '<i class="bi bi-star-fill text-warning" style="font-size:'.$size.';"></i>';
        elseif ($r >= $i-.5) $h .= '<i class="bi bi-star-half text-warning" style="font-size:'.$size.';"></i>';
        else                 $h .= '<i class="bi bi-star text-secondary" style="font-size:'.$size.';opacity:.4;"></i>';
    }
    return $h;
}

$status_badge = match($project['status']) {
    'completed'  => ['success', 'Completed'],
    'in_progress'=> ['primary', 'In Progress'],
    'approved'   => ['info',    'Approved'],
    'archived'   => ['secondary','Archived'],
    default      => ['warning', 'Submitted'],
};
?>

<style>
.pd-hero{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);border-radius:16px;padding:32px;margin-bottom:28px;color:#fff;position:relative;overflow:hidden;}
.pd-hero::before{content:'';position:absolute;top:-50px;right:-50px;width:200px;height:200px;background:rgba(59,130,246,.1);border-radius:50%;}
.pd-title{font-size:1.6rem;font-weight:800;color:#fff;line-height:1.3;margin-bottom:10px;}
.pd-meta span{font-size:.82rem;color:rgba(255,255,255,.65);display:inline-flex;align-items:center;gap:5px;margin-right:16px;}
.pd-meta span i{color:rgba(255,255,255,.5);}

.rating-box{background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #fde68a;border-radius:14px;padding:22px;}
.big-rating{font-size:3.5rem;font-weight:900;color:#d97706;line-height:1;}
.tag-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:14px;font-size:.78rem;font-weight:600;margin:3px;text-decoration:none;transition:opacity .18s;}
.tag-chip:hover{opacity:.8;}

.review-card{border:1px solid #e9ecef;border-radius:12px;padding:16px 18px;margin-bottom:12px;transition:box-shadow .2s;}
.review-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.08);}
.reviewer-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.88rem;flex-shrink:0;}

.star-input{display:flex;flex-direction:row-reverse;gap:4px;}
.star-input input{display:none;}
.star-input label{font-size:1.8rem;cursor:pointer;color:#d1d5db;transition:color .15s;}
.star-input input:checked ~ label,
.star-input label:hover,
.star-input label:hover ~ label{color:#f59e0b;}

.related-card{border-radius:10px;border:1px solid #e9ecef;padding:12px 14px;text-decoration:none;color:inherit;display:block;transition:box-shadow .2s;}
.related-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.1);color:inherit;}
</style>

<!-- ══ HERO ══════════════════════════════════════════════════════════════════ -->
<div class="pd-hero mb-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
        <span class="badge bg-<?= $status_badge[0] ?> px-3 py-2 fs-7"><?= $status_badge[1] ?></span>
        <?php foreach ($project_tags as $t): ?>
            <a href="<?= base_url('vault.php?tag='.urlencode($t['slug'])) ?>"
               class="tag-chip" style="background:<?= $t['color'] ?>33;color:<?= $t['color'] ?>;border:1.5px solid <?= $t['color'] ?>66;">
                <i class="<?= e($t['icon']) ?>"></i><?= e($t['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <h1 class="pd-title"><?= e($project['title']) ?></h1>
    <div class="pd-meta">
        <?php if ($project['student_name']): ?>
            <span><i class="bi bi-person"></i> <?= e($project['student_name']) ?></span>
        <?php endif; ?>
        <?php if ($project['supervisor_name']): ?>
            <span><i class="bi bi-person-badge"></i> <?= e($project['supervisor_name']) ?></span>
        <?php endif; ?>
        <?php if ($project['department']): ?>
            <span><i class="bi bi-building"></i> <?= e($project['department']) ?></span>
        <?php endif; ?>
        <?php if ($project['academic_year']): ?>
            <span><i class="bi bi-calendar3"></i> <?= e($project['academic_year']) ?></span>
        <?php endif; ?>
        <span><i class="bi bi-eye"></i> <?= number_format($project['view_count']) ?> views</span>
        <?php if ($rating_cnt > 0): ?>
            <span><i class="bi bi-star-fill" style="color:#f59e0b;"></i> <?= number_format($avg_rating,1) ?> (<?= $rating_cnt ?> reviews)</span>
        <?php endif; ?>
    </div>
    <div class="mt-3">
        <a href="<?= base_url('vault.php') ?>" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i> Back to Vault
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- ══ MAIN LEFT COLUMN ══════════════════════════════════════════════════ -->
    <div class="col-lg-8">

        <!-- Keywords & Tech Stack -->
        <?php if ($project['keywords'] || $project['technology_stack']): ?>
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body">
                <?php if ($project['keywords']): ?>
                    <div class="mb-3">
                        <div class="fw-bold mb-2" style="font-size:.88rem;color:#374151;"><i class="bi bi-tags me-1 text-primary"></i> Keywords</div>
                        <div>
                            <?php foreach (array_filter(array_map('trim', explode(',', $project['keywords']))) as $kw): ?>
                                <a href="<?= base_url('vault.php?q=' . urlencode($kw)) ?>" class="badge bg-secondary text-white me-1 mb-1 text-decoration-none" style="font-weight:500;font-size:.78rem;"><?= e($kw) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($project['technology_stack']): ?>
                    <div>
                        <div class="fw-bold mb-2" style="font-size:.88rem;color:#374151;"><i class="bi bi-code-slash me-1 text-success"></i> Technology Stack</div>
                        <div>
                            <?php foreach (array_filter(array_map('trim', explode(',', $project['technology_stack']))) as $ti): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 me-1 mb-1" style="font-size:.78rem;font-weight:500;"><?= e($ti) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Documents -->
        <?php if (!empty($documents)): ?>
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-header bg-white fw-bold py-3" style="border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-file-earmark-text me-1 text-primary"></i> Project Documents
            </div>
            <div class="card-body p-0">
                <?php foreach ($documents as $doc): ?>
                <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" style="border-color:#f5f5f5!important;">
                    <i class="bi bi-file-pdf text-danger fs-4"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="font-size:.88rem;"><?= e($doc['file_name']) ?></div>
                        <div style="font-size:.75rem;color:#9ca3af;">
                            <?= e(ucfirst(str_replace('_',' ',$doc['document_type']))) ?>
                            <?= $doc['chapter'] ? ' · ' . e(str_replace('chapter','Chapter ',$doc['chapter'])) : '' ?>
                            · <?= number_format($doc['file_size']/1024/1024,2) ?> MB
                            · <?= e(date('M j, Y', strtotime($doc['uploaded_at']))) ?>
                        </div>
                    </div>
                    <a href="<?= base_url('download.php?id='.$doc['id']) ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-download me-1"></i> Download
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══ RATING SECTION ════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;" id="ratings">
            <div class="card-header bg-white py-3" style="border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fw-bold"><i class="bi bi-star-half me-1 text-warning"></i> Ratings & Reviews</span>
                    <?php if ($rating_cnt > 0): ?>
                        <span class="text-muted" style="font-size:.83rem;"><?= $rating_cnt ?> review<?= $rating_cnt>1?'s':'' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">

                <!-- ── Aggregate rating summary ── -->
                <?php if ($rating_cnt > 0): ?>
                <div class="rating-box mb-4">
                    <div class="row align-items-center g-3">
                        <div class="col-auto text-center">
                            <div class="big-rating"><?= number_format($avg_rating,1) ?></div>
                            <div class="mt-1"><?= star_html_lg($avg_rating,'1.2rem') ?></div>
                            <div style="font-size:.78rem;color:#92400e;margin-top:4px;"><?= $rating_cnt ?> review<?= $rating_cnt>1?'s':'' ?></div>
                        </div>
                        <div class="col">
                            <div class="d-flex align-items-center gap-2 mb-1"><span style="font-size:.78rem;color:#374151;min-width:12px;">5</span><i class="bi bi-star-fill text-warning" style="font-size:.7rem;"></i><?= star_bar((int)$agg['s5'],$rating_cnt) ?></div>
                            <div class="d-flex align-items-center gap-2 mb-1"><span style="font-size:.78rem;color:#374151;min-width:12px;">4</span><i class="bi bi-star-fill text-warning" style="font-size:.7rem;"></i><?= star_bar((int)$agg['s4'],$rating_cnt) ?></div>
                            <div class="d-flex align-items-center gap-2 mb-1"><span style="font-size:.78rem;color:#374151;min-width:12px;">3</span><i class="bi bi-star-fill text-warning" style="font-size:.7rem;"></i><?= star_bar((int)$agg['s3'],$rating_cnt) ?></div>
                            <div class="d-flex align-items-center gap-2 mb-1"><span style="font-size:.78rem;color:#374151;min-width:12px;">2</span><i class="bi bi-star-fill text-warning" style="font-size:.7rem;"></i><?= star_bar((int)$agg['s2'],$rating_cnt) ?></div>
                            <div class="d-flex align-items-center gap-2">      <span style="font-size:.78rem;color:#374151;min-width:12px;">1</span><i class="bi bi-star-fill text-warning" style="font-size:.7rem;"></i><?= star_bar((int)$agg['s1'],$rating_cnt) ?></div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <p class="text-muted mb-4" style="font-size:.9rem;">No reviews yet. Be the first to rate this project!</p>
                <?php endif; ?>

                <!-- ── Write / update a review ── -->
                <div class="mb-4 p-4" style="background:#f8faff;border-radius:12px;border:1.5px solid #e0e7ff;">
                    <div class="fw-bold mb-3" style="color:#3730a3;">
                        <i class="bi bi-pencil-square me-1"></i>
                        <?= $my_rating ? 'Update Your Review' : 'Write a Review' ?>
                    </div>
                    <?php if ($my_rating): ?>
                        <div class="alert alert-info py-2 mb-3" style="font-size:.82rem;border-radius:8px;">
                            <i class="bi bi-info-circle me-1"></i> You rated this project <strong><?= $my_rating['rating'] ?>/5</strong>. Update below to change your rating.
                        </div>
                    <?php endif; ?>
                    <form id="ratingForm">
                        <input type="hidden" name="project_id" value="<?= $project_id ?>">

                        <!-- Star picker -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">Your Rating <span class="text-danger">*</span></label>
                            <div class="star-input" id="starPicker">
                                <?php for ($i=5; $i>=1; $i--): ?>
                                    <input type="radio" name="rating" id="s<?= $i ?>" value="<?= $i ?>"
                                           <?= ($my_rating && $my_rating['rating'] == $i) ? 'checked' : '' ?>>
                                    <label for="s<?= $i ?>" title="<?= $i ?> star<?= $i>1?'s':'' ?>">&#9733;</label>
                                <?php endfor; ?>
                            </div>
                            <div id="ratingLabel" class="mt-1" style="font-size:.8rem;color:#6c757d;min-height:18px;"></div>
                        </div>

                        <!-- Comment -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">Your Review <small class="text-muted fw-normal">(Optional)</small></label>
                            <textarea class="form-control" name="comment" id="reviewComment" rows="3"
                                      maxlength="1200"
                                      placeholder="Share your thoughts on this project — quality, originality, relevance…"
                                      style="border-radius:8px;font-size:.88rem;"><?= e($my_rating['comment'] ?? '') ?></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted">Be constructive and respectful.</small>
                                <small class="text-muted" id="charCount">0 / 1200</small>
                            </div>
                        </div>

                        <div id="ratingAlert" class="mb-2" style="display:none;"></div>
                        <button type="submit" class="btn btn-primary px-4" id="submitRatingBtn">
                            <i class="bi bi-send me-1"></i> <?= $my_rating ? 'Update Review' : 'Submit Review' ?>
                        </button>
                        <?php if ($my_rating): ?>
                            <button type="button" class="btn btn-outline-danger ms-2" id="deleteRatingBtn">
                                <i class="bi bi-trash me-1"></i> Remove
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- ── Review list ── -->
                <?php if (!empty($reviews)): ?>
                <div id="reviewsList">
                    <?php foreach ($reviews as $rv): ?>
                    <div class="review-card" id="review-<?= $rv['id'] ?>">
                        <div class="d-flex gap-3">
                            <div class="reviewer-avatar"><?= strtoupper(substr($rv['full_name'],0,1)) ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                                    <div>
                                        <span class="fw-bold" style="font-size:.88rem;"><?= e($rv['full_name']) ?></span>
                                        <span class="badge bg-light text-secondary ms-1" style="font-size:.7rem;"><?= e(ucfirst($rv['role'])) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div>
                                            <?php for ($i=1;$i<=5;$i++): ?>
                                                <i class="bi bi-star<?= $i<=$rv['rating']?'-fill':'' ?> text-<?= $i<=$rv['rating']?'warning':'secondary' ?>" style="font-size:.8rem;<?= $i>$rv['rating']?'opacity:.3;':'' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <small class="text-muted"><?= e(date('M j, Y', strtotime($rv['updated_at']))) ?></small>
                                        <?php if (in_array($role, ['admin','hod'], true)): ?>
                                            <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.72rem;"
                                                    onclick="flagReview(<?= $rv['id'] ?>)">
                                                <i class="bi bi-flag"></i> Flag
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($rv['comment']): ?>
                                    <p class="mt-2 mb-0" style="font-size:.87rem;color:#374151;line-height:1.6;"><?= nl2br(e($rv['comment'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        </div><!-- /.rating card -->
    </div><!-- /.col-lg-8 -->

    <!-- ══ RIGHT SIDEBAR ════════════════════════════════════════════════════ -->
    <div class="col-lg-4">

        <!-- Project Info card -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
            <div class="card-header bg-white fw-bold py-3" style="border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-info-circle me-1 text-primary"></i> Project Details
            </div>
            <div class="card-body">
                <?php $meta_rows = [
                    ['bi-diagram-3',    'Status',      ucfirst(str_replace('_',' ',$project['status']))],
                    ['bi-person',       'Student',     $project['student_name']],
                    ['bi-person-badge', 'Supervisor',  $project['supervisor_name'] ?: 'Not assigned'],
                    ['bi-building',     'Department',  $project['department']],
                    ['bi-calendar3',    'Academic Year',$project['academic_year']],
                    ['bi-eye',          'Views',       number_format($project['view_count'])],
                ]; ?>
                <?php foreach ($meta_rows as [$ico,$lbl,$val]): if (!$val) continue; ?>
                <div class="d-flex align-items-start gap-2 mb-3">
                    <i class="bi <?= $ico ?> text-muted mt-1" style="font-size:.9rem;width:18px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;font-weight:600;"><?= $lbl ?></div>
                        <div style="font-size:.88rem;color:#1f2937;font-weight:500;"><?= e($val) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($project['submitted_at']): ?>
                <div class="d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-calendar-check text-muted mt-1" style="font-size:.9rem;width:18px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;font-weight:600;">Submitted</div>
                        <div style="font-size:.88rem;color:#1f2937;font-weight:500;"><?= e(date('M j, Y', strtotime($project['submitted_at']))) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rating snapshot (if any) -->
        <?php if ($rating_cnt > 0): ?>
        <div class="card border-0 shadow-sm mb-4 text-center" style="border-radius:12px;background:linear-gradient(135deg,#fffbeb,#fef9ee);">
            <div class="card-body py-4">
                <div style="font-size:3rem;font-weight:900;color:#d97706;line-height:1;"><?= number_format($avg_rating,1) ?></div>
                <div class="mt-1"><?= star_html_lg($avg_rating,'1.1rem') ?></div>
                <div class="text-muted mt-1" style="font-size:.8rem;"><?= $rating_cnt ?> review<?= $rating_cnt>1?'s':'' ?></div>
                <a href="#ratings" class="btn btn-warning btn-sm mt-3 px-4 fw-bold">Rate this Project</a>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-4 text-center" style="border-radius:12px;">
            <div class="card-body py-4">
                <i class="bi bi-star" style="font-size:2.5rem;color:#d1d5db;"></i>
                <p class="mt-2 mb-3 text-muted" style="font-size:.85rem;">No ratings yet</p>
                <a href="#ratings" class="btn btn-warning btn-sm px-4 fw-bold">Be the First to Rate</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tags -->
        <?php if ($project_tags): ?>
        <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
            <div class="card-header bg-white fw-bold py-2" style="font-size:.85rem;border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-tags me-1 text-primary"></i> Research Areas
            </div>
            <div class="card-body">
                <?php foreach ($project_tags as $t): ?>
                    <a href="<?= base_url('vault.php?tag='.urlencode($t['slug'])) ?>"
                       class="tag-chip mb-1"
                       style="background:<?= $t['color'] ?>18;color:<?= $t['color'] ?>;border:1.5px solid <?= $t['color'] ?>44;">
                        <i class="<?= e($t['icon']) ?>"></i><?= e($t['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Related Projects -->
        <?php if ($related): ?>
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-header bg-white fw-bold py-2" style="font-size:.85rem;border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-collection me-1 text-primary"></i> Related Projects
            </div>
            <div class="card-body py-2">
                <?php foreach ($related as $r): ?>
                <a href="<?= base_url('project_detail.php?id='.$r['id']) ?>" class="related-card mb-2">
                    <div class="fw-semibold" style="font-size:.83rem;color:#1a1a2e;line-height:1.3;" title="<?= e($r['title']) ?>">
                        <?= e(mb_strimwidth($r['title'],0,60,'…')) ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span style="font-size:.73rem;color:#6c757d;"><?= e(mb_strimwidth($r['student_name']??'',0,24,'…')) ?></span>
                        <?php if ($r['avg_rating']): ?>
                            <span class="text-warning" style="font-size:.72rem;">★ <?= number_format($r['avg_rating'],1) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.col-lg-4 -->
</div>

<script>
const ratingLabels = {1:'Poor',2:'Fair',3:'Good',4:'Very Good',5:'Excellent'};
const labelEl = document.getElementById('ratingLabel');
const textArea = document.getElementById('reviewComment');
const charCount = document.getElementById('charCount');

// Star label on select
document.querySelectorAll('.star-input input').forEach(inp => {
    inp.addEventListener('change', () => {
        labelEl.textContent = ratingLabels[inp.value] || '';
        labelEl.style.color = '#f59e0b';
    });
});
// Show initial label if pre-selected
const checked = document.querySelector('.star-input input:checked');
if (checked) { labelEl.textContent = ratingLabels[checked.value] || ''; labelEl.style.color = '#f59e0b'; }

// Char count
if (textArea) {
    const update = () => { charCount.textContent = textArea.value.length + ' / 1200'; };
    textArea.addEventListener('input', update);
    update();
}

// Submit rating via AJAX
document.getElementById('ratingForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('submitRatingBtn');
    const alertEl = document.getElementById('ratingAlert');
    const rating = document.querySelector('.star-input input:checked');
    if (!rating) {
        showAlert(alertEl, 'danger', 'Please select a star rating.');
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
    try {
        const fd = new FormData(e.target);
        fd.append('action', 'submit');
        const res = await fetch('<?= base_url('api/rate_project.php') ?>', {method:'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
            showAlert(alertEl, 'success', data.message || 'Review saved!');
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Saved!';
            setTimeout(() => location.reload(), 900);
        } else {
            showAlert(alertEl, 'danger', data.error || 'Could not save. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i> Submit Review';
        }
    } catch(err) {
        showAlert(alertEl, 'danger', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i> Submit Review';
    }
});

// Delete rating
const delBtn = document.getElementById('deleteRatingBtn');
if (delBtn) {
    delBtn.addEventListener('click', async () => {
        if (!confirm('Remove your review from this project?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('project_id', '<?= $project_id ?>');
        const res = await fetch('<?= base_url('api/rate_project.php') ?>', {method:'POST', body: fd});
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert(data.error || 'Could not remove review.'); }
    });
}

// Flag review (admin/hod)
function flagReview(id) {
    if (!confirm('Flag this review for moderation?')) return;
    const fd = new FormData();
    fd.append('action', 'flag');
    fd.append('review_id', id);
    fetch('<?= base_url('api/rate_project.php') ?>', {method:'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                document.getElementById('review-'+id)?.remove();
                alert('Review flagged for moderation.');
            } else {
                alert(d.error || 'Could not flag review.');
            }
        });
}

function showAlert(el, type, msg) {
    el.className = 'alert alert-' + type + ' py-2 mb-2';
    el.style.display = 'block';
    el.style.fontSize = '.85rem';
    el.style.borderRadius = '8px';
    el.innerHTML = msg;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
