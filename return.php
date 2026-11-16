<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId  = $_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: account.php');
    exit;
}

// Lấy đơn
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['flash_error'] = 'Đơn hàng không tồn tại.';
    header('Location: account.php');
    exit;
}

// 1. LẤY YÊU CẦU TRẢ HÀNG (NẾU CÓ)
$stmt = $pdo->prepare("
    SELECT id, return_code, status, created_at, return_type, carrier, reason, reason_detail, refund_method, refund_bank_id 
    FROM return_requests
    WHERE order_id = ? AND status IN ('pending','approved','refunding')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$orderId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
$isExisting = !empty($existing);

$existingStatus  = $existing['status'] ?? '';
$existingType    = $existing['return_type'] ?? null;
$existingCarrier = $existing['carrier'] ?? 'SPX';

// 2. NẾU CHƯA CÓ YÊU CẦU -> KIỂM TRA ĐIỀU KIỆN TẠO MỚI
if (!$isExisting) {
    if ($order['status'] !== 'delivered') {
        $_SESSION['flash_error'] = 'Chỉ có thể trả hàng với đơn đã giao thành công.';
        header('Location: account.php');
        exit;
    }
    $deliveredTime = strtotime($order['delivered_at'] ?? $order['updated_at'] ?? $order['created_at']);
    $daysLeft      = 7 - floor((time() - $deliveredTime) / 86400);
    if ($daysLeft <= 0) {
        $_SESSION['flash_error'] = 'Đã quá thời hạn trả hàng (7 ngày kể từ khi giao).';
        header('Location: account.php');
        exit;
    }
}

/* ============================================================
   XỬ LÝ KHI KHÁCH CHỌN PHƯƠNG THỨC GỬI HÀNG (SAU KHI ĐÃ DUYỆT)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_return_method') {
    $returnId = (int)($_POST['return_id'] ?? 0);
    $rType    = $_POST['return_type'] ?? '';
    $carrier  = $_POST['carrier'] ?? 'SPX';

    if (in_array($rType, ['self', 'shipping'])) {
        $stmt = $pdo->prepare("SELECT id FROM return_requests WHERE id = ? AND user_id = ? AND status = 'approved'");
        $stmt->execute([$returnId, $userId]);
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE return_requests SET return_type = ?, carrier = ? WHERE id = ?");
            $stmt->execute([$rType, $carrier, $returnId]);
            header("Location: return.php?order_id=$orderId");
            exit;
        }
    }
}

// Lấy items của đơn gốc
$stmt = $pdo->prepare("
    SELECT oi.*, p.name, p.image_url
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy chi tiết những món khách đã chọn trả (nếu có đơn hoàn)
$returnItems = [];
$returnImages = [];
$bankInfo = null;

if ($isExisting) {
    // Lấy sản phẩm đã chọn trả
    try {
        $stmt = $pdo->prepare("SELECT order_item_id, quantity FROM return_items WHERE return_id = ?");
        $stmt->execute([$existing['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
            $returnItems[$ri['order_item_id']] = (int)$ri['quantity'];
        }
    } catch (Exception $e) {}

    // Lấy ảnh minh chứng
    try {
        $stmt = $pdo->prepare("SELECT image_path FROM return_images WHERE return_id = ?");
        $stmt->execute([$existing['id']]);
        $returnImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    // Lấy thông tin ngân hàng nếu hoàn qua bank
    if ($existing['refund_method'] === 'bank' && !empty($existing['refund_bank_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT bank_name, account_no FROM linked_banks WHERE id = ?");
            $stmt->execute([$existing['refund_bank_id']]);
            $bankInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

// Linked banks cho form tạo mới
$stmt = $pdo->prepare("SELECT id, bank_name, account_no, account_name, is_default FROM linked_banks WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$stmt->execute([$userId]);
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ví + PIN
$stmt = $pdo->prepare("SELECT atelier_pay_balance, payment_pin FROM users WHERE id = ?");
$stmt->execute([$userId]);
$uRow          = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$walletBalance = (float)($uRow['atelier_pay_balance'] ?? 0);
$hasPin        = !empty($uRow['payment_pin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$reasons = [
    'Sản phẩm bị lỗi / hư hỏng',
    'Sản phẩm không đúng mô tả',
    'Sản phẩm không vừa size',
    'Nhận sai sản phẩm / thiếu hàng',
    'Sản phẩm kém chất lượng',
    'Đổi ý, không muốn mua nữa',
    'Khác',
];

// Lấy địa chỉ Shop
$shopAddress = '123 Đường Thời Trang, Phường Bến Thành, Quận 1, TP.HCM';
$shopPhone = '1900 123 456';
try {
    $stmtFooter = $pdo->query("SELECT address, phone FROM footer_settings WHERE id = 1");
    $footerData = $stmtFooter->fetch(PDO::FETCH_ASSOC);
    if (!empty($footerData['address'])) $shopAddress = $footerData['address'];
    if (!empty($footerData['phone'])) $shopPhone = $footerData['phone'];
} catch (Exception $e) {}

// ============================================================
// CHUẨN BỊ DỮ LIỆU VẬN ĐƠN (NẾU ĐÃ CHỌN GỬI BƯU ĐIỆN)
// ============================================================
$trackingNo = '';
$logoName = 'Shopee'; $logoMain = 'SPX'; $logoSub = 'EXPRESS'; $logoColor = '#ee4d2d'; $logoIcon = 'S';

if ($isExisting && $existingType === 'shipping') {
    $prefix = $existingCarrier === 'SPX' ? 'SPXVN' : ($existingCarrier === 'GHN' ? 'GHN' : ($existingCarrier === 'VIETTEL' ? 'VTP' : 'GHTK'));
    $numbersOnly = preg_replace('/\D/', '', $existing['return_code']);
    if (empty($numbersOnly)) $numbersOnly = '00000000';
    $trackingNo = $prefix . $numbersOnly;

    if ($existingCarrier === 'GHN') {
        $logoName = 'Giao Hàng'; $logoMain = 'GHN'; $logoSub = 'NHANH'; $logoColor = '#0055aa'; $logoIcon = 'G';
    } elseif ($existingCarrier === 'VIETTEL') {
        $logoName = 'Viettel'; $logoMain = 'VTP'; $logoSub = 'POST'; $logoColor = '#d32f2f'; $logoIcon = 'V';
    } elseif ($existingCarrier === 'GHTK') {
        $logoName = 'Giao Hàng'; $logoMain = 'GHTK'; $logoSub = 'TIẾT KIỆM'; $logoColor = '#008844'; $logoIcon = 'G';
    }
}
?>
<?php include 'includes/header.php'; ?>

<style>
    .return-page { padding: 40px 0; background: #f8f9fa; min-height: 80vh; }
    .return-layout { display: grid; grid-template-columns: 1fr 400px; gap: 24px; align-items: start; max-width: 1200px; margin: 0 auto; }
    .return-card { background: #fff; border-radius: 12px; padding: 1.5rem 2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #efeae0; }
    .sticky-sidebar { position: sticky; top: 20px; }
    .return-section { margin-bottom: 2rem; }
    .return-section h3 { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: #8a8377; margin: 0 0 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 6px; }
    .return-section h3 i { font-size: 1.1rem; }
    .return-item { display: flex; align-items: center; gap: 1rem; padding: 0.85rem; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.6rem; transition: all 0.2s; cursor: pointer; }
    .return-item.selected { border-color: #a8461f; background: #fdf7f3; }
    .return-item input[type=checkbox] { transform: scale(1.3); accent-color: #a8461f; cursor: pointer; }
    .return-item img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; }
    .return-item .info { flex: 1; }
    .return-item .info .name { font-weight: 600; font-size: 0.9rem; color: #1c1b19; }
    .return-item .info .meta { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }
    .return-item .qty-input { width: 60px; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; text-align: center; }
    .method-box { display: block; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 10px; cursor: pointer; margin-bottom: 0.75rem; transition: all 0.2s; background: #fff; }
    .method-box:hover { border-color: #a8461f55; }
    .method-box.selected { border-color: #a8461f; background: #fdf7f3; }
    .method-box input[type=radio] { accent-color: #a8461f; margin-right: 0.6rem; transform: scale(1.2); }
    .method-box b { display: flex; align-items: center; gap: 6px; font-size: 0.95rem; }
    #returnPin { width: 100%; padding: 14px 18px; border: 2px solid #d1d5db; border-radius: 10px; font-size: 1.5rem; letter-spacing: 0.5em; text-align: center; font-family: 'IBM Plex Mono', monospace; background: #fff; }
    #returnPin:focus { outline: none; border-color: #a8461f; box-shadow: 0 0 0 3px rgba(168,70,31,0.12); }
    .uploader-wrap { background: #faf8f4; border: 1px solid #efeae0; border-radius: 12px; padding: 1.25rem; }
    .dropzone { position: relative; border: 2px dashed #d1d5db; border-radius: 12px; padding: 2rem 1rem; text-align: center; cursor: pointer; background: #fff; transition: all 0.2s; }
    .dropzone:hover, .dropzone.dragover { border-color: #a8461f; background: #fdf7f3; }
    .dropzone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .uploader-preview { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 0.75rem; margin-top: 1rem; }
    .preview-card { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
    .preview-card img { width: 100%; height: 100%; object-fit: cover; }
    .preview-remove { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: rgba(185,28,28,0.9); color: #fff; border: none; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }
    .submit-btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .submit-btn i { font-size: 1.2rem; }
    .submit-btn:disabled { background: #e5e7eb !important; color: #9ca3af !important; cursor: not-allowed; box-shadow: none !important; }
    .submit-btn:not(:disabled) { background: #a8461f; color: #fff; cursor: pointer; box-shadow: 0 4px 12px rgba(168,70,31,0.3); }
    .submit-btn:not(:disabled):hover { background: #8f3a18; transform: translateY(-1px); }
    
    .btn-print { background: #166534; color: #fff; padding: 10px 20px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.95rem; transition: all 0.2s; box-shadow: 0 2px 8px rgba(22, 101, 52, 0.25); width: 100%;}
    .btn-print:hover { background: #15803d; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22, 101, 52, 0.35); }
    .btn-print i { font-size: 1.2rem; }
    .btn-adm { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 20px; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.95rem; transition: 0.2s; cursor: pointer; border: 1px solid #d1d5db; background: #fff; color: #374151; }
    .btn-adm:hover { background: #f3f4f6; }

    /* Style riêng cho form bị khóa (Readonly) */
    .form-readonly input, .form-readonly select, .form-readonly textarea, .form-readonly .dropzone { pointer-events: none; background-color: #f9fafb; opacity: 0.8; }
    .form-readonly .method-box { pointer-events: none; opacity: 0.8; }
    .form-readonly .return-item input[type=checkbox] { opacity: 1; }

    /* ============ Waybill SPX Style (Thu gọn cho Sidebar) ============ */
    .waybill-wrap { background: #fff; padding: 10px; border: 1px dashed #2563eb; display: none; box-sizing: border-box; width: 100%; margin-bottom: 1rem;}
    .waybill-wrap.show { display: block; }
    .wb-label { border: 2px solid #000; padding: 10px; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; position: relative; width: 100%; box-sizing: border-box; font-size: 10px; }
    .wb-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
    .wb-logo-area { display: flex; flex-direction: column; align-items: flex-start; }
    .wb-barcode-area { text-align: right; display: flex; flex-direction: column; align-items: flex-end; width: 150px; }
    .wb-barcode-area img { height: 35px !important; width: 100% !important; object-fit: fill; }
    .wb-tracking-text { font-size: 10px; margin-top: 2px; font-family: monospace; }
    .wb-tracking-text span { font-weight: bold; }
    .wb-dashed { border-top: 1px dashed #000; margin: 6px 0; width: 100%; }
    .wb-addresses { display: flex; gap: 6px; }
    .wb-col-left { flex: 1; border-right: 1px dashed #000; padding-right: 6px; }
    .wb-col-right { flex: 1.2; }
    .wb-addr-title { font-weight: bold; font-size: 10px; margin-bottom: 2px; }
    .wb-addr-content { font-size: 10px; line-height: 1.3; word-break: break-word; }
    .wb-sorting { display: flex; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 4px 0; align-items: center; justify-content: space-between; }
    .wb-sort-code { flex: 1.2; font-size: 13px; font-weight: bold; font-family: monospace; letter-spacing: 0.5px; padding-left: 4px; white-space: nowrap; }
    .wb-sort-dest { flex: 1; font-size: 12px; font-weight: bold; text-align: center; border-left: 1px dashed #000; text-transform: uppercase; white-space: nowrap; }
    .wb-body { display: flex; padding: 6px 0; }
    .wb-items { flex: 1; font-size: 10px; line-height: 1.3; padding-right: 6px; border-right: 1px dashed #000; display: flex; flex-direction: column; }
    .wb-qr-area { width: 90px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; padding-left: 6px; }
    .wb-qr-area img { width: 70px; height: 70px; object-fit: contain; }
    .wb-qr-sub { font-size: 11px; font-weight: bold; margin-top: 2px; font-family: monospace; }
    .wb-date-text { font-size: 10px; font-weight: bold; margin-top: 2px; text-align: center; }
    .wb-footer { display: flex; border-top: 1px dashed #000; padding-top: 6px; }
    .wb-footer-left { flex: 1.2; border-right: 1px dashed #000; padding-right: 6px; }
    .wb-cod-title { font-size: 10px; }
    .wb-cod-amount { font-size: 16px; font-weight: bold; margin: 2px 0; font-family: Arial, sans-serif; }
    .wb-weight { font-size: 10px; text-align: right; font-weight: bold; margin-top: 2px; }
    .wb-footer-right { flex: 0.8; padding-left: 6px; display: flex; align-items: center; justify-content: center; }
    .wb-sign-box { border: 1px solid #000; padding: 4px; text-align: center; font-size: 9px; width: 100%; height: 60px; display: flex; flex-direction: column; justify-content: space-between; }
    .wb-sign-title { font-weight: bold; font-size: 10px; }
    .wb-instructions { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 6px; font-size: 9px; }
    .wb-guide-text { font-weight: bold; font-size: 10px; }
    .wb-return-text { text-align: right; font-size: 8px; line-height: 1.1; }
    .wb-hotline { text-align: center; color: #ee4d2d; font-weight: bold; font-size: 10px; margin-top: 6px; border-top: 1px solid #000; padding-top: 4px; }

    @media print {
        body * { visibility: hidden; }
        #returnWaybillSection, #returnWaybillSection * { visibility: visible; }
        #returnWaybillSection { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; border: none; }
        .waybill-wrap { border: none; max-width: 100%; padding: 0; margin: 0; }
        .wb-label { border: 2px solid #000; page-break-inside: avoid; }
        .no-print { display: none !important; }
    }
    @media (max-width: 992px) {
        .return-layout { grid-template-columns: 1fr; }
        .sticky-sidebar { position: static; }
    }
</style>

<main class="return-page">
    <div style="max-width:1200px; margin:0 auto 20px; padding:0 15px;" class="no-print">
        <a href="account.php" style="color:#6b7280;text-decoration:none;font-size:14px; display:inline-flex; align-items:center; gap:4px;">
            <i class="ph ph-arrow-left"></i> Quay lại tài khoản
        </a>
    </div>

    <div style="padding: 0 15px;" class="no-print">
        <div class="return-layout" id="mainLayout">
            
            <!-- CỘT TRÁI: THÔNG TIN CHI TIẾT ĐƠN TRẢ HÀNG -->
            <div class="return-card <?php echo $isExisting ? 'form-readonly' : ''; ?>">
                <h1 style="margin:0 0 0.5rem;font-size:1.5rem;font-weight:700;color:#1c1b19; display:flex; align-items:center; gap:8px;">
                    <i class="ph ph-arrows-counter-clockwise" style="color:#a8461f;"></i> Yêu cầu trả hàng / Hoàn tiền
                </h1>
                <p style="color:#6b7280;font-size:0.9rem;margin:0 0 1.5rem;">
                    Đơn hàng <b>#<?php echo (int)$order['id']; ?></b> 
                    <?php if (!$isExisting): ?>· Còn <b style="color:#a8461f;"><?php echo $daysLeft; ?> ngày</b> để trả hàng.<?php endif; ?>
                </p>

                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:1.5rem;font-size:0.9rem; display:flex; align-items:center; gap:8px;">
                        <i class="ph-fill ph-warning-circle" style="font-size:1.2rem;"></i>
                        <?php echo sanitize($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$hasPin && !$isExisting): ?>
                    <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:14px 16px;border-radius:10px;margin-bottom:1.5rem;font-size:0.9rem; display:flex; align-items:flex-start; gap:8px;">
                        <i class="ph-fill ph-warning" style="font-size:1.2rem; margin-top:2px;"></i>
                        <div>Bạn chưa thiết lập <b>mã PIN</b>. Vui lòng vào <a href="wallet.php" style="color:#a8461f;font-weight:700; text-decoration:underline;">Ví Atelier Pay</a> để thiết lập.</div>
                    </div>
                <?php endif; ?>

                <form id="returnForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">

                    <!-- 1. SẢN PHẨM -->
                    <div class="return-section">
                        <h3>1. Sản phẩm trả hàng *</h3>
                        <?php foreach ($items as $it): 
                            $isSelected = $isExisting && (empty($returnItems) || isset($returnItems[$it['id']]));
                            $selQty = ($isExisting && isset($returnItems[$it['id']])) ? $returnItems[$it['id']] : 1;
                        ?>
                            <label class="return-item <?php echo $isSelected ? 'selected' : ''; ?>">
                                <input type="checkbox" class="item-check" name="items[]" value="<?php echo (int)$it['id']; ?>" data-price="<?php echo (float)$it['price']; ?>" <?php echo $isSelected ? 'checked' : ''; ?> <?php echo $isExisting ? 'disabled' : ''; ?>>
                                <img src="<?php echo sanitize($it['image_url']); ?>" alt="">
                                <div class="info">
                                    <div class="name"><?php echo sanitize($it['name']); ?></div>
                                    <div class="meta">
                                        Size: <b><?php echo sanitize($it['size']); ?></b> · Đã mua: <b>x<?php echo (int)$it['quantity']; ?></b> ·
                                        <span style="font-family:'IBM Plex Mono';font-weight:600;color:#a8461f;"><?php echo number_format($it['price'], 0, ',', '.'); ?>đ</span>/cái
                                    </div>
                                </div>
                                <div style="text-align:center;">
                                    <div style="font-size:0.7rem;color:#6b7280;margin-bottom:4px;">SL trả</div>
                                    <input type="number" class="qty-input item-qty" name="qty[<?php echo (int)$it['id']; ?>]" value="<?php echo $selQty; ?>" min="1" max="<?php echo (int)$it['quantity']; ?>" <?php echo $isSelected ? '' : 'disabled'; ?>>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- 2. LÝ DO -->
                    <div class="return-section">
                        <h3>2. Lý do trả hàng *</h3>
                        <select name="reason" id="returnReason" required style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;background:#fff;" <?php echo $isExisting ? 'disabled' : ''; ?>>
                            <option value="">-- Chọn lý do --</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?php echo htmlspecialchars($r, ENT_QUOTES); ?>" <?php echo ($isExisting && $existing['reason'] === $r) ? 'selected' : ''; ?>><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="reasonOtherGroup" <?php echo ($isExisting && $existing['reason'] === 'Khác') ? '' : 'hidden'; ?> style="margin-top:0.75rem;">
                            <textarea name="reason_detail" id="reasonDetail" rows="3" maxlength="500" placeholder="Mô tả chi tiết..." style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;resize:vertical;box-sizing:border-box;" <?php echo $isExisting ? 'disabled' : ''; ?>><?php echo $isExisting ? htmlspecialchars($existing['reason_detail']) : ''; ?></textarea>
                        </div>
                    </div>

                    <!-- 3. ẢNH -->
                    <div class="return-section">
                        <h3>3. Ảnh sản phẩm thực tế *</h3>
                        <div class="uploader-wrap">
                            <div class="dropzone" id="dropzone">
                                <input type="file" id="returnImages" name="images[]" accept="image/*" multiple <?php echo $isExisting ? 'disabled' : ''; ?>>
                                <i class="ph ph-camera" style="font-size:2.5rem;color:#a8461f;margin-bottom:0.5rem;display:block;"></i>
                                <div style="font-weight:700;font-size:0.95rem;color:#1c1b19;">Kéo ảnh vào đây hoặc bấm để chọn</div>
                                <div style="font-size:0.8rem;color:#8a8377;margin-top:4px;">Tối đa 5 ảnh · ≤ 5MB/ảnh</div>
                            </div>
                            <div class="uploader-preview" id="uploaderPreview">
                                <?php if ($isExisting && !empty($returnImages)): ?>
                                    <?php foreach ($returnImages as $imgUrl): ?>
                                        <div class="preview-card">
                                            <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="Ảnh minh chứng">
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 4. HOÀN TIỀN -->
                    <div class="return-section">
                        <h3>4. Phương thức hoàn tiền *</h3>
                        <label class="method-box <?php echo (!$isExisting || $existing['refund_method'] === 'wallet') ? 'selected' : ''; ?>" data-method="wallet">
                            <input type="radio" name="refund_method" value="wallet" <?php echo (!$isExisting || $existing['refund_method'] === 'wallet') ? 'checked' : ''; ?> <?php echo $isExisting ? 'disabled' : ''; ?>>
                            <b><i class="ph ph-wallet" style="color:#a8461f;"></i> Ví Atelier Pay</b>
                            <?php if (!$isExisting): ?>
                                <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Số dư: <b><?php echo number_format($walletBalance, 0, ',', '.'); ?>đ</b> · Hoàn ngay sau khi duyệt</div>
                            <?php endif; ?>
                        </label>
                        <label class="method-box <?php echo ($isExisting && $existing['refund_method'] === 'bank') ? 'selected' : ''; ?>" data-method="bank">
                            <input type="radio" name="refund_method" value="bank" <?php echo ($isExisting && $existing['refund_method'] === 'bank') ? 'checked' : ''; ?> <?php echo $isExisting ? 'disabled' : ''; ?>>
                            <b><i class="ph ph-bank" style="color:#a8461f;"></i> Chuyển khoản ngân hàng</b>
                            <?php if (!$isExisting): ?>
                                <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Hoàn về tài khoản đã liên kết (1-3 ngày)</div>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($isExisting && $bankInfo): ?>
                            <div style="margin-top:0.5rem;padding-left:1.6rem; font-size:0.85rem; color:#374151;">
                                <b>STK nhận hoàn:</b> <?php echo htmlspecialchars($bankInfo['bank_name']); ?> - ••••<?php echo substr($bankInfo['account_no'], -4); ?>
                            </div>
                        <?php elseif (!$isExisting): ?>
                            <div id="bankSelectWrap" <?php echo ($existing['refund_method'] ?? '') === 'bank' ? '' : 'hidden'; ?> style="margin-top:0.5rem;padding-left:1.6rem;">
                                <?php if (empty($banks)): ?>
                                    <div style="background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;font-size:0.85rem;">
                                        <i class="ph ph-warning"></i> Chưa liên kết ngân hàng. <a href="wallet.php" style="color:#a8461f;font-weight:600;">Liên kết ngay →</a>
                                    </div>
                                <?php else: ?>
                                    <select name="refund_bank_id" id="refundBankId" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;">
                                        <?php foreach ($banks as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>">
                                                <?php echo sanitize($b['bank_name']); ?> · •••• <?php echo substr($b['account_no'], -4); ?> <?php echo $b['is_default'] ? '(Mặc định)' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 5. PIN -->
                    <div class="return-section">
                        <h3>5. Xác nhận mã PIN *</h3>
                        <div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:0.88rem;color:#374151;margin-bottom:10px;font-weight:600;">
                                <i class="ph ph-lock-key" style="font-size:1.1rem; color:#a8461f;"></i> Nhập mã PIN 6 số (Ví Atelier Pay)
                            </label>
                            <?php if ($isExisting): ?>
                                <div style="color:#166534; font-weight:600; font-size:0.9rem; display:flex; align-items:center; gap:6px;">
                                    <i class="ph-fill ph-check-circle" style="font-size:1.2rem;"></i> Đã xác thực mã PIN
                                </div>
                            <?php else: ?>
                                <input type="password" name="pin" id="returnPin" inputmode="numeric" maxlength="6" placeholder="••••••" autocomplete="off" <?php echo !$hasPin ? 'disabled' : ''; ?>>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- CỘT PHẢI: KHỐI ĐIỀU HƯỚNG / CHỌN PHƯƠNG THỨC / TEM VẬN ĐƠN -->
            <div class="sticky-sidebar">
                <div class="return-card">
                    <?php if (!$isExisting): ?>
                        <!-- TRẠNG THÁI: TẠO MỚI -->
                        <h3 style="margin-top:0;">Tổng quan</h3>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
                            <div>
                                <div style="font-size:0.75rem;text-transform:uppercase;color:#8a8377;font-weight:700;">Dự kiến hoàn</div>
                                <div style="font-size:0.8rem;color:#7c3216;margin-top:2px;">Chờ Admin duyệt</div>
                            </div>
                            <div id="refundAmount" style="font-family:'IBM Plex Mono',monospace;font-size:1.6rem;font-weight:700;color:#a8461f;">0đ</div>
                        </div>
                        <p id="returnErrorMsg" style="color:#b91c1c;font-size:0.85rem;margin:0 0 0.75rem;min-height:1em;text-align:center; font-weight:600;"></p>
                        <button type="submit" form="returnForm" id="returnSubmitBtn" class="submit-btn" disabled>
                            <i class="ph ph-paper-plane-tilt"></i> Gửi yêu cầu trả hàng
                        </button>

                    <?php elseif ($existingStatus === 'pending'): ?>
                        <!-- TRẠNG THÁI: CHỜ DUYỆT -->
                        <div style="text-align: center; padding: 2rem 0;">
                            <i class="ph ph-hourglass" style="font-size: 3.5rem; color: #d97706; margin-bottom: 1rem;"></i>
                            <h3 style="color: #92400e; margin-bottom: 1rem; justify-content:center;">Đang chờ duyệt</h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 0; line-height:1.5;">
                                Yêu cầu trả hàng <b>#<?= htmlspecialchars($existing['return_code']) ?></b> đã được gửi. Shop đang xem xét.
                            </p>
                        </div>

                    <?php elseif ($existingStatus === 'approved' && empty($existingType)): ?>
                        <!-- TRẠNG THÁI: ĐÃ DUYỆT -> YÊU CẦU CHỌN PHƯƠNG THỨC -->
                        <div style="text-align: center; margin-bottom: 1.5rem;">
                            <i class="ph ph-check-circle" style="font-size: 2.5rem; color: #166534; margin-bottom: 0.5rem;"></i>
                            <h3 style="color: #166534; margin: 0; justify-content:center;">Đã được duyệt!</h3>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="save_return_method">
                            <input type="hidden" name="return_id" value="<?php echo $existing['id']; ?>">
                            
                            <label class="method-box selected" id="lblSelf" onclick="selectMethod('self')">
                                <input type="radio" name="return_type" value="self" checked>
                                <b><i class="ph ph-storefront" style="color:#a8461f;"></i> Tự mang đến Shop</b>
                            </label>
                            <div id="shopAddressBox" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem; line-height:1.5;">
                                <b>Địa chỉ Shop:</b><br><?php echo htmlspecialchars($shopAddress); ?><br><b>SĐT:</b> <?php echo htmlspecialchars($shopPhone); ?>
                            </div>

                            <label class="method-box" id="lblShip" onclick="selectMethod('shipping')">
                                <input type="radio" name="return_type" value="shipping">
                                <b><i class="ph ph-truck" style="color:#a8461f;"></i> Gửi qua Bưu điện</b>
                            </label>
                            <div id="carrierBox" style="display:none; margin-bottom:1rem; padding-left:1.6rem;">
                                <select name="carrier" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;">
                                    <option value="SPX">Shopee Express (SPX)</option>
                                    <option value="GHN">Giao Hàng Nhanh (GHN)</option>
                                    <option value="VIETTEL">Viettel Post (VTP)</option>
                                    <option value="GHTK">Giao Hàng Tiết Kiệm (GHTK)</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="submit-btn" style="margin-top:10px;">
                                <i class="ph ph-check"></i> Xác nhận phương thức
                            </button>
                        </form>

                    <?php elseif ($existingType === 'shipping'): ?>
                        <!-- TRẠNG THÁI: ĐÃ CHỌN BƯU ĐIỆN -> HIỂN THỊ TEM VẬN ĐƠN -->
                        <h3 style="color:#166534; margin-top:0; justify-content:center; font-size:1.1rem; display:flex; align-items:center; gap:6px;">
                            <i class="ph-fill ph-check-circle"></i> Mã vận đơn của bạn
                        </h3>
                        <p style="color:#6b7280; font-size:0.85rem; margin-bottom: 1rem; text-align:center;">Vui lòng in và dán lên hộp hàng trước khi gửi.</p>
                        
                        <!-- TEM VẬN ĐƠN -->
                        <div class="waybill-wrap show" id="returnWaybillSection">
                            <div class="wb-label" id="printArea">
                                <!-- Header -->
                                <div class="wb-header">
                                    <div class="wb-logo-area">
                                        <div style="display:flex; flex-direction:column; align-items:flex-start;">
                                            <div style="display:flex; align-items:center; gap:4px; margin-bottom:4px;">
                                                <div style="width:20px; height:20px; background:<?php echo $logoColor; ?>; border-radius:4px; display:flex; align-items:center; justify-content:center; color:white; font-weight:bold; font-size:12px;"><?php echo $logoIcon; ?></div>
                                                <div style="color:<?php echo $logoColor; ?>; font-size:16px; font-weight:bold; letter-spacing:-0.5px;"><?php echo $logoName; ?></div>
                                            </div>
                                            <div style="color:<?php echo $logoColor; ?>; font-size:26px; font-weight:900; line-height:0.9; font-style:italic; letter-spacing:-1px;"><?php echo $logoMain; ?></div>
                                            <div style="color:<?php echo $logoColor; ?>; font-size:8px; font-weight:bold; letter-spacing:2px; margin-left:2px;"><?php echo $logoSub; ?></div>
                                        </div>
                                    </div>
                                    <div class="wb-barcode-area">
                                        <img id="returnBarcodeImg" src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($trackingNo); ?>&code=Code128&translate-esc=on&dpi=96&height=40&text=no" alt="Barcode">
                                        <div class="wb-tracking-text">Mã vận đơn: <span id="returnTrackingNo"><?php echo htmlspecialchars($trackingNo); ?></span></div>
                                        <div class="wb-tracking-text" style="margin-top:2px;">Mã đơn gốc: <span>#<?php echo $orderId; ?></span></div>
                                    </div>
                                </div>

                                <div class="wb-dashed"></div>

                                <div class="wb-addresses">
                                    <div class="wb-col-left">
                                        <div class="wb-addr-title">Từ (Người gửi):</div>
                                        <div class="wb-addr-content">
                                            <b><?php echo htmlspecialchars($order['full_name']); ?></b><br>
                                            <?php echo htmlspecialchars($order['address']); ?><br>
                                            <b>SĐT:</b> <?php echo htmlspecialchars($order['phone']); ?>
                                        </div>
                                    </div>
                                    <div class="wb-col-right">
                                        <div class="wb-addr-title">Đến: <?php echo !empty($order['note']) ? '(Ghi chú: ' . htmlspecialchars($order['note']) . ')' : '(Không có ghi chú)'; ?></div>
                                        <div class="wb-addr-content">
                                            <b>THE ATELIER STORE</b><br>
                                            <?php echo htmlspecialchars($shopAddress); ?><br>
                                            <b>SĐT:</b> <?php echo htmlspecialchars($shopPhone); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="wb-sorting">
                                    <div class="wb-sort-code">SE1-63-04-TU01</div>
                                    <div class="wb-sort-dest">HÀNG HOÀN</div>
                                </div>

                                <div class="wb-body">
                                    <div class="wb-items">
                                        <div>
                                            <b>Nội dung hàng (Tổng SL: <?php echo array_sum(array_column($items, 'quantity')); ?>)</b><br>
                                            Hàng hoàn từ đơn #<?php echo $orderId; ?>
                                        </div>
                                        <div style="margin-top: auto; padding-top: 10px; font-size: 8px; font-style: italic; color:#333; line-height: 1.2; text-align: justify;">
                                            Người gửi phải cam kết hàng hóa có đầy đủ chách hóa đơn, chứng từ, giấy phép cần thiết theo quy định của pháp luật và chịu trách nhiệm trước pháp luật về nội dung hàng hóa này.
                                        </div>
                                    </div>
                                    <div class="wb-qr-area">
                                        <img id="returnQrImg" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($trackingNo); ?>" alt="QR">
                                        <div class="wb-qr-sub"><?php echo substr($trackingNo, 0, 12); ?></div>
                                        <div class="wb-date-text"><?php echo date('d-m-Y'); ?></div>
                                    </div>
                                </div>

                                <div class="wb-footer">
                                    <div class="wb-footer-left">
                                        <div class="wb-cod-title">Tiền thu Người nhận:</div>
                                        <div class="wb-cod-amount">0 VND</div>
                                        <div class="wb-weight">Khối lượng: 500 g</div>
                                    </div>
                                    <div class="wb-footer-right">
                                        <div class="wb-sign-box">
                                            <div class="wb-sign-title">Chữ ký người nhận</div>
                                            <div style="font-size:8px;">Xác nhận hàng nguyên vẹn</div>
                                            <div></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="wb-instructions">
                                    <div class="wb-guide-text">Chỉ dẫn: <span style="text-decoration: underline;">Được đồng kiểm</span></div>
                                    <div class="wb-return-text">Chuyển hoàn sau 3 lần phát</div>
                                </div>

                            </div>
                        </div>

                        <button type="button" class="btn-print no-print" onclick="window.print()" style="margin-top:10px;">
                            <i class="ph ph-printer"></i> In Mã Vận Đơn
                        </button>

                    <?php elseif ($existingType === 'self'): ?>
                        <!-- TRẠNG THÁI: ĐÃ CHỌN TỰ MANG ĐẾN -->
                        <div style="text-align:center;">
                            <i class="ph ph-storefront" style="font-size: 2.5rem; color: #166534; margin-bottom: 0.5rem;"></i>
                            <h3 style="color: #166534; margin-top:0; justify-content:center;">Tự mang đến Shop</h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 1rem;">Vui lòng mang hàng đến địa chỉ:</p>
                            
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 12px; text-align: left; font-size:0.85rem; line-height:1.5;">
                                <div style="font-weight: 700; color: #166534; margin-bottom: 4px;">THE ATELIER STORE</div>
                                <div style="color: #374151;"><?php echo htmlspecialchars($shopAddress); ?></div>
                                <div style="color: #374151; margin-top: 4px;"><b>SĐT:</b> <?php echo htmlspecialchars($shopPhone); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</main>

<script>
// JS DÀNH CHO FORM GỬI YÊU CẦU BAN ĐẦU
(function () {
    var form       = document.getElementById('returnForm');
    if (!form || form.classList.contains('form-readonly')) return; 

    var reasonSel  = document.getElementById('returnReason');
    var reasonGrp  = document.getElementById('reasonOtherGroup');
    var errMsg     = document.getElementById('returnErrorMsg');
    var amtDisplay = document.getElementById('refundAmount');
    var bankWrap   = document.getElementById('bankSelectWrap');
    var bankSel    = document.getElementById('refundBankId');
    var pinInput   = document.getElementById('returnPin');
    var submitBtn  = document.getElementById('returnSubmitBtn');

    // LÝ DO KHÁC
    reasonSel.addEventListener('change', function () {
        var isOther = this.value === 'Khác';
        reasonGrp.hidden = !isOther;
        if (!isOther) document.getElementById('reasonDetail').value = '';
    });

    // PHƯƠNG THỨC HOÀN TIỀN
    document.querySelectorAll('.method-box').forEach(function (opt) {
        opt.addEventListener('click', function () {
            document.querySelectorAll('input[name="refund_method"]').forEach(r => r.closest('.method-box').classList.remove('selected'));
            this.classList.add('selected');
            var method = this.dataset.method;
            bankWrap.hidden = (method !== 'bank');
            if (bankSel) bankSel.required = (method === 'bank');
        });
    });

    // TÍNH TIỀN
    function recalc() {
        var total = 0;
        document.querySelectorAll('.item-check').forEach(function (cb) {
            var label = cb.closest('.return-item');
            var qty   = label.querySelector('.item-qty');
            if (cb.checked) {
                label.classList.add('selected');
                qty.disabled = false;
                var price = parseFloat(cb.dataset.price) || 0;
                var q = parseInt(qty.value, 10) || 1;
                total += price * q;
            } else {
                label.classList.remove('selected');
                qty.disabled = true;
                qty.value = 1;
            }
        });
        if(amtDisplay) amtDisplay.textContent = total.toLocaleString('vi-VN') + 'đ';
        submitBtn.disabled = (total === 0);
    }
    document.querySelectorAll('.item-check, .item-qty').forEach(function (el) {
        el.addEventListener('input', recalc);
        el.addEventListener('change', recalc);
    });

    // UPLOAD ẢNH
    var MAX_FILES = 5, MAX_SIZE = 5 * 1024 * 1024, ALLOWED = ['image/jpeg','image/png','image/webp','image/gif'];
    var fileInput = document.getElementById('returnImages');
    var dropzone = document.getElementById('dropzone');
    var previewGrid = document.getElementById('uploaderPreview');
    var filesList = [];

    function renderPreview() {
        previewGrid.innerHTML = '';
        filesList.forEach(function (file, index) {
            var card = document.createElement('div'); card.className = 'preview-card';
            var img = document.createElement('img');
            var reader = new FileReader(); reader.onload = e => img.src = e.target.result; reader.readAsDataURL(file);
            card.appendChild(img);
            var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'preview-remove'; btn.innerHTML = '×';
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                filesList.splice(index, 1); syncInput(); renderPreview();
            });
            card.appendChild(btn); previewGrid.appendChild(card);
        });
    }
    function syncInput() {
        var dt = new DataTransfer();
        filesList.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
    }
    function handleFiles(newFiles) {
        for (var i = 0; i < newFiles.length; i++) {
            var f = newFiles[i];
            if (filesList.length >= MAX_FILES) break;
            if (ALLOWED.indexOf(f.type) === -1 || f.size > MAX_SIZE) continue;
            if (!filesList.some(x => x.name === f.name && x.size === f.size)) filesList.push(f);
        }
        syncInput(); renderPreview();
    }
    if (fileInput && !fileInput.disabled) {
        fileInput.addEventListener('change', function() { handleFiles(Array.from(this.files)); });
        dropzone.addEventListener('drop', function(e) { e.preventDefault(); dropzone.classList.remove('dragover'); handleFiles(Array.from(e.dataTransfer.files || [])); });
        dropzone.addEventListener('dragover', function(e) { e.preventDefault(); dropzone.classList.add('dragover'); });
        dropzone.addEventListener('dragleave', function(e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
    }

    // PIN INPUT
    if (pinInput && !pinInput.disabled) {
        pinInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            this.classList.remove('error');
        });
    }

    // SUBMIT FORM
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errMsg.textContent = '';

        var checked = document.querySelectorAll('.item-check:checked');
        if (checked.length === 0) { errMsg.textContent = 'Vui lòng chọn sản phẩm muốn trả.'; return; }
        if (!reasonSel.value) { errMsg.textContent = 'Vui lòng chọn lý do.'; return; }
        if (reasonSel.value === 'Khác' && !document.getElementById('reasonDetail').value.trim()) { errMsg.textContent = 'Vui lòng nhập lý do cụ thể.'; return; }
        if (filesList.length === 0) { errMsg.textContent = 'Vui lòng tải lên ít nhất 1 ảnh sản phẩm.'; return; }
        
        var method = form.querySelector('input[name="refund_method"]:checked').value;
        if (method === 'bank' && (!bankSel || !bankSel.value)) { errMsg.textContent = 'Vui lòng chọn ngân hàng nhận tiền.'; return; }

        var pinVal = (pinInput?.value || '').trim();
        if (!/^\d{6}$/.test(pinVal)) {
            errMsg.textContent = 'Vui lòng nhập mã PIN 6 số.';
            if(pinInput) { pinInput.classList.add('error'); pinInput.focus(); }
            return;
        }

        var formData = new FormData(form);

        var old = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="ph ph-spinner-gap" style="animation: spin 1s linear infinite;"></i> Đang gửi...';
        
        var style = document.createElement('style');
        style.innerHTML = '@keyframes spin { 100% { transform: rotate(360deg); } }';
        document.head.appendChild(style);

        fetch('ajax/submit_return.php', {
            method: 'POST',
            body: formData
        })
        .then(async r => {
            var text = await r.text();
            try { return JSON.parse(text); } 
            catch (e) { console.error(text); alert("Lỗi Server:\n" + text.substring(0, 500)); throw new Error("Err"); }
        })
        .then(data => {
            if (data.ok) {
                window.location.reload();
            } else {
                errMsg.textContent = data.msg || 'Có lỗi xảy ra.';
                submitBtn.disabled = false;
                submitBtn.innerHTML = old;
                if (/PIN/i.test(data.msg || '') && pinInput) {
                    pinInput.classList.add('error'); pinInput.focus();
                }
            }
        })
        .catch(err => {
            if (err.message !== "Err") errMsg.textContent = 'Lỗi kết nối máy chủ.';
            submitBtn.disabled = false;
            submitBtn.innerHTML = old;
        });
    });

    recalc();
})();

// JS DÀNH CHO PHẦN CHỌN PHƯƠNG THỨC (KHI ĐÃ DUYỆT)
function selectMethod(method) {
    var lblSelf = document.getElementById('lblSelf');
    var lblShip = document.getElementById('lblShip');
    var shopBox = document.getElementById('shopAddressBox');
    var carrierBox = document.getElementById('carrierBox');

    if (!lblSelf || !lblShip) return;

    if (method === 'self') {
        lblSelf.classList.add('selected');
        lblShip.classList.remove('selected');
        shopBox.style.display = 'block';
        carrierBox.style.display = 'none';
    } else {
        lblShip.classList.add('selected');
        lblSelf.classList.remove('selected');
        shopBox.style.display = 'none';
        carrierBox.style.display = 'block';
    }
}
</script>

<?php include 'includes/footer.php'; ?>