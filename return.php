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

if ($order['status'] !== 'delivered') {
    $_SESSION['flash_error'] = 'Chỉ có thể trả hàng với đơn đã giao thành công.';
    header('Location: account.php');
    exit;
}

// Thời hạn 7 ngày
$deliveredTime = strtotime($order['delivered_at'] ?? $order['updated_at'] ?? $order['created_at']);
$daysLeft      = 7 - floor((time() - $deliveredTime) / 86400);
if ($daysLeft <= 0) {
    $_SESSION['flash_error'] = 'Đã quá thời hạn trả hàng (7 ngày kể từ khi giao).';
    header('Location: account.php');
    exit;
}

// Check request active
$stmt = $pdo->prepare("
    SELECT id, return_code, status, created_at FROM return_requests
    WHERE order_id = ? AND status IN ('pending','approved','refunding')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$orderId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
$isExisting = !empty($existing);

// Lấy items
$stmt = $pdo->prepare("
    SELECT oi.*, p.name, p.image_url
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Linked banks
$stmt = $pdo->prepare("
    SELECT id, bank_name, account_no, account_name, is_default
    FROM linked_banks WHERE user_id = ?
    ORDER BY is_default DESC, id DESC
");
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
?>
<?php include 'includes/header.php'; ?>

<style>
    .return-page { padding: 40px 0; background: #f8f9fa; min-height: 80vh; }
    
    /* Layout 2 cột */
    .return-layout { 
        display: grid; 
        grid-template-columns: 1fr 400px; 
        gap: 24px; 
        align-items: start; 
        max-width: 1200px; 
        margin: 0 auto; 
    }

    .return-card {
        background: #fff; border-radius: 12px; padding: 1.5rem 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.04); border: 1px solid #efeae0;
    }
    
    .sticky-sidebar { position: sticky; top: 20px; }

    .return-section { margin-bottom: 2rem; }
    .return-section h3 {
        font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em;
        color: #8a8377; margin: 0 0 0.75rem; font-weight: 700;
    }

    /* Item chọn trả */
    .return-item {
        display: flex; align-items: center; gap: 1rem; padding: 0.85rem;
        border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.6rem;
        transition: all 0.2s; cursor: pointer;
    }
    .return-item.selected { border-color: #a8461f; background: #fdf7f3; }
    .return-item input[type=checkbox] { transform: scale(1.3); accent-color: #a8461f; cursor: pointer; }
    .return-item img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; }
    .return-item .info { flex: 1; }
    .return-item .info .name { font-weight: 600; font-size: 0.9rem; color: #1c1b19; }
    .return-item .info .meta { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }
    .return-item .qty-input {
        width: 60px; padding: 6px 8px; border: 1px solid #d1d5db;
        border-radius: 6px; font-size: 0.85rem; text-align: center;
    }

    /* Lựa chọn phương thức */
    .method-box {
        display: block; padding: 1rem; border: 2px solid #e5e7eb;
        border-radius: 10px; cursor: pointer; margin-bottom: 0.75rem;
        transition: all 0.2s; background: #fff;
    }
    .method-box:hover { border-color: #a8461f55; }
    .method-box.selected { border-color: #a8461f; background: #fdf7f3; }
    .method-box input[type=radio] { accent-color: #a8461f; margin-right: 0.6rem; transform: scale(1.2); }
    
    /* PIN */
    #returnPin {
        width: 100%; padding: 14px 18px; border: 2px solid #d1d5db; border-radius: 10px;
        font-size: 1.5rem; letter-spacing: 0.5em; text-align: center;
        font-family: 'IBM Plex Mono', monospace; background: #fff;
    }
    #returnPin:focus { outline: none; border-color: #a8461f; box-shadow: 0 0 0 3px rgba(168,70,31,0.12); }
    #returnPin.error { border-color: #dc2626; animation: shakePin 0.35s; }
    @keyframes shakePin { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-6px); } 75% { transform: translateX(6px); } }

    /* Image Uploader */
    .uploader-wrap { background: #faf8f4; border: 1px solid #efeae0; border-radius: 12px; padding: 1.25rem; }
    .dropzone {
        position: relative; border: 2px dashed #d1d5db; border-radius: 12px;
        padding: 2rem 1rem; text-align: center; cursor: pointer; background: #fff; transition: all 0.2s;
    }
    .dropzone:hover, .dropzone.dragover { border-color: #a8461f; background: #fdf7f3; }
    .dropzone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .uploader-preview { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 0.75rem; margin-top: 1rem; }
    .preview-card { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
    .preview-card img { width: 100%; height: 100%; object-fit: cover; }
    .preview-remove { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: rgba(185,28,28,0.9); color: #fff; border: none; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }

    /* Trạng thái khóa (phải chọn phương thức) */
    .submit-btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; transition: all 0.2s; }
    .submit-btn:disabled { background: #e5e7eb !important; color: #9ca3af !important; cursor: not-allowed; box-shadow: none !important; }
    .submit-btn:not(:disabled) { background: #a8461f; color: #fff; cursor: pointer; box-shadow: 0 4px 12px rgba(168,70,31,0.3); }
    .submit-btn:not(:disabled):hover { background: #8f3a18; transform: translateY(-1px); }

    /* ============================================================
       WAYBILL (MÃ VẬN ĐƠN) - Y HỆT BÊN ADMIN
       ============================================================ */
    .waybill-wrap { 
        background: #fff; padding: 15px; border: 1px solid #ccc; 
        margin-top: 20px; display: none; max-width: 500px; 
        box-sizing: border-box; margin-left: auto; margin-right: auto;
    }
    
    .wb-label { 
        border: 2px solid #000; padding: 15px; font-family: Arial, Helvetica, sans-serif; 
        color: #000; background: #fff; position: relative; width: 100%; box-sizing: border-box; 
    }
    
    .wb-header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
    .wb-logo { font-size: 28px; font-weight: 900; color: #ee4d2d; text-transform: uppercase; line-height: 1; flex-shrink: 0; }
    .wb-barcode { text-align: center; display: flex; flex-direction: column; align-items: center; flex: 1; padding-left: 15px; }
    .wb-barcode img { width: 100%; max-width: 280px; height: 40px; object-fit: contain; }
    .wb-tracking-no { font-size: 14px; font-weight: bold; margin-top: 4px; letter-spacing: 0.5px; }
    
    .wb-cols { display: flex; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; gap: 10px; }
    .wb-col { flex: 1; font-size: 12px; line-height: 1.4; word-break: break-word; }
    .wb-col:first-child { border-right: 1px dashed #000; padding-right: 10px; }
    
    .wb-sort-code { text-align: center; font-size: 22px; font-weight: bold; border-bottom: 1px solid #000; padding: 8px 0; margin-bottom: 10px; letter-spacing: 2px; }
    
    .wb-body { display: flex; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; gap: 10px; }
    .wb-items { flex: 1; font-size: 12px; line-height: 1.4; padding-right: 10px; border-right: 1px dashed #000; }
    .wb-qr { width: 110px; text-align: center; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .wb-qr img { width: 100px; height: 100px; object-fit: contain; }
    .wb-qr-code { font-size: 11px; font-weight: bold; margin-top: 4px; word-break: break-all; }
    
    .wb-footer { display: flex; justify-content: space-between; align-items: flex-end; }
    .wb-sign { border: 1px solid #000; padding: 5px 10px; font-size: 10px; text-align: center; min-width: 120px; }
    
    .btn-print { background: #166534; color: #fff; padding: 10px 20px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; }
    .btn-print:hover { background: #15803d; }

    @media print {
        body * { visibility: hidden; }
        #returnWaybillSection, #returnWaybillSection * { visibility: visible; }
        #returnWaybillSection { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; }
        .waybill-wrap { border: none; max-width: 100%; padding: 0; }
        .no-print { display: none !important; }
    }

    @media (max-width: 992px) {
        .return-layout { grid-template-columns: 1fr; }
        .sticky-sidebar { position: static; }
    }
</style>

<main class="return-page">
    <div style="max-width:1200px; margin:0 auto 20px; padding:0 15px;" class="no-print">
        <a href="account.php" style="color:#6b7280;text-decoration:none;font-size:14px;">← Quay lại tài khoản</a>
    </div>

    <div style="padding: 0 15px;" class="no-print">
        <div class="return-layout" id="mainLayout">
            
            <!-- CỘT TRÁI: FORM ĐIỀN THÔNG TIN -->
            <div class="return-card">
                <h1 style="margin:0 0 0.5rem;font-size:1.5rem;font-weight:700;color:#1c1b19;">
                    🔄 Yêu cầu trả hàng / Hoàn tiền
                </h1>
                <p style="color:#6b7280;font-size:0.9rem;margin:0 0 1.5rem;">
                    Đơn hàng <b>#<?php echo (int)$order['id']; ?></b> · Còn <b style="color:#a8461f;"><?php echo $daysLeft; ?> ngày</b> để trả hàng.
                </p>

                <!-- THÔNG BÁO LỖI & NÚT IN LẠI MÃ VẬN ĐƠN -->
                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:1.5rem;font-size:0.9rem;">
                        <?php echo sanitize($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($isExisting): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <strong>Đơn hàng này đã có yêu cầu trả hàng đang xử lý.</strong><br>
                            <span style="font-size: 0.85rem;">Mã yêu cầu: <?= htmlspecialchars($existing['return_code']) ?></span>
                        </div>
                        <button type="button" class="btn-print" onclick="printExistingWaybill('<?= htmlspecialchars($existing['return_code']) ?>')" style="margin: 0;">
                            🖨️ In lại mã vận đơn
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (!$hasPin && !$isExisting): ?>
                    <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:14px 16px;border-radius:10px;margin-bottom:1.5rem;font-size:0.9rem;">
                        ⚠️ Bạn chưa thiết lập <b>mã PIN</b>. Vui lòng vào <a href="wallet.php" style="color:#a8461f;font-weight:700;">Ví Atelier Pay</a> để thiết lập.
                    </div>
                <?php endif; ?>

                <form id="returnForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">

                    <!-- 1. SẢN PHẨM -->
                    <div class="return-section">
                        <h3>1. Chọn sản phẩm muốn trả *</h3>
                        <?php foreach ($items as $it): ?>
                            <label class="return-item <?= $isExisting ? 'disabled' : '' ?>">
                                <input type="checkbox" class="item-check" name="items[]"
                                       value="<?php echo (int)$it['id']; ?>"
                                       data-price="<?php echo (float)$it['price']; ?>"
                                       <?= $isExisting ? 'disabled' : '' ?>>
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
                                    <input type="number" class="qty-input item-qty" name="qty[<?php echo (int)$it['id']; ?>]"
                                           value="1" min="1" max="<?php echo (int)$it['quantity']; ?>" disabled>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- 2. LÝ DO -->
                    <div class="return-section">
                        <h3>2. Lý do trả hàng *</h3>
                        <select name="reason" id="returnReason" required style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;background:#fff;" <?= $isExisting ? 'disabled' : '' ?>>
                            <option value="">-- Chọn lý do --</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?php echo htmlspecialchars($r, ENT_QUOTES); ?>"><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="reasonOtherGroup" hidden style="margin-top:0.75rem;">
                            <textarea name="reason_detail" id="reasonDetail" rows="3" maxlength="500" placeholder="Mô tả chi tiết..." style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;resize:vertical;box-sizing:border-box;" <?= $isExisting ? 'disabled' : '' ?>></textarea>
                        </div>
                    </div>

                    <!-- 3. ẢNH -->
                    <div class="return-section">
                        <h3>3. Ảnh sản phẩm thực tế *</h3>
                        <div class="uploader-wrap <?= $isExisting ? 'disabled' : '' ?>">
                            <div class="dropzone" id="dropzone" <?= $isExisting ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                                <input type="file" id="returnImages" name="images[]" accept="image/*" multiple <?= $isExisting ? 'disabled' : '' ?>>
                                <div style="font-size:2rem;color:#a8461f;margin-bottom:0.5rem;">📸</div>
                                <div style="font-weight:700;font-size:0.95rem;color:#1c1b19;">Kéo ảnh vào đây hoặc bấm để chọn</div>
                                <div style="font-size:0.8rem;color:#8a8377;margin-top:4px;">Tối đa 5 ảnh · ≤ 5MB/ảnh</div>
                            </div>
                            <div class="uploader-preview" id="uploaderPreview"></div>
                        </div>
                    </div>

                    <!-- 4. HOÀN TIỀN -->
                    <div class="return-section">
                        <h3>4. Phương thức hoàn tiền *</h3>
                        <label class="method-box selected" data-method="wallet" <?= $isExisting ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                            <input type="radio" name="refund_method" value="wallet" checked <?= $isExisting ? 'disabled' : '' ?>>
                            <b>💰 Ví Atelier Pay</b>
                            <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Số dư: <b><?php echo number_format($walletBalance, 0, ',', '.'); ?>đ</b> · Hoàn ngay sau khi duyệt</div>
                        </label>
                        <label class="method-box" data-method="bank" <?= $isExisting ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                            <input type="radio" name="refund_method" value="bank" <?= $isExisting ? 'disabled' : '' ?>>
                            <b>🏦 Chuyển khoản ngân hàng</b>
                            <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Hoàn về tài khoản đã liên kết (1-3 ngày)</div>
                        </label>
                        
                        <div id="bankSelectWrap" hidden style="margin-top:0.5rem;padding-left:1.6rem;">
                            <?php if (empty($banks)): ?>
                                <div style="background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;font-size:0.85rem;">
                                    ⚠ Chưa liên kết ngân hàng. <a href="wallet.php" style="color:#a8461f;font-weight:600;">Liên kết ngay →</a>
                                </div>
                            <?php else: ?>
                                <select name="refund_bank_id" id="refundBankId" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;" <?= $isExisting ? 'disabled' : '' ?>>
                                    <?php foreach ($banks as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>">
                                            <?php echo sanitize($b['bank_name']); ?> · •••• <?php echo substr($b['account_no'], -4); ?> <?php echo $b['is_default'] ? '(Mặc định)' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 5. PIN -->
                    <div class="return-section">
                        <h3>5. Xác nhận mã PIN *</h3>
                        <div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem;">
                            <label style="display:block;font-size:0.88rem;color:#374151;margin-bottom:10px;font-weight:600;">🔐 Nhập mã PIN 6 số (Ví Atelier Pay)</label>
                            <input type="password" name="pin" id="returnPin" inputmode="numeric" maxlength="6" placeholder="••••••" autocomplete="off" <?php echo (!$hasPin || $isExisting) ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                </form>
            </div>

            <!-- CỘT PHẢI: PHƯƠNG THỨC GỬI HÀNG & TỔNG TIỀN -->
            <div class="sticky-sidebar">
                <div class="return-card">
                    <h3 style="margin-top:0;">6. Phương thức gửi hàng *</h3>
                    
                    <label class="method-box" id="optSelf" <?= $isExisting ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                        <input type="radio" name="return_type" value="self" form="returnForm" <?= $isExisting ? 'disabled' : '' ?>>
                        <b>Tự mang đến Shop</b>
                        <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Giao trực tiếp, miễn phí</div>
                    </label>
                    
                    <div id="selfInfo" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:8px; margin-bottom:1rem; font-size:0.85rem; line-height:1.5;">
                        <b>Địa chỉ Shop:</b><br>
                        <?php echo htmlspecialchars($shopAddress); ?><br>
                        <b>SĐT:</b> <?php echo htmlspecialchars($shopPhone); ?>
                    </div>

                    <label class="method-box" id="optShip" <?= $isExisting ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                        <input type="radio" name="return_type" value="shipping" form="returnForm" <?= $isExisting ? 'disabled' : '' ?>>
                        <b>Gửi qua Bưu điện</b>
                        <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">Tạo mã vận đơn gửi về Shop</div>
                    </label>

                    <div id="shipInfo" style="display:none; margin-bottom:1.5rem;">
                        <label style="display:block;font-size:0.8rem;color:#374151;margin-bottom:6px;font-weight:600;">Chọn đơn vị vận chuyển:</label>
                        <select name="carrier" id="carrierSelect" form="returnForm" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;" <?= $isExisting ? 'disabled' : '' ?>>
                            <option value="SPX">Shopee Express (SPX)</option>
                            <option value="GHN">Giao Hàng Nhanh (GHN)</option>
                            <option value="VIETTEL">Viettel Post (VTP)</option>
                            <option value="GHTK">Giao Hàng Tiết Kiệm (GHTK)</option>
                        </select>
                    </div>

                    <hr style="border:0; border-top:1px dashed #efeae0; margin:1.25rem 0;">

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
                        <div>
                            <div style="font-size:0.75rem;text-transform:uppercase;color:#8a8377;font-weight:700;">Dự kiến hoàn</div>
                            <div style="font-size:0.8rem;color:#7c3216;margin-top:2px;">Sau khi duyệt</div>
                        </div>
                        <div id="refundAmount" style="font-family:'IBM Plex Mono',monospace;font-size:1.6rem;font-weight:700;color:#a8461f;">0đ</div>
                    </div>

                    <p id="returnErrorMsg" style="color:#b91c1c;font-size:0.85rem;margin:0 0 0.75rem;min-height:1em;text-align:center;"></p>

                    <button type="submit" form="returnForm" id="returnSubmitBtn" class="submit-btn" disabled>
                        <?= $isExisting ? 'Đã gửi yêu cầu' : 'Vui lòng chọn P.Thức gửi hàng' ?>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- GIAO DIỆN SAU KHI GỬI THÀNH CÔNG (HOẶC KHI BẤM IN LẠI MÃ) -->
    <div id="returnSuccessSection" style="display: none; max-width: 600px; margin: 0 auto; padding: 0 15px;">
        
        <!-- Khung thông báo thành công -->
        <div style="background:#ecfdf5; border:1px solid #bbf7d0; padding:1.5rem; border-radius:12px; text-align:center; margin-bottom:1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
            <h2 style="color:#166534; margin-top:0; font-size:1.3rem;" id="successTitle">Gửi yêu cầu thành công!</h2>
            <p style="color:#374151; font-size:0.95rem; margin-bottom: 0;" id="successMsgDesc">Vui lòng in Mã vận đơn dưới đây, dán lên hộp hàng và mang ra bưu cục gửi về Shop.</p>
            
            <!-- KHUNG HIỂN THỊ MÃ VẬN ĐƠN ẢO -->
            <div id="displayTrackingNoWrap" style="display:none; margin: 15px auto 20px; padding: 12px; background: #fff; border: 2px dashed #166534; border-radius: 8px; max-width: 320px;">
                <div style="font-size: 0.85rem; color: #374151; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Mã vận đơn của bạn</div>
                <div id="displayTrackingNo" style="font-size: 1.6rem; font-weight: bold; color: #166534; letter-spacing: 1px; font-family: 'IBM Plex Mono', monospace; margin: 4px 0;">SPX123456</div>
                <div style="font-size: 0.75rem; color: #6b7280;">(Chụp lại mã này để tra cứu)</div>
            </div>

            <button type="button" class="btn-print no-print" onclick="window.print()" id="btnPrintWaybill" style="display:none;">
                🖨️ In Mã Vận Đơn
            </button>
            <a href="account.php" class="no-print" style="display:inline-block; margin-left:10px; padding:10px 20px; background:#fff; border:1px solid #d1d5db; border-radius:6px; color:#374151; font-weight:bold; text-decoration:none;">Về Tài khoản</a>
        </div>

        <!-- TEM VẬN ĐƠN (WAYBILL) -->
        <div class="waybill-wrap" id="returnWaybillSection">
            <div class="wb-label" id="printArea">
                <div class="wb-header">
                    <div class="wb-logo" id="wbLogo">SPX</div>
                    <div class="wb-barcode">
                        <img id="returnBarcodeImg" src="" alt="Barcode">
                        <div class="wb-tracking-no" id="returnTrackingNo">SPXVN000000000</div>
                        <div style="font-size:10px; color:#555; margin-top:2px;">Mã đơn gốc: #<?php echo (int)$order['id']; ?></div>
                    </div>
                </div>

                <div class="wb-cols">
                    <div class="wb-col">
                        <div style="font-size:10px; color:#555; margin-bottom:2px;">TỪ (Người gửi):</div>
                        <b><?php echo htmlspecialchars($order['full_name']); ?></b><br>
                        <?php echo htmlspecialchars($order['phone']); ?><br>
                        <?php echo htmlspecialchars($order['address']); ?>
                    </div>
                    <div class="wb-col">
                        <div style="font-size:10px; color:#555; margin-bottom:2px;">ĐẾN (Shop):</div>
                        <b>THE ATELIER STORE</b><br>
                        <?php echo htmlspecialchars($shopAddress); ?><br>
                        <b>SĐT:</b> <?php echo htmlspecialchars($shopPhone); ?>
                    </div>
                </div>

                <div class="wb-sort-code">HÀNG HOÀN</div>

                <div class="wb-body">
                    <div class="wb-items">
                        <b>Nội dung (Tổng SL: <span id="returnTotalQty">0</span>)</b><br>
                        <div id="returnItemNames" style="margin-top:5px; color:#333;"></div>
                        <div style="margin-top:8px; font-size:10px; font-style:italic; color:#555;">
                            Người gửi chịu trách nhiệm về hàng hóa. Bưu gửi không được chứa hàng cấm, hàng dễ cháy nổ.
                        </div>
                    </div>
                    <div class="wb-qr">
                        <img id="returnQrImg" src="" alt="QR">
                        <div class="wb-qr-code" id="returnQrCode">SPX000</div>
                        <div style="font-size:10px; margin-top:4px;">Ngày: <?php echo date('d-m-Y'); ?></div>
                    </div>
                </div>

                <div class="wb-footer">
                    <div>
                        <div style="font-size:11px; color:#555;">Loại đơn:</div>
                        <div class="wb-cod" style="font-size:16px;">HÀNG HOÀN</div>
                        <div style="font-size:11px; margin-top:6px;">KL: <span id="wbWeightDisplay">500g</span></div>
                    </div>
                    <div class="wb-sign">
                        <div style="margin-bottom:25px;">Chữ ký người nhận</div>
                        <div style="font-size:8px; color:#555;">(Xác nhận hàng nguyên vẹn)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<script>
(function () {
    var form       = document.getElementById('returnForm');
    var reasonSel  = document.getElementById('returnReason');
    var reasonGrp  = document.getElementById('reasonOtherGroup');
    var errMsg     = document.getElementById('returnErrorMsg');
    var amtDisplay = document.getElementById('refundAmount');
    var bankWrap   = document.getElementById('bankSelectWrap');
    var bankSel    = document.getElementById('refundBankId');
    var pinInput   = document.getElementById('returnPin');
    var submitBtn  = document.getElementById('returnSubmitBtn');
    
    // Phương thức gửi hàng
    var returnTypeRadios = document.querySelectorAll('input[name="return_type"]');
    var selfInfo  = document.getElementById('selfInfo');
    var shipInfo  = document.getElementById('shipInfo');
    var optSelf   = document.getElementById('optSelf');
    var optShip   = document.getElementById('optShip');
    var selectedReturnType = null;

    // 1. CHỌN PHƯƠNG THỨC GỬI HÀNG
    returnTypeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            selectedReturnType = this.value;
            
            optSelf.classList.remove('selected');
            optShip.classList.remove('selected');
            selfInfo.style.display = 'none';
            shipInfo.style.display = 'none';

            if (selectedReturnType === 'self') {
                optSelf.classList.add('selected');
                selfInfo.style.display = 'block';
            } else {
                optShip.classList.add('selected');
                shipInfo.style.display = 'block';
            }

            if (!submitBtn.hasAttribute('data-existing')) {
                submitBtn.disabled = false;
                submitBtn.textContent = '📤 Gửi yêu cầu trả hàng';
            }
        });
    });

    // 2. LÝ DO KHÁC
    reasonSel.addEventListener('change', function () {
        var isOther = this.value === 'Khác';
        reasonGrp.hidden = !isOther;
        if (!isOther) document.getElementById('reasonDetail').value = '';
    });

    // 3. PHƯƠNG THỨC HOÀN TIỀN
    document.querySelectorAll('.method-box').forEach(function (opt) {
        if (opt.id === 'optSelf' || opt.id === 'optShip') return;
        opt.addEventListener('click', function () {
            if (this.style.pointerEvents === 'none') return; // Bỏ qua nếu đang bị khóa
            document.querySelectorAll('input[name="refund_method"]').forEach(r => r.closest('.method-box').classList.remove('selected'));
            this.classList.add('selected');
            var method = this.dataset.method;
            bankWrap.hidden = (method !== 'bank');
            if (bankSel) bankSel.required = (method === 'bank');
        });
    });

    // 4. TÍNH TIỀN
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
        amtDisplay.textContent = total.toLocaleString('vi-VN') + 'đ';
    }
    document.querySelectorAll('.item-check, .item-qty').forEach(function (el) {
        el.addEventListener('input', recalc);
        el.addEventListener('change', recalc);
    });

    // 5. UPLOAD ẢNH (Bỏ qua nếu disabled)
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

    // 6. PIN INPUT
    if (pinInput && !pinInput.disabled) {
        pinInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            this.classList.remove('error');
        });
    }

    // 7. SUBMIT FORM
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errMsg.textContent = '';

            if (!selectedReturnType) { errMsg.textContent = 'Vui lòng chọn Phương thức gửi hàng.'; return; }
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
            formData.append('return_type', selectedReturnType);
            
            var carrier = 'SPX';
            if (selectedReturnType === 'shipping') {
                carrier = document.getElementById('carrierSelect').value;
                formData.append('carrier', carrier);
            }

            var old = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Đang xử lý...';

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
                    // Ẩn form, hiện thông báo
                    document.getElementById('mainLayout').style.display = 'none';
                    document.querySelector('a[href="account.php"]').style.display = 'none';
                    var successSec = document.getElementById('returnSuccessSection');
                    successSec.style.display = 'block';
                    window.scrollTo({ top: 0, behavior: 'smooth' });

                    if (selectedReturnType === 'shipping') {
                        // === TẠO MÃ VẬN ĐƠN ẢO Y HỆT BÊN ADMIN ===
                        var prefix = carrier === 'SPX' ? 'SPXVN' : (carrier === 'GHN' ? 'GHN' : (carrier === 'VIETTEL' ? 'VTP' : 'GHTK'));
                        var trackingNo = prefix + Math.floor(100000000000 + Math.random() * 900000000000);

                        renderWaybill(trackingNo, carrier, checked);
                    } else {
                        // Nếu tự mang đến, ẩn mã vận đơn và tem
                        document.getElementById('displayTrackingNoWrap').style.display = 'none';
                        document.getElementById('returnWaybillSection').style.display = 'none';
                        document.getElementById('successMsgDesc').innerHTML = 'Vui lòng mang sản phẩm đến địa chỉ Shop dưới đây để được xử lý:<br><br><b>' + <?php echo json_encode($shopAddress); ?> + '</b><br>SĐT: ' + <?php echo json_encode($shopPhone); ?>;
                    }
                } else {
                    errMsg.textContent = data.msg || 'Có lỗi xảy ra.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = old;
                    if (/PIN/i.test(data.msg || '') && pinInput) {
                        pinInput.classList.add('error'); pinInput.focus();
                    }
                }
            })
            .catch(err => {
                if (err.message !== "Err") errMsg.textContent = 'Lỗi kết nối máy chủ.';
                submitBtn.disabled = false;
                submitBtn.textContent = old;
            });
        });
    }

    // HÀM HIỂN THỊ TEM VẬN ĐƠN
    window.renderWaybill = function(trackingNo, carrier, checkedItems) {
        document.getElementById('displayTrackingNoWrap').style.display = 'block';
        document.getElementById('displayTrackingNo').textContent = trackingNo;

        var wbLogo = document.getElementById('wbLogo');
        wbLogo.textContent = carrier === 'VIETTEL' ? 'VTP' : carrier;
        wbLogo.style.color = carrier === 'SPX' ? '#ee4d2d' : (carrier === 'GHN' ? '#0055aa' : '#000');

        document.getElementById('returnTrackingNo').textContent = trackingNo;
        document.getElementById('returnBarcodeImg').src = 'https://barcode.tec-it.com/barcode.ashx?data=' + trackingNo + '&code=Code128&translate-esc=on&dpi=96&height=40&text=no';
        document.getElementById('returnQrCode').textContent = trackingNo.substring(0, 12);
        document.getElementById('returnQrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + trackingNo;
        
        document.getElementById('btnPrintWaybill').style.display = 'inline-flex';
        document.getElementById('returnWaybillSection').style.display = 'block';
        
        var itemNames = [];
        var totalQty = 0;
        
        // Nếu truyền vào element checkbox (lúc submit mới)
        if (checkedItems && checkedItems.length) {
            checkedItems.forEach(cb => {
                var label = cb.closest('.return-item');
                var qty = parseInt(label.querySelector('.item-qty').value);
                itemNames.push(label.querySelector('.name').textContent + ' (SL: ' + qty + ')');
                totalQty += qty;
            });
        } else {
            // Nếu hiện lại (không có checkbox) -> Chỉ hiện text chung
            itemNames.push('Sản phẩm hoàn trả từ đơn hàng #<?= (int)$order['id'] ?>');
            totalQty = 1;
        }
        
        document.getElementById('returnItemNames').innerHTML = itemNames.join('<br>');
        document.getElementById('returnTotalQty').textContent = totalQty;
    };

    // HÀM IN LẠI MÃ VẬN ĐƠN CŨ
    window.printExistingWaybill = function(returnCode) {
        document.getElementById('mainLayout').style.display = 'none';
        document.querySelector('a[href="account.php"]').style.display = 'none';
        var successSec = document.getElementById('returnSuccessSection');
        successSec.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });

        document.getElementById('successTitle').textContent = 'Mã vận đơn của bạn';
        document.getElementById('successMsgDesc').textContent = 'Đây là mã vận đơn đã tạo trước đó. Vui lòng in ra và dán lên gói hàng.';

        renderWaybill(returnCode, 'SPX', null);
    };

    // Khóa nút Submit nếu là đơn đã tồn tại
    <?php if ($isExisting): ?>
    submitBtn.disabled = true;
    submitBtn.setAttribute('data-existing', '1');
    <?php endif; ?>

    recalc();
})();
</script>

<?php include 'includes/footer.php'; ?>