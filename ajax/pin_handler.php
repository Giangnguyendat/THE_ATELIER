<?php
/**
 * Xử lý mã PIN 6 số cho thanh toán COD
 * - action=status  : kiểm tra user đã có PIN chưa
 * - action=set     : tạo PIN lần đầu (yêu cầu xác nhận 2 lần)
 * - action=verify  : xác minh PIN khi thanh toán
 */
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
    exit;
}

$action = $_POST['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

/* Helper: lấy user hiện tại */
function getCurrentUser(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT id, payment_pin, pin_attempts, pin_locked_until FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ============ STATUS: có PIN chưa? ============ */
if ($action === 'status') {
    $user = getCurrentUser($pdo, $userId);
    if (!$user) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy tài khoản.']);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'has_pin' => !empty($user['payment_pin']),
    ]);
    exit;
}

/* ============ SET: tạo PIN lần đầu ============ */
if ($action === 'set') {
    $pin     = trim($_POST['pin'] ?? '');
    $pinConf = trim($_POST['pin_confirm'] ?? '');

    if (!preg_match('/^\d{6}$/', $pin)) {
        echo json_encode(['ok' => false, 'msg' => 'PIN phải là 6 chữ số.']);
        exit;
    }
    if ($pin !== $pinConf) {
        echo json_encode(['ok' => false, 'msg' => 'Xác nhận PIN không khớp.']);
        exit;
    }

    // Bảo mật: chặn PIN quá đơn giản
    $weakPins = ['000000', '111111', '222222', '333333', '444444',
                 '555555', '666666', '777777', '888888', '999999',
                 '123456', '654321', '012345', '543210'];
    if (in_array($pin, $weakPins, true)) {
        echo json_encode(['ok' => false, 'msg' => 'PIN quá đơn giản. Vui lòng chọn mã khác.']);
        exit;
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        UPDATE users 
        SET payment_pin = ?, pin_attempts = 0, pin_locked_until = NULL
        WHERE id = ?
    ");
    $stmt->execute([$hash, $userId]);

    // Đánh dấu đã verify trong session (không cần nhập lại khi vừa tạo)
    $_SESSION['pin_verified'] = true;

    echo json_encode([
        'ok'  => true,
        'msg' => 'Đã thiết lập mã PIN thành công!',
    ]);
    exit;
}

/* ============ VERIFY: xác minh PIN ============ */
if ($action === 'verify') {
    $pin = trim($_POST['pin'] ?? '');

    if (!preg_match('/^\d{6}$/', $pin)) {
        echo json_encode(['ok' => false, 'msg' => 'PIN phải là 6 chữ số.']);
        exit;
    }

    $user = getCurrentUser($pdo, $userId);
    if (!$user || empty($user['payment_pin'])) {
        echo json_encode(['ok' => false, 'msg' => 'Bạn chưa thiết lập mã PIN.']);
        exit;
    }

    // Kiểm tra lock
    if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
        $remain = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
        echo json_encode([
            'ok'  => false,
            'msg' => "PIN đã bị khóa tạm thời. Vui lòng thử lại sau {$remain} phút.",
        ]);
        exit;
    }

    // So khớp
    if (!password_verify($pin, $user['payment_pin'])) {
        $attempts = (int)$user['pin_attempts'] + 1;
        $left = 5 - $attempts;

        if ($attempts >= 5) {
            // Khóa 15 phút
            $pdo->prepare("
                UPDATE users 
                SET pin_attempts = 0, pin_locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                WHERE id = ?
            ")->execute([$userId]);

            echo json_encode([
                'ok'  => false,
                'msg' => 'Bạn đã nhập sai quá 5 lần. PIN bị khóa 15 phút.',
            ]);
            exit;
        }

        $pdo->prepare("UPDATE users SET pin_attempts = ? WHERE id = ?")
            ->execute([$attempts, $userId]);

        echo json_encode([
            'ok'  => false,
            'msg' => "PIN không đúng. Còn {$left} lần thử.",
        ]);
        exit;
    }

    // ✅ Đúng → reset attempts + set session
    $pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE id = ?")
        ->execute([$userId]);

    // ✅ Hỗ trợ context — checkout (mặc định) hoặc settings
$context = $_POST['context'] ?? 'checkout';
$sessionKey = ($context === 'settings') ? 'settings_pin_verified' : 'pin_verified';
$_SESSION[$sessionKey] = true;

    echo json_encode(['ok' => true, 'msg' => 'Xác minh PIN thành công.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ.']);