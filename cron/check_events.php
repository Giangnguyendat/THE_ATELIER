<?php
/**
 * ============================================================
 * CRON: KIỂM TRA SỰ KIỆN GIẢM GIÁ
 * ============================================================
 * Chạy 1 lần/ngày (đề xuất 8h sáng) để tạo thông báo admin về:
 *   - Sự kiện sắp bắt đầu (ngày mai)
 *   - Sự kiện bắt đầu hôm nay
 *   - Sự kiện sắp kết thúc (còn 1 ngày)
 *
 * CÁCH CHẠY:
 *
 *   ▶ CLI (Windows Task Scheduler / Linux cron):
 *     D:\xampp\php\php.exe D:\xampp\htdocs\clothing_shop\cron\check_events.php
 *
 *   ▶ Web (đặt secret key trong includes/secrets.php):
 *     http://localhost/clothing_shop/cron/check_events.php?key=YOUR_SECRET
 *
 * SETUP TASK SCHEDULER (Windows):
 *   1. Mở Task Scheduler → Create Basic Task
 *   2. Name: "Atelier Check Events"
 *   3. Trigger: Daily, 8:00 AM
 *   4. Action: Start a program
 *      - Program: D:\xampp\php\php.exe
 *      - Arguments: D:\xampp\htdocs\clothing_shop\cron\check_events.php
 *      - Start in: D:\xampp\htdocs\clothing_shop\cron
 * ============================================================
 */

// ============================================================
// BOOTSTRAP
// ============================================================
$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    // CLI mode — cho phép chạy trực tiếp
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/notification_helper.php';
} else {
    // Web mode — yêu cầu secret key
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/notification_helper.php';

    // Lấy secret key từ includes/secrets.php
    $validKey = null;
    $secretsFile = __DIR__ . '/../includes/secrets.php';
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

// ============================================================
// LOG FILE
// ============================================================
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/check_events.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function cronLog(string $msg, string $level = 'INFO'): void {
    global $logFile, $isCLI;
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($isCLI) echo $line;
}

function output(string $msg): void {
    global $isCLI;
    if ($isCLI) {
        echo $msg . PHP_EOL;
    } else {
        echo $msg . "\n";
    }
}

cronLog('=== START check_events ===');
output("🕐 Bắt đầu check sự kiện giảm giá — " . date('d/m/Y H:i:s'));

// ============================================================
// HELPER: Format giới tính
// ============================================================
function genderLabel(?string $g): string {
    return [
        'female' => 'Nữ',
        'male'   => 'Nam',
        'unisex' => 'Unisex (cả nam & nữ)',
    ][$g] ?? 'Tất cả';
}

// ============================================================
// HELPER: Kiểm tra đã notify chưa (tránh spam)
// ============================================================
/**
 * @param string $type       Loại noti (discount_event)
 * @param int    $eventId    ID sự kiện
 * @param string $titlePart  Chuỗi con trong title để phân biệt loại noti
 */
function alreadyNotified(string $type, int $eventId, string $titlePart, int $withinHours = 20): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM admin_notifications
            WHERE type = ?
              AND ref_id = ?
              AND ref_type = 'event'
              AND title LIKE ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$type, $eventId, '%' . $titlePart . '%', $withinHours]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================================
// LẤY DANH SÁCH SỰ KIỆN ACTIVE
// ============================================================
try {
    $stmt = $pdo->query("
        SELECT * FROM discount_events
        WHERE active = 1
        ORDER BY start_month, start_day
    ");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    cronLog('Lỗi query discount_events: ' . $e->getMessage(), 'ERROR');
    output("❌ Lỗi: " . $e->getMessage());
    exit(1);
}

if (empty($events)) {
    cronLog('Không có sự kiện active nào.');
    output("📭 Không có sự kiện active nào.");
    exit(0);
}

cronLog('Tìm thấy ' . count($events) . ' sự kiện active.');
output("📋 Tìm thấy " . count($events) . " sự kiện active.");
output("");

// ============================================================
// MỐC THỜI GIAN CẦN CHECK
// ============================================================
$now       = new DateTime();
$today     = (int)$now->format('j');   // 1-31
$thisMonth = (int)$now->format('n');   // 1-12
$thisYear  = (int)$now->format('Y');

$tomorrow = (clone $now)->modify('+1 day');
$tmrwDay  = (int)$tomorrow->format('j');
$tmrwMon  = (int)$tomorrow->format('n');

$yesterday     = (clone $now)->modify('-1 day');
$ystdDay       = (int)$yesterday->format('j');
$ystdMon       = (int)$yesterday->format('n');

$notifiedCount = 0;

// ============================================================
// DUYỆT TỪNG SỰ KIỆN
// ============================================================
foreach ($events as $ev) {
    $evId   = (int)$ev['id'];
    $evName = $ev['name'] ?? ('Sự kiện #' . $evId);

    $sdDay = (int)$ev['start_day'];
    $sdMon = (int)$ev['start_month'];
    $edDay = (int)$ev['end_day'];
    $edMon = (int)$ev['end_month'];

    $gender    = genderLabel($ev['target_gender'] ?? null);
    $discount  = (float)$ev['discount_percent'];
    $discountF = rtrim(rtrim(number_format($discount, 1, ',', '.'), '0'), ',') . '%';

    // ---------- 1. SẮP BẮT ĐẦU (ngày mai = ngày start) ----------
    if ($tmrwDay === $sdDay && $tmrwMon === $sdMon) {
        if (!alreadyNotified('discount_event', $evId, 'bắt đầu ngày mai')) {
            $startDate = sprintf('%02d/%02d/%d', $sdDay, $sdMon, $thisYear);

            $title = "🎉 Sự kiện \"{$evName}\" bắt đầu ngày mai";
            $msg   = "Sự kiện sẽ diễn ra từ {$startDate}.\n"
                   . "Giảm: {$discountF} cho {$gender}.\n\n"
                   . "👉 Nhớ chuẩn bị banner và kiểm tra tồn kho!";

            $notifId = notifyAdmin('discount_event', $title, $msg, [
                'link'     => 'discount-events.php?id=' . $evId,
                'ref_id'   => $evId,
                'ref_type' => 'event',
                'icon'     => '🎉',
                'priority' => 'high',
            ]);

            if ($notifId > 0) {
                $notifiedCount++;
                cronLog("✓ Notify T-1: {$evName} (#{$evId})");
                output("✓ [T-1] Đã thông báo: {$evName}");
            }
        } else {
            output("• [T-1] Đã notify hôm nay rồi: {$evName}");
        }
    }

    // ---------- 2. BẮT ĐẦU HÔM NAY ----------
    if ($sdDay === $today && $sdMon === $thisMonth) {
        if (!alreadyNotified('discount_event', $evId, 'BẮT ĐẦU HÔM NAY')) {
            $endDate = sprintf('%02d/%02d/%d', $edDay, $edMon, $thisYear);

            $title = "🚀 Sự kiện \"{$evName}\" BẮT ĐẦU HÔM NAY";
            $msg   = "Sự kiện đang chạy đến hết {$endDate}.\n"
                   . "Giảm: {$discountF} cho {$gender}.\n\n"
                   . "👉 Kiểm tra lại giá & banner đã hiển thị chưa!";

            $notifId = notifyAdmin('discount_event', $title, $msg, [
                'link'     => 'discount-events.php?id=' . $evId,
                'ref_id'   => $evId,
                'ref_type' => 'event',
                'icon'     => '🚀',
                'priority' => 'high',
            ]);

            if ($notifId > 0) {
                $notifiedCount++;
                cronLog("✓ Notify START: {$evName} (#{$evId})");
                output("✓ [START] Đã thông báo: {$evName}");
            }
        } else {
            output("• [START] Đã notify hôm nay rồi: {$evName}");
        }
    }

    // ---------- 3. SẮP KẾT THÚC (ngày mai = ngày end, khác ngày start) ----------
    if ($tmrwDay === $edDay && $tmrwMon === $edMon
        && !($edDay === $sdDay && $edMon === $sdMon)) {
        if (!alreadyNotified('discount_event', $evId, 'sắp kết thúc')) {
            $endDate = sprintf('%02d/%02d/%d', $edDay, $edMon, $thisYear);

            $title = "⏰ Sự kiện \"{$evName}\" sắp kết thúc";
            $msg   = "Sự kiện sẽ kết thúc vào {$endDate} (còn 1 ngày).\n"
                   . "Giảm: {$discountF} cho {$gender}.\n\n"
                   . "👉 Cơ hội cuối cho khách mua sắm!";

            $notifId = notifyAdmin('discount_event', $title, $msg, [
                'link'     => 'discount-events.php?id=' . $evId,
                'ref_id'   => $evId,
                'ref_type' => 'event',
                'icon'     => '⏰',
                'priority' => 'normal',
            ]);

            if ($notifId > 0) {
                $notifiedCount++;
                cronLog("✓ Notify END-1: {$evName} (#{$evId})");
                output("✓ [END-1] Đã thông báo: {$evName}");
            }
        } else {
            output("• [END-1] Đã notify hôm nay rồi: {$evName}");
        }
    }

    // ---------- 4. ĐÃ KẾT THÚC HÔM QUA ----------
    if ($ystdDay === $edDay && $ystdMon === $edMon) {
        if (!alreadyNotified('discount_event', $evId, 'đã kết thúc', 30)) {
            $title = "✅ Sự kiện \"{$evName}\" đã kết thúc";
            $msg   = "Sự kiện đã kết thúc vào " . sprintf('%02d/%02d', $edDay, $edMon) . ".\n"
                   . "👉 Nhớ tắt banner & khôi phục giá gốc nếu cần.";

            $notifId = notifyAdmin('discount_event', $title, $msg, [
                'link'     => 'discount-events.php?id=' . $evId,
                'ref_id'   => $evId,
                'ref_type' => 'event',
                'icon'     => '✅',
                'priority' => 'normal',
            ]);

            if ($notifId > 0) {
                $notifiedCount++;
                cronLog("✓ Notify ENDED: {$evName} (#{$evId})");
                output("✓ [ENDED] Đã thông báo: {$evName}");
            }
        } else {
            output("• [ENDED] Đã notify rồi: {$evName}");
        }
    }
}

// ============================================================
// KẾT THÚC
// ============================================================
output("");
output("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
output("✅ Hoàn thành. Đã tạo {$notifiedCount} thông báo mới.");

cronLog("=== END — created {$notifiedCount} notification(s) ===");

exit(0);