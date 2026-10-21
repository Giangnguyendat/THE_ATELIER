<?php
/**
 * ajax/send_register_otp.php
 * Gửi OTP xác minh đăng ký tài khoản
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Xóa mọi output rác (BOM, warning, echo lỡ)
while (ob_get_level() > 0) ob_end_clean();
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Bắt fatal error → vẫn trả JSON
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

// Chặn warning in ra output
set_error_handler(function ($no, $str, $file, $line) {
    error_log("[send_register_otp] PHP err [$no] $str @ $file:$line");
    return true;
});

try {
    require_once __DIR__ . '/../includes/config.php';

    // Fix timezone MySQL cho khớp PHP (Asia/Ho_Chi_Minh = UTC+7)
    try {
        $pdo->exec("SET time_zone = '+07:00'");
    } catch (Throwable $e) { /* bỏ qua nếu user không có quyền */ }

    /* ---------- Chỉ nhận POST ---------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Method không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Parse input ---------- */
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($input['email'] ?? '');
    $csrf  = $input['csrf_token'] ?? '';

    /* ---------- CSRF ---------- */
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Phiên làm việc đã hết hạn, vui lòng tải lại trang'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Validate email ---------- */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Địa chỉ email không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Check email đã tồn tại ---------- */
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'error' => 'Địa chỉ email này đã được sử dụng'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Bỏ rate limit theo yêu cầu ---------- */

    /* ---------- Sinh OTP 6 số ---------- */
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    /* ---------- Xóa OTP cũ, chèn OTP mới (hết hạn 5 phút) ---------- */
    $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'register'")->execute([$email]);

    $stmt = $pdo->prepare("INSERT INTO email_otps (email, otp_code, purpose, expires_at) VALUES (?, ?, 'register', DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
    $stmt->execute([$email, $otp]);

    /* ---------- Chuẩn bị mail ---------- */
    $subject = "Mã xác minh đăng ký — THE ATELIER";
    $body = "
    <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
      <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
      <p style='color:#8a8377;font-size:13px;margin:0 0 20px;letter-spacing:2px;text-transform:uppercase'>Xác minh đăng ký tài khoản</p>

      <p>Xin chào,</p>
      <p>Mã xác minh đăng ký tài khoản của bạn là:</p>

      <div style='font-size:34px;letter-spacing:10px;font-weight:700;color:#a8461f;background:#fffdf9;padding:18px;text-align:center;border:1px dashed #a8461f;border-radius:6px;font-family:monospace;margin:16px 0'>{$otp}</div>

      <p>Mã có hiệu lực trong <strong>5 phút</strong>. Vui lòng không chia sẻ mã này với bất kỳ ai.</p>
      <p style='color:#8a8377;font-size:12px;border-top:1px solid #ddd;padding-top:14px;margin-top:22px'>
        Nếu bạn không yêu cầu mã này, hãy bỏ qua email.
      </p>
    </div>";

    /* ---------- Gửi mail (bọc try/catch) ---------- */
    $sent    = false;
    $mailErr = '';

    try {
        require_once __DIR__ . '/../includes/mailer.php';

        if (function_exists('send_mail')) {
            // Hàm snake_case (nếu có): 3 tham số
            $sent = send_mail($email, $subject, $body);
        } elseif (function_exists('sendMail')) {
            // Hàm camelCase: 4 tham số ($to, $toName, $subject, $htmlBody)
            $sent = sendMail($email, 'bạn', $subject, $body);
        } else {
            $mailErr = 'mailer.php không có hàm send_mail/sendMail';
        }
    } catch (Throwable $e) {
        $mailErr = 'Mailer exception: ' . $e->getMessage();
        error_log('[send_register_otp] ' . $mailErr);
    }

    if (!$sent) {
        // Xóa OTP đã tạo vì không gửi được
        $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'register'")->execute([$email]);

        ob_end_clean();
        echo json_encode([
            'ok'    => false,
            'error' => 'Không gửi được email: ' . ($mailErr ?: 'kiểm tra cấu hình SMTP'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------- Log email ---------- */
    try {
        $pdo->prepare("INSERT INTO email_logs (user_id, type, email, status, sent_at) VALUES (NULL, 'register_otp', ?, 'sent', NOW())")
            ->execute([$email]);
    } catch (Throwable $e) {
        error_log('[send_register_otp] log error: ' . $e->getMessage());
    }

    /* ---------- Trả kết quả ---------- */
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