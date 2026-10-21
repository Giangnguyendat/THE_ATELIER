<?php
require_once 'includes/config.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';
$step    = 1;   // 1 = nhập email, 2 = nhập OTP + password mới

// Nếu đã có session reset → vào step 2
if (!empty($_SESSION['reset_email']) && !empty($_SESSION['reset_email_ts'])) {
    // Hết hạn session sau 15 phút
    if (time() - (int)$_SESSION['reset_email_ts'] > 900) {
        unset($_SESSION['reset_email'], $_SESSION['reset_email_ts']);
    } else {
        $step = 2;
    }
}

// Xử lý action
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Yêu cầu không hợp lệ hoặc đã hết hạn (CSRF Token)';
    } else {

        /* ============ STEP 2: ĐỔI MẬT KHẨU ============ */
        if ($action === 'reset') {
            $otp_input        = trim($_POST['otp_code'] ?? '');
            $new_password     = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($_SESSION['reset_email'])) {
                $error = 'Phiên đặt lại mật khẩu đã hết hạn. Vui lòng bắt đầu lại';
                $step  = 1;
            } elseif (empty($otp_input)) {
                $error = 'Vui lòng nhập mã OTP';
                $step  = 2;
            } elseif (strlen($new_password) < 6) {
                $error = 'Mật khẩu phải chứa ít nhất 6 ký tự';
                $step  = 2;
            } elseif ($new_password !== $confirm_password) {
                $error = 'Mật khẩu xác nhận không trùng khớp';
                $step  = 2;
            } else {
                $email = $_SESSION['reset_email'];

                // Lấy OTP
                $stmt = $pdo->prepare("SELECT id, otp_code, attempts, expires_at FROM email_otps WHERE email = ? AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$email]);
                $otpRow = $stmt->fetch();

                if (!$otpRow) {
                    $error = 'Chưa có mã OTP cho email này. Vui lòng gửi lại';
                    $step  = 2;
                } elseif (strtotime($otpRow['expires_at']) < time()) {
                    $error = 'Mã OTP đã hết hạn. Vui lòng gửi lại mã mới';
                    $step  = 2;
                } elseif ((int)$otpRow['attempts'] >= 5) {
                    $error = 'Bạn đã nhập sai quá nhiều lần. Vui lòng gửi lại mã mới';
                    $step  = 2;
                } elseif (!hash_equals($otpRow['otp_code'], $otp_input)) {
                    $pdo->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$otpRow['id']]);
                    $remain = 5 - ((int)$otpRow['attempts'] + 1);
                    $error = "Mã OTP không đúng. Bạn còn {$remain} lần thử";
                    $step  = 2;
                } else {
                    // OTP ĐÚNG → ĐỔI MẬT KHẨU
                    $hashed = password_hash($new_password, PASSWORD_BCRYPT);

                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, 
                                           pin_attempts = 0, pin_locked_until = NULL 
                                           WHERE email = ?");
                    if ($stmt->execute([$hashed, $email])) {
                        // Xóa OTP đã dùng
                        $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'reset_password'")->execute([$email]);

                        // Log email thông báo đổi mật khẩu thành công
                        try {
                            require_once __DIR__ . '/includes/mailer.php';
                            $subject = "Mật khẩu đã được thay đổi — THE ATELIER";
                            $body = "
                            <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
                              <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
                              <p>Mật khẩu tài khoản của bạn đã được đổi thành công vào lúc <strong>" . date('H:i d/m/Y') . "</strong>.</p>
                              <p>Nếu <strong>bạn không thực hiện</strong> thay đổi này, hãy liên hệ ngay hotline <strong>1900 123 456</strong> để được hỗ trợ.</p>
                            </div>";
                            if (function_exists('send_mail')) {
    send_mail($email, $subject, $body);
} elseif (function_exists('sendMail')) {
    // Lấy tên user — bạn đã có $user từ SELECT trước đó chưa? Nếu chưa thì dùng 'bạn'
    sendMail($email, 'bạn', $subject, $body);
}
                        } catch (Throwable $e) { /* bỏ qua lỗi gửi mail thông báo */ }

                        // Clean session
                        unset($_SESSION['reset_email'], $_SESSION['reset_email_ts'], $_SESSION['csrf_token']);

                        $success = 'Đổi mật khẩu thành công! Bạn có thể đăng nhập ngay bây giờ.';
                        $step    = 3; // hoàn tất
                    } else {
                        $error = 'Có lỗi xảy ra khi cập nhật mật khẩu. Vui lòng thử lại';
                        $step  = 2;
                    }
                }
            }
        }

        /* ============ STEP 2: GỬI LẠI OTP ============ */
        elseif ($action === 'resend' && !empty($_SESSION['reset_email'])) {
            // AJAX sẽ xử lý — nhưng để backup nếu JS lỗi, xử lý server-side luôn
            $step = 2;
        }
    }
}

// Nếu session có reset_email → step 2
if (!empty($_SESSION['reset_email']) && $step !== 3) {
    $step = 2;
}

$csrf = $_SESSION['csrf_token'];
$maskedEmail = '';
if (!empty($_SESSION['reset_email'])) {
    $e = $_SESSION['reset_email'];
    [$local, $domain] = explode('@', $e);
    $maskedEmail = substr($local, 0, 2) . str_repeat('•', max(1, strlen($local) - 2)) . '@' . $domain;
}
?>
<?php include 'includes/header.php'; ?>

<main class="auth-page-wrapper">
    <div class="auth-container">
        <div class="auth-card">

            <header class="auth-header">
                <h1 class="auth-title">Quên Mật Khẩu</h1>
                <p class="auth-subtitle">
                    <?php if ($step === 1): ?>Nhập email để nhận mã xác minh
                    <?php elseif ($step === 2): ?>Nhập mã OTP và mật khẩu mới
                    <?php else: ?>Hoàn tất
                    <?php endif; ?>
                </p>
            </header>

            <?php if (!empty($error)): ?>
                <div class="auth-alert error">
                    <span class="alert-icon">&#9888;</span>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="auth-alert success">
                    <span class="alert-icon">&#10004;</span>
                    <span><?php echo sanitize($success); ?></span>
                </div>
            <?php endif; ?>

            <?php /* ============ STEP 1 ============ */ ?>
            <?php if ($step === 1): ?>
                <form method="post" class="auth-form" id="stepForm1" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="send">

                    <div class="form-group">
                        <label for="email" class="form-label">Địa chỉ Email</label>
                        <input type="email" id="email" name="email" class="form-control"
                               placeholder="email@example.com" required autofocus>
                        <small class="form-hint">Mã xác minh 6 số sẽ được gửi tới email này</small>
                    </div>

                    <button type="button" class="btn-submit" id="btnSendOtp">Gửi mã xác minh</button>
                    <small id="otpStatus" class="otp-status"></small>
                </form>
            <?php endif; ?>

            <?php /* ============ STEP 2 ============ */ ?>
            <?php if ($step === 2): ?>
                <div class="reset-email-info">
                    Mã đã gửi tới <strong><?php echo sanitize($maskedEmail); ?></strong>
                    <a href="forgot-password.php?restart=1" class="btn-restart">Đổi email khác</a>
                </div>

                <form method="post" class="auth-form" id="stepForm2" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="reset">

                    <div class="form-group">
                        <label for="otp_code" class="form-label">Mã xác minh OTP</label>
                        <div class="email-otp-wrapper">
                            <input type="text" id="otp_code" name="otp_code" class="form-control"
                                   placeholder="Nhập 6 chữ số" maxlength="6" inputmode="numeric"
                                   pattern="\d{6}" required autofocus>
                            <button type="button" id="btnResendOtp" class="btn-send-otp">Gửi lại</button>
                        </div>
                        <small class="form-hint">Mã có hiệu lực 5 phút</small>
                        <small id="otpStatus" class="otp-status"></small>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="form-label">Mật khẩu mới</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="new_password" name="new_password" class="form-control"
                                   placeholder="Tối thiểu 6 ký tự" required>
                            <button type="button" class="btn-toggle-password" id="toggleNew" aria-label="Hiện mật khẩu">&#128065;</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                   placeholder="Nhập lại mật khẩu mới" required>
                            <button type="button" class="btn-toggle-password" id="toggleConfirm" aria-label="Hiện mật khẩu">&#128065;</button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Đặt lại mật khẩu</button>
                </form>
            <?php endif; ?>

            <?php /* ============ STEP 3 ============ */ ?>
            <?php if ($step === 3): ?>
                <div class="auth-success-box">
                    <div class="success-icon">✓</div>
                    <p><?php echo sanitize($success); ?></p>
                    <a href="login.php" class="btn-submit" style="display:inline-block;text-decoration:none;text-align:center;margin-top:16px">Đăng nhập ngay</a>
                </div>
            <?php endif; ?>

            <footer class="auth-footer">
                <p>Nhớ ra rồi? <a href="login.php" class="register-link">Đăng nhập</a></p>
                <p style="margin-top:6px;font-size:.85rem">Chưa có tài khoản? <a href="register.php" class="register-link">Đăng ký ngay</a></p>
            </footer>

        </div>
    </div>
</main>

<style>
.email-otp-wrapper { display: flex; gap: 8px; align-items: stretch; }
.email-otp-wrapper .form-control { flex: 1; }
.btn-send-otp {
    padding: 0 18px;
    background: var(--color-ink, #1c1b19);
    color: var(--color-ivory, #f2ece0);
    border: none; border-radius: 6px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .82rem; font-weight: 600;
    cursor: pointer; white-space: nowrap;
    transition: background .2s;
}
.btn-send-otp:hover:not(:disabled) { background: var(--color-rust, #a8461f); }
.btn-send-otp:disabled { opacity: .55; cursor: not-allowed; }

.otp-status {
    display: block; margin-top: 6px;
    font-size: .82rem; min-height: 1.1em;
    font-family: 'IBM Plex Mono', monospace;
}
.otp-status.ok  { color: var(--color-success, #166534); }
.otp-status.err { color: var(--color-danger, #b91c1c); }

.form-hint {
    display: block; margin-top: 5px;
    color: var(--color-stone, #8a8377);
    font-size: .78rem; font-style: italic;
}

.reset-email-info {
    background: var(--color-ivory, #f2ece0);
    border-left: 3px solid var(--color-rust, #a8461f);
    padding: 10px 14px;
    margin-bottom: 16px;
    font-size: .88rem;
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    flex-wrap: wrap;
}
.btn-restart {
    font-size: .8rem;
    color: var(--color-rust, #a8461f);
    text-decoration: underline;
    font-family: 'IBM Plex Mono', monospace;
}

.auth-alert.success {
    background: #ecfdf5;
    border-left: 3px solid var(--color-success, #166534);
    color: var(--color-success, #166534);
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 18px;
    display: flex; gap: 10px; align-items: flex-start;
}

.auth-success-box {
    text-align: center;
    padding: 24px 12px;
}
.auth-success-box .success-icon {
    width: 64px; height: 64px;
    margin: 0 auto 16px;
    border-radius: 50%;
    background: var(--color-success, #166534);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; font-weight: 700;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    /* --- Toggle password --- */
    function setupToggle(inputId, btnId) {
        const i = document.getElementById(inputId);
        const b = document.getElementById(btnId);
        if (i && b) b.addEventListener('click', () => {
            i.type = i.type === 'password' ? 'text' : 'password';
        });
    }
    setupToggle('new_password', 'toggleNew');
    setupToggle('confirm_password', 'toggleConfirm');

    const csrf = document.querySelector('input[name="csrf_token"]')?.value;
    const statusEl = document.getElementById('otpStatus');

    function setStatus(msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'otp-status ' + (type || '');
    }

    function startCountdown(btn, seconds) {
        btn.disabled = true;
        let left = seconds;
        btn.textContent = left + 's';
        const t = setInterval(() => {
            left--;
            if (left <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'Gửi lại';
            } else {
                btn.textContent = left + 's';
            }
        }, 1000);
    }

    /* --- STEP 1: Gửi OTP --- */
    const btnSend = document.getElementById('btnSendOtp');
    if (btnSend) {
        btnSend.addEventListener('click', async () => {
            const email = document.getElementById('email').value.trim();
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setStatus('Vui lòng nhập email hợp lệ', 'err');
                return;
            }
            btnSend.disabled = true;
            setStatus('Đang gửi...', '');

            try {
                const res = await fetch('ajax/send_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, csrf_token: csrf })
                });
                const data = await res.json();

                if (data.ok) {
                    setStatus('✓ Đã gửi! Đang chuyển...', 'ok');
                    // Reload để chuyển sang step 2 (server đã lưu session)
                    setTimeout(() => location.reload(), 700);
                } else {
                    setStatus('✗ ' + (data.error || 'Không gửi được'), 'err');
                    btnSend.disabled = false;
                }
            } catch (e) {
                setStatus('✗ Lỗi kết nối', 'err');
                btnSend.disabled = false;
            }
        });
    }

    /* --- STEP 2: Gửi lại OTP --- */
    const btnResend = document.getElementById('btnResendOtp');
    if (btnResend) {
        const emailEl = document.getElementById('resetEmailHidden');
        // Lấy email từ masked (server render) — ta cần email gốc → dùng cách khác
        // Đơn giản: gửi request, server tự lấy từ session
        btnResend.addEventListener('click', async () => {
            btnResend.disabled = true;
            setStatus('Đang gửi lại...', '');

            try {
                const res = await fetch('ajax/send_reset_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: window.__RESET_EMAIL__ || '',
                        csrf_token: csrf
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    setStatus('✓ ' + data.message, 'ok');
                    startCountdown(btnResend, 60);
                } else {
                    setStatus('✗ ' + (data.error || 'Không gửi được'), 'err');
                    btnResend.disabled = false;
                }
            } catch (e) {
                setStatus('✗ Lỗi kết nối', 'err');
                btnResend.disabled = false;
            }
        });
    }

    /* --- STEP 2: chỉ cho nhập số --- */
    const otpInput = document.getElementById('otp_code');
    if (otpInput) {
        otpInput.addEventListener('input', e => {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
        });
    }
});
</script>

<?php
// Render email gốc cho JS (dùng khi resend)
if (!empty($_SESSION['reset_email'])) {
    echo '<script>window.__RESET_EMAIL__ = ' . json_encode($_SESSION['reset_email']) . ';</script>';
}
?>

<?php include 'includes/footer.php'; ?>