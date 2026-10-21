<?php
/**
 * ============================================================
 * CHECK VOUCHER — Áp dụng mã giảm giá
 * ============================================================
 * ⚠️ LƯU Ý: File này CHỈ KIỂM TRA, KHÔNG trừ lượt sử dụng.
 * Việc trừ lượt chỉ xảy ra trong checkout.php khi đơn được tạo.
 * ============================================================
 */
require_once '../includes/config.php';

// Load voucher helper (nếu chưa có ở config)
$helperFile = __DIR__ . '/../includes/voucher_helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;
}

header('Content-Type: application/json; charset=utf-8');

/* ============================================================
   1. AUTH
   ============================================================ */
if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];

/* ============================================================
   2. INPUT
   ============================================================ */
$code = strtoupper(trim($_POST['code'] ?? ''));
if ($code === '') {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập mã.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   3. TÍNH SUBTOTAL TỪ CART
   ============================================================ */
$subtotal = 0;
foreach ($_SESSION['cart'] ?? [] as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
}

if ($subtotal <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Giỏ hàng trống.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   4. LẤY VOUCHER
   Không filter `used = 0` vì voucher multi-use vẫn có used = 0
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT *
    FROM vouchers
    WHERE code = ?
      AND valid_from <= CURDATE()
      AND valid_to   >= CURDATE()
    LIMIT 1
");
$stmt->execute([$code]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$v) {
    echo json_encode(['ok' => false, 'msg' => 'Mã không tồn tại hoặc đã hết hạn.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   5. CHECK SỐ LƯỢT CÒN LẠI (CHỈ ĐỌC, KHÔNG UPDATE)
   ============================================================ */
$usageLimit = isset($v['usage_limit']) ? (int)$v['usage_limit'] : 1;
$usedCount  = isset($v['used_count'])  ? (int)$v['used_count']  : 0;
$legacyUsed = (int)($v['used'] ?? 0);

// Voucher dùng 1 lần (giống cũ)
if ($usageLimit === 1) {
    if ($legacyUsed === 1 || $usedCount >= 1) {
        echo json_encode([
            'ok'  => false,
            'msg' => 'Mã này đã được sử dụng.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
// Voucher có giới hạn N lần
elseif ($usageLimit > 1) {
    if ($usedCount >= $usageLimit) {
        echo json_encode([
            'ok'  => false,
            'msg' => 'Mã này đã hết lượt sử dụng (' . $usedCount . '/' . $usageLimit . ').',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
// Voucher không giới hạn (usage_limit = 0) → luôn pass

/* ============================================================
   6. CHECK QUYỀN SỞ HỮU
   ============================================================ */
if ($v['user_id'] !== null && (int)$v['user_id'] !== $userId) {
    echo json_encode(['ok' => false, 'msg' => 'Mã này không thuộc về bạn.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   7. CHECK ĐƠN TỐI THIỂU
   ============================================================ */
$minOrder = (float)($v['min_order_amount'] ?? 0);
if ($subtotal < $minOrder) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'Đơn tối thiểu ' . number_format($minOrder, 0, ',', '.') . 'đ.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   8. TÍNH TIỀN GIẢM
   ============================================================ */
$percent  = (int)$v['discount_percent'];
$discount = $subtotal * $percent / 100;

$maxDiscount = (float)($v['max_discount_amount'] ?? 0);
if ($maxDiscount > 0 && $discount > $maxDiscount) {
    $discount = $maxDiscount;
}

// Không để giảm quá subtotal
if ($discount > $subtotal) {
    $discount = $subtotal;
}

$discount = round($discount);
$newTotal = max(0, $subtotal - $discount);

/* ============================================================
   9. TÍNH SỐ LƯỢT CÒN LẠI (để hiển thị cho user)
   ============================================================ */
$remainingUses = null;    // null = không giới hạn
$usageMsg      = '';

if ($usageLimit === 0) {
    $remainingUses = null;
    $usageMsg      = 'Mã không giới hạn lượt sử dụng.';
} elseif ($usageLimit === 1) {
    $remainingUses = 0; // Sau khi dùng xong sẽ hết
    $usageMsg      = 'Mã chỉ dùng được 1 lần.';
} else {
    $remainingUses = max(0, $usageLimit - $usedCount);
    $usageMsg      = 'Còn ' . $remainingUses . '/' . $usageLimit . ' lượt sử dụng.';
}

/* ============================================================
   10. LƯU VÀO SESSION (để checkout.php dùng lại)
   ⚠️ Chỉ lưu session, KHÔNG đụng DB
   ============================================================ */
$_SESSION['applied_voucher'] = [
    'id'         => (int)$v['id'],
    'code'       => $v['code'],
    'type'       => $v['type'] ?? 'public',
    'percent'    => $percent,
    'discount'   => $discount,
    'subtotal'   => $subtotal,
    'new_total'  => $newTotal,
    'applied_at' => time(),
];

/* ============================================================
   11. RESPONSE
   ============================================================ */
echo json_encode([
    'ok'             => true,
    'code'           => $v['code'],
    'type'           => $v['type'] ?? 'public',
    'percent'        => $percent,
    'discount'       => (int)$discount,
    'subtotal'       => (int)$subtotal,
    'new_total'      => (int)$newTotal,
    'usage_limit'    => $usageLimit,
    'used_count'     => $usedCount,
    'remaining_uses' => $remainingUses,
    'usage_msg'      => $usageMsg,
    'msg'            => 'Áp dụng thành công! Giảm ' . number_format($discount, 0, ',', '.') . 'đ',
], JSON_UNESCAPED_UNICODE);