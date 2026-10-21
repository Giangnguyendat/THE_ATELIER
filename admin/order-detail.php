<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'ID đơn hàng không hợp lệ.'];
    header('Location: orders.php');
    exit;
}

// Lấy thông tin đơn
$stmt = $pdo->prepare("SELECT o.*, u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Lấy chi tiết sản phẩm
$stmtItems = $pdo->prepare("SELECT oi.*, p.name, p.image_url FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
$stmtItems->execute([$orderId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Lấy địa chỉ Shop từ footer_settings
$shopAddress = '123 Đường Thời Trang, Phường Bến Thành, Quận 1, TP.HCM';
try {
    $stmtFooter = $pdo->query("SELECT address FROM footer_settings WHERE id = 1");
    $footerData = $stmtFooter->fetch(PDO::FETCH_ASSOC);
    if (!empty($footerData['address'])) {
        $shopAddress = $footerData['address'];
    }
} catch (Exception $e) {}

/* ============================================================
   XỬ LÝ WORKFLOW: CHUẨN BỊ HÀNG / XUẤT KHO
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {

    $redirect = 'order-detail.php?id=' . $orderId;

    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên làm việc hết hạn. Vui lòng thử lại.'];
        if (!headers_sent()) { header('Location: ' . $redirect); }
        else { echo '<script>location.href=' . json_encode($redirect) . ';</script>'; }
        exit;
    }

    $action    = $_POST['action'];
    $newStatus = null;
    $error     = '';

    if ($action === 'prepare') {
        if (!in_array($order['status'], ['pending', 'processing'], true)) {
            $error = 'Chỉ có thể "Chuẩn bị hàng" khi đơn ở trạng thái Chờ xử lý / Đang xử lý.';
        } else {
            $newStatus = 'confirmed';
        }
    } elseif ($action === 'ship') {
        if ($order['status'] !== 'confirmed') {
            $error = 'Bạn phải bấm "📦 Chuẩn bị hàng" (đơn chuyển sang Đã xác nhận) trước khi xuất kho.';
        } else {
            $newStatus = 'shipped';
        }
    } else {
        $error = 'Hành động không hợp lệ.';
    }

    $labels = ['confirmed' => 'Đã xác nhận', 'shipped' => 'Đang giao'];

    if ($error !== '') {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => $error];
    } elseif ($newStatus !== null) {
        $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newStatus, $orderId]);
        $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật đơn #{$orderId} → " . $labels[$newStatus]];
    }

    if (!headers_sent()) { header('Location: ' . $redirect); }
    else { echo '<script>location.href=' . json_encode($redirect) . ';</script>'; }
    exit;
}

$statusMap = [
    'pending' => ['Chờ xử lý', '#c2410c', '#fff7ed'],
    'processing' => ['Đang xử lý', '#1d4ed8', '#eff6ff'],
    'confirmed' => ['Đã xác nhận', '#1d4ed8', '#eff6ff'],
    'shipped' => ['Đang giao', '#1d4ed8', '#eff6ff'],
    'delivered' => ['Đã giao', '#15803d', '#f0fdf4'],
    'cancelled' => ['Đã hủy', '#b91c1c', '#fef2f2'],
];
$st = $statusMap[$order['status']] ?? ['Không rõ', '#57534e', '#f5f5f4'];

$payStatusMap = [
    'unpaid' => ['Chưa thanh toán', '#57534e', '#f5f5f4'],
    'pending' => ['Chờ thanh toán', '#c2410c', '#fff7ed'],
    'paid' => ['Đã thanh toán', '#15803d', '#f0fdf4'],
    'refund_pending' => ['Chờ hoàn tiền', '#b91c1c', '#fef2f2'],
];
$ps = $payStatusMap[$order['payment_status']] ?? ['Không rõ', '#57534e', '#f5f5f4'];

$payMethods = ['cod' => '💵 COD', 'bank' => '🏦 Chuyển khoản', 'card' => '💳 Thẻ', 'wallet' => '💰 Atelier Pay'];
?>

<style>
    .od-grid { display: grid; grid-template-columns: 1fr 420px; gap: 20px; align-items: start; }
    .od-grid > div { min-width: 0; } /* Fix grid bị tràn */
    
    /* Cột trái */
    .od-card { 
        background: #fff; 
        border: 1px solid var(--admin-border); 
        border-radius: 12px; 
        padding: 20px; 
        margin-bottom: 20px; 
        overflow: visible !important; /* Fix lỗi ẩn bảng */
    }
    .od-title { font-family: 'Fraunces', serif; font-size: 1.25rem; font-weight: 600; margin: 0 0 16px; color: var(--admin-text); border-bottom: 1px solid var(--admin-border); padding-bottom: 12px; }
    .od-info-row { display: flex; margin-bottom: 10px; font-size: 0.9rem; }
    .od-info-label { width: 140px; color: var(--admin-text-muted); font-weight: 600; }
    .od-info-value { flex: 1; color: var(--admin-text); }
    
    /* Fix lỗi hiển thị bảng */
    .od-items-table { 
        display: table !important; 
        width: 100% !important; 
        border-collapse: collapse !important; 
        margin-top: 10px; 
    }
    .od-items-table tbody { display: table-row-group !important; }
    .od-items-table tr { display: table-row !important; }
    .od-items-table th, .od-items-table td { display: table-cell !important; }
    
    .od-items-table th { text-align: left; padding: 10px; background: #fbf9f5; font-size: 0.75rem; color: var(--admin-text-muted); }
    .od-items-table td { padding: 10px; border-bottom: 1px solid var(--admin-border); font-size: 0.9rem; }

    /* Cột phải - Shipping Form */
    .od-sticky { position: sticky; top: 20px; }
    .shipping-box { background: #fff; border: 2px dashed #2563eb; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 0.8rem; font-weight: 700; color: #4b5563; margin-bottom: 6px; }
    .form-control { width: 100%; border: 1px solid #ccc; padding: 8px 12px; border-radius: 6px; font-family: inherit; font-size: 0.9rem; }
    
    .fee-display { font-weight: 700; color: #a8461f; font-family: 'IBM Plex Mono', monospace; font-size: 1rem; background: #fdf7f3; padding: 6px 10px; border-radius: 6px; display: inline-block; }
    
    .radio-group { display: flex; gap: 16px; margin-top: 6px; }
    .radio-group label { display: flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer; margin: 0; color: #1c1b19; }

    /* ========== WORKFLOW CARD ========== */
    .workflow-card {
        border-color: #2563eb !important;
        background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%) !important;
    }
    .workflow-steps {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 18px;
    }
    .wf-step {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        flex: 0 0 auto;
    }
    .wf-dot {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: #e5e7eb;
        color: #6b7280;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.88rem;
        transition: all 0.2s;
    }
    .wf-label {
        font-size: 0.7rem;
        color: #6b7280;
        text-align: center;
        font-weight: 600;
        white-space: nowrap;
    }
    .wf-line {
        flex: 1;
        height: 2px;
        background: #e5e7eb;
        min-width: 12px;
        margin-bottom: 18px;
    }
    .wf-step--active .wf-dot {
        background: #2563eb;
        color: #fff;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        animation: pulseWf 2s infinite;
    }
    .wf-step--active .wf-label { color: #1d4ed8; font-weight: 700; }
    .wf-step--done .wf-dot { background: #16a34a; color: #fff; }
    .wf-step--done .wf-label { color: #15803d; }
    .wf-step--locked .wf-dot { background: #f3f4f6; color: #9ca3af; }
    @keyframes pulseWf {
        0%, 100% { box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15); }
        50%      { box-shadow: 0 0 0 7px rgba(37, 99, 235, 0.22); }
    }

    .btn-wf {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex: 1;
        padding: 12px 16px;
        border: none;
        border-radius: 8px;
        font-size: 0.92rem;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: all 0.15s;
        line-height: 1;
    }
    .btn-wf--prepare {
        background: #16a34a; color: #fff;
        box-shadow: 0 2px 8px rgba(22, 163, 74, 0.25);
    }
    .btn-wf--prepare:hover {
        background: #15803d;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.35);
    }
    .btn-wf--ship {
        background: #2563eb; color: #fff;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
    }
    .btn-wf--ship:hover {
        background: #1d4ed8;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
    }
    .btn-wf--disabled {
        background: #e5e7eb;
        color: #9ca3af;
        cursor: not-allowed;
        box-shadow: none;
    }
    .btn-wf--cancel {
        background: #fef2f2; color: #dc2626;
        border: 1px solid #fecaca;
        flex: 0 0 auto;
        margin-left: auto; /* Đẩy sang phải */
    }
    .btn-wf--cancel:hover {
        background: #dc2626; color: #fff;
        border-color: #dc2626;
    }

    /* ============ MODAL HỦY ĐƠN ============ */
    .adm-modal {
        position: fixed; inset: 0; background: rgba(36, 33, 29, 0.55);
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        display: none; align-items: center; justify-content: center;
        z-index: 9999; padding: 1.5rem;
    }
    .adm-modal.show { display: flex; animation: admFadeIn 0.2s ease; }
    @keyframes admFadeIn { from { opacity: 0; } to { opacity: 1; } }
    
    .adm-modal__box {
        background: #fff; border-radius: 16px; width: 100%;
        max-width: 520px; max-height: 90vh; overflow-y: auto;
        padding: 1.75rem 2rem 2rem; position: relative;
        box-shadow: 0 16px 40px rgba(36, 33, 29, 0.10);
        animation: admSlideUp 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes admSlideUp {
        from { opacity: 0; transform: translateY(20px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .adm-modal__close {
        position: absolute; top: 1rem; right: 1rem;
        width: 36px; height: 36px; border-radius: 50%; border: none;
        background: #f5f2ec; color: #24211d; font-size: 1.3rem;
        line-height: 1; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.15s ease;
    }
    .adm-modal__close:hover { background: #e8e0d0; transform: rotate(90deg); }
    .adm-modal__title {
        font-family: 'Fraunces', serif;
        font-size: 1.5rem; margin: 0 0 1.5rem; color: #24211d;
        padding-right: 3rem; letter-spacing: -0.01em;
    }

    .cancel-alert {
        background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
        border: 1px solid #fecaca; border-radius: 12px;
        padding: 1.1rem 1.25rem; margin-bottom: 1.35rem;
        display: flex; gap: 0.85rem; align-items: flex-start;
    }
    .cancel-alert__icon { font-size: 1.6rem; line-height: 1; flex-shrink: 0; }
    .cancel-alert__title {
        font-size: 0.95rem; font-weight: 700; color: #991b1b; margin: 0 0 4px;
    }
    .cancel-alert__desc {
        font-size: 0.83rem; color: #7f1d1d; line-height: 1.55;
    }
    .cancel-order-info {
        display: flex; gap: 1rem; padding: 0.85rem 1rem;
        background: #faf8f4; border: 1px solid #efeae0;
        border-radius: 10px; margin-bottom: 1.25rem; flex-wrap: wrap;
    }
    .cancel-order-info__item {
        display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 100px;
    }
    .cancel-order-info__label {
        font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em;
        color: #8a8377; font-weight: 700;
    }
    .cancel-order-info__value {
        font-size: 0.9rem; font-weight: 600; color: #24211d;
    }
    .cancel-field { margin-bottom: 1rem; }
    .cancel-field label {
        display: block; font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.06em;
        color: #8a8377; margin-bottom: 6px;
    }
    .cancel-field textarea,
    .cancel-field input {
        width: 100%; padding: 0.7rem 0.9rem;
        border: 1px solid #efeae0; border-radius: 8px;
        font-size: 0.9rem; font-family: inherit;
        background: #fff; color: #24211d; box-sizing: border-box;
        transition: border-color 0.15s;
    }
    .cancel-field textarea { resize: vertical; min-height: 72px; }
    .cancel-field textarea:focus,
    .cancel-field input:focus {
        outline: none; border-color: #a8461f;
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.10);
    }
    .cancel-pin-input {
        font-family: 'IBM Plex Mono', monospace !important;
        font-size: 1.35rem !important;
        letter-spacing: 0.5em;
        text-align: center;
        padding: 12px 16px !important;
    }
    .cancel-pin-input.error {
        border-color: #dc2626 !important;
        animation: shakePin 0.35s;
    }
    @keyframes shakePin {
        0%,100% { transform: translateX(0); }
        25%     { transform: translateX(-6px); }
        75%     { transform: translateX(6px); }
    }
    .cancel-err {
        color: #b91c1c; font-size: 0.83rem; margin: 0.5rem 0 0;
        min-height: 1.1em; font-weight: 600;
    }
    .cancel-success {
        background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534;
        padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.85rem;
        margin-bottom: 1rem;
    }
    .cancel-actions {
        display: flex; gap: 10px; margin-top: 1.25rem;
    }
    .cancel-actions .btn-adm { flex: 1; padding: 0.75rem; font-size: 0.88rem; }
    .cancel-step { display: none; }
    .cancel-step.active { display: block; animation: admFadeIn 0.25s ease; }

    /* ============ Waybill ============ */
    .waybill-wrap { background: #fff; padding: 10px; border: 1px solid #ccc; margin-top: 20px; display: none; max-width: 100%; box-sizing: border-box; overflow: hidden; }
    .waybill-wrap.show { display: block; }
    .wb-label { border: 2px solid #000; padding: 10px; font-family: Arial, sans-serif; color: #000; background: #fff; position: relative; width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden; }
    .wb-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 8px; gap: 10px; }
    .wb-logo { font-size: 24px; font-weight: 900; color: #ee4d2d; text-transform: uppercase; line-height: 1; flex-shrink: 0; }
    .wb-barcode { text-align: right; display: flex; flex-direction: column; align-items: flex-end; min-width: 0; flex: 1; }
    .wb-barcode img { height: 40px; max-width: 100%; object-fit: contain; }
    .wb-cols { display: flex; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; gap: 8px; }
    .wb-col { flex: 1; font-size: 11px; line-height: 1.3; min-width: 0; word-break: break-word; }
    .wb-col:first-child { border-right: 1px dashed #000; padding-right: 8px; }
    .wb-col:last-child { padding-left: 0; }
    .wb-sort-code { text-align: center; font-size: 20px; font-weight: bold; border-bottom: 1px solid #000; padding: 8px 0; margin-bottom: 8px; letter-spacing: 1px; }
    .wb-body { display: flex; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; gap: 8px; }
    .wb-items { flex: 1; font-size: 11px; padding-right: 8px; border-right: 1px dashed #000; min-width: 0; word-break: break-word; }
    .wb-qr { width: 100px; text-align: center; padding-left: 0; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .wb-qr img { width: 90px; height: 90px; object-fit: contain; }
    .wb-qr-code { font-size: 12px; font-weight: bold; margin-top: 4px; word-break: break-all; }
    .wb-footer { display: flex; justify-content: space-between; align-items: flex-end; }
    .wb-cod { font-size: 18px; font-weight: bold; }
    
    @media print {
        body * { visibility: hidden; }
        .waybill-wrap, .waybill-wrap * { visibility: visible; }
        .waybill-wrap { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; border: none; }
        .wb-label { border: 2px solid #000; }
        .no-print { display: none !important; }
    }
</style>

<div class="admin-page-head no-print">
    <div>
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:4px;">
            <a href="orders.php" style="text-decoration:none; color:var(--admin-text-muted); font-size:14px;">&larr; Quay lại</a>
            <h1 style="margin:0;">Chi tiết đơn #<?php echo $orderId; ?></h1>
        </div>
        <div class="subtitle">Tạo lúc: <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></div>
    </div>
</div>

<?php if (!empty($_SESSION['admin_flash'])):
    $f = $_SESSION['admin_flash']; unset($_SESSION['admin_flash']);
    $bg  = $f['type'] === 'success' ? '#ecfdf5' : '#fef2f2';
    $bd  = $f['type'] === 'success' ? '#16a34a' : '#dc2626';
    $clr = $f['type'] === 'success' ? '#166534' : '#991b1b';
?>
<div class="no-print" style="margin-bottom:16px; padding:12px 16px; background:<?php echo $bg; ?>;
            border-left:4px solid <?php echo $bd; ?>; border-radius:8px;
            color:<?php echo $clr; ?>; font-weight:600; font-size:0.9rem;">
    <?php echo $f['type'] === 'success' ? '✅ ' : '⚠️ '; ?><?php echo htmlspecialchars($f['msg']); ?>
</div>
<?php endif; ?>

<div class="od-grid no-print">

    <!-- CỘT TRÁI: THÔNG TIN ĐƠN HÀNG -->
    <div>
        <div class="od-card">
            <h2 class="od-title">📦 Thông tin đơn hàng</h2>
            <div class="od-info-row">
                <div class="od-info-label">Trạng thái đơn:</div>
                <div class="od-info-value">
                    <span style="background:<?php echo $st[2]; ?>; color:<?php echo $st[1]; ?>; padding:4px 10px; border-radius:99px; font-weight:700; font-size:12px;">
                        <?php echo $st[0]; ?>
                    </span>
                </div>
            </div>
            <div class="od-info-row">
                <div class="od-info-label">Thanh toán:</div>
                <div class="od-info-value">
                    <?php echo $payMethods[$order['payment_method']] ?? $order['payment_method']; ?> -
                    <span style="background:<?php echo $ps[2]; ?>; color:<?php echo $ps[1]; ?>; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700;">
                        <?php echo $ps[0]; ?>
                    </span>
                </div>
            </div>
            <div class="od-info-row">
                <div class="od-info-label">Tổng thanh toán:</div>
                <div class="od-info-value" style="font-size:1.2rem; font-weight:700; color:#a8461f;">
                    <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                </div>
            </div>
            <?php if ($order['discount_amount'] > 0): ?>
            <div class="od-info-row">
                <div class="od-info-label">Đã giảm giá:</div>
                <div class="od-info-value" style="color:#166534;">-<?php echo number_format($order['discount_amount'], 0, ',', '.'); ?>đ (<?php echo htmlspecialchars($order['voucher_code'] ?? ''); ?>)</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="od-card">
            <h2 class="od-title">👤 Thông tin người nhận</h2>
            <div class="od-info-row">
                <div class="od-info-label">Họ tên:</div>
                <div class="od-info-value"><b><?php echo htmlspecialchars($order['full_name']); ?></b></div>
            </div>
            <div class="od-info-row">
                <div class="od-info-label">Số điện thoại:</div>
                <div class="od-info-value"><?php echo htmlspecialchars($order['phone']); ?></div>
            </div>
            <div class="od-info-row">
                <div class="od-info-label">Địa chỉ:</div>
                <div class="od-info-value"><?php echo htmlspecialchars($order['address']); ?></div>
            </div>
            <?php if (!empty($order['note'])): ?>
            <div class="od-info-row">
                <div class="od-info-label">Ghi chú:</div>
                <div class="od-info-value" style="font-style:italic; color:#b91c1c;">"<?php echo htmlspecialchars($order['note']); ?>"</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="od-card">
            <h2 class="od-title">🛍️ Sản phẩm (<?php echo count($items); ?>)</h2>
            <table class="od-items-table">
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Size</th>
                        <th>SL</th>
                        <th style="text-align:right;">Giá</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 30px; color: #b91c1c; font-style: italic;">
                                ⚠️ Không tìm thấy sản phẩm nào trong đơn hàng này.<br>
                                (Có thể dữ liệu đã bị xóa hoặc lỗi khi đặt hàng)
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img src="../<?php echo htmlspecialchars($it['image_url'] ?? ''); ?>" 
                                         style="width:40px; height:40px; border-radius:6px; object-fit:cover; background:#eee;" 
                                         onerror="this.src='https://via.placeholder.com/40x40?text=No+Img'">
                                    <span style="font-weight:600;"><?php echo htmlspecialchars($it['name'] ?? 'Sản phẩm không xác định'); ?></span>
                                </div>
                            </td>
                            <td><b><?php echo htmlspecialchars($it['size'] ?? 'M'); ?></b></td>
                            <td>x<?php echo $it['quantity'] ?? 1; ?></td>
                            <td style="text-align:right; font-family:monospace;">
                                <?php echo number_format(($it['price'] ?? 0) * ($it['quantity'] ?? 1), 0, ',', '.'); ?>đ
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- CỘT PHẢI: VẬN CHUYỂN -->
    <div class="od-sticky">

        <!-- ============ WORKFLOW XỬ LÝ ĐƠN ============ -->
        <?php if (!in_array($order['status'], ['delivered', 'cancelled'])): 
            $canPrepare = in_array($order['status'], ['pending', 'processing'], true);
            $canShip    = ($order['status'] === 'confirmed');

            $step1 = 'wf-step--locked';
            $step2 = 'wf-step--locked';
            $step3 = 'wf-step--locked';

            if (in_array($order['status'], ['pending', 'processing'], true)) {
                $step1 = 'wf-step--active';
            } elseif ($order['status'] === 'confirmed') {
                $step1 = 'wf-step--done';
                $step2 = 'wf-step--active';
            } elseif ($order['status'] === 'shipped') {
                $step1 = 'wf-step--done';
                $step2 = 'wf-step--done';
                $step3 = 'wf-step--active';
            }
        ?>
        <div class="od-card workflow-card">
            <h2 class="od-title" style="border:none; padding:0; margin:0 0 16px; color:#1d4ed8;">
                ⚙️ Xử lý đơn hàng
            </h2>

            <!-- Stepper -->
            <div class="workflow-steps">
                <div class="wf-step <?php echo $step1; ?>">
                    <div class="wf-dot"><?php echo $step1 === 'wf-step--done' ? '✓' : '1'; ?></div>
                    <div class="wf-label">Chuẩn bị hàng</div>
                </div>
                <div class="wf-line"></div>
                <div class="wf-step <?php echo $step2; ?>">
                    <div class="wf-dot"><?php echo $step2 === 'wf-step--done' ? '✓' : '2'; ?></div>
                    <div class="wf-label">Xuất kho</div>
                </div>
                <div class="wf-line"></div>
                <div class="wf-step <?php echo $step3; ?>">
                    <div class="wf-dot"><?php echo $step3 === 'wf-step--done' ? '✓' : '3'; ?></div>
                    <div class="wf-label">Đang giao</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin:0;">
                <form method="POST" style="display:flex; gap:10px; flex: 1; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <?php if ($canPrepare): ?>
                        <button type="submit" name="action" value="prepare" class="btn-wf btn-wf--prepare">
                            📦 Chuẩn bị hàng
                        </button>
                    <?php elseif (in_array($order['status'], ['confirmed', 'shipped'], true)): ?>
                        <button type="button" class="btn-wf btn-wf--disabled" disabled>
                            ✓ Đã chuẩn bị hàng
                        </button>
                    <?php endif; ?>

                    <?php if ($canShip): ?>
                        <button type="submit" name="action" value="ship" class="btn-wf btn-wf--ship">
                            🚚 Xuất kho
                        </button>
                    <?php elseif (in_array($order['status'], ['pending', 'processing'], true)): ?>
                        <button type="button" class="btn-wf btn-wf--disabled" disabled
                                title="Phải bấm Chuẩn bị hàng trước">
                            🔒 Xuất kho
                        </button>
                    <?php elseif ($order['status'] === 'shipped'): ?>
                        <button type="button" class="btn-wf btn-wf--disabled" disabled>
                            ✓ Đã xuất kho
                        </button>
                    <?php endif; ?>
                </form>

                <!-- Nút Hủy đơn (nằm ngoài cùng bên phải) -->
                <button type="button" class="btn-wf btn-wf--cancel" 
                        onclick="openCancelModal(<?php echo $orderId; ?>, '<?php echo addslashes(htmlspecialchars($order['full_name'])); ?>', '<?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ')">
                    ❌ Hủy đơn
                </button>
            </div>

            <?php if ($order['status'] === 'shipped'): ?>
                <div style="margin-top:14px; padding:10px 12px; background:#eff6ff;
                            border-left:3px solid #2563eb; border-radius:6px;
                            font-size:0.85rem; color:#1e40af; line-height:1.5;">
                    📌 Đơn đang trên đường giao. Khi khách nhận hàng, cập nhật trạng thái
                    <b>Đã giao</b> tại trang
                    <a href="orders.php" style="color:#1d4ed8; font-weight:700;">Đơn hàng</a>.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['delivered', 'cancelled'])): ?>
            <!-- TRƯỜNG HỢP: ĐÃ GIAO HOẶC ĐÃ HỦY -->
            <div class="shipping-box" style="border-color: #cbd5e1; background: #f8fafc; text-align: center; padding: 40px 20px;">
                <div style="font-size: 3rem; margin-bottom: 16px;">
                    <?php echo $order['status'] === 'delivered' ? '✅' : '❌'; ?>
                </div>
                <h3 style="font-size: 1.1rem; color: #334155; margin: 0 0 8px;">
                    <?php echo $order['status'] === 'delivered' ? 'Đơn hàng đã giao thành công' : 'Đơn hàng đã bị hủy'; ?>
                </h3>
                <p style="font-size: 0.9rem; color: #64748b; margin: 0; line-height: 1.5;">
                    <?php if ($order['status'] === 'delivered'): ?>
                        Đơn hàng này đã hoàn tất. Không thể tạo thêm vận đơn.
                    <?php else: ?>
                        Đơn hàng này đã hủy. Không thể tạo vận đơn.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>
            <!-- TRƯỜNG HỢP: CHƯA GIAO (Cho phép tạo vận đơn) -->
            <div class="shipping-box" id="shippingBox">
                <h2 class="od-title" style="border:none; padding:0; margin:0 0 16px; font-size:1.1rem; color:#1d4ed8;">🚚 Tạo vận đơn</h2>
                
                <form id="shippingForm" onsubmit="generateWaybill(event)">
                    
                    <div class="form-group">
                        <label>Địa chỉ lấy hàng (Shop) *</label>
                        <textarea id="pickupAddress" class="form-control" rows="2" required><?php echo htmlspecialchars($shopAddress); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Đơn vị vận chuyển *</label>
                        <select id="courierSelect" class="form-control" required onchange="updateShippingFee()">
                            <option value="">-- Chọn đơn vị --</option>
                            <option value="SPX" data-fee="22000">Shopee Express (SPX) - 22.000đ</option>
                            <option value="GHN" data-fee="24000">Giao Hàng Nhanh (GHN) - 24.000đ</option>
                            <option value="VIETTEL" data-fee="20000">Viettel Post - 20.000đ</option>
                            <option value="GHTK" data-fee="21000">Giao Hàng Tiết Kiệm (GHTK) - 21.000đ</option>
                        </select>
                    </div>

                    <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:10px; border-radius:6px;">
                        <label style="margin:0;">Phí vận chuyển:</label>
                        <span class="fee-display" id="shippingFeeDisplay">0đ</span>
                    </div>

                    <div class="form-group">
                        <label>Người thanh toán phí ship *</label>
                        <div class="radio-group">
                            <label><input type="radio" name="ship_payer" value="buyer" id="payerBuyer" checked onchange="updateShippingFee()"> Người mua trả</label>
                            <label><input type="radio" name="ship_payer" value="seller" id="payerSeller" onchange="updateShippingFee()"> Người bán trả (Shop)</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Khối lượng (gram)</label>
                        <input type="number" id="weightInput" class="form-control" value="500" min="100" step="100" required>
                    </div>
                    
                    <button type="submit" class="btn-adm btn-adm--primary" style="width:100%; justify-content:center; padding:12px; font-size:0.95rem; margin-top:10px;">
                        🎫 Tạo mã vận đơn
                    </button>
                </form>
            </div>

            <!-- TEM VẬN ĐƠN IN ĐƯỢC -->
            <div class="waybill-wrap" id="waybillWrap">
                <div style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;" class="no-print">
                    <span style="color:#166534; font-weight:700; font-size:0.85rem;">✅ Đã tạo vận đơn</span>
                    <button type="button" class="btn-adm btn-adm--ghost" onclick="window.print()" style="padding:6px 12px; font-size:0.8rem;">
                        🖨️ In mã vận
                    </button>
                </div>

                <div class="wb-label" id="printArea">
                    <!-- Header -->
                    <div class="wb-header">
                        <div class="wb-logo" id="wbLogo">SPX</div>
                        <div class="wb-barcode">
                            <img id="wbBarcodeImg" src="" alt="Barcode">
                            <div style="font-size:10px; font-weight:bold; margin-top:2px;">Mã vận đơn: <span id="wbTrackingNo"></span></div>
                            <div style="font-size:10px;">Mã đơn hàng: <?php echo $orderId; ?></div>
                        </div>
                    </div>

                    <!-- Info Columns -->
                    <div class="wb-cols">
                        <div class="wb-col">
                            <b>Từ:</b><br>
                            THE ATELIER STORE<br>
                            <span id="wbPickupAddress"><?php echo htmlspecialchars($shopAddress); ?></span><br>
                            SĐT: 1900 123 456
                        </div>
                        <div class="wb-col">
                            <b>Đến:</b><br>
                            <?php echo htmlspecialchars($order['full_name']); ?><br>
                            <?php echo htmlspecialchars($order['phone']); ?><br>
                            <?php echo htmlspecialchars($order['address']); ?>
                        </div>
                    </div>

                    <!-- Sorting Code -->
                    <div class="wb-sort-code" id="wbSortCode">SE1-63-19-TU06</div>

                    <!-- Body -->
                    <div class="wb-body">
                        <div class="wb-items">
                            <b>Nội dung (Tổng SL sản phẩm: <?php echo array_sum(array_column($items, 'quantity')); ?>)</b><br>
                            <?php 
                            $itemNames = [];
                            foreach ($items as $it) {
                                $itemNames[] = ($it['name'] ?? 'SP') . ' (SL: ' . ($it['quantity'] ?? 1) . ')';
                            }
                            echo implode(', ', $itemNames);
                            ?>
                            <div style="margin-top:8px; font-size:10px; font-style:italic; color:#555;">
                                Người gửi chịu trách nhiệm về hàng hóa. Bưu gửi không được chứa hàng cấm, hàng dễ cháy nổ. Được mở kiểm tra khi nhận hàng.
                            </div>
                        </div>
                        <div class="wb-qr">
                            <img id="wbQrImg" src="" alt="QR">
                            <div class="wb-qr-code" id="wbQrCode">HCA-50-07-Q74</div>
                            <div style="font-size:10px; margin-top:4px;">Ngày đăng: <?php echo date('d-m-Y'); ?></div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="wb-footer">
                        <div>
                            <div style="font-size:11px;">Tiền thu hộ (nhận):</div>
                            <div class="wb-cod" id="wbCodAmount">0 VND</div>
                        </div>
                        <div>
                            <div style="font-size:11px;">Khối lượng tối đa: <span id="wbWeightDisplay">500 g</span></div>
                            <div style="border:1px solid #000; padding:4px 8px; font-size:10px; margin-top:4px; text-align:center;">
                                Chữ ký người nhận<br><br><br>
                                <span style="font-size:8px; color:#555;">Xác nhận hàng nguyên vẹn</span>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align:center; font-size:10px; margin-top:8px; color:#d32f2f;" id="wbHotline">
                        LƯU Ý: Đóng đủ, đúng, không bồi thường nếu không khai giá.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ MODAL HỦY ĐƠN — PIN + OTP ============ -->
<div class="adm-modal" id="cancelOrderModal">
    <div class="adm-modal__box">
        <button type="button" class="adm-modal__close" onclick="closeCancelModal()">×</button>
        <h2 class="adm-modal__title" style="color:#b91c1c;">⚠️ Hủy đơn hàng</h2>

        <div class="cancel-alert">
            <div class="cancel-alert__icon">⚠️</div>
            <div>
                <div class="cancel-alert__title">Bạn có chắc muốn hủy đơn này?</div>
                <div class="cancel-alert__desc">
                    Hành động này <b>không thể hoàn tác</b>. Đơn sẽ chuyển sang trạng thái
                    <b>Đã hủy</b>, hàng được hoàn kho và khách nhận email thông báo.
                </div>
            </div>
        </div>

        <div class="cancel-order-info">
            <div class="cancel-order-info__item">
                <div class="cancel-order-info__label">Đơn hàng</div>
                <div class="cancel-order-info__value" id="cancelOrderId">#0</div>
            </div>
            <div class="cancel-order-info__item">
                <div class="cancel-order-info__label">Khách hàng</div>
                <div class="cancel-order-info__value" id="cancelOrderCustomer">—</div>
            </div>
            <div class="cancel-order-info__item">
                <div class="cancel-order-info__label">Tổng tiền</div>
                <div class="cancel-order-info__value" id="cancelOrderTotal" style="color:#a8461f;">—</div>
            </div>
        </div>

        <!-- BƯỚC 1 -->
        <div class="cancel-step active" id="cancelStep1">
            <div class="cancel-field">
                <label>Lý do hủy đơn *</label>
                <textarea id="cancelReason" maxlength="500"
                          placeholder="Ví dụ: Khách yêu cầu hủy, hết hàng, sai địa chỉ giao..."></textarea>
            </div>

            <div class="cancel-field">
                <label>🔐 Mã PIN admin (6 số) *</label>
                <input type="password" id="cancelPin" class="cancel-pin-input"
                       inputmode="numeric" maxlength="6" placeholder="••••••"
                       autocomplete="off">
                <div style="font-size:0.75rem;color:#8a8377;margin-top:6px;">
                    💡 Dùng chung mã PIN ví Atelier Pay của bạn.
                </div>
            </div>

            <p class="cancel-err" id="cancelErr1"></p>

            <div class="cancel-actions">
                <button type="button" class="btn-adm btn-adm--ghost" onclick="closeCancelModal()">
                    Hủy bỏ
                </button>
                <button type="button" class="btn-adm btn-adm--danger" id="cancelSendOtpBtn">
                    📧 Gửi OTP xác nhận
                </button>
            </div>
        </div>

        <!-- BƯỚC 2 -->
        <div class="cancel-step" id="cancelStep2">
            <div class="cancel-success" id="cancelOtpSentMsg">✅ OTP đã được gửi</div>

            <div class="cancel-field">
                <label>🔐 Mã OTP (6 số) *</label>
                <input type="password" id="cancelOtp" class="cancel-pin-input"
                       inputmode="numeric" maxlength="6" placeholder="••••••"
                       autocomplete="off">
                <div style="font-size:0.75rem;color:#8a8377;margin-top:6px;">
                    ⏱️ OTP có hiệu lực trong <b>5 phút</b>.
                    <a href="#" id="cancelResendOtp" style="color:#a8461f;font-weight:700;margin-left:4px;">Gửi lại OTP</a>
                </div>
            </div>

            <p class="cancel-err" id="cancelErr2"></p>

            <div class="cancel-actions">
                <button type="button" class="btn-adm btn-adm--ghost" id="cancelBackBtn">
                    ← Quay lại
                </button>
                <button type="button" class="btn-adm btn-adm--danger" id="cancelConfirmBtn">
                    ⚠️ XÁC NHẬN HỦY ĐƠN
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ==========================================
// CẤU HÌNH TỪ PHP
// ==========================================
var orderTotal = <?php echo (float)$order['total_amount']; ?>;
var isPaid = <?php echo ($order['payment_status'] === 'paid') ? 'true' : 'false'; ?>;
var isFreeShip = orderTotal >= 500000;

// ==========================================
// KHỞI TẠO
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    if (isFreeShip) {
        var buyerRadio = document.getElementById('payerBuyer');
        var sellerRadio = document.getElementById('payerSeller');
        if (buyerRadio && sellerRadio) {
            buyerRadio.disabled = true;
            sellerRadio.checked = true;
        }
    }
    updateShippingFee();
});

// ==========================================
// HÀM CẬP NHẬT PHÍ SHIP HIỂN THỊ
// ==========================================
function updateShippingFee() {
    var courierSelect = document.getElementById('courierSelect');
    if (!courierSelect) return;
    
    var selectedOption = courierSelect.options[courierSelect.selectedIndex];
    var fee = parseInt(selectedOption.getAttribute('data-fee')) || 0;
    var display = document.getElementById('shippingFeeDisplay');
    
    if (courierSelect.value === "") {
        display.textContent = '0đ';
        return;
    }

    if (isFreeShip) {
        display.textContent = 'Miễn phí (Đơn >= 500k)';
        display.style.color = '#166534'; 
        return;
    }

    var payer = document.querySelector('input[name="ship_payer"]:checked').value;

    if (payer === 'seller') {
        display.textContent = 'Shop trả (' + fee.toLocaleString('vi-VN') + 'đ)';
        display.style.color = '#166534'; 
    } else {
        display.textContent = fee.toLocaleString('vi-VN') + 'đ';
        display.style.color = '#a8461f'; 
    }
}

// ==========================================
// HÀM TẠO MÃ VẬN ĐƠN
// ==========================================
function generateWaybill(e) {
    e.preventDefault();
    
    var courier = document.getElementById('courierSelect').value;
    var weight = document.getElementById('weightInput').value;
    var pickupAddress = document.getElementById('pickupAddress').value;
    var payer = document.querySelector('input[name="ship_payer"]:checked').value;
    
    if (!courier) {
        alert('Vui lòng chọn đơn vị vận chuyển!');
        return;
    }

    var courierSelect = document.getElementById('courierSelect');
    var baseFee = parseInt(courierSelect.options[courierSelect.selectedIndex].getAttribute('data-fee')) || 0;
    
    var actualFee = isFreeShip ? 0 : baseFee;
    
    // 1. Tạo mã vận đơn ảo
    var prefix = courier === 'SPX' ? 'SPXVN' : (courier === 'GHN' ? 'GHN' : (courier === 'VIETTEL' ? 'VTP' : 'GHTK'));
    var trackingNo = prefix + Math.floor(100000000000 + Math.random() * 900000000000);
    
    // 2. Cập nhật giao diện tem
    document.getElementById('wbLogo').textContent = courier;
    document.getElementById('wbLogo').style.color = courier === 'SPX' ? '#ee4d2d' : (courier === 'GHN' ? '#0055aa' : '#000');
    document.getElementById('wbTrackingNo').textContent = trackingNo;
    document.getElementById('wbQrCode').textContent = trackingNo.substring(0, 12);
    document.getElementById('wbPickupAddress').textContent = pickupAddress;
    
    // 3. API Barcode & QR Code (Thêm height=50 để barcode không bị vỡ hình)
    document.getElementById('wbBarcodeImg').src = 'https://barcode.tec-it.com/barcode.ashx?data=' + trackingNo + '&code=Code128&translate-esc=on&dpi=96&height=50';
    document.getElementById('wbQrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + trackingNo;
    
    // 4. Random mã phân loại
    var sortCode = 'SE1-' + Math.floor(10 + Math.random()*90) + '-' + Math.floor(10 + Math.random()*90) + '-TU06';
    document.getElementById('wbSortCode').textContent = sortCode;
    
    // 5. Khối lượng
    document.getElementById('wbWeightDisplay').textContent = parseInt(weight).toLocaleString('vi-VN') + ' g';
    
    // ==========================================
    // 6. TÍNH TIỀN THU HỘ (COD)
    // ==========================================
    var finalCod = 0;
    
    if (!isPaid) {
        finalCod = orderTotal; 
        if (payer === 'buyer' && !isFreeShip) {
            finalCod += baseFee;
        }
    } else {
        finalCod = 0;
    }
    
    document.getElementById('wbCodAmount').textContent = finalCod.toLocaleString('vi-VN') + ' VND';

    // 7. Hiển thị tem
    document.getElementById('waybillWrap').classList.add('show');
    document.getElementById('shippingBox').style.display = 'none'; 
    
    document.getElementById('waybillWrap').scrollIntoView({ behavior: 'smooth' });
}

/* ============================================================
   MODAL HỦY ĐƠN — PIN + OTP
   ============================================================ */
(function () {
    var cancelModal = document.getElementById('cancelOrderModal');
    var step1El     = document.getElementById('cancelStep1');
    var step2El     = document.getElementById('cancelStep2');
    var reasonEl    = document.getElementById('cancelReason');
    var pinEl       = document.getElementById('cancelPin');
    var otpEl       = document.getElementById('cancelOtp');
    var err1El      = document.getElementById('cancelErr1');
    var err2El      = document.getElementById('cancelErr2');
    var sendOtpBtn  = document.getElementById('cancelSendOtpBtn');
    var confirmBtn  = document.getElementById('cancelConfirmBtn');
    var backBtn     = document.getElementById('cancelBackBtn');
    var sentMsgEl   = document.getElementById('cancelOtpSentMsg');
    var resendLink  = document.getElementById('cancelResendOtp');
    var CSRF        = <?php echo json_encode($_SESSION['csrf_token']); ?>;

    var currentOrderId = 0;

    /* Chỉ cho nhập số */
    [pinEl, otpEl].forEach(function (el) {
        el.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            this.classList.remove('error');
        });
    });

    /* Mở modal */
    window.openCancelModal = function (orderId, customerName, total) {
        currentOrderId = orderId;

        reasonEl.value = '';
        pinEl.value = '';
        otpEl.value = '';
        err1El.textContent = '';
        err2El.textContent = '';
        pinEl.classList.remove('error');
        otpEl.classList.remove('error');
        step1El.classList.add('active');
        step2El.classList.remove('active');
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = '📧 Gửi OTP xác nhận';
        confirmBtn.disabled = false;
        confirmBtn.textContent = '⚠️ XÁC NHẬN HỦY ĐƠN';

        document.getElementById('cancelOrderId').textContent = '#' + orderId;
        document.getElementById('cancelOrderCustomer').textContent = customerName || '—';
        document.getElementById('cancelOrderTotal').textContent = total || '—';

        cancelModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { reasonEl.focus(); }, 150);
    };

    window.closeCancelModal = function () {
        cancelModal.classList.remove('show');
        document.body.style.overflow = '';
        currentOrderId = 0;
    };

    cancelModal.addEventListener('click', function (e) {
        if (e.target === cancelModal) closeCancelModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && cancelModal.classList.contains('show')) {
            closeCancelModal();
        }
    });

    /* Bước 1: Gửi OTP */
    function sendOtp() {
        err1El.textContent = '';

        var reason = reasonEl.value.trim();
        var pin    = pinEl.value.trim();

        if (!reason) {
            err1El.textContent = '⚠️ Vui lòng nhập lý do hủy đơn.';
            reasonEl.focus();
            return;
        }
        if (!/^\d{6}$/.test(pin)) {
            err1El.textContent = '⚠️ Vui lòng nhập mã PIN 6 số.';
            pinEl.classList.add('error');
            pinEl.focus();
            return;
        }

        sendOtpBtn.disabled = true;
        var oldTxt = sendOtpBtn.textContent;
        sendOtpBtn.textContent = '⏳ Đang gửi...';

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('order_id', currentOrderId);
        fd.append('pin', pin);

        fetch('admin_cancel_send_otp.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    sentMsgEl.innerHTML = '✅ ' + d.msg;
                    step1El.classList.remove('active');
                    step2El.classList.add('active');
                    setTimeout(function () { otpEl.focus(); }, 150);
                } else {
                    err1El.textContent = d.msg || 'Có lỗi xảy ra.';
                    if (/PIN/i.test(d.msg || '')) {
                        pinEl.classList.add('error');
                        pinEl.focus();
                        pinEl.select();
                    }
                    sendOtpBtn.disabled = false;
                    sendOtpBtn.textContent = oldTxt;
                }
            })
            .catch(function () {
                err1El.textContent = 'Không thể kết nối tới máy chủ.';
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = oldTxt;
            });
    }

    sendOtpBtn.addEventListener('click', sendOtp);
    pinEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); sendOtp(); }
    });

    /* Quay lại */
    backBtn.addEventListener('click', function () {
        step2El.classList.remove('active');
        step1El.classList.add('active');
        err2El.textContent = '';
        otpEl.value = '';
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = '📧 Gửi OTP xác nhận';
        setTimeout(function () { pinEl.focus(); }, 150);
    });

    /* Gửi lại OTP */
    resendLink.addEventListener('click', function (e) {
        e.preventDefault();
        step2El.classList.remove('active');
        step1El.classList.add('active');
        err2El.textContent = '';
        otpEl.value = '';
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = '📧 Gửi lại OTP';
        setTimeout(function () { pinEl.focus(); pinEl.select(); }, 150);
    });

    /* Bước 2: Xác nhận hủy */
    function confirmCancel() {
        err2El.textContent = '';

        var otp    = otpEl.value.trim();
        var reason = reasonEl.value.trim();

        if (!/^\d{6}$/.test(otp)) {
            err2El.textContent = '⚠️ Vui lòng nhập OTP 6 số.';
            otpEl.classList.add('error');
            otpEl.focus();
            return;
        }

        confirmBtn.disabled = true;
        var oldTxt = confirmBtn.textContent;
        confirmBtn.textContent = '⏳ Đang xử lý...';

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('order_id', currentOrderId);
        fd.append('otp', otp);
        fd.append('reason', reason);

        fetch('admin_cancel_confirm.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    alert(d.msg || '✅ Đã hủy đơn thành công!');
                    location.reload();
                } else {
                    err2El.textContent = d.msg || 'Có lỗi xảy ra.';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = oldTxt;
                    if (/OTP/i.test(d.msg || '')) {
                        otpEl.classList.add('error');
                        otpEl.focus();
                        otpEl.select();
                    }
                }
            })
            .catch(function () {
                err2El.textContent = 'Không thể kết nối tới máy chủ.';
                confirmBtn.disabled = false;
                confirmBtn.textContent = oldTxt;
            });
    }

    confirmBtn.addEventListener('click', confirmCancel);
    otpEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); confirmCancel(); }
    });
})();
</script>

<?php include 'includes/admin_footer.php'; ?>