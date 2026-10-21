<?php
require_once 'includes/config.php';

// Nếu đã đăng nhập rồi thì điều hướng thẳng theo vai trò, không cho ở lại trang login nữa
if (isLoggedIn()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header('Location: admin/index.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// Tự động tạo CSRF Token nếu chưa có trong Session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Yêu cầu không hợp lệ hoặc đã hết hạn (CSRF Token)';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Vui lòng nhập đầy đủ email và mật khẩu';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {

    // ✅ LƯU giỏ guest lại trước khi reset session
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

    unset($_SESSION['csrf_token']);

    // ✅ Khôi phục giỏ hàng CỦA USER này từ DB (nếu có)
    require_once __DIR__ . '/includes/cart_storage.php';
    restoreCartFromDb($pdo);

    // ✅ Merge giỏ guest (nếu có) vào giỏ user
    if (!empty($guestCart)) {
        if (empty($_SESSION['cart'])) {
            $_SESSION['cart'] = $guestCart;
        } else {
            foreach ($guestCart as $key => $item) {
                if (isset($_SESSION['cart'][$key])) {
                    $_SESSION['cart'][$key]['quantity'] += (int)$item['quantity'];
                } else {
                    $_SESSION['cart'][$key] = $item;
                }
            }
        }

        // Xóa giỏ guest khỏi DB
        if (!empty($_COOKIE['guest_id'])) {
            $guestKey = 'guest_' . preg_replace('/[^a-f0-9]/', '', $_COOKIE['guest_id']);
            try {
                $pdo->prepare("DELETE FROM cart_storage WHERE owner_key = ?")->execute([$guestKey]);
            } catch (Exception $e) {}
            // Xóa cookie guest
            setcookie('guest_id', '', time() - 3600, '/');
            unset($_COOKIE['guest_id']);
        }
    }

    // Lưu giỏ (đã merge) vào DB cho user
    saveCartToDb($pdo);

    // ... redirect

                // === PHẦN PHÂN QUYỀN ĐIỀU HƯỚNG TẠI ĐÂY ===
                if ($user['role'] === 'admin') {
                    header('Location: admin/index.php'); // Admin vào trang quản trị
                } else {
                    header('Location: index.php');       // Khách hàng vào trang chủ
                }
                exit;

            } else {
                $error = 'Email hoặc mật khẩu không chính xác';
            }
        }
    }
}
?>
<?php include 'includes/header.php'; ?>

<main class="auth-page-wrapper">
    <div class="auth-container">
        <div class="auth-card">
            
            <header class="auth-header">
                <h1 class="auth-title">Đăng Nhập</h1>
                <p class="auth-subtitle">Chào mừng bạn quay trở lại</p>
            </header>

            <?php if (!empty($error)): ?>
                <div class="auth-alert error">
                    <span class="alert-icon">&#9888;</span>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="email" class="form-label">Địa chỉ Email</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="nam@example.com" 
                        value="<?php echo sanitize($email); ?>" 
                        required 
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <div class="label-row">
                        <label for="password" class="form-label">Mật khẩu</label>
                        <a href="forgot-password.php" class="forgot-link">Quên mật khẩu?</a>
                    </div>
                    <div class="password-input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="••••••••" 
                            required
                        >
                        <button type="button" class="btn-toggle-password" id="togglePassword" aria-label="Hiện mật khẩu">
                            👁
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Đăng Nhập</button>
            </form>

            <footer class="auth-footer">
                <p>Chưa có tài khoản? <a href="register.php" class="register-link">Đăng ký ngay</a></p>
            </footer>

        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            toggleBtn.style.opacity = isPassword ? '1' : '0.5';
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>