<?php
require_once '../includes/config.php';
require_once __DIR__ . '/includes/notification_helper.php';

include 'includes/admin_header.php';

/* ============================================================
   TỰ ĐỘNG QUÉT SỰ KIỆN SẮP DIỄN RA (Trước 3 ngày)
   ============================================================ */
try {
    $upcomingEvents = $pdo->query("SELECT * FROM discount_events WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $today = new DateTime();
    $today->setTime(0, 0, 0);

    foreach ($upcomingEvents as $ev) {
        $startDate = new DateTime(date('Y') . '-' . str_pad($ev['start_month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($ev['start_day'], 2, '0', STR_PAD_LEFT));
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
} catch (Exception $e) {}

/* ============================================================
   XỬ LÝ POST (THÊM / SỬA / XÓA)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: discount-events.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtName = $pdo->prepare("SELECT name FROM discount_events WHERE id = ?");
        $stmtName->execute([$id]);
        $evName = $stmtName->fetchColumn() ?: 'Không rõ';

        $pdo->prepare("DELETE FROM discount_events WHERE id = ?")->execute([$id]);
        
        addNotification('discount_event', 'Xóa sự kiện', 'Đã xóa sự kiện: ' . $evName, 'discount-events.php', 'low', 'ph-trash');
        $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã xóa sự kiện'];
        header('Location: discount-events.php'); exit;
    }

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $target   = $_POST['target_gender'] ?? 'all';
        $percent  = max(1, min(100, (int)($_POST['discount_percent'] ?? 10)));
        $sm = max(1, min(12, (int)($_POST['start_month'] ?? 1)));
        $sd = max(1, min(31, (int)($_POST['start_day']   ?? 1)));
        $em = max(1, min(12, (int)($_POST['end_month']   ?? 1)));
        $ed = max(1, min(31, (int)($_POST['end_day']     ?? 1)));
        $active   = (int)($_POST['active'] ?? 1) ? 1 : 0;

        // Validate chặt chẽ
        if (!in_array($target, ['all', 'male', 'female', 'unisex'], true)) {
            $target = 'all';
        }

        if ($name === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập tên sự kiện.'];
            header('Location: discount-events.php'); exit;
        }

        try {
            if ($id > 0) {
                $stmtOld = $pdo->prepare("SELECT active FROM discount_events WHERE id = ?");
                $stmtOld->execute([$id]);
                $oldActive = (int)$stmtOld->fetchColumn();

                $pdo->prepare("
                    UPDATE discount_events SET
                        name=?, description=?, target_gender=?, discount_percent=?,
                        start_month=?, start_day=?, end_month=?, end_day=?, active=?
                    WHERE id=?
                ")->execute([$name, $desc, $target, $percent, $sm, $sd, $em, $ed, $active, $id]);

                if ($oldActive !== $active) {
                    if ($active === 1) {
                        addNotification('discount_event', 'Bật sự kiện', 'Đã bật sự kiện: ' . $name, 'discount-events.php', 'normal', 'ph-toggle-right');
                    } else {
                        addNotification('discount_event', 'Tắt sự kiện', 'Đã tắt sự kiện: ' . $name, 'discount-events.php', 'low', 'ph-toggle-left');
                    }
                } else {
                    addNotification('discount_event', 'Cập nhật sự kiện', 'Đã cập nhật thông tin sự kiện: ' . $name, 'discount-events.php', 'normal', 'ph-pencil-simple');
                }

                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã cập nhật sự kiện'];

            } else {
                $pdo->prepare("
                    INSERT INTO discount_events
                        (name, description, target_gender, discount_percent,
                         start_month, start_day, end_month, end_day, active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$name, $desc, $target, $percent, $sm, $sd, $em, $ed, $active]);

                addNotification('discount_event', 'Tạo sự kiện mới', 'Đã tạo sự kiện: ' . $name, 'discount-events.php', 'normal', 'ph-confetti');
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm sự kiện'];
            }
        } catch (Exception $e) {
            // Bắt lỗi Database để hiển thị ra màn hình
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi CSDL: ' . $e->getMessage()];
        }
        
        header('Location: discount-events.php'); exit;
    }
}

$events = $pdo->query("SELECT * FROM discount_events ORDER BY start_month, start_day")->fetchAll(PDO::FETCH_ASSOC);

$gLabels = ['male' => 'Nam', 'female' => 'Nữ', 'unisex' => 'Unisex', 'all' => 'Tất cả sản phẩm'];
?>

<div class="admin-page-head">
    <div>
        <h1>Sự kiện giảm giá</h1>
        <div class="subtitle"><?php echo count($events); ?> sự kiện</div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openEventModal()">
        + Thêm sự kiện
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
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Chưa có sự kiện nào</p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Tên sự kiện</th>
                    <th>Đối tượng</th>
                    <th>Giảm</th>
                    <th>Thời gian</th>
                    <th>Trạng thái</th>
                    <th style="width:150px;">Thao tác</th>
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
                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;">
                            <?php echo $e['start_day'] . '/' . $e['start_month']; ?>
                            &rarr;
                            <?php echo $e['end_day'] . '/' . $e['end_month']; ?>
                        </td>
                        <td>
                            <?php if ($e['active']): ?>
                                <span class="adm-badge adm-badge--green">Đang bật</span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--gray">Đã tắt</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn-adm btn-adm--edit"
                                    onclick='editEvent(<?php echo json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Sửa</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirmDelete('Xóa sự kiện này?')">
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

<!-- Modal -->
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
                <input type="text" name="name" id="e_name" class="adm-input" required maxlength="100" placeholder="VD: Quốc tế Phụ nữ 8/3">
            </div>

            <div class="adm-form-group">
                <label>Mô tả ngắn</label>
                <input type="text" name="description" id="e_description" class="adm-input" maxlength="255">
            </div>

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Đối tượng</label>
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

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Ngày bắt đầu (tháng / ngày)</label>
                    <div style="display:flex;gap:0.5rem;">
                        <input type="number" name="start_month" id="e_sm" class="adm-input" min="1" max="12" value="3">
                        <input type="number" name="start_day"   id="e_sd" class="adm-input" min="1" max="31" value="8">
                    </div>
                </div>
                <div class="adm-form-group">
                    <label>Ngày kết thúc (tháng / ngày)</label>
                    <div style="display:flex;gap:0.5rem;">
                        <input type="number" name="end_month" id="e_em" class="adm-input" min="1" max="12" value="3">
                        <input type="number" name="end_day"   id="e_ed" class="adm-input" min="1" max="31" value="8">
                    </div>
                </div>
            </div>

            <div class="adm-form-group">
                <label>Trạng thái</label>
                <select name="active" id="e_active" class="adm-select">
                    <option value="1">Bật</option>
                    <option value="0">Tắt</option>
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
function openEventModal() {
    document.getElementById('eventModalTitle').textContent = 'Thêm sự kiện';
    document.querySelector('#eventModal form').reset();
    document.getElementById('e_id').value = '';
    document.getElementById('e_percent').value = 30;
    document.getElementById('eventModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editEvent(e) {
    document.getElementById('eventModalTitle').textContent = 'Sửa sự kiện #' + e.id;
    document.getElementById('e_id').value          = e.id;
    document.getElementById('e_name').value        = e.name;
    document.getElementById('e_description').value = e.description || '';
    document.getElementById('e_target').value      = e.target_gender;
    document.getElementById('e_percent').value     = e.discount_percent;
    document.getElementById('e_sm').value          = e.start_month;
    document.getElementById('e_sd').value          = e.start_day;
    document.getElementById('e_em').value          = e.end_month;
    document.getElementById('e_ed').value          = e.end_day;
    document.getElementById('e_active').value      = e.active;
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
</script>

<?php include 'includes/admin_footer.php'; ?>