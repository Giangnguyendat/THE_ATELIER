<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit(array $data) {
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Auth check ---------- */
if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}

// Check admin role
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'], true)) {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền truy cập.']);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonExit(['ok' => false, 'msg' => 'ID không hợp lệ.']);
}

/* ---------- Get return ---------- */
$stmt = $pdo->prepare("
    SELECT r.*,
           u.full_name AS user_name,
           u.email     AS user_email,
           u.phone     AS user_phone,
           u.atelier_pay_balance,
           lb.bank_name     AS refund_bank_name,
           lb.account_no    AS refund_bank_account,
           lb.account_name  AS refund_bank_holder
    FROM return_requests r
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN linked_banks lb ON lb.id = r.refund_bank_id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    jsonExit(['ok' => false, 'msg' => 'Không tìm thấy yêu cầu trả hàng.']);
}

/* Format */
$r['created_at_fmt'] = date('d/m/Y H:i', strtotime($r['created_at']));
$r['refund_method_label'] = ($r['refund_method'] === 'bank') ? '🏦 Chuyển khoản' : '💰 Ví Atelier Pay';
$r['wallet_balance'] = $r['refund_method'] === 'wallet' ? (float)$r['atelier_pay_balance'] : null;

/* ---------- Get items ---------- */
$stmt = $pdo->prepare("
    SELECT * FROM return_items WHERE return_id = ? ORDER BY id
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Images ---------- */
$images = [];
if (!empty($r['images'])) {
    $decoded = json_decode($r['images'], true);
    if (is_array($decoded)) $images = $decoded;
}

jsonExit([
    'ok'     => true,
    'return' => $r,
    'items'  => $items,
    'images' => $images,
]);