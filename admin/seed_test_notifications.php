<?php
/**
 * ADMIN — Tạo thông báo mẫu để test giao diện
 * ⚠️ CHỈ DÙNG ĐỂ TEST — XÓA FILE NÀY SAU KHI TEST XONG
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền.']);
}

$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên hết hạn.']);
}

$samples = [
    [
        'type' => 'order_new',
        'title' => '🛒 Đơn hàng mới #1024',
        'message' => "Khách hàng: Nguyễn Văn A\nSố tiền: 850.000đ\nPhương thức: COD",
        'link' => 'orders.php?q=1024',
        'icon' => '🛒',
        'priority' => 'high',
    ],
    [
        'type' => 'order_confirmed',
        'title' => '✅ Đã xác nhận đơn #1023',
        'message' => "Trạng thái: Đã xác nhận\nKhách: Trần Thị B",
        'link' => 'orders.php?q=1023',
        'icon' => '✅',
        'priority' => 'normal',
    ],
    [
        'type' => 'order_cancelled',
        'title' => '❌ Đơn #1022 đã hủy',
        'message' => "Lý do: Khách yêu cầu hủy",
        'link' => 'orders.php?q=1022',
        'icon' => '❌',
        'priority' => 'high',
    ],
    [
        'type' => 'return_new',
        'title' => '🔄 Yêu cầu hoàn hàng mới RT20261007-0001',
        'message' => "Khách: Lê Văn C\nĐơn gốc: #1015\nSố tiền: 350.000đ\nLý do: Sản phẩm bị lỗi",
        'link' => 'returns.php?id=1',
        'icon' => '🔄',
        'priority' => 'urgent',
    ],
    [
        'type' => 'return_approved',
        'title' => '✓ Đã duyệt hoàn 350.000đ',
        'message' => "Mã đơn hoàn: RT20261007-0001\nĐơn gốc: #1015",
        'link' => 'returns.php?id=1',
        'icon' => '✓',
        'priority' => 'normal',
    ],
    [
        'type' => 'review_new',
        'title' => '⭐ Đánh giá 5 sao — Nguyễn Văn D',
        'message' => "Sản phẩm: Áo sơ mi linen trắng\nĐánh giá: ★★★★★",
        'link' => 'reviews.php?id=1',
        'icon' => '⭐',
        'priority' => 'normal',
    ],
    [
        'type' => 'review_low',
        'title' => '⚠️ ĐÁNH GIÁ 1 SAO — Trần Thị E',
        'message' => "Sản phẩm: Quần jeans skinny xanh\nĐánh giá: ★☆☆☆☆\nNội dung: Vải không đẹp như hình",
        'link' => 'reviews.php?id=2',
        'icon' => '⚠️',
        'priority' => 'urgent',
    ],
    [
        'type' => 'tax_warning',
        'title' => '📊 Doanh thu sắp đạt ngưỡng thuế',
        'message' => "Doanh thu hiện tại: 425.000.000đ\nĐã đạt 85% ngưỡng 500tr.",
        'link' => 'tax.php',
        'icon' => '📊',
        'priority' => 'high',
    ],
    [
        'type' => 'discount_event',
        'title' => '🎉 Sự kiện "Quốc tế Phụ nữ" bắt đầu ngày mai',
        'message' => "Sự kiện sẽ diễn ra từ 08/03/2026.\nGiảm: 30% cho Nữ.",
        'link' => 'discount-events.php',
        'icon' => '🎉',
        'priority' => 'high',
    ],
    [
        'type' => 'stock_low',
        'title' => '📦 Sắp hết hàng: Áo thun basic trắng',
        'message' => "Tồn kho hiện tại: 3 sản phẩm\nChi tiết size: S: 1 · M: 1 · L: 1 · XL: 0 · 2XL: 0",
        'link' => 'products.php?id=1',
        'icon' => '📦',
        'priority' => 'high',
    ],
    [
        'type' => 'stock_low',
        'title' => '🚨 HẾT HÀNG: Váy midi hoa nhí',
        'message' => "Tồn kho hiện tại: 0 sản phẩm\nChi tiết size: S: 0 · M: 0 · L: 0 · XL: 0 · 2XL: 0",
        'link' => 'products.php?id=2',
        'icon' => '🚨',
        'priority' => 'urgent',
    ],
    [
        'type' => 'system',
        'title' => '⚙️ Hệ thống cập nhật thành công',
        'message' => "Đã đồng bộ 125 sản phẩm lên hệ thống.",
        'link' => null,
        'icon' => '⚙️',
        'priority' => 'low',
    ],
];

$created = 0;
foreach ($samples as $s) {
    $id = notifyAdmin($s['type'], $s['title'], $s['message'], [
        'link'     => $s['link'],
        'icon'     => $s['icon'],
        'priority' => $s['priority'],
        'ref_type' => 'test',
    ]);
    if ($id > 0) $created++;
}

jsonExit([
    'ok'  => true,
    'msg' => "✅ Đã tạo {$created} thông báo mẫu. Bấm F5 để xem.",
    'created' => $created,
]);