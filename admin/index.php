<?php require_once '../includes/config.php'; ?>
<?php
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if (!function_exists('adminImg')) {
    function adminImg($url) {
        if (empty($url)) return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        return '../' . ltrim($url, '/');
    }
}

/* ============================================================
   XỬ LÝ FILTER THỜI GIAN
   ============================================================ */
$rangeType = $_GET['range'] ?? 'month';   // day | month | year
$rangeVal  = $_GET['val']   ?? date('Y-m');

if (!in_array($rangeType, ['day', 'month', 'year'], true)) {
    $rangeType = 'month';
}

switch ($rangeType) {
    case 'day':
        $baseDate = DateTime::createFromFormat('Y-m-d', $rangeVal) ?: new DateTime();
        $from = clone $baseDate;
        $from->modify('-29 days');
        $to = clone $baseDate;
        break;

    case 'year':
        $year = (int)$rangeVal;
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');
        $from = new DateTime("$year-01-01");
        $to   = new DateTime("$year-12-31");
        break;

    case 'month':
    default:
        $baseDate = DateTime::createFromFormat('Y-m', $rangeVal) ?: new DateTime();
        $baseDate->modify('first day of this month');
        $from = clone $baseDate;
        $from->modify('-29 days');
        $to = clone $baseDate;
        $to->modify('last day of this month');
        break;
}

$fromStr = $from->format('Y-m-d 00:00:00');
$toStr   = $to->format('Y-m-d 23:59:59');

include 'includes/admin_header.php';

/* 1. DOANH THU THEO KỲ */
$stmtRev = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS order_count
    FROM orders
    WHERE status != 'cancelled' AND created_at BETWEEN ? AND ?
");
$stmtRev->execute([$fromStr, $toStr]);
$periodRevenue = $stmtRev->fetch(PDO::FETCH_ASSOC);

/* 2. HÔM NAY / THÁNG NÀY / NĂM NAY */
$today = date('Y-m-d');
$todayRevenue = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM orders
    WHERE status != 'cancelled' AND DATE(created_at) = '$today'
")->fetchColumn();

$thisMonth = date('Y-m');
$thisMonthRevenue = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM orders
    WHERE status != 'cancelled' AND DATE_FORMAT(created_at, '%Y-%m') = '$thisMonth'
")->fetchColumn();

$thisYear = date('Y');
$thisYearRevenue = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM orders
    WHERE status != 'cancelled' AND YEAR(created_at) = $thisYear
")->fetchColumn();

$totalOrders   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

/* 3. BIỂU ĐỒ ĐƯỜNG */
$chartFrom = $from->format('Y-m-d');
$chartTo   = $to->format('Y-m-d');

$daysDiff = (int)$from->diff($to)->days;
$groupBy  = $daysDiff > 90 ? 'month' : 'day';

if ($groupBy === 'day') {
    $sqlChart = "
        SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS cnt
        FROM orders
        WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ";
} else {
    $sqlChart = "
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS d, COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS cnt
        FROM orders
        WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY d ASC
    ";
}

$stmtChart = $pdo->prepare($sqlChart);
$stmtChart->execute([$chartFrom, $chartTo]);
$chartRows = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

$chartData = [];
$map = [];
foreach ($chartRows as $r) { $map[$r['d']] = $r; }

if ($groupBy === 'day') {
    $cursor = clone $from;
    $end = clone $to;
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $chartData[] = [
            'label'   => $cursor->format('d/m'),
            'revenue' => (float)($map[$key]['revenue'] ?? 0),
            'count'   => (int)($map[$key]['cnt'] ?? 0),
        ];
        $cursor->modify('+1 day');
    }
} else {
    $cursor = clone $from;
    $cursor->modify('first day of this month');
    $end = clone $to;
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m');
        $chartData[] = [
            'label'   => $cursor->format('m/Y'),
            'revenue' => (float)($map[$key]['revenue'] ?? 0),
            'count'   => (int)($map[$key]['cnt'] ?? 0),
        ];
        $cursor->modify('+1 month');
    }
}

/* 4. BIỂU ĐỒ CỘT 12 THÁNG */
$yearForChart = (int)date('Y');
if ($rangeType === 'year') {
    $yearForChart = (int)$rangeVal;
}

$stmtYear = $pdo->prepare("
    SELECT MONTH(created_at) AS m, COALESCE(SUM(total_amount), 0) AS revenue
    FROM orders
    WHERE status != 'cancelled' AND YEAR(created_at) = ?
    GROUP BY MONTH(created_at)
");
$stmtYear->execute([$yearForChart]);
$mapYear = [];
foreach ($stmtYear->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mapYear[(int)$r['m']] = (float)$r['revenue'];
}

$yearLabels = [];
$yearValues = [];
for ($m = 1; $m <= 12; $m++) {
    $yearLabels[] = 'T' . $m;
    $yearValues[] = $mapYear[$m] ?? 0;
}

/* 5. TOP SẢN PHẨM BÁN CHẠY */
$stmtTop = $pdo->prepare("
    SELECT oi.product_id, p.name, p.image_url,
           SUM(oi.quantity) AS total_qty,
           SUM(oi.price * oi.quantity) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.status != 'cancelled' AND o.created_at BETWEEN ? AND ?
    GROUP BY oi.product_id, p.name, p.image_url
    ORDER BY total_qty DESC
    LIMIT 8
");
$stmtTop->execute([$fromStr, $toStr]);
$topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

/* 6. SẮP HẾT HÀNG */
$lowStock = $pdo->query("
    SELECT p.id, p.name, p.image_url, COALESCE(SUM(ps.stock), 0) AS total_stock
    FROM products p
    LEFT JOIN product_sizes ps ON p.id = ps.product_id
    GROUP BY p.id, p.name, p.image_url
    HAVING total_stock < 10
    ORDER BY total_stock ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

/* 7. TỒN LÂU, BÁN CHẬM */
$stmtSlow = $pdo->prepare("
    SELECT
        p.id, p.name, p.image_url, p.price, p.created_at, p.sold_count,
        DATEDIFF(NOW(), p.created_at) AS days_old,
        COALESCE(SUM(ps.stock), 0) AS total_stock,
        COALESCE((
            SELECT SUM(oi.quantity)
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE oi.product_id = p.id
              AND o.status != 'cancelled'
              AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ), 0) AS sold_30d
    FROM products p
    LEFT JOIN product_sizes ps ON p.id = ps.product_id
    WHERE p.created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY p.id, p.name, p.image_url, p.price, p.created_at, p.sold_count
    HAVING sold_30d < 3 AND total_stock > 0
    ORDER BY sold_30d ASC, days_old DESC
    LIMIT 10
");
$stmtSlow->execute();
$slowProducts = $stmtSlow->fetchAll(PDO::FETCH_ASSOC);

/* 8. BANNER (đưa lên đầu trang) */
$banners = $pdo->query("SELECT * FROM banners WHERE active = 1 AND position = 'homepage' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$bannerCount = count($banners);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
/* ============================================================ 
   DASHBOARD — giao diện mới
   ============================================================ */
.dash { padding-top: 1.25rem; padding-bottom: 3rem; }
.dash > * + * { margin-top: 1.75rem; }

.dash-h {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: .75rem; flex-wrap: wrap; margin: 0 0 .85rem;
}
.dash-h h2 {
    font-family: 'Fraunces', serif; font-size: 1.25rem; font-weight: 600;
    margin: 0; color: var(--admin-text);
}
.dash-h small, .dash-h a {
    font-size: .82rem; color: var(--admin-text-muted); text-decoration: none;
}
.dash-h a:hover { color: var(--admin-accent); }

.panel {
    background: #fff; border: 1px solid var(--admin-border);
    border-radius: 12px; overflow: hidden;
}
.panel__pad { padding: 1.1rem 1.3rem; }

/* ---------- BANNER HERO ---------- */
.bn {
    position: relative; border-radius: 16px; overflow: hidden;
    background: #1c1b19; isolation: isolate;
    box-shadow: 0 18px 40px -22px rgba(28, 27, 25, .55);
}
.bn__viewport { overflow: hidden; touch-action: pan-y; }
.bn__track {
    display: flex; list-style: none; margin: 0; padding: 0;
    transition: transform .6s cubic-bezier(.22, .8, .24, 1);
}
.bn__slide { flex: 0 0 100%; min-width: 0; }
.bn__link {
    display: block; position: relative; color: #fff; text-decoration: none;
    height: clamp(190px, 28vw, 360px);
}
.bn__img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bn__shade {
    position: absolute; inset: 0;
    background: linear-gradient(90deg, rgba(20,18,15,.78) 0%, rgba(20,18,15,.35) 45%, rgba(20,18,15,0) 75%);
}
.bn__cap {
    position: absolute; left: clamp(1.1rem, 3vw, 2.5rem); bottom: clamp(1.2rem, 3.2vw, 2.5rem);
    max-width: min(560px, 80%);
}
.bn__tag { font-size: .8rem; opacity: .8; margin-bottom: .35rem; }
.bn__title {
    font-family: 'Fraunces', serif; font-weight: 600; margin: 0;
    font-size: clamp(1.35rem, 3.4vw, 2.5rem); line-height: 1.12;
}
.bn__arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: 42px; height: 42px; border-radius: 50%; border: 0; cursor: pointer;
    background: rgba(255,255,255,.9); color: #1c1b19; font-size: 1rem;
    display: grid; place-items: center; transition: background .15s;
}
.bn__arrow:hover { background: #fff; }
.bn__arrow--prev { left: 14px; }
.bn__arrow--next { right: 14px; }
.bn__foot {
    position: absolute; right: 18px; bottom: 16px;
    display: flex; align-items: center; gap: 10px; color: #fff;
}
.bn__count { font-family: 'IBM Plex Mono', monospace; font-size: .78rem; opacity: .85; }
.bn__dots { display: flex; gap: 6px; }
.bn__dot {
    width: 8px; height: 8px; border-radius: 99px; border: 0; padding: 0; cursor: pointer;
    background: rgba(255,255,255,.5); transition: width .25s, background .25s;
}
.bn__dot[aria-current="true"] { width: 26px; background: #fff; }
.bn__manage {
    position: absolute; top: 14px; right: 14px; z-index: 2;
    font-size: .78rem; padding: .35rem .8rem; border-radius: 99px;
    background: rgba(255,255,255,.92); color: #1c1b19; text-decoration: none;
}
.bn__manage:hover { background: #fff; }

.bn-empty {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    border: 1.5px dashed var(--admin-border); border-radius: 16px;
    padding: 1.6rem 1.8rem; background: #fff;
}
.bn-empty p { margin: 0; color: var(--admin-text-muted); }
.bn-empty strong { display: block; color: var(--admin-text); font-size: 1.05rem; margin-bottom: .2rem; }

/* ---------- TIÊU ĐỀ + BỘ LỌC ---------- */
.dash-top {
    display: flex; justify-content: space-between; align-items: flex-end;
    gap: 1rem; flex-wrap: wrap;
}
.dash-top h1 {
    font-family: 'Fraunces', serif; font-size: 1.9rem; font-weight: 600;
    margin: 0 0 .2rem; color: var(--admin-text);
}
.dash-top .range { color: var(--admin-text-muted); font-size: .9rem; margin: 0; }
.dash-top .range b { color: var(--admin-text); font-weight: 600; }

.dash-tools { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.seg {
    display: inline-flex; background: #f3efe8; border-radius: 8px; padding: 3px; gap: 2px;
}
.seg a {
    padding: .42rem .9rem; border-radius: 6px; font-size: .84rem; font-weight: 500;
    color: var(--admin-text-muted); text-decoration: none; transition: background .15s, color .15s;
}
.seg a:hover { color: var(--admin-text); }
.seg a.active { background: #fff; color: var(--admin-text); box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.dash-tools form { display: flex; gap: .5rem; align-items: center; margin: 0; }

/* ---------- DOANH THU ---------- */
.ledger { display: grid; grid-template-columns: minmax(260px, 1.15fr) 2fr; }
.ledger__main {
    background: var(--admin-sidebar-bg, #1c1b19); color: #fffdf9; padding: 1.6rem 1.7rem;
    display: flex; flex-direction: column; justify-content: space-between; gap: 1.4rem;
}
.ledger__main .lbl { font-size: .9rem; opacity: .75; margin: 0 0 .5rem; }
.ledger__main .big {
    font-family: 'Fraunces', serif; font-weight: 600; line-height: 1;
    font-size: clamp(1.9rem, 3.4vw, 2.8rem); word-break: break-word;
}
.ledger__main .big sup { font-size: .45em; margin-left: .15em; opacity: .8; vertical-align: super; }
.ledger__main .sub { font-size: .88rem; opacity: .8; margin: 0; }

.ledger__side { display: grid; grid-template-columns: repeat(3, 1fr); }
.ledger__cell { padding: 1.3rem 1.3rem; border-left: 1px solid var(--admin-border); }
.ledger__cell:first-child { border-left: 0; }
.ledger__cell .lbl { font-size: .85rem; color: var(--admin-text-muted); margin: 0 0 .45rem; }
.ledger__cell .val {
    font-family: 'IBM Plex Mono', monospace; font-weight: 700; font-size: 1.25rem;
    color: var(--admin-text); line-height: 1.15;
}
.ledger__cell .val sup { font-size: .6em; font-weight: 500; color: var(--admin-text-muted); margin-left: 2px; }
.ledger__cell .sub { font-size: .78rem; color: var(--admin-text-muted); margin: .35rem 0 0; }
.ledger__counts {
    grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: .4rem 2rem;
    border-top: 1px solid var(--admin-border); padding: .85rem 1.3rem;
    font-size: .88rem; color: var(--admin-text-muted);
}
.ledger__counts b { color: var(--admin-text); font-family: 'IBM Plex Mono', monospace; margin-right: .3rem; }

/* ---------- BIỂU ĐỒ ---------- */
.cols-charts { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; }
.chart-wrap { position: relative; height: 300px; }

/* ---------- TOP + CẢNH BÁO ---------- */
.cols-lists { display: grid; grid-template-columns: 1.15fr 1fr; gap: 1rem; align-items: start; }
.stack { display: grid; gap: 1rem; }

.top-list { list-style: none; margin: 0; padding: 0; }
.row {
    display: grid; gap: .8rem; align-items: center; padding: .7rem 1.1rem;
    border-top: 1px solid #f1ede6; text-decoration: none; color: inherit;
    transition: background .15s;
}
.row:first-child { border-top: 0; }
.row:hover { background: #faf7f2; }
.row--top { grid-template-columns: 26px 46px 1fr auto; }
.row--alert { grid-template-columns: 42px 1fr auto; }
.row img {
    width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; background: #f1f5f9; display: block;
}
.row__rank {
    font-family: 'Fraunces', serif; font-size: 1.15rem; font-weight: 600;
    color: var(--admin-text-muted); text-align: center;
}
.row__rank.gold { color: #c9971a; } .row__rank.silver { color: #9a9a9a; } .row__rank.bronze { color: #b87333; }
.row__info { min-width: 0; }
.row__name {
    font-size: .9rem; font-weight: 600; color: var(--admin-text);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.row__meta { font-size: .78rem; color: var(--admin-text-muted); margin-top: 2px; }
.row__stat { text-align: right; font-family: 'IBM Plex Mono', monospace; }
.row__qty { font-weight: 700; font-size: .95rem; color: var(--admin-accent); }
.row__rev { font-size: .72rem; color: var(--admin-text-muted); margin-top: 2px; }
.pill {
    font-size: .78rem; font-weight: 600; padding: 3px 10px; border-radius: 99px; white-space: nowrap;
}
.pill--warn { background: #fef3c7; color: #92400e; }
.pill--bad { background: #fee2e2; color: #991b1b; }

.alert-head {
    display: flex; align-items: center; gap: .5rem; padding: .85rem 1.1rem;
    border-bottom: 1px solid var(--admin-border); font-weight: 600; font-size: .95rem;
}
.alert-head--warn { background: #fffbeb; color: #92400e; }
.alert-head--bad { background: #fef2f2; color: #991b1b; }
.alert-head .n {
    margin-left: auto; background: rgba(0,0,0,.08); border-radius: 99px;
    padding: 1px 9px; font-size: .75rem;
}
.alert-scroll { list-style: none; margin: 0; padding: 0; max-height: 300px; overflow-y: auto; }
.empty { padding: 1.8rem 1.1rem; text-align: center; color: var(--admin-text-muted); font-size: .9rem; }

/* ---------- RESPONSIVE ---------- */
@media (max-width: 1000px) {
    .cols-charts, .cols-lists { grid-template-columns: 1fr; }
    .ledger { grid-template-columns: 1fr; }
}
@media (max-width: 620px) {
    .ledger__side { grid-template-columns: 1fr; }
    .ledger__cell { border-left: 0; border-top: 1px solid var(--admin-border); }
    .ledger__cell:first-child { border-top: 0; }
    .bn__arrow { width: 34px; height: 34px; }
    .bn__count { display: none; }
    .row--top { grid-template-columns: 22px 40px 1fr auto; }
}
@media (prefers-reduced-motion: reduce) {
    .bn__track, .bn__dot { transition: none; }
}
.bn__arrow:focus-visible, .bn__dot:focus-visible, .seg a:focus-visible, .row:focus-visible {
    outline: 2px solid #fff; outline-offset: 2px;
}
.seg a:focus-visible, .row:focus-visible { outline-color: var(--admin-accent); }
</style>

<div class="container-custom dash">

    <!-- ============ 1. BANNER (ĐẦU TRANG) ============ -->
    <?php if ($bannerCount > 0): ?>
    <section class="bn" id="bn" data-autoplay="4500" aria-label="Banner quảng cáo đang chạy">
        <a href="banners.php" class="bn__manage">Quản lý banner</a>
        <div class="bn__viewport">
            <ul class="bn__track" id="bnTrack">
                <?php foreach ($banners as $i => $banner): ?>
                <li class="bn__slide">
                    <a class="bn__link" href="<?php echo sanitize($banner['link']); ?>">
                        <img class="bn__img"
                             src="<?php echo adminImg($banner['image_url']); ?>"
                             alt="<?php echo sanitize($banner['title']); ?>"
                             <?php echo $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
                        <div class="bn__shade"></div>
                        <?php if (!empty($banner['title'])): ?>
                        <div class="bn__cap">
                            <div class="bn__tag">Banner #<?php echo (int)$banner['id']; ?></div>
                            <h2 class="bn__title"><?php echo sanitize($banner['title']); ?></h2>
                        </div>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php if ($bannerCount > 1): ?>
        <button type="button" class="bn__arrow bn__arrow--prev" id="bnPrev" aria-label="Banner trước">❮</button>
        <button type="button" class="bn__arrow bn__arrow--next" id="bnNext" aria-label="Banner sau">❯</button>
        <div class="bn__foot">
            <span class="bn__count" id="bnCount">1 / <?php echo $bannerCount; ?></span>
            <div class="bn__dots" id="bnDots" role="tablist" aria-label="Chọn banner"></div>
        </div>
        <?php endif; ?>
    </section>
    <?php else: ?>
    <div class="bn-empty">
        <p><strong>Chưa có banner nào đang chạy</strong>Thêm banner để hiển thị quảng cáo trên trang chủ.</p>
        <a href="banners.php" class="btn-adm btn-adm--primary">Thêm banner</a>
    </div>
    <?php endif; ?>

    <!-- ============ 2. TIÊU ĐỀ + BỘ LỌC ============ -->
    <div class="dash-top">
        <div>
            <h1>Dashboard</h1>
            <p class="range">Đang xem từ <b><?php echo $from->format('d/m/Y'); ?></b> đến <b><?php echo $to->format('d/m/Y'); ?></b></p>
        </div>

        <div class="dash-tools">
            <nav class="seg" aria-label="Chọn kiểu thời gian">
                <a href="?range=day&val=<?php echo date('Y-m-d'); ?>" class="<?php echo $rangeType === 'day' ? 'active' : ''; ?>">Ngày</a>
                <a href="?range=month&val=<?php echo date('Y-m'); ?>" class="<?php echo $rangeType === 'month' ? 'active' : ''; ?>">Tháng</a>
                <a href="?range=year&val=<?php echo date('Y'); ?>" class="<?php echo $rangeType === 'year' ? 'active' : ''; ?>">Năm</a>
            </nav>

            <form method="GET">
                <input type="hidden" name="range" value="<?php echo htmlspecialchars($rangeType); ?>">
                <?php if ($rangeType === 'day'): ?>
                    <input type="date" name="val" class="adm-input"
                           value="<?php echo $to->format('Y-m-d'); ?>"
                           style="padding:0.45rem 0.7rem;font-size:0.85rem;width:auto;">
                <?php elseif ($rangeType === 'year'): ?>
                    <select name="val" class="adm-select" style="padding:0.45rem 0.7rem;font-size:0.85rem;width:auto;">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $rangeVal ? 'selected' : ''; ?>>Năm <?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                <?php else: ?>
                    <input type="month" name="val" class="adm-input"
                           value="<?php echo $to->format('Y-m'); ?>"
                           style="padding:0.45rem 0.7rem;font-size:0.85rem;width:auto;">
                <?php endif; ?>
                <button type="submit" class="btn-adm btn-adm--primary" style="padding:0.5rem 1rem;">Xem</button>
            </form>
        </div>
    </div>

    <!-- ============ 3. DOANH THU ============ -->
    <section class="panel ledger" aria-label="Doanh thu">
        <div class="ledger__main">
            <div>
                <p class="lbl">Doanh thu trong kỳ đã chọn</p>
                <div class="big"><?php echo number_format((float)$periodRevenue['revenue'], 0, ',', '.'); ?><sup>đ</sup></div>
            </div>
            <p class="sub"><?php echo (int)$periodRevenue['order_count']; ?> đơn hàng (không tính đơn đã hủy)</p>
        </div>

        <div class="ledger__side">
            <div class="ledger__cell">
                <p class="lbl">Hôm nay</p>
                <div class="val"><?php echo number_format($todayRevenue, 0, ',', '.'); ?><sup>đ</sup></div>
                <p class="sub"><?php echo date('d/m/Y'); ?></p>
            </div>
            <div class="ledger__cell">
                <p class="lbl">Tháng <?php echo date('m'); ?></p>
                <div class="val"><?php echo number_format($thisMonthRevenue, 0, ',', '.'); ?><sup>đ</sup></div>
                <p class="sub">Tháng <?php echo date('m/Y'); ?></p>
            </div>
            <div class="ledger__cell">
                <p class="lbl">Năm <?php echo $thisYear; ?></p>
                <div class="val"><?php echo number_format($thisYearRevenue, 0, ',', '.'); ?><sup>đ</sup></div>
                <p class="sub">Cả năm <?php echo $thisYear; ?></p>
            </div>
            <div class="ledger__counts">
                <span><b><?php echo number_format($totalOrders); ?></b>đơn hàng</span>
                <span><b><?php echo number_format($totalUsers); ?></b>khách hàng</span>
                <span><b><?php echo number_format($totalProducts); ?></b>sản phẩm</span>
            </div>
        </div>
    </section>

    <!-- ============ 4. BIỂU ĐỒ ============ -->
    <section class="cols-charts">
        <div>
            <div class="dash-h">
                <h2>Doanh thu <?php echo $groupBy === 'day' ? 'theo ngày' : 'theo tháng'; ?></h2>
                <small><?php echo count($chartData); ?> mốc dữ liệu</small>
            </div>
            <div class="panel panel__pad"><div class="chart-wrap"><canvas id="revenueLineChart"></canvas></div></div>
        </div>
        <div>
            <div class="dash-h">
                <h2>12 tháng năm <?php echo $yearForChart; ?></h2>
            </div>
            <div class="panel panel__pad"><div class="chart-wrap"><canvas id="revenueBarChart"></canvas></div></div>
        </div>
    </section>

    <!-- ============ 5. TOP SẢN PHẨM + CẢNH BÁO KHO ============ -->
    <section class="cols-lists">
        <div>
            <div class="dash-h">
                <h2>Sản phẩm bán chạy <small>trong kỳ đã chọn</small></h2>
                <a href="products.php">Quản lý sản phẩm</a>
            </div>
            <div class="panel">
                <?php if (empty($topProducts)): ?>
                    <div class="empty">Chưa có sản phẩm nào bán trong kỳ này.</div>
                <?php else: ?>
                    <ul class="top-list">
                        <?php foreach ($topProducts as $i => $p):
                            $rank = $i + 1;
                            $rankCls = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        ?>
                        <li>
                            <a class="row row--top" href="products.php?edit=<?php echo (int)$p['product_id']; ?>">
                                <div class="row__rank <?php echo $rankCls; ?>"><?php echo $rank; ?></div>
                                <img src="<?php echo adminImg($p['image_url']); ?>" alt="">
                                <div class="row__info">
                                    <div class="row__name"><?php echo sanitize($p['name'] ?? '—'); ?></div>
                                    <div class="row__meta">SP #<?php echo (int)$p['product_id']; ?></div>
                                </div>
                                <div class="row__stat">
                                    <div class="row__qty"><?php echo number_format((int)$p['total_qty']); ?> sp</div>
                                    <div class="row__rev"><?php echo number_format((float)$p['total_revenue'], 0, ',', '.'); ?>đ</div>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="dash-h"><h2>Cảnh báo tồn kho</h2></div>
            <div class="stack">
                <div class="panel">
                    <div class="alert-head alert-head--warn">Sắp hết hàng <span class="n"><?php echo count($lowStock); ?></span></div>
                    <?php if (empty($lowStock)): ?>
                        <div class="empty">Không có sản phẩm nào sắp hết hàng.</div>
                    <?php else: ?>
                        <ul class="alert-scroll">
                            <?php foreach ($lowStock as $p): ?>
                            <li>
                                <a class="row row--alert" href="products.php?edit=<?php echo (int)$p['id']; ?>">
                                    <img src="<?php echo adminImg($p['image_url']); ?>" alt="">
                                    <div class="row__info">
                                        <div class="row__name"><?php echo sanitize($p['name']); ?></div>
                                        <div class="row__meta">SP #<?php echo (int)$p['id']; ?></div>
                                    </div>
                                    <span class="pill pill--warn">Còn <?php echo (int)$p['total_stock']; ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="alert-head alert-head--bad">Tồn lâu, bán chậm <span class="n"><?php echo count($slowProducts); ?></span></div>
                    <?php if (empty($slowProducts)): ?>
                        <div class="empty">Không có sản phẩm nào bán chậm.</div>
                    <?php else: ?>
                        <ul class="alert-scroll">
                            <?php foreach ($slowProducts as $p): ?>
                            <li>
                                <a class="row row--alert" href="products.php?edit=<?php echo (int)$p['id']; ?>">
                                    <img src="<?php echo adminImg($p['image_url']); ?>" alt="">
                                    <div class="row__info">
                                        <div class="row__name"><?php echo sanitize($p['name']); ?></div>
                                        <div class="row__meta">30 ngày qua bán <?php echo (int)$p['sold_30d']; ?> · Kho <?php echo (int)$p['total_stock']; ?></div>
                                    </div>
                                    <span class="pill pill--bad"><?php echo (int)$p['days_old']; ?> ngày</span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

</div>

<script src="../js/chatbot.js"></script>
<script>
/* ============================================================ 
   BANNER SLIDER
   ============================================================ */
(function () {
    var root = document.getElementById('bn');
    if (!root) return;
    var track = document.getElementById('bnTrack');
    var slides = Array.prototype.slice.call(track.children);
    if (slides.length < 2) return;

    var prevBtn = document.getElementById('bnPrev');
    var nextBtn = document.getElementById('bnNext');
    var dotsBox = document.getElementById('bnDots');
    var countEl = document.getElementById('bnCount');
    var delay = parseInt(root.getAttribute('data-autoplay'), 10) || 4500;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var index = 0, timer = null, dots = [];

    slides.forEach(function (_, i) {
        var d = document.createElement('button');
        d.type = 'button';
        d.className = 'bn__dot';
        d.setAttribute('aria-label', 'Banner ' + (i + 1));
        d.addEventListener('click', function () { goTo(i); });
        dotsBox.appendChild(d);
        dots.push(d);
    });

    function render() {
        track.style.transform = 'translateX(-' + (index * 100) + '%)';
        dots.forEach(function (d, i) {
            if (i === index) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current');
        });
        if (countEl) countEl.textContent = (index + 1) + ' / ' + slides.length;
    }
    function goTo(i) { index = ((i % slides.length) + slides.length) % slides.length; render(); restart(); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() {
        if (reduce) return;
        stop();
        timer = setInterval(function () { index = (index + 1) % slides.length; render(); }, delay);
    }
    function restart() { stop(); start(); }

    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(index + 1); });
    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(index - 1); });
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);

    var startX = null;
    root.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; stop(); }, { passive: true });
    root.addEventListener('touchend', function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        startX = null;
        if (Math.abs(dx) > 50) goTo(index + (dx < 0 ? 1 : -1)); else start();
    });

    render();
    start();
})();

/* ============================================================ 
   CHARTS
   ============================================================ */
(function () {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#6b7280';

    var mono = { family: "'IBM Plex Mono', monospace", size: 10 };
    var fmtShort = function (v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
        return v;
    };
    var tooltipBase = {
        backgroundColor: '#1c1b19',
        padding: 12,
        titleFont: { family: "'IBM Plex Mono', monospace", size: 12 },
        bodyFont: { family: "'IBM Plex Mono', monospace", size: 12 },
    };

    /* ---------- ĐƯỜNG ---------- */
    var lineData = <?php echo json_encode($chartData); ?>;
    var ctxLine = document.getElementById('revenueLineChart');
    if (ctxLine) {
        var labels = lineData.map(function (d) { return d.label; });
        var revenueData = lineData.map(function (d) { return d.revenue; });
        var countData = lineData.map(function (d) { return d.count; });
        var skipLabels = labels.length > 30 ? Math.ceil(labels.length / 12) : Math.ceil(labels.length / 10);

        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Doanh thu (đ)',
                    data: revenueData,
                    borderColor: '#a8461f',
                    backgroundColor: 'rgba(168, 70, 31, 0.08)',
                    borderWidth: 2.5,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#a8461f',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: labels.length > 60 ? 0 : 3,
                    pointHoverRadius: 6,
                    yAxisID: 'y',
                }, {
                    label: 'Số đơn',
                    data: countData,
                    borderColor: '#2563eb',
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [5, 4],
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    yAxisID: 'y1',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top', align: 'start',
                        labels: { boxWidth: 12, boxHeight: 12, padding: 15, usePointStyle: true, pointStyle: 'rectRounded' },
                    },
                    tooltip: Object.assign({}, tooltipBase, {
                        callbacks: {
                            label: function (ctx) {
                                var v = ctx.parsed.y;
                                return ctx.datasetIndex === 0
                                    ? ' Doanh thu: ' + new Intl.NumberFormat('vi-VN').format(v) + 'đ'
                                    : ' Số đơn: ' + v;
                            },
                        },
                    }),
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxRotation: 0, autoSkip: false, font: mono,
                            callback: function (val, idx) {
                                return idx % skipLabels === 0 ? this.getLabelForValue(val) : '';
                            },
                        },
                    },
                    y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: mono, callback: fmtShort } },
                    y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0, font: mono } },
                },
            },
        });
    }

    /* ---------- CỘT 12 THÁNG ---------- */
    var ctxBar = document.getElementById('revenueBarChart');
    if (ctxBar) {
        var yearValues = <?php echo json_encode($yearValues); ?>;
        var maxVal = Math.max.apply(null, yearValues);
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($yearLabels); ?>,
                datasets: [{
                    label: 'Doanh thu',
                    data: yearValues,
                    backgroundColor: yearValues.map(function (v) { return (v === maxVal && v > 0) ? '#a8461f' : '#e8e0d0'; }),
                    hoverBackgroundColor: '#a8461f',
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 28,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipBase, {
                        callbacks: {
                            label: function (ctx) {
                                return ' Doanh thu: ' + new Intl.NumberFormat('vi-VN').format(ctx.parsed.y) + 'đ';
                            },
                        },
                    }),
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: mono } },
                    y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: mono, callback: fmtShort } },
                },
            },
        });
    }
})();
</script>

<?php include 'includes/admin_footer.php'; ?>