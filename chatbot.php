<?php
/**
 * Chatbot API endpoint
 * POST JSON { message } → trả JSON { message, products }
 */

// 1. Bắt đầu bộ đệm NGAY LẬP TỨC để chặn mọi khoảng trắng / warning lọt ra ngoài
ob_start();

// 2. Cấu hình lỗi: Không in ra màn hình, chỉ ghi log
error_reporting(E_ALL);
ini_set('display_errors', 0);   
ini_set('log_errors', 1);        

// Bắt mọi warning/notice → ghi log, KHÔNG echo
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log(sprintf('chatbot warning [%d]: %s in %s:%d', $errno, $errstr, $errfile, $errline));
    return true; 
});

// Bắt exception chưa handle (kể cả TypeError, Fatal Error)
set_exception_handler(function ($e) {
    error_log('chatbot uncaught: ' . $e->getMessage());
    if (ob_get_level() > 0) ob_end_clean(); // Dọn rác
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'message'  => 'Xin lỗi, mình không hiểu câu hỏi của bạn. Bạn thử lại hoặc gọi hotline **1900 123 456** nhé!',
        'products' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once 'includes/config.php';
require_once 'includes/chatbot_helper.php';
// Kiểm tra xem file helper có lỗi cú pháp hay không
if (!function_exists('chatbotReply')) {
    die("LỖI: Không tìm thấy hàm chatbotReply. File helper chưa lưu hoặc bị lỗi cú pháp!");
}
header('Content-Type: application/json; charset=utf-8');

/* ============================================================
   RATE LIMIT — 30 tin/giờ
   ============================================================ */
$now  = time();
$rate = $_SESSION['chat_rate'] ?? ['count' => 0, 'reset_at' => $now + 3600];

if ($now >= $rate['reset_at']) {
    $rate = ['count' => 0, 'reset_at' => $now + 3600];
}
if ($rate['count'] >= 30) {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode([
        'message'  => "Bạn đã gửi khá nhiều tin nhắn trong 1 giờ. Vui lòng đợi chút rồi thử lại, hoặc gọi hotline **1900 123 456** để được hỗ trợ ngay ạ! 🙏",
        'products' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$rate['count']++;
$_SESSION['chat_rate'] = $rate;

/* ============================================================
   PARSE INPUT
   ============================================================ */
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = $_POST;

$message = trim((string)($input['message'] ?? ''));

if ($message === '') {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode([
        'message'  => 'Bạn chưa nhập gì cả 😊 Bạn cần mình tư vấn gì ạ?',
        'products' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($message) > 500) $message = mb_substr($message, 0, 500);

/* ============================================================
   XỬ LÝ
   ============================================================ */
try {
    $result = chatbotReply($pdo, $message);

    // Lưu context SP để dùng cho câu tiếp theo
    if (!empty($result['products']) && is_array($result['products'])) {
        chatbotSaveProductContext($result['products']);
    }

    // ✅ Xóa SẠCH mọi output rác (nếu có) trước khi in JSON
    if (ob_get_level() > 0) {
        $garbage = ob_get_clean();
        if (trim($garbage) !== '') {
            error_log('chatbot.php output rác: ' . substr($garbage, 0, 500));
        }
    }

    echo json_encode([
        'message'  => $result['message']  ?? '',
        'products' => $result['products'] ?? [],
        '_debug'   => isset($_GET['debug']) ? [
            'source' => $result['source'] ?? 'unknown',
            'input'  => $message,
        ] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) { // Đổi Exception thành Throwable để bắt cả Fatal Error
    error_log(sprintf('chatbot.php ERROR: %s | File: %s:%d | Input: %s', $e->getMessage(), $e->getFile(), $e->getLine(), $message));

    if (ob_get_level() > 0) ob_end_clean();

    echo json_encode([
        'message'  => 'Xin lỗi, mình không hiểu câu hỏi của bạn. Bạn thử lại sau hoặc gọi hotline **1900 123 456** nhé! 🙏',
        'products' => [],
        '_error'   => isset($_GET['debug']) ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}