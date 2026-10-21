<?php
/**
 * ADMIN — Gửi OTP để hủy đơn hàng
 * Bước 1: Verify PIN admin → Sinh OTP 6 số → Gửi mail → Lưu session
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 1. Auth ---------- */
if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}

$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền truy cập.']);
}

/* ---------- 2. CSRF ---------- */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
}

/* ---------- 3. Inputs ---------- */
$orderId = (int)($_POST['order_id'] ?? 0);
$pin     = trim($_POST['pin'] ?? '');

if ($orderId <= 0) {
    jsonExit(['ok' => false, 'msg' => 'Đơn hàng không hợp lệ.']);
}
if (!preg_match('/^\d{6}$/', $pin)) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập mã PIN 6 số.']);
}

/* ---------- 4. Check đơn hàng ---------- */
$stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy đơn hàng #' . $orderId]);
}
if ($order['status'] === 'cancelled') {
    jsonExit(['ok' => false, 'msg' => 'Đơn #' . $orderId . ' đã bị hủy rồi.']);
}
if ($order['status'] === 'delivered') {
    jsonExit(['ok' => false, 'msg' => 'Đơn #' . $orderId . ' đã giao thành công — không thể hủy.']);
}

/* ---------- 5. Verify PIN admin ---------- */
$adminId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT email, full_name, payment_pin, pin_attempts, pin_locked_until
    FROM users
    WHERE id = ?
");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy tài khoản admin.']);
}
if (empty($admin['payment_pin'])) {
    jsonExit([
        'ok' => false,
        'msg' => 'Bạn chưa thiết lập mã PIN. Vào Ví Atelier Pay để thiết lập trước.',
    ]);
}

// Check locked
if (!empty($admin['pin_locked_until']) && strtotime($admin['pin_locked_until']) > time()) {
    $sec = strtotime($admin['pin_locked_until']) - time();
    $min = max(1, (int)ceil($sec / 60));
    jsonExit(['ok' => false, 'msg' => "Mã PIN đang bị khóa. Vui lòng thử lại sau {$min} phút."]);
}

// Verify PIN
if (!password_verify($pin, $admin['payment_pin'])) {
    $attempts = (int)$admin['pin_attempts'] + 1;

    if ($attempts >= 5) {
        $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = ? WHERE id = ?")
            ->execute([$lockUntil, $adminId]);
        jsonExit(['ok' => false, 'msg' => '❌ Sai mã PIN 5 lần. Đã khóa 15 phút.']);
    }

    $pdo->prepare("UPDATE users SET pin_attempts = ? WHERE id = ?")
        ->execute([$attempts, $adminId]);

    $left = 5 - $attempts;
    jsonExit(['ok' => false, 'msg' => "❌ Mã PIN không đúng. Còn {$left} lần thử."]);
}

// PIN đúng → reset
$pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")
    ->execute([$adminId]);

/* ---------- 6. Sinh OTP ---------- */
$otp       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = time() + 300; // 5 phút

$_SESSION['admin_cancel_otp'] = [
    'order_id'   => $orderId,
    'code'       => $otp,
    'expires_at' => $expiresAt,
    'attempts'   => 0,
    'created_at' => time(),
];

/* ---------- 7. Gửi mail OTP ---------- */
$mailSent = false;
try {
    require_once __DIR__ . '/../includes/mailer.php';

    $body = '
    <div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;">
        <h2 style="color:#b91c1c;margin-bottom:8px;">🔐 Xác nhận HỦY ĐƠN HÀNG</h2>
        <p>Xin chào <b>' . htmlspecialchars($admin['full_name']) . '</b>,</p>
        <p>Bạn đang thực hiện thao tác <b style="color:#b91c1c;">HỦY ĐƠN HÀNG #' . $orderId . '</b>.</p>
        <p>Nhập mã OTP sau để xác nhận:</p>
        <div style="background:#fef2f2;border:2px dashed #b91c1c;padding:24px;text-align:center;margin:18px 0;border-radius:12px;">
            <div style="font-family:\'Courier New\',monospace;font-size:36px;font-weight:900;letter-spacing:12px;color:#b91c1c;">'
                . $otp . '</div>
        </div>
        <p style="color:#6b7280;font-size:13px;line-height:1.6;">
            ⏱️ Mã có hiệu lực trong <b>5 phút</b>.<br>
            Nếu bạn không thực hiện thao tác này, hãy bỏ qua email và kiểm tra bảo mật tài khoản.
        </p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#8a8377;font-size:12px;">— THE ATELIER Admin System</p>
    </div>';

    $mailSent = sendMail(
        $admin['email'],
        $admin['full_name'],
        'OTP hủy đơn #' . $orderId . ' — THE ATELIER',
        $body
    );
} catch (Exception $e) {
    // ignore
}

if (!$mailSent) {
    unset($_SESSION['admin_cancel_otp']);
    jsonExit(['ok' => false, 'msg' => 'Không thể gửi mail OTP. Vui lòng kiểm tra cấu hình SMTP.']);
}

/* ---------- 8. Mask email ---------- */
$maskedEmail = preg_replace('/(.{2}).+(@.+)/', '$1***$2', $admin['email']);

jsonExit([
    'ok'           => true,
    'msg'          => 'Đã gửi OTP tới ' . $maskedEmail,
    'masked_email' => $maskedEmail,
    'expires_in'   => 300,
]);