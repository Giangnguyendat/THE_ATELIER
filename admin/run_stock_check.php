<?php
/**
 * ADMIN — Quét sản phẩm sắp hết hàng (thủ công)
 * Dùng khi không muốn setup Task Scheduler, hoặc test nhanh.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();
ob_start();

function jsonExit($d) {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Auth */
if (!isLoggedIn()) {
    jsonExit(['ok' => false, 'msg' => 'Vui lòng đăng nhập.']);
}
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    jsonExit(['ok' => false, 'msg' => 'Không có quyền.']);
}

/* CSRF */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    jsonExit(['ok' => false, 'msg' => 'Phiên hết hạn.']);
}

/* ============================================================
   CẤU HÌNH
   ============================================================ */
$THRESHOLD        = 5;  // < 5 → sắp hết
$URGENT_THRESHOLD = 3;  // < 3 → rất sắp hết
$SPAM_HOURS       = 12; // Đổi thành 12h cho dễ test (cron dùng 24h)

/* ============================================================
   QUERY
   ============================================================ */
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            COALESCE(SUM(ps.stock), 0) AS total_stock,
            GROUP_CONCAT(
                CONCAT(ps.size, ': ', ps.stock)
                ORDER BY FIELD(ps.size, 'S','M','L','XL','2XL')
                SEPARATOR ' · '
            ) AS size_breakdown
        FROM products p
        LEFT JOIN product_sizes ps ON ps.product_id = p.id
        GROUP BY p.id, p.name
        HAVING total_stock < ?
        ORDER BY total_stock ASC, p.id ASC
    ");
    $stmt->execute([$THRESHOLD]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    jsonExit(['ok' => false, 'msg' => 'Lỗi query: ' . $e->getMessage()]);
}

if (empty($products)) {
    jsonExit([
        'ok'      => true,
        'msg'     => '📭 Không có sản phẩm nào có tồn kho < ' . $THRESHOLD . '.',
        'created' => 0,
        'skipped' => 0,
    ]);
}

/* ============================================================
   HELPER: Đã notify trong N giờ qua chưa?
   ============================================================ */
function alreadyNotified(int $productId, int $hours): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM admin_notifications
            WHERE type = 'stock_low'
              AND ref_id = ?
              AND ref_type = 'product'
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$productId, $hours]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/* ============================================================
   TẠO NOTI
   ============================================================ */
$created = 0;
$skipped = 0;
$details = [];

foreach ($products as $p) {
    $productId = (int)$p['id'];
    $stock     = (int)$p['total_stock'];
    $name      = $p['name'];
    $sizeInfo  = $p['size_breakdown'] ?: 'Chưa có size';

    if (alreadyNotified($productId, $SPAM_HOURS)) {
        $skipped++;
        $details[] = "⏭️ [skip] {$name} (đã thông báo gần đây)";
        continue;
    }

    /* Mức độ */
    if ($stock === 0) {
        $priority  = 'urgent';
        $icon      = '🚨';
        $levelText = 'HẾT HÀNG';
    } elseif ($stock < $URGENT_THRESHOLD) {
        $priority  = 'urgent';
        $icon      = '⚠️';
        $levelText = 'RẤT SẮP HẾT';
    } else {
        $priority  = 'high';
        $icon      = '📦';
        $levelText = 'Sắp hết hàng';
    }

    $title = "{$icon} {$levelText}: {$name}";
    $msg   = "Tồn kho hiện tại: {$stock} sản phẩm\n"
           . "Chi tiết size: {$sizeInfo}\n\n"
           . "👉 Bấm để xem chi tiết & nhập thêm hàng.";

    $notifId = notifyAdmin('stock_low', $title, $msg, [
        'link'     => 'products.php?id=' . $productId,
        'icon'     => $icon,
        'priority' => $priority,
        'ref_id'   => $productId,
        'ref_type' => 'product',
    ]);

    if ($notifId > 0) {
        $created++;
        $details[] = "✓ [{$levelText}] {$name} — còn {$stock}";
    }
}

jsonExit([
    'ok'      => true,
    'msg'     => "✅ Đã quét xong. Tạo mới: {$created} · Bỏ qua: {$skipped}",
    'created' => $created,
    'skipped' => $skipped,
    'details' => $details,
]);