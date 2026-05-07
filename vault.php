<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo  = getPDO();
$user = current_user();
$uid  = user_id();

ensure_project_keywords_column($pdo);
ensure_discovery_tables($pdo);

// ── Search & filter parameters ───────────────────────────────────────────────
$q        = trim($_GET['q']      ?? '');
$tag_slug = trim($_GET['tag']    ?? '');
$domain   = trim($_GET['domain'] ?? '');
$dept     = trim($_GET['dept']   ?? '');
$year     = trim($_GET['year']   ?? '');
$tech     = trim($_GET['tech']   ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['recent','viewed','rated','az'], true) ? $_GET['sort'] : 'recent';
$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 12;

// Record search interest
if ($q !== '' && $uid) {
    upsert_interest($pdo, $uid, 'keyword', strtolower($q));
}
if ($tag_slug !== '' && $uid) {
    upsert_interest($pdo, $uid, 'tag', $tag_slug);
}
if ($domain !== '' && $uid) {
    upsert_interest($pdo, $uid, 'domain', $domain);
}

// ── Taxonomy for filters ─────────────────────────────────────────────────────
$all_tags  = $pdo->query('SELECT id, name, domain, slug, color, icon FROM project_tags WHERE is_active = 1 ORDER BY domain, name')->fetchAll();
$domains   = array_values(array_unique(array_column($all_tags, 'domain')));
sort($domains);
$all_depts = $pdo->query('SELECT name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$all_years = $pdo->query("SELECT DISTINCT academic_year FROM projects WHERE status IN ('approved','in_progress','completed','archived') AND academic_year IS NOT NULL ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── Build WHERE ──────────────────────────────────────────────────────────────
$where  = ["p.status IN ('approved','in_progress','completed','archived')"];
$params = [];

if ($q !== '') {
    $where[]  = '(p.title LIKE ? OR p.keywords LIKE ? OR p.technology_stack LIKE ? OR stu.full_name LIKE ?)';
    $like     = '%' . $q . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($dept !== '') {
    $where[]  = 'LOWER(TRIM(COALESCE(stu.department,""))) LIKE ?';
    $params[] = '%' . strtolower($dept) . '%';
}
if ($year !== '') {
    $where[]  = 'p.academic_year = ?';
    $params[] = $year;
}
if ($tech !== '') {
    $where[]  = 'p.technology_stack LIKE ?';
    $params[] = '%' . $tech . '%';
}

$tag_id = null;
if ($tag_slug !== '') {
    $ts = $pdo->prepare('SELECT id, domain FROM project_tags WHERE slug = ? LIMIT 1');
    $ts->execute([$tag_slug]);
    $tag_row = $ts->fetch();
    if ($tag_row) {
        $tag_id   = (int) $tag_row['id'];
        $where[]  = 'EXISTS (SELECT 1 FROM project_tag_map tm WHERE tm.project_id = p.id AND tm.tag_id = ?)';
        $params[] = $tag_id;
    }
}
if ($domain !== '' && !$tag_id) {
    $where[]  = 'EXISTS (SELECT 1 FROM project_tag_map tm JOIN project_tags pt ON pt.id = tm.tag_id WHERE tm.project_id = p.id AND pt.domain = ?)';
    $params[] = $domain;
}

$where_sql = implode(' AND ', $where);
$order_sql = match($sort) {
    'viewed' => 'p.view_count DESC, p.updated_at DESC',
    'rated'  => 'p.avg_rating DESC, p.rating_count DESC, p.updated_at DESC',
    'az'     => 'p.title ASC',
    default  => 'p.updated_at DESC',
};

// Count
$cnt_stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM projects p LEFT JOIN users stu ON stu.id = p.student_id WHERE $where_sql");
$cnt_stmt->execute($params);
$total  = (int) $cnt_stmt->fetchColumn();
$pages  = max(1, (int) ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

// Fetch
$sql = "SELECT DISTINCT p.id, p.title, p.keywords, p.technology_stack, p.status, p.academic_year,
               p.view_count, p.avg_rating, p.rating_count, p.updated_at,
               stu.full_name AS student_name, stu.department,
               sup.full_name AS supervisor_name
        FROM projects p
        LEFT JOIN users stu ON stu.id = p.student_id
        LEFT JOIN users sup ON sup.id = p.supervisor_id
        WHERE $where_sql
        ORDER BY $order_sql
        LIMIT $per_page OFFSET $offset";
$res_stmt = $pdo->prepare($sql);
$res_stmt->execute($params);
$projects = $res_stmt->fetchAll();

// Attach tags per project
$project_ids = array_column($projects, 'id');
$tag_map = [];
if ($project_ids) {
    $in  = sql_placeholders(count($project_ids));
    $ts2 = $pdo->prepare("SELECT tm.project_id, pt.name, pt.slug, pt.color, pt.icon
                           FROM project_tag_map tm JOIN project_tags pt ON pt.id = tm.tag_id
                           WHERE tm.project_id IN ($in) ORDER BY pt.name");
    $ts2->execute($project_ids);
    foreach ($ts2->fetchAll() as $t) {
        $tag_map[$t['project_id']][] = $t;
    }
}

// ── Recommendations ──────────────────────────────────────────────────────────
$recommendations = [];
if ($uid) {
    $int_s = $pdo->prepare('SELECT interest_type, value FROM user_interests WHERE user_id = ? ORDER BY weight DESC, last_used_at DESC LIMIT 8');
    $int_s->execute([$uid]);
    $interests = $int_s->fetchAll();

    $rec_conds  = [];
    $rec_params = [];
    foreach ($interests as $i) {
        if ($i['interest_type'] === 'tag') {
            $rec_conds[]  = 'EXISTS (SELECT 1 FROM project_tag_map tm JOIN project_tags pt ON pt.id=tm.tag_id WHERE tm.project_id=p.id AND pt.slug=?)';
            $rec_params[] = $i['value'];
        } elseif ($i['interest_type'] === 'domain') {
            $rec_conds[]  = 'EXISTS (SELECT 1 FROM project_tag_map tm JOIN project_tags pt ON pt.id=tm.tag_id WHERE tm.project_id=p.id AND pt.domain=?)';
            $rec_params[] = $i['value'];
        } elseif ($i['interest_type'] === 'keyword') {
            $rec_conds[]  = '(p.title LIKE ? OR p.keywords LIKE ?)';
            $rec_params[] = '%' . $i['value'] . '%';
            $rec_params[] = '%' . $i['value'] . '%';
        }
    }

    if ($rec_conds) {
        try {
            $rec_s = $pdo->prepare("SELECT p.id, p.title, p.avg_rating, p.rating_count, p.view_count, stu.full_name AS student_name
                FROM projects p LEFT JOIN users stu ON stu.id=p.student_id
                WHERE p.status IN ('approved','in_progress','completed','archived')
                  AND (" . implode(' OR ', $rec_conds) . ")
                ORDER BY p.avg_rating DESC, p.view_count DESC LIMIT 5");
            $rec_s->execute($rec_params);
            $recommendations = $rec_s->fetchAll();
        } catch (Throwable $e) {
            $recommendations = [];
        }
    }

    if (empty($recommendations)) {
        $rec_s = $pdo->prepare("SELECT p.id, p.title, p.avg_rating, p.rating_count, p.view_count, stu.full_name AS student_name
            FROM projects p LEFT JOIN users stu ON stu.id=p.student_id
            WHERE p.status IN ('approved','in_progress','completed','archived')
            ORDER BY p.avg_rating DESC, p.view_count DESC LIMIT 5");
        $rec_s->execute();
        $recommendations = $rec_s->fetchAll();
    }
}

// ── Vault stats ──────────────────────────────────────────────────────────────
$s_active    = (int) $pdo->query("SELECT COUNT(*) FROM projects WHERE status IN ('approved','in_progress','completed')")->fetchColumn();
$s_completed = (int) $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'completed'")->fetchColumn();

$pageTitle = 'Project Vault';
require_once __DIR__ . '/includes/header.php';

function star_html(float $r): string {
    $h = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($r >= $i)          $h .= '<i class="bi bi-star-fill text-warning" style="font-size:.75rem;"></i>';
        elseif ($r >= $i-.5)   $h .= '<i class="bi bi-star-half text-warning" style="font-size:.75rem;"></i>';
        else                   $h .= '<i class="bi bi-star text-secondary"    style="font-size:.75rem;opacity:.4;"></i>';
    }
    return $h;
}

$status_color = fn($s) => match($s) {
    'completed'  => '#10b981',
    'in_progress','approved' => '#3b82f6',
    'archived'   => '#6b7280',
    default      => '#f59e0b',
};
?>

<style>
/* ── Vault global styles ────────────────────────── */
.vault-hero{background:linear-gradient(135deg,#1e3a5f 0%,#1565c0 60%,#0d47a1 100%);border-radius:16px;padding:36px 32px;margin-bottom:28px;position:relative;overflow:hidden;}
.vault-hero::before{content:'';position:absolute;top:-70px;right:-70px;width:280px;height:280px;background:rgba(255,255,255,.05);border-radius:50%;}
.vault-hero::after{content:'';position:absolute;bottom:-40px;left:20%;width:180px;height:180px;background:rgba(255,255,255,.03);border-radius:50%;}
.vh-title{font-size:1.9rem;font-weight:800;color:#fff;margin-bottom:6px;}
.vh-sub{color:rgba(255,255,255,.75);font-size:.95rem;}
.vs-num{font-size:1.7rem;font-weight:800;color:#fff;line-height:1.1;}
.vs-lbl{font-size:.75rem;color:rgba(255,255,255,.65);margin-top:3px;}

.vault-search .form-control{border-radius:10px 0 0 10px;padding:12px 16px;font-size:.95rem;border-color:#c5d5e8;}
.vault-search .btn{border-radius:0 10px 10px 0;padding:12px 20px;font-weight:700;}

.filter-card{border-radius:12px;border:0;box-shadow:0 1px 8px rgba(0,0,0,.07);}
.fpill{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:.8rem;font-weight:600;border:1.5px solid #e0e7ef;background:#fff;color:#344054;cursor:pointer;transition:all .18s;text-decoration:none;margin-bottom:5px;}
.fpill:hover,.fpill.on{border-color:var(--bs-primary);background:var(--bs-primary);color:#fff;}

.project-card{border-radius:12px;border:1px solid #e9ecef;transition:transform .2s,box-shadow .2s;height:100%;display:flex;flex-direction:column;}
.project-card:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(0,0,0,.12);}
.pc-footer{border-top:1px solid #f0f0f0;background:#fafafa;border-radius:0 0 12px 12px;padding:10px 14px;}

.tag-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:.73rem;font-weight:600;margin:2px;text-decoration:none;transition:opacity .18s;white-space:nowrap;}
.tag-chip:hover{opacity:.8;}

.sort-btn{border-radius:8px;font-size:.8rem;font-weight:600;}
.sort-btn.on{background:var(--bs-primary);color:#fff;border-color:var(--bs-primary);}

.rec-card{border-radius:10px;border:1px solid #e9ecef;padding:11px 13px;transition:box-shadow .2s;text-decoration:none;color:inherit;display:block;background:#fff;}
.rec-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.1);color:inherit;}
.rec-strip{background:linear-gradient(135deg,#f0f4ff 0%,#fafbff 100%);border-radius:12px;border:0;box-shadow:0 1px 8px rgba(0,0,0,.06);}
</style>

<!-- ══ HERO ══════════════════════════════════════════════════════════════════ -->
<div class="vault-hero">
    <div class="row align-items-center g-3">
        <div class="col-lg-7">
            <div style="font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:1.3px;color:rgba(255,255,255,.65);margin-bottom:5px;">
                <i class="bi bi-archive me-1"></i> Project Vault
            </div>
            <h1 class="vh-title">Discover FYP Projects</h1>
            <p class="vh-sub mb-0">Search and filter final year projects across all departments, domains, and technologies.</p>
        </div>
        <div class="col-lg-5">
            <div class="row text-center g-2">
                <div class="col-4"><div class="vs-num"><?= number_format($s_active) ?></div><div class="vs-lbl">Active Projects</div></div>
                <div class="col-4"><div class="vs-num"><?= number_format($s_completed) ?></div><div class="vs-lbl">Completed</div></div>
                <div class="col-4"><div class="vs-num"><?= count($all_tags) ?></div><div class="vs-lbl">Research Tags</div></div>
            </div>
        </div>
    </div>

    <form method="get" class="mt-4 vault-search" id="searchForm">
        <?php foreach (['sort','dept','year','tag','domain','tech'] as $hf): ?>
            <input type="hidden" name="<?= $hf ?>" value="<?= e($$hf) ?>">
        <?php endforeach; ?>
        <div class="input-group shadow">
            <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius:10px 0 0 10px;border-color:#c5d5e8;">
                <i class="bi bi-search"></i>
            </span>
            <input type="text" name="q" class="form-control border-start-0 ps-0"
                   placeholder="Search title, keywords, technology stack, student name…"
                   value="<?= e($q) ?>" autocomplete="off" id="vaultQ">
            <button type="submit" class="btn btn-warning">Search</button>
        </div>
    </form>
</div>

<div class="row g-4">

    <!-- ══ SIDEBAR FILTERS ══════════════════════════════════════════════════ -->
    <div class="col-lg-3 col-xl-2">

        <!-- Domain -->
        <div class="card filter-card mb-3">
            <div class="card-header bg-white fw-bold py-2" style="font-size:.85rem;border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-grid me-1 text-primary"></i> Domains
            </div>
            <div class="card-body py-2 px-3">
                <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year])) ?>"
                   class="fpill w-100 <?= ($domain==='' && $tag_slug==='') ? 'on' : '' ?>">
                    <i class="bi bi-collection"></i> All Domains
                </a>
                <?php foreach ($domains as $d): ?>
                    <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year,'domain'=>$d])) ?>"
                       class="fpill w-100 <?= $domain === $d ? 'on' : '' ?>">
                        <?= e($d) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Department -->
        <?php if ($all_depts): ?>
        <div class="card filter-card mb-3">
            <div class="card-header bg-white fw-bold py-2" style="font-size:.85rem;border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-building me-1 text-primary"></i> Department
            </div>
            <div class="card-body py-2 px-3">
                <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'year'=>$year,'domain'=>$domain,'tag'=>$tag_slug])) ?>"
                   class="fpill w-100 <?= $dept==='' ? 'on' : '' ?>"><i class="bi bi-dash-circle me-1"></i> All Depts</a>
                <?php foreach ($all_depts as $d): ?>
                    <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'year'=>$year,'domain'=>$domain,'tag'=>$tag_slug,'dept'=>$d])) ?>"
                       class="fpill w-100 <?= $dept === $d ? 'on' : '' ?>" style="word-break:break-word;"><?= e($d) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Year -->
        <?php if ($all_years): ?>
        <div class="card filter-card">
            <div class="card-header bg-white fw-bold py-2" style="font-size:.85rem;border-radius:12px 12px 0 0;border-bottom:1px solid #f0f0f0;">
                <i class="bi bi-calendar3 me-1 text-primary"></i> Year
            </div>
            <div class="card-body py-2 px-3">
                <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'domain'=>$domain,'tag'=>$tag_slug])) ?>"
                   class="fpill w-100 <?= $year==='' ? 'on' : '' ?>"><i class="bi bi-dash-circle me-1"></i> All Years</a>
                <?php foreach ($all_years as $y): ?>
                    <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'domain'=>$domain,'tag'=>$tag_slug,'year'=>$y])) ?>"
                       class="fpill w-100 <?= $year===(string)$y ? 'on' : '' ?>"><?= e($y) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ MAIN CONTENT ══════════════════════════════════════════════════════ -->
    <div class="col-lg-9 col-xl-10">

        <!-- Tag cloud -->
        <div class="mb-3 d-flex flex-wrap gap-1">
            <?php foreach ($all_tags as $t): ?>
                <a href="<?= base_url('vault.php?' . http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year,'tag'=>$t['slug']])) ?>"
                   class="tag-chip"
                   style="background:<?= $tag_slug===$t['slug'] ? $t['color'] : $t['color'].'22' ?>;
                          color:<?= $tag_slug===$t['slug'] ? '#fff' : $t['color'] ?>;
                          border:1.5px solid <?= $t['color'] ?>55;
                          <?= $tag_slug===$t['slug'] ? 'box-shadow:0 0 0 3px '.$t['color'].'33;' : '' ?>">
                    <i class="<?= e($t['icon']) ?>"></i><?= e($t['name']) ?>
                </a>
            <?php endforeach; ?>
            <?php if ($tag_slug || $domain || $dept || $year || $q): ?>
                <a href="<?= base_url('vault.php') ?>" class="tag-chip" style="background:#f8f9fa;color:#6c757d;border:1.5px solid #dee2e6;">
                    <i class="bi bi-x-circle"></i> Clear filters
                </a>
            <?php endif; ?>
        </div>

        <!-- Sort + result bar -->
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div class="text-muted" style="font-size:.85rem;">
                <?php if ($total === 0): ?>
                    No projects found
                <?php else: ?>
                    Showing <strong><?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page,$total)) ?></strong>
                    of <strong><?= number_format($total) ?></strong>
                    <?= $q ? '— results for "<strong>' . e($q) . '</strong>"' : '' ?>
                <?php endif; ?>
            </div>
            <div class="btn-group btn-group-sm">
                <?php foreach (['recent'=>'Most Recent','viewed'=>'Most Viewed','rated'=>'Top Rated','az'=>'A–Z'] as $s=>$lbl): ?>
                    <a href="<?= base_url('vault.php?'.http_build_query(['q'=>$q,'dept'=>$dept,'year'=>$year,'tag'=>$tag_slug,'domain'=>$domain,'sort'=>$s])) ?>"
                       class="btn btn-outline-secondary sort-btn <?= $sort===$s ? 'on' : '' ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Recommendations strip ───────────────────────────────────────── -->
        <?php if (!empty($recommendations) && !$q && !$tag_slug && !$domain): ?>
        <div class="card rec-strip mb-4">
            <div class="card-body px-4 py-3">
                <div class="fw-bold mb-3" style="font-size:.88rem;color:#4f46e5;">
                    <i class="bi bi-stars me-1"></i> Recommended for You
                    <span class="text-muted fw-normal ms-1" style="font-size:.78rem;">based on your activity</span>
                </div>
                <div class="row g-2">
                    <?php foreach ($recommendations as $r): ?>
                    <div class="col-sm-6 col-lg-<?= count($recommendations) >= 4 ? '3' : (count($recommendations) >= 3 ? '4' : '6') ?>">
                        <a href="<?= base_url('project_detail.php?id=' . $r['id']) ?>" class="rec-card">
                            <div class="fw-semibold" style="font-size:.83rem;color:#1a1a2e;line-height:1.3;" title="<?= e($r['title']) ?>">
                                <?= e(mb_strimwidth($r['title'], 0, 60, '…')) ?>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span style="font-size:.73rem;color:#6c757d;"><?= e(mb_strimwidth($r['student_name'] ?? '', 0, 24, '…')) ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <?php if ($r['avg_rating']): ?>
                                    <span><?= star_html((float)$r['avg_rating']) ?></span>
                                    <span style="font-size:.73rem;color:#6c757d;"><?= number_format($r['avg_rating'],1) ?></span>
                                <?php endif; ?>
                                <span style="font-size:.73rem;color:#6c757d;"><i class="bi bi-eye"></i> <?= number_format($r['view_count']) ?></span>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Project grid ───────────────────────────────────────────────── -->
        <?php if (empty($projects)): ?>
            <div class="text-center py-5">
                <i class="bi bi-search" style="font-size:3.5rem;color:#d1d5db;"></i>
                <h5 class="mt-3 fw-bold text-muted">No projects found</h5>
                <p class="text-muted">Try different keywords, or <a href="<?= base_url('vault.php') ?>">clear all filters</a>.</p>
            </div>
        <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach ($projects as $p): ?>
            <div class="col-sm-6 col-xl-4">
                <div class="card project-card shadow-sm">
                    <div style="height:4px;border-radius:12px 12px 0 0;background:<?= $status_color($p['status']) ?>;"></div>
                    <div class="card-body pb-2">
                        <div class="mb-2">
                            <?php foreach (array_slice($tag_map[$p['id']] ?? [], 0, 3) as $t): ?>
                                <a href="<?= base_url('vault.php?tag='.urlencode($t['slug'])) ?>" class="tag-chip"
                                   style="background:<?= $t['color'] ?>18;color:<?= $t['color'] ?>;border:1.5px solid <?= $t['color'] ?>44;">
                                    <i class="<?= e($t['icon']) ?>"></i><?= e($t['name']) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($tag_map[$p['id']] ?? []) > 3): ?>
                                <span class="tag-chip" style="background:#f3f4f6;color:#6b7280;border:1.5px solid #e5e7eb;">
                                    +<?= count($tag_map[$p['id']]) - 3 ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <h6 class="fw-bold mb-1" style="font-size:.95rem;color:#1a1a2e;line-height:1.35;">
                            <a href="<?= base_url('project_detail.php?id=' . $p['id']) ?>"
                               class="text-decoration-none" style="color:inherit;"
                               onmouseover="this.style.color='#3b82f6'" onmouseout="this.style.color='inherit'">
                                <?= e(mb_strimwidth($p['title'], 0, 80, '…')) ?>
                            </a>
                        </h6>

                        <?php if (!empty($p['keywords'])): ?>
                            <p class="text-muted mb-2" style="font-size:.78rem;line-height:1.4;">
                                <?= e(mb_strimwidth($p['keywords'], 0, 100, '…')) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($p['technology_stack'])): ?>
                            <div class="mb-1">
                                <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $p['technology_stack']))), 0, 4) as $ti): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size:.7rem;font-weight:500;"><?= e($ti) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pc-footer d-flex align-items-center justify-content-between">
                        <div>
                            <div style="font-size:.78rem;font-weight:600;color:#374151;"><?= e($p['student_name'] ?? '—') ?></div>
                            <div style="font-size:.72rem;color:#9ca3af;"><?= e($p['academic_year'] ?? '') ?> &nbsp;
                                <span class="badge" style="font-size:.65rem;background:<?= $status_color($p['status']) ?>22;color:<?= $status_color($p['status']) ?>;">
                                    <?= e(ucfirst(str_replace('_',' ',$p['status']))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <?php if ($p['avg_rating']): ?>
                                <div style="font-size:.78rem;line-height:1.2;">
                                    <?= star_html((float)$p['avg_rating']) ?>
                                    <span class="text-muted ms-1" style="font-size:.72rem;"><?= number_format($p['avg_rating'],1) ?> (<?= $p['rating_count'] ?>)</span>
                                </div>
                            <?php endif; ?>
                            <div style="font-size:.72rem;color:#9ca3af;"><i class="bi bi-eye"></i> <?= number_format($p['view_count']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <nav aria-label="Project pages">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                    <a class="page-link" href="<?= base_url('vault.php?'.http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year,'tag'=>$tag_slug,'domain'=>$domain,'page'=>$page-1])) ?>">‹</a>
                </li>
                <?php for ($pg=max(1,$page-2); $pg<=min($pages,$page+2); $pg++): ?>
                <li class="page-item <?= $pg===$page?'active':'' ?>">
                    <a class="page-link" href="<?= base_url('vault.php?'.http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year,'tag'=>$tag_slug,'domain'=>$domain,'page'=>$pg])) ?>"><?= $pg ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                    <a class="page-link" href="<?= base_url('vault.php?'.http_build_query(['q'=>$q,'sort'=>$sort,'dept'=>$dept,'year'=>$year,'tag'=>$tag_slug,'domain'=>$domain,'page'=>$page+1])) ?>">›</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /.col main -->
</div><!-- /.row -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
