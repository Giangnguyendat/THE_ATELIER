<?php
// Bật hiển thị lỗi để dễ debug (Tắt khi chạy thực tế bằng cách đổi thành 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   1. CẤU HÌNH KẾT NỐI DATABASE (TỰ ĐỘNG NHẬN DIỆN MÔI TRƯỜNG)
   ============================================================ */
if (getenv('DB_HOST')) {
    // Môi trường Cloud (Render)
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME');
    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');
    $port = getenv('DB_PORT');
    // Đường dẫn file CA trên Render (Secret File)
    $ca_path = file_exists('/etc/secrets/ca.pem') ? '/etc/secrets/ca.pem' : __DIR__ . '/ca.pem';
} else {
    // Môi trường Local (XAMPP)
    $host = 'localhost';
    $dbname = 'clothing_shop';
    $username = 'root';
    $password = '';
    $port = '3306';
    $ca_path = ''; // Local không cần SSL
}

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

// Hàm tạo kết nối
function connectDB($dsn, $username, $password, $ca_path) {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10, // Timeout 10s
    ];
    if ($ca_path && file_exists($ca_path)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca_path;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    return new PDO($dsn, $username, $password, $options);
}

// Hàm kiểm tra và tự động kết nối lại nếu bị ngắt (Fix lỗi 2006)
function getValidConnection(&$pdo, $dsn, $username, $password, $ca_path) {
    try {
        if ($pdo) {
            $pdo->query('SELECT 1'); // Ping thử
        } else {
            throw new PDOException("No connection");
        }
    } catch (PDOException $e) {
        // Nếu mất kết nối, tạo lại
        $pdo = connectDB($dsn, $username, $password, $ca_path);
    }
    return $pdo;
}

// Khởi tạo kết nối ban đầu
try {
    $pdo = connectDB($dsn, $username, $password, $ca_path);
} catch (PDOException $e) {
    die("Kết nối database thất bại: " . $e->getMessage());
}

/* ============================================================
   2. CÁC HÀM HELPER (Dùng chung toàn hệ thống)
   ============================================================ */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function sanitize($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Tạo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load các helper (Nếu có)
if (file_exists(__DIR__ . '/discount_helper.php')) require_once __DIR__ . '/discount_helper.php';
if (file_exists(__DIR__ . '/stock_helper.php')) require_once __DIR__ . '/stock_helper.php';
if (file_exists(__DIR__ . '/cart_storage.php')) {
    require_once __DIR__ . '/cart_storage.php';
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Gọi hàm khôi phục giỏ hàng nếu có
        if (function_exists('restoreCartFromDb')) {
            restoreCartFromDb($pdo);
        }
    }
}

/* ============================================================
   3. TRUY VẤN DỮ LIỆU CHO TRANG CHỦ
   ============================================================ */
try {
    // Đảm bảo kết nối còn sống trước khi query
    $pdo = getValidConnection($pdo, $dsn, $username, $password, $ca_path);

    // Lấy Banners
    $stmtBanners = $pdo->query("SELECT * FROM banners WHERE active = 1 ORDER BY id DESC");
    $banners = $stmtBanners->fetchAll();

    // Lấy Danh mục
    $stmtCategories = $pdo->query("SELECT * FROM categories");
    $categories = $stmtCategories->fetchAll();

    // Lấy Sản phẩm mới nhất (8 sản phẩm)
    $stmtProducts = $pdo->query("SELECT * FROM products ORDER BY created_at DESC LIMIT 8");
    $products = $stmtProducts->fetchAll();

} catch (PDOException $e) {
    // Nếu query lỗi, hiển thị thông báo (không làm sập trang)
    $error_msg = "Lỗi lấy dữ liệu: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>THE ATELIER - Thời Trang Tinh Tế</title>
    <!-- Nhớ thêm link CSS của bạn vào đây -->
    <!-- <link rel="stylesheet" href="assets/css/style.css"> -->
    <style>
        /* CSS tạm thời để hiển thị cơ bản */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f9f9f9; }
        header { background: #fff; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .product-card { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .product-card img { width: 100%; height: auto; border-radius: 4px; }
        .price { color: #a8461f; font-weight: bold; margin-top: 10px; }
        .error { color: red; padding: 20px; background: #fee; }
    </style>
</head>
<body>

<header>
    <div class="container">
        <h1>THE ATELIER</h1>
        <!-- Thanh Menu -->
        <nav>
            <a href="index.php">Trang chủ</a> | 
            <a href="products.php">Sản phẩm</a> | 
            <a href="cart.php">Giỏ hàng</a> | 
            <?php if (isLoggedIn()): ?>
                <a href="my-orders.php">Đơn hàng</a> | 
                <a href="logout.php">Đăng xuất</a>
            <?php else: ?>
                <a href="login.php">Đăng nhập</a> | 
                <a href="register.php">Đăng ký</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container">
    <?php if (isset($error_msg)): ?>
        <div class="error"><?= sanitize($error_msg) ?></div>
    <?php endif; ?>

    <!-- Phần Banner (Nếu có) -->
    <?php if (!empty($banners)): ?>
    <section style="margin-bottom: 40px;">
        <div style="display: flex; gap: 10px; overflow-x: auto;">
            <?php foreach ($banners as $banner): ?>
                <img src="<?= sanitize($banner['image_url']) ?>" alt="<?= sanitize($banner['title'] ?? 'Banner') ?>" style="max-height: 300px; border-radius: 8px;">
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Phần Danh mục -->
    <?php if (!empty($categories)): ?>
    <section style="margin-bottom: 40px;">
        <h2>Danh mục</h2>
        <div style="display: flex; gap: 15px;">
            <?php foreach ($categories as $cat): ?>
                <a href="products.php?category_id=<?= $cat['id'] ?>" style="padding: 10px 20px; background: #eee; text-decoration: none; color: #333; border-radius: 4px;">
                    <?= sanitize($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Phần Sản phẩm mới -->
    <section>
        <h2>Sản phẩm mới nhất</h2>
        <div class="grid">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $prod): ?>
                    <div class="product-card">
                        <a href="product-details.php?id=<?= $prod['id'] ?>">
                            <!-- Xử lý ảnh: Nếu không có ảnh thì dùng ảnh mặc định -->
                            <img src="<?= !empty($prod['image_url']) ? sanitize($prod['image_url']) : 'assets/images/placeholder.jpg' ?>" alt="<?= sanitize($prod['name']) ?>">
                        </a>
                        <h3 style="font-size: 16px; margin: 10px 0;">
                            <a href="product-details.php?id=<?= $prod['id'] ?>" style="text-decoration: none; color: #333;"><?= sanitize($prod['name']) ?></a>
                        </h3>
                        <div class="price"><?= number_format($prod['price'], 0, ',', '.') ?> VNĐ</div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Chưa có sản phẩm nào.</p>
            <?php endif; ?>
        </div>
    </section>
</main>

</body>
</html>