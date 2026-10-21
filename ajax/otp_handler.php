<?php
/**
 * Xử lý OTP qua EMAIL
 * - action=send   : sinh OTP 6 số → gửi vào email của user
 * - action=verify : so khớp OTP với session
 */
require_once '../includes/config.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
    exit;
}

$action = $_POST['action'] ?? '';

function maskEmail(string $email): string {
    if (!str_contains($email, '@')) return '*******';
    [$name, $domain] = explode('@', $email, 2);
    $len = mb_strlen($name);
    if ($len <= 2) {
        $masked = str_repeat('*', $len);
    } else {
        $masked = mb_substr($name, 0, 1)
                . str_repeat('*', max(1, $len - 2))
                . mb_substr($name, -1);
    }
    return $masked . '@' . $domain;
}

/* ============ SEND OTP ============ */
if ($action === 'send') {
    $lastSent = $_SESSION['otp_last_sent'] ?? 0;
    if (time() - $lastSent < 60) {
        echo json_encode([
            'ok'  => false,
            'msg' => 'Vui lòng đợi ' . (60 - (time() - $lastSent)) . ' giây trước khi gửi lại.',
        ]);
        exit;
    }

    $userEmail = $_SESSION['user']['email']
              ?? $_SESSION['email']
              ?? '';

    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'Tài khoản chưa có email hợp lệ.']);
        exit;
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['otp_code']       = $otp;
    $_SESSION['otp_expires_at'] = time() + 300;
    $_SESSION['otp_attempts']   = 0;
    $_SESSION['otp_last_sent']  = time();

    $userName = $_SESSION['user']['full_name'] ?? 'Khách hàng';
    $subject  = '🔐 Mã OTP xác minh thanh toán — THE ATELIER';

    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="font-family:Arial,Helvetica,sans-serif;background:#f2ece0;margin:0;padding:40px 20px">
        <div style="max-width:520px;margin:0 auto;background:#fffdf9;border:1px solid #8a8377">
            <div style="background:#1c1b19;color:#fffdf9;padding:24px 20px;text-align:center">
                <h1 style="margin:0;font-size:18px;letter-spacing:0.15em">THE ATELIER</h1>
                <p style="margin:6px 0 0;font-size:11px;opacity:0.6;letter-spacing:0.1em">XÁC MINH THANH TOÁN</p>
            </div>
            <div style="padding:32px 28px;color:#1c1b19;line-height:1.65;font-size:15px">
                <p>Xin chào <b>' . htmlspecialchars($userName) . '</b>,</p>
                <p>Bạn đang thực hiện thanh toán tại <b>THE ATELIER</b>. Vui lòng nhập mã OTP dưới đây:</p>
                <div style="background:#fef3c7;border-left:4px solid #d97706;padding:24px 20px;text-align:center;margin:24px 0">
                    <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.15em;text-transform:uppercase;color:#92400e">Mã OTP của bạn</p>
                    <p style="margin:0;font-family:Courier New,monospace;font-size:38px;font-weight:700;letter-spacing:0.35em;color:#78350f">' . $otp . '</p>
                </div>
                <p style="font-size:13px;color:#8a8377;margin-top:24px">
                    ⏱ Mã có hiệu lực trong <b>5 phút</b>.<br>
                    🔒 Không chia sẻ mã này với bất kỳ ai.
                </p>
            </div>
            <div style="background:#e8e0d0;padding:16px;text-align:center;font-size:11px;color:#8a8377">
                © ' . date('Y') . ' THE ATELIER — Email tự động.
            </div>
        </div>
    </body>
    </html>';

    if (!function_exists('sendMail')) {
        echo json_encode([
            'ok'           => true,
            'email_mask'   => maskEmail($userEmail),
            'otp_fallback' => $otp,
            'warning'      => 'Mailer chưa cài. Đang dùng chế độ demo.',
        ]);
        exit;
    }

    $sent = sendMail($userEmail, $userName, $subject, $htmlBody);

    if ($sent) {
        echo json_encode([
            'ok'         => true,
            'email_mask' => maskEmail($userEmail),
        ]);
    } else {
        echo json_encode([
            'ok'           => true,
            'email_mask'   => maskEmail($userEmail),
            'otp_fallback' => $otp,
            'warning'      => 'Không gửi được email. Kiểm tra SMTP.',
        ]);
    }
    exit;
}

/* ============ VERIFY OTP ============ */
if ($action === 'verify') {
    $input = trim($_POST['code'] ?? '');

    if (empty($_SESSION['otp_code'])) {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng yêu cầu mã OTP mới.']);
        exit;
    }

    if (time() > ($_SESSION['otp_expires_at'] ?? 0)) {
        unset($_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['otp_attempts']);
        echo json_encode(['ok' => false, 'msg' => 'Mã OTP đã hết hạn. Vui lòng gửi lại.']);
        exit;
    }

    if (($_SESSION['otp_attempts'] ?? 0) >= 3) {
        unset($_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['otp_attempts']);
        echo json_encode(['ok' => false, 'msg' => 'Đã nhập sai quá 3 lần. Vui lòng gửi lại mã mới.']);
        exit;
    }

    if (!hash_equals($_SESSION['otp_code'], $input)) {
        $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
        $left = 3 - $_SESSION['otp_attempts'];
        echo json_encode(['ok' => false, 'msg' => "Mã OTP không đúng. Còn {$left} lần thử."]);
        exit;
    }

    // ✅ Hỗ trợ context — checkout (mặc định) hoặc settings
$context = $_POST['context'] ?? 'checkout';
$sessionKey = ($context === 'settings') ? 'settings_otp_verified' : 'otp_verified';
$_SESSION[$sessionKey] = true;
    unset($_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['otp_attempts']);

    echo json_encode(['ok' => true, 'msg' => 'Xác minh thành công.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ.']);