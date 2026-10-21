<?php
require_once __DIR__ . '/../includes/config.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập lại.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Phương thức không hợp lệ.']);
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Phiên làm việc hết hạn, vui lòng tải lại trang.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$fullName = trim($_POST['full_name'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$address  = trim($_POST['address'] ?? '');

// Validate
if ($fullName === '' || mb_strlen($fullName) > 100) {
    echo json_encode(['ok' => false, 'msg' => 'Họ tên không hợp lệ (1-100 ký tự).']);
    exit;
}

if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{8,20}$/', $phone)) {
    echo json_encode(['ok' => false, 'msg' => 'Số điện thoại không hợp lệ.']);
    exit;
}

if ($address !== '' && mb_strlen($address) > 500) {
    echo json_encode(['ok' => false, 'msg' => 'Địa chỉ quá dài (tối đa 500 ký tự).']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE users
        SET full_name = ?, phone = ?, address = ?
        WHERE id = ?
    ");
    $stmt->execute([$fullName, $phone ?: null, $address ?: null, $userId]);

    // Update session
    $_SESSION['user']['full_name'] = $fullName;
    $_SESSION['user']['phone']     = $phone;
    $_SESSION['user']['address']   = $address;
    $_SESSION['full_name']         = $fullName;

    echo json_encode([
        'ok'  => true,
        'msg' => '✅ Cập nhật thông tin thành công!',
        'data' => [
            'full_name' => $fullName,
            'phone'     => $phone,
            'address'   => $address,
        ],
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    exit;
}