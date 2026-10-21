<?php
/**
 * Send reset-password OTP
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Xóa mọi output rác
while (ob_get_level() > 0) ob_end_clean();
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Bắt fatal → trả JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'fatal' => true,
            'error' => $err['message'],
            'file'  => $err['file'],
            'line'  => $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($no, $str, $file, $line) {
    error_log("[send_reset_otp] PHP err [$no] $str @ $file:$line");
    return true;
});

try {
    require_once __DIR__ . '/../includes/config.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Method không hợp lệ']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($input['email'] ?? '');
    $csrf  = $input['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Phiên làm việc đã hết hạn, vui lòng tải lại trang']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Địa chỉ email không hợp lệ']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, full_name, is_locked FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Email này chưa được đăng ký trong hệ thống']);
        exit;
    }

    if ((int)$user['is_locked'] === 1) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Tài khoản đã bị khóa. Vui lòng liên hệ hotline 1900 123 456']);
        exit;
    }

    // Rate limit 60s
    $stmt = $pdo->prepare("SELECT created_at FROM email_otps WHERE email = ? AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email]);
    $last = $stmt->fetch();
    if ($last) {
        $elapsed = time() - strtotime($last['created_at']);
        if ($elapsed < 60) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Vui lòng đợi ' . (60 - $elapsed) . ' giây trước khi gửi lại']);
            exit;
        }
    }

    // Rate limit 5/hour
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_otps WHERE email = ? AND purpose = 'reset_password' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$email]);
    if ((int)$stmt->fetchColumn() >= 5) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Bạn đã yêu cầu quá nhiều lần. Thử lại sau 1 giờ']);
        exit;
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'reset_password'")->execute([$email]);
    $stmt = $pdo->prepare("INSERT INTO email_otps (email, otp_code, purpose, expires_at) VALUES (?, ?, 'reset_password', DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
    $stmt->execute([$email, $otp]);

    $_SESSION['reset_email']    = $email;
    $_SESSION['reset_email_ts'] = time();

    // ---- Gửi mail ----
    $safeName = htmlspecialchars($user['full_name'] ?: 'bạn', ENT_QUOTES, 'UTF-8');
    $subject  = "Đặt lại mật khẩu — THE ATELIER";
    $body = "
    <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
      <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
      <p style='color:#8a8377;font-size:13px;margin:0 0 20px;letter-spacing:2px;text-transform:uppercase'>Đặt lại mật khẩu</p>
      <p>Xin chào <strong>{$safeName}</strong>,</p>
      <p>Mã xác minh đặt lại mật khẩu của bạn là:</p>
      <div style='font-size:34px;letter-spacing:10px;font-weight:700;color:#a8461f;background:#fffdf9;padding:18px;text-align:center;border:1px dashed #a8461f;border-radius:6px;font-family:monospace;margin:16px 0'>{$otp}</div>
      <p>Mã có hiệu lực trong <strong>5 phút</strong>. Không chia sẻ mã này với bất kỳ ai.</p>
      <p style='color:#8a8377;font-size:12px;border-top:1px solid #ddd;padding-top:14px;margin-top:22px'>
        Nếu bạn không yêu cầu, hãy bỏ qua email.
      </p>
    </div>";

    $sent = false;
    $mailErr = '';
    try {
        require_once __DIR__ . '/../includes/mailer.php';
if (function_exists('send_mail')) {
    $sent = send_mail($email, $subject, $body);
} elseif (function_exists('sendMail')) {
    $toName = $user['full_name'] ?: 'bạn';
    $sent = sendMail($email, $toName, $subject, $body);   // ← 4 tham số
} else {
    $mailErr = 'mailer.php không có hàm send_mail/sendMail';
}
    } catch (Throwable $e) {
        $mailErr = 'Mailer exception: ' . $e->getMessage();
        error_log('[send_reset_otp] ' . $mailErr);
    }

    if (!$sent) {
        $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'reset_password'")->execute([$email]);
        ob_end_clean();
        echo json_encode([
            'ok'    => false,
            'error' => 'Không gửi được email: ' . ($mailErr ?: 'kiểm tra cấu hình SMTP'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Log
    try {
        $pdo->prepare("INSERT INTO email_logs (user_id, type, email, status, sent_at) VALUES (?, 'reset_password_otp', ?, 'sent', NOW())")
            ->execute([$user['id'], $email]);
    } catch (Throwable $e) { /* bỏ qua nếu bảng log khác schema */ }

    ob_end_clean();
    echo json_encode([
        'ok'         => true,
        'message'    => 'Đã gửi mã OTP tới ' . $email,
        'expires_in' => 300,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}