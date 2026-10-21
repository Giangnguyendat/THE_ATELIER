<?php
ob_start();

// Bật log lỗi (không hiển thị để tránh output rác)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Bắt fatal error → trả JSON thay vì HTML
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'  => false,
            'msg' => 'PHP Fatal: ' . $err['message'] . ' (dòng ' . $err['line'] . ')',
        ]);
    }
});

require_once '../includes/config.php';
// Gọi file helper thông báo
require_once __DIR__ . '/../admin/includes/notification_helper.php';

// ============================================================
// XÓA MỌI output rác từ config.php / warning trước đó
// ============================================================
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start(); // Start buffer mới sạch

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// XỬ LÝ
// ============================================================
if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method không hợp lệ.']);
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Phiên hết hạn.']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$productId = (int)($_POST['product_id'] ?? 0);
$orderId   = (int)($_POST['order_id']   ?? 0);
$rating    = (int)($_POST['rating']     ?? 0);
$content   = trim($_POST['content'] ?? '');

if ($productId <= 0 || $orderId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin.']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    echo json_encode(['ok' => false, 'msg' => 'Số sao phải từ 1 đến 5.']);
    exit;
}
if (mb_strlen($content) > 2000) {
    echo json_encode(['ok' => false, 'msg' => 'Đánh giá quá dài (tối đa 2000 ký tự).']);
    exit;
}

// 1. Check đơn hàng thuộc user VÀ đã delivered
$stmt = $pdo->prepare("
    SELECT o.id
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE o.id = ? AND o.user_id = ? AND o.status = 'delivered'
      AND oi.product_id = ?
    LIMIT 1
");
$stmt->execute([$orderId, $userId, $productId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Bạn chỉ có thể đánh giá sản phẩm trong đơn đã giao.']);
    exit;
}

// 2. Check đã review chưa
$check = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ? AND order_id = ? LIMIT 1");
$check->execute([$userId, $productId, $orderId]);
if ($check->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Bạn đã đánh giá sản phẩm này rồi.']);
    exit;
}

// 3. Upload ảnh
$uploadedImages = [];
$uploadDir = __DIR__ . '/../uploads/reviews/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

if (!empty($_FILES['images']['tmp_name'][0] ?? null)) {
    $maxFiles = 5;
    $maxSize  = 5 * 1024 * 1024;
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    $count = min(count($_FILES['images']['tmp_name']), $maxFiles);

    for ($i = 0; $i < $count; $i++) {
        $tmpName = $_FILES['images']['tmp_name'][$i] ?? '';
        $size    = (int)($_FILES['images']['size'][$i] ?? 0);
        $error   = (int)($_FILES['images']['error'][$i] ?? 4);

        if ($error !== UPLOAD_ERR_OK || $size <= 0 || $size > $maxSize) continue;
        if (!is_uploaded_file($tmpName)) continue;

        $mime = function_exists('mime_content_type')
            ? mime_content_type($tmpName)
            : ($_FILES['images']['type'][$i] ?? '');

        if (!in_array($mime, $allowed, true)) continue;

        $ext = 'jpg';
        if ($mime === 'image/png')  $ext = 'png';
        if ($mime === 'image/webp') $ext = 'webp';
        if ($mime === 'image/gif')  $ext = 'gif';

        $filename = 'rv_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (move_uploaded_file($tmpName, $destPath)) {
            $uploadedImages[] = 'uploads/reviews/' . $filename;
        }
    }
}

// 4. Insert
try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO reviews (product_id, user_id, order_id, rating, content, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $insert->execute([$productId, $userId, $orderId, $rating, $content ?: null]);
    $reviewId = (int)$pdo->lastInsertId();

    if (!empty($uploadedImages)) {
        $imgStmt = $pdo->prepare("INSERT INTO review_images (review_id, image_url) VALUES (?, ?)");
        foreach ($uploadedImages as $url) {
            $imgStmt->execute([$reviewId, $url]);
        }
    }

    $pdo->commit();

    // ============================================================
    // ✅ GỬI THÔNG BÁO CHO ADMIN
    // ============================================================
    // Lấy tên sản phẩm để thông báo sinh động hơn
    $stmtP = $pdo->prepare("SELECT name FROM products WHERE id = ?");
    $stmtP->execute([$productId]);
    $productName = $stmtP->fetchColumn() ?: 'Sản phẩm';

    // Đánh giá <= 2 sao sẽ ưu tiên cao hơn
    $notifType = ($rating <= 2) ? 'review_low' : 'review_new';
    $notifPriority = ($rating <= 2) ? 'high' : 'normal';
    $notifTitle = ($rating <= 2) ? 'Đánh giá thấp' : 'Đánh giá mới';

    addNotification(
        $notifType,
        $notifTitle,
        'Khách hàng vừa đánh giá ' . $rating . ' sao cho sản phẩm "' . mb_substr($productName, 0, 30) . '".',
        'reviews.php#rv-' . $reviewId, // Link tự động cuộn và mở đánh giá
        $notifPriority,
        'ph-star'
    );
    // ============================================================

    // ✅ Xóa buffer trước khi gửi JSON cuối
    while (ob_get_level() > 0) ob_end_clean();

    echo json_encode([
        'ok'  => true,
        'msg' => '✅ Cảm ơn bạn đã đánh giá sản phẩm!',
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Submit review error: ' . $e->getMessage());

    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode([
        'ok'  => false,
        'msg' => 'Lỗi hệ thống: ' . $e->getMessage(),
    ]);
    exit;
}