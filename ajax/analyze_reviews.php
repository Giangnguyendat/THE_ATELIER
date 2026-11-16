<?php
// File: ajax/analyze_reviews.php
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// Bật báo lỗi để debug (nhớ tắt khi chạy thật)
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Phương thức không hợp lệ.']);
    exit;
}

// 1. Lấy 60 đánh giá có nội dung gần nhất
$stmt = $pdo->query("
    SELECT r.rating, r.content, p.name AS product_name 
    FROM reviews r
    JOIN products p ON r.product_id = p.id
    WHERE r.content IS NOT NULL AND TRIM(r.content) != ''
    ORDER BY r.created_at DESC
    LIMIT 60
");
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($reviews)) {
    echo json_encode(['ok' => false, 'msg' => 'Chưa có đủ dữ liệu đánh giá để phân tích.']);
    exit;
}

// 2. Lấy thống kê điểm TB kèm số lượng đánh giá theo từng sản phẩm
$stmtStats = $pdo->query("
    SELECT p.name, COUNT(r.id) as total_reviews, AVG(r.rating) as avg_rating
    FROM products p
    JOIN reviews r ON p.id = r.product_id
    GROUP BY p.id, p.name
    ORDER BY avg_rating ASC, total_reviews DESC
");
$productStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

// 3. Phân loại review tiêu cực và tích cực
$negativeReviews = "";
$positiveReviews = "";
$negCount = 0;
$posCount = 0;

foreach ($reviews as $r) {
    $item = "- [{$r['rating']}*] {$r['product_name']}: \"{$r['content']}\"\n";
    if ($r['rating'] <= 3) {
        $negativeReviews .= $item;
        $negCount++;
    } else {
        $positiveReviews .= $item;
        $posCount++;
    }
}

$statsText = "";
foreach ($productStats as $ps) {
    $avg = round($ps['avg_rating'], 1);
    $statsText .= "- {$ps['name']}: {$avg}/5* ({$ps['total_reviews']} đánh giá)\n";
}

// 4. Xây dựng Prompt
$prompt = "Bạn là chuyên gia kiểm soát chất lượng và trải nghiệm khách hàng (QA & CX Specialist) của THE ATELIER.\n";
$prompt .= "Nhiệm vụ: Phân tích sâu các đánh giá thực tế của khách hàng, gắn lỗi/khen cụ thể với từng sản phẩm. TUYỆT ĐỐI KHÔNG nhận xét chung chung.\n\n";

$prompt .= "=== DỮ LIỆU ĐÁNH GIÁ TIÊU CỰC (<= 3 sao: {$negCount} lượt) ===\n" . ($negativeReviews ?: "Không có đánh giá tiêu cực.\n") . "\n";
$prompt .= "=== DỮ LIỆU ĐÁNH GIÁ TÍCH CỰC (4-5 sao: {$posCount} lượt) ===\n" . ($positiveReviews ?: "Không có đánh giá tích cực.\n") . "\n";
$prompt .= "=== BẢNG THỐNG KÊ TOÀN BỘ SẢN PHẨM ===\n" . $statsText . "\n\n";

$prompt .= "Hãy tổng hợp và xuất ra HTML hợp lệ (chỉ dùng các thẻ: div, h3, h4, ul, li, p, strong; không markdown, không thẻ html/body/head/style).\n";
$prompt .= "Cấu trúc báo cáo BẮT BUỘC:\n";
$prompt .= "1. <h3>1. Bóc Tách Sản Phẩm Bị Đánh Giá Tệ & Nguyên Nhân Trực Tiếp</h3>\n";
$prompt .= "2. <h3>2. Phân Tích Điểm Mạnh & Sản Phẩm Tiêu Biểu</h3>\n";
$prompt .= "3. <h3>3. Bảng Phân Nhóm Nguyên Nhân Gây Bất Mãn</h3>\n";
$prompt .= "4. <h3>4. Kế Hoạch Xử Lý & Đề Xuất Khắc Phục Cụ Thể</h3>\n";

// 5. Gọi API Groq (Model: llama-3.3-70b-versatile)
$apiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
if (empty($apiKey)) {
    echo json_encode(['ok' => false, 'msg' => 'Chưa cấu hình GROQ_API_KEY.']);
    exit;
}

$url = 'https://api.groq.com/openai/v1/chat/completions';

$data = [
    'model' => 'openai/gpt-oss-120b',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Bạn là chuyên gia phân tích dữ liệu và trải nghiệm khách hàng cho thương hiệu thời trang THE ATELIER. Luôn trả lời bằng HTML hợp lệ theo yêu cầu, bám sát dữ liệu thực tế, không nhận xét chung chung.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ],
    'temperature' => 0.2,
    'max_tokens' => 2048
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['ok' => false, 'msg' => 'Lỗi cURL: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {$errorData = json_decode($response, true);$errorMsg = $errorData['error']['message'] ?? ('HTTP Error ' .$httpCode);
    echo json_encode(['ok' => false, 'msg' => 'Lỗi Groq API: ' . $errorMsg]);
    exit;
}

$result = json_decode($response, true);
$text =$result['choices'][0]['message']['content'] ?? '';

// Làm sạch markdown tags nếu có
$text = preg_replace('/```(?:html)?/i', '', $text);
$text = trim($text);

if (empty($text)) {
    echo json_encode(['ok' => false, 'msg' => 'AI không thể phân tích lúc này.']);
    exit;
}

echo json_encode(['ok' => true, 'html' => $text]);