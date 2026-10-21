<?php
require_once 'includes/config.php';
require_once 'includes/cart_storage.php';

// ✅ Lưu giỏ hiện tại vào DB (theo user_id) trước khi logout
if (isset($pdo)) {
    saveCartToDb($pdo);
}

// Xóa session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: index.php');
exit;