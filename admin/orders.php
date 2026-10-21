<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

/* ============================================================
   LẤY DỮ LIỆU
   ============================================================ */
$filter    = $_GET['status'] ?? '';
$payFilter = $_GET['pay'] ?? '';
$search    = trim($_GET['q'] ?? '');

$where = []; $params = [];

if (in_array($filter, ['pending','processing','confirmed','shipped','delivered','cancelled'], true)) {
    $where[]  = 'o.status = ?';
    $params[] = $filter;
}

if (in_array($payFilter, ['unpaid','pending','paid','refund_pending'], true)) {
    $where[]  = 'o.payment_status = ?';
    $params[] = $payFilter;
}

if ($search !== '') {
    $where[]  = "(o.id = ? OR o.full_name LIKE ? OR o.phone LIKE ? OR u.email LIKE ?)";
    $params[] = is_numeric($search) ? (int)$search : 0;
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT o.*,
           u.full_name AS user_name,
           u.email AS user_email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    $whereSQL
    ORDER BY o.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   MAP
   ============================================================ */
$statusMap = [
    'pending'    => ['Chờ xử lý',   'adm-badge--orange'],
    'processing' => ['Đang xử lý',  'adm-badge--blue'],
    'confirmed'  => ['Đã xác nhận', 'adm-badge--blue'],
    'shipped'    => ['Đang giao',   'adm-badge--blue'],
    'delivered'  => ['Đã giao',     'adm-badge--green'],
    'cancelled'  => ['Đã hủy',      'adm-badge--red'],
];

// Sử dụng Phosphor Icons thay cho Emoji
$payMap = [
    'cod'    => '<i class="ph ph-money"></i> COD',
    'bank'   => '<i class="ph ph-bank"></i> Chuyển khoản',
    'card'   => '<i class="ph ph-credit-card"></i> Thẻ',
    'wallet' => '<i class="ph ph-wallet"></i> Atelier Pay',
];

$payStatusMap = [
    'unpaid'         => ['Chưa thanh toán', 'adm-badge--gray'],
    'pending'        => ['Chờ thanh toán',  'adm-badge--orange'],
    'paid'           => ['Đã thanh toán',   'adm-badge--green'],
    'refund_pending' => ['Chờ hoàn tiền',   'adm-badge--red'],
];

/* ============================================================
   ĐẾM
   ============================================================ */
$countStmt = $pdo->query("SELECT status, COUNT(*) AS total FROM orders GROUP BY status");
$statusCounts = [
    'pending' => 0, 'processing' => 0, 'confirmed' => 0,
    'shipped' => 0, 'delivered' => 0, 'cancelled' => 0,
];
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int)$row['total'];
    }
}

$totalPending   = $statusCounts['pending'] + $statusCounts['processing'] + $statusCounts['confirmed'];
$totalShipping  = $statusCounts['shipped'];
$totalDelivered = $statusCounts['delivered'];
$totalCancelled = $statusCounts['cancelled'];
$totalAll       = array_sum($statusCounts);

$pendingActive = in_array($filter, ['pending', 'processing', 'confirmed'], true);

$payCountStmt = $pdo->query("SELECT payment_status, COUNT(*) AS total FROM orders GROUP BY payment_status");
$payCounts = ['unpaid' => 0, 'pending' => 0, 'paid' => 0, 'refund_pending' => 0];
foreach ($payCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($payCounts[$row['payment_status']])) {
        $payCounts[$row['payment_status']] = (int)$row['total'];
    }
}
?>

<!-- ============================================================ CSS ============================================================ -->
<style>
    .adm-orders {
        --c-primary:       #a8461f;
        --c-bg:            #faf8f4;
        --c-card:          #ffffff;
        --c-border:        #efeae0;
        --c-border-strong: #ddd5c7;
        --c-text:          #24211d;
        --c-text-muted:    #8a8377;
        --c-shadow-sm:     0 1px 2px rgba(36, 33, 29, 0.04);
        --c-shadow-md:     0 8px 24px rgba(36, 33, 29, 0.07);
        --c-shadow-lg:     0 16px 40px rgba(36, 33, 29, 0.10);
        --r-sm: 8px;
        --r-md: 12px;
        --r-lg: 16px;
    }

    /* ============ PAGE HEAD ============ */
    .adm-orders .admin-page-head {
        display: flex; align-items: flex-end; justify-content: space-between;
        margin-bottom: 1.5rem; padding-bottom: 1.1rem;
        border-bottom: 1px solid var(--c-border); gap: 1rem; flex-wrap: wrap;
    }
    .adm-orders .admin-page-head h1 {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 2rem; margin: 0; color: var(--c-text);
        letter-spacing: -0.015em; line-height: 1.1;
    }
    .adm-orders .admin-page-head .subtitle {
        font-size: 0.85rem; color: var(--c-text-muted); margin-top: 0.4rem;
    }
    .adm-orders .admin-page-head .subtitle b {
        color: var(--c-primary); font-family: 'IBM Plex Mono', monospace;
    }

    /* ============ STATS ============ */
    .adm-orders .adm-stats {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 0.9rem; margin-bottom: 1.5rem;
    }
    .adm-orders .adm-stat-card {
        position: relative; display: flex; align-items: center; gap: 0.9rem;
        padding: 1.1rem 1.2rem; background: var(--c-card);
        border: 1px solid var(--c-border); border-radius: var(--r-md);
        text-decoration: none; color: inherit; overflow: hidden;
        transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1),
                    box-shadow 0.22s ease, border-color 0.22s ease;
        box-shadow: var(--c-shadow-sm);
    }
    .adm-orders .adm-stat-card::after {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(circle at 100% 0%, rgba(168, 70, 31, 0.05), transparent 55%);
        opacity: 0; transition: opacity 0.22s ease; pointer-events: none;
    }
    .adm-orders .adm-stat-card:hover {
        transform: translateY(-3px); box-shadow: var(--c-shadow-md);
        border-color: var(--c-border-strong);
    }
    .adm-orders .adm-stat-card:hover::after { opacity: 1; }
    .adm-orders .adm-stat-card--active {
        border-color: var(--c-primary);
        background: linear-gradient(135deg, #fffaf5 0%, #ffffff 70%);
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.10), var(--c-shadow-md);
    }
    .adm-orders .adm-stat-card--active::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 4px; background: var(--c-primary);
    }
    .adm-orders .adm-stat-icon {
        width: 48px; height: 48px; border-radius: var(--r-md);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.6rem; flex-shrink: 0; transition: transform 0.22s ease;
    }
    .adm-orders .adm-stat-card:hover .adm-stat-icon { transform: scale(1.06) rotate(-3deg); }
    .adm-orders .adm-stat-body { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
    .adm-orders .adm-stat-value {
        font-size: 1.65rem; font-weight: 700;
        font-family: 'IBM Plex Mono', monospace;
        color: var(--c-text); letter-spacing: -0.02em;
    }
    .adm-orders .adm-stat-label {
        font-size: 0.72rem; color: var(--c-text-muted); margin-top: 3px;
        text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* ============ FILTER ============ */
    .adm-orders .adm-filter-bar {
        background: var(--c-card); border: 1px solid var(--c-border);
        border-radius: var(--r-md); padding: 1.1rem 1.25rem;
        margin-bottom: 1.25rem; box-shadow: var(--c-shadow-sm);
    }
    .adm-orders .adm-filter-form {
        display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;
    }
    .adm-orders .adm-filter-field { display: flex; flex-direction: column; min-width: 0; }
    .adm-orders .adm-filter-field--search { flex: 2 1 260px; }
    .adm-orders .adm-filter-field--status { flex: 1 1 170px; }
    .adm-orders .adm-filter-field--pay    { flex: 1 1 170px; }
    .adm-orders .adm-filter-label {
        display: block; font-size: 0.68rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.08em;
        color: var(--c-text-muted); margin-bottom: 0.45rem;
    }
    .adm-orders .adm-input,
    .adm-orders .adm-select {
        width: 100%; padding: 0.65rem 0.85rem;
        font-size: 0.88rem; font-family: inherit; color: var(--c-text);
        background: #fbf9f5; border: 1px solid var(--c-border);
        border-radius: var(--r-sm); outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .adm-orders .adm-input:hover,
    .adm-orders .adm-select:hover { border-color: var(--c-border-strong); }
    .adm-orders .adm-input:focus,
    .adm-orders .adm-select:focus {
        border-color: var(--c-primary); background: #ffffff;
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.10);
    }
    .adm-orders .adm-select {
        appearance: none; -webkit-appearance: none;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'><path d='M3 4.5l3 3 3-3' fill='none' stroke='%238a8377' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
        background-repeat: no-repeat; background-position: right 0.7rem center;
        background-size: 12px; padding-right: 2rem;
    }
    .adm-orders .adm-filter-actions { display: flex; gap: 0.5rem; align-items: center; }

    /* ============ BUTTONS ============ */
    .adm-orders .btn-adm {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 0.35rem; padding: 0.6rem 1.1rem;
        font-size: 0.82rem; font-weight: 600; font-family: inherit;
        border-radius: var(--r-sm); border: 1px solid transparent;
        cursor: pointer; text-decoration: none; white-space: nowrap;
        transition: all 0.15s ease; line-height: 1;
    }
    .adm-orders .btn-adm--primary {
        background: var(--c-primary); color: #fff;
        box-shadow: 0 2px 6px rgba(168, 70, 31, 0.25);
    }
    .adm-orders .btn-adm--primary:hover {
        background: #8f3a18; box-shadow: 0 4px 12px rgba(168, 70, 31, 0.32);
        transform: translateY(-1px);
    }
    .adm-orders .btn-adm--ghost {
        background: #fff; color: var(--c-text); border-color: var(--c-border-strong);
    }
    .adm-orders .btn-adm--ghost:hover { background: #fbf9f5; border-color: #c8bfa9; }

    /* ============ TABLE ============ */
    .adm-orders .adm-table-card {
        background: var(--c-card); border: 1px solid var(--c-border);
        border-radius: var(--r-md); overflow: hidden; box-shadow: var(--c-shadow-sm);
    }
    .adm-orders .adm-table-scroll { overflow-x: auto; }
    .adm-orders .adm-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .adm-orders .adm-table thead th {
        text-align: left; padding: 0.85rem 1rem;
        font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--c-text-muted);
        background: #fbf9f5; border-bottom: 1px solid var(--c-border);
        white-space: nowrap;
    }
    .adm-orders .adm-table tbody td {
        padding: 0.95rem 1rem; border-bottom: 1px solid var(--c-border);
        vertical-align: middle;
    }
    .adm-orders .adm-table tbody tr {
        transition: background 0.15s ease; border-left: 3px solid transparent;
    }
    .adm-orders .adm-table tbody tr:hover { background: #fbf9f5; }
    .adm-orders .adm-table tbody tr:last-child td { border-bottom: none; }
    .adm-orders .adm-table tbody tr[data-pay="paid"]           { border-left-color: #15803d; }
    .adm-orders .adm-table tbody tr[data-pay="pending"]        { border-left-color: #c2410c; }
    .adm-orders .adm-table tbody tr[data-pay="refund_pending"] { border-left-color: #b91c1c; }
    .adm-orders .adm-table tbody tr[data-pay="unpaid"]         { border-left-color: #a8a29e; }

    .adm-orders .adm-order-id {
        font-family: 'IBM Plex Mono', monospace; font-weight: 700;
        color: var(--c-text); font-size: 0.85rem;
    }
    .adm-orders .adm-customer-name { font-weight: 600; color: var(--c-text); }
    .adm-orders .adm-customer-phone {
        font-size: 0.75rem; color: var(--c-text-muted); margin-top: 2px;
        font-family: 'IBM Plex Mono', monospace;
    }
    .adm-orders .adm-date {
        font-size: 0.78rem; color: var(--c-text-muted);
        font-family: 'IBM Plex Mono', monospace; white-space: nowrap;
    }
    .adm-orders .adm-total {
        font-family: 'IBM Plex Mono', monospace; font-weight: 700;
        color: var(--c-primary); white-space: nowrap;
    }

    /* ============ BADGES ============ */
    .adm-orders .adm-badge {
        display: inline-flex; align-items: center; gap: 0.3rem;
        padding: 0.3rem 0.65rem; font-size: 0.72rem; font-weight: 600;
        border-radius: 999px; line-height: 1.2; white-space: nowrap;
        border: 1px solid transparent;
    }
    .adm-orders .adm-badge::before {
        content: ''; width: 6px; height: 6px; border-radius: 50%;
        background: currentColor; opacity: 0.85;
    }
    .adm-orders .adm-badge--orange { color: #c2410c; background: #fff7ed; border-color: #fed7aa; }
    .adm-orders .adm-badge--blue   { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
    .adm-orders .adm-badge--green  { color: #15803d; background: #f0fdf4; border-color: #bbf7d0; }
    .adm-orders .adm-badge--red    { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
    .adm-orders .adm-badge--gray   { color: #57534e; background: #f5f5f4; border-color: #e7e5e4; }

    /* ============ ACTIONS ============ */
    .adm-orders .adm-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }

    /* ============ EMPTY ============ */
    .adm-orders .adm-empty { text-align: center; padding: 4rem 1.5rem; color: var(--c-text-muted); }
    .adm-orders .adm-empty-icon { font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.5; color: var(--c-text-muted); }
    .adm-orders .adm-empty-title { font-size: 1rem; font-weight: 600; color: var(--c-text); margin-bottom: 0.35rem; }

    /* ============ CANCEL NOTE ============ */
    .adm-orders .adm-cancel-note {
        font-size: 0.72rem; color: #991b1b; margin-top: 5px; max-width: 200px;
        line-height: 1.4; padding: 0.35rem 0.55rem; background: #fef2f2;
        border-radius: var(--r-sm); border-left: 2px solid #b91c1c;
    }
    .adm-orders tr.adm-row--cancelled { opacity: 0.72; background: #fefbfb; }
    .adm-orders tr.adm-row--cancelled:hover { background: #fdf6f6; }

    /* ============ MODAL ============ */
    .adm-orders .adm-modal {
        position: fixed; inset: 0; background: rgba(36, 33, 29, 0.55);
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        display: none; align-items: center; justify-content: center;
        z-index: 9999; padding: 1.5rem;
    }
    .adm-orders .adm-modal.show { display: flex; animation: admFadeIn 0.2s ease; }
    @keyframes admFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .adm-orders .adm-modal__box {
        background: #fff; border-radius: var(--r-lg); width: 100%;
        max-width: 840px; max-height: 90vh; overflow-y: auto;
        padding: 1.75rem 2rem 2rem; position: relative;
        box-shadow: var(--c-shadow-lg);
        animation: admSlideUp 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes admSlideUp {
        from { opacity: 0; transform: translateY(20px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .adm-orders .adm-modal__close {
        position: absolute; top: 1rem; right: 1rem;
        width: 36px; height: 36px; border-radius: 50%; border: none;
        background: #f5f2ec; color: var(--c-text); font-size: 1.3rem;
        line-height: 1; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.15s ease;
    }
    .adm-orders .adm-modal__close:hover { background: #e8e0d0; transform: rotate(90deg); }
    .adm-orders .adm-modal__title {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 1.5rem; margin: 0 0 1.5rem; color: var(--c-text);
        padding-right: 3rem; letter-spacing: -0.01em;
    }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 720px) {
        .adm-orders .admin-page-head h1 { font-size: 1.6rem; }
        .adm-orders .adm-stats { grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
        .adm-orders .adm-stat-card { padding: 0.9rem; gap: 0.7rem; }
        .adm-orders .adm-stat-icon { width: 40px; height: 40px; font-size: 1.3rem; }
        .adm-orders .adm-stat-value { font-size: 1.25rem; }
        .adm-orders .adm-stat-label { font-size: 0.65rem; }
        .adm-orders .adm-filter-form { flex-direction: column; align-items: stretch; }
        .adm-orders .adm-filter-actions { justify-content: stretch; }
        .adm-orders .adm-filter-actions .btn-adm { flex: 1; }
        .adm-orders .adm-modal__box { padding: 1.5rem 1.25rem; }
        .adm-orders .adm-table { font-size: 0.82rem; }
        .adm-orders .adm-table thead th,
        .adm-orders .adm-table tbody td { padding: 0.7rem 0.65rem; }
    }
</style>

<div class="adm-orders">

    <!-- PAGE HEAD -->
    <div class="admin-page-head">
        <div>
            <h1>Đơn hàng</h1>
            <div class="subtitle">
                Hiển thị <b><?php echo count($orders); ?></b> đơn hàng
                <?php if ($filter !== '' || $search !== '' || $payFilter !== ''): ?>— đã lọc<?php endif; ?>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <div class="adm-stats">
        <a href="orders.php" class="adm-stat-card <?php echo ($filter === '' && $payFilter === '') ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#eef2ff;color:#4338ca;">
                <i class="ph ph-package"></i>
            </div>
            <div class="adm-stat-body">
                <div class="adm-stat-value"><?php echo number_format($totalAll); ?></div>
                <div class="adm-stat-label">Tất cả đơn</div>
            </div>
        </a>

        <a href="orders.php?status=pending" class="adm-stat-card <?php echo $pendingActive ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#fff7ed;color:#c2410c;">
                <i class="ph ph-hourglass"></i>
            </div>
            <div class="adm-stat-body">
                <div class="adm-stat-value"><?php echo number_format($totalPending); ?></div>
                <div class="adm-stat-label">Chờ xác nhận</div>
            </div>
        </a>

        <a href="orders.php?status=shipped" class="adm-stat-card <?php echo $filter === 'shipped' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#eff6ff;color:#1d4ed8;">
                <i class="ph ph-truck"></i>
            </div>
            <div class="adm-stat-body">
                <div class="adm-stat-value"><?php echo number_format($totalShipping); ?></div>
                <div class="adm-stat-label">Đang vận chuyển</div>
            </div>
        </a>

        <a href="orders.php?status=delivered" class="adm-stat-card <?php echo $filter === 'delivered' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#f0fdf4;color:#15803d;">
                <i class="ph ph-check-circle"></i>
            </div>
            <div class="adm-stat-body">
                <div class="adm-stat-value"><?php echo number_format($totalDelivered); ?></div>
                <div class="adm-stat-label">Đã giao</div>
            </div>
        </a>

        <a href="orders.php?status=cancelled" class="adm-stat-card <?php echo $filter === 'cancelled' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#fef2f2;color:#b91c1c;">
                <i class="ph ph-x-circle"></i>
            </div>
            <div class="adm-stat-body">
                <div class="adm-stat-value"><?php echo number_format($totalCancelled); ?></div>
                <div class="adm-stat-label">Đã hủy</div>
            </div>
        </a>
    </div>

    <!-- FILTER -->
    <div class="adm-filter-bar">
        <form method="GET" class="adm-filter-form">
            <div class="adm-filter-field adm-filter-field--search">
                <label class="adm-filter-label"><i class="ph ph-magnifying-glass"></i> Tìm kiếm</label>
                <input type="text" name="q" class="adm-input"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Mã đơn, tên KH, SĐT, email...">
            </div>
            <div class="adm-filter-field adm-filter-field--status">
                <label class="adm-filter-label">Trạng thái đơn</label>
                <select name="status" class="adm-select">
                    <option value="">Tất cả</option>
                    <?php foreach ($statusMap as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filter === $k ? 'selected' : ''; ?>>
                            <?php echo $v[0]; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="adm-filter-field adm-filter-field--pay">
                <label class="adm-filter-label">Thanh toán</label>
                <select name="pay" class="adm-select">
                    <option value="">Tất cả</option>
                    <option value="unpaid"         <?php echo $payFilter === 'unpaid'         ? 'selected' : ''; ?>>Chưa thanh toán</option>
                    <option value="pending"        <?php echo $payFilter === 'pending'        ? 'selected' : ''; ?>>Chờ thanh toán</option>
                    <option value="paid"           <?php echo $payFilter === 'paid'           ? 'selected' : ''; ?>>Đã thanh toán</option>
                    <option value="refund_pending" <?php echo $payFilter === 'refund_pending' ? 'selected' : ''; ?>>Chờ hoàn tiền</option>
                </select>
            </div>
            <div class="adm-filter-actions">
                <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
                <?php if ($search !== '' || $filter !== '' || $payFilter !== ''): ?>
                    <a href="orders.php" class="btn-adm btn-adm--ghost">Xóa lọc</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TABLE -->
    <div class="adm-table-card">
        <?php if (empty($orders)): ?>
            <div class="adm-empty">
                <div class="adm-empty-icon"><i class="ph ph-tray"></i></div>
                <div class="adm-empty-title">Không có đơn hàng nào</div>
                <div class="adm-empty-desc">Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm.</div>
            </div>
        <?php else: ?>
            <div class="adm-table-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th style="width:70px;">Mã</th>
                            <th>Khách hàng</th>
                            <th>Ngày đặt</th>
                            <th>Tổng tiền</th>
                            <th>Thanh toán</th>
                            <th>Trạng thái</th>
                            <th style="width:200px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $isCancelled = $o['status'] === 'cancelled';
                            $ps = $o['payment_status'] ?? 'unpaid';
                            $psInfo = $payStatusMap[$ps] ?? ['Không rõ', 'adm-badge--gray'];
                        ?>
                            <tr class="<?php echo $isCancelled ? 'adm-row--cancelled' : ''; ?>"
                                data-pay="<?php echo htmlspecialchars($ps); ?>">
                                <td><span class="adm-order-id">#<?php echo (int)$o['id']; ?></span></td>
                                <td>
                                    <div class="adm-customer-name"><?php echo htmlspecialchars($o['full_name']); ?></div>
                                    <div class="adm-customer-phone"><?php echo htmlspecialchars($o['phone']); ?></div>
                                </td>
                                <td class="adm-date">
                                    <?php echo date('d/m/Y', strtotime($o['created_at'])); ?><br>
                                    <span style="opacity:0.7;"><?php echo date('H:i', strtotime($o['created_at'])); ?></span>
                                </td>
                                <td class="adm-total">
                                    <?php echo number_format($o['total_amount'], 0, ',', '.'); ?>đ
                                </td>
                                <td style="font-size:0.85rem;">
                                    <div style="display:flex; align-items:center; gap:4px;">
                                        <?php echo $payMap[$o['payment_method']] ?? '—'; ?>
                                    </div>
                                    <div style="margin-top:5px;">
                                        <span class="adm-badge <?php echo $psInfo[1]; ?>"><?php echo $psInfo[0]; ?></span>
                                    </div>
                                    <?php if ($ps === 'paid' && !empty($o['paid_at'])): ?>
                                        <div style="font-size:0.72rem;color:var(--c-text-muted);margin-top:4px;font-family:'IBM Plex Mono',monospace;">
                                            <?php echo date('d/m H:i', strtotime($o['paid_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="adm-badge <?php echo $statusMap[$o['status']][1] ?? 'adm-badge--gray'; ?>">
                                        <?php echo $statusMap[$o['status']][0] ?? $o['status']; ?>
                                    </span>
                                    <?php if ($isCancelled && !empty($o['cancel_reason'])): ?>
                                        <div class="adm-cancel-note">
                                            <b>Lý do:</b> <?php echo htmlspecialchars($o['cancel_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="adm-actions" style="display: flex; gap: 4px;">
                                        <!-- Nút Xem nhanh (mở modal) -->
                                        <button type="button" class="btn-adm btn-adm--ghost"
                                                onclick="viewOrderQuick(<?php echo (int)$o['id']; ?>)"
                                                title="Xem nhanh thông tin">
                                            Xem nhanh
                                        </button>
                                        
                                        <!-- Nút Chi tiết (mở tab mới sang trang xử lý) -->
                                        <a href="order-detail.php?id=<?php echo (int)$o['id']; ?>" 
                                           target="_blank" 
                                           class="btn-adm btn-adm--primary"
                                           style="background:#2563eb; color:#fff;"
                                           title="Mở trang chi tiết & cập nhật trạng thái">
                                            Chi tiết
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ MODAL CHI TIẾT ĐƠN ============ -->
    <div class="adm-modal" id="orderDetailModal">
        <div class="adm-modal__box">
            <button type="button" class="adm-modal__close" onclick="closeOrderModal()">&times;</button>
            <h2 class="adm-modal__title" id="orderModalTitle">Chi tiết đơn hàng</h2>
            <div id="orderDetailContent">
                <p style="text-align:center;padding:2rem;color:var(--c-text-muted);">Đang tải...</p>
            </div>
        </div>
    </div>

</div>

<script>
/* ============================================================
   MODAL CHI TIẾT ĐƠN (XEM NHANH)
   ============================================================ */
var orderModal = document.getElementById('orderDetailModal');

function viewOrderQuick(orderId) {
    document.getElementById('orderModalTitle').textContent = 'Chi tiết đơn hàng #' + orderId;
    document.getElementById('orderDetailContent').innerHTML =
        '<p style="text-align:center;padding:2rem;color:#8a8377;">⏳ Đang tải chi tiết...</p>';
    orderModal.classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('order_detail_ajax.php?id=' + orderId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                document.getElementById('orderDetailContent').innerHTML =
                    '<p style="text-align:center;padding:2rem;color:#b91c1c;">' + (data.msg || 'Không tải được') + '</p>';
                return;
            }
            renderOrderDetail(data.order, data.items);
        })
        .catch(function () {
            document.getElementById('orderDetailContent').innerHTML =
                '<p style="text-align:center;padding:2rem;color:#b91c1c;">Lỗi kết nối.</p>';
        });
}

function renderOrderDetail(o, items) {
    var statusLabels = {
        'pending': 'Chờ xử lý', 'processing': 'Đang xử lý', 'confirmed': 'Đã xác nhận',
        'shipped': 'Đang giao', 'delivered': 'Đã giao', 'cancelled': 'Đã hủy'
    };
    
    // Sử dụng Phosphor Icons cho Modal
    var payLabels = { 
        'cod': '<i class="ph ph-money"></i> COD', 
        'bank': '<i class="ph ph-bank"></i> Chuyển khoản', 
        'card': '<i class="ph ph-credit-card"></i> Thẻ', 
        'wallet': '<i class="ph ph-wallet"></i> Atelier Pay' 
    };

    var html = '';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">';

    html += '<div style="background:#faf8f4;padding:1rem;border-radius:10px;border:1px solid #efeae0;">';
    html += '<div style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;margin-bottom:0.6rem;font-weight:700;"><i class="ph ph-user"></i> Người nhận</div>';
    html += '<div style="font-size:0.9rem;line-height:1.7;">';
    html += '<div><b>' + escapeHtml(o.full_name) + '</b></div>';
    html += '<div><i class="ph ph-phone"></i> ' + escapeHtml(o.phone) + '</div>';
    html += '<div><i class="ph ph-map-pin"></i> ' + escapeHtml(o.address) + '</div>';
    if (o.note) html += '<div style="font-style:italic;color:#8a8377;margin-top:0.35rem;"><i class="ph ph-note"></i> "' + escapeHtml(o.note) + '"</div>';
    html += '</div></div>';

    html += '<div style="background:#faf8f4;padding:1rem;border-radius:10px;border:1px solid #efeae0;">';
    html += '<div style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;margin-bottom:0.6rem;font-weight:700;"><i class="ph ph-clipboard-text"></i> Đơn hàng</div>';
    html += '<div style="font-size:0.9rem;line-height:1.7;">';
    html += '<div>Mã: <b>#' + o.id + '</b></div>';
    html += '<div>Ngày đặt: ' + o.created_at_fmt + '</div>';
    html += '<div>Phương thức: <b>' + (payLabels[o.payment_method] || o.payment_method) + '</b></div>';
    html += '<div>Trạng thái: <b>' + (statusLabels[o.status] || o.status) + '</b></div>';

    var payStatusColor = { 'unpaid':'#57534e','pending':'#c2410c','paid':'#15803d','refund_pending':'#b91c1c' };
    var payStatusBg = { 'unpaid':'#f5f5f4','pending':'#fff7ed','paid':'#f0fdf4','refund_pending':'#fef2f2' };
    var payStatusLabel = {
        'unpaid':'Chưa thanh toán','pending':'Chờ thanh toán',
        'paid':'Đã thanh toán','refund_pending':'Chờ hoàn tiền'
    };
    var ps = o.payment_status || 'unpaid';
    var psLabel = o.payment_status_label || payStatusLabel[ps] || ps;

    html += '<div style="margin-top:0.35rem;">Thanh toán: ';
    html += '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:0.78rem;font-weight:700;color:'
         + (payStatusColor[ps] || '#57534e') + ';background:' + (payStatusBg[ps] || '#f5f5f4') + ';">'
         + psLabel + '</span></div>';

    if (ps === 'paid' && o.paid_at_fmt) {
        html += '<div style="font-size:0.78rem;color:#8a8377;margin-top:0.2rem;"><i class="ph ph-check"></i> Lúc: ' + o.paid_at_fmt + '</div>';
    }
    if (o.payment_note) {
        html += '<div style="font-size:0.78rem;color:#8a8377;margin-top:0.2rem;font-style:italic;"><i class="ph ph-note"></i> ' + escapeHtml(o.payment_note) + '</div>';
    }
    html += '</div></div></div>';

    if (o.status === 'cancelled' && o.cancel_reason) {
        html += '<div style="background:#fef2f2;border-left:4px solid #b91c1c;padding:0.85rem 1rem;margin-bottom:1.5rem;border-radius:6px;">';
        html += '<div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.1em;color:#991b1b;margin-bottom:0.35rem;font-weight:700;"><i class="ph ph-x-circle"></i> Lý do hủy</div>';
        html += '<div style="color:#7f1d1d;font-weight:600;">' + escapeHtml(o.cancel_reason) + '</div>';
        if (o.cancelled_at_fmt) {
            html += '<div style="font-size:0.78rem;color:#991b1b;margin-top:0.35rem;">Hủy lúc: ' + o.cancelled_at_fmt + '</div>';
        }
        html += '</div>';
    }

    html += '<div style="font-size:0.72rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;margin-bottom:0.6rem;font-weight:700;"><i class="ph ph-shopping-bag"></i> Sản phẩm (' + items.length + ')</div>';
    html += '<div style="border:1px solid #efeae0;border-radius:10px;overflow:hidden;">';
    html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;">';
    html += '<thead><tr style="background:#faf8f4;">';
    html += '<th style="text-align:left;padding:0.7rem 0.9rem;font-size:0.7rem;text-transform:uppercase;color:#8a8377;width:60px;"></th>';
    html += '<th style="text-align:left;padding:0.7rem 0.9rem;font-size:0.7rem;text-transform:uppercase;color:#8a8377;">Sản phẩm</th>';
    html += '<th style="text-align:left;padding:0.7rem 0.9rem;font-size:0.7rem;text-transform:uppercase;color:#8a8377;width:70px;">Size</th>';
    html += '<th style="text-align:left;padding:0.7rem 0.9rem;font-size:0.7rem;text-transform:uppercase;color:#8a8377;width:60px;">SL</th>';
    html += '<th style="text-align:right;padding:0.7rem 0.9rem;font-size:0.7rem;text-transform:uppercase;color:#8a8377;width:120px;">Thành tiền</th>';
    html += '</tr></thead><tbody>';

    items.forEach(function (it) {
        html += '<tr style="border-top:1px solid #efeae0;">';
        html += '<td style="padding:0.7rem 0.9rem;"><img src="../' + escapeHtml(it.image_url) + '" style="width:44px;height:44px;object-fit:cover;border-radius:6px;" onerror="this.style.display=\'none\'"></td>';
        html += '<td style="padding:0.7rem 0.9rem;"><b>' + escapeHtml(it.name) + '</b></td>';
        html += '<td style="padding:0.7rem 0.9rem;">' + escapeHtml(it.size || 'M') + '</td>';
        html += '<td style="padding:0.7rem 0.9rem;">x' + it.quantity + '</td>';
        html += '<td style="padding:0.7rem 0.9rem;text-align:right;font-family:\'IBM Plex Mono\',monospace;">' + formatVnd(it.price * it.quantity) + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';

    html += '<div style="margin-top:1.5rem;padding-top:1rem;border-top:2px solid #efeae0;">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;font-size:0.9rem;">';
    html += '<span>Tạm tính</span><b>' + formatVnd(o.subtotal_products || o.total_amount) + '</b></div>';
    if (parseFloat(o.discount_amount) > 0) {
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;font-size:0.9rem;color:#166534;">';
        html += '<span>Giảm giá (' + escapeHtml(o.voucher_code || '') + ')</span>';
        html += '<b>−' + formatVnd(o.discount_amount) + '</b></div>';
    }
    html += '<div style="display:flex;justify-content:space-between;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #efeae0;font-size:1.1rem;">';
    html += '<b>TỔNG THANH TOÁN</b>';
    html += '<b style="color:#a8461f;font-family:\'IBM Plex Mono\',monospace;">' + formatVnd(o.total_amount) + '</b></div></div>';

    document.getElementById('orderDetailContent').innerHTML = html;
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}
function formatVnd(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ'; }

function closeOrderModal() {
    orderModal.classList.remove('show');
    document.body.style.overflow = '';
}

orderModal.addEventListener('click', function (e) { if (e.target === this) closeOrderModal(); });

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (orderModal.classList.contains('show')) closeOrderModal();
});
</script>

<?php include 'includes/admin_footer.php'; ?>