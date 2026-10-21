<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tự động lấy từ Render Environment
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');
$port = getenv('DB_PORT');

// Nếu chạy ở máy local (XAMPP) thì dùng thông số mặc định
if (!$host) {
    $host = 'localhost';
    $dbname = 'clothing_shop';
    $username = 'root';
    $password = '';
    $port = '3306';
}

try {
    // Tạo DSN chuẩn cho MySQL PDO
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    $options = [
        // Trỏ tới file ca.pem nằm cùng thư mục
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/ca.pem',
        // Bỏ qua kiểm tra tên miền (giúp tránh lỗi chứng chỉ trên môi trường cloud)
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Kết nối database thất bại: " . $e->getMessage());
}

// ... (Các hàm bên dưới giữ nguyên)
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Hàm kiểm tra quyền admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Hàm làm sạch dữ liệu đầu ra
function sanitize($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   LOAD CÁC HELPER
   ============================================================ */
require_once __DIR__ . '/discount_helper.php';
require_once __DIR__ . '/stock_helper.php';
require_once __DIR__ . '/cart_storage.php';   // ✅ Helper quản lý giỏ hàng DB

if (isset($pdo) && session_status() === PHP_SESSION_ACTIVE) {
    restoreCartFromDb($pdo);
}