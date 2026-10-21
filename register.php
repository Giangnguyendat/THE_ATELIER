<?php
require_once 'includes/config.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$full_name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Yêu cầu không hợp lệ hoặc đã hết hạn (CSRF Token)';
    } else {
        $full_name        = trim($_POST['full_name'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $password         = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $otp_input        = trim($_POST['otp_code'] ?? '');

        if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = 'Vui lòng điền đầy đủ các trường thông tin';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Địa chỉ email không hợp lệ';
        } elseif (empty($otp_input)) {
            $error = 'Vui lòng nhập mã OTP đã gửi tới email';
        } elseif (strlen($password) < 6) {
            $error = 'Mật khẩu phải chứa ít nhất 6 ký tự';
        } elseif ($password !== $confirm_password) {
            $error = 'Mật khẩu xác nhận không trùng khớp';
        } else {
            // ============ XÁC MINH OTP ============
            $stmt = $pdo->prepare("SELECT id, otp_code, attempts, expires_at FROM email_otps WHERE email = ? AND purpose = 'register' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email]);
            $otpRow = $stmt->fetch();

            if (!$otpRow) {
                $error = 'Chưa có mã OTP cho email này. Vui lòng bấm "Gửi mã OTP"';
            } elseif (strtotime($otpRow['expires_at']) < time()) {
                $error = 'Mã OTP đã hết hạn. Vui lòng gửi lại mã mới';
            } elseif ((int)$otpRow['attempts'] >= 5) {
                $error = 'Bạn đã nhập sai quá nhiều lần. Vui lòng gửi lại mã mới';
            } elseif (!hash_equals($otpRow['otp_code'], $otp_input)) {
                $pdo->prepare("UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$otpRow['id']]);
                $remain = 5 - ((int)$otpRow['attempts'] + 1);
                $error = "Mã OTP không đúng. Bạn còn {$remain} lần thử";
            } else {
                // ============ OTP ĐÚNG → TẠO TÀI KHOẢN ============
                // Check trùng email lần nữa (tránh race condition)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $error = 'Địa chỉ email này đã được sử dụng';
                } else {
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)");

                    if ($stmt->execute([$full_name, $email, $hashed])) {
                        // Xóa OTP đã dùng
                        $pdo->prepare("DELETE FROM email_otps WHERE email = ? AND purpose = 'register'")->execute([$email]);

                        // Rotate CSRF
                        unset($_SESSION['csrf_token']);

                        header('Location: login.php?registered=success');
                        exit;
                    } else {
                        $error = 'Đã có lỗi xảy ra trong quá trình đăng ký, vui lòng thử lại';
                    }
                }
            }
        }
    }
}

// Sinh CSRF mới nếu cần (dùng lại token cũ nếu đã có)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<?php include 'includes/header.php'; ?>

<main class="auth-page-wrapper">
    <div class="auth-container">
        <div class="auth-card">

            <header class="auth-header">
                <h1 class="auth-title">Đăng Ký Tài Khoản</h1>
                <p class="auth-subtitle">Xác minh email để bảo vệ tài khoản của bạn</p>
            </header>

            <?php if (!empty($error)): ?>
                <div class="auth-alert error">
                    <span class="alert-icon">&#9888;</span>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form" autocomplete="off" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <div class="form-group">
                    <label for="full_name" class="form-label">Họ và tên</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           placeholder="Nguyễn Văn A"
                           value="<?php echo sanitize($full_name); ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Địa chỉ Email</label>
                    <div class="email-otp-wrapper">
                        <input type="email" id="email" name="email" class="form-control"
                               placeholder="nam@example.com"
                               value="<?php echo sanitize($email); ?>" required>
                        <button type="button" id="btnSendOtp" class="btn-send-otp">Gửi mã OTP</button>
                    </div>
                    <small id="otpStatus" class="otp-status"></small>
                </div>

                <div class="form-group">
                    <label for="otp_code" class="form-label">Mã xác minh OTP</label>
                    <input type="text" id="otp_code" name="otp_code" class="form-control"
                           placeholder="Nhập 6 chữ số" maxlength="6" inputmode="numeric"
                           pattern="\d{6}" required disabled>
                    <small class="form-hint">Mã có hiệu lực 5 phút kể từ khi gửi</small>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Mật khẩu</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="Tối thiểu 6 ký tự" required>
                        <button type="button" class="btn-toggle-password" id="togglePassword" aria-label="Hiện mật khẩu">&#128065;</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Xác nhận mật khẩu</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                               placeholder="Nhập lại mật khẩu" required>
                        <button type="button" class="btn-toggle-password" id="toggleConfirmPassword" aria-label="Hiện mật khẩu">&#128065;</button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit" disabled>Tạo Tài Khoản</button>
            </form>

            <footer class="auth-footer">
                <p>Đã có tài khoản? <a href="login.php" class="register-link">Đăng nhập ngay</a></p>
            </footer>

        </div>
    </div>
</main>

<style>
.email-otp-wrapper {
    display: flex;
    gap: 8px;
    align-items: stretch;
}
.email-otp-wrapper .form-control { flex: 1; }
.btn-send-otp {
    padding: 0 18px;
    background: var(--color-ink, #1c1b19);
    color: var(--color-ivory, #f2ece0);
    border: none;
    border-radius: 6px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background .2s;
}
.btn-send-otp:hover:not(:disabled) { background: var(--color-rust, #a8461f); }
.btn-send-otp:disabled { opacity: .55; cursor: not-allowed; }

.otp-status {
    display: block;
    margin-top: 6px;
    font-size: .82rem;
    min-height: 1.1em;
    font-family: 'IBM Plex Mono', monospace;
}
.otp-status.ok  { color: var(--color-success, #166534); }
.otp-status.err { color: var(--color-danger, #b91c1c); }

.form-hint {
    display: block;
    margin-top: 5px;
    color: var(--color-stone, #8a8377);
    font-size: .78rem;
    font-style: italic;
}

#otp_code:disabled {
    background: #f5f5f5;
    cursor: not-allowed;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    /* --- Toggle password --- */
    function setupPasswordToggle(inputId, btnId) {
        const input = document.getElementById(inputId);
        const btn   = document.getElementById(btnId);
        if (input && btn) {
            btn.addEventListener('click', () => {
                const isPwd = input.type === 'password';
                input.type = isPwd ? 'text' : 'password';
                btn.style.opacity = isPwd ? '1' : '0.5';
            });
        }
    }
    setupPasswordToggle('password', 'togglePassword');
    setupPasswordToggle('confirm_password', 'toggleConfirmPassword');

    /* --- Gửi OTP --- */
    const btnSend   = document.getElementById('btnSendOtp');
    const emailEl   = document.getElementById('email');
    const otpInput  = document.getElementById('otp_code');
    const btnSubmit = document.getElementById('btnSubmit');
    const statusEl  = document.getElementById('otpStatus');
    const csrf      = document.querySelector('input[name="csrf_token"]').value;

    let countdownTimer = null;

    function setStatus(msg, type) {
        statusEl.textContent = msg;
        statusEl.className = 'otp-status ' + (type || '');
    }

    function startCountdown(seconds) {
        btnSend.disabled = true;
        let left = seconds;
        const baseText = 'Gửi lại';
        btnSend.textContent = `${left}s`;

        clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            left--;
            if (left <= 0) {
                clearInterval(countdownTimer);
                btnSend.disabled = false;
                btnSend.textContent = baseText;
            } else {
                btnSend.textContent = `${left}s`;
            }
        }, 1000);
    }

    btnSend.addEventListener('click', async () => {
        const email = emailEl.value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setStatus('Vui lòng nhập email hợp lệ trước', 'err');
            emailEl.focus();
            return;
        }

        btnSend.disabled = true;
        setStatus('Đang gửi mã...', '');

        try {
            const res = await fetch('ajax/send_register_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, csrf_token: csrf })
            });
            const data = await res.json();

            if (data.ok) {
                setStatus('✓ ' + data.message, 'ok');
                otpInput.disabled = false;
                otpInput.focus();
                startCountdown(60);
            } else {
                setStatus('✗ ' + (data.error || 'Không gửi được'), 'err');
                btnSend.disabled = false;
            }
        } catch (e) {
            setStatus('✗ Lỗi kết nối. Vui lòng thử lại', 'err');
            btnSend.disabled = false;
        }
    });

    /* --- Bật nút submit khi OTP có đủ 6 số --- */
    otpInput.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
        btnSubmit.disabled = (e.target.value.length !== 6);
    });

    /* --- Reset email → khóa OTP lại --- */
    emailEl.addEventListener('input', () => {
        if (!otpInput.disabled) {
            otpInput.value = '';
            otpInput.disabled = true;
            btnSubmit.disabled = true;
            setStatus('', '');
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>