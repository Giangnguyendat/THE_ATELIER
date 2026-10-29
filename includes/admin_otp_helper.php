<?php
if (!defined('ADMIN_OTP_TTL'))             define('ADMIN_OTP_TTL', 300);
if (!defined('ADMIN_OTP_MAX_ATTEMPTS'))    define('ADMIN_OTP_MAX_ATTEMPTS', 5);
if (!defined('ADMIN_OTP_RESEND_COOLDOWN')) define('ADMIN_OTP_RESEND_COOLDOWN', 60);
if (!defined('ADMIN_OTP_MAX_RESEND'))      define('ADMIN_OTP_MAX_RESEND', 5);

function adminOtpGenerate(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function adminOtpInitSession(array $user): string {
    $otp = adminOtpGenerate();
    $_SESSION['admin_otp_pending'] = [
        'user_id'      => (int)$user['id'],
        'email'        => $user['email'],
        'full_name'    => $user['full_name'] ?? 'Admin',
        'otp_hash'     => password_hash($otp, PASSWORD_DEFAULT),
        'created_at'   => time(),
        'expires_at'   => time() + ADMIN_OTP_TTL,
        'attempts'     => 0,
        'last_sent_at' => time(),
        'resend_count' => 0,
    ];
    return $otp;
}

function adminOtpRegenerate(): ?string {
    if (empty($_SESSION['admin_otp_pending'])) return null;
    $p = $_SESSION['admin_otp_pending'];

    if (($p['resend_count'] ?? 0) >= ADMIN_OTP_MAX_RESEND) return null;
    if (time() - ($p['last_sent_at'] ?? 0) < ADMIN_OTP_RESEND_COOLDOWN) return null;

    $otp = adminOtpGenerate();
    $_SESSION['admin_otp_pending'] = array_merge($p, [
        'otp_hash'     => password_hash($otp, PASSWORD_DEFAULT),
        'created_at'   => time(),
        'expires_at'   => time() + ADMIN_OTP_TTL,
        'attempts'     => 0,
        'last_sent_at' => time(),
        'resend_count' => ($p['resend_count'] ?? 0) + 1,
    ]);
    return $otp;
}

function adminOtpVerify(string $input): array {
    if (empty($_SESSION['admin_otp_pending'])) {
        return ['ok' => false, 'message' => 'Phiên xác thực không tồn tại hoặc đã hết hạn.', 'user_id' => null];
    }
    $p = &$_SESSION['admin_otp_pending'];

    if (time() > ($p['expires_at'] ?? 0)) {
        unset($_SESSION['admin_otp_pending']);
        return ['ok' => false, 'message' => 'Mã OTP đã hết hạn. Vui lòng đăng nhập lại.', 'user_id' => null];
    }
    if (($p['attempts'] ?? 0) >= ADMIN_OTP_MAX_ATTEMPTS) {
        unset($_SESSION['admin_otp_pending']);
        return ['ok' => false, 'message' => 'Bạn đã nhập sai quá số lần cho phép.', 'user_id' => null];
    }

    $input = preg_replace('/\D/', '', $input);
    if (strlen($input) !== 6) {
        return ['ok' => false, 'message' => 'Mã OTP phải gồm đúng 6 chữ số.', 'user_id' => null];
    }

    if (!password_verify($input, $p['otp_hash'])) {
        $p['attempts'] = ($p['attempts'] ?? 0) + 1;
        $remaining     = max(0, ADMIN_OTP_MAX_ATTEMPTS - $p['attempts']);
        return ['ok' => false, 'message' => "Mã OTP không đúng. Còn $remaining lần thử.", 'user_id' => null];
    }

    $uid = (int)$p['user_id'];
    unset($_SESSION['admin_otp_pending']);
    return ['ok' => true, 'message' => 'Xác thực thành công.', 'user_id' => $uid];
}

function adminOtpSendEmail(string $to, string $name, string $otp): bool {
    $mailerPath = __DIR__ . '/mailer.php';
    if (file_exists($mailerPath)) {
        require_once $mailerPath;
    }

    if (!function_exists('sendMail')) {
        error_log('[adminOtpSendEmail] Không tìm thấy sendMail() tại: ' . $mailerPath);
        return false;
    }

    $subject = '[THE ATELIER] Mã xác thực đăng nhập Admin';
    $html = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;border:1px solid #e5e1da;padding:24px;">
            <h2 style="color:#1a1a1a;margin:0 0 12px;">THE ATELIER — Xác thực Admin</h2>
            <p style="color:#333;line-height:1.6;">Xin chào <strong>' . htmlspecialchars($name) . '</strong>,</p>
            <p style="color:#333;line-height:1.6;">Mã OTP đăng nhập quản trị của bạn là:</p>
            <div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#9c2b1e;text-align:center;padding:16px 0;background:#faf7f2;border-radius:6px;margin:16px 0;">' . $otp . '</div>
            <p style="color:#666;font-size:13px;line-height:1.6;">Mã có hiệu lực <strong>' . (ADMIN_OTP_TTL / 60) . ' phút</strong>. Không chia sẻ mã này cho bất kỳ ai.</p>
        </div>';

    try {
        $ok = sendMail($to, $name, $subject, $html);
        if ($ok === false) {
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('[adminOtpSendEmail] Exception: ' . $e->getMessage());
        return false;
    }
}