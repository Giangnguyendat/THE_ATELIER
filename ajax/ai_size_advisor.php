<?php
// Bật báo lỗi để dễ debug (sẽ tắt khi chạy thực tế)
ini_set('display_errors', 0); 
error_reporting(E_ALL);

require_once '../includes/config.php';
require_once '../includes/chatbot_helper.php'; 

header('Content-Type: application/json; charset=utf-8');

// Bắt đầu bọc try-catch để không bao giờ văng lỗi 500
try {
    if (!isLoggedIn()) {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
        exit;
    }

    $height    = (float)($_POST['height'] ?? 0);
    $weight    = (float)($_POST['weight'] ?? 0);
    $bodyType  = $_POST['body_type'] ?? 'can_doi';
    $fitPref   = $_POST['fit_pref'] ?? 'vua_van';
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($height < 100 || $weight < 20 || $productId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT name, product_type, gender, description FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy sản phẩm.']);
        exit;
    }

    $bodyTypeLabel = [
        'gay' => 'Gầy (Mảnh khảnh)',
        'can_doi' => 'Cân đối',
        'day_dan' => 'Đầy đặn (Có da có thịt)'
    ][$bodyType] ?? 'Cân đối';

    $fitPrefLabel = [
        'om_sat' => 'Ôm sát (Thể hiện đường cong)',
        'vua_van' => 'Vừa vặn',
        'oversize' => 'Oversize (Rộng rãi thoải mái)'
    ][$fitPref] ?? 'Vừa vặn';

    $productType = $product['product_type'];
    $productName = $product['name'];
    $genderText  = ($product['gender'] === 'female') ? 'Nữ' : (($product['gender'] === 'male') ? 'Nam' : 'Unisex');
    $productDesc = mb_substr($product['description'] ?? '', 0, 100);

    $systemPrompt = "Bạn là chuyên gia tư vấn thời trang của thương hiệu THE ATELIER.
Nhiệm vụ của bạn là tư vấn size quần áo cho khách hàng dựa trên số đo và sở thích của họ.

THÔNG TIN KHÁCH HÀNG:
- Chiều cao: {$height} cm
- Cân nặng: {$weight} kg
- Dáng người: {$bodyTypeLabel}
- Sở thích mặc: {$fitPrefLabel}

THÔNG TIN SẢN PHẨM:
- Tên sản phẩm: {$productName}
- Loại sản phẩm: {$productType}
- Giới tính: {$genderText}
- Mô tả: {$productDesc}

QUY TẮC TƯ VẤN:
1. Tính toán size cơ bản dựa trên chiều cao và cân nặng (BMI). Quy ước size: S, M, L, XL, 2XL.
2. Nếu khách thích Oversize, hãy cộng thêm 1 size. Nếu khách gầy + thích ôm sát, có thể trừ 1 size.
3. Nếu là đồ bó, nên khuyên tăng size để thoải mái.

YÊU CẦU ĐẦU RA BẮT BUỘC:
Trả về một đối tượng JSON hợp lệ với cấu trúc chính xác như sau:
{
  \"size\": \"L\",
  \"explanation\": \"Với chiều cao 1m70 và cân nặng 65kg cùng sở thích mặc vừa vặn, bạn nên chọn Size L cho mẫu Áo Sơ Mi này để thoải mái vận động.\"
}
Tuyệt đối không thêm bất kỳ văn bản nào khác ngoài JSON.";

    $userMessage = "Tôi cao {$height}cm, nặng {$weight}kg. Tôi nên mặc size nào?";

    // Gọi AI (Nếu hàm này bị lỗi bên trong, nó sẽ trả về null)
    $aiReply = chatbotCallAI($systemPrompt, $userMessage, [], true);

        if (!$aiReply) {
        echo json_encode([
            'ok' => false, 
            'msg' => 'Hệ thống AI đang bận hoặc quá tải (Rate Limit). Vui lòng đợi 10-15 giây rồi thử lại.'
        ]);
        exit;
    }

    $data = json_decode($aiReply, true);

    if ($data && isset($data['size']) && isset($data['explanation'])) {
        echo json_encode([
            'ok' => true, 
            'size' => $data['size'], 
            'explanation' => $data['explanation']
        ]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'AI phản hồi không đúng định dạng JSON.']);
    }

} catch (Throwable $e) {
    // Nếu có bất kỳ lỗi PHP nào (sai tên bảng, sai cột...), sẽ trả về JSON thay vì HTML
    echo json_encode([
        'ok' => false, 
        'msg' => 'Lỗi hệ thống PHP: ' . $e->getMessage()
    ]);
}