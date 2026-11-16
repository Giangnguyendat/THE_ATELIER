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

    if ($action === 'cancel_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason  = trim($_POST['cancel_reason'] ?? '');

        if ($orderId <= 0 || empty($reason)) {
            echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ.']);
            exit;
        }

        // Lấy thông tin đơn hàng kèm trạng thái thanh toán
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $_SESSION['user_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['ok' => false, 'msg' => 'Đơn hàng không tồn tại.']);
            exit;
        }

        if ($order['status'] === 'cancelled') {
            echo json_encode(['ok' => false, 'msg' => 'Đơn hàng này đã được hủy trước đó.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Cập nhật trạng thái đơn hàng thành Đã hủy
            $newPayStatus = $order['payment_status'];
            
            // Nếu đơn đã thanh toán -> Chuyển sang chờ hoàn tiền (nếu thanh toán qua Bank/Card)
            // Nếu thanh toán qua Ví -> Sẽ hoàn tiền ngay bên dưới
            if ($order['payment_status'] === 'paid') {
                if (in_array($order['payment_method'], ['bank', 'card'])) {
                    $newPayStatus = 'refund_pending';
                }
            }

            $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = ?, cancel_reason = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newPayStatus, $reason, $orderId]);

            // 2. XỬ LÝ HOÀN TIỀN VÀO VÍ (Nếu đơn đã thanh toán bằng Ví)
            if ($order['payment_status'] === 'paid' && $order['payment_method'] === 'wallet') {
                $refundAmount = (float)$order['total_amount'];
                $userId = (int)$order['user_id'];

                // Lấy số dư ví hiện tại
                $stmtUser = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ? FOR UPDATE");
                $stmtUser->execute([$userId]);
                $currentBalance = (float)$stmtUser->fetchColumn();

                $newBalance = $currentBalance + $refundAmount;

                // Cập nhật số dư user
                $stmtUpdateUser = $pdo->prepare("UPDATE users SET atelier_pay_balance = ? WHERE id = ?");
                $stmtUpdateUser->execute([$newBalance, $userId]);

                // Ghi log giao dịch ví (Refund)
                $stmtLog = $pdo->prepare("
                    INSERT INTO wallet_transactions 
                    (user_id, type, amount, balance_after, status, description, created_at) 
                    VALUES (?, 'refund', ?, ?, 'completed', ?, NOW())
                ");
                $description = 'Hoàn tiền hủy đơn hàng #' . $orderId;
                $stmtLog->execute([$userId, $refundAmount, $newBalance, $description]);
            }

            // 3. HOÀN LẠI SỐ LƯỢNG TỒN KHO VÀ TRỪ ĐI SỐ LƯỢNG ĐÃ BÁN
            $stmtItems = $pdo->prepare("SELECT product_id, size, quantity FROM order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $pid  = (int)$item['product_id'];
                $size = $item['size'];
                $qty  = (int)$item['quantity'];

                // 1. Cộng lại số lượng vào bảng product_sizes (Kho theo Size)
                $stmtSize = $pdo->prepare("UPDATE product_sizes SET stock = stock + ? WHERE product_id = ? AND size = ?");
                $stmtSize->execute([$qty, $pid, $size]);

                // 2. Cộng lại số lượng vào bảng products (Tổng kho) và trừ đi sold_count
                // Dùng GREATEST(0, ...) để đảm bảo sold_count không bị âm nếu có sai sót
                $stmtProd = $pdo->prepare("UPDATE products SET stock = stock + ?, sold_count = GREATEST(0, sold_count - ?) WHERE id = ?");
                $stmtProd->execute([$qty, $qty, $pid]);
            }

            $pdo->commit();

            // Trả về kết quả thành công cho Frontend
            echo json_encode(['ok' => true, 'msg' => 'Hủy đơn hàng thành công!']);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
            exit;
        }
    }

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ.']);
exit;