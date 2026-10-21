<?php
// Bật hiển thị lỗi (tắt đi khi đưa lên môi trường thực tế bằng cách đổi thành 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   1. CẤU HÌNH TỰ ĐỘNG NHẬN DIỆN MÔI TRƯỜNG (RENDER HAY XAMPP)
   ============================================================ */
if (getenv('DB_HOST')) {
    // Môi trường Cloud (Render + Aiven)
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME');
    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');
    $port = getenv('DB_PORT');
    // Đường dẫn file CA (Ưu tiên thư mục Secret của Render, nếu không có thì tìm ở thư mục hiện tại)
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

/* ============================================================
   2. HÀM KẾT NỐI VÀ TỰ ĐỘNG KẾT NỐI LẠI (FIX LỖI 2006)
   ============================================================ */
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

function getValidConnection(&$pdo, $dsn, $username, $password, $ca_path) {
    try {
        if ($pdo) {
            $pdo->query('SELECT 1'); // Ping kiểm tra kết nối
        } else {
            throw new PDOException("No connection");
        }
    } catch (PDOException $e) {
        // Nếu mất kết nối, tự động tạo lại
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
   3. CÁC HÀM HELPER CƠ BẢN
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

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   4. LOAD CÁC HELPER KHÁC
   ============================================================ */
if (file_exists(__DIR__ . '/discount_helper.php')) require_once __DIR__ . '/discount_helper.php';
if (file_exists(__DIR__ . '/stock_helper.php')) require_once __DIR__ . '/stock_helper.php';
if (file_exists(__DIR__ . '/cart_storage.php')) {
    require_once __DIR__ . '/cart_storage.php';
    if (session_status() === PHP_SESSION_ACTIVE && function_exists('restoreCartFromDb')) {
        restoreCartFromDb($pdo);
    }
}