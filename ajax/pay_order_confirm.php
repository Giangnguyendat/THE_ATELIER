<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

while (ob_get_level() > 0) ob_end_clean();
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Method không hợp lệ']); exit;
    }
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Chưa đăng nhập']); exit;
    }
    if (empty($_SESSION['pin_verified']) || empty($_SESSION['otp_verified'])) {
        echo json_encode(['ok' => false, 'error' => 'Vui lòng xác minh PIN và OTP trước']); exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf   = $input['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Phiên hết hạn']); exit;
    }

    $orderId = (int)($input['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng không hợp lệ']); exit;
    }

    // Lấy order + bank info + user
    $stmt = $pdo->prepare("
        SELECT o.*, 
               lb.bank_name, lb.account_no, lb.account_name,
               u.email, u.full_name AS user_full_name
        FROM orders o
        LEFT JOIN linked_banks lb ON lb.id = o.linked_bank_id
        JOIN users u ON u.id = o.user_id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'Không tìm thấy đơn hàng']); exit;
    }
    if (in_array($order['payment_status'], ['paid', 'refund_pending'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng đã được thanh toán']); exit;
    }
    if ($order['status'] === 'cancelled') {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng đã hủy']); exit;
    }
    if ($order['payment_method'] !== 'bank') {
        echo json_encode(['ok' => false, 'error' => 'Phương thức không hợp lệ']); exit;
    }

    $total = (float)$order['total_amount'];

    // Update order → paid
    $pdo->beginTransaction();
    try {
                $stmt = $pdo->prepare("
            UPDATE orders
            SET payment_status = 'paid',
                paid_at        = NOW(),
                status         = CASE WHEN status = 'pending' THEN 'confirmed' ELSE status END
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);

        // Log vào wallet_transactions để dễ track
        try {
            $pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, status, description, order_id, created_at)
                VALUES (?, 'payment', ?, 0, 0, 'completed', ?, ?, NOW())
            ")->execute([
                $userId,
                $total,
                'Thanh toán đơn #' . $orderId . ' từ ' . $bankLabel,
                $orderId,
            ]);
        } catch (Throwable $e) { /* ignore nếu table chưa có */ }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[pay_order_confirm] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Lỗi cập nhật đơn hàng']); exit;
    }

    // Clear session flags
    unset($_SESSION['pin_verified'], $_SESSION['otp_verified'], $_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['otp_attempts']);

    // Gửi mail xác nhận
    try {
        require_once __DIR__ . '/../includes/mailer.php';

        $safeName  = htmlspecialchars($order['user_full_name'] ?: 'bạn', ENT_QUOTES, 'UTF-8');
        $safeTotal = number_format($total, 0, ',', '.') . 'đ';
        $safeBank  = htmlspecialchars($order['bank_name'] ?? 'Ngân hàng', ENT_QUOTES, 'UTF-8');
        $safeAcct  = '•••• ' . substr($order['account_no'] ?? '0000', -4);
        $timeNow   = date('d/m/Y H:i');

        $subject = "Thanh toán đơn #$orderId thành công — THE ATELIER";
        $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
          <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
          <p style='color:#8a8377;font-size:13px;margin:0 0 20px;letter-spacing:2px;text-transform:uppercase'>Xác nhận thanh toán</p>

          <p>Xin chào <strong>{$safeName}</strong>,</p>
          <p>Đơn hàng <strong>#{$orderId}</strong> của bạn đã được thanh toán thành công.</p>

          <div style='background:#fffdf9;border:1px solid #e5dfd2;border-radius:8px;padding:16px 20px;margin:20px 0'>
            <div style='display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #eee'>
              <span style='color:#8a8377'>Số tiền:</span>
              <strong style='color:#a8461f;font-family:monospace'>{$safeTotal}</strong>
            </div>
            <div style='display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #eee'>
              <span style='color:#8a8377'>Từ tài khoản:</span>
              <strong>{$safeBank} {$safeAcct}</strong>
            </div>
            <div style='display:flex;justify-content:space-between;padding:6px 0'>
              <span style='color:#8a8377'>Thời gian:</span>
              <strong>{$timeNow}</strong>
            </div>
          </div>

          <p>Đơn hàng của bạn sẽ được xử lý trong thời gian sớm nhất. Bạn có thể theo dõi trạng thái tại trang <strong>Tài khoản → Đơn hàng của tôi</strong>.</p>

          <p style='color:#8a8377;font-size:12px;border-top:1px solid #ddd;padding-top:14px;margin-top:22px'>
            Nếu có thắc mắc, vui lòng liên hệ hotline <strong>1900 123 456</strong>.
          </p>
        </div>";

        if (function_exists('send_mail')) {
            send_mail($order['email'], $subject, $body);
        } elseif (function_exists('sendMail')) {
            sendMail($order['email'], $order['user_full_name'] ?: 'bạn', $subject, $body);
        }
    } catch (Throwable $e) {
        error_log('[pay_order_confirm] mail: ' . $e->getMessage());
    }

    ob_end_clean();
    echo json_encode([
        'ok'    => true,
        'msg'   => 'Thanh toán thành công!',
        'total' => $total,
    ]);

} catch (Throwable $e) {
    error_log('[pay_order_confirm] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}