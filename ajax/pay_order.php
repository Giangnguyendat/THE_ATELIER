<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

while (ob_get_level() > 0) ob_end_clean();
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Method không hợp lệ']); exit;
    }
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Chưa đăng nhập']); exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf   = $input['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Phiên hết hạn']); exit;
    }

    $orderId = (int)($input['order_id'] ?? 0);
    $method  = $input['method'] ?? 'bank';

    if ($orderId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng không hợp lệ']); exit;
    }
    if (!in_array($method, ['bank', 'wallet'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Phương thức không hỗ trợ']); exit;
    }

    // Lấy đơn
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'Không tìm thấy đơn hàng']); exit;
    }
    if (in_array($order['payment_status'], ['paid', 'refund_pending'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng đã được thanh toán']); exit;
    }
    if ($order['status'] === 'cancelled') {
        echo json_encode(['ok' => false, 'error' => 'Đơn hàng đã hủy']); exit;
    }
    if ($order['payment_method'] !== 'bank') {
        echo json_encode(['ok' => false, 'error' => 'Chỉ hỗ trợ thanh toán đơn chuyển khoản']); exit;
    }

    $total = (float)$order['total_amount'];

    /* ============ BANK: đánh dấu pending ============ */
    if ($method === 'bank') {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET payment_status = 'pending',
                payment_note   = CONCAT(COALESCE(payment_note, ''), ' | User xác nhận CK lúc ', NOW()),
                updated_at     = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);

        // Gửi mail thông báo admin (tùy chọn)
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $adminEmail = $pdo->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            if ($adminEmail && function_exists('sendMail')) {
                $subject = "Đơn #$orderId xác nhận chuyển khoản — THE ATELIER";
                $body = "<p>Khách hàng <b>" . htmlspecialchars($order['full_name']) . "</b> xác nhận đã chuyển khoản cho đơn <b>#$orderId</b> với số tiền <b>" . number_format($total, 0, ',', '.') . "đ</b>.</p>";
                @sendMail($adminEmail, 'Admin', $subject, $body);
            }
        } catch (Throwable $e) { /* ignore */ }

        ob_end_clean();
        echo json_encode([
            'ok'  => true,
            'msg' => 'Đã xác nhận chuyển khoản. Admin sẽ kiểm tra và cập nhật.',
        ]);
        exit;
    }

    /* ============ WALLET: trừ ví ngay ============ */
    if ($method === 'wallet') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $balanceBefore = (float)$stmt->fetchColumn();

            if ($balanceBefore < $total) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Số dư ví không đủ']); exit;
            }

            $balanceAfter = $balanceBefore - $total;

            $pdo->prepare("UPDATE users SET atelier_pay_balance = ? WHERE id = ?")
                ->execute([$balanceAfter, $userId]);

            $pdo->prepare("
                UPDATE orders
                SET payment_status = 'paid',
                    payment_method = 'wallet',
                    paid_at        = NOW(),
                    status         = CASE WHEN status = 'pending' THEN 'confirmed' ELSE status END,
                    updated_at     = NOW()
                WHERE id = ?
            ")->execute([$orderId]);

            $pdo->prepare("
                INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, status, description, order_id, created_at)
                VALUES (?, 'payment', ?, ?, ?, 'completed', ?, ?, NOW())
            ")->execute([
                $userId,
                $total,
                $balanceBefore,
                $balanceAfter,
                'Thanh toán đơn #' . $orderId,
                $orderId,
            ]);

            $pdo->commit();

            // Mail xác nhận
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $userStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $u = $userStmt->fetch();
                if ($u && function_exists('sendMail')) {
                    $subject = "Thanh toán đơn #$orderId thành công — THE ATELIER";
                    $body = "<p>Xin chào <b>" . htmlspecialchars($u['full_name']) . "</b>,</p>
                             <p>Đơn hàng <b>#$orderId</b> đã được thanh toán thành công bằng ví Atelier Pay.</p>
                             <p>Số tiền: <b>" . number_format($total, 0, ',', '.') . "đ</b></p>
                             <p>Số dư còn lại: <b>" . number_format($balanceAfter, 0, ',', '.') . "đ</b></p>";
                    @sendMail($u['email'], $u['full_name'], $subject, $body);
                }
            } catch (Throwable $e) { /* ignore */ }

            ob_end_clean();
            echo json_encode([
                'ok'  => true,
                'msg' => 'Thanh toán bằng ví thành công!',
            ]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[pay_order] wallet error: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Lỗi xử lý thanh toán']);
        }
        exit;
    }

} catch (Throwable $e) {
    error_log('[pay_order] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}