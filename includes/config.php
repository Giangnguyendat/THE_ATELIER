<?php
// Bật hiển thị lỗi khi chạy debug (đặt thành 0 khi chạy chính thức)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Thiết lập múi giờ Việt Nam để so sánh ngày tháng chuẩn xác
date_default_timezone_set('Asia/Ho_Chi_Minh');

/* ============================================================
   1. CẤU HÌNH TỰ ĐỘNG NHẬN DIỆN MÔI TRƯỜNG (RENDER HAY XAMPP)
   ============================================================ */
if (getenv('DB_HOST')) {
    // Môi trường Cloud (Render + Aiven)
    $host     = getenv('DB_HOST');
    $dbname   = getenv('DB_NAME');
    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');
    $port     = getenv('DB_PORT') ?: '3306';
    // Đường dẫn file chứng chỉ CA
    $ca_path  = file_exists('/etc/secrets/ca.pem') ? '/etc/secrets/ca.pem' : __DIR__ . '/ca.pem';
} else {
    // Môi trường Local (XAMPP)
    $host     = 'localhost';
    $dbname   = 'clothing_shop';
    $username = 'root';
    $password = '';
    $port     = '3306';
    $ca_path  = ''; // Local không cần SSL
}

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

/* ============================================================
   2. HÀM KẾT NỐI VÀ TỰ ĐỘNG KẾT NỐI LẠI (FIX LỖI 2006 MySQL)
   ============================================================ */
function connectDB($dsn, $username, $password, $ca_path) {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ];
    if ($ca_path && file_exists($ca_path)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca_path;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    return new PDO($dsn, $username, $password, $options);
}

function getValidConnection(&$pdo, $dsn, $username, $password, $ca_path) {
    try {
        if (!empty($pdo)) {
            $pdo->query('SELECT 1'); // Ping kiểm tra kết nối còn sống không
        } else {
            throw new PDOException("No connection");
        }
    } catch (PDOException $e) {
        $pdo = connectDB($dsn, $username, $password, $ca_path);
    }
    return $pdo;
}

// Khởi tạo kết nối PDO ban đầu
try {
    $pdo = connectDB($dsn, $username, $password, $ca_path);
} catch (PDOException $e) {
    die("Kết nối database thất bại: " . $e->getMessage());
}

/* ============================================================
   3. CẤU HÌNH API KEYS (GEMINI / OPENAI)
   ============================================================ */
// Ưu tiên đọc biến môi trường (Render/Server ENV) cho Gemini
if (getenv('GEMINI_API_KEY')) {
    if (!defined('GEMINI_API_KEY')) {
        define('GEMINI_API_KEY', getenv('GEMINI_API_KEY'));
    }
}

// Vẫn giữ OpenAI phòng trường hợp có module khác dùng
if (getenv('OPENAI_API_KEY')) {
    if (!defined('OPENAI_API_KEY')) {
        define('OPENAI_API_KEY', getenv('OPENAI_API_KEY'));
    }
}

// Nếu chạy local (XAMPP), nạp các key từ file secret.php
if (file_exists(__DIR__ . '/secret.php')) {
    require_once __DIR__ . '/secret.php';
}

/* ============================================================
   4. CÁC HÀM HELPER CƠ BẢN & BẢO MẬT
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
   5. HÀM TỰ ĐỘNG ĐỒNG BỘ TRẠNG THÁI SỰ KIỆN GIẢM GIÁ (AUTO SYNC)
   ============================================================ */
if (!function_exists('syncDiscountEventsStatus')) {
    function syncDiscountEventsStatus(PDO $pdo): void {
        try {
            $today = new DateTime();
            $currentMD = (int)$today->format('m') * 100 + (int)$today->format('d'); // VD: 09/11 -> 1109

            // Chỉ quét các sự kiện không bị can thiệp thủ công (is_manual_override = 0)
            $stmt = $pdo->query("SELECT id, start_month, start_day, end_month, end_day, active FROM discount_events WHERE is_manual_override = 0");
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($events)) {
                return;
            }

            $toActivate = [];
            $toDeactivate = [];

            foreach ($events as $ev) {
                $startMD = ((int)$ev['start_month']) * 100 + (int)$ev['start_day'];
                $endMD   = ((int)$ev['end_month']) * 100 + (int)$ev['end_day'];

                $shouldBeActive = false;

                if ($startMD <= $endMD) {
                    // Cùng năm (VD: 08/03 -> 10/03 hoặc 01/06 -> 30/08)
                    $shouldBeActive = ($currentMD >= $startMD && $currentMD <= $endMD);
                } else {
                    // Xuyên Tết / Xuyên năm (VD: 25/12 -> 05/01)
                    $shouldBeActive = ($currentMD >= $startMD || $currentMD <= $endMD);
                }

                if ($shouldBeActive && (int)$ev['active'] === 0) {
                    $toActivate[] = (int)$ev['id'];
                } elseif (!$shouldBeActive && (int)$ev['active'] === 1) {
                    $toDeactivate[] = (int)$ev['id'];
                }
            }

            if (!empty($toActivate)) {
                $in = implode(',', $toActivate);
                $pdo->exec("UPDATE discount_events SET active = 1 WHERE id IN ($in)");
            }

            if (!empty($toDeactivate)) {
                $in = implode(',', $toDeactivate);
                $pdo->exec("UPDATE discount_events SET active = 0 WHERE id IN ($in)");
            }
        } catch (Exception $e) {
            error_log("Lỗi tự động đồng bộ sự kiện: " . $e->getMessage());
        }
    }
}

// Tự động kiểm tra và đồng bộ sự kiện khi bước sang ngày mới
if (!isset($_SESSION['last_event_sync_date']) || $_SESSION['last_event_sync_date'] !== date('Y-m-d')) {
    syncDiscountEventsStatus($pdo);
    $_SESSION['last_event_sync_date'] = date('Y-m-d');
}

/* ============================================================
   6. LOAD CÁC HELPER PHỤ TRỢ KHÁC
   ============================================================ */
if (file_exists(__DIR__ . '/discount_helper.php')) {
    require_once __DIR__ . '/discount_helper.php';
}

if (file_exists(__DIR__ . '/stock_helper.php')) {
    require_once __DIR__ . '/stock_helper.php';
}

if (file_exists(__DIR__ . '/cart_storage.php')) {
    require_once __DIR__ . '/cart_storage.php';
    if (session_status() === PHP_SESSION_ACTIVE && function_exists('restoreCartFromDb')) {
        restoreCartFromDb($pdo);
    }
}