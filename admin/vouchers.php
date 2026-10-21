<?php
require_once '../includes/config.php';
require_once '../includes/voucher_helper.php';  // ✅ dùng resetVoucherUses()
include 'includes/admin_header.php';

/* ============================================================
   XỬ LÝ POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: vouchers.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    /* ============================================================
       XÓA — xóa cả log voucher_usages (transaction)
       ============================================================ */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM voucher_usages WHERE voucher_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM vouchers WHERE id = ?")->execute([$id]);
                $pdo->commit();
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa voucher #$id"];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi xóa: ' . $e->getMessage()];
            }
        }
        header('Location: vouchers.php'); exit;
    }

    /* ============================================================
       RESET LƯỢT DÙNG
       ============================================================ */
    if ($action === 'reset') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && function_exists('resetVoucherUses')) {
            if (resetVoucherUses($pdo, $id)) {
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã reset lượt dùng voucher #$id"];
            } else {
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Không thể reset.'];
            }
        }
        header('Location: vouchers.php'); exit;
    }

    /* ============================================================
       LƯU (THÊM / SỬA)
       ============================================================ */
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $code        = strtoupper(trim($_POST['code'] ?? ''));
        $type        = in_array($_POST['type'] ?? '', ['birthday','public','manual'], true) ? $_POST['type'] : 'public';
        $percent     = max(1, min(100, (int)($_POST['discount_percent'] ?? 10)));
        $maxDiscount = !empty($_POST['max_discount_amount']) ? (float)$_POST['max_discount_amount'] : null;
        $minOrder    = max(0, (float)($_POST['min_order_amount'] ?? 0));
        $validFrom   = $_POST['valid_from'] ?? date('Y-m-d');
        $validTo     = $_POST['valid_to']   ?? date('Y-m-d', strtotime('+30 days'));
        $userId      = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $usageLimit  = max(0, (int)($_POST['usage_limit'] ?? 1));

        /* ---- Validate ---- */
        if ($code === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập mã voucher.'];
            header('Location: vouchers.php'); exit;
        }
        if (!preg_match('/^[A-Z0-9\-_]+$/', $code)) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Mã chỉ được chứa chữ HOA, số, gạch ngang, gạch dưới.'];
            header('Location: vouchers.php'); exit;
        }
        if ($validFrom > $validTo) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Ngày bắt đầu phải trước ngày kết thúc.'];
            header('Location: vouchers.php'); exit;
        }

        /* Check trùng code (trừ chính nó) */
        $dupCheck = $pdo->prepare("SELECT id FROM vouchers WHERE code = ? AND id != ?");
        $dupCheck->execute([$code, $id]);
        if ($dupCheck->fetch()) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => "Mã '$code' đã tồn tại."];
            header('Location: vouchers.php'); exit;
        }

        if ($id > 0) {
            /* ---- UPDATE ---- */
            $pdo->prepare("
                UPDATE vouchers
                SET code=?, type=?, discount_percent=?, max_discount_amount=?,
                    min_order_amount=?, valid_from=?, valid_to=?, user_id=?, usage_limit=?
                WHERE id=?
            ")->execute([
                $code, $type, $percent, $maxDiscount, $minOrder,
                $validFrom, $validTo, $userId, $usageLimit, $id
            ]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật voucher $code"];
        } else {
            /* ---- INSERT ---- */
            $pdo->prepare("
                INSERT INTO vouchers
                    (code, type, discount_percent, max_discount_amount, min_order_amount,
                     valid_from, valid_to, user_id, used, usage_limit, used_count, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, NOW())
            ")->execute([
                $code, $type, $percent, $maxDiscount, $minOrder,
                $validFrom, $validTo, $userId, $usageLimit
            ]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã thêm voucher $code"];
        }
        header('Location: vouchers.php'); exit;
    }
}

/* ============================================================
   FILTER + LOAD DỮ LIỆU
   ============================================================ */
$filter = $_GET['status'] ?? '';
$where  = "WHERE 1=1";

/* ✅ Filter mới hỗ trợ multi-use voucher */
if ($filter === 'active') {
    $where .= " AND valid_from <= CURDATE() AND valid_to >= CURDATE()
                AND (usage_limit = 0 OR used_count < usage_limit)";
} elseif ($filter === 'expired') {
    $where .= " AND valid_to < CURDATE()";
} elseif ($filter === 'used') {
    $where .= " AND usage_limit > 0 AND used_count >= usage_limit";
} elseif ($filter === 'upcoming') {
    $where .= " AND valid_from > CURDATE()";
} elseif ($filter === 'unlimited') {
    $where .= " AND usage_limit = 0";
}

$stmt = $pdo->query("
    SELECT v.*, u.full_name AS user_name, u.email AS user_email,
           (SELECT COUNT(*) FROM voucher_usages vu WHERE vu.voucher_id = v.id AND vu.status = 'applied') AS total_usages
    FROM vouchers v
    LEFT JOIN users u ON v.user_id = u.id
    $where
    ORDER BY v.created_at DESC
    LIMIT 200
");
$vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Danh sách user để gán voucher (chỉ query 1 lần) */
$userList = $pdo->query("SELECT id, full_name, email FROM users ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   ĐẾM VOUCHER THEO TRẠNG THÁI (cho tab bar)
   ============================================================ */
$countAll = $countActive = $countUsed = $countExpired = $countUpcoming = $countUnlimited = 0;

try {
    $countAll = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();

    $countActive = (int)$pdo->query("
        SELECT COUNT(*) FROM vouchers
        WHERE valid_from <= CURDATE() AND valid_to >= CURDATE()
          AND (usage_limit = 0 OR used_count < usage_limit)
    ")->fetchColumn();

    $countUsed = (int)$pdo->query("
        SELECT COUNT(*) FROM vouchers
        WHERE usage_limit > 0 AND used_count >= usage_limit
    ")->fetchColumn();

    $countExpired = (int)$pdo->query("
        SELECT COUNT(*) FROM vouchers WHERE valid_to < CURDATE()
    ")->fetchColumn();

    $countUpcoming = (int)$pdo->query("
        SELECT COUNT(*) FROM vouchers WHERE valid_from > CURDATE()
    ")->fetchColumn();

    $countUnlimited = (int)$pdo->query("
        SELECT COUNT(*) FROM vouchers WHERE usage_limit = 0
    ")->fetchColumn();

} catch (Exception $e) {
    /* Fallback nếu cột mới chưa tồn tại */
    error_log('[vouchers.php counts] ' . $e->getMessage());

    try {
        $countAll       = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
        $countActive    = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE used = 0 AND valid_from <= CURDATE() AND valid_to >= CURDATE()")->fetchColumn();
        $countUsed      = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE used = 1")->fetchColumn();
        $countExpired   = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE valid_to < CURDATE()")->fetchColumn();
        $countUpcoming  = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE valid_from > CURDATE()")->fetchColumn();
        $countUnlimited = 0;
    } catch (Exception $e2) {}
}

/* ✅ FIX: Định nghĩa $typeLabels */
$typeLabels = [
    'birthday' => '🎂 Sinh nhật',
    'public'   => '🌐 Công khai',
    'manual'   => '✍️ Thủ công',
];
?>

<style>
/* ============================================================
   MARKETING TABS — fallback nếu chưa có trong admin_header
   ============================================================ */
.mkt-tabs {
    display: flex;
    gap: 6px;
    padding: 6px;
    background: var(--admin-card);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    margin-bottom: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.mkt-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: transparent;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--admin-text-muted);
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
}
.mkt-tab:hover { background: #fbf7ef; color: var(--admin-text); }
.mkt-tab.active {
    background: var(--admin-sidebar-bg);
    color: #fffdf9;
    box-shadow: 0 2px 8px rgba(28, 27, 25, 0.15);
}
.mkt-tab .icon { font-size: 1.05rem; }
.mkt-tab .cnt {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    background: rgba(0, 0, 0, 0.08);
    color: inherit;
    border-radius: 999px;
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
}
.mkt-tab.active .cnt { background: rgba(255, 255, 255, 0.22); }

/* ============================================================
   FILTER TABS
   ============================================================ */
.vch-filters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding: 12px 16px;
    background: var(--admin-card);
    border: 1px solid var(--admin-border);
    border-radius: 10px;
    margin-bottom: 14px;
}
.vch-filter {
    padding: 8px 14px;
    background: #fff;
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--admin-text);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
}
.vch-filter:hover { background: #fbf7ef; border-color: #d4c9b3; }
.vch-filter.active {
    background: var(--admin-accent);
    border-color: var(--admin-accent);
    color: #fff;
}
.vch-filter .cnt {
    background: rgba(0, 0, 0, 0.08);
    padding: 1px 7px;
    border-radius: 999px;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.7rem;
    font-weight: 700;
}
.vch-filter.active .cnt { background: rgba(255, 255, 255, 0.25); }

/* ============================================================
   USAGE CELL + PROGRESS BAR
   ============================================================ */
.vch-usage-cell {
    text-align: center;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.85rem;
}
.vch-usage-cell b {
    display: block;
    font-size: 1rem;
}
.vch-usage-cell small {
    display: block;
    font-size: 0.68rem;
    color: var(--admin-text-muted);
    margin-top: 2px;
    letter-spacing: 0.03em;
}
.vch-progress {
    width: 60px;
    height: 4px;
    background: #f0e9dc;
    border-radius: 999px;
    overflow: hidden;
    margin: 4px auto 0;
}
.vch-progress__fill {
    height: 100%;
    background: #a8461f;
    border-radius: 999px;
    transition: width 0.3s;
}
.vch-progress__fill.is-full { background: #b91c1c; }
.vch-progress__fill.is-low  { background: #c2410c; }

/* ============================================================
   ACTIONS
   ============================================================ */
.vch-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.vch-actions .btn-adm {
    padding: 6px 10px;
    font-size: 0.72rem;
}

/* ============================================================
   PRESET BUTTONS
   ============================================================ */
.vch-preset {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    flex-wrap: wrap;
}
.vch-preset button {
    padding: 4px 10px;
    background: #fff;
    border: 1px solid var(--admin-border);
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
}
.vch-preset button:hover {
    background: #a8461f;
    color: #fff;
    border-color: #a8461f;
}

@media (max-width: 600px) {
    .mkt-tab, .vch-filter { padding: 9px 12px; font-size: 0.78rem; }
}
</style>

<div class="admin-page-head">
    <div>
        <h1>Voucher / Mã giảm giá</h1>
        <div class="subtitle">
            Tổng <b><?php echo $countAll; ?></b> voucher
            <?php if ($countUnlimited > 0): ?>
                · <b style="color:#1d4ed8;"><?php echo $countUnlimited; ?></b> không giới hạn
            <?php endif; ?>
        </div>
    </div>
    <button type="button" class="btn-adm btn-adm--primary" onclick="openVoucherModal()">
        + Thêm voucher
    </button>
</div>

<!-- TAB: Marketing -->
<div class="mkt-tabs">
    <a href="discount-events.php" class="mkt-tab">
        <span class="icon">🎉</span>
        Sự kiện giảm giá
        <?php
        $__evCount = 0;
        try {
            $__evCount = (int)$pdo->query("SELECT COUNT(*) FROM discount_events")->fetchColumn();
        } catch (Exception $e) {}
        if ($__evCount > 0):
        ?>
            <span class="cnt"><?php echo $__evCount; ?></span>
        <?php endif; ?>
    </a>
    <a href="vouchers.php" class="mkt-tab active">
        <span class="icon">🎟️</span>
        Voucher / Mã giảm giá
        <span class="cnt"><?php echo $countAll; ?></span>
    </a>
</div>

<!-- FILTER TABS -->
<div class="vch-filters">
    <a href="vouchers.php" class="vch-filter <?php echo $filter === '' ? 'active' : ''; ?>">
        📋 Tất cả <span class="cnt"><?php echo $countAll; ?></span>
    </a>
    <a href="vouchers.php?status=active" class="vch-filter <?php echo $filter === 'active' ? 'active' : ''; ?>">
        ✅ Đang hoạt động <span class="cnt"><?php echo $countActive; ?></span>
    </a>
    <a href="vouchers.php?status=used" class="vch-filter <?php echo $filter === 'used' ? 'active' : ''; ?>">
        🚫 Hết lượt <span class="cnt"><?php echo $countUsed; ?></span>
    </a>
    <a href="vouchers.php?status=upcoming" class="vch-filter <?php echo $filter === 'upcoming' ? 'active' : ''; ?>">
        ⏳ Sắp tới <span class="cnt"><?php echo $countUpcoming; ?></span>
    </a>
    <a href="vouchers.php?status=expired" class="vch-filter <?php echo $filter === 'expired' ? 'active' : ''; ?>">
        ⏰ Hết hạn <span class="cnt"><?php echo $countExpired; ?></span>
    </a>
    <a href="vouchers.php?status=unlimited" class="vch-filter <?php echo $filter === 'unlimited' ? 'active' : ''; ?>">
        ∞ Không giới hạn <span class="cnt"><?php echo $countUnlimited; ?></span>
    </a>
</div>

<!-- TABLE -->
<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($vouchers)): ?>
        <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">
            Không có voucher nào phù hợp với bộ lọc
        </p>
    <?php else: ?>
        <table class="adm-table">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Mã</th>
                    <th>Loại</th>
                    <th>Giảm</th>
                    <th>Đơn tối thiểu</th>
                    <th>Hiệu lực</th>
                    <th style="width:110px;text-align:center;">Lượt dùng</th>
                    <th>Người dùng</th>
                    <th style="width:110px;">Trạng thái</th>
                    <th style="width:200px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vouchers as $v):
                    $today      = date('Y-m-d');
                    $usageLimit = (int)($v['usage_limit'] ?? 1);
                    $usedCount  = (int)($v['used_count']  ?? 0);
                    $totalUse   = (int)($v['total_usages'] ?? 0);

                    /* ---- Trạng thái ---- */
                    if ($v['valid_to'] < $today) {
                        $statusBadge = '<span class="adm-badge adm-badge--red">Hết hạn</span>';
                    } elseif ($v['valid_from'] > $today) {
                        $statusBadge = '<span class="adm-badge adm-badge--orange">Sắp tới</span>';
                    } elseif ($usageLimit > 0 && $usedCount >= $usageLimit) {
                        $statusBadge = '<span class="adm-badge adm-badge--gray">Hết lượt</span>';
                    } else {
                        $statusBadge = '<span class="adm-badge adm-badge--green">Hoạt động</span>';
                    }
                ?>
                    <tr>
                        <td><b>#<?php echo (int)$v['id']; ?></b></td>

                        <td>
                            <code style="background:#f2ece0;padding:4px 8px;border-radius:4px;font-size:0.82rem;font-weight:700;">
                                <?php echo htmlspecialchars($v['code']); ?>
                            </code>
                        </td>

                        <td style="font-size:0.82rem;">
                            <?php echo $typeLabels[$v['type']] ?? htmlspecialchars($v['type']); ?>
                        </td>

                        <td>
                            <span class="adm-badge adm-badge--red">-<?php echo (int)$v['discount_percent']; ?>%</span>
                            <?php if (!empty($v['max_discount_amount'])): ?>
                                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:3px;">
                                    Tối đa <?php echo number_format($v['max_discount_amount'], 0, ',', '.'); ?>đ
                                </div>
                            <?php endif; ?>
                        </td>

                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.82rem;">
                            <?php echo number_format($v['min_order_amount'], 0, ',', '.'); ?>đ
                        </td>

                        <td style="font-family:'IBM Plex Mono',monospace;font-size:0.78rem;line-height:1.5;">
                            <?php echo date('d/m/Y', strtotime($v['valid_from'])); ?>
                            <br><span style="color:var(--admin-text-muted);">↓</span><br>
                            <?php echo date('d/m/Y', strtotime($v['valid_to'])); ?>
                        </td>

                        <!-- ✅ CỘT LƯỢT DÙNG -->
                        <td class="vch-usage-cell">
                            <?php if ($usageLimit === 0): ?>
                                <b style="color:#1d4ed8;font-size:1.3rem;">∞</b>
                                <small>đã dùng <?php echo $usedCount; ?></small>
                            <?php elseif ($usageLimit === 1): ?>
                                <?php if ($usedCount >= 1): ?>
                                    <b style="color:#b91c1c;">0/1</b>
                                    <small>đã dùng</small>
                                <?php else: ?>
                                    <b style="color:#166534;">1/1</b>
                                    <small>còn lại</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                $remaining = max(0, $usageLimit - $usedCount);
                                $color = $remaining === 0 ? '#b91c1c' : ($remaining <= 2 ? '#c2410c' : '#166534');
                                $pct = $usageLimit > 0 ? ($usedCount / $usageLimit * 100) : 0;
                                $fillCls = $remaining === 0 ? 'is-full' : ($remaining <= 2 ? 'is-low' : '');
                                ?>
                                <b style="color:<?php echo $color; ?>;">
                                    <?php echo $remaining; ?>/<?php echo $usageLimit; ?>
                                </b>
                                <small>còn lại</small>
                                <div class="vch-progress">
                                    <div class="vch-progress__fill <?php echo $fillCls; ?>" style="width:<?php echo $pct; ?>%;"></div>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td style="font-size:0.82rem;">
                            <?php if (!empty($v['user_name'])): ?>
                                <b><?php echo htmlspecialchars($v['user_name']); ?></b>
                                <div style="color:var(--admin-text-muted);font-size:0.72rem;">
                                    <?php echo htmlspecialchars($v['user_email']); ?>
                                </div>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--blue">Mọi người</span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo $statusBadge; ?></td>

                        <td>
                            <div class="vch-actions">
                                <button type="button" class="btn-adm btn-adm--edit"
                                        onclick='editVoucher(<?php echo json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    ✏️ Sửa
                                </button>

                                <?php if ($usedCount > 0): ?>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Reset lượt dùng voucher này về 0?\n\nSẽ xóa toàn bộ log sử dụng!');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="reset">
                                        <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                        <button type="submit" class="btn-adm btn-adm--ghost" title="Reset lượt dùng">
                                            🔄
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Xóa voucher này?\n\nSẽ xóa cả log sử dụng!');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                                    <button type="submit" class="btn-adm btn-adm--delete">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ============================================================
     MODAL THÊM/SỬA
     ============================================================ -->
<div class="adm-modal" id="voucherModal">
    <div class="adm-modal__box" style="max-width:620px;">
        <button type="button" class="adm-modal__close" onclick="closeVoucherModal()">×</button>
        <h2 class="adm-modal__title" id="voucherModalTitle">Thêm voucher</h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="v_id" value="">

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Mã voucher *</label>
                    <input type="text" name="code" id="v_code" class="adm-input" required maxlength="50"
                           placeholder="SALE20" style="text-transform:uppercase;font-family:'IBM Plex Mono',monospace;"
                           oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-_]/g, '')">
                </div>
                <div class="adm-form-group">
                    <label>Loại</label>
                    <select name="type" id="v_type" class="adm-select">
                        <option value="public">🌐 Công khai</option>
                        <option value="birthday">🎂 Sinh nhật</option>
                        <option value="manual">✍️ Thủ công</option>
                    </select>
                </div>
            </div>

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Giảm giá (%) *</label>
                    <input type="number" name="discount_percent" id="v_percent" class="adm-input"
                           min="1" max="100" value="10" required>
                </div>
                <div class="adm-form-group">
                    <label>Giảm tối đa (VNĐ)</label>
                    <input type="number" name="max_discount_amount" id="v_max" class="adm-input"
                           min="0" step="1000" placeholder="Bỏ trống = không giới hạn">
                </div>
            </div>

            <div class="adm-form-group">
                <label>Đơn tối thiểu (VNĐ)</label>
                <input type="number" name="min_order_amount" id="v_min" class="adm-input"
                       min="0" step="1000" value="0">
            </div>

            <!-- ✅ SỐ LƯỢT DÙNG -->
            <div class="adm-form-group" style="background:#faf7f1;padding:14px;border-radius:8px;border:1px dashed #e8e0d0;">
                <label style="color:#a8461f;font-weight:700;">🔢 Số lần sử dụng tối đa *</label>
                <input type="number" name="usage_limit" id="v_usage_limit" class="adm-input"
                       min="0" value="1" required
                       style="font-family:'IBM Plex Mono',monospace;font-size:1rem;font-weight:700;">

                <div class="vch-preset">
                    <button type="button" onclick="setUsageLimit(1)">Dùng 1 lần</button>
                    <button type="button" onclick="setUsageLimit(5)">Dùng 5 lần</button>
                    <button type="button" onclick="setUsageLimit(10)">Dùng 10 lần</button>
                    <button type="button" onclick="setUsageLimit(100)">Dùng 100 lần</button>
                    <button type="button" onclick="setUsageLimit(0)">∞ Không giới hạn</button>
                </div>

                <div style="font-size:0.75rem;color:var(--admin-text-muted);margin-top:10px;line-height:1.6;">
                    • <b>1</b> = voucher chỉ dùng <code>1 lần</code> (mặc định)<br>
                    • <b>5</b>, <b>10</b>... = voucher dùng được tối đa <code>N lần</code><br>
                    • <b>0</b> = <code>không giới hạn</code> lượt sử dụng
                </div>
            </div>

            <div class="adm-row">
                <div class="adm-form-group">
                    <label>Từ ngày *</label>
                    <input type="date" name="valid_from" id="v_from" class="adm-input" required>
                </div>
                <div class="adm-form-group">
                    <label>Đến ngày *</label>
                    <input type="date" name="valid_to" id="v_to" class="adm-input" required>
                </div>
            </div>

            <div class="adm-form-group">
                <label>Gán cho user (bỏ trống = mọi người)</label>
                <select name="user_id" id="v_user_id" class="adm-select">
                    <option value="">— Mọi người dùng —</option>
                    <?php foreach ($userList as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>">
                            #<?php echo (int)$u['id']; ?> — <?php echo htmlspecialchars($u['full_name'] ?: $u['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                <button type="button" class="btn-adm btn-adm--ghost"
                        style="flex:1;justify-content:center;padding:0.85rem"
                        onclick="closeVoucherModal()">
                    Hủy
                </button>
                <button type="submit" class="btn-adm btn-adm--primary"
                        style="flex:2;justify-content:center;padding:0.85rem">
                    💾 Lưu voucher
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var voucherModal = document.getElementById('voucherModal');

function openVoucherModal() {
    document.getElementById('voucherModalTitle').textContent = 'Thêm voucher mới';
    document.querySelector('#voucherModal form').reset();
    document.getElementById('v_id').value = '';
    document.getElementById('v_percent').value = 10;
    document.getElementById('v_min').value = 0;
    document.getElementById('v_max').value = '';
    document.getElementById('v_from').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('v_to').value   = '<?php echo date('Y-m-d', strtotime('+30 days')); ?>';
    document.getElementById('v_usage_limit').value = 1;
    voucherModal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editVoucher(v) {
    document.getElementById('voucherModalTitle').textContent = 'Sửa voucher #' + v.id;
    document.getElementById('v_id').value          = v.id;
    document.getElementById('v_code').value        = v.code;
    document.getElementById('v_type').value        = v.type;
    document.getElementById('v_percent').value     = v.discount_percent;
    document.getElementById('v_max').value         = v.max_discount_amount || '';
    document.getElementById('v_min').value         = v.min_order_amount || 0;
    document.getElementById('v_from').value        = v.valid_from;
    document.getElementById('v_to').value          = v.valid_to;
    document.getElementById('v_user_id').value     = v.user_id || '';
    document.getElementById('v_usage_limit').value = (v.usage_limit !== undefined && v.usage_limit !== null)
                                                    ? v.usage_limit : 1;
    voucherModal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeVoucherModal() {
    voucherModal.classList.remove('show');
    document.body.style.overflow = '';
}

voucherModal.addEventListener('click', function (e) {
    if (e.target === this) closeVoucherModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && voucherModal.classList.contains('show')) closeVoucherModal();
});

/* Preset buttons */
function setUsageLimit(n) {
    document.getElementById('v_usage_limit').value = n;
}
</script>

<?php include 'includes/admin_footer.php'; ?>