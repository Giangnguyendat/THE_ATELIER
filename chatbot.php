<?php
/**
 * Chatbot API endpoint
 * POST JSON { message } → trả JSON { message, products, source }
 */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log(sprintf('chatbot warning [%d]: %s in %s:%d', $errno, $errstr, $errfile, $errline));
    return true;
});

set_exception_handler(function ($e) {
    error_log('chatbot uncaught: ' . $e->getMessage());
    if (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'message'  => 'Xin lỗi, mình gặp sự cố khi xử lý. Bạn thử lại hoặc gọi hotline **1900 123 456** nhé.',
        'products' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once 'includes/config.php';
require_once 'includes/chatbot_helper.php';

/* Helper phải load đủ trước khi gọi */
if (!function_exists('chatbotReply')) {
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'message'  => 'Hệ thống đang bảo trì. Vui lòng thử lại sau hoặc gọi hotline **1900 123 456**.',
        'products' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
        'message'  => 'Bạn đã gửi khá nhiều tin nhắn trong 1 giờ. Vui lòng đợi chút rồi thử lại, hoặc gọi hotline **1900 123 456** để được hỗ trợ ngay.',
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
        'message'  => 'Bạn chưa nhập gì cả. Bạn cần mình tư vấn gì ạ?',
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

    if (!empty($result['products']) && is_array($result['products'])) {
        chatbotSaveProductContext($result['products']);
    }

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
            'source'     => $result['source'] ?? 'unknown',
            'complexity' => $result['complexity'] ?? null,
            'input'      => $message,
        ] : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log(sprintf(
        'chatbot.php ERROR: %s | File: %s:%d | Input: %s',
        $e->getMessage(), $e->getFile(), $e->getLine(), $message
    ));

    if (ob_get_level() > 0) ob_end_clean();

    echo json_encode([
        'message'  => 'Xin lỗi, mình không hiểu câu hỏi của bạn. Bạn thử lại sau hoặc gọi hotline **1900 123 456** nhé.',
        'products' => [],
        '_error'   => isset($_GET['debug']) ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}