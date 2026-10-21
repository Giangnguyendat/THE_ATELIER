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

    $topupId = (int)($input['request_id'] ?? ($input['topup_id'] ?? 0));
    $otpIn   = trim($input['otp_code'] ?? '');
    $pinIn   = trim($input['pin'] ?? '');

    if ($topupId <= 0 || !preg_match('/^\d{6}$/', $otpIn) || !preg_match('/^\d{6}$/', $pinIn)) {
        echo json_encode(['ok' => false, 'error' => 'Dữ liệu không hợp lệ']); exit;
    }

    // Lấy topup request
    $stmt = $pdo->prepare("
        SELECT tr.*, lb.bank_name, lb.account_no
        FROM topup_requests tr
        LEFT JOIN linked_banks lb ON lb.id = tr.linked_bank_id
        WHERE tr.id = ? AND tr.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$topupId, $userId]);
    $req = $stmt->fetch();

    if (!$req) {
        echo json_encode(['ok' => false, 'error' => 'Yêu cầu không tồn tại']); exit;
    }
    if ($req['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'Yêu cầu đã được xử lý']); exit;
    }
    if (strtotime($req['otp_expires_at']) < time()) {
        $pdo->prepare("UPDATE topup_requests SET status = 'expired' WHERE id = ?")->execute([$topupId]);
        echo json_encode(['ok' => false, 'error' => 'OTP đã hết hạn. Vui lòng gửi lại']); exit;
    }
    if ((int)$req['otp_attempts'] >= 5) {
        $pdo->prepare("UPDATE topup_requests SET status = 'failed' WHERE id = ?")->execute([$topupId]);
        echo json_encode(['ok' => false, 'error' => 'Đã nhập sai quá nhiều lần. Vui lòng tạo yêu cầu mới']); exit;
    }

    // Verify OTP
    if (!hash_equals($req['otp_code'], $otpIn)) {
        $pdo->prepare("UPDATE topup_requests SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$topupId]);
        $remain = 5 - ((int)$req['otp_attempts'] + 1);
        echo json_encode([
            'ok' => false,
            'error' => "OTP không đúng. Còn {$remain} lần thử",
            'clear_otp' => true,
        ]); exit;
    }

    // Verify PIN
    $stmt = $pdo->prepare("SELECT payment_pin, pin_attempts, pin_locked_until, email, full_name, atelier_pay_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
        $remain = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
        echo json_encode(['ok' => false, 'error' => "PIN đang bị khóa. Thử lại sau {$remain} phút"]); exit;
    }

    if (!password_verify($pinIn, $user['payment_pin'])) {
        $attempts = (int)$user['pin_attempts'] + 1;
        $lockUntil = null;
        if ($attempts >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        }
        $pdo->prepare("UPDATE users SET pin_attempts = ?, pin_locked_until = ? WHERE id = ?")
            ->execute([$attempts, $lockUntil, $userId]);

        if ($lockUntil) {
            echo json_encode(['ok' => false, 'error' => 'Sai PIN 5 lần. PIN bị khóa 15 phút', 'clear_pin' => true]); exit;
        }
        $remain = 5 - $attempts;
        echo json_encode(['ok' => false, 'error' => "PIN không đúng. Còn {$remain} lần thử", 'clear_pin' => true]); exit;
    }

    // ============ THÀNH CÔNG — CỘNG TIỀN ============
    $pdo->beginTransaction();

    try {
        // Lock user row
        $stmt = $pdo->prepare("SELECT atelier_pay_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $before = (float)$stmt->fetchColumn();
        $after  = $before + (float)$req['amount'];

        // Update balance
        $pdo->prepare("UPDATE users SET atelier_pay_balance = ?, pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")
            ->execute([$after, $userId]);

        // Insert transaction
        $desc = 'Nạp từ ' . ($req['bank_name'] ?? 'bank') . ' **** ' . substr($req['account_no'] ?? '0000', -4);
        $pdo->prepare("
            INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, status, description, linked_bank_id, created_at)
            VALUES (?, 'topup', ?, ?, ?, 'completed', ?, ?, NOW())
        ")->execute([$userId, $req['amount'], $before, $after, $desc, $req['linked_bank_id']]);

        // Update topup request
        $pdo->prepare("UPDATE topup_requests SET status = 'completed', completed_at = NOW() WHERE id = ?")
            ->execute([$topupId]);

        $pdo->commit();

        ob_end_clean();
        echo json_encode([
            'ok'            => true,
            'new_balance'   => $after,
            'amount'        => (float)$req['amount'],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[wallet_topup_confirm] tx error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Lỗi xử lý giao dịch']);
    }

} catch (Throwable $e) {
    error_log('[wallet_topup_confirm] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}