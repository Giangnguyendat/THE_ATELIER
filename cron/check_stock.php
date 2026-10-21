<?php
/**
 * ============================================================
 * CRON: KIỂM TRA SẢN PHẨM SẮP HẾT HÀNG
 * ============================================================
 * Chạy mỗi 6h hoặc 1 lần/ngày để tạo thông báo admin về:
 *   - Sản phẩm SẮP HẾT (tổng stock < 5)
 *   - Sản phẩm RẤT SẮP HẾT (stock < 3) → priority urgent
 *   - Sản phẩm HẾT HÀNG (stock = 0) → priority urgent
 *
 * CÁCH CHẠY:
 *
 *   ▶ CLI:
 *     D:\xampp\php\php.exe D:\xampp\htdocs\clothing_shop\cron\check_stock.php
 *
 *   ▶ Web:
 *     http://localhost/clothing_shop/cron/check_stock.php?key=YOUR_SECRET
 *
 * SETUP TASK SCHEDULER (Windows):
 *   Trigger: Daily, repeat every 6 hours
 *   Program: D:\xampp\php\php.exe
 *   Args:    D:\xampp\htdocs\clothing_shop\cron\check_stock.php
 * ============================================================
 */

$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/notification_helper.php';
} else {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/notification_helper.php';

    $secretsFile = __DIR__ . '/../includes/secrets.php';
    $validKey = null;
    if (file_exists($secretsFile)) {
        $secrets = include $secretsFile;
        if (is_array($secrets) && !empty($secrets['CRON_SECRET'])) {
            $validKey = $secrets['CRON_SECRET'];
        }
    }
    $providedKey = $_GET['key'] ?? '';

    if (empty($validKey) || !hash_equals($validKey, (string)$providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — Invalid or missing cron key.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

/* ============================================================
   LOG
   ============================================================ */
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/check_stock.log';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

function cronLog(string $msg, string $level = 'INFO'): void {
    global $logFile, $isCLI;
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($isCLI) echo $line;
}

function output(string $msg): void {
    echo $msg . (php_sapi_name() === 'cli' ? PHP_EOL : "\n");
}

cronLog('=== START check_stock ===');
output("🔍 Kiểm tra tồn kho — " . date('d/m/Y H:i:s'));
output("");

/* ============================================================
   CẤU HÌNH NGƯỠNG
   ============================================================ */
$THRESHOLD        = 5;  // < 5 → sắp hết
$URGENT_THRESHOLD = 3;  // < 3 → rất sắp hết / hết hàng
$SPAM_HOURS       = 24; // Không notify lại SP trong 24h

/* ============================================================
   QUERY: SP CÓ TỔNG STOCK < NGƯỠNG
   ============================================================ */
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.image_url,
            COALESCE(SUM(ps.stock), 0) AS total_stock,
            GROUP_CONCAT(
                CONCAT(ps.size, ': ', ps.stock)
                ORDER BY FIELD(ps.size, 'S','M','L','XL','2XL')
                SEPARATOR ' · '
            ) AS size_breakdown
        FROM products p
        LEFT JOIN product_sizes ps ON ps.product_id = p.id
        GROUP BY p.id, p.name, p.image_url
        HAVING total_stock < ?
        ORDER BY total_stock ASC, p.id ASC
    ");
    $stmt->execute([$THRESHOLD]);
    $lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    cronLog('Lỗi query: ' . $e->getMessage(), 'ERROR');
    output("❌ Lỗi query: " . $e->getMessage());
    exit(1);
}

if (empty($lowStockProducts)) {
    cronLog('Không có sản phẩm nào sắp hết.');
    output("📭 Không có sản phẩm nào sắp hết hàng.");
    exit(0);
}

cronLog('Tìm thấy ' . count($lowStockProducts) . ' SP có tồn kho < ' . $THRESHOLD);
output("📋 Tìm thấy " . count($lowStockProducts) . " sản phẩm sắp hết hàng.");
output("");

/* ============================================================
   HELPER: Kiểm tra đã notify chưa (tránh spam)
   ============================================================ */
function alreadyNotifiedStock(int $productId, int $hours): bool {
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
   TẠO THÔNG BÁO
   ============================================================ */
$createdCount = 0;
$skippedCount = 0;

foreach ($lowStockProducts as $p) {
    $productId = (int)$p['id'];
    $stock     = (int)$p['total_stock'];
    $name      = $p['name'];
    $sizeInfo  = $p['size_breakdown'] ?: 'Không có size nào';

    // Tránh spam
    if (alreadyNotifiedStock($productId, $SPAM_HOURS)) {
        $skippedCount++;
        output("• [skip] Đã notify trong {$SPAM_HOURS}h: {$name}");
        continue;
    }

    /* Chia mức theo tồn kho */
    if ($stock === 0) {
        $priority  = 'urgent';
        $icon      = '🚨';
        $levelText = 'HẾT HÀNG';
        $emoji     = '🚨';
    } elseif ($stock < $URGENT_THRESHOLD) {
        $priority  = 'urgent';
        $icon      = '⚠️';
        $levelText = 'RẤT SẮP HẾT';
        $emoji     = '⚠️';
    } else {
        $priority  = 'high';
        $icon      = '📦';
        $levelText = 'Sắp hết hàng';
        $emoji     = '📦';
    }

    $title = "{$emoji} {$levelText}: {$name}";

    $message =
        "Tồn kho hiện tại: {$stock} sản phẩm\n" .
        "Chi tiết size: {$sizeInfo}\n\n" .
        "👉 Bấm để xem chi tiết & nhập thêm hàng.";

    $link = 'products.php?id=' . $productId;

    $notifId = notifyAdmin('stock_low', $title, $message, [
        'link'     => $link,
        'icon'     => $icon,
        'priority' => $priority,
        'ref_id'   => $productId,
        'ref_type' => 'product',
    ]);

    if ($notifId > 0) {
        $createdCount++;
        cronLog("✓ Notify stock_low: {$name} (#{$productId}) — stock {$stock}");
        output("✓ [{$levelText}] {$name} — còn {$stock}");
    }
}

output("");
output("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
output("✅ Hoàn thành. Tạo mới: {$createdCount} · Bỏ qua: {$skippedCount}");

cronLog("=== END — created {$createdCount}, skipped {$skippedCount} ===");
exit(0);