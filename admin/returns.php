<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============ LẤY DỮ LIỆU ============ */
$filter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

$validStatuses = ['pending','approved','refunding','refunded','rejected','cancelled'];
if (in_array($filter, $validStatuses, true)) {
    $where[]  = 'r.status = ?';
    $params[] = $filter;
}

if ($search !== '') {
    $where[]  = "(r.return_code LIKE ? OR r.id = ? OR r.order_id = ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = is_numeric($search) ? (int)$search : 0;
    $params[] = is_numeric($search) ? (int)$search : 0;
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT r.*,
           u.full_name AS user_name,
           u.email     AS user_email,
           u.phone     AS user_phone,
           o.total_amount AS order_total,
           o.payment_method,
           o.payment_status,
           lb.bank_name AS refund_bank_name,
           lb.account_no AS refund_bank_account
    FROM return_requests r
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN orders o ON o.id = r.order_id
    LEFT JOIN linked_banks lb ON lb.id = r.refund_bank_id
    $whereSQL
    ORDER BY 
        CASE r.status 
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'refunding' THEN 3
            WHEN 'refunded' THEN 4
            WHEN 'rejected' THEN 5
            ELSE 6
        END,
        r.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============ ĐẾM THEO TRẠNG THÁI ============ */
$countStmt = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM return_requests
    GROUP BY status
");
$counts = [
    'pending'   => 0,
    'approved'  => 0,
    'refunding' => 0,
    'refunded'  => 0,
    'rejected'  => 0,
    'cancelled' => 0,
];
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['total'];
    }
}
$totalAll       = array_sum($counts);
$totalPending   = $counts['pending'];
$totalApproved  = $counts['approved'] + $counts['refunding']; // Đang giao hàng / Đang xử lý
$totalRefunded  = $counts['refunded'];
$totalRejected  = $counts['rejected'];

/* ============ ADMIN PIN STATUS ============ */
$adminId = (int)$_SESSION['user_id'];
$adminStmt = $pdo->prepare("SELECT payment_pin FROM users WHERE id = ?");
$adminStmt->execute([$adminId]);
$adminPinHash = $adminStmt->fetchColumn();
$adminHasPin  = !empty($adminPinHash);

/* ============ STATUS MAP ============ */
$returnStatusMap = [
    'pending'   => ['Chờ duyệt',       'adm-badge--orange'],
    'approved'  => ['Đang giao hàng',  'adm-badge--blue'], // Đã duyệt, chờ hàng về
    'refunding' => ['Đang xử lý',      'adm-badge--blue'], 
    'refunded'  => ['Đã hoàn tiền',    'adm-badge--green'],
    'rejected'  => ['Đã từ chối',      'adm-badge--red'],
    'cancelled' => ['Đã hủy',          'adm-badge--gray'],
];

$refundMethodMap = [
    'wallet' => '<i class="ph ph-wallet"></i> Ví Atelier Pay',
    'bank'   => '<i class="ph ph-bank"></i> Chuyển khoản',
];
?>

<!-- CSS (Giữ nguyên) -->
<style>
    .adm-returns {
        --c-primary:       #a8461f;
        --c-primary-soft:  #fdf3ee;
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
    .adm-returns .admin-page-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 1.5rem; padding-bottom: 1.1rem; border-bottom: 1px solid var(--c-border); gap: 1rem; flex-wrap: wrap; }
    .adm-returns .admin-page-head h1 { font-family: 'Playfair Display', Georgia, serif; font-size: 2rem; margin: 0; color: var(--c-text); letter-spacing: -0.015em; line-height: 1.1; }
    .adm-returns .admin-page-head .subtitle { font-size: 0.85rem; color: var(--c-text-muted); margin-top: 0.4rem; }
    .adm-returns .admin-page-head .subtitle b { color: var(--c-primary); font-family: 'IBM Plex Mono', monospace; }
    .adm-returns .adm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.9rem; margin-bottom: 1.5rem; }
    .adm-returns .adm-stat-card { position: relative; display: flex; align-items: center; gap: 0.9rem; padding: 1.1rem 1.2rem; background: var(--c-card); border: 1px solid var(--c-border); border-radius: var(--r-md); text-decoration: none; color: inherit; overflow: hidden; transition: all 0.22s; box-shadow: var(--c-shadow-sm); }
    .adm-returns .adm-stat-card:hover { transform: translateY(-3px); box-shadow: var(--c-shadow-md); border-color: var(--c-border-strong); }
    .adm-returns .adm-stat-card--active { border-color: var(--c-primary); background: linear-gradient(135deg, #fffaf5 0%, #ffffff 70%); box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.10), var(--c-shadow-md); }
    .adm-returns .adm-stat-icon { width: 48px; height: 48px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .adm-returns .adm-stat-body { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
    .adm-returns .adm-stat-value { font-size: 1.65rem; font-weight: 700; font-family: 'IBM Plex Mono', monospace; color: var(--c-text); letter-spacing: -0.02em; }
    .adm-returns .adm-stat-label { font-size: 0.72rem; color: var(--c-text-muted); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .adm-returns .adm-filter-bar { background: var(--c-card); border: 1px solid var(--c-border); border-radius: var(--r-md); padding: 1.1rem 1.25rem; margin-bottom: 1.25rem; box-shadow: var(--c-shadow-sm); }
    .adm-returns .adm-filter-form { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; }
    .adm-returns .adm-filter-field { display: flex; flex-direction: column; min-width: 0; }
    .adm-returns .adm-filter-field--search { flex: 2 1 260px; }
    .adm-returns .adm-filter-field--status { flex: 1 1 190px; }
    .adm-returns .adm-filter-label { display: block; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--c-text-muted); margin-bottom: 0.45rem; }
    .adm-returns .adm-input, .adm-returns .adm-select { width: 100%; padding: 0.65rem 0.85rem; font-size: 0.88rem; font-family: inherit; color: var(--c-text); background: #fbf9f5; border: 1px solid var(--c-border); border-radius: var(--r-sm); outline: none; transition: all 0.15s ease; }
    .adm-returns .adm-input:focus, .adm-returns .adm-select:focus { border-color: var(--c-primary); background: #ffffff; box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.10); }
    .adm-returns .adm-filter-actions { display: flex; gap: 0.5rem; align-items: center; }
    .adm-returns .btn-adm { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; padding: 0.6rem 1.1rem; font-size: 0.82rem; font-weight: 600; font-family: inherit; border-radius: var(--r-sm); border: 1px solid transparent; cursor: pointer; text-decoration: none; white-space: nowrap; transition: all 0.15s ease; line-height: 1; }
    .adm-returns .btn-adm--primary { background: var(--c-primary); color: #fff; box-shadow: 0 2px 6px rgba(168, 70, 31, 0.25); }
    .adm-returns .btn-adm--primary:hover { background: #8f3a18; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(168, 70, 31, 0.32); }
    .adm-returns .btn-adm--ghost { background: #fff; color: var(--c-text); border-color: var(--c-border-strong); }
    .adm-returns .btn-adm--ghost:hover { background: #fbf9f5; border-color: #c8bfa9; }
    .adm-returns .btn-adm--danger { background: #fff; color: #b91c1c; border-color: #fecaca; }
    .adm-returns .btn-adm--danger:hover { background: #fef2f2; border-color: #b91c1c; }
    .adm-returns .btn-adm--success { background: #166534; color: #fff; }
    .adm-returns .btn-adm--success:hover { background: #14532d; transform: translateY(-1px); }
    .adm-returns .adm-table-card { background: var(--c-card); border: 1px solid var(--c-border); border-radius: var(--r-md); overflow: hidden; box-shadow: var(--c-shadow-sm); }
    .adm-returns .adm-table-scroll { overflow-x: auto; }
    .adm-returns .adm-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .adm-returns .adm-table thead th { text-align: left; padding: 0.85rem 1rem; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--c-text-muted); background: #fbf9f5; border-bottom: 1px solid var(--c-border); white-space: nowrap; }
    .adm-returns .adm-table tbody td { padding: 0.95rem 1rem; border-bottom: 1px solid var(--c-border); vertical-align: middle; }
    .adm-returns .adm-table tbody tr { transition: background 0.15s ease; }
    .adm-returns .adm-table tbody tr:hover { background: #fbf9f5; }
    .adm-returns .adm-table tbody tr:last-child td { border-bottom: none; }
    .adm-returns .adm-code { font-family: 'IBM Plex Mono', monospace; font-weight: 700; color: var(--c-primary); font-size: 0.85rem; white-space: nowrap; }
    .adm-returns .adm-sub { font-size: 0.75rem; color: var(--c-text-muted); margin-top: 2px; font-family: 'IBM Plex Mono', monospace; }
    .adm-returns .adm-customer-name { font-weight: 600; color: var(--c-text); }
    .adm-returns .adm-customer-email { font-size: 0.75rem; color: var(--c-text-muted); margin-top: 2px; }
    .adm-returns .adm-amount { font-family: 'IBM Plex Mono', monospace; font-weight: 700; color: var(--c-primary); white-space: nowrap; }
    .adm-returns .adm-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.65rem; font-size: 0.72rem; font-weight: 600; border-radius: 999px; line-height: 1.2; white-space: nowrap; border: 1px solid transparent; }
    .adm-returns .adm-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.85; }
    .adm-returns .adm-badge--orange { color: #c2410c; background: #fff7ed; border-color: #fed7aa; }
    .adm-returns .adm-badge--blue   { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
    .adm-returns .adm-badge--green  { color: #15803d; background: #f0fdf4; border-color: #bbf7d0; }
    .adm-returns .adm-badge--red    { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
    .adm-returns .adm-badge--gray   { color: #57534e; background: #f5f5f4; border-color: #e7e5e4; }
    .adm-returns .adm-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }
    .adm-returns .adm-empty { text-align: center; padding: 4rem 1.5rem; color: var(--c-text-muted); }
    .adm-returns .adm-empty-icon { font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.5; }
    .adm-returns .adm-empty-title { font-size: 1rem; font-weight: 600; color: var(--c-text); margin-bottom: 0.35rem; }
    .adm-returns .adm-modal { position: fixed; inset: 0; background: rgba(36, 33, 29, 0.55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 1.5rem; }
    .adm-returns .adm-modal.show { display: flex; animation: admFadeIn 0.2s ease; }
    @keyframes admFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .adm-returns .adm-modal__box { background: #fff; border-radius: var(--r-lg); width: 100%; max-width: 860px; max-height: 92vh; overflow-y: auto; padding: 1.75rem 2rem 2rem; position: relative; box-shadow: var(--c-shadow-lg); animation: admSlideUp 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
    @keyframes admSlideUp { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .adm-returns .adm-modal__close { position: absolute; top: 1rem; right: 1rem; width: 36px; height: 36px; border-radius: 50%; border: none; background: #f5f2ec; color: var(--c-text); font-size: 1.3rem; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s ease; }
    .adm-returns .adm-modal__close:hover { background: #e8e0d0; transform: rotate(90deg); }
    .adm-returns .adm-modal__title { font-family: 'Playfair Display', Georgia, serif; font-size: 1.5rem; margin: 0 0 1.5rem; color: var(--c-text); padding-right: 3rem; letter-spacing: -0.01em; }
    .adm-pin-box { background: #faf8f4; border: 1px solid #efeae0; border-radius: 12px; padding: 1.25rem; }
    .adm-pin-box.error { border-color: #b91c1c; background: #fef2f2; }
    .adm-pin-input { width: 100%; max-width: 240px; padding: 14px 18px; border: 2px solid #d1d5db; border-radius: 10px; font-size: 1.6rem; letter-spacing: 0.55em; text-align: center; font-family: 'IBM Plex Mono', monospace; background: #fff; box-sizing: border-box; transition: border-color 0.2s; }
    .adm-pin-input:focus { outline: none; border-color: #a8461f; box-shadow: 0 0 0 3px rgba(168,70,31,0.12); }
    .adm-pin-input.error { border-color: #dc2626; animation: shakePin 0.35s; }
    @keyframes shakePin { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-6px); } 75% { transform: translateX(6px); } }
    .adm-return-toast { position: fixed; top: 1.5rem; left: 50%; transform: translateX(-50%) translateY(-20px); background: #166534; color: #fff; padding: 0.95rem 1.75rem; font-size: 0.88rem; letter-spacing: 0.03em; box-shadow: 0 10px 25px rgba(0,0,0,0.18); z-index: 99999; opacity: 0; transition: all 0.3s ease; max-width: 90vw; border-radius: 8px; font-weight: 600; }
    .adm-return-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .adm-return-toast.error { background: #b91c1c; }
</style>

<div class="adm-returns">
    <div class="admin-page-head">
        <div>
            <h1>Đơn hoàn</h1>
            <div class="subtitle">
                Hiển thị <b><?php echo count($returns); ?></b> yêu cầu trả hàng
                <?php if ($filter !== '' || $search !== ''): ?>— đã lọc<?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$adminHasPin): ?>
        <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:14px 18px;border-radius:10px;margin-bottom:1.5rem;font-size:0.9rem; display:flex; align-items:center; gap:8px;">
            <i class="ph ph-warning" style="font-size:1.2rem;"></i>
            <div><b>Tài khoản admin chưa thiết lập mã PIN.</b> Bạn cần thiết lập mã PIN (dùng chung với ví Atelier Pay) trước khi có thể duyệt đơn hoàn. <a href="wallet.php" style="color:#a8461f;font-weight:700;text-decoration:none;">Thiết lập ngay &rarr;</a></div>
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="adm-stats">
        <a href="returns.php" class="adm-stat-card <?php echo ($filter === '') ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#eef2ff;color:#4338ca;"><i class="ph ph-arrows-counter-clockwise"></i></div>
            <div class="adm-stat-body"><div class="adm-stat-value"><?php echo number_format($totalAll); ?></div><div class="adm-stat-label">Tất cả yêu cầu</div></div>
        </a>
        <a href="returns.php?status=pending" class="adm-stat-card <?php echo $filter === 'pending' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#fff7ed;color:#c2410c;"><i class="ph ph-hourglass"></i></div>
            <div class="adm-stat-body"><div class="adm-stat-value"><?php echo number_format($totalPending); ?></div><div class="adm-stat-label">Chờ duyệt</div></div>
        </a>
        <a href="returns.php?status=approved" class="adm-stat-card <?php echo in_array($filter, ['approved','refunding'], true) ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#eff6ff;color:#1d4ed8;"><i class="ph ph-truck"></i></div>
            <div class="adm-stat-body"><div class="adm-stat-value"><?php echo number_format($totalApproved); ?></div><div class="adm-stat-label">Đang giao hàng</div></div>
        </a>
        <a href="returns.php?status=refunded" class="adm-stat-card <?php echo $filter === 'refunded' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#f0fdf4;color:#15803d;"><i class="ph ph-check-circle"></i></div>
            <div class="adm-stat-body"><div class="adm-stat-value"><?php echo number_format($totalRefunded); ?></div><div class="adm-stat-label">Đã hoàn tiền</div></div>
        </a>
        <a href="returns.php?status=rejected" class="adm-stat-card <?php echo $filter === 'rejected' ? 'adm-stat-card--active' : ''; ?>">
            <div class="adm-stat-icon" style="background:#fef2f2;color:#b91c1c;"><i class="ph ph-x-circle"></i></div>
            <div class="adm-stat-body"><div class="adm-stat-value"><?php echo number_format($totalRejected); ?></div><div class="adm-stat-label">Đã từ chối</div></div>
        </a>
    </div>

    <!-- FILTER -->
    <div class="adm-filter-bar">
        <form method="GET" class="adm-filter-form">
            <div class="adm-filter-field adm-filter-field--search">
                <label class="adm-filter-label"><i class="ph ph-magnifying-glass"></i> Tìm kiếm</label>
                <input type="text" name="q" class="adm-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Mã đơn hoàn, mã đơn gốc, tên KH, email...">
            </div>
            <div class="adm-filter-field adm-filter-field--status">
                <label class="adm-filter-label">Trạng thái</label>
                <select name="status" class="adm-select">
                    <option value="">Tất cả</option>
                    <?php foreach ($returnStatusMap as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filter === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="adm-filter-actions">
                <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
                <?php if ($search !== '' || $filter !== ''): ?><a href="returns.php" class="btn-adm btn-adm--ghost">Xóa lọc</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TABLE -->
    <div class="adm-table-card">
        <?php if (empty($returns)): ?>
            <div class="adm-empty">
                <div class="adm-empty-icon"><i class="ph ph-tray"></i></div>
                <div class="adm-empty-title">Không có yêu cầu trả hàng nào</div>
            </div>
        <?php else: ?>
            <div class="adm-table-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th style="width:140px;">Mã đơn hoàn</th>
                            <th>Khách hàng</th>
                            <th style="width:80px;">Đơn gốc</th>
                            <th style="width:130px;">Số tiền hoàn</th>
                            <th style="width:150px;">Phương thức</th>
                            <th style="width:130px;">Trạng thái</th>
                            <th style="width:180px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $r):
                            $code = !empty($r['return_code']) ? $r['return_code'] : ('RT' . date('Ymd', strtotime($r['created_at'])) . '-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT));
                            $stInfo = $returnStatusMap[$r['status']] ?? ['Không rõ', 'adm-badge--gray'];
                            $canAct = in_array($r['status'], ['pending', 'approved', 'refunding'], true);
                        ?>
                            <tr>
                                <td>
                                    <div class="adm-code"><?php echo htmlspecialchars($code); ?></div>
                                    <div class="adm-sub"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="adm-customer-name"><?php echo htmlspecialchars($r['user_name'] ?? 'N/A'); ?></div>
                                    <div class="adm-customer-email"><?php echo htmlspecialchars($r['user_email'] ?? ''); ?></div>
                                </td>
                                <td><span style="font-family:'IBM Plex Mono',monospace;font-weight:600;">#<?php echo (int)$r['order_id']; ?></span></td>
                                <td class="adm-amount"><?php echo number_format($r['refund_amount'], 0, ',', '.'); ?>đ</td>
                                <td style="font-size:0.82rem;">
                                    <div style="display:flex; align-items:center; gap:4px;"><?php echo $refundMethodMap[$r['refund_method']] ?? $r['refund_method']; ?></div>
                                    <?php if ($r['refund_method'] === 'bank' && !empty($r['refund_bank_name'])): ?>
                                        <div class="adm-sub"><?php echo htmlspecialchars($r['refund_bank_name']); ?> ••••<?php echo substr($r['refund_bank_account'] ?? '', -4); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="adm-badge <?php echo $stInfo[1]; ?>"><?php echo $stInfo[0]; ?></span></td>
                                <td>
                                    <div class="adm-actions">
                                        <button type="button" class="btn-adm btn-adm--ghost btn-view-return" data-id="<?php echo (int)$r['id']; ?>">
                                            <i class="ph ph-eye"></i> Xem
                                        </button>
                                        <?php if ($canAct): ?>
                                            <button type="button" class="btn-adm btn-adm--primary btn-view-return" data-id="<?php echo (int)$r['id']; ?>" data-auto-action="<?php echo $r['status'] === 'pending' ? 'approve' : 'mark_refunded'; ?>">
                                                <?php if ($r['status'] === 'pending'): ?>
                                                    <i class="ph ph-check"></i> Duyệt
                                                <?php else: ?>
                                                    <i class="ph ph-check-circle"></i> Nhận hàng & HT
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ MODAL CHI TIẾT ============ -->
<div class="adm-returns">
    <div class="adm-modal" id="returnDetailModal">
        <div class="adm-modal__box">
            <button type="button" class="adm-modal__close" id="closeReturnModal">&times;</button>
            <h2 class="adm-modal__title" id="returnModalTitle">Chi tiết yêu cầu trả hàng</h2>
            <div id="returnDetailContent"><p style="text-align:center;padding:2rem;color:var(--c-text-muted);">Đang tải...</p></div>
        </div>
    </div>
</div>

<div id="admReturnToast" class="adm-return-toast" hidden></div>

<script>
(function () {
    var modal     = document.getElementById('returnDetailModal');
    var content   = document.getElementById('returnDetailContent');
    var titleEl   = document.getElementById('returnModalTitle');
    var toastEl   = document.getElementById('admReturnToast');
    var currentId = 0;
    var CSRF      = <?php echo json_encode($_SESSION['csrf_token']); ?>;
    var HAS_PIN   = <?php echo $adminHasPin ? 'true' : 'false'; ?>;

    function showToast(msg, isError) {
        toastEl.textContent = msg;
        toastEl.className = 'adm-return-toast show' + (isError ? ' error' : '');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { toastEl.className = 'adm-return-toast'; }, 3500);
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function formatVnd(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ'; }

    function openModal() { modal.classList.add('show'); document.body.style.overflow = 'hidden'; }
    function closeModal() { modal.classList.remove('show'); document.body.style.overflow = ''; }

    document.getElementById('closeReturnModal').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    /* ============ LOAD DETAIL ============ */
    function loadDetail(id, autoAction) {
        currentId = id;
        titleEl.textContent = 'Chi tiết yêu cầu trả hàng';
        content.innerHTML = '<p style="text-align:center;padding:2rem;color:#8a8377;"><i class="ph ph-spinner-gap" style="font-size:1.5rem;"></i> Đang tải...</p>';
        openModal();

        fetch('return_detail_ajax.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    content.innerHTML = '<p style="text-align:center;padding:2rem;color:#b91c1c;">' + escapeHtml(data.msg || 'Không tải được') + '</p>';
                    return;
                }
                renderDetail(data);

                if (autoAction) {
                    setTimeout(function () {
                        var btn = document.getElementById(autoAction === 'approve' ? 'btnActionApprove' : 'btnActionMarkRefunded');
                        if (btn) btn.click();
                    }, 300);
                }
            })
            .catch(function () {
                content.innerHTML = '<p style="text-align:center;padding:2rem;color:#b91c1c;">Lỗi kết nối.</p>';
            });
    }

    /* ============ RENDER DETAIL ============ */
    function renderDetail(d) {
        var r = d.return;
        var items = d.items || [];
        var images = d.images || [];

        var statusLabels = {
            'pending':   '<i class="ph ph-hourglass"></i> Chờ duyệt',
            'approved':  '<i class="ph ph-truck"></i> Đang giao hàng',
            'refunding': '<i class="ph ph-arrows-clockwise"></i> Đang xử lý',
            'refunded':  '<i class="ph ph-check-circle"></i> Đã hoàn tiền',
            'rejected':  '<i class="ph ph-x-circle"></i> Đã từ chối',
            'cancelled': '<i class="ph ph-minus-circle"></i> Đã hủy'
        };
        var statusColors = {
            'pending':   '#c2410c',
            'approved':  '#1d4ed8',
            'refunding': '#1d4ed8',
            'refunded':  '#15803d',
            'rejected':  '#b91c1c',
            'cancelled': '#57534e'
        };

        var html = '';

        /* HEADER BOX */
        html += '<div style="background:#faf8f4;border:1px solid #efeae0;border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;">';
        html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">';
        html += '<div>';
        html += '<div style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;font-weight:700;margin-bottom:4px;">Mã đơn hoàn</div>';
        html += '<div style="font-family:\'IBM Plex Mono\',monospace;font-size:1.35rem;font-weight:700;color:#a8461f;">' + escapeHtml(r.return_code) + '</div>';
        html += '<div style="font-size:0.8rem;color:#6b7280;margin-top:6px;">Đơn gốc <b>#' + r.order_id + '</b> · ' + escapeHtml(r.created_at_fmt) + '</div>';
        html += '</div>';
        html += '<div style="text-align:right;">';
        html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-size:0.78rem;font-weight:700;color:#fff;background:' + (statusColors[r.status] || '#57534e') + ';">' + (statusLabels[r.status] || r.status) + '</div>';
        html += '<div style="font-size:1.35rem;font-weight:700;color:#a8461f;font-family:\'IBM Plex Mono\',monospace;margin-top:8px;">' + formatVnd(r.refund_amount) + '</div>';
        html += '<div style="font-size:0.78rem;color:#6b7280;">' + (r.refund_method_label || '') + '</div>';
        html += '</div></div></div>';

        /* GRID INFO */
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">';
        /* KHÁCH HÀNG */
        html += '<div style="background:#fff;padding:1rem;border-radius:10px;border:1px solid #efeae0;">';
        html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;margin-bottom:0.6rem;font-weight:700;"><i class="ph ph-user"></i> Khách hàng</div>';
        html += '<div style="font-size:0.9rem;line-height:1.7;">';
        html += '<div><b>' + escapeHtml(r.user_name) + '</b></div>';
        if (r.user_email) html += '<div><i class="ph ph-envelope-simple" style="color:#8a8377;margin-right:4px;"></i>' + escapeHtml(r.user_email) + '</div>';
        if (r.user_phone) html += '<div><i class="ph ph-phone" style="color:#8a8377;margin-right:4px;"></i>' + escapeHtml(r.user_phone) + '</div>';
        html += '</div></div>';
        /* HOÀN TIỀN */
        html += '<div style="background:#fff;padding:1rem;border-radius:10px;border:1px solid #efeae0;">';
        html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;margin-bottom:0.6rem;font-weight:700;"><i class="ph ph-money"></i> Hoàn tiền</div>';
        html += '<div style="font-size:0.9rem;line-height:1.7;">';
        html += '<div>Phương thức: <b>' + escapeHtml(r.refund_method_label) + '</b></div>';
        if (r.refund_method === 'bank' && r.refund_bank_name) {
            html += '<div style="background:#faf8f4;padding:8px 10px;border-radius:6px;margin-top:6px;font-size:0.82rem;">';
            html += '<div><b>' + escapeHtml(r.refund_bank_name) + '</b></div>';
            html += '<div style="font-family:\'IBM Plex Mono\',monospace;">' + escapeHtml(r.refund_bank_account) + '</div>';
            html += '<div>' + escapeHtml(r.refund_bank_holder || '') + '</div>';
            html += '</div>';
        }
        html += '</div></div></div>';

        /* LÝ DO */
        html += '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.5rem;">';
        html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#92400e;font-weight:700;margin-bottom:6px;"><i class="ph ph-note-pencil"></i> Lý do trả hàng</div>';
        html += '<div style="color:#78350f;font-weight:600;font-size:0.9rem;">' + escapeHtml(r.reason) + '</div>';
        if (r.reason_detail) html += '<div style="color:#78350f;font-size:0.85rem;margin-top:6px;font-style:italic;">"' + escapeHtml(r.reason_detail) + '"</div>';
        html += '</div>';

        /* ẢNH */
        if (images.length > 0) {
            html += '<div style="margin-bottom:1.5rem;">';
            html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;font-weight:700;margin-bottom:8px;"><i class="ph ph-image"></i> Ảnh minh chứng (' + images.length + ')</div>';
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            images.forEach(function (url) {
                html += '<a href="../' + escapeHtml(url) + '" target="_blank" style="display:block;"><img src="../' + escapeHtml(url) + '" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;"></a>';
            });
            html += '</div></div>';
        }

        /* SẢN PHẨM */
        html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;font-weight:700;margin-bottom:8px;"><i class="ph ph-shopping-bag"></i> Sản phẩm trả (' + items.length + ')</div>';
        html += '<div style="border:1px solid #efeae0;border-radius:10px;overflow:hidden;margin-bottom:1.5rem;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;">';
        html += '<thead><tr style="background:#faf8f4;">';
        html += '<th style="text-align:left;padding:0.7rem 0.9rem;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#8a8377;">Sản phẩm</th>';
        html += '<th style="text-align:center;padding:0.7rem 0.9rem;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#8a8377;width:70px;">Size</th>';
        html += '<th style="text-align:center;padding:0.7rem 0.9rem;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#8a8377;width:60px;">SL</th>';
        html += '<th style="text-align:right;padding:0.7rem 0.9rem;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#8a8377;width:120px;">Thành tiền</th>';
        html += '</tr></thead><tbody>';
        items.forEach(function (it) {
            html += '<tr style="border-top:1px solid #efeae0;">';
            html += '<td style="padding:0.7rem 0.9rem;"><b>' + escapeHtml(it.product_name) + '</b></td>';
            html += '<td style="padding:0.7rem 0.9rem;text-align:center;">' + escapeHtml(it.size || '—') + '</td>';
            html += '<td style="padding:0.7rem 0.9rem;text-align:center;">x' + it.quantity + '</td>';
            html += '<td style="padding:0.7rem 0.9rem;text-align:right;font-family:\'IBM Plex Mono\',monospace;">' + formatVnd(it.price * it.quantity) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';

        /* ACTIONS */
        if (r.status === 'pending') {
            html += '<div id="adminActionSection" style="border-top:2px solid #efeae0;padding-top:1.5rem;margin-top:1rem;">';
            html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;font-weight:700;margin-bottom:12px;"><i class="ph ph-lightning"></i> Thao tác</div>';
            html += '<div id="actionButtons" style="display:flex;gap:10px;flex-wrap:wrap;">';
            html += '<button type="button" class="btn-adm btn-adm--success" id="btnActionApprove" style="padding:0.75rem 1.5rem;font-size:0.88rem;"><i class="ph ph-check"></i> Duyệt yêu cầu trả hàng</button>';
            html += '<button type="button" class="btn-adm btn-adm--danger" id="btnActionReject" style="padding:0.75rem 1.5rem;font-size:0.88rem;"><i class="ph ph-x"></i> Từ chối</button>';
            html += '</div>';
            html += '<div id="actionConfirmBox" style="display:none;"></div>';
            html += '</div>';
        } else if (r.status === 'approved' || r.status === 'refunding') {
            html += '<div id="adminActionSection" style="border-top:2px solid #efeae0;padding-top:1.5rem;margin-top:1rem;">';
            html += '<div style="display:flex;align-items:center;gap:6px;font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:#8a8377;font-weight:700;margin-bottom:12px;"><i class="ph ph-lightning"></i> Thao tác</div>';
            html += '<div id="actionButtons" style="display:flex;gap:10px;flex-wrap:wrap;">';
            html += '<button type="button" class="btn-adm btn-adm--success" id="btnActionMarkRefunded" style="padding:0.75rem 1.5rem;font-size:0.88rem;"><i class="ph ph-check-circle"></i> Đã nhận được hàng & Hoàn tiền</button>';
            html += '</div>';
            html += '<div id="actionConfirmBox" style="display:none;"></div>';
            html += '</div>';
        }

        content.innerHTML = html;

        /* BIND ACTIONS */
        var btnApprove = document.getElementById('btnActionApprove');
        var btnReject = document.getElementById('btnActionReject');
        var btnMarkRefunded = document.getElementById('btnActionMarkRefunded');

        if (btnApprove) btnApprove.addEventListener('click', function () { showPinConfirm('approve'); });
        if (btnReject) btnReject.addEventListener('click', function () { showPinConfirm('reject'); });
        if (btnMarkRefunded) btnMarkRefunded.addEventListener('click', function () { showPinConfirm('mark_refunded'); });
    }

    /* ============ SHOW PIN CONFIRM ============ */
    function showPinConfirm(action) {
        if (!HAS_PIN) {
            showToast('Bạn chưa thiết lập mã PIN. Vui lòng thiết lập trong ví Atelier Pay.', true);
            return;
        }

        var actionLabels = {
            'approve':        { title: '<i class="ph ph-check"></i> Xác nhận duyệt đơn hoàn', color: '#166534', note: 'Lúc này tiền CHƯA được hoàn. Đơn sẽ chuyển sang trạng thái "Đang giao hàng". Khi hàng hoàn về tới shop, bạn mới bấm hoàn tiền cho khách.' },
            'reject':         { title: '<i class="ph ph-x-circle"></i> Xác nhận từ chối', color: '#b91c1c', note: 'Khách sẽ nhận được thông báo từ chối qua email.' },
            'mark_refunded':  { title: '<i class="ph ph-check-circle"></i> Xác nhận đã nhận hàng & Hoàn tiền', color: '#166534', note: 'Bạn xác nhận đã nhận được gói hàng hoàn trả. Hệ thống sẽ TIẾN HÀNH HOÀN TIỀN ngay bây giờ.' }
        };
        var info = actionLabels[action] || { title: 'Xác nhận', color: '#24211d', note: '' };

        var rejectReasonHtml = '';
        if (action === 'reject') {
            rejectReasonHtml = '' +
                '<div style="margin-bottom:1rem;margin-top:1rem;">' +
                '<label style="display:block;font-size:0.8rem;color:#374151;font-weight:600;margin-bottom:6px;">Lý do từ chối (sẽ gửi cho khách) *</label>' +
                '<textarea id="rejectReasonInput" rows="2" maxlength="500" placeholder="Ví dụ: Sản phẩm không đủ điều kiện trả hàng do đã qua 7 ngày..." ' +
                'style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;resize:vertical;font-family:inherit;box-sizing:border-box;"></textarea>' +
                '</div>';
        }

        var confirmHtml = '' +
            '<div style="background:#faf8f4;border:1px solid #efeae0;border-radius:10px;padding:1.25rem;">' +
            '<div style="display:flex;align-items:center;gap:6px;font-size:1rem;font-weight:700;color:' + info.color + ';margin-bottom:8px;">' + info.title + '</div>' +
            '<div style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;">' + info.note + '</div>' +
            rejectReasonHtml +
            '<div class="adm-pin-box" id="pinBox" style="background:#fff;">' +
            '<label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#374151;font-weight:600;margin-bottom:10px;"><i class="ph ph-lock-key"></i> Nhập mã PIN admin để xác nhận</label>' +
            '<input type="password" id="adminPinInput" class="adm-pin-input" inputmode="numeric" maxlength="6" placeholder="••••••" autocomplete="off">' +
            '<div style="font-size:0.78rem;color:#8a8377;margin-top:10px;">Dùng chung mã PIN ví Atelier Pay của bạn.</div>' +
            '<div id="pinErrorMsg" style="color:#b91c1c;font-size:0.82rem;margin-top:8px;min-height:1em;"></div>' +
            '<div style="display:flex;gap:10px;margin-top:1rem;">' +
            '<button type="button" id="pinCancelBtn" class="btn-adm btn-adm--ghost" style="flex:1;padding:0.75rem;font-size:0.88rem;">Hủy bỏ</button>' +
            '<button type="button" id="pinConfirmBtn" class="btn-adm btn-adm--success" style="flex:2;padding:0.75rem;font-size:0.88rem;">Xác nhận</button>' +
            '</div></div></div>';

        var btnContainer = document.getElementById('actionButtons');
        var confirmContainer = document.getElementById('actionConfirmBox');

        btnContainer.style.display = 'none';
        confirmContainer.style.display = 'block';
        confirmContainer.innerHTML = confirmHtml;
        confirmContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });

        var pinInput = document.getElementById('adminPinInput');
        var pinErr   = document.getElementById('pinErrorMsg');
        var pinBox   = document.getElementById('pinBox');

        pinInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            pinErr.textContent = '';
            pinBox.classList.remove('error');
            this.classList.remove('error');
        });

        pinInput.focus();

        document.getElementById('pinCancelBtn').addEventListener('click', function () {
            confirmContainer.style.display = 'none';
            confirmContainer.innerHTML = '';
            btnContainer.style.display = 'flex';
        });

        var confirmBtn = document.getElementById('pinConfirmBtn');

        function doConfirm() {
            pinErr.textContent = '';
            var pin = pinInput.value.trim();
            if (!/^\d{6}$/.test(pin)) {
                pinErr.textContent = 'Vui lòng nhập mã PIN 6 số.';
                pinInput.classList.add('error');
                pinBox.classList.add('error');
                pinInput.focus();
                return;
            }

            var reason = '';
            if (action === 'reject') {
                var ta = document.getElementById('rejectReasonInput');
                reason = ta ? ta.value.trim() : '';
                if (!reason) {
                    pinErr.textContent = 'Vui lòng nhập lý do từ chối.';
                    if (ta) ta.focus();
                    return;
                }
            }

            confirmBtn.disabled = true;
            var oldTxt = confirmBtn.textContent;
            confirmBtn.textContent = 'Đang xử lý...';

            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('return_id', currentId);
            fd.append('action', action);
            fd.append('pin', pin);
            if (reason) fd.append('reason', reason);

            fetch('return_action.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        showToast(data.msg || '✅ Thành công!', false);
                        closeModal();
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        pinErr.textContent = data.msg || 'Có lỗi xảy ra.';
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = oldTxt;
                        pinInput.classList.add('error');
                        pinBox.classList.add('error');
                        pinInput.focus();
                        pinInput.select();
                    }
                })
                .catch(function () {
                    pinErr.textContent = 'Không thể kết nối tới máy chủ.';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = oldTxt;
                });
        }

        confirmBtn.addEventListener('click', doConfirm);
        pinInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') doConfirm(); });
    }

    /* ============ BIND BUTTONS ============ */
    document.querySelectorAll('.btn-view-return').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(this.dataset.id, 10);
            var autoAction = this.dataset.autoAction || null;
            loadDetail(id, autoAction);
        });
    });
})();
</script>

<?php include 'includes/admin_footer.php'; ?>