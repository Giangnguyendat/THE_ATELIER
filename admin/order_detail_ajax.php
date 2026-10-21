<?php
/**
 * API trả về chi tiết đơn hàng dạng JSON cho modal admin
 */
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền.']);
    exit;
}

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'ID không hợp lệ.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT o.*, u.full_name AS user_name, u.email AS user_email
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy đơn hàng.']);
    exit;
}

// Danh sách sản phẩm trong đơn
$itemStmt = $pdo->prepare("
    SELECT oi.*, p.name, p.image_url
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Tính subtotal sản phẩm (không gồm ship)
$subtotalProducts = 0;
foreach ($items as $it) {
    $subtotalProducts += $it['price'] * $it['quantity'];
}
$order['subtotal_products'] = $subtotalProducts;

// Định dạng ngày
$order['created_at_fmt']   = date('d/m/Y H:i', strtotime($order['created_at']));
$order['cancelled_at_fmt'] = !empty($order['cancelled_at']) 
    ? date('d/m/Y H:i', strtotime($order['cancelled_at'])) 
    : null;

// Trạng thái thanh toán
$payStatusLabels = [
    'unpaid'         => 'Chưa thanh toán',
    'pending'        => 'Chờ thanh toán',
    'paid'           => 'Đã thanh toán',
    'refund_pending' => 'Chờ hoàn tiền',
];
$order['payment_status_label'] = $payStatusLabels[$order['payment_status'] ?? 'unpaid'] ?? 'Không rõ';
$order['paid_at_fmt'] = !empty($order['paid_at']) 
    ? date('d/m/Y H:i', strtotime($order['paid_at'])) 
    : null;

echo json_encode([
    'ok'    => true,
    'order' => $order,
    'items' => $items,
]);