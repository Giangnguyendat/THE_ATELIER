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

    $userId = (int)$_SESSION['user_id'];
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf   = $input['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Phiên hết hạn, tải lại trang']); exit;
    }

    $amount = (int)($input['amount'] ?? 0);
    $bankId = (int)($input['bank_id'] ?? 0);

    // Validate
    if ($amount < 10000) {
        echo json_encode(['ok' => false, 'error' => 'Số tiền tối thiểu 10.000đ']); exit;
    }
    if ($amount > 50000000) {
        echo json_encode(['ok' => false, 'error' => 'Số tiền tối đa 50.000.000đ']); exit;
    }
    if ($bankId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Chưa chọn ngân hàng']); exit;
    }

    // Kiểm tra bank thuộc user
    $stmt = $pdo->prepare("SELECT id, bank_name, account_no, account_name FROM linked_banks WHERE id = ? AND user_id = ?");
    $stmt->execute([$bankId, $userId]);
    $bank = $stmt->fetch();
    if (!$bank) {
        echo json_encode(['ok' => false, 'error' => 'Ngân hàng không hợp lệ']); exit;
    }

    // Lấy user + PIN
    $stmt = $pdo->prepare("SELECT email, full_name, payment_pin, pin_locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (empty($user['payment_pin'])) {
        echo json_encode(['ok' => false, 'error' => 'Bạn chưa thiết lập mã PIN. Vui lòng thiết lập PIN trước']); exit;
    }

    if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
        $remain = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
        echo json_encode(['ok' => false, 'error' => "PIN đang bị khóa. Vui lòng thử lại sau {$remain} phút"]); exit;
    }

    // Hủy tất cả topup pending cũ của user
    $pdo->prepare("UPDATE topup_requests SET status = 'expired' WHERE user_id = ? AND status = 'pending'")->execute([$userId]);

    // Sinh OTP
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO topup_requests (user_id, amount, linked_bank_id, otp_code, otp_expires_at, status)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'pending')
    ");
    $stmt->execute([$userId, $amount, $bankId, $otp]);
    $topupId = (int)$pdo->lastInsertId();

    // Gửi mail
    require_once __DIR__ . '/../includes/mailer.php';

    $safeName   = htmlspecialchars($user['full_name'] ?: 'bạn', ENT_QUOTES, 'UTF-8');
    $safeAmount = number_format($amount, 0, ',', '.') . 'đ';
    $safeBank   = htmlspecialchars($bank['bank_name'] . ' **** ' . substr($bank['account_no'], -4), ENT_QUOTES, 'UTF-8');

    $subject = "Mã OTP nạp tiền Atelier Pay — THE ATELIER";
    $body = "
    <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
      <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
      <p style='color:#8a8377;font-size:13px;margin:0 0 20px;letter-spacing:2px;text-transform:uppercase'>Xác nhận nạp tiền Atelier Pay</p>

      <p>Xin chào <strong>{$safeName}</strong>,</p>
      <p>Bạn đang yêu cầu nạp <strong style='color:#a8461f'>{$safeAmount}</strong> vào ví Atelier Pay từ <strong>{$safeBank}</strong>.</p>

      <p>Mã OTP xác nhận của bạn:</p>
      <div style='font-size:34px;letter-spacing:10px;font-weight:700;color:#a8461f;background:#fffdf9;padding:18px;text-align:center;border:1px dashed #a8461f;border-radius:6px;font-family:monospace;margin:16px 0'>{$otp}</div>

      <p>Mã có hiệu lực <strong>5 phút</strong>. Sau khi nhập OTP, bạn cần nhập thêm <strong>mã PIN 6 số</strong> để hoàn tất.</p>
      <p style='color:#8a8377;font-size:12px;border-top:1px solid #ddd;padding-top:14px;margin-top:22px'>
        Nếu bạn không yêu cầu giao dịch này, hãy bỏ qua email và đổi mật khẩu ngay.
      </p>
    </div>";

    $sent = false;
    try {
        if (function_exists('send_mail')) {
            $sent = send_mail($user['email'], $subject, $body);
        } elseif (function_exists('sendMail')) {
            $sent = sendMail($user['email'], $user['full_name'] ?: 'bạn', $subject, $body);
        }
    } catch (Throwable $e) {
        error_log('[wallet_topup_send_otp] mail error: ' . $e->getMessage());
    }

    if (!$sent) {
        $pdo->prepare("UPDATE topup_requests SET status = 'failed' WHERE id = ?")->execute([$topupId]);
        echo json_encode(['ok' => false, 'error' => 'Không gửi được email OTP. Kiểm tra cấu hình SMTP']); exit;
    }

    ob_end_clean();
        echo json_encode([
        'ok'         => true,
        'request_id' => $topupId,
        'topup_id'   => $topupId,
        'bank_name'  => $bank['bank_name'] . ' **** ' . substr($bank['account_no'], -4),
        'expires_in' => 300,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[wallet_topup_send_otp] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}