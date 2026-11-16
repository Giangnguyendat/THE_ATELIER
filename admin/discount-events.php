<?php
require_once '../includes/config.php';
require_once __DIR__ . '/includes/notification_helper.php';
require_once '../includes/discount_helper.php';

include 'includes/admin_header.php';

/* ============================================================
   1. ĐỒNG BỘ TRẠNG THÁI SỰ KIỆN TỰ ĐỘNG NGAY KHI LOAD TRANG ADMIN
   ============================================================ */
if (function_exists('syncDiscountEventsStatus')) {
    syncDiscountEventsStatus($pdo);
}
/* ============================================================
   2. TỰ ĐỘNG QUÉT SỰ KIỆN SẮP DIỄN RA (Trước 1 - 3 ngày)
   ============================================================ */
try {
    $upcomingEvents = $pdo->query("SELECT * FROM discount_events WHERE active = 1 OR is_manual_override = 0")->fetchAll(PDO::FETCH_ASSOC);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $currentYear = (int)$today->format('Y');

    foreach ($upcomingEvents as $ev) {
        $startDate = new DateTime(sprintf('%04d-%02d-%02d', $currentYear, (int)$ev['start_month'], (int)$ev['start_day']));
        if ($startDate < $today) {
            $startDate->modify('+1 year');
        }
        $diff = (int)$today->diff($startDate)->days;

        if ($diff >= 1 && $diff <= 3) {
            $title = 'Sắp diễn ra: ' . $ev['name'];
            $check = $pdo->prepare("SELECT id FROM admin_notifications WHERE title = ? AND DATE(created_at) = CURDATE()");
            $check->execute([$title]);

            if (!$check->fetch()) {
                addNotification(
                    'discount_event',
                    $title,
                    "Sự kiện giảm giá sẽ bắt đầu sau $diff ngày (" . $ev['start_day'] . "/" . $ev['start_month'] . "). Hãy chuẩn bị banner và sản phẩm!",
                    'discount-events.php',
                    'high',
                    'ph-calendar-star'
                );
            }
        }
    }
} catch (Exception $e) {
    error_log("Lỗi thông báo sự kiện: " . $e->getMessage());
}

/* ============================================================
   3. XỬ LÝ POST (THÊM / SỬA / XÓA)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên làm việc đã hết hạn. Vui lòng thử lại.'];
        header('Location: discount-events.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';

    // XỬ LÝ XÓA SỰ KIỆN
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtName = $pdo->prepare("SELECT name FROM discount_events WHERE id = ?");
        $stmtName->execute([$id]);
        $evName = $stmtName->fetchColumn() ?: 'Không rõ';

        $pdo->prepare("DELETE FROM discount_events WHERE id = ?")->execute([$id]);

        addNotification('discount_event', 'Xóa sự kiện', 'Đã xóa sự kiện: ' . $evName, 'discount-events.php', 'low', 'ph-trash');
        $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã xóa sự kiện thành công'];
        header('Location: discount-events.php');
        exit;
    }

    // XỬ LÝ THÊM MỚI / LƯU SỰ KIỆN
    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $target  = $_POST['target_gender'] ?? 'all';
        $percent = max(1, min(100, (int)($_POST['discount_percent'] ?? 10)));
        $sm      = max(1, min(12, (int)($_POST['start_month'] ?? 1)));
        $sd      = max(1, min(31, (int)($_POST['start_day']   ?? 1)));
        $em      = max(1, min(12, (int)($_POST['end_month']   ?? 1)));
        $ed      = max(1, min(31, (int)($_POST['end_day']     ?? 1)));
        $active  = (int)($_POST['active'] ?? 1) ? 1 : 0;

        // Chế độ: 'auto' (chạy theo lịch) hoặc 'manual' (cưỡng chế bật/tắt thủ công)
        $scheduleMode = $_POST['schedule_mode'] ?? 'auto';
        $isManualOverride = ($scheduleMode === 'manual') ? 1 : 0;

        if (!in_array($target, ['all', 'male', 'female', 'unisex'], true)) {
            $target = 'all';
        }

        if ($name === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Vui lòng nhập tên sự kiện.'];
            header('Location: discount-events.php');
            exit;
        }

        // Nếu đặt chế độ tự động, tự tính toán active ngay lúc lưu
        if ($isManualOverride === 0) {
            $today = new DateTime();
            $currentMD = (int)$today->format('m') * 100 + (int)$today->format('d');
            $startMD = $sm * 100 + $sd;
            $endMD   = $em * 100 + $ed;

            if ($startMD <= $endMD) {
                $active = ($currentMD >= $startMD && $currentMD <= $endMD) ? 1 : 0;
            } else {
                $active = ($currentMD >= $startMD || $currentMD <= $endMD) ? 1 : 0;
            }
        }

        try {
            if ($id > 0) {
                $stmtOld = $pdo->prepare("SELECT active FROM discount_events WHERE id = ?");
                $stmtOld->execute([$id]);
                $oldActive = (int)$stmtOld->fetchColumn();

                $pdo->prepare("
                    UPDATE discount_events SET
                        name=?, description=?, target_gender=?, discount_percent=?,
                        start_month=?, start_day=?, end_month=?, end_day=?, active=?,
                        is_manual_override=?
                    WHERE id=?
                ")->execute([$name, $desc, $target, $percent, $sm, $sd, $em, $ed, $active, $isManualOverride, $id]);

                if ($oldActive !== $active) {
                    $logAction = ($active === 1) ? 'Đã bật sự kiện: ' : 'Đã tắt sự kiện: ';
                    $logIcon   = ($active === 1) ? 'ph-toggle-right' : 'ph-toggle-left';
                    addNotification('discount_event', 'Cập nhật trạng thái', $logAction . $name, 'discount-events.php', 'normal', $logIcon);
                } else {
                    addNotification('discount_event', 'Cập nhật sự kiện', 'Đã cập nhật thông tin sự kiện: ' . $name, 'discount-events.php', 'normal', 'ph-pencil-simple');
                }

                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã cập nhật sự kiện thành công'];
            } else {
                $pdo->prepare("
                    INSERT INTO discount_events
                        (name, description, target_gender, discount_percent,
                         start_month, start_day, end_month, end_day, active, is_manual_override, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$name, $desc, $target, $percent, $sm, $sd, $em, $ed, $active, $isManualOverride]);

                addNotification('discount_event', 'Tạo sự kiện mới', 'Đã tạo sự kiện: ' . $name, 'discount-events.php', 'normal', 'ph-confetti');
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm sự kiện thành công'];
            }
        } catch (Exception $e) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi CSDL: ' . $e->getMessage()];
        }

        header('Location: discount-events.php');
        exit;
    }
}

/* ============================================================
   4. LẤY DỮ LIỆU HIỂN THỊ
   ============================================================ */
$events = $pdo->query("SELECT * FROM discount_events ORDER BY start_month, start_day")->fetchAll(PDO::FETCH_ASSOC);
$gLabels = ['male' => 'Nam', 'female' => 'Nữ', 'unisex' => 'Unisex', 'all' => 'Tất cả sản phẩm'];
?>

<div class="admin-page-head">
    <div>
        <h1>Sự kiện giảm giá</h1>
        <div class="subtitle"><?php echo count($events); ?> sự kiện trong danh sách</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openEventModal()">
        <i class="ph ph-plus"></i> Thêm sự kiện
    </button>
</div>

<div class="mkt-tabs">
    <a href="discount-events.php" class="mkt-tab active">
        <span class="icon"><i class="ph ph-confetti"></i></span>
        Sự kiện giảm giá
        <span class="cnt"><?php echo count($events); ?></span>
    </a>
    <a href="vouchers.php" class="mkt-tab">
        <span class="icon"><i class="ph ph-ticket"></i></span>
        Voucher / Mã giảm giá
        <?php
        $__vCount = 0;
        try { $__vCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn(); } catch (Exception $e) {}
        if ($__vCount > 0): ?>
            <span class="cnt"><?php echo $__vCount > 99 ? '99+' : $__vCount; ?></span>
        <?php endif; ?>
    </a>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($events)): ?>
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Chưa có sự kiện nào trong hệ thống</p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Tên sự kiện</th>
                    <th>Đối tượng</th>
                    <th>Giảm</th>
                    <th>Thời gian áp dụng</th>
                    <th>Chế độ & Trạng thái</th>
                    <th style="width:150px; text-align:center;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td>
                            <b><?php echo htmlspecialchars($e['name']); ?></b>
                            <?php if (!empty($e['description'])): ?>
                                <div style="font-size:0.78rem;color:var(--admin-text-muted);margin-top:2px;">
                                    <?php echo htmlspecialchars($e['description']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $gLabels[$e['target_gender']] ?? $e['target_gender']; ?></td>
                        <td><span class="adm-badge adm-badge--red">-<?php echo (int)$e['discount_percent']; ?>%</span></td>
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.85rem;">
                            <?php echo sprintf('%02d/%02d', $e['start_day'], $e['start_month']); ?>
                            &rarr;
                            <?php echo sprintf('%02d/%02d', $e['end_day'], $e['end_month']); ?>
                        </td>
                        <td>
                            <?php if ($e['active']): ?>
                                <span class="adm-badge adm-badge--green"><i class="ph ph-check-circle"></i> Đang bật</span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--gray"><i class="ph ph-x-circle"></i> Đã tắt</span>
                            <?php endif; ?>

                            <?php if (empty($e['is_manual_override'])): ?>
                                <span class="adm-badge adm-badge--blue" style="font-size:0.7rem;padding:2px 6px;" title="Tự động đồng bộ theo ngày tháng">
                                    <i class="ph ph-clock"></i> Auto
                                </span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--orange" style="font-size:0.7rem;padding:2px 6px;" title="Đã khóa bằng tay bởi Admin">
                                    <i class="ph ph-hand-palm"></i> Manual
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="btn-adm btn-adm--edit"
                                    onclick='editEvent(<?php echo json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Sửa</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn chắc chắn muốn xóa sự kiện này?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                                <button type="submit" class="btn-adm btn-adm--delete">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal Thêm / Sửa -->
<div class="adm-modal" id="eventModal">
    <div class="adm-modal__box">
        <button type="button" class="adm-modal__close" onclick="closeEventModal()">&times;</button>
        <h2 class="adm-modal__title" id="eventModalTitle">Thêm sự kiện</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="e_id" value="">

            <div class="adm-form-group">
                <label>Tên sự kiện *</label>
                <input type="text" name="name" id="e_name" class="adm-input" required maxlength="100" placeholder="VD: Khuyến mãi ngày 8/3">
            </div>

            <div class="adm-form-group">
                <label>Mô tả ngắn</label>
                <input type="text" name="description" id="e_description" class="adm-input" maxlength="255" placeholder="Ghi chú thêm về sự kiện...">
            </div>

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Đối tượng áp dụng</label>
                    <select name="target_gender" id="e_target" class="adm-select">
                        <option value="all">Tất cả sản phẩm</option>
                        <option value="unisex">Sản phẩm Unisex</option>
                        <option value="female">Sản phẩm Nữ</option>
                        <option value="male">Sản phẩm Nam</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label>Giảm giá (%)</label>
                    <input type="number" name="discount_percent" id="e_percent" class="adm-input" min="1" max="100" value="30">
                </div>
            </div>

            <!-- CHỌN NGÀY BẮT ĐẦU - NGÀY KẾT THÚC -->
            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Ngày bắt đầu</label>
                    <input type="date" id="e_start_date_picker" class="adm-input" required>
                    <input type="hidden" name="start_month" id="e_sm" value="1">
                    <input type="hidden" name="start_day"   id="e_sd" value="1">
                </div>
                <div class="adm-form-group">
                    <label>Ngày kết thúc</label>
                    <input type="date" id="e_end_date_picker" class="adm-input" required>
                    <input type="hidden" name="end_month" id="e_em" value="1">
                    <input type="hidden" name="end_day"   id="e_ed" value="1">
                </div>
            </div>

            <!-- CHỌN CHẾ ĐỘ QUẢN LÝ -->
            <div class="adm-form-group" style="background:#fcfbf9; padding:12px; border-radius:8px; border:1px solid #ebe5d8; margin-top:0.5rem;">
                <label style="font-weight:600; margin-bottom:8px; display:block;">Chế độ kích hoạt:</label>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                        <input type="radio" name="schedule_mode" id="mode_auto" value="auto" checked onchange="toggleManualState()">
                        <span><b>Tự động (Theo lịch)</b> — Hệ thống tự bật khi đến ngày và tự tắt khi hết ngày.</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                        <input type="radio" name="schedule_mode" id="mode_manual" value="manual" onchange="toggleManualState()">
                        <span><b>Thủ công (Can thiệp tay)</b> — Giữ nguyên trạng thái bên dưới, không theo lịch máy chủ.</span>
                    </label>
                </div>
            </div>

            <!-- CHỌN TRẠNG THÁI (CHỈ ÁP DỤNG KHI MANUAL) -->
            <div class="adm-form-group" id="manual_active_wrapper" style="display:none;">
                <label>Trạng thái cưỡng chế</label>
                <select name="active" id="e_active" class="adm-select">
                    <option value="1">Bật sự kiện ngay</option>
                    <option value="0">Tắt sự kiện</option>
                </select>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem" onclick="closeEventModal()">Hủy</button>
                <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                    <i class="ph ph-floppy-disk"></i> Lưu sự kiện
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function padZero(num) {
    return num.toString().padStart(2, '0');
}

function toggleManualState() {
    const isManual = document.getElementById('mode_manual').checked;
    document.getElementById('manual_active_wrapper').style.display = isManual ? 'block' : 'none';
}

function openEventModal() {
    document.getElementById('eventModalTitle').textContent = 'Thêm sự kiện mới';
    document.querySelector('#eventModal form').reset();
    document.getElementById('e_id').value = '';
    document.getElementById('e_percent').value = 30;
    
    // Mặc định: ngày bắt đầu là hôm nay, kết thúc sau 7 ngày
    const today = new Date();
    const nextWeek = new Date(today);
    nextWeek.setDate(nextWeek.getDate() + 7);
    
    document.getElementById('e_start_date_picker').value = today.toISOString().split('T')[0];
    document.getElementById('e_end_date_picker').value = nextWeek.toISOString().split('T')[0];

    document.getElementById('mode_auto').checked = true;
    toggleManualState();

    document.getElementById('eventModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editEvent(e) {
    document.getElementById('eventModalTitle').textContent = 'Sửa sự kiện: ' + e.name;
    document.getElementById('e_id').value          = e.id;
    document.getElementById('e_name').value        = e.name;
    document.getElementById('e_description').value = e.description || '';
    document.getElementById('e_target').value      = e.target_gender;
    document.getElementById('e_percent').value     = e.discount_percent;
    document.getElementById('e_active').value      = e.active;
    
    // Lấy năm hiện tại để render ra picker date
    const currentYear = new Date().getFullYear();
    const startDateStr = currentYear + '-' + padZero(e.start_month) + '-' + padZero(e.start_day);
    const endDateStr   = currentYear + '-' + padZero(e.end_month) + '-' + padZero(e.end_day);
    
    document.getElementById('e_start_date_picker').value = startDateStr;
    document.getElementById('e_end_date_picker').value   = endDateStr;

    if (parseInt(e.is_manual_override) === 1) {
        document.getElementById('mode_manual').checked = true;
    } else {
        document.getElementById('mode_auto').checked = true;
    }
    toggleManualState();

    document.getElementById('eventModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEventModal() {
    document.getElementById('eventModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('eventModal').addEventListener('click', function (ev) {
    if (ev.target === this) closeEventModal();
});

// Bắt sự kiện submit form để trích xuất tháng & ngày
document.querySelector('#eventModal form').addEventListener('submit', function(e) {
    const startVal = document.getElementById('e_start_date_picker').value;
    const endVal   = document.getElementById('e_end_date_picker').value;
    
    if (!startVal || !endVal) {
        e.preventDefault();
        alert('Vui lòng chọn đầy đủ ngày bắt đầu và kết thúc!');
        return;
    }

    const startParts = startVal.split('-'); // [YYYY, MM, DD]
    const endParts   = endVal.split('-');

    document.getElementById('e_sm').value = parseInt(startParts[1], 10);
    document.getElementById('e_sd').value = parseInt(startParts[2], 10);
    
    document.getElementById('e_em').value = parseInt(endParts[1], 10);
    document.getElementById('e_ed').value = parseInt(endParts[2], 10);
});
</script>

<?php include 'includes/admin_footer.php'; ?>