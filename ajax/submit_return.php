<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit(array $data) {
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}

$userId = $_SESSION['user_id'];

/* ---------- CSRF ---------- */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.']);
}

/* ---------- Inputs ---------- */
$orderId     = (int)($_POST['order_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$reasonOther = trim($_POST['reason_detail'] ?? '');
$method      = $_POST['refund_method'] ?? 'wallet';
$bankId      = (int)($_POST['refund_bank_id'] ?? 0);
$pin         = trim($_POST['pin'] ?? '');
$itemIds     = $_POST['items'] ?? [];
$qtys        = $_POST['qty'] ?? [];

if ($orderId <= 0)   jsonExit(['ok' => false, 'msg' => 'Đơn hàng không hợp lệ.']);
if ($reason === '')  jsonExit(['ok' => false, 'msg' => 'Vui lòng chọn lý do trả hàng.']);
if ($reason === 'Khác' && $reasonOther === '') {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập lý do cụ thể.']);
}
if (empty($itemIds) || !is_array($itemIds)) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng chọn ít nhất 1 sản phẩm muốn trả.']);
}
// === Yêu cầu tối thiểu 1 ảnh ===
if (empty($_FILES['images']['name'][0])) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng tải lên ít nhất 1 ảnh sản phẩm thực tế.']);
}
if (!in_array($method, ['wallet', 'bank'], true)) {
    $method = 'wallet';
}
if ($pin === '' || !preg_match('/^\d{6}$/', $pin)) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng nhập mã PIN 6 số.']);
}

/* ---------- Order check ---------- */
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    jsonExit(['ok' => false, 'msg' => 'Đơn hàng không tồn tại.']);
}
if ($order['status'] !== 'delivered') {
    jsonExit(['ok' => false, 'msg' => 'Chỉ có thể trả hàng với đơn đã giao thành công.']);
}

$deliveredTime = strtotime($order['delivered_at'] ?? $order['updated_at'] ?? $order['created_at']);
if (time() - $deliveredTime > 7 * 86400) {
    jsonExit(['ok' => false, 'msg' => 'Đã quá thời hạn trả hàng (7 ngày).']);
}

$stmt = $pdo->prepare("
    SELECT id FROM return_requests
    WHERE order_id = ? AND status IN ('pending','approved','refunding')
");
$stmt->execute([$orderId]);
if ($stmt->fetch()) {
    jsonExit(['ok' => false, 'msg' => 'Đơn hàng đã có yêu cầu trả hàng đang xử lý.']);
}

/* ============================================================
   🔐 XÁC NHẬN MÃ PIN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT payment_pin, pin_attempts, pin_locked_until
    FROM users WHERE id = ?
");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy người dùng.']);
}

if (empty($u['payment_pin'])) {
    jsonExit([
        'ok'  => false,
        'msg' => 'Bạn chưa thiết lập mã PIN. Vui lòng thiết lập trong ví Atelier Pay trước.',
        'need_setup_pin' => true,
    ]);
}

if (!empty($u['pin_locked_until']) && strtotime($u['pin_locked_until']) > time()) {
    $remainSec = strtotime($u['pin_locked_until']) - time();
    $remainMin = max(1, (int)ceil($remainSec / 60));
    jsonExit(['ok' => false, 'msg' => "Mã PIN đang bị khóa. Vui lòng thử lại sau {$remainMin} phút."]);
}

if (!password_verify($pin, $u['payment_pin'])) {
    $attempts = (int)$u['pin_attempts'] + 1;

    if ($attempts >= 5) {
        $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        $upd = $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = ? WHERE id = ?");
        $upd->execute([$lockUntil, $userId]);
        jsonExit(['ok' => false, 'msg' => '❌ Sai mã PIN 5 lần. Vui lòng thử lại sau 15 phút.']);
    }

    $upd = $pdo->prepare("UPDATE users SET pin_attempts = ? WHERE id = ?");
    $upd->execute([$attempts, $userId]);

    $left = 5 - $attempts;
    jsonExit(['ok' => false, 'msg' => "❌ Mã PIN không đúng. Còn {$left} lần thử."]);
}

// PIN đúng → reset
$pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")
    ->execute([$userId]);

/* ---------- Bank check ---------- */
if ($method === 'bank') {
    if ($bankId <= 0) {
        jsonExit(['ok' => false, 'msg' => 'Vui lòng chọn ngân hàng nhận hoàn tiền.']);
    }
    $stmt = $pdo->prepare("SELECT id FROM linked_banks WHERE id = ? AND user_id = ?");
    $stmt->execute([$bankId, $userId]);
    if (!$stmt->fetch()) {
        jsonExit(['ok' => false, 'msg' => 'Ngân hàng không hợp lệ.']);
    }
}

/* ---------- Order items ---------- */
$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$stmt = $pdo->prepare("
    SELECT oi.*, p.name AS product_name
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ? AND oi.id IN ($placeholders)
");
$stmt->execute(array_merge([$orderId], $itemIds));
$validItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($validItems)) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy sản phẩm hợp lệ để trả.']);
}

/* ---------- Upload images ---------- */
$imageUrls = [];
$uploadDir = __DIR__ . '/../uploads/returns/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// ✅ Bắt buộc có ít nhất 1 ảnh
if (empty($_FILES['images']['name'][0])) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng tải lên ít nhất 1 ảnh sản phẩm thực tế.']);
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$count   = 0;
$filesN  = count($_FILES['images']['name']);

// ✅ Vòng for mới — báo lỗi rõ ràng từng ảnh thay vì bỏ qua âm thầm
for ($i = 0; $i < $filesN && $count < 5; $i++) {
    if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
        jsonExit(['ok' => false, 'msg' => 'Ảnh thứ ' . ($i + 1) . ' bị lỗi upload. Vui lòng thử lại.']);
    }
    if ($_FILES['images']['size'][$i] > 5 * 1024 * 1024) {
        jsonExit(['ok' => false, 'msg' => 'Ảnh thứ ' . ($i + 1) . ' vượt quá 5MB.']);
    }

    $tmp = $_FILES['images']['tmp_name'][$i];
    
    // 🟢 FIX: Dùng getimagesize thay cho mime_content_type để tránh lỗi thiếu extension fileinfo
    $imgInfo = @getimagesize($tmp);
    $mime = $imgInfo ? $imgInfo['mime'] : '';

    if (!isset($allowed[$mime])) {
        jsonExit(['ok' => false, 'msg' => 'Ảnh thứ ' . ($i + 1) . ' không đúng định dạng (JPG/PNG/WEBP/GIF).']);
    }

    $ext  = $allowed[$mime];
    $name = 'return_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($tmp, $uploadDir . $name)) {
        $imageUrls[] = 'uploads/returns/' . $name;
        $count++;
    }
}

// ✅ Đảm bảo có ít nhất 1 ảnh upload thành công
if (empty($imageUrls)) {
    jsonExit(['ok' => false, 'msg' => 'Không thể lưu ảnh. Vui lòng thử lại.']);
}

/* ---------- Transaction ---------- */
try {
    $pdo->beginTransaction();

    $totalRefund = 0;
    $insertItems = [];

    foreach ($validItems as $it) {
        $maxQty = (int)$it['quantity'];
        $reqQty = (int)($qtys[$it['id']] ?? 1);
        $qty    = max(1, min($maxQty, $reqQty));

        $lineTotal    = $qty * (float)$it['price'];
        $totalRefund += $lineTotal;

        $insertItems[] = [
            'order_item_id' => (int)$it['id'],
            'product_id'    => (int)$it['product_id'],
            'product_name'  => $it['product_name'] ?? '',
            'size'          => $it['size'] ?? '',
            'quantity'      => $qty,
            'price'         => (float)$it['price'],
        ];
    }

    if ($totalRefund <= 0) {
        $pdo->rollBack();
        jsonExit(['ok' => false, 'msg' => 'Số tiền hoàn không hợp lệ.']);
    }

    // Insert return_requests
    $stmt = $pdo->prepare("
        INSERT INTO return_requests
        (order_id, user_id, reason, reason_detail, refund_amount, refund_method,
         refund_bank_id, status, images, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        $orderId,
        $userId,
        $reason,
        $reasonOther !== '' ? $reasonOther : null,
        $totalRefund,
        $method,
        $method === 'bank' ? $bankId : null,
        !empty($imageUrls) ? json_encode($imageUrls, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $returnId = (int)$pdo->lastInsertId();

    /* ============================================================
       🎫 SINH MÃ ĐƠN HOÀN: RT + YYYYMMDD + '-' + 4 số
       ============================================================ */
    $returnCode = 'RT' . date('Ymd') . '-' . str_pad((string)$returnId, 4, '0', STR_PAD_LEFT);

    $pdo->prepare("UPDATE return_requests SET return_code = ? WHERE id = ?")
        ->execute([$returnCode, $returnId]);

    // Insert return_items
    $stmtItem = $pdo->prepare("
        INSERT INTO return_items
        (return_id, order_item_id, product_id, product_name, size, quantity, price)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($insertItems as $r) {
        $stmtItem->execute([
            $returnId,
            $r['order_item_id'],
            $r['product_id'],
            $r['product_name'],
            $r['size'],
            $r['quantity'],
            $r['price'],
        ]);
    }

    $pdo->commit();

} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonExit(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $ex->getMessage()]);
}

/* ---------- Mail ---------- */
try {
    require_once __DIR__ . '/../includes/mailer.php';
    $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $uinfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($uinfo && !empty($uinfo['email'])) {
        $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;">
            <h2 style="color:#a8461f;">🔄 Yêu cầu trả hàng đã được ghi nhận</h2>
            <p>Xin chào <b>' . htmlspecialchars($uinfo['full_name']) . '</b>,</p>
            <p>Chúng tôi đã nhận được yêu cầu trả hàng của bạn.</p>
            <table style="border-collapse:collapse;margin:12px 0;">
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Mã đơn hoàn:</td>
                    <td><b style="font-family:monospace;color:#a8461f;font-size:15px;">' . $returnCode . '</b></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Đơn gốc:</td>
                    <td><b>#' . $orderId . '</b></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Số tiền:</td>
                    <td><b>' . number_format($totalRefund, 0, ',', '.') . 'đ</b></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#6b7280;">Lý do:</td>
                    <td>' . htmlspecialchars($reason) . '</td></tr>
            </table>
            <p>Vui lòng chờ admin xét duyệt. Chúng tôi sẽ thông báo qua email khi có kết quả.</p>
            <p style="color:#6b7280;font-size:13px;">— THE ATELIER</p>
        </div>';
        sendMail($uinfo['email'], $uinfo['full_name'], 'Yêu cầu trả hàng ' . $returnCode . ' — THE ATELIER', $body);
    }
} catch (Exception $e) {
    // Bỏ qua lỗi mail
}

jsonExit([
    'ok'          => true,
    'msg'         => '✅ Đã gửi yêu cầu trả hàng ' . $returnCode . '. Admin sẽ xét duyệt trong 24h.',
    'return_id'   => $returnId,
    'return_code' => $returnCode,
]);