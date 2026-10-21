<?php
/**
 * Helper: Lưu/khôi phục giỏ hàng vào database
 * - User đã login → key = "user_{id}"
 * - Guest → key = "guest_{random_id}" (lưu trong cookie)
 */

/**
 * Lấy owner_key của session hiện tại
 */
function getCartOwnerKey(): string {
    if (!empty($_SESSION['user_id'])) {
        return 'user_' . (int)$_SESSION['user_id'];
    }

    // Guest — tạo ID ngẫu nhiên nếu chưa có
    if (empty($_COOKIE['guest_id'])) {
        $guestId = bin2hex(random_bytes(16));
        setcookie('guest_id', $guestId, [
            'expires'  => time() + 30 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['guest_id'] = $guestId;
    }

    return 'guest_' . preg_replace('/[^a-f0-9]/', '', $_COOKIE['guest_id']);
}

/**
 * Lưu giỏ hàng hiện tại vào DB
 */
function saveCartToDb(PDO $pdo): void {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return;
    }

    $key  = getCartOwnerKey();
    $json = json_encode($_SESSION['cart'], JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO cart_storage (owner_key, cart_data, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE cart_data = VALUES(cart_data), updated_at = NOW()
        ");
        $stmt->execute([$key, $json]);
    } catch (Exception $e) {
        // Silent — không ảnh hưởng user
        error_log('saveCartToDb error: ' . $e->getMessage());
    }
}

/**
 * Khôi phục giỏ hàng từ DB vào session
 * Chỉ khôi phục nếu session hiện CHƯA có cart
 */
function restoreCartFromDb(PDO $pdo): void {
    if (!empty($_SESSION['cart'])) return;   // Session đã có cart → không ghi đè

    $key = getCartOwnerKey();

    try {
        $stmt = $pdo->prepare("SELECT cart_data FROM cart_storage WHERE owner_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $json = $stmt->fetchColumn();

        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data) && !empty($data)) {
                $_SESSION['cart'] = $data;
            }
        }
    } catch (Exception $e) {
        error_log('restoreCartFromDb error: ' . $e->getMessage());
    }
}

/**
 * Xóa giỏ hàng của owner hiện tại khỏi DB
 */
function clearCartFromDb(PDO $pdo): void {
    $key = getCartOwnerKey();
    try {
        $pdo->prepare("DELETE FROM cart_storage WHERE owner_key = ?")->execute([$key]);
    } catch (Exception $e) {
        error_log('clearCartFromDb error: ' . $e->getMessage());
    }
}