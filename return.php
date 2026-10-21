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

if ($existing) {
    $_SESSION['flash_error'] = 'Đơn hàng này đã có yêu cầu trả hàng đang xử lý.';
    header('Location: account.php');
    exit;
}

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
?>
<?php include 'includes/header.php'; ?>

<style>
    .return-page { padding: 40px 0; background: #f8f9fa; min-height: 60vh; }
    .return-card {
        background: #fff; border-radius: 12px; padding: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); max-width: 900px;
        margin: 0 auto;
    }
    .return-section { margin-bottom: 2rem; }
    .return-section h3 {
        font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em;
        color: #8a8377; margin: 0 0 0.75rem; font-weight: 700;
    }
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
    .return-item .price { font-family: 'IBM Plex Mono', monospace; font-weight: 600; color: #a8461f; }
    .method-option {
        display: block; padding: 1rem; border: 2px solid #e5e7eb;
        border-radius: 10px; cursor: pointer; margin-bottom: 0.6rem;
        transition: all 0.2s;
    }
    .method-option:hover { border-color: #a8461f55; }
    .method-option.selected { border-color: #a8461f; background: #fdf7f3; }
    .method-option input[type=radio] { accent-color: #a8461f; margin-right: 0.6rem; }

    #returnPin {
        width: 100%; max-width: 240px; padding: 14px 18px;
        border: 2px solid #d1d5db; border-radius: 10px;
        font-size: 1.6rem; letter-spacing: 0.55em; text-align: center;
        font-family: 'IBM Plex Mono', monospace; background: #fff;
        transition: border-color 0.2s; box-sizing: border-box;
    }
    #returnPin:focus {
        outline: none; border-color: #a8461f;
        box-shadow: 0 0 0 3px rgba(168,70,31,0.12);
    }
    #returnPin.error { border-color: #dc2626; animation: shakePin 0.35s; }
    @keyframes shakePin {
        0%,100% { transform: translateX(0); }
        25%     { transform: translateX(-6px); }
        75%     { transform: translateX(6px); }
    }

    /* ============================================================
       📷 IMAGE UPLOADER
       ============================================================ */
    .uploader-wrap {
        background: #faf8f4;
        border: 1px solid #efeae0;
        border-radius: 12px;
        padding: 1.25rem;
    }
    .uploader-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.85rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .uploader-title {
        font-size: 0.88rem;
        color: #374151;
        font-weight: 600;
    }
    .uploader-counter {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        color: #a8461f;
        padding: 4px 12px;
        background: #fff;
        border-radius: 999px;
        border: 1px solid #f5c9a8;
    }
    .uploader-counter.full {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    /* Dropzone */
    .dropzone {
        position: relative;
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 2.25rem 1.5rem;
        text-align: center;
        cursor: pointer;
        background: #fff;
        transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .dropzone:hover {
        border-color: #a8461f;
        background: #fdf7f3;
    }
    .dropzone.dragover {
        border-color: #a8461f;
        background: #fdf3ee;
        transform: scale(1.01);
        box-shadow: 0 0 0 4px rgba(168, 70, 31, 0.08);
    }
    .dropzone.disabled {
        opacity: 0.55;
        cursor: not-allowed;
        pointer-events: none;
    }
    .dropzone-icon {
        font-size: 2.5rem;
        margin-bottom: 0.6rem;
        color: #a8461f;
        transition: transform 0.22s;
    }
    .dropzone:hover .dropzone-icon { transform: translateY(-3px); }
    .dropzone-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1c1b19;
        margin-bottom: 0.35rem;
    }
    .dropzone-sub {
        font-size: 0.8rem;
        color: #8a8377;
    }
    .dropzone-sub b { color: #a8461f; }
    .dropzone input[type=file] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }

    /* Preview grid */
    .uploader-preview {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }
    .preview-card {
        position: relative;
        aspect-ratio: 1;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
        background: #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        animation: previewPop 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes previewPop {
        from { opacity: 0; transform: scale(0.85); }
        to   { opacity: 1; transform: scale(1); }
    }
    .preview-card img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .preview-info {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0) 100%);
        color: #fff;
        padding: 1.5rem 0.5rem 0.4rem;
        font-size: 0.68rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: 'IBM Plex Mono', monospace;
    }
    .preview-remove {
        position: absolute;
        top: 6px; right: 6px;
        width: 26px; height: 26px;
        border-radius: 50%;
        border: none;
        background: rgba(185, 28, 28, 0.92);
        color: #fff;
        font-size: 1.05rem;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        transition: all 0.15s;
        box-shadow: 0 2px 6px rgba(0,0,0,0.22);
    }
    .preview-remove:hover {
        background: #7f1d1d;
        transform: scale(1.12) rotate(90deg);
    }
    .preview-badge {
        position: absolute;
        top: 6px; left: 6px;
        background: rgba(28, 27, 25, 0.85);
        color: #fff;
        font-size: 0.66rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 999px;
        font-family: 'IBM Plex Mono', monospace;
    }

    /* Uploader note */
    .uploader-note {
        margin-top: 0.75rem;
        font-size: 0.78rem;
        color: #8a8377;
        line-height: 1.55;
    }
    .uploader-note b { color: #a8461f; }

    /* Uploader toast */
    .uploader-toast {
        margin-top: 0.75rem;
        padding: 0.7rem 1rem;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        animation: previewPop 0.25s ease;
    }
    .uploader-toast.error {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    .uploader-toast.warn {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }

    @media (max-width: 600px) {
        .return-card { padding: 1.25rem; }
        .uploader-preview { grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); }
        .dropzone { padding: 1.5rem 1rem; }
        .dropzone-icon { font-size: 2rem; }
    }
</style>

<main class="return-page">
    <div class="container" style="max-width:1100px;margin:0 auto;padding:0 15px;">

        <div style="max-width:900px;margin:0 auto 20px;">
            <a href="account.php" style="color:#6b7280;text-decoration:none;font-size:14px;">
                ← Quay lại tài khoản
            </a>
        </div>

        <div class="return-card">
            <h1 style="margin:0 0 0.5rem;font-size:1.5rem;font-weight:700;color:#1c1b19;">
                🔄 Yêu cầu trả hàng / Hoàn tiền
            </h1>
            <p style="color:#6b7280;font-size:0.9rem;margin:0 0 1.5rem;">
                Đơn hàng <b>#<?php echo (int)$order['id']; ?></b> ·
                Còn <b style="color:#a8461f;"><?php echo $daysLeft; ?> ngày</b> để yêu cầu trả hàng.
            </p>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:1.5rem;font-size:0.9rem;">
                    <?php echo sanitize($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$hasPin): ?>
                <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:14px 16px;border-radius:10px;margin-bottom:1.5rem;font-size:0.9rem;">
                    ⚠️ Bạn chưa thiết lập <b>mã PIN</b> thanh toán.
                    Vui lòng vào <a href="wallet.php" style="color:#a8461f;font-weight:700;">Ví Atelier Pay</a> để thiết lập trước khi gửi yêu cầu trả hàng.
                </div>
            <?php endif; ?>

            <form id="returnForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">

                <!-- 1. SẢN PHẨM -->
                <div class="return-section">
                    <h3>1. Chọn sản phẩm muốn trả *</h3>
                    <?php foreach ($items as $it): ?>
                        <label class="return-item" data-price="<?php echo (float)$it['price']; ?>">
                            <input type="checkbox" class="item-check" name="items[]"
                                   value="<?php echo (int)$it['id']; ?>"
                                   data-item-id="<?php echo (int)$it['id']; ?>"
                                   data-max-qty="<?php echo (int)$it['quantity']; ?>">
                            <img src="<?php echo sanitize($it['image_url']); ?>" alt="">
                            <div class="info">
                                <div class="name"><?php echo sanitize($it['name']); ?></div>
                                <div class="meta">
                                    Size: <b><?php echo sanitize($it['size']); ?></b> ·
                                    Đã mua: <b>x<?php echo (int)$it['quantity']; ?></b> ·
                                    <span class="price"><?php echo number_format($it['price'], 0, ',', '.'); ?>đ</span>/cái
                                </div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:0.7rem;color:#6b7280;margin-bottom:4px;">SL trả</div>
                                <input type="number" class="qty-input item-qty"
                                       name="qty[<?php echo (int)$it['id']; ?>]"
                                       value="1" min="1" max="<?php echo (int)$it['quantity']; ?>"
                                       disabled>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- 2. LÝ DO -->
                <div class="return-section">
                    <h3>2. Lý do trả hàng *</h3>
                    <select name="reason" id="returnReason" required
                            style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;background:#fff;">
                        <option value="">-- Chọn lý do --</option>
                        <?php foreach ($reasons as $r): ?>
                            <option value="<?php echo htmlspecialchars($r, ENT_QUOTES); ?>"><?php echo $r; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div id="reasonOtherGroup" hidden style="margin-top:0.75rem;">
                        <textarea name="reason_detail" id="reasonDetail" rows="3" maxlength="500"
                                  placeholder="Vui lòng mô tả chi tiết tình trạng sản phẩm..."
                                  style="width:100%;padding:11px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;resize:vertical;box-sizing:border-box;"></textarea>
                    </div>
                </div>

                <!-- 3. 📷 ẢNH SẢN PHẨM -->
                <div class="return-section">
                    <h3>3. Ảnh sản phẩm thực tế *</h3>

                    <div class="uploader-wrap">
                        <div class="uploader-head">
                            <div class="uploader-title">
                                📷 Chụp ảnh sản phẩm để shop dễ xử lý hơn
                            </div>
                            <div class="uploader-counter" id="uploaderCounter">0/5 ảnh</div>
                        </div>

                        <!-- DROPZONE -->
                        <div class="dropzone" id="dropzone">
                            <input type="file" id="returnImages" name="images[]"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   multiple>
                            <div class="dropzone-icon">📸</div>
                            <div class="dropzone-title">
                                Kéo ảnh vào đây hoặc <span style="color:#a8461f;text-decoration:underline;">bấm để chọn</span>
                            </div>
                            <div class="dropzone-sub">
                                Tối đa <b>5 ảnh</b> · mỗi ảnh <b>≤ 5MB</b> · JPG, PNG, WEBP, GIF
                            </div>
                        </div>

                        <!-- PREVIEW GRID -->
                        <div class="uploader-preview" id="uploaderPreview"></div>

                        <!-- TOAST -->
                        <div id="uploaderToast" style="display:none;"></div>

                        <div class="uploader-note">
                            💡 <b>Gợi ý:</b> Chụp rõ <b>lỗi/hư hỏng</b> hoặc <b>tem mác</b> của sản phẩm.
                            Ảnh càng rõ, shop càng xử lý nhanh. Tối thiểu nên có <b>1 ảnh</b>.
                        </div>
                    </div>
                </div>

                <!-- 4. PHƯƠNG THỨC HOÀN TIỀN -->
                <div class="return-section">
                    <h3>4. Phương thức hoàn tiền *</h3>

                    <label class="method-option selected" data-method="wallet">
                        <input type="radio" name="refund_method" value="wallet" checked>
                        <b>💰 Ví Atelier Pay</b>
                        <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">
                            Số dư hiện tại: <b><?php echo number_format($walletBalance, 0, ',', '.'); ?>đ</b>
                            · Hoàn ngay sau khi admin duyệt
                        </div>
                    </label>

                    <label class="method-option" data-method="bank">
                        <input type="radio" name="refund_method" value="bank">
                        <b>🏦 Chuyển khoản ngân hàng</b>
                        <div style="font-size:0.82rem;color:#6b7280;margin-top:4px;margin-left:1.6rem;">
                            Hoàn về tài khoản đã liên kết (1-3 ngày làm việc)
                        </div>
                    </label>

                    <div id="bankSelectWrap" hidden style="margin-top:0.5rem;padding-left:1.6rem;">
                        <?php if (empty($banks)): ?>
                            <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:10px 14px;border-radius:8px;font-size:0.85rem;">
                                ⚠ Bạn chưa liên kết ngân hàng nào.
                                <a href="wallet.php" style="color:#a8461f;font-weight:600;">Liên kết ngay →</a>
                            </div>
                        <?php else: ?>
                            <label style="display:block;font-size:0.8rem;color:#374151;margin-bottom:6px;">Chọn ngân hàng nhận tiền:</label>
                            <select name="refund_bank_id" id="refundBankId"
                                    style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88rem;background:#fff;">
                                <?php foreach ($banks as $b): ?>
                                    <option value="<?php echo (int)$b['id']; ?>">
                                        <?php echo sanitize($b['bank_name']); ?> ·
                                        •••• <?php echo substr($b['account_no'], -4); ?>
                                        <?php echo $b['is_default'] ? '(mặc định)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5. PIN XÁC NHẬN -->
                <div class="return-section">
                    <h3>5. Xác nhận mã PIN *</h3>
                    <div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem;">
                        <label style="display:block;font-size:0.88rem;color:#374151;margin-bottom:10px;font-weight:600;">
                            🔐 Nhập mã PIN 6 số để xác nhận yêu cầu trả hàng
                        </label>
                        <input type="password" name="pin" id="returnPin"
                               inputmode="numeric" pattern="\d{6}" maxlength="6"
                               placeholder="••••••"
                               autocomplete="off"
                               <?php echo !$hasPin ? 'disabled' : ''; ?>>
                        <div style="font-size:0.78rem;color:#8a8377;margin-top:10px;">
                            💡 Dùng mã PIN bạn đã thiết lập cho ví Atelier Pay.
                            <a href="wallet.php" style="color:#a8461f;font-weight:600;">Quản lý ví →</a>
                        </div>
                    </div>
                </div>

                <!-- TỔNG -->
                <div style="background:#fdf7f3;border:1px solid #f5c9a8;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <div>
                            <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;font-weight:700;">
                                Số tiền dự kiến hoàn
                            </div>
                            <div style="font-size:0.82rem;color:#7c3216;margin-top:2px;">
                                Sau khi admin duyệt yêu cầu
                            </div>
                        </div>
                        <div id="refundAmount" style="font-family:'IBM Plex Mono',monospace;font-size:1.6rem;font-weight:700;color:#a8461f;">
                            0đ
                        </div>
                    </div>
                </div>

                <p id="returnErrorMsg" style="color:#b91c1c;font-size:0.85rem;margin:0 0 0.75rem;min-height:1em;"></p>

                <div style="display:flex;gap:10px;">
                    <a href="account.php"
                       style="flex:1;text-align:center;padding:12px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-weight:600;text-decoration:none;font-size:0.9rem;box-sizing:border-box;">
                        Hủy
                    </a>
                    <button type="submit" id="returnSubmitBtn"
                            style="flex:2;padding:12px;background:#a8461f;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:0.9rem;<?php echo !$hasPin ? 'opacity:0.5;cursor:not-allowed;' : ''; ?>"
                            <?php echo !$hasPin ? 'disabled' : ''; ?>>
                        📤 Gửi yêu cầu trả hàng
                    </button>
                </div>
            </form>
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

    /* ============================================================
       LÝ DO "KHÁC"
       ============================================================ */
    reasonSel.addEventListener('change', function () {
        var isOther = this.value === 'Khác';
        reasonGrp.hidden = !isOther;
        if (!isOther) document.getElementById('reasonDetail').value = '';
    });

    /* ============================================================
       PHƯƠNG THỨC HOÀN TIỀN
       ============================================================ */
    document.querySelectorAll('.method-option').forEach(function (opt) {
        opt.addEventListener('click', function () {
            document.querySelectorAll('.method-option').forEach(function (o) {
                o.classList.remove('selected');
            });
            this.classList.add('selected');
            var method = this.dataset.method;
            bankWrap.hidden = (method !== 'bank');
            if (bankSel) bankSel.required = (method === 'bank');
        });
    });

    /* ============================================================
       TÍNH TIỀN HOÀN
       ============================================================ */
    function recalc() {
        var total = 0;
        document.querySelectorAll('.item-check').forEach(function (cb) {
            var label = cb.closest('.return-item');
            var qty   = label.querySelector('.item-qty');
            if (cb.checked) {
                label.classList.add('selected');
                qty.disabled = false;
                var price = parseFloat(label.dataset.price) || 0;
                var q     = parseInt(qty.value, 10) || 1;
                total += price * q;
            } else {
                label.classList.remove('selected');
                qty.disabled = true;
                qty.value = 1;
            }
        });
        amtDisplay.textContent = total.toLocaleString('vi-VN') + 'đ';
        return total;
    }

    document.querySelectorAll('.item-check, .item-qty').forEach(function (el) {
        el.addEventListener('input', recalc);
        el.addEventListener('change', recalc);
    });

    /* ============================================================
       📷 IMAGE UPLOADER
       ============================================================ */
    var MAX_FILES   = 5;
    var MAX_SIZE    = 5 * 1024 * 1024; // 5MB
    var ALLOWED     = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    var fileInput   = document.getElementById('returnImages');
    var dropzone    = document.getElementById('dropzone');
    var previewGrid = document.getElementById('uploaderPreview');
    var counterEl   = document.getElementById('uploaderCounter');
    var toastEl     = document.getElementById('uploaderToast');

    var filesList = []; // Array of File objects

    /* ---------- Toast ---------- */
    function showUploaderToast(msg, type) {
        toastEl.className = 'uploader-toast ' + (type || 'error');
        toastEl.innerHTML = (type === 'warn' ? '⚠️ ' : '❌ ') + msg;
        toastEl.style.display = 'flex';
        clearTimeout(showUploaderToast._t);
        showUploaderToast._t = setTimeout(function () {
            toastEl.style.display = 'none';
        }, 4000);
    }

    function hideUploaderToast() {
        toastEl.style.display = 'none';
    }

    /* ---------- Format size ---------- */
    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    /* ---------- Update counter ---------- */
    function updateCounter() {
        var n = filesList.length;
        counterEl.textContent = n + '/' + MAX_FILES + ' ảnh';
        counterEl.className = 'uploader-counter' + (n >= MAX_FILES ? ' full' : '');

        if (n >= MAX_FILES) {
            dropzone.classList.add('disabled');
        } else {
            dropzone.classList.remove('disabled');
        }
    }

    /* ---------- Sync input files ---------- */
    function syncInput() {
        var dt = new DataTransfer();
        filesList.forEach(function (f) { dt.items.add(f); });
        fileInput.files = dt.files;
    }

    /* ---------- Render preview ---------- */
    function renderPreview() {
        previewGrid.innerHTML = '';

        filesList.forEach(function (file, index) {
            var card = document.createElement('div');
            card.className = 'preview-card';

            var img = document.createElement('img');
            img.alt = file.name;
            var reader = new FileReader();
            reader.onload = function (e) { img.src = e.target.result; };
            reader.readAsDataURL(file);
            card.appendChild(img);

            var badge = document.createElement('div');
            badge.className = 'preview-badge';
            badge.textContent = '#' + (index + 1);
            card.appendChild(badge);

            var info = document.createElement('div');
            info.className = 'preview-info';
            info.innerHTML = '<span>' + formatSize(file.size) + '</span>';
            card.appendChild(info);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'preview-remove';
            btn.title = 'Xóa ảnh này';
            btn.innerHTML = '×';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                filesList.splice(index, 1);
                syncInput();
                renderPreview();
                updateCounter();
                hideUploaderToast();
            });
            card.appendChild(btn);

            previewGrid.appendChild(card);
        });
    }

    /* ---------- Process files ---------- */
    function addFiles(newFiles) {
        hideUploaderToast();
        var rejected  = [];
        var addedCount = 0;

        for (var i = 0; i < newFiles.length; i++) {
            var f = newFiles[i];

            // Check count
            if (filesList.length >= MAX_FILES) {
                rejected.push('Đã đủ ' + MAX_FILES + ' ảnh (bỏ qua "' + f.name + '")');
                break;
            }

            // Check type
            if (ALLOWED.indexOf(f.type) === -1) {
                rejected.push('"' + f.name + '" không phải ảnh hợp lệ');
                continue;
            }

            // Check size
            if (f.size > MAX_SIZE) {
                rejected.push('"' + f.name + '" vượt quá 5MB');
                continue;
            }

            // Check trùng tên + size
            var isDup = filesList.some(function (x) {
                return x.name === f.name && x.size === f.size;
            });
            if (isDup) {
                rejected.push('"' + f.name + '" đã có trong danh sách');
                continue;
            }

            filesList.push(f);
            addedCount++;
        }

        syncInput();
        renderPreview();
        updateCounter();

        if (rejected.length > 0) {
            showUploaderToast(rejected.join('<br>'), 'warn');
        } else if (addedCount > 0) {
            // ok, no toast
        }
    }

    /* ---------- File input change ---------- */
    fileInput.addEventListener('change', function () {
        var files = Array.from(this.files);
        // Reset filesList from input (browser may replace)
        files.forEach(function (f) {
            var isDup = filesList.some(function (x) {
                return x.name === f.name && x.size === f.size;
            });
            if (!isDup && filesList.length < MAX_FILES && ALLOWED.indexOf(f.type) !== -1 && f.size <= MAX_SIZE) {
                filesList.push(f);
            }
        });
        syncInput();
        renderPreview();
        updateCounter();
    });

    /* ---------- Drag & drop ---------- */
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (filesList.length >= MAX_FILES) return;
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', function (e) {
        if (filesList.length >= MAX_FILES) return;
        var dropped = Array.from(e.dataTransfer.files || []);
        if (dropped.length === 0) return;
        addFiles(dropped);
    });

    /* ============================================================
       PIN — chỉ cho nhập số
       ============================================================ */
    pinInput?.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        this.classList.remove('error');
    });

    /* ============================================================
       SUBMIT
       ============================================================ */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errMsg.textContent = '';

        // Validate items
        var checked = document.querySelectorAll('.item-check:checked');
        if (checked.length === 0) {
            errMsg.textContent = 'Vui lòng chọn ít nhất 1 sản phẩm muốn trả.';
            return;
        }

        // Validate reason
        if (!reasonSel.value) {
            errMsg.textContent = 'Vui lòng chọn lý do trả hàng.';
            return;
        }
        if (reasonSel.value === 'Khác' && !document.getElementById('reasonDetail').value.trim()) {
            errMsg.textContent = 'Vui lòng nhập lý do cụ thể.';
            return;
        }

        // Validate ảnh — bắt buộc tối thiểu 1 ảnh
        if (filesList.length === 0) {
            errMsg.textContent = 'Vui lòng tải lên ít nhất 1 ảnh sản phẩm thực tế.';
            document.querySelector('.uploader-wrap').scrollIntoView({
                behavior: 'smooth', block: 'center'
            });
            setTimeout(function () {
                dropzone.classList.add('dragover');
                setTimeout(function () { dropzone.classList.remove('dragover'); }, 800);
            }, 300);
            return;
        }

        // Validate bank
        var method = form.querySelector('input[name="refund_method"]:checked').value;
        if (method === 'bank' && !bankSel) {
            errMsg.textContent = 'Bạn chưa liên kết ngân hàng. Vui lòng chọn ví Atelier Pay hoặc liên kết ngân hàng.';
            return;
        }

        // Validate PIN
        var pinVal = (pinInput?.value || '').trim();
        if (!/^\d{6}$/.test(pinVal)) {
            errMsg.textContent = 'Vui lòng nhập mã PIN 6 số để xác nhận.';
            pinInput?.classList.add('error');
            pinInput?.focus();
            return;
        }

        var btn = document.getElementById('returnSubmitBtn');
        var old = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Đang xử lý...';

        fetch('ajax/submit_return.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(async function (r) {
            // Đọc dạng text trước để tránh lỗi parse JSON nếu PHP trả về Warning/Fatal Error
            var text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Lỗi từ Server (Không phải JSON):", text);
                // Hiển thị trực tiếp lỗi PHP ra màn hình để dễ debug
                alert("Lỗi từ máy chủ:\n" + text.substring(0, 500));
                throw new Error("Lỗi định dạng dữ liệu.");
            }
        })
        .then(function (data) {
            if (data.ok) {
                alert(data.msg || '✅ Gửi yêu cầu thành công!');
                window.location.href = 'account.php';
            } else {
                errMsg.textContent = data.msg || 'Có lỗi xảy ra.';
                btn.disabled = false;
                btn.textContent = old;
                if (/PIN/i.test(data.msg || '')) {
                    pinInput?.classList.add('error');
                    pinInput?.focus();
                    pinInput?.select();
                }
            }
        })
        .catch(function (error) {
            // Chỉ báo lỗi mạng khi thực sự không kết nối được
            if (error.message !== "Lỗi định dạng dữ liệu.") {
                errMsg.textContent = 'Không thể kết nối tới máy chủ.';
            }
            btn.disabled = false;
            btn.textContent = old;
        });
    });

    recalc();
    updateCounter();
})();
</script>

<?php include 'includes/footer.php'; ?>