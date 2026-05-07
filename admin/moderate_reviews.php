<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = getPDO();
$uid = user_id();

ensure_discovery_tables($pdo);

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action    = $_POST['action'] ?? '';
    $review_id = (int) ($_POST['review_id'] ?? 0);

    if ($review_id > 0) {
        switch ($action) {
            case 'approve':
                $pdo->prepare('UPDATE project_ratings SET status = "visible", flagged_by = NULL, flagged_at = NULL WHERE id = ?')
                    ->execute([$review_id]);
                $pdo->prepare('SELECT project_id FROM project_ratings WHERE id = ?')->execute([$review_id]);
                $pid = (int) $pdo->query("SELECT project_id FROM project_ratings WHERE id = $review_id")->fetchColumn();
                if ($pid) refresh_project_rating($pdo, $pid);
                flash('success', 'Review restored and made visible.');
                break;

            case 'hide':
                $pdo->prepare('UPDATE project_ratings SET status = "hidden", flagged_by = ?, flagged_at = NOW() WHERE id = ?')
                    ->execute([$uid, $review_id]);
                $pid = (int) $pdo->query("SELECT project_id FROM project_ratings WHERE id = $review_id")->fetchColumn();
                if ($pid) refresh_project_rating($pdo, $pid);
                flash('success', 'Review hidden from public view.');
                break;

            case 'delete':
                $row = $pdo->prepare('SELECT project_id FROM project_ratings WHERE id = ? LIMIT 1');
                $row->execute([$review_id]);
                $pid = (int) ($row->fetchColumn() ?: 0);
                $pdo->prepare('DELETE FROM project_ratings WHERE id = ?')->execute([$review_id]);
                if ($pid) refresh_project_rating($pdo, $pid);
                flash('success', 'Review permanently deleted.');
                break;
        }
    }
    redirect(base_url('admin/moderate_reviews.php?' . http_build_query(['tab' => $_POST['tab'] ?? 'flagged'])));
}

$tab = in_array($_GET['tab'] ?? '', ['flagged', 'all', 'hidden'], true) ? $_GET['tab'] : 'flagged';

// ── Fetch reviews by tab ──────────────────────────────────────────────────────
$base_sql = "SELECT pr.id, pr.rating, pr.comment, pr.status, pr.created_at, pr.updated_at, pr.flagged_at,
               reviewer.full_name AS reviewer_name, reviewer.email AS reviewer_email, reviewer.role AS reviewer_role,
               flagger.full_name  AS flagged_by_name,
               p.id AS project_id, p.title AS project_title
        FROM project_ratings pr
        JOIN users reviewer ON reviewer.id = pr.user_id
        LEFT JOIN users flagger ON flagger.id = pr.flagged_by
        JOIN projects p ON p.id = pr.project_id";

$where = match($tab) {
    'flagged' => "WHERE pr.status = 'flagged'",
    'hidden'  => "WHERE pr.status = 'hidden'",
    default   => "WHERE 1",
};

$reviews = $pdo->query("$base_sql $where ORDER BY pr.updated_at DESC LIMIT 100")->fetchAll();

// Tab counts
$cnt_flagged = (int) $pdo->query("SELECT COUNT(*) FROM project_ratings WHERE status = 'flagged'")->fetchColumn();
$cnt_hidden  = (int) $pdo->query("SELECT COUNT(*) FROM project_ratings WHERE status = 'hidden'")->fetchColumn();
$cnt_all     = (int) $pdo->query("SELECT COUNT(*) FROM project_ratings")->fetchColumn();

// Overall stats
$stats = [
    'total'   => $cnt_all,
    'visible' => (int) $pdo->query("SELECT COUNT(*) FROM project_ratings WHERE status = 'visible'")->fetchColumn(),
    'flagged' => $cnt_flagged,
    'hidden'  => $cnt_hidden,
];

$pageTitle = 'Moderate Reviews — Admin';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.mod-hero{background:linear-gradient(135deg,#1e3a5f,#1a237e);border-radius:14px;padding:28px 32px;margin-bottom:24px;color:#fff;}
.mod-hero h1{font-size:1.5rem;font-weight:800;}
.stat-pill{background:rgba(255,255,255,.12);border-radius:10px;padding:12px 20px;text-align:center;}
.stat-pill .num{font-size:1.6rem;font-weight:800;line-height:1;}
.stat-pill .lbl{font-size:.72rem;color:rgba(255,255,255,.7);margin-top:3px;}

.rev-card{border-radius:12px;border:1px solid #e9ecef;padding:16px 20px;margin-bottom:12px;transition:box-shadow .2s;}
.rev-card.flagged{border-left:4px solid #ef4444;background:#fff5f5;}
.rev-card.hidden {border-left:4px solid #6b7280;background:#f9fafb;}
.rev-card.visible{border-left:4px solid #10b981;}

.reviewer-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.82rem;flex-shrink:0;}
</style>

<!-- Hero -->
<div class="mod-hero mb-4">
    <div class="row align-items-center g-3">
        <div class="col-lg-7">
            <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.6);margin-bottom:5px;">
                <i class="bi bi-shield-check me-1"></i> Content Moderation
            </div>
            <h1 class="mod-hero h1 mb-1">Review Moderation</h1>
            <p class="mb-0" style="color:rgba(255,255,255,.7);font-size:.9rem;">Manage project ratings and feedback to maintain quality and respectful discourse.</p>
        </div>
        <div class="col-lg-5">
            <div class="row g-2 text-center">
                <div class="col-3"><div class="stat-pill"><div class="num"><?= $stats['total'] ?></div><div class="lbl">Total</div></div></div>
                <div class="col-3"><div class="stat-pill" style="background:rgba(16,185,129,.2);"><div class="num"><?= $stats['visible'] ?></div><div class="lbl">Visible</div></div></div>
                <div class="col-3"><div class="stat-pill" style="background:rgba(239,68,68,.2);"><div class="num"><?= $stats['flagged'] ?></div><div class="lbl">Flagged</div></div></div>
                <div class="col-3"><div class="stat-pill" style="background:rgba(107,114,128,.2);"><div class="num"><?= $stats['hidden'] ?></div><div class="lbl">Hidden</div></div></div>
            </div>
        </div>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i> <?= e($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='flagged'?'active':'' ?>" href="?tab=flagged">
            <i class="bi bi-flag me-1"></i> Flagged
            <?php if ($cnt_flagged > 0): ?><span class="badge bg-danger ms-1"><?= $cnt_flagged ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='hidden'?'active':'' ?>" href="?tab=hidden">
            <i class="bi bi-eye-slash me-1"></i> Hidden
            <?php if ($cnt_hidden > 0): ?><span class="badge bg-secondary ms-1"><?= $cnt_hidden ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='all'?'active':'' ?>" href="?tab=all">
            <i class="bi bi-list-ul me-1"></i> All Reviews
            <span class="badge bg-light text-dark ms-1"><?= $cnt_all ?></span>
        </a>
    </li>
</ul>

<?php if (empty($reviews)): ?>
    <div class="text-center py-5">
        <i class="bi bi-check-circle" style="font-size:3rem;color:#10b981;"></i>
        <h5 class="mt-3 fw-bold text-muted">
            <?= $tab === 'flagged' ? 'No flagged reviews' : ($tab === 'hidden' ? 'No hidden reviews' : 'No reviews yet') ?>
        </h5>
        <p class="text-muted">
            <?= $tab === 'flagged' ? 'All reviews are clean.' : '' ?>
        </p>
    </div>
<?php else: ?>
    <div>
        <?php foreach ($reviews as $rv): ?>
        <div class="rev-card <?= e($rv['status']) ?>">
            <div class="d-flex gap-3">
                <div class="reviewer-avatar"><?= strtoupper(substr($rv['reviewer_name'], 0, 1)) ?></div>
                <div class="flex-grow-1">
                    <!-- Header row -->
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="fw-bold" style="font-size:.88rem;"><?= e($rv['reviewer_name']) ?></span>
                            <span class="badge bg-light text-secondary ms-1" style="font-size:.7rem;"><?= e(ucfirst($rv['reviewer_role'])) ?></span>
                            <span class="badge ms-1 <?= match($rv['status']) { 'flagged'=>'bg-danger', 'hidden'=>'bg-secondary', default=>'bg-success' } ?>" style="font-size:.7rem;"><?= ucfirst($rv['status']) ?></span>
                        </div>
                        <small class="text-muted"><?= e(date('M j, Y H:i', strtotime($rv['updated_at']))) ?></small>
                    </div>

                    <!-- Project link -->
                    <div class="mt-1 mb-2">
                        <a href="<?= base_url('project_detail.php?id='.$rv['project_id']) ?>"
                           class="text-decoration-none text-primary" style="font-size:.82rem;">
                            <i class="bi bi-folder me-1"></i><?= e(mb_strimwidth($rv['project_title'], 0, 70, '…')) ?>
                        </a>
                    </div>

                    <!-- Stars -->
                    <div class="mb-2">
                        <?php for ($i=1;$i<=5;$i++): ?>
                            <i class="bi bi-star<?= $i<=$rv['rating']?'-fill':'' ?> text-<?= $i<=$rv['rating']?'warning':'secondary' ?>" style="font-size:.85rem;<?= $i>$rv['rating']?'opacity:.3;':'' ?>"></i>
                        <?php endfor; ?>
                        <span class="ms-1 fw-bold" style="font-size:.82rem;"><?= $rv['rating'] ?>/5</span>
                    </div>

                    <!-- Comment -->
                    <?php if ($rv['comment']): ?>
                        <p class="mb-2" style="font-size:.87rem;color:#374151;line-height:1.6;background:#f9fafb;border-radius:8px;padding:10px 12px;">
                            <?= nl2br(e($rv['comment'])) ?>
                        </p>
                    <?php else: ?>
                        <p class="text-muted mb-2" style="font-size:.82rem;font-style:italic;">(No written comment)</p>
                    <?php endif; ?>

                    <!-- Flag info -->
                    <?php if ($rv['status'] === 'flagged' && $rv['flagged_by_name']): ?>
                        <div class="mb-2" style="font-size:.78rem;color:#dc2626;">
                            <i class="bi bi-flag me-1"></i> Flagged by <?= e($rv['flagged_by_name']) ?>
                            <?= $rv['flagged_at'] ? ' on ' . e(date('M j, Y', strtotime($rv['flagged_at']))) : '' ?>
                        </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <?php if ($rv['status'] !== 'visible'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="review_id" value="<?= $rv['id'] ?>">
                            <input type="hidden" name="tab" value="<?= e($tab) ?>">
                            <button class="btn btn-sm btn-success px-3"><i class="bi bi-check-circle me-1"></i> Restore</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($rv['status'] !== 'hidden'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="hide">
                            <input type="hidden" name="review_id" value="<?= $rv['id'] ?>">
                            <input type="hidden" name="tab" value="<?= e($tab) ?>">
                            <button class="btn btn-sm btn-secondary px-3"><i class="bi bi-eye-slash me-1"></i> Hide</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('Permanently delete this review?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="review_id" value="<?= $rv['id'] ?>">
                            <input type="hidden" name="tab" value="<?= e($tab) ?>">
                            <button class="btn btn-sm btn-outline-danger px-3"><i class="bi bi-trash me-1"></i> Delete</button>
                        </form>
                        <a href="<?= base_url('project_detail.php?id='.$rv['project_id'].'#ratings') ?>"
                           class="btn btn-sm btn-outline-secondary px-3" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i> View Project
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
