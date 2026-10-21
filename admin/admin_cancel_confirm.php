<?php
/**
 * ADMIN — Xác nhận hủy đơn hàng
 * Bước 2: Verify OTP → Hủy đơn + Hoàn kho + Hoàn voucher + Gửi mail cho khách
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
$otp     = trim($_POST['otp'] ?? '');
$reason  = trim($_POST['reason'] ?? '');

if ($orderId <= 0) {
    jsonExit(['ok' => false, 'msg' => 'Đơn hàng không hợp lệ.']);
}
if (!preg_match('/^\d{6}$/', $otp)) {
    jsonExit(['ok' => false, 'msg' => 'OTP phải là 6 chữ số.']);
}
if ($reason === '') {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập lý do hủy đơn.']);
}
if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

/* ---------- 4. Verify OTP từ session ---------- */
$sess = $_SESSION['admin_cancel_otp'] ?? null;

if (!$sess || (int)($sess['order_id'] ?? 0) !== $orderId) {
    jsonExit(['ok' => false, 'msg' => 'Chưa có OTP cho đơn này. Vui lòng gửi lại OTP.']);
}

if (time() > (int)($sess['expires_at'] ?? 0)) {
    unset($_SESSION['admin_cancel_otp']);
    jsonExit(['ok' => false, 'msg' => 'OTP đã hết hạn. Vui lòng gửi lại OTP mới.']);
}

if ((int)($sess['attempts'] ?? 0) >= 5) {
    unset($_SESSION['admin_cancel_otp']);
    jsonExit(['ok' => false, 'msg' => 'Nhập sai OTP quá nhiều lần. Vui lòng gửi lại OTP mới.']);
}

if (!hash_equals((string)$sess['code'], $otp)) {
    $_SESSION['admin_cancel_otp']['attempts'] = (int)($sess['attempts'] ?? 0) + 1;
    $left = 5 - $_SESSION['admin_cancel_otp']['attempts'];
    jsonExit(['ok' => false, 'msg' => "❌ OTP không đúng. Còn {$left} lần thử."]);
}

/* ---------- 5. Load order ---------- */
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy đơn hàng.']);
}
if ($order['status'] === 'cancelled') {
    unset($_SESSION['admin_cancel_otp']);
    jsonExit(['ok' => false, 'msg' => 'Đơn đã hủy rồi.']);
}
if ($order['status'] === 'delivered') {
    jsonExit(['ok' => false, 'msg' => 'Đơn đã giao thành công — không thể hủy.']);
}

/* ---------- 6. Transaction ---------- */
try {
    $pdo->beginTransaction();

    /* 6.1. Hoàn kho + giảm sold_count */
    $stmtItems = $pdo->prepare("SELECT product_id, size, quantity FROM order_items WHERE order_id = ?");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        if (!empty($it['size'])) {
            $pdo->prepare("
                UPDATE product_sizes
                SET stock = stock + ?
                WHERE product_id = ? AND size = ?
            ")->execute([(int)$it['quantity'], (int)$it['product_id'], $it['size']]);
        }

        $pdo->prepare("
            UPDATE products
            SET sold_count = GREATEST(0, sold_count - ?)
            WHERE id = ?
        ")->execute([(int)$it['quantity'], (int)$it['product_id']]);
    }

    /* 6.2. Hoàn voucher (nếu có) */
    if (!empty($order['voucher_code'])) {
        $pdo->prepare("
            UPDATE vouchers
            SET used = 0, used_at = NULL, order_id = NULL
            WHERE code = ? AND order_id = ?
        ")->execute([$order['voucher_code'], $orderId]);
    }

    /* 6.3. Update order */
    $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled',
            cancel_reason = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$reason, $orderId]);

    $pdo->commit();
    unset($_SESSION['admin_cancel_otp']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}

/* ============================================================
   7. GỬI MAIL THÔNG BÁO CHO KHÁCH HÀNG
   ============================================================ */
$mailSent = false;
$mailError = '';

try {
    require_once __DIR__ . '/../includes/mailer.php';

    // Lấy thông tin khách
    $stmtU = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $stmtU->execute([$order['user_id']]);
    $customer = $stmtU->fetch(PDO::FETCH_ASSOC);

    if ($customer && !empty($customer['email'])) {

        $customerName = htmlspecialchars($customer['full_name']);
        $reasonHtml   = nl2br(htmlspecialchars($reason));
        $totalFmt     = number_format((float)$order['total_amount'], 0, ',', '.');
        $orderDate    = date('d/m/Y H:i', strtotime($order['created_at']));
        $cancelDate   = date('d/m/Y H:i');

        $subject = '❌ THE ATELIER đã hủy đơn hàng #' . $orderId . ' của bạn';

        $body = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f8f5ef;font-family:Arial,Helvetica,sans-serif;color:#24211d;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;">

        <!-- HEADER -->
        <div style="background:#1c1b19;padding:32px 24px;text-align:center;">
            <h1 style="margin:0;font-family:Georgia,serif;font-size:24px;color:#fffdf9;letter-spacing:3px;font-weight:400;">
                THE ATELIER
            </h1>
            <p style="margin:8px 0 0;font-size:11px;color:#b98a1d;letter-spacing:3px;text-transform:uppercase;">
                Thông báo hủy đơn hàng
            </p>
        </div>

        <!-- BODY -->
        <div style="padding:32px 32px 24px;">

            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                Xin chào <b>' . $customerName . '</b>,
            </p>

            <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#4b5563;">
                <b style="color:#b91c1c;">THE ATELIER đã hủy đơn hàng của bạn</b> do:
            </p>

            <!-- LÝ DO HỦY -->
            <div style="background:#fef2f2;border-left:4px solid #b91c1c;padding:16px 20px;margin-bottom:24px;border-radius:6px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#991b1b;font-weight:700;margin-bottom:8px;">
                    Lý do hủy đơn
                </div>
                <div style="font-size:15px;color:#7f1d1d;line-height:1.7;font-weight:600;">
                    ' . $reasonHtml . '
                </div>
            </div>

            <!-- THÔNG TIN ĐƠN -->
            <div style="background:#faf8f4;border:1px solid #efeae0;border-radius:10px;padding:20px;margin-bottom:24px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#8a8377;font-weight:700;margin-bottom:14px;">
                    Thông tin đơn hàng
                </div>
                <table style="width:100%;font-size:14px;border-collapse:collapse;">
                    <tr>
                        <td style="padding:6px 0;color:#6b7280;">Mã đơn hàng:</td>
                        <td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace;color:#a8461f;">
                            #' . $orderId . '
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#6b7280;">Ngày đặt:</td>
                        <td style="padding:6px 0;text-align:right;font-weight:600;">
                            ' . $orderDate . '
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#6b7280;">Ngày hủy:</td>
                        <td style="padding:6px 0;text-align:right;font-weight:600;">
                            ' . $cancelDate . '
                        </td>
                    </tr>
                    <tr style="border-top:1px solid #efeae0;">
                        <td style="padding:12px 0 6px;color:#6b7280;font-weight:600;">Tổng tiền:</td>
                        <td style="padding:12px 0 6px;text-align:right;font-weight:800;font-family:monospace;font-size:16px;color:#a8461f;">
                            ' . $totalFmt . 'đ
                        </td>
                    </tr>
                </table>
            </div>

            <!-- HOÀN TIỀN -->
            <div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
                <div style="font-size:14px;color:#166534;line-height:1.7;">
                    💰 <b>Hoàn tiền:</b>
                    Nếu bạn đã thanh toán cho đơn hàng này, số tiền sẽ được hoàn lại
                    <b>trong vòng 1-3 ngày làm việc</b> qua phương thức thanh toán ban đầu
                    (hoặc ví Atelier Pay).
                </div>
            </div>

            <!-- CTA -->
            <div style="text-align:center;margin-bottom:24px;">
                <a href="http://localhost/clothing_shop/products.php"
                   style="display:inline-block;background:#a8461f;color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:6px;font-size:14px;font-weight:700;letter-spacing:0.5px;">
                    TIẾP TỤC MUA SẮM
                </a>
            </div>

            <p style="margin:0 0 8px;font-size:14px;color:#6b7280;line-height:1.7;">
                Nếu bạn có bất kỳ thắc mắc nào, vui lòng liên hệ:
            </p>
            <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.7;">
                📞 Hotline: <b style="color:#24211d;">1900 123 456</b><br>
                📧 Email: <b style="color:#24211d;">support@theatelier.vn</b>
            </p>

            <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.7;">
                Xin lỗi vì sự bất tiện này. Rất mong được phục vụ bạn trong những đơn hàng tiếp theo. 💛
            </p>

        </div>

        <!-- FOOTER -->
        <div style="background:#faf8f4;padding:24px 32px;text-align:center;border-top:1px solid #efeae0;">
            <p style="margin:0 0 6px;font-family:Georgia,serif;font-size:16px;color:#1c1b19;letter-spacing:2px;">
                THE ATELIER
            </p>
            <p style="margin:0;font-size:11px;color:#8a8377;line-height:1.6;">
                Thời trang không chỉ là mặc — mà là phong cách sống.<br>
                © ' . date('Y') . ' THE ATELIER. All rights reserved.
            </p>
        </div>

    </div>
</body>
</html>';

        $mailSent = sendMail(
            $customer['email'],
            $customer['full_name'],
            $subject,
            $body
        );

    } else {
        $mailError = 'Không tìm thấy email khách hàng.';
    }

} catch (Exception $e) {
    $mailError = $e->getMessage();
}

/* ============================================================
   8. Response
   ============================================================ */
$responseMsg = "✅ Đã hủy đơn #{$orderId} thành công.";
if ($mailSent) {
    $responseMsg .= ' Mail thông báo đã gửi tới khách.';
} else {
    $responseMsg .= ' (Lưu ý: Không gửi được mail thông báo'
                  . ($mailError ? ': ' . $mailError : '') . ')';
}

jsonExit([
    'ok'        => true,
    'msg'       => $responseMsg,
    'mail_sent' => $mailSent,
]);