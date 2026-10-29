<?php
require_once '../includes/config.php';

/* ============================================================
   LOAD HELPER AN TOÀN
   ============================================================ */
$helperFile = __DIR__ . '/includes/notification_helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;
}

// Fallback nếu function chưa được định nghĩa
if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount(): int {
        global $pdo;
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

/* ============================================================
   CHECK BẢNG admin_notifications CÓ TỒN TẠI KHÔNG
   ============================================================ */
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM admin_notifications LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    $tableExists = false;
}

include 'includes/admin_header.php';

/* ============================================================
   NẾU BẢNG CHƯA TỒN TẠI → HƯỚNG DẪN CHẠY SQL
   ============================================================ */
if (!$tableExists) {
    ?>
    <div style="max-width:720px;margin:2rem auto;padding:2rem;background:#fff;border:1px solid #efeae0;border-radius:12px;box-shadow:0 4px 20px rgba(36,33,29,0.06);">
        <div style="font-size:3rem;text-align:center;margin-bottom:0.75rem;color:#a8461f;">
            <i class="ph ph-warning-circle"></i>
        </div>
        <h2 style="font-family:'Fraunces',Georgia,serif;font-size:1.6rem;color:#24211d;margin:0 0 0.75rem;text-align:center;">
            Bảng thông báo chưa được tạo
        </h2>
        <p style="text-align:center;color:#8a8377;font-size:0.9rem;margin:0 0 1.5rem;">
            Vui lòng chạy SQL sau trong phpMyAdmin (database <b>clothing_shop</b>) để bật tính năng thông báo.
        </p>

        <pre style="background:#1c1b19;color:#fffdf9;padding:1.25rem;border-radius:8px;overflow-x:auto;font-size:0.78rem;line-height:1.6;font-family:'IBM Plex Mono',monospace;">CREATE TABLE IF NOT EXISTS admin_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(255) NULL,
    icon VARCHAR(255) DEFAULT NULL,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    is_read TINYINT(1) DEFAULT 0,
    ref_id INT NULL,
    ref_type VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    INDEX idx_unread (is_read, created_at),
    INDEX idx_type (type),
    INDEX idx_priority (priority, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>

        <p style="text-align:center;color:#8a8377;font-size:0.85rem;margin:1.5rem 0 0;">
            Sau khi chạy xong, <a href="notifications.php" style="color:#a8461f;font-weight:700;">bấm vào đây để tải lại trang</a>.
        </p>
    </div>
    <?php
    include 'includes/admin_footer.php';
    exit;
}

/* ============================================================
   XỬ LÝ MARK ALL
   ============================================================ */
if (isset($_GET['mark_all']) && $_GET['mark_all'] === '1') {
    try {
        $pdo->query("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
    } catch (Exception $e) {}
    header('Location: notifications.php');
    exit;
}

/* ============================================================
   FILTER
   ============================================================ */
$filter = $_GET['type'] ?? '';

$validTypes = [
    'order_new', 'order_confirmed', 'order_cancelled',
    'return_new', 'return_approved',
    'review_new', 'review_low',
    'tax_warning', 'discount_event',
    'stock_low',
    'system',
];

$where = []; $params = [];
if (in_array($filter, $validTypes, true)) {
    $where[]  = 'type = ?';
    $params[] = $filter;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ============================================================
   LẤY DỮ LIỆU
   ============================================================ */
$notifications = [];
$counts        = ['all' => 0];
$unread        = 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM admin_notifications
        $whereSQL
        ORDER BY is_read ASC, created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $notifications = [];
}

try {
    foreach ($pdo->query("SELECT type, COUNT(*) c FROM admin_notifications GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['type']] = (int)$r['c'];
        $counts['all'] += (int)$r['c'];
    }
} catch (Exception $e) {}

$unread = getUnreadNotificationCount();

/* ============================================================
   MAP ICONS (Phosphor)
   ============================================================ */
$typeLabelMap = [
    'order_new'       => ['Đơn hàng mới',    'ph-shopping-cart',          '#c2410c'],
    'order_confirmed' => ['Xác nhận đơn',    'ph-check-circle',           '#1d4ed8'],
    'order_cancelled' => ['Đơn hủy',         'ph-x-circle',               '#b91c1c'],
    'return_new'      => ['Yêu cầu hoàn',    'ph-arrow-counter-clockwise','#7c3216'],
    'return_approved' => ['Duyệt hoàn',      'ph-check-fat',              '#15803d'],
    'review_new'      => ['Đánh giá mới',    'ph-star',                   '#a8461f'],
    'review_low'      => ['Đánh giá thấp',   'ph-warning-circle',         '#b91c1c'],
    'tax_warning'     => ['Thuế',            'ph-chart-bar',              '#1d4ed8'],
    'discount_event'  => ['Giảm giá',        'ph-confetti',               '#7c3216'],
    'stock_low'       => ['Sắp hết hàng',    'ph-package',                '#c2410c'],
    'system'          => ['Hệ thống',        'ph-gear',                   '#57534e'],
];

$priorityMap = [
    'urgent' => ['#b91c1c', '#fef2f2', 'Khẩn'],
    'high'   => ['#c2410c', '#fff7ed', 'Cao'],
    'normal' => ['#1d4ed8', '#eff6ff', 'Thường'],
    'low'    => ['#57534e', '#f5f5f4', 'Thấp'],
];
?>

<style>
    .adm-notif { --c-primary:#a8461f; --c-border:#efeae0; --c-card:#fff; --c-text-muted:#8a8377; }
    .adm-notif .admin-page-head {
        display:flex; align-items:flex-end; justify-content:space-between;
        margin-bottom:1.5rem; padding-bottom:1.1rem;
        border-bottom:1px solid var(--c-border); gap:1rem; flex-wrap:wrap;
    }
    .adm-notif .admin-page-head h1 {
        font-family:'Fraunces',Georgia,serif; font-size:2rem; margin:0; color:#24211d;
    }
    .adm-notif .admin-page-head .subtitle { font-size:0.85rem; color:var(--c-text-muted); margin-top:0.4rem; }

    .adm-notif .adm-tabs {
        display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1.25rem;
        padding-bottom:0.75rem; border-bottom:1px solid var(--c-border);
    }
    .adm-notif .adm-tab {
        padding:0.5rem 0.95rem; background:#fff; border:1px solid var(--c-border);
        border-radius:8px; font-size:0.8rem; font-weight:600; text-decoration:none;
        color:#24211d; transition:all 0.15s; display:inline-flex; align-items:center; gap:0.4rem;
    }
    .adm-notif .adm-tab:hover { background:#fbf9f5; border-color:#c8bfa9; }
    .adm-notif .adm-tab.active {
        background:var(--c-primary); color:#fff; border-color:var(--c-primary);
    }
    .adm-notif .adm-tab .cnt {
        background:rgba(0,0,0,0.08); padding:1px 7px; border-radius:999px;
        font-family:'IBM Plex Mono',monospace; font-size:0.7rem; font-weight:700;
    }
    .adm-notif .adm-tab.active .cnt { background:rgba(255,255,255,0.22); }

    .adm-notif .adm-notif-list { display:flex; flex-direction:column; gap:0.65rem; }

    .adm-notif .notif-card {
        display:flex; gap:1rem; align-items:flex-start;
        padding:1rem 1.15rem;
        background:#fff; border:1px solid var(--c-border);
        border-radius:12px; text-decoration:none; color:inherit;
        transition:all 0.18s; position:relative;
        box-shadow:0 1px 2px rgba(36,33,29,0.04);
    }
    .adm-notif .notif-card:hover {
        transform:translateX(3px);
        box-shadow:0 6px 20px rgba(36,33,29,0.08);
        border-color:#c8bfa9;
    }
    .adm-notif .notif-card.unread {
        background:linear-gradient(90deg, #fffaf5 0%, #ffffff 40%);
        border-left:4px solid var(--c-primary);
        padding-left:calc(1.15rem - 3px);
    }
    .adm-notif .notif-card.unread::after {
        content:''; position:absolute; top:1rem; right:1rem;
        width:8px; height:8px; border-radius:50%; background:var(--c-primary);
        box-shadow:0 0 0 4px rgba(168,70,31,0.15);
    }
    .adm-notif .notif-icon {
        width:44px; height:44px; border-radius:12px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-size:1.35rem; background:#faf8f4;
    }
    .adm-notif .notif-body { flex:1; min-width:0; }
    .adm-notif .notif-top {
        display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.35rem;
    }
    .adm-notif .notif-title { font-weight:700; color:#24211d; font-size:0.95rem; }
    .adm-notif .notif-time {
        font-size:0.72rem; color:var(--c-text-muted);
        font-family:'IBM Plex Mono',monospace; margin-left:auto;
    }
    .adm-notif .notif-message {
        font-size:0.85rem; color:#4b5563; line-height:1.55;
    }
    .adm-notif .notif-badge {
        display:inline-flex; align-items:center; gap:0.3rem;
        padding:0.2rem 0.6rem; font-size:0.68rem; font-weight:700;
        border-radius:999px; border:1px solid transparent;
    }
    .adm-notif .notif-badge::before {
        content:''; width:5px; height:5px; border-radius:50%; background:currentColor;
    }

    .adm-notif .adm-empty {
        text-align:center; padding:4rem 1.5rem; color:var(--c-text-muted);
        background:#fff; border:1px solid var(--c-border); border-radius:12px;
    }
    .adm-notif .adm-empty-icon { font-size:3rem; margin-bottom:0.75rem; opacity:0.4; color:#a8461f; }

    .adm-notif .btn-mark-all {
        padding:0.6rem 1.1rem; background:#fff; color:#24211d;
        border:1px solid var(--c-border); border-radius:8px; font-size:0.82rem;
        font-weight:600; cursor:pointer; text-decoration:none;
        display:inline-flex; align-items:center; gap:0.4rem; transition:all 0.15s;
    }
    .adm-notif .btn-mark-all:hover { background:#fbf9f5; border-color:#c8bfa9; }
</style>

<div class="adm-notif">

    <div class="admin-page-head">
        <div>
            <h1>Thông báo</h1>
            <div class="subtitle">
                Bạn có <b style="color:#a8461f;"><?php echo (int)$unread; ?></b> thông báo chưa đọc
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <button type="button" id="btnScanStock" class="btn-mark-all" style="background:#a8461f;color:#fff;border-color:#a8461f;">
                <i class="ph ph-package"></i> Quét SP sắp hết
            </button>
            <button type="button" id="btnSeedTest" class="btn-mark-all">
                <i class="ph ph-flask"></i> Tạo dữ liệu mẫu
            </button>
            <?php if ($unread > 0): ?>
                <a href="notifications.php?mark_all=1" class="btn-mark-all"
                   onclick="return confirm('Đánh dấu tất cả đã đọc?');">
                    <i class="ph ph-checks"></i> Đánh dấu tất cả đã đọc
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABS -->
    <div class="adm-tabs">
        <a href="notifications.php" class="adm-tab <?php echo $filter === '' ? 'active' : ''; ?>">
            <i class="ph ph-list"></i> Tất cả <span class="cnt"><?php echo (int)$counts['all']; ?></span>
        </a>
        <?php foreach ($typeLabelMap as $tKey => $tInfo): ?>
            <a href="notifications.php?type=<?php echo $tKey; ?>"
               class="adm-tab <?php echo $filter === $tKey ? 'active' : ''; ?>">
                <i class="ph <?php echo $tInfo[1]; ?>"></i> <?php echo $tInfo[0]; ?>
                <?php if (!empty($counts[$tKey])): ?>
                    <span class="cnt"><?php echo (int)$counts[$tKey]; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- LIST -->
    <?php if (empty($notifications)): ?>
        <div class="adm-empty">
            <div class="adm-empty-icon"><i class="ph ph-bell-slash"></i></div>
            <div style="font-size:1rem;font-weight:600;color:#24211d;margin-bottom:0.35rem;">
                Không có thông báo nào
            </div>
            <div style="font-size:0.85rem;">Mọi hoạt động mới sẽ xuất hiện ở đây.</div>
        </div>
    <?php else: ?>
        <div class="adm-notif-list">
            <?php foreach ($notifications as $n):
                $tInfo  = $typeLabelMap[$n['type']] ?? ['Khác', 'ph-bell', '#57534e'];
                $pInfo  = $priorityMap[$n['priority']] ?? $priorityMap['normal'];
                $isNew  = $n['is_read'] == 0;
                $href   = !empty($n['link']) ? $n['link'] : '#';

                // Xử lý icon thông minh
                $rawIcon = $n['icon'] ?: $tInfo[1];
                $iconHtml = '';
                if (strpos($rawIcon, '<') !== false) {
                    // Nếu DB đã lưu thẻ HTML (VD: <i class="..."></i>)
                    $iconHtml = $rawIcon;
                } elseif (strpos($rawIcon, 'ph-') === 0) {
                    // Nếu DB lưu tên class Phosphor (VD: ph-toggle-right)
                    $iconHtml = '<i class="ph ' . htmlspecialchars($rawIcon) . '"></i>';
                } else {
                    // Fallback cho Emoji cũ trong DB
                    $iconHtml = htmlspecialchars($rawIcon);
                }
            ?>
                <a href="<?php echo htmlspecialchars($href); ?>"
                   class="notif-card <?php echo $isNew ? 'unread' : ''; ?>"
                   data-id="<?php echo (int)$n['id']; ?>"
                   data-read="<?php echo $isNew ? '0' : '1'; ?>"
                   onclick="markRead(<?php echo (int)$n['id']; ?>, this);">
                    <div class="notif-icon" style="color:<?php echo $tInfo[2]; ?>;background:<?php echo $tInfo[2]; ?>18;">
                        <?php echo $iconHtml; ?>
                    </div>
                    <div class="notif-body">
                        <div class="notif-top">
                            <span class="notif-title"><?php echo htmlspecialchars($n['title']); ?></span>
                            <span class="notif-badge" style="color:<?php echo $pInfo[0]; ?>;background:<?php echo $pInfo[1]; ?>;border-color:<?php echo $pInfo[0]; ?>33;">
                                <?php echo $pInfo[2]; ?>
                            </span>
                            <span class="notif-time"><?php echo date('d/m/Y H:i', strtotime($n['created_at'])); ?></span>
                        </div>
                        <div class="notif-message"><?php echo nl2br(htmlspecialchars($n['message'] ?? '')); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function markRead(id, el) {
    if (el.dataset.read === '1') return;
    var fd = new FormData();
    fd.append('id', id);
    fetch('../ajax/mark_notification_read.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function () {
            el.classList.remove('unread');
            el.dataset.read = '1';
        })
        .catch(function () {});
}
</script>
<script>
/* ============================================================
   NÚT QUÉT SP SẮP HẾT
   ============================================================ */
document.getElementById('btnScanStock').addEventListener('click', function () {
    var btn = this;
    var old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-spinner-gap"></i> Đang quét...';

    var fd = new FormData();
    fd.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

    fetch('run_stock_check.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            alert(d.msg || (d.ok ? 'Thành công' : 'Lỗi'));
            if (d.ok) location.reload();
            else {
                btn.disabled = false;
                btn.innerHTML = old;
            }
        })
        .catch(function () {
            alert('Không kết nối được server');
            btn.disabled = false;
            btn.innerHTML = old;
        });
});

/* ============================================================
   NÚT TẠO DỮ LIỆU MẪU
   ============================================================ */
document.getElementById('btnSeedTest').addEventListener('click', function () {
    if (!confirm('Tạo 12 thông báo mẫu để test giao diện?\n(Nên xóa sau khi test xong)')) return;

    var btn = this;
    var old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-spinner-gap"></i> Đang tạo...';

    var fd = new FormData();
    fd.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

    fetch('seed_test_notifications.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            alert(d.msg || (d.ok ? 'Thành công' : 'Lỗi'));
            if (d.ok) location.reload();
            else {
                btn.disabled = false;
                btn.innerHTML = old;
            }
        })
        .catch(function () {
            alert('Không kết nối được server');
            btn.disabled = false;
            btn.innerHTML = old;
        });
});
</script>
<?php include 'includes/admin_footer.php'; ?>