<?php
// Bật hiển thị lỗi để dễ debug (sẽ tắt khi đưa lên production)
ini_set('display_errors', 0); 
error_reporting(E_ALL);

require_once '../includes/config.php';
// Gọi file helper (đường dẫn từ thư mục ajax)
require_once __DIR__ . '/../admin/includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
    exit;
}

$action  = $_POST['action']   ?? '';
$orderId = (int)($_POST['order_id'] ?? 0);
$userId  = (int)$_SESSION['user_id'];

// VERIFY CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
    exit;
}

if ($orderId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Đơn hàng không hợp lệ.']);
    exit;
}

// LẤY ĐƠN HÀNG
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy đơn hàng.']);
    exit;
}

// ============================================================
// ACTION: RECEIVE ORDER (KHÁCH ĐÃ NHẬN HÀNG)
// ============================================================
if ($action === 'receive_order') {
    if ($order['status'] !== 'shipped') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ có thể xác nhận nhận hàng khi đơn đang giao.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("
            UPDATE orders
            SET status       = 'delivered',
                updated_at   = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $upd->execute([$orderId, $userId]);

        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Không thể cập nhật trạng thái đơn hàng.']);
            exit;
        }

        // Tự động chuyển COD thành Đã thanh toán
        if ($order['payment_method'] === 'cod' && $order['payment_status'] !== 'paid') {
            $pdo->prepare("
                UPDATE orders 
                SET payment_status = 'paid'
                WHERE id = ?
            ")->execute([$orderId]); 
        }

        $pdo->commit();

        // ============================================================
        // ✅ THÊM THÔNG BÁO KHI KHÁCH NHẬN HÀNG
        // ============================================================
        addNotification(
            'order_confirmed',
            'Khách đã nhận hàng',
            'Đơn hàng #' . $orderId . ' đã được khách xác nhận nhận hàng thành công.',
            'order-detail.php?id=' . $orderId,
            'high',
            'ph-check-circle'
        );
        // ============================================================

        echo json_encode([
            'ok'  => true,
            'msg' => '✅ Xác nhận nhận hàng thành công! Bạn có thể đánh giá sản phẩm ngay bây giờ.'
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Lỗi SQL: ' . $e->getMessage()]);
        exit;
    }
}

// KIỂM TRA TRẠNG THÁI CHUNG
$allowedStatuses = ['pending', 'confirmed'];
if (!in_array($order['status'], $allowedStatuses, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Đơn hàng đã được xử lý, không thể thay đổi.']);
    exit;
}

// ============================================================
// ACTION: UPDATE ORDER
// ============================================================
if ($action === 'update_order') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone    = trim($_POST['phone']     ?? '');
    $address  = trim($_POST['address']   ?? '');
    $note     = trim($_POST['note']      ?? '');

    if (empty($fullName) || empty($phone) || empty($address)) {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng điền đầy đủ thông tin bắt buộc.']);
        exit;
    }

    try {
        $upd = $pdo->prepare("
            UPDATE orders
            SET full_name = ?, phone = ?, address = ?, note = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')
        ");
        $upd->execute([$fullName, $phone, $address, $note, $orderId, $userId]);

        if ($upd->rowCount() > 0) {
            echo json_encode(['ok' => true, 'msg' => '✅ Cập nhật đơn hàng thành công!']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Không có thay đổi nào được lưu.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Lỗi SQL: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: CANCEL ORDER
// ============================================================
if ($action === 'cancel_order') {
    $reason = trim($_POST['cancel_reason'] ?? '');
    if ($reason === '') {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng chọn lý do hủy đơn.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("
            UPDATE orders
            SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), updated_at = NOW()
            WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed')
        ");
        $upd->execute([$reason, $orderId, $userId]);

        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Không thể hủy đơn hàng ở trạng thái này.']);
            exit;
        }

        // Hoàn kho
        $itemsStmt = $pdo->prepare("SELECT product_id, size, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $oi) {
            if (function_exists('increaseStock')) {
                increaseStock($pdo, (int)$oi['product_id'], $oi['size'], (int)$oi['quantity']);
            }
        }

        // Hoàn voucher
        if (file_exists(__DIR__ . '/../includes/voucher_helper.php')) {
            require_once __DIR__ . '/../includes/voucher_helper.php';
            if (function_exists('revertVoucherByOrder')) {
                revertVoucherByOrder($pdo, $orderId);
            }
        }

        $needRefund = in_array($order['payment_method'], ['bank', 'card'], true)
                   && in_array($order['payment_status'], ['paid', 'pending'], true);

        if ($needRefund) {
            $pdo->prepare("UPDATE orders SET payment_status = 'refund_pending' WHERE id = ?")->execute([$orderId]);
        }

        $pdo->commit();

        // ============================================================
        // ✅ THÊM THÔNG BÁO KHI KHÁCH HỦY ĐƠN
        // ============================================================
        addNotification(
            'order_cancelled',
            'Khách hủy đơn hàng',
            'Đơn hàng #' . $orderId . ' vừa bị khách hủy. Lý do: ' . mb_substr($reason, 0, 100),
            'order-detail.php?id=' . $orderId,
            'urgent',
            'ph-x-circle'
        );
        // ============================================================

        echo json_encode([
            'ok'  => true,
            'msg' => $needRefund ? '✅ Đã hủy đơn. Tiền sẽ được hoàn trong 12 giờ.' : '✅ Đã hủy đơn hàng thành công!'
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Lỗi SQL: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ.']);
exit;