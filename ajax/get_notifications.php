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

$limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));

$stmt = $pdo->prepare("
    SELECT id, type, title, message, link, icon, priority, is_read, created_at
    FROM admin_notifications
    ORDER BY is_read ASC, created_at DESC
    LIMIT ?
");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $ts = strtotime($r['created_at']);
    $diff = time() - $ts;
    if ($diff < 60)         $r['time_ago'] = 'Vừa xong';
    elseif ($diff < 3600)   $r['time_ago'] = floor($diff / 60) . ' phút trước';
    elseif ($diff < 86400)  $r['time_ago'] = floor($diff / 3600) . ' giờ trước';
    elseif ($diff < 604800) $r['time_ago'] = floor($diff / 86400) . ' ngày trước';
    else                    $r['time_ago'] = date('d/m/Y', $ts);
}
unset($r);

echo json_encode([
    'ok'    => true,
    'items' => $rows,
    'unread'=> getUnreadNotificationCount(),
], JSON_UNESCAPED_UNICODE);