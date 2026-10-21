<?php
// chatbot.php - Endpoint xử lý chat với AI Gemini

// 1. Tắt hiển thị lỗi HTML ra màn hình để tránh làm hỏng JSON trả về client
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Bắt đầu session nếu chưa có
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Thiết lập Header trả về chuẩn JSON UTF-8
header('Content-Type: application/json; charset=utf-8');

// Bật buffer để đảm bảo không có khoảng trắng/HTML thừa lọt vào JSON
ob_start();

try {
    // 2. Nạp file cấu hình và hàm
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/chatbot_functions.php';

    // 3. Lấy input JSON từ client
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);
    $rawMessage = trim($input['message'] ?? '');

    // Khởi tạo mảng response mặc định
    $response = [
        'message' => '',
        'products' => []
    ];

    if (empty($rawMessage)) {
        $response['message'] = "Xin lỗi, tôi chưa nhận được tin nhắn từ bạn.";
    } else {
        // Lấy user_id nếu đã đăng nhập
        $userId = $_SESSION['user_id'] ?? null;

        // Tạo hoặc lấy session chat hiện tại
        $chatSessionId = getOrCreateChatSession($userId);

        if (!$chatSessionId) {
            $response['message'] = 'Lỗi hệ thống: Không thể khởi tạo phiên làm việc.';
        } else {
            // Lưu tin nhắn người dùng vào Database
            saveChatMessage($chatSessionId, 'user', $rawMessage);

            // Gọi hàm xử lý Gemini AI kết hợp Function Calling
            $aiResult = processAiChatbot($rawMessage, $chatSessionId);

            $response['message'] = $aiResult['message'] ?? 'Xin lỗi, tôi chưa phản hồi được lúc này.';
            $response['products'] = $aiResult['products'] ?? [];

            // Lưu tin nhắn phản hồi của Bot vào Database
            if (!empty($response['message'])) {
                saveChatMessage($chatSessionId, 'bot', $response['message']);
            }
        }
    }

} catch (Throwable $e) {
    // Bắt toàn bộ ngoại lệ/lỗi Fatal để trả về JSON an toàn thay vì bung lỗi HTML
    $response = [
        'message' => 'Hệ thống xảy ra sự cố kỹ thuật. Vui lòng thử lại sau!',
        'products' => []
    ];
}

// 4. Xóa sạch mọi ký tự thừa trong bộ đệm trước khi output
if (ob_get_length()) {
    ob_clean();
}

// Trả về kết quả JSON duy nhất
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;