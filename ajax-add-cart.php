<?php
require_once 'includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
$size = isset($_POST['size']) ? trim($_POST['size']) : 'M';

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Sản phẩm không hợp lệ.']);
    exit;
}

// Khởi tạo giỏ hàng nếu chưa có
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Tạo key duy nhất cho sản phẩm kèm size
$cartKey = $productId . '_' . $size;

if (isset($_SESSION['cart'][$cartKey])) {
    $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
} else {
    $_SESSION['cart'][$cartKey] = [
        'product_id' => $productId,
        'quantity'   => $quantity,
        'size'       => $size
    ];
}

// Tính tổng số lượng sản phẩm trong giỏ
$totalCartItems = array_sum(array_column($_SESSION['cart'], 'quantity'));

echo json_encode([
    'success' => true,
    'message' => 'Thêm sản phẩm vào giỏ hàng thành công!',
    'total_items' => $totalCartItems
]);
exit;