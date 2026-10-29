<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_otp_helper.php';

// Đã đăng nhập rồi thì không cần xác thực nữa
if (isLoggedIn()) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? '../admin/index.php' : '../index.php'));
    exit;
}

// Không có pending → quay lại login
if (empty($_SESSION['admin_otp_pending'])) {
    header('Location: ../login.php');
    exit;
}

// Hủy phiên OTP
if (($_GET['action'] ?? '') === 'cancel') {
    unset($_SESSION['admin_otp_pending']);
    header('Location: ../login.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';
$pending = $_SESSION['admin_otp_pending'];
$maskedEmail = preg_replace('/(.{1}).+(.{1}@.+)/', '$1***********$2', $pending['email']);

// Gửi lại OTP
if (($_GET['action'] ?? '') === 'resend') {
    $newOtp = adminOtpRegenerate();
    if ($newOtp === null) {
        $error = 'Chưa thể gửi lại (vui lòng đợi 60 giây) hoặc đã đạt giới hạn gửi lại.';
    } else {
        adminOtpSendEmail($pending['email'], $pending['full_name'], $newOtp);
        $success = 'Đã gửi lại mã OTP mới đến email của bạn.';
        $pending = $_SESSION['admin_otp_pending'];
    }
}

// Xác thực OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Yêu cầu không hợp lệ (CSRF).';
    } else {
        $result = adminOtpVerify($_POST['otp'] ?? '');

        if (!$result['ok']) {
            $error   = $result['message'];
            $pending = $_SESSION['admin_otp_pending'] ?? [];
        } else {
            // ── OTP ĐÚNG → HOÀN TẤT ĐĂNG NHẬP ADMIN ──
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$result['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) { header('Location: ../login.php'); exit; }

            $guestCart = [];
            if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
                $guestCart = $_SESSION['cart'];
            }

            $_SESSION = [];
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['user']      = $user;
            $_SESSION['email']     = $user['email'];

            require_once __DIR__ . '/cart_storage.php';
            restoreCartFromDb($pdo);

            if (!empty($guestCart)) {
                foreach ($guestCart as $key => $item) {
                    if (isset($_SESSION['cart'][$key])) {
                        $_SESSION['cart'][$key]['quantity'] += (int)$item['quantity'];
                    } else {
                        $_SESSION['cart'][$key] = $item;
                    }
                }
                if (!empty($_COOKIE['guest_id'])) {
                    $guestKey = 'guest_' . preg_replace('/[^a-f0-9]/', '', $_COOKIE['guest_id']);
                    try {
                        $pdo->prepare("DELETE FROM cart_storage WHERE owner_key = ?")->execute([$guestKey]);
                    } catch (Exception $e) {}
                    setcookie('guest_id', '', time() - 3600, '/');
                    unset($_COOKIE['guest_id']);
                }
            }
            saveCartToDb($pdo);

            header('Location: ../admin/index.php');
            exit;
        }
    }
}

// Sử dụng header/footer của bạn
include __DIR__ . '/header.php'; 
?>

<!-- CSS TÙY CHỈNH CHO TRANG OTP (Không phụ thuộc file ngoài) -->
<style>
    body {
        background-color: #f7f5f0; /* Màu nền be nhạt của THE ATELIER */
    }
    .otp-page-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 80vh;
        padding: 40px 20px;
        font-family: 'Inter', sans-serif;
    }
    .otp-card {
        background: #ffffff;
        width: 100%;
        max-width: 480px;
        padding: 50px 40px;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.05);
        border: 1px solid #eaeaea;
        text-align: center;
    }
    .otp-title {
        font-family: 'Fraunces', serif;
        font-size: 32px;
        color: #1a1a1a;
        margin-bottom: 10px;
        font-weight: 500;
    }
    .otp-subtitle {
        font-size: 14px;
        color: #666;
        margin-bottom: 30px;
        line-height: 1.6;
    }
    .otp-subtitle strong {
        color: #1a1a1a;
    }
    
    /* Các ô nhập OTP */
    .otp-input-group {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-bottom: 30px;
    }
    .otp-input-group input {
        width: 50px;
        height: 60px;
        font-size: 24px;
        font-weight: 600;
        text-align: center;
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #fafafa;
        color: #1a1a1a;
        outline: none;
        transition: all 0.2s ease;
    }
    .otp-input-group input:focus {
        border-color: #9c2b1e; /* Đỏ gạch */
        background: #fff;
        box-shadow: 0 0 0 3px rgba(156, 43, 30, 0.1);
    }

    /* Nút xác nhận */
    .btn-submit-otp {
        width: 100%;
        padding: 16px;
        background-color: #1a1a1a;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 25px;
    }
    .btn-submit-otp:hover {
        background-color: #9c2b1e;
    }

    /* Links */
    .otp-links {
        font-size: 14px;
        color: #666;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .otp-links a {
        color: #1a1a1a;
        text-decoration: underline;
        font-weight: 500;
        transition: color 0.2s;
    }
    .otp-links a:hover {
        color: #9c2b1e;
    }
    .otp-links a.disabled {
        color: #aaa;
        pointer-events: none;
        text-decoration: none;
    }

    /* Thông báo lỗi / thành công */
    .alert-message {
        padding: 12px 15px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 25px;
        text-align: left;
    }
    .alert-error {
        background-color: #fdf0ed;
        color: #9c2b1e;
        border: 1px solid #f5d0c9;
    }
    .alert-success {
        background-color: #eef8f0;
        color: #1e7b34;
        border: 1px solid #c3e6cb;
    }
</style>

<main class="otp-page-wrapper">
    <div class="otp-card">
        
        <h1 class="otp-title">Xác thực Admin</h1>
        <p class="otp-subtitle">
            Mã OTP đã được gửi đến email<br>
            <strong><?php echo sanitize($maskedEmail); ?></strong>
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert-message alert-error">
                <?php echo sanitize($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-message alert-success">
                <?php echo sanitize($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" id="otpForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- Input ẩn để chứa mã OTP hoàn chỉnh gửi lên server -->
            <input type="hidden" name="otp" id="otpHidden">

            <!-- Giao diện 6 ô nhập -->
            <div class="otp-input-group" id="otpInputs">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required autofocus autocomplete="one-time-code">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" required>
            </div>

            <button type="submit" class="btn-submit-otp">Xác nhận OTP</button>
        </form>

        <div class="otp-links">
            <div>
                Không nhận được mã? 
                <a href="?action=resend" id="resendLink" class="disabled">Gửi lại <span id="countdown"></span></a>
            </div>
            <div>
                <a href="?action=cancel">← Hủy và quay lại đăng nhập</a>
            </div>
        </div>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const inputs = document.querySelectorAll('#otpInputs input');
    const hiddenInput = document.getElementById('otpHidden');
    const form = document.getElementById('otpForm');

    // 1. Logic tự động nhảy ô và chỉ cho nhập số
    inputs.forEach((input, index) => {
        // Chỉ nhận số
        input.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '');
            if (e.target.value.length === 1 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        });

        // Xử lý Backspace
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                inputs[index - 1].focus();
            }
        });

        // Xử lý Paste (Copy/Paste mã OTP)
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
            if (pastedData) {
                const digits = pastedData.split('');
                digits.forEach((digit, i) => {
                    if (index + i < inputs.length) {
                        inputs[index + i].value = digit;
                    }
                });
                // Focus vào ô cuối cùng vừa điền
                const focusIndex = Math.min(index + digits.length, inputs.length - 1);
                inputs[focusIndex].focus();
            }
        });
    });

    // 2. Trước khi submit, gom 6 ô thành 1 mã và gán vào hidden input
    form.addEventListener('submit', (e) => {
        let otpCode = '';
        inputs.forEach(input => otpCode += input.value);
        
        if (otpCode.length !== 6) {
            e.preventDefault();
            alert('Vui lòng nhập đầy đủ 6 chữ số.');
            return;
        }
        hiddenInput.value = otpCode;
    });

    // 3. Đếm ngược thời gian gửi lại
    const lastSent = <?php echo (int)($pending['last_sent_at'] ?? 0); ?>;
    const cooldown = <?php echo ADMIN_OTP_RESEND_COOLDOWN; ?>;
    const link = document.getElementById('resendLink');
    const label = document.getElementById('countdown');

    if (link && label) {
        function tick() {
            const remain = Math.max(0, cooldown - (Math.floor(Date.now() / 1000) - lastSent));
            if (remain > 0) {
                link.classList.add('disabled');
                label.textContent = `(${remain}s)`;
                setTimeout(tick, 1000);
            } else {
                link.classList.remove('disabled');
                label.textContent = '';
            }
        }
        tick();
    }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>