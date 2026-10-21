<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();

$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if (!isLoggedIn() || !in_array($role, ['admin', 'super_admin'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền.']);
    exit;
}

$id   = (int)($_POST['id'] ?? 0);
$all  = !empty($_POST['all']);

if ($all) {
    $pdo->query("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
    echo json_encode(['ok' => true, 'unread' => 0]);
    exit;
}

if ($id > 0) {
    $pdo->prepare("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?")
        ->execute([$id]);
    echo json_encode(['ok' => true, 'unread' => getUnreadNotificationCount()]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'ID không hợp lệ.']);