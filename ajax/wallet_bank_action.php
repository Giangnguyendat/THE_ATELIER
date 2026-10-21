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

    $banks  = require __DIR__ . '/../includes/banks.php';
    $action = $input['action'] ?? '';

    /* ============ ADD ============ */
    if ($action === 'add') {
        $bin   = trim($input['bank_bin'] ?? '');
        $acct  = trim($input['account_no'] ?? '');
        $name  = strtoupper(trim($input['account_name'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $cccd  = trim($input['cccd'] ?? '');

        if (!isset($banks[$bin])) {
            echo json_encode(['ok' => false, 'error' => 'Ngân hàng không hợp lệ']); exit;
        }
        if (!preg_match('/^\d{6,20}$/', $acct)) {
            echo json_encode(['ok' => false, 'error' => 'Số tài khoản phải là 6-20 chữ số']); exit;
        }
        if (!preg_match('/^0\d{9,10}$/', $phone)) {
            echo json_encode(['ok' => false, 'error' => 'SĐT không hợp lệ (bắt đầu bằng 0, 10-11 số)']); exit;
        }
        if (mb_strlen($name) < 3) {
            echo json_encode(['ok' => false, 'error' => 'Tên chủ tài khoản quá ngắn']); exit;
        }
        if (!preg_match('/^0\d{11}$/', $cccd)) {
            echo json_encode(['ok' => false, 'error' => 'CCCD phải là 12 chữ số, bắt đầu bằng 0']); exit;
        }

        // Check trùng
        $stmt = $pdo->prepare("SELECT id FROM linked_banks WHERE user_id = ? AND bank_bin = ? AND account_no = ?");
        $stmt->execute([$userId, $bin, $acct]);
        if ($stmt->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Ngân hàng này đã được liên kết']); exit;
        }

        // Check số lượng
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM linked_banks WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() >= 5) {
            echo json_encode(['ok' => false, 'error' => 'Tối đa 5 ngân hàng']); exit;
        }

        // Default nếu là bank đầu
        $isFirst = ((int)$stmt->fetchColumn() === 0) ? 1 : 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM linked_banks WHERE user_id = ?");
        $stmt->execute([$userId]);
        $isFirst = ((int)$stmt->fetchColumn() === 0) ? 1 : 0;

        $stmt = $pdo->prepare("
            INSERT INTO linked_banks (user_id, bank_bin, bank_name, account_no, account_name, phone, cccd, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $bin, $banks[$bin]['name'], $acct, $name, $phone, $cccd, $isFirst]);

        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    /* ============ DELETE ============ */
    /* ============ DELETE (yêu cầu PIN + gửi mail) ============ */
    if ($action === 'delete') {
        $bankId = (int)($input['bank_id'] ?? 0);
        $pin    = trim($input['pin'] ?? '');

        if ($bankId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID không hợp lệ']); exit;
        }
        if (!preg_match('/^\d{6}$/', $pin)) {
            echo json_encode(['ok' => false, 'error' => 'Vui lòng nhập mã PIN 6 số']); exit;
        }

        // Lấy bank thuộc user
        $stmt = $pdo->prepare("SELECT * FROM linked_banks WHERE id = ? AND user_id = ?");
        $stmt->execute([$bankId, $userId]);
        $bank = $stmt->fetch();
        if (!$bank) {
            echo json_encode(['ok' => false, 'error' => 'Ngân hàng không tồn tại']); exit;
        }

        // Lấy user + PIN info
        $stmt = $pdo->prepare("SELECT email, full_name, payment_pin, pin_attempts, pin_locked_until FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (empty($user['payment_pin'])) {
            echo json_encode(['ok' => false, 'error' => 'Bạn chưa thiết lập mã PIN']); exit;
        }
        if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
            $remain = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
            echo json_encode(['ok' => false, 'error' => "PIN đang bị khóa. Thử lại sau {$remain} phút"]); exit;
        }

        // Verify PIN
        if (!password_verify($pin, $user['payment_pin'])) {
            $attempts = (int)$user['pin_attempts'] + 1;
            $lockUntil = null;
            if ($attempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
            }
            $pdo->prepare("UPDATE users SET pin_attempts = ?, pin_locked_until = ? WHERE id = ?")
                ->execute([$attempts, $lockUntil, $userId]);

            if ($lockUntil) {
                echo json_encode(['ok' => false, 'error' => 'Sai PIN 5 lần. Khóa 15 phút', 'clear_pin' => true]); exit;
            }
            $remain = 5 - $attempts;
            echo json_encode(['ok' => false, 'error' => "PIN không đúng. Còn {$remain} lần thử", 'clear_pin' => true]); exit;
        }

        // Reset pin_attempts
        $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")->execute([$userId]);

        // Chặn nếu có giao dịch pending
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM topup_requests WHERE linked_bank_id = ? AND status = 'pending' AND otp_expires_at > NOW()");
        $stmt->execute([$bankId]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => 'Có yêu cầu nạp tiền đang chờ. Vui lòng hoàn tất hoặc đợi hết hạn']); exit;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdraw_requests WHERE linked_bank_id = ? AND status = 'pending' AND otp_expires_at > NOW()");
        $stmt->execute([$bankId]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => 'Có yêu cầu rút tiền đang chờ. Vui lòng hoàn tất hoặc đợi hết hạn']); exit;
        }

        // Xóa
        $pdo->prepare("DELETE FROM linked_banks WHERE id = ? AND user_id = ?")->execute([$bankId, $userId]);

        // Nếu xóa bank default → set bank còn lại làm default
        $stmt = $pdo->prepare("SELECT id FROM linked_banks WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$userId]);
        $next = $stmt->fetchColumn();
        if ($next) {
            $pdo->prepare("UPDATE linked_banks SET is_default = 1 WHERE id = ?")->execute([$next]);
        }

        // Gửi mail thông báo
        try {
            require_once __DIR__ . '/../includes/mailer.php';

            $safeName = htmlspecialchars($user['full_name'] ?: 'bạn', ENT_QUOTES, 'UTF-8');
            $safeBank = htmlspecialchars($bank['bank_name'], ENT_QUOTES, 'UTF-8');
            $acctMask = '•••• ' . substr($bank['account_no'], -4);
            $time     = date('d/m/Y H:i');

            $subject = "Đã hủy liên kết ngân hàng — THE ATELIER";
            $body = "
            <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:28px;background:#f2ece0;border:1px solid #1c1b19;border-radius:8px'>
              <h2 style='font-family:Georgia,serif;color:#1c1b19;margin:0 0 8px'>THE ATELIER</h2>
              <p style='color:#8a8377;font-size:13px;margin:0 0 20px;letter-spacing:2px;text-transform:uppercase'>Hủy liên kết ngân hàng</p>

              <p>Xin chào <strong>{$safeName}</strong>,</p>
              <p>Chúng tôi xác nhận bạn đã <strong>hủy liên kết</strong> tài khoản ngân hàng sau khỏi ví Atelier Pay:</p>

              <div style='background:#fffdf9;border:1px solid #e5dfd2;border-radius:8px;padding:16px 20px;margin:16px 0'>
                <div style='font-size:1.1rem;color:#1c1b19;margin-bottom:6px'><strong>{$safeBank}</strong></div>
                <div style='font-family:monospace;color:#8a8377;font-size:.9rem'>{$acctMask}</div>
              </div>

              <p style='color:#8a8377;font-size:.85rem'>Thời gian: <strong>{$time}</strong></p>

              <p style='color:#8a8377;font-size:12px;border-top:1px solid #ddd;padding-top:14px;margin-top:22px'>
                Nếu bạn <strong>KHÔNG</strong> thực hiện hành động này, vui lòng liên hệ ngay hotline <strong>1900 123 456</strong> để được hỗ trợ.
              </p>
            </div>";

            if (function_exists('send_mail')) {
                send_mail($user['email'], $subject, $body);
            } elseif (function_exists('sendMail')) {
                sendMail($user['email'], $user['full_name'] ?: 'bạn', $subject, $body);
            }
        } catch (Throwable $e) {
            error_log('[wallet_bank_action] mail delete error: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    /* ============ SET DEFAULT ============ */
    if ($action === 'set_default') {
        $bankId = (int)($input['bank_id'] ?? 0);
        $pdo->prepare("UPDATE linked_banks SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE linked_banks SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$bankId, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action không hợp lệ']);

} catch (Throwable $e) {
    error_log('[wallet_bank_action] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}