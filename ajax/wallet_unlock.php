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

    // ✅ KHAI BÁO $input TRƯỚC KHI DÙNG
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $action = $input['action'] ?? 'unlock';
    $csrf   = $input['csrf_token'] ?? '';
    $pin    = trim($input['pin'] ?? '');

    // CSRF check cho cả 2 action
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Phiên hết hạn']); exit;
    }

    /* ============ ACTION: LOCK (ẩn số dư ngay) ============ */
    if ($action === 'lock') {
        unset($_SESSION['wallet_unlocked_until']);
        ob_end_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ============ ACTION: UNLOCK (nhập PIN để hiện) ============ */
    if (!preg_match('/^\d{6}$/', $pin)) {
        echo json_encode(['ok' => false, 'error' => 'PIN phải là 6 chữ số']); exit;
    }

    $stmt = $pdo->prepare("SELECT payment_pin, pin_attempts, pin_locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (empty($user['payment_pin'])) {
        echo json_encode(['ok' => false, 'error' => 'Bạn chưa thiết lập mã PIN']); exit;
    }
    if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
        $remain = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
        echo json_encode(['ok' => false, 'error' => "PIN đang bị khóa. Thử lại sau {$remain} phút"]); exit;
    }

    if (!password_verify($pin, $user['payment_pin'])) {
        $attempts = (int)$user['pin_attempts'] + 1;
        $lockUntil = null;
        if ($attempts >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        }
        $pdo->prepare("UPDATE users SET pin_attempts = ?, pin_locked_until = ? WHERE id = ?")
            ->execute([$attempts, $lockUntil, $userId]);

        if ($lockUntil) {
            echo json_encode(['ok' => false, 'error' => 'Sai PIN 5 lần. Khóa 15 phút']); exit;
        }
        $remain = 5 - $attempts;
        echo json_encode(['ok' => false, 'error' => "PIN không đúng. Còn {$remain} lần thử"]); exit;
    }

    // ĐÚNG → Mở khóa 15 phút
    $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")->execute([$userId]);
    $_SESSION['wallet_unlocked_until'] = time() + 900;

    ob_end_clean();
    echo json_encode(['ok' => true, 'unlocked_until' => $_SESSION['wallet_unlocked_until']]);

} catch (Throwable $e) {
    error_log('[wallet_unlock] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}