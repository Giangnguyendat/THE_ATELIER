<?php
// Tắt hiển thị lỗi trực tiếp để không làm hỏng chuỗi JSON trả về
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // 1. Kiểm tra CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $token)) {
        throw new Exception('Phiên làm việc hết hạn. Vui lòng tải lại trang.');
    }

    // 2. Truy vấn kết hợp bảng `products` và `product_sizes`
    // Tìm các size có số lượng từ 1 đến 5 (sắp hết)
    $sql = "
        SELECT 
            p.id AS product_id, 
            p.name AS product_name, 
            ps.size, 
            ps.stock
        FROM product_sizes ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.stock > 0 AND ps.stock <= 5
        ORDER BY ps.stock ASC
    ";

    $stmt = $pdo->query($sql);
    $lowStockVariants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lowStockVariants)) {
        echo json_encode(['ok' => true, 'msg' => 'Tất cả các size đều có tồn kho an toàn (trên 5). Không có thông báo mới.']);
        exit;
    }

    $createdCount = 0;
    $today = date('Y-m-d');

    // 3. Duyệt qua từng size và tạo thông báo
    foreach ($lowStockVariants as $item) {
        $prodId   = (int)$item['product_id'];
        $prodName = $item['product_name'];
        $size     = $item['size'];
        $stock    = (int)$item['stock'];

        // Mã hóa size để lưu vào cột ref_type (VD: size_M)
        // Việc này giúp phân biệt thông báo của size M và size L của cùng 1 sản phẩm
        $refType = 'size_' . $size;

        // Kiểm tra xem HÔM NAY đã tạo thông báo cho SẢN PHẨM + SIZE này chưa để tránh spam
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM admin_notifications 
            WHERE type = 'stock_low' 
            AND ref_id = ? 
            AND ref_type = ? 
            AND DATE(created_at) = ?
        ");
        $checkStmt->execute([$prodId, $refType, $today]);
        
        if ((int)$checkStmt->fetchColumn() > 0) {
            continue; // Đã báo hôm nay rồi, bỏ qua không tạo trùng
        }

        // Tạo thông báo mới
        $insertStmt = $pdo->prepare("
            INSERT INTO admin_notifications 
            (type, title, message, link, icon, priority, ref_id, ref_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Nội dung thông báo
        $title = "Sắp hết size {$size}: {$prodName}";
        $message = "Sản phẩm \"{$prodName}\" (Size {$size}) chỉ còn {$stock} cái trong kho. Vui lòng nhập thêm hàng.";
        $link = "product-edit.php?id=" . $prodId; // Đường dẫn tới trang sửa sản phẩm của bạn
        $icon = "ph-package";
        
        // Ưu tiên khẩn cấp nếu số lượng <= 2, còn lại là ưu tiên cao
        $priority = ($stock <= 2) ? 'urgent' : 'high'; 

        $insertStmt->execute([
            'stock_low',
            $title,
            $message,
            $link,
            $icon,
            $priority,
            $prodId,
            $refType
        ]);
        
        $createdCount++;
    }

    // 4. Trả kết quả về cho giao diện
    if ($createdCount > 0) {
        echo json_encode(['ok' => true, 'msg' => "Đã quét xong. Đã tạo {$createdCount} thông báo sắp hết hàng (theo size) mới!"]);
    } else {
        echo json_encode(['ok' => true, 'msg' => 'Đã quét xong. Các size sắp hết hàng đều đã được báo cáo trong hôm nay.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Lỗi máy chủ: ' . $e->getMessage()
    ]);
}