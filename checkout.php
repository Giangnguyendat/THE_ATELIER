<?php
require_once 'includes/config.php';
// Nhúng file helper để tạo thông báo
require_once 'admin/includes/notification_helper.php'; 

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$fullCart = $_SESSION['cart'] ?? [];
if (empty($fullCart)) {
    header('Location: cart.php');
    exit;
}

$selectedKeys = $_SESSION['selected_cart_keys'] ?? [];

if (empty($selectedKeys)) {
    $cart = $fullCart;
} else {
    $cart = array_intersect_key($fullCart, array_flip($selectedKeys));
    if (empty($cart)) {
        unset($_SESSION['selected_cart_keys']);
        header('Location: cart.php');
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userStmt = $pdo->prepare("SELECT id, full_name, email, phone, address, birthday, atelier_pay_balance FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$_SESSION['user']  = $currentUser;
$_SESSION['email'] = $currentUser['email'] ?? '';

$walletBalance = (float)($currentUser['atelier_pay_balance'] ?? 0);

// Linked banks
$linkedBanks = [];
try {
    $lb = $pdo->prepare("SELECT * FROM linked_banks WHERE user_id = ? ORDER BY is_default DESC, id DESC");
    $lb->execute([$_SESSION['user_id']]);
    $linkedBanks = $lb->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $linkedBanks = []; }

$banksList = require 'includes/banks.php';

$subtotal = 0;
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shippingFee = $subtotal >= 500000 ? 0 : 30000;

$voucherRow     = null;
$discountAmount = 0;
$total          = $subtotal + $shippingFee;
$error          = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Yêu cầu không hợp lệ (Lỗi Token)!';
    } else {
        $fullName      = trim($_POST['full_name'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $note          = trim($_POST['note'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? 'cod';
        $voucherCode   = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $linkedBankId  = (int)($_POST['linked_bank_id'] ?? 0);
        $cardId        = trim($_POST['card_id'] ?? '');

        if (!in_array($paymentMethod, ['cod', 'bank', 'card', 'wallet'], true)) {
            $paymentMethod = 'cod';
        }

        if ($voucherCode !== '') {
            $vStmt = $pdo->prepare("
                SELECT * FROM vouchers
                WHERE code = ?
                  AND valid_from <= CURDATE()
                  AND valid_to   >= CURDATE()
                LIMIT 1
            ");
            $vStmt->execute([$voucherCode]);
            $voucherRow = $vStmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucherRow) {
                $error = 'Mã giảm giá không hợp lệ hoặc đã hết hạn.';
            }
            // ===== CHECK LƯỢT SỬ DỤNG =====
            else {
                $usageLimit = isset($voucherRow['usage_limit']) ? (int)$voucherRow['usage_limit'] : 1;
                $usedCount  = isset($voucherRow['used_count'])  ? (int)$voucherRow['used_count']  : 0;
                $legacyUsed = (int)($voucherRow['used'] ?? 0);

                if ($usageLimit === 1 && ($legacyUsed === 1 || $usedCount >= 1)) {
                    $error = 'Mã giảm giá này đã được sử dụng.';
                } elseif ($usageLimit > 1 && $usedCount >= $usageLimit) {
                    $error = 'Mã giảm giá này đã hết lượt sử dụng (' . $usedCount . '/' . $usageLimit . ').';
                } elseif ($voucherRow['user_id'] !== null && (int)$voucherRow['user_id'] !== (int)$_SESSION['user_id']) {
                    $error = 'Mã giảm giá này không thuộc về bạn.';
                } elseif ($subtotal < (float)$voucherRow['min_order_amount']) {
                    $error = 'Đơn hàng chưa đủ giá trị tối thiểu để dùng mã này.';
                } else {
                    $discountAmount = $subtotal * (int)$voucherRow['discount_percent'] / 100;
                    if (!empty($voucherRow['max_discount_amount'])) {
                        $discountAmount = min($discountAmount, (float)$voucherRow['max_discount_amount']);
                    }
                    $discountAmount = round($discountAmount);
                }
            }
        }

        $total = max(0, $subtotal - $discountAmount + $shippingFee);

        // ===== VALIDATION SERVER-SIDE =====
        if ($error !== '') {
            // giữ lỗi voucher
        } elseif (empty($fullName) || empty($phone) || empty($address)) {
            $error = 'Vui lòng điền đầy đủ thông tin giao hàng!';
        } elseif (!preg_match('/^[0-9+\-\s()]{8,20}$/', $phone)) {
            $error = 'Số điện thoại không hợp lệ!';
        }
        elseif (in_array($paymentMethod, ['bank', 'card'], true) && $linkedBankId <= 0) {
            $error = 'Vui lòng chọn ngân hàng đã liên kết.';
        }
        elseif ($paymentMethod === 'card' && !preg_match('/^\d{12,19}$/', $cardId)) {
            $error = 'ID thẻ không hợp lệ (12-19 chữ số).';
        }
        elseif ($paymentMethod === 'wallet' && $walletBalance < $total) {
            $error = 'Số dư ví Atelier Pay không đủ để thanh toán đơn này.';
        }
        elseif (empty($_SESSION['pin_verified'])) {
            $error = 'Vui lòng xác minh mã PIN trước khi xác nhận đặt hàng.';
        }
        elseif ($paymentMethod === 'card' && empty($_SESSION['otp_verified'])) {
            $error = 'Vui lòng xác minh mã OTP trước khi xác nhận đặt hàng.';
        }
        else {
            try {
                $pdo->beginTransaction();

                $statusMap = [
                    'cod'    => 'unpaid',
                    'bank'   => 'unpaid',
                    'card'   => 'paid',
                    'wallet' => 'paid',
                ];
                $paymentStatus = $statusMap[$paymentMethod] ?? 'unpaid';

                // Verify linked bank thuộc user
                if (in_array($paymentMethod, ['bank', 'card'], true)) {
                    $vb = $pdo->prepare("SELECT id FROM linked_banks WHERE id = ? AND user_id = ?");
                    $vb->execute([$linkedBankId, $_SESSION['user_id']]);
                    if (!$vb->fetch()) {
                        throw new Exception('Ngân hàng không hợp lệ.');
                    }
                }

                // Wallet — lock + check
                if ($paymentMethod === 'wallet') {
                    $ub = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ? FOR UPDATE");
                    $ub->execute([$_SESSION['user_id']]);
                    $balanceNow = (float)$ub->fetchColumn();
                    if ($balanceNow < $total) {
                        throw new Exception('Số dư ví không đủ.');
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO orders
                        (user_id, total_amount, full_name, phone, address, note,
                         payment_method, payment_status, voucher_code, discount_amount,
                         linked_bank_id, card_id, paid_at,
                         status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $paidAt = ($paymentMethod === 'wallet') ? date('Y-m-d H:i:s') : null;
                $stmt->execute([
                    $_SESSION['user_id'], $total, $fullName, $phone, $address, $note,
                    $paymentMethod, $paymentStatus,
                    $voucherRow ? $voucherRow['code'] : null,
                    $discountAmount,
                    $linkedBankId > 0 ? $linkedBankId : null,
                    $cardId !== '' ? $cardId : null,
                    $paidAt,
                ]);

                $orderId = $pdo->lastInsertId();


                // Wallet → trừ tiền + log transaction
                if ($paymentMethod === 'wallet') {
                    $ub = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ? FOR UPDATE");
                    $ub->execute([$_SESSION['user_id']]);
                    $balBefore = (float)$ub->fetchColumn();
                    $balAfter  = $balBefore - $total;

                    $pdo->prepare("UPDATE users SET atelier_pay_balance = ? WHERE id = ?")
                        ->execute([$balAfter, $_SESSION['user_id']]);

                    $pdo->prepare("
                        INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_before, balance_after, status, description, order_id, created_at)
                        VALUES (?, 'payment', ?, ?, ?, 'completed', ?, ?, NOW())
                    ")->execute([
                        $_SESSION['user_id'], $total, $balBefore, $balAfter,
                        'Thanh toán đơn #' . $orderId, $orderId,
                    ]);
                }

                // Kiểm tra stock
                foreach ($cart as $item) {
                    $pid  = (int)($item['product_id'] ?? $item['id']);
                    $size = $item['size'] ?? 'M';
                    $qty  = (int)$item['quantity'];

                    if (!hasEnoughStock($pdo, $pid, $size, $qty)) {
                        $available = getProductStock($pdo, $pid, $size);
                        throw new Exception("Sản phẩm \"{$item['name']}\" (size $size) chỉ còn $available — không đủ số lượng yêu cầu.");
                    }
                }

                // Trừ tồn + insert
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, size, quantity, price)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($cart as $item) {
                    $pid  = (int)($item['product_id'] ?? $item['id']);
                    $size = $item['size'] ?? 'M';
                    $qty  = (int)$item['quantity'];

                    if (!decreaseStock($pdo, $pid, $size, $qty)) {
                        throw new Exception("Không đủ hàng: {$item['name']} (size $size)");
                    }

                    $itemStmt->execute([
                        $orderId,
                        $pid,
                        $size,
                        $qty,
                        (float)$item['price'],
                    ]);
                }

                /* ============================================================
                   ✅ APPLY VOUCHER — ở ĐÚNG VỊ TRÍ (sau khi mọi thứ OK)
                   ============================================================ */
                if ($voucherRow) {
                    require_once 'includes/voucher_helper.php';

                    $applied = applyVoucher(
                        $pdo,
                        (int)$voucherRow['id'],
                        (int)$orderId,
                        (int)$_SESSION['user_id'],
                        (float)$discountAmount
                    );

                    if (!$applied) {
                        // Voucher bị user khác dùng mất (race condition)
                        throw new Exception('Voucher vừa được sử dụng bởi người khác. Vui lòng chọn voucher khác.');
                    }
                }

                $pdo->commit();

                // ============================================================
                // ✅ BẮT ĐẦU CHÈN CODE THÔNG BÁO TẠI ĐÂY
                // ============================================================
                addNotification(
                    'order_new',
                    'Đơn hàng mới',
                    'Khách hàng ' . $fullName . ' vừa đặt đơn #' . $orderId . ' trị giá ' . number_format($total, 0, ',', '.') . 'đ.',
                    'order-detail.php?id=' . $orderId,
                    'high',
                    'ph-shopping-cart'
                );
                // ============================================================
                // KẾT THÚC CODE THÔNG BÁO
                // ============================================================

                unset($_SESSION['otp_verified'], $_SESSION['otp_code'],
                      $_SESSION['otp_expires_at'], $_SESSION['otp_attempts']);
                unset($_SESSION['pin_verified']);

                // Xóa sản phẩm đã mua khỏi session cart
                if (!empty($selectedKeys)) {
                    foreach ($selectedKeys as $key) {
                        unset($_SESSION['cart'][$key]);
                    }
                    unset($_SESSION['selected_cart_keys']);
                } else {
                    unset($_SESSION['cart']);
                }

                // Đồng bộ DB cart_storage
                if (function_exists('clearCartFromDb') && function_exists('saveCartToDb')) {
                    if (empty($_SESSION['cart'])) {
                        clearCartFromDb($pdo);
                    } else {
                        saveCartToDb($pdo);
                    }
                }

                /* ============================================================
                   REDIRECT — Phụ thuộc vào lựa chọn sau PIN (chỉ với bank)
                   ============================================================ */
                if ($paymentMethod === 'bank') {
                    $redirectAfter = $_POST['redirect_after_order'] ?? 'payment';

                    if ($redirectAfter === 'home') {
                        /* Người dùng chọn "Về trang chủ" */
                        $_SESSION['flash_message'] = [
                            'type'    => 'success',
                            'message' => "✅ Đặt hàng thành công! Đơn #$orderId đang chờ thanh toán. Bạn có thể thanh toán sau trong Tài khoản."
                        ];
                        header('Location: index.php');
                        exit;
                    }

                    /* Mặc định: "Thanh toán ngay" → chuyển tới trang thanh toán */
                    header('Location: payment.php?order_id=' . $orderId);
                    exit;
                }

                /* COD / Wallet / Card → order_success */
                header('Location: order_success.php?order_id=' . $orderId);
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Lỗi đặt hàng: ' . $e->getMessage();
            }
        }
    }
}

$defaultFullName = $_POST['full_name'] ?? $currentUser['full_name'] ?? '';
$defaultPhone    = $_POST['phone']     ?? $currentUser['phone']     ?? '';
$defaultAddress  = $_POST['address']   ?? $currentUser['address']   ?? '';
$defaultNote     = $_POST['note']      ?? '';
$defaultPayment  = $_POST['payment_method'] ?? 'cod';
$defaultVoucher  = strtoupper(trim($_POST['voucher_code'] ?? ''));

$userBirthdayVoucher = null;
$isBirthday = false;
if (function_exists('getActiveBirthdayVoucher') && function_exists('isBirthdayToday')) {
    $userBirthdayVoucher = getActiveBirthdayVoucher((int)$_SESSION['user_id']);
    $isBirthday          = isBirthdayToday($currentUser);
}

/* ===== Load thông tin ngân hàng ===== */
$bank = [];
try {
    $bank = $pdo->query("SELECT * FROM bank_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $bank = [];
}

$bankBin          = $bank['bank_bin']         ?? '970436';
$bankName         = $bank['bank_name']        ?? 'Vietcombank';
$bankAccountNo    = $bank['account_no']       ?? '';
$bankAccountName  = $bank['account_name']     ?? '';
$bankQrImage      = $bank['qr_image']         ?? '';
$bankTransferNote = $bank['transfer_note']    ?? 'Họ tên + SĐT';
$bankInstruction  = $bank['instruction']      ?? '';
include 'includes/header.php';
?>

<style>
/* ===== Wallet badge ===== */
/* ============================================================
   BANK CHOICE MODAL — Sau khi PIN thành công
   ============================================================ */
.bank-choice-overlay {
    position: fixed;
    inset: 0;
    background: rgba(28, 27, 25, 0.7);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.bank-choice-overlay.show {
    display: flex;
    animation: bcmFadeIn 0.25s ease;
}
@keyframes bcmFadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

.bank-choice-box {
    background: #fffdf9;
    border-radius: 16px;
    width: 100%;
    max-width: 460px;
    padding: 32px 28px 28px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(28, 27, 25, 0.28);
    animation: bcmSlideUp 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
}
@keyframes bcmSlideUp {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.bank-choice-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    margin: 0 auto 18px;
    box-shadow: 0 8px 24px rgba(22, 101, 52, 0.18);
    animation: bcmPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s both;
}
@keyframes bcmPop {
    from { transform: scale(0); }
    to   { transform: scale(1); }
}

.bank-choice-title {
    font-family: 'Fraunces', Georgia, serif;
    font-size: 1.55rem;
    font-weight: 600;
    color: #1c1b19;
    margin: 0 0 10px;
    letter-spacing: -0.01em;
}

.bank-choice-desc {
    font-size: 0.92rem;
    color: #57534e;
    line-height: 1.65;
    margin: 0 0 6px;
}

.bank-choice-subdesc {
    font-size: 0.82rem;
    color: #8a8377;
    margin: 0 0 24px;
    line-height: 1.55;
}

.bank-choice-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.bank-choice-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 15px 20px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 0.95rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    letter-spacing: 0.02em;
}

.bank-choice-btn--primary {
    background: #a8461f;
    color: #fffdf9;
    box-shadow: 0 4px 14px rgba(168, 70, 31, 0.28);
}
.bank-choice-btn--primary:hover {
    background: #8f3a18;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(168, 70, 31, 0.35);
}
.bank-choice-btn--primary:active {
    transform: translateY(0);
}

.bank-choice-btn--ghost {
    background: #f5f2ec;
    color: #57534e;
    border: 1px solid #e8e0d0;
}
.bank-choice-btn--ghost:hover {
    background: #e8e0d0;
    color: #1c1b19;
}

.bank-choice-icon--pay { font-size: 1.15rem; }

@media (max-width: 480px) {
    .bank-choice-box {
        padding: 26px 22px 22px;
    }
    .bank-choice-title {
        font-size: 1.35rem;
    }
    .bank-choice-btn {
        padding: 14px 16px;
        font-size: 0.9rem;
    }
}
.wallet-badge {
    display: inline-block;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    padding: 2px 8px;
    border-radius: 999px;
    margin-left: 6px;
    vertical-align: middle;
    font-weight: 700;
}
.wallet-badge--ok { background: #ecfdf5; color: #166534; }
.wallet-badge--no { background: #fef2f2; color: #b91c1c; }

.wallet-note {
    display: block;
    margin-top: 6px;
    font-size: 0.78rem;
    color: #b91c1c;
    font-style: italic;
}
.wallet-note a { color: inherit; font-weight: 600; text-decoration: underline; }
.payment-option--disabled { opacity: 0.55; cursor: not-allowed; }

/* ===== Bank picker ===== */
.bank-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.bank-pick {
    position: relative;
    padding: 12px 14px;
    border: 1.5px solid #e5dfd2;
    border-radius: 10px;
    cursor: pointer;
    background: #fff;
    transition: all .18s;
    display: flex;
    align-items: center;
    gap: 10px;
}
.bank-pick:hover { border-color: #a8461f; transform: translateY(-1px); }
.bank-pick.selected {
    border-color: #a8461f;
    background: #fff8f4;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}
.bank-pick input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.bank-pick__logo {
    width: 36px; height: 36px;
    border-radius: 8px;
    object-fit: contain;
    background: #f2ece0;
    padding: 4px;
}
.bank-pick__info { flex: 1; min-width: 0; }
.bank-pick__name {
    font-weight: 600;
    font-size: 0.88rem;
    color: #1c1b19;
    margin-bottom: 2px;
}
.bank-pick__acct {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.75rem;
    color: #8a8377;
}
.bank-pick__default {
    position: absolute;
    top: 6px; right: 6px;
    font-size: 0.6rem;
    background: #b98a1d;
    color: #fff;
    padding: 1px 6px;
    border-radius: 999px;
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 700;
}

.bank-empty {
    padding: 20px;
    background: #faf7f1;
    border: 1px dashed #e5dfd2;
    border-radius: 10px;
    text-align: center;
    color: #8a8377;
    font-size: 0.88rem;
}
.bank-empty a { color: #a8461f; font-weight: 600; text-decoration: underline; }

/* ===== Card input ===== */
.card-input-group { display: flex; gap: 10px; margin-top: 12px; }
.card-input-group input {
    flex: 1;
    padding: 12px 14px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1rem;
    letter-spacing: 0.1em;
    background: #faf7f1;
    border: 1px solid #e5dfd2;
    border-radius: 8px;
    outline: none;
}
.card-input-group input:focus {
    border-color: #a8461f;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
}
</style>

<main class="checkout-wrap">
    <div class="checkout-container">

        <header class="checkout-header">
            <span class="tag-eyebrow">Bước cuối cùng</span>
            <h1 class="checkout-title">Thanh Toán Đơn Hàng</h1>
            <p class="checkout-subtitle">Vui lòng kiểm tra thông tin trước khi xác nhận</p>
        </header>

        <?php if (!empty($error)): ?>
            <div class="checkout-alert"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <form method="post" action="checkout.php" class="checkout-grid" id="checkoutForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="linked_bank_id" id="hiddenLinkedBankId" value="">
            <input type="hidden" name="card_id" id="hiddenCardId" value="">
            <input type="hidden" name="redirect_after_order" id="redirectAfterOrder" value="">

            <!-- ==== CỘT TRÁI ==== -->
            <div class="checkout-main">

                <!-- CARD 01 -->
                <section class="checkout-card">
                    <h2 class="checkout-card__title">
                        <span class="step-badge">01</span> Thông tin nhận hàng
                    </h2>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Họ và tên người nhận *</label>
                            <input type="text" name="full_name" required
                                   value="<?php echo sanitize($defaultFullName); ?>"
                                   placeholder="Nguyễn Văn A" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Số điện thoại *</label>
                            <input type="tel" name="phone" required
                                   value="<?php echo sanitize($defaultPhone); ?>"
                                   placeholder="0912 345 678" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Địa chỉ giao hàng chi tiết *</label>
                        <textarea name="address" required rows="3"
                                  placeholder="Số nhà, tên đường, phường/xã, quận/huyện, tỉnh/thành phố..."
                                  class="form-input"><?php echo sanitize($defaultAddress); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ghi chú đơn hàng (tùy chọn)</label>
                        <textarea name="note" rows="2"
                                  placeholder="Ví dụ: giao giờ hành chính, gọi trước khi giao..."
                                  class="form-input"><?php echo sanitize($defaultNote); ?></textarea>
                    </div>
                </section>

                <!-- CARD 02 -->
                <section class="checkout-card">
                    <h2 class="checkout-card__title">
                        <span class="step-badge">02</span> Phương thức thanh toán
                    </h2>

                    <div class="payment-options">

                        <!-- COD -->
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="cod"
                                   <?php echo $defaultPayment === 'cod' ? 'checked' : ''; ?>>
                            <div class="payment-option__body">
                                <div class="payment-option__icon">💵</div>
                                <div class="payment-option__info">
                                    <strong>Thanh toán khi nhận hàng (COD)</strong>
                                    <span>Trả tiền mặt cho shipper — xác minh PIN</span>
                                </div>
                                <div class="payment-option__check"></div>
                            </div>
                        </label>

                        <!-- WALLET -->
                        <label class="payment-option <?php echo $walletBalance < $total ? 'payment-option--disabled' : ''; ?>">
                            <input type="radio" name="payment_method" value="wallet"
                                   <?php echo $defaultPayment === 'wallet' ? 'checked' : ''; ?>
                                   <?php echo $walletBalance < $total ? 'disabled' : ''; ?>>
                            <div class="payment-option__body">
                                <div class="payment-option__icon">💰</div>
                                <div class="payment-option__info">
                                    <strong>Ví Atelier Pay
                                        <span class="wallet-badge <?php echo $walletBalance >= $total ? 'wallet-badge--ok' : 'wallet-badge--no'; ?>">
                                            <?php echo number_format($walletBalance, 0, ',', '.'); ?>đ
                                        </span>
                                    </strong>
                                    <span>Trừ trực tiếp số dư ví — thanh toán nhanh</span>
                                    <?php if ($walletBalance < $total): ?>
                                        <span class="wallet-note">
                                            ⚠️ Số dư không đủ (cần thêm <b><?php echo number_format($total - $walletBalance, 0, ',', '.'); ?>đ</b>).
                                            <a href="wallet.php" target="_blank">Nạp thêm →</a>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="payment-option__check"></div>
                            </div>
                        </label>

                        <!-- BANK -->
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="bank"
                                   <?php echo $defaultPayment === 'bank' ? 'checked' : ''; ?>>
                            <div class="payment-option__body">
                                <div class="payment-option__icon">🏦</div>
                                <div class="payment-option__info">
                                    <strong>Chuyển khoản ngân hàng</strong>
                                    <span>Chọn ngân hàng đã liên kết — có thể thanh toán sau</span>
                                </div>
                                <div class="payment-option__check"></div>
                            </div>
                        </label>

                        <!-- CARD -->
                        <label class="payment-option <?php echo empty($linkedBanks) ? 'payment-option--disabled' : ''; ?>">
                            <input type="radio" name="payment_method" value="card"
                                   <?php echo $defaultPayment === 'card' ? 'checked' : ''; ?>
                                   <?php echo empty($linkedBanks) ? 'disabled' : ''; ?>>
                            <div class="payment-option__body">
                                <div class="payment-option__icon">💳</div>
                                <div class="payment-option__info">
                                    <strong>Thẻ tín dụng / Ghi nợ</strong>
                                    <span>Nhập ID thẻ liên kết ví — xác minh PIN + OTP</span>
                                    <?php if (empty($linkedBanks)): ?>
                                        <span class="wallet-note">⚠️ Cần liên kết ngân hàng trước.</span>
                                    <?php endif; ?>
                                </div>
                                <div class="payment-option__check"></div>
                            </div>
                        </label>

                    </div>

                    <!-- EXTRA: WALLET -->
                    <div class="payment-extra" id="paymentExtraWallet" hidden>
                        <div class="payment-extra__box">
                            <strong>💰 Thanh toán bằng ví Atelier Pay</strong>
                            <p>Hệ thống sẽ trừ <b><?php echo number_format($total, 0, ',', '.'); ?>đ</b> từ số dư ví.</p>
                            <p style="margin-top:8px;">
                                Số dư: <b style="color:#a8461f;font-family:'IBM Plex Mono',monospace;"><?php echo number_format($walletBalance, 0, ',', '.'); ?>đ</b>
                                <?php if ($walletBalance >= $total): ?>
                                    <span style="color:#166534;">→ Đủ ✓</span>
                                <?php else: ?>
                                    <span style="color:#b91c1c;">→ Không đủ</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- EXTRA: BANK PICK -->
                    <div class="payment-extra" id="paymentExtraBankPick" hidden>
                        <div class="payment-extra__box">
                            <strong>🏦 Chọn ngân hàng thanh toán</strong>
                            <p style="margin-bottom:12px;font-size:0.85rem;color:#8a8377;">
                                Bạn có thể thanh toán ngay hoặc để sau. Đơn sẽ ở trạng thái <b>Chưa thanh toán</b>.
                            </p>

                            <?php if (empty($linkedBanks)): ?>
                                <div class="bank-empty">
                                    Bạn chưa liên kết ngân hàng nào.<br>
                                    <a href="wallet.php" target="_blank">→ Liên kết ngân hàng ngay</a>
                                </div>
                            <?php else: ?>
                                <div class="bank-picker" id="bankPicker">
                                    <?php foreach ($linkedBanks as $lb):
                                        $info = $banksList[$lb['bank_bin']] ?? ['name' => $lb['bank_name'], 'logo' => ''];
                                        $masked = '•••• ' . substr($lb['account_no'], -4);
                                    ?>
                                        <label class="bank-pick" data-id="<?php echo (int)$lb['id']; ?>">
                                            <input type="radio" name="linked_bank_id" value="<?php echo (int)$lb['id']; ?>"
                                                   <?php echo $lb['is_default'] ? 'checked' : ''; ?>>
                                            <?php if (!empty($info['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($info['logo']); ?>" class="bank-pick__logo" onerror="this.style.display='none'">
                                            <?php endif; ?>
                                            <div class="bank-pick__info">
                                                <div class="bank-pick__name"><?php echo htmlspecialchars($info['name']); ?></div>
                                                <div class="bank-pick__acct"><?php echo htmlspecialchars($masked); ?> · <?php echo htmlspecialchars($lb['account_name']); ?></div>
                                            </div>
                                            <?php if ($lb['is_default']): ?>
                                                <span class="bank-pick__default">MẶC ĐỊNH</span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <p style="margin-top:12px;font-size:0.82rem;text-align:right;">
                                <a href="wallet.php" target="_blank" style="color:#a8461f;text-decoration:underline;">+ Quản lý ngân hàng</a>
                            </p>
                        </div>
                    </div>

                    <!-- EXTRA: CARD -->
                    <div class="payment-extra" id="paymentExtraCardPick" hidden>
                        <div class="payment-extra__box">
                            <strong>💳 Thanh toán bằng thẻ</strong>
                            <p style="font-size:0.85rem;color:#8a8377;">Chọn ngân hàng phát hành + nhập ID thẻ (12-19 chữ số).</p>

                            <?php if (empty($linkedBanks)): ?>
                                <div class="bank-empty">
                                    Cần liên kết ngân hàng trước.<br>
                                    <a href="wallet.php" target="_blank">→ Liên kết ngay</a>
                                </div>
                            <?php else: ?>
                                <div class="bank-picker" id="cardBankPicker">
                                    <?php foreach ($linkedBanks as $lb):
                                        $info = $banksList[$lb['bank_bin']] ?? ['name' => $lb['bank_name'], 'logo' => ''];
                                    ?>
                                        <label class="bank-pick" data-id="<?php echo (int)$lb['id']; ?>">
                                            <input type="radio" name="card_bank_pick" value="<?php echo (int)$lb['id']; ?>"
                                                   <?php echo $lb['is_default'] ? 'checked' : ''; ?>>
                                            <?php if (!empty($info['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($info['logo']); ?>" class="bank-pick__logo" onerror="this.style.display='none'">
                                            <?php endif; ?>
                                            <div class="bank-pick__info">
                                                <div class="bank-pick__name"><?php echo htmlspecialchars($info['name']); ?></div>
                                                <div class="bank-pick__acct">•••• <?php echo substr($lb['account_no'], -4); ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="card-input-group">
                                    <input type="text" id="cardIdInput" maxlength="19" inputmode="numeric"
                                           placeholder="Nhập ID thẻ (12-19 chữ số)"
                                           value="<?php echo htmlspecialchars($_POST['card_id'] ?? ''); ?>">
                                </div>
                                <span style="display:block;margin-top:8px;font-size:0.78rem;color:#8a8377;font-style:italic;">
                                    ID thẻ mô phỏng — chỉ nhập số, ví dụ: 1234567890123456
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- CARD 03 -->
                <section class="checkout-card">
                    <h2 class="checkout-card__title">
                        <span class="step-badge">03</span> Mã giảm giá
                    </h2>

                    <?php if ($isBirthday && $userBirthdayVoucher): ?>
                        <div class="birthday-greeting">
                            🎂 <strong>Chúc mừng sinh nhật!</strong> Bạn có voucher giảm
                            <strong><?php echo (int)$userBirthdayVoucher['discount_percent']; ?>%</strong>
                            cho đơn hàng hôm nay.
                            <div class="birthday-code">
                                Mã: <b><?php echo sanitize($userBirthdayVoucher['code']); ?></b>
                                <button type="button" id="applyBirthdayVoucher" class="btn-apply-voucher">Áp dụng</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="voucher-input-group">
                        <input type="text" name="voucher_code" id="voucherCode"
                               placeholder="Nhập mã giảm giá..."
                               class="form-input"
                               value="<?php echo sanitize($defaultVoucher); ?>">
                        <button type="button" id="checkVoucherBtn" class="btn-check-voucher">Kiểm tra</button>
                    </div>

                    <div id="voucherMessage" class="voucher-message" hidden></div>
                </section>

            </div><!-- /.checkout-main -->

            <!-- ==== CỘT PHẢI: SIDEBAR ==== -->
            <aside class="checkout-sidebar">
                <div class="summary-card">
                    <h2 class="summary-title">
                        Tóm tắt đơn hàng
                        <span class="summary-count"><?php echo array_sum(array_column($cart, 'quantity')); ?> SP</span>
                    </h2>

                    <div class="summary-items">
                        <?php foreach ($cart as $item): ?>
                            <div class="summary-item">
                                <img src="<?php echo sanitize($item['image_url']); ?>"
                                     alt="<?php echo sanitize($item['name']); ?>">
                                <div class="summary-item__info">
                                    <h4><?php echo sanitize($item['name']); ?></h4>
                                    <p>
                                        Size <b><?php echo sanitize($item['size'] ?? 'M'); ?></b>
                                        · SL <b><?php echo (int)$item['quantity']; ?></b>
                                    </p>
                                </div>
                                <div class="summary-item__price">
                                    <?php echo number_format($item['price'] * $item['quantity'], 0, ',', '.'); ?>đ
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="summary-totals">
                        <div class="summary-row">
                            <span>Tạm tính</span>
                            <strong><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</strong>
                        </div>

                        <div class="summary-row summary-row--discount" id="discountRow"
                             <?php echo $discountAmount > 0 ? '' : 'hidden'; ?>>
                            <span id="discountLabel">
                                Giảm giá <?php echo $voucherRow ? '(' . sanitize($voucherRow['code']) . ')' : ''; ?>
                            </span>
                            <strong id="discountAmount">
                                −<?php echo number_format($discountAmount, 0, ',', '.'); ?>đ
                            </strong>
                        </div>

                        <div class="summary-row">
                            <span>Phí vận chuyển</span>
                            <?php if ($shippingFee === 0): ?>
                                <span class="free">Miễn phí</span>
                            <?php else: ?>
                                <strong><?php echo number_format($shippingFee, 0, ',', '.'); ?>đ</strong>
                            <?php endif; ?>
                        </div>

                        <?php if ($shippingFee > 0): ?>
                            <p class="summary-hint">
                                Mua thêm <b><?php echo number_format(500000 - $subtotal, 0, ',', '.'); ?>đ</b> để được miễn phí ship
                            </p>
                        <?php endif; ?>

                        <div class="summary-divider"></div>

                        <div class="summary-row total">
                            <span>Tổng thanh toán</span>
                            <span class="total-amount" id="totalAmount">
                                <?php echo number_format($total, 0, ',', '.'); ?>đ
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-checkout-submit" id="btnSubmitCheckout">
                        ✓ XÁC NHẬN ĐẶT HÀNG
                    </button>

                    <a href="cart.php" class="link-back-cart">← Quay lại giỏ hàng</a>
                </div>
            </aside>

        </form>
    </div>
</main>

<!-- OVERLAY LOADING / SUCCESS -->
<div class="otp-loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="otp-loading-box" id="loadingBox">
        <div class="hourglass-spinner"></div>
        <p class="otp-loading-text" id="loadingText">Đang xử lý đơn hàng...</p>
        <p class="otp-loading-subtext" id="loadingSubtext">Vui lòng không đóng cửa sổ này</p>
    </div>
</div>

<!-- MODAL OTP -->
<div class="otp-modal-overlay" id="otpModal" aria-hidden="true">
    <div class="otp-modal" role="dialog" aria-modal="true">
        <button type="button" class="otp-modal__close" id="otpCloseBtn" aria-label="Đóng">&times;</button>
        <div class="otp-modal__icon">📧</div>
        <h2 class="otp-modal__title">Xác minh thanh toán</h2>
        <p class="otp-modal__subtitle">
            Mã OTP đã được gửi tới email<br>
            <span class="otp-modal__phone" id="otpEmailDisplay">*******</span>
        </p>
        <div class="otp-inputs" id="otpInputs">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="0">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="1">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="2">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="3">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="4">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="5">
        </div>
        <button type="button" class="btn-otp-confirm" id="btnOtpConfirm" disabled>XÁC NHẬN OTP</button>
        <p class="otp-error-msg" id="otpErrorMsg"></p>
        <button type="button" class="otp-help-link" id="resendOtpBtn">Chưa nhận được mã? Gửi lại</button>
    </div>
</div>

<!-- MODAL PIN -->
<div class="otp-modal-overlay" id="pinModal" aria-hidden="true">
    <div class="otp-modal" role="dialog" aria-modal="true">
        <button type="button" class="otp-modal__close" id="pinCloseBtn" aria-label="Đóng">&times;</button>
        <div class="otp-modal__icon" id="pinIcon">🔐</div>
        <h2 class="otp-modal__title" id="pinTitle">Xác minh mã PIN</h2>
        <p class="otp-modal__subtitle" id="pinSubtitle">Nhập mã PIN 6 số để xác nhận thanh toán</p>

        <div id="pinVerifyBlock">
            <div class="otp-inputs" id="pinInputsVerify">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
            </div>
        </div>

        <div id="pinSetupBlock" hidden>
            <p style="text-align:center;font-family:var(--font-mono);font-size:0.78rem;color:var(--color-stone);margin:0 0 0.5rem">
                Nhập PIN 6 số
            </p>
            <div class="otp-inputs" id="pinInputsSetup">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
            </div>
            <p style="text-align:center;font-family:var(--font-mono);font-size:0.78rem;color:var(--color-stone);margin:1rem 0 0.5rem">
                Nhập lại PIN
            </p>
            <div class="otp-inputs" id="pinInputsConfirm">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric">
            </div>
        </div>

        <button type="button" class="btn-otp-confirm" id="btnPinConfirm" disabled style="margin-top:1.25rem">
            XÁC NHẬN PIN
        </button>
        <p class="otp-error-msg" id="pinErrorMsg"></p>
    </div>
</div>

<script src="js/chatbot.js"></script>
<script>
(function () {
    var CART_SUBTOTAL = <?php echo (int)$subtotal; ?>;
    var SHIPPING_FEE  = <?php echo (int)$shippingFee; ?>;

    function formatVnd(n) {
        return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ';
    }

    function updateSummary(discount, voucherCode) {
        var discountRow    = document.getElementById('discountRow');
        var discountLabel  = document.getElementById('discountLabel');
        var discountAmount = document.getElementById('discountAmount');
        var totalEl        = document.getElementById('totalAmount');

        var total = Math.max(0, CART_SUBTOTAL - discount + SHIPPING_FEE);

        if (discount > 0) {
            discountRow.hidden = false;
            if (discountLabel) discountLabel.textContent = voucherCode ? 'Giảm giá (' + voucherCode + ')' : 'Giảm giá';
            if (discountAmount) discountAmount.textContent = '−' + formatVnd(discount);
        } else {
            discountRow.hidden = true;
        }
        if (totalEl) totalEl.textContent = formatVnd(total);
    }

    /* ============================================================
       Extras + bank picker
       ============================================================ */
    var radios = document.querySelectorAll('input[name="payment_method"]');
    var extraWallet   = document.getElementById('paymentExtraWallet');
    var extraBankPick = document.getElementById('paymentExtraBankPick');
    var extraCardPick = document.getElementById('paymentExtraCardPick');

    function syncHiddenInputs(method) {
        var hiddenBank = document.getElementById('hiddenLinkedBankId');
        var hiddenCard = document.getElementById('hiddenCardId');
        if (!hiddenBank || !hiddenCard) return;

        if (method === 'bank') {
            var pick = document.querySelector('#bankPicker input[name="linked_bank_id"]:checked');
            hiddenBank.value = pick ? pick.value : '';
            hiddenCard.value = '';
        } else if (method === 'card') {
            var cardPick  = document.querySelector('#cardBankPicker input[name="card_bank_pick"]:checked');
            var cardInput = document.getElementById('cardIdInput');
            hiddenBank.value = cardPick ? cardPick.value : '';
            hiddenCard.value = cardInput ? cardInput.value.replace(/\D/g, '') : '';
        } else {
            hiddenBank.value = '';
            hiddenCard.value = '';
        }
    }

    function updateExtras() {
        var checked = document.querySelector('input[name="payment_method"]:checked');
        var val = checked ? checked.value : 'cod';

        if (extraWallet)   extraWallet.hidden   = (val !== 'wallet');
        if (extraBankPick) extraBankPick.hidden = (val !== 'bank');
        if (extraCardPick) extraCardPick.hidden = (val !== 'card');

        syncHiddenInputs(val);
    }

    /* Bank picker selected class */
    document.querySelectorAll('.bank-pick').forEach(function (el) {
        el.addEventListener('click', function () {
            var group = el.closest('.bank-picker');
            if (!group) return;
            group.querySelectorAll('.bank-pick').forEach(function (x) { x.classList.remove('selected'); });
            el.classList.add('selected');
            syncHiddenInputs(document.querySelector('input[name="payment_method"]:checked')?.value || 'cod');
        });
    });
    document.querySelectorAll('.bank-picker').forEach(function (g) {
        var checked = g.querySelector('input:checked');
        if (checked) checked.closest('.bank-pick').classList.add('selected');
    });

    /* Card ID input — chỉ số */
    var cardIdInput = document.getElementById('cardIdInput');
    if (cardIdInput) {
        cardIdInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 19);
            syncHiddenInputs('card');
        });
    }

    radios.forEach(function (r) { r.addEventListener('change', updateExtras); });
    updateExtras();

    /* ============================================================
       Voucher
       ============================================================ */
    var voucherInput = document.getElementById('voucherCode');
    var checkBtn     = document.getElementById('checkVoucherBtn');
    var msgBox       = document.getElementById('voucherMessage');

    function showVoucherMsg(text, type) {
        if (!msgBox) return;
        msgBox.hidden = false;
        msgBox.textContent = text;
        msgBox.className = 'voucher-message ' + (type === 'success' ? 'success' : 'error');
    }

    checkBtn?.addEventListener('click', function () {
        var code = voucherInput ? voucherInput.value.trim().toUpperCase() : '';
        if (!code) {
            showVoucherMsg('Vui lòng nhập mã giảm giá.', 'error');
            updateSummary(0);
            return;
        }
        checkBtn.disabled = true;
        var oldText = checkBtn.textContent;
        checkBtn.textContent = '...';

        fetch('ajax/check_voucher.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'code=' + encodeURIComponent(code)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            showVoucherMsg(data.msg, data.ok ? 'success' : 'error');
            updateSummary(data.ok ? data.discount : 0, code);
        })
        .catch(function () { showVoucherMsg('Không thể kết nối tới máy chủ.', 'error'); })
        .finally(function () {
            checkBtn.disabled = false;
            checkBtn.textContent = oldText;
        });
    });

    voucherInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); checkBtn?.click(); }
    });

    document.getElementById('applyBirthdayVoucher')?.addEventListener('click', function () {
        var bCode = this.closest('.birthday-code')?.querySelector('b')?.textContent?.trim() || '';
        if (voucherInput && bCode) {
            voucherInput.value = bCode;
            checkBtn?.click();
        }
    });

    var initDisc = <?php echo (int)$discountAmount; ?>;
    var initCode = <?php echo $voucherRow ? "'" . addslashes($voucherRow['code']) . "'" : "''"; ?>;
    updateSummary(initDisc, initCode);

    /* ============================================================
       DOM refs
       ============================================================ */
    var form          = document.getElementById('checkoutForm');
    var loadingOv     = document.getElementById('loadingOverlay');
    var loadingBox    = document.getElementById('loadingBox');
    var loadingText   = document.getElementById('loadingText');
    var loadingSub    = document.getElementById('loadingSubtext');

    var otpModal      = document.getElementById('otpModal');
    var otpInputs     = document.querySelectorAll('#otpInputs .otp-input');
    var otpEmailDisp  = document.getElementById('otpEmailDisplay');
    var btnOtpConfirm = document.getElementById('btnOtpConfirm');
    var otpErrorMsg   = document.getElementById('otpErrorMsg');
    var otpCloseBtn   = document.getElementById('otpCloseBtn');
    var resendOtpBtn  = document.getElementById('resendOtpBtn');

    var pinModal         = document.getElementById('pinModal');
    var pinCloseBtn      = document.getElementById('pinCloseBtn');
    var pinIcon          = document.getElementById('pinIcon');
    var pinTitle         = document.getElementById('pinTitle');
    var pinSubtitle      = document.getElementById('pinSubtitle');
    var pinVerifyBlock   = document.getElementById('pinVerifyBlock');
    var pinSetupBlock    = document.getElementById('pinSetupBlock');
    var pinInputsVerify  = document.querySelectorAll('#pinInputsVerify .pin-input');
    var pinInputsSetup   = document.querySelectorAll('#pinInputsSetup .pin-input');
    var pinInputsConfirm = document.querySelectorAll('#pinInputsConfirm .pin-input');
    var btnPinConfirm    = document.getElementById('btnPinConfirm');
    var pinErrorMsg      = document.getElementById('pinErrorMsg');

    if (!form) return;

    /* ============================================================
       State
       ============================================================ */
    var otpVerified = false;
    var otpSending  = false;
    var pinVerified = false;
    var pinHasPin   = false;
    var pinMode     = 'verify';
    var currentPaymentMethod = 'cod';

    /* ============================================================
       Helpers: loading
       ============================================================ */
    function showLoading(text, subtext) {
        loadingBox.classList.remove('is-success');
        if (loadingText) loadingText.textContent = text || 'Đang xử lý...';
        if (loadingSub)  loadingSub.textContent  = subtext || 'Vui lòng không đóng cửa sổ này';
        loadingOv?.classList.add('show');
    }
    function showSuccess(text, subtext) {
        loadingBox.classList.add('is-success');
        if (loadingText) loadingText.textContent = text || '✅ Thành công!';
        if (loadingSub)  loadingSub.textContent  = subtext || '';
        loadingOv?.classList.add('show');
    }
    function hideLoading() {
        loadingOv?.classList.remove('show');
        loadingBox.classList.remove('is-success');
    }
    function delayThen(ms, cb) { setTimeout(cb, ms); }

    /* ============================================================
       OTP modal
       ============================================================ */
    function openOtpModal() {
        otpModal?.classList.add('show');
        otpErrorMsg.textContent = '';
        otpErrorMsg.style.color = '';
        otpInputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
        btnOtpConfirm.disabled = true;
        btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
        setTimeout(function () { otpInputs[0]?.focus(); }, 300);
    }
    function closeOtpModal() { otpModal?.classList.remove('show'); }

    /* ============================================================
       PIN modal
       ============================================================ */
    function attachPinInputHandlers(inputs, nextGroup) {
        inputs.forEach(function (input, idx) {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '');
                if (this.value) {
                    this.classList.add('filled');
                    if (idx < inputs.length - 1) inputs[idx + 1].focus();
                    else if (nextGroup) nextGroup[0]?.focus();
                } else {
                    this.classList.remove('filled');
                }
                checkPinAllFilled();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx - 1].focus();
            });
            input.addEventListener('paste', function (e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text')
                    .replace(/\D/g, '').slice(0, 6);
                if (!pasted) return;
                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = pasted[i] || '';
                    inputs[i].classList.toggle('filled', !!pasted[i]);
                }
                if (nextGroup && pasted.length >= 6) nextGroup[0]?.focus();
                else inputs[Math.min(pasted.length, inputs.length - 1)].focus();
                checkPinAllFilled();
            });
        });
    }

    attachPinInputHandlers(pinInputsVerify);
    attachPinInputHandlers(pinInputsSetup, pinInputsConfirm);
    attachPinInputHandlers(pinInputsConfirm);

    function getPinValue(inputs) {
        return Array.from(inputs).map(function (i) { return i.value; }).join('');
    }
    function clearPinInputs(inputs) {
        inputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
    }
    function checkPinAllFilled() {
        var filled = false;
        if (pinMode === 'verify') {
            filled = getPinValue(pinInputsVerify).length === 6;
        } else {
            filled = getPinValue(pinInputsSetup).length === 6
                  && getPinValue(pinInputsConfirm).length === 6;
        }
        btnPinConfirm.disabled = !filled;
    }

    function openPinModal() {
        pinModal.classList.add('show');
        pinErrorMsg.textContent = '';
        pinErrorMsg.style.color = '';

        clearPinInputs(pinInputsVerify);
        clearPinInputs(pinInputsSetup);
        clearPinInputs(pinInputsConfirm);

        fetch('ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=status'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            pinHasPin = data.has_pin === true;
            pinMode = pinHasPin ? 'verify' : 'setup';

            if (pinMode === 'verify') {
                pinIcon.textContent = '🔐';
                pinTitle.textContent = 'Xác minh mã PIN';
                pinSubtitle.textContent = 'Nhập mã PIN 6 số để xác nhận thanh toán';
                pinVerifyBlock.hidden = false;
                pinSetupBlock.hidden = true;
                setTimeout(function () { pinInputsVerify[0]?.focus(); }, 300);
            } else {
                pinIcon.textContent = '🆕';
                pinTitle.textContent = 'Thiết lập mã PIN';
                pinSubtitle.textContent = 'Đây là lần đầu bạn thanh toán. Vui lòng tạo mã PIN 6 số.';
                pinVerifyBlock.hidden = true;
                pinSetupBlock.hidden = false;
                setTimeout(function () { pinInputsSetup[0]?.focus(); }, 300);
            }
            checkPinAllFilled();
        })
        .catch(function () {
            pinErrorMsg.textContent = 'Không thể kiểm tra trạng thái PIN.';
        });
    }

    function closePinModal() {
        pinModal.classList.remove('show');
    }
/* ============================================================
   BANK CHOICE MODAL — Sau PIN thành công với bank
   ============================================================ */
function showBankChoiceModal() {
    var modal = document.getElementById('bankChoiceModal');
    if (modal) modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeBankChoiceModal() {
    var modal = document.getElementById('bankChoiceModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    var btnPay = document.getElementById('btnPayNow');
    var btnHome = document.getElementById('btnGoHome');

    /* "Thanh toán ngay" → submit form với redirect_after_order = payment */
    if (btnPay) {
        btnPay.addEventListener('click', function () {
            document.getElementById('redirectAfterOrder').value = 'payment';
            syncHiddenInputs('bank');

            closeBankChoiceModal();
            showLoading('Đang chuyển tới trang thanh toán...', 'Vui lòng đợi...');

            setTimeout(function () { form.submit(); }, 900);
        });
    }

    /* "Về trang chủ" → submit form với redirect_after_order = home */
    if (btnHome) {
        btnHome.addEventListener('click', function () {
            document.getElementById('redirectAfterOrder').value = 'home';
            syncHiddenInputs('bank');

            closeBankChoiceModal();
            showLoading('Đã đặt hàng thành công!', 'Đang quay về trang chủ...');

            setTimeout(function () { form.submit(); }, 900);
        });
    }
});
    /* ============================================================
       proceedAfterPin
       ============================================================ */
    function proceedAfterPin(method) {
    /* ============================================================
       🆕 BANK — Hiện modal chọn "Thanh toán ngay" hoặc "Về trang chủ"
       ============================================================ */
    if (method === 'bank') {
        showLoading('Đang ghi nhận đơn hàng...', 'Vui lòng không đóng cửa sổ này');
        delayThen(800, function () {
            hideLoading();
            showBankChoiceModal();
        });
        return;
    }

    /* COD / WALLET — Submit ngay như cũ */
    if (method === 'cod' || method === 'wallet') {
        var msg = method === 'wallet'
            ? 'Đang thanh toán bằng ví...'
            : 'Đang xử lý đơn hàng...';

        showLoading(msg, 'Vui lòng không đóng cửa sổ này');
        delayThen(1800, function () {
            syncHiddenInputs(method);
            form.submit();
        });
        return;
    }

    /* ... phần card giữ nguyên ... */
    if (otpVerified) {
        showSuccess('✅ Thanh toán thành công!', 'Đang hoàn tất đơn hàng...');
        delayThen(1500, function () {
            syncHiddenInputs(method);
            form.submit();
        });
        return;
    }

    showLoading('Đang kết nối cổng thanh toán...', 'Vui lòng không đóng cửa sổ này');
    delayThen(1800, function () {
        // ... code card gửi OTP giữ nguyên
    });
}

    /* ============================================================
       Form submit
       ============================================================ */
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var phone = document.querySelector('input[name="phone"]').value.trim();
        if (!/^[0-9+\-\s()]{8,20}$/.test(phone)) {
            alert('Số điện thoại không hợp lệ!');
            return;
        }

        var checked = document.querySelector('input[name="payment_method"]:checked');
        var method = checked ? checked.value : 'cod';
        currentPaymentMethod = method;

        if (method === 'bank') {
            var bankChecked = document.querySelector('#bankPicker input[name="linked_bank_id"]:checked');
            if (!bankChecked) {
                alert('Vui lòng chọn ngân hàng thanh toán.');
                return;
            }
        }
        if (method === 'card') {
            var cardBankChecked = document.querySelector('#cardBankPicker input[name="card_bank_pick"]:checked');
            if (!cardBankChecked) {
                alert('Vui lòng chọn ngân hàng phát hành thẻ.');
                return;
            }
            var cardVal = (document.getElementById('cardIdInput').value || '').replace(/\D/g, '');
            if (!/^\d{12,19}$/.test(cardVal)) {
                alert('ID thẻ phải là 12-19 chữ số.');
                return;
            }
        }

        syncHiddenInputs(method);

        if (!pinVerified) {
            openPinModal();
            return;
        }

        proceedAfterPin(method);
    });

    /* ============================================================
       OTP handlers
       ============================================================ */
    otpInputs.forEach(function (input, idx) {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
            if (this.value) {
                this.classList.add('filled');
                if (idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
            } else { this.classList.remove('filled'); }
            checkAllFilled();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) otpInputs[idx - 1].focus();
            if (e.key === 'ArrowLeft' && idx > 0) otpInputs[idx - 1].focus();
            if (e.key === 'ArrowRight' && idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
        });
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            for (var i = 0; i < otpInputs.length; i++) {
                otpInputs[i].value = pasted[i] || '';
                otpInputs[i].classList.toggle('filled', !!pasted[i]);
            }
            otpInputs[Math.min(pasted.length, otpInputs.length - 1)].focus();
            checkAllFilled();
        });
    });

    function checkAllFilled() {
        var filled = true;
        otpInputs.forEach(function (i) { if (!i.value) filled = false; });
        btnOtpConfirm.disabled = !filled;
    }

    btnOtpConfirm?.addEventListener('click', function () {
        var code = Array.from(otpInputs).map(function (i) { return i.value; }).join('');
        if (code.length !== 6) return;

        btnOtpConfirm.disabled = true;
        btnOtpConfirm.textContent = 'Đang xác minh...';

        fetch('ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=verify&code=' + encodeURIComponent(code)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                otpVerified = true;
                closeOtpModal();
                showSuccess('✅ Thanh toán thành công!', 'Đang hoàn tất đơn hàng...');
                delayThen(1800, function () {
                    syncHiddenInputs('card');
                    form.submit();
                });
            } else {
                otpErrorMsg.textContent = data.msg || 'Mã không đúng.';
                otpInputs.forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });
                btnOtpConfirm.disabled = false;
                btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
            }
        })
        .catch(function () {
            otpErrorMsg.textContent = 'Không thể kết nối tới máy chủ.';
            btnOtpConfirm.disabled = false;
            btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
        });
    });

    otpCloseBtn?.addEventListener('click', function () {
        if (confirm('Bạn muốn hủy xác minh? Đơn hàng sẽ không được đặt.')) {
            closeOtpModal();
            otpErrorMsg.textContent = '';
        }
    });

    resendOtpBtn?.addEventListener('click', function () {
        if (otpSending) return;
        otpSending = true;
        otpErrorMsg.textContent = '';
        otpErrorMsg.style.color = '';

        fetch('ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            otpSending = false;
            if (data.ok) {
                otpInputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
                btnOtpConfirm.disabled = true;
                btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
                otpInputs[0]?.focus();
                otpErrorMsg.textContent = '✅ Đã gửi lại mã mới tới email của bạn.';
                otpErrorMsg.style.color = '#166534';
            } else {
                otpErrorMsg.textContent = data.msg || 'Không thể gửi lại.';
                otpErrorMsg.style.color = '';
            }
        })
        .catch(function () {
            otpSending = false;
            otpErrorMsg.textContent = 'Không thể kết nối tới máy chủ.';
        });
    });

    /* ============================================================
       PIN confirm
       ============================================================ */
    btnPinConfirm?.addEventListener('click', function () {
        var pin, pinConfirm = '', action;

        if (pinMode === 'verify') {
            pin = getPinValue(pinInputsVerify);
            action = 'verify';
        } else {
            pin = getPinValue(pinInputsSetup);
            pinConfirm = getPinValue(pinInputsConfirm);
            action = 'set';
        }

        btnPinConfirm.disabled = true;
        btnPinConfirm.textContent = 'Đang xử lý...';

        var body = 'action=' + action + '&pin=' + encodeURIComponent(pin);
        if (pinConfirm) body += '&pin_confirm=' + encodeURIComponent(pinConfirm);

        fetch('ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                pinVerified = true;
                closePinModal();

                proceedAfterPin(currentPaymentMethod);
            } else {
                pinErrorMsg.textContent = data.msg || 'Có lỗi xảy ra.';
                pinErrorMsg.style.color = '';

                (pinMode === 'verify' ? pinInputsVerify : pinInputsSetup).forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });

                clearPinInputs(pinInputsVerify);
                clearPinInputs(pinInputsSetup);
                clearPinInputs(pinInputsConfirm);
                btnPinConfirm.disabled = true;
                btnPinConfirm.textContent = 'XÁC NHẬN PIN';
                setTimeout(function () {
                    if (pinMode === 'verify') pinInputsVerify[0]?.focus();
                    else pinInputsSetup[0]?.focus();
                }, 300);
            }
        })
        .catch(function () {
            pinErrorMsg.textContent = 'Không thể kết nối tới máy chủ.';
            btnPinConfirm.disabled = false;
            btnPinConfirm.textContent = 'XÁC NHẬN PIN';
        });
    });

    pinCloseBtn?.addEventListener('click', function () {
        if (confirm('Bạn muốn hủy xác minh? Đơn hàng sẽ không được đặt.')) {
            closePinModal();
        }
    });

})();
</script>
<!-- MODAL BANK CHOICE — Sau khi PIN thành công với phương thức bank -->
<div class="bank-choice-overlay" id="bankChoiceModal" aria-hidden="true">
    <div class="bank-choice-box" role="dialog" aria-modal="true">

        <div class="bank-choice-icon">✓</div>

        <h2 class="bank-choice-title">Đơn hàng đã sẵn sàng!</h2>

        <p class="bank-choice-desc">
            Xác minh mã PIN thành công. Bạn muốn làm gì tiếp theo?
        </p>

        <p class="bank-choice-subdesc">
            <b>Thanh toán ngay</b> để hoàn tất đơn hàng,<br>
            hoặc <b>Về trang chủ</b> để thanh toán sau.
        </p>

        <div class="bank-choice-actions">
            <button type="button" class="bank-choice-btn bank-choice-btn--primary" id="btnPayNow">
                <span class="bank-choice-icon--pay"></span>
                Thanh toán ngay
            </button>

            <button type="button" class="bank-choice-btn bank-choice-btn--ghost" id="btnGoHome">
                 Về trang chủ
            </button>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>