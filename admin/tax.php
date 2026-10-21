<?php
require_once '../includes/config.php';

/* ============================================================
   BẢO VỆ TRANG — CHỈ ADMIN
   ============================================================ */
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

include 'includes/admin_header.php';

/* ============================================================
   TẠO BẢNG NẾU CHƯA CÓ
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tax_settings (
            id INT PRIMARY KEY DEFAULT 1,
            tax_code        VARCHAR(50) NULL,
            business_name   VARCHAR(200) NULL,
            tax_office      VARCHAR(200) NULL,
            exempt_threshold DECIMAL(15,0) DEFAULT 500000000,
            vat_rate_goods  DECIMAL(5,2) DEFAULT 1.00,
            pit_rate_goods  DECIMAL(5,2) DEFAULT 0.50,
            declaration_period ENUM('monthly','quarterly','yearly') DEFAULT 'quarterly',
            is_registered   TINYINT(1) DEFAULT 0,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO tax_settings (id) VALUES (1)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tax_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_type   ENUM('month','quarter','year') NOT NULL,
            period_value  VARCHAR(20) NOT NULL,
            period_start  DATE NOT NULL,
            period_end    DATE NOT NULL,
            total_revenue DECIMAL(15,0) DEFAULT 0,
            exempt_amount DECIMAL(15,0) DEFAULT 0,
            taxable_revenue DECIMAL(15,0) DEFAULT 0,
            vat_amount    DECIMAL(15,0) DEFAULT 0,
            pit_amount    DECIMAL(15,0) DEFAULT 0,
            total_tax     DECIMAL(15,0) DEFAULT 0,
            status        ENUM('draft','declared','paid') DEFAULT 'draft',
            declared_at   DATETIME NULL,
            paid_at       DATETIME NULL,
            note          TEXT NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_period (period_type, period_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Tax table init error: ' . $e->getMessage());
}

/* ============================================================
   HELPER
   ============================================================ */
function fmtMoney($n) {
    return number_format((float)$n, 0, ',', '.') . 'đ';
}

function calcPeriodRange(string $type, string $value): array {
    // $value: "2026-01" (month) | "2026-Q1" (quarter) | "2026" (year)
    if ($type === 'month') {
        $ts = strtotime($value . '-01');
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    if ($type === 'quarter') {
        [$y, $q] = explode('-Q', $value);
        $y = (int)$y; $q = (int)$q;
        $startMonth = ($q - 1) * 3 + 1;
        $endMonth   = $startMonth + 2;
        return [
            sprintf('%04d-%02d-01', $y, $startMonth),
            date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $endMonth))),
        ];
    }
    // year
    $y = (int)$value;
    return ["$y-01-01", "$y-12-31"];
}

/* ============================================================
   XỬ LÝ POST
   ============================================================ */
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên làm việc hết hạn.'];
        header('Location: tax.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    /* ========== 1. LƯU CẤU HÌNH ========== */
    if ($action === 'save_settings') {
        $taxCode      = trim($_POST['tax_code'] ?? '');
        $businessName = trim($_POST['business_name'] ?? '');
        $taxOffice    = trim($_POST['tax_office'] ?? '');
        $threshold    = max(0, (float)($_POST['exempt_threshold'] ?? 500000000));
        $vatRate      = max(0, min(20, (float)($_POST['vat_rate_goods'] ?? 1)));
        $pitRate      = max(0, min(20, (float)($_POST['pit_rate_goods'] ?? 0.5)));
        $period       = $_POST['declaration_period'] ?? 'quarterly';
        $isRegistered = !empty($_POST['is_registered']) ? 1 : 0;

        if (!in_array($period, ['monthly','quarterly','yearly'], true)) {
            $period = 'quarterly';
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE tax_settings SET
                    tax_code = ?, business_name = ?, tax_office = ?,
                    exempt_threshold = ?, vat_rate_goods = ?, pit_rate_goods = ?,
                    declaration_period = ?, is_registered = ?, updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                $taxCode ?: null, $businessName ?: null, $taxOffice ?: null,
                $threshold, $vatRate, $pitRate, $period, $isRegistered,
            ]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => '✅ Đã lưu cấu hình thuế'];
        } catch (Exception $e) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi: ' . $e->getMessage()];
        }
        header('Location: tax.php'); exit;
    }

    /* ========== 2. TẠO BÁO CÁO THUẾ ========== */
    if ($action === 'create_report') {
        $periodType  = $_POST['period_type'] ?? 'quarter';
        $periodValue = trim($_POST['period_value'] ?? '');

        if (!in_array($periodType, ['month','quarter','year'], true)) {
            $periodType = 'quarter';
        }
        if ($periodValue === '') {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Vui lòng chọn kỳ kê khai.'];
            header('Location: tax.php'); exit;
        }

        [$start, $end] = calcPeriodRange($periodType, $periodValue);

        // Lấy settings
        $settings = $pdo->query("SELECT * FROM tax_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $threshold = (float)($settings['exempt_threshold'] ?? 500000000);
        $vatRate   = (float)($settings['vat_rate_goods']   ?? 1);
        $pitRate   = (float)($settings['pit_rate_goods']   ?? 0.5);

        // Doanh thu kỳ này
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM orders
            WHERE status IN ('delivered', 'shipped', 'confirmed')
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$start, $end]);
        $periodRevenue = (float)$stmt->fetchColumn();

        // Doanh thu cả năm của kỳ (để xác định ngưỡng)
        $year = (int)date('Y', strtotime($start));
        $stmtY = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM orders
            WHERE status IN ('delivered', 'shipped', 'confirmed')
              AND YEAR(created_at) = ?
        ");
        $stmtY->execute([$year]);
        $yearRevenue = (float)$stmtY->fetchColumn();

        // Tính thuế
        if ($yearRevenue <= $threshold) {
            // Cả năm chưa vượt ngưỡng → miễn thuế
            $exemptAmount   = $periodRevenue;
            $taxableRevenue = 0;
        } else {
            // Vượt ngưỡng → tính trên phần vượt (phân bổ tỷ lệ theo kỳ)
            // Đơn giản: tính tỷ lệ doanh thu kỳ / doanh thu năm × phần vượt
            $overAmount = $yearRevenue - $threshold;
            if ($yearRevenue > 0) {
                $shareRatio = $periodRevenue / $yearRevenue;
            } else {
                $shareRatio = 0;
            }
            $taxableRevenue = round($overAmount * $shareRatio);
            $exemptAmount   = $periodRevenue - $taxableRevenue;
        }

        $vatAmount = round($taxableRevenue * $vatRate / 100);
        $pitAmount = round($taxableRevenue * $pitRate / 100);
        $totalTax  = $vatAmount + $pitAmount;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO tax_reports
                    (period_type, period_value, period_start, period_end,
                     total_revenue, exempt_amount, taxable_revenue,
                     vat_amount, pit_amount, total_tax, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW())
                ON DUPLICATE KEY UPDATE
                    total_revenue = VALUES(total_revenue),
                    exempt_amount = VALUES(exempt_amount),
                    taxable_revenue = VALUES(taxable_revenue),
                    vat_amount = VALUES(vat_amount),
                    pit_amount = VALUES(pit_amount),
                    total_tax = VALUES(total_tax)
            ");
            $stmt->execute([
                $periodType, $periodValue, $start, $end,
                $periodRevenue, $exemptAmount, $taxableRevenue,
                $vatAmount, $pitAmount, $totalTax,
            ]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => '✅ Đã tạo/cập nhật báo cáo kỳ ' . $periodValue];
        } catch (Exception $e) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi: ' . $e->getMessage()];
        }
        header('Location: tax.php'); exit;
    }

    /* ========== 3. ĐỔI TRẠNG THÁI BÁO CÁO ========== */
    if ($action === 'update_status') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $status   = $_POST['status'] ?? 'draft';
        if (!in_array($status, ['draft','declared','paid'], true)) $status = 'draft';

        $declaredAt = ($status === 'declared') ? date('Y-m-d H:i:s') : null;
        $paidAt     = ($status === 'paid')     ? date('Y-m-d H:i:s') : null;

        try {
            $pdo->prepare("
                UPDATE tax_reports
                SET status = ?, declared_at = COALESCE(?, declared_at), paid_at = COALESCE(?, paid_at)
                WHERE id = ?
            ")->execute([$status, $declaredAt, $paidAt, $reportId]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => '✅ Đã cập nhật trạng thái'];
        } catch (Exception $e) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi: ' . $e->getMessage()];
        }
        header('Location: tax.php'); exit;
    }

    /* ========== 4. XÓA BÁO CÁO ========== */
    if ($action === 'delete_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tax_reports WHERE id = ?")->execute([$reportId]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => '🗑 Đã xóa báo cáo'];
        } catch (Exception $e) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Lỗi: ' . $e->getMessage()];
        }
        header('Location: tax.php'); exit;
    }
}

/* ============================================================
   LẤY DỮ LIỆU HIỂN THỊ
   ============================================================ */
$settings = $pdo->query("SELECT * FROM tax_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$threshold = (float)($settings['exempt_threshold'] ?? 500000000);
$vatRate   = (float)($settings['vat_rate_goods']   ?? 1);
$pitRate   = (float)($settings['pit_rate_goods']   ?? 0.5);

// Doanh thu năm hiện tại
$currentYear = (int)date('Y');
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE status IN ('delivered', 'shipped', 'confirmed')
      AND YEAR(created_at) = ?
");
$stmt->execute([$currentYear]);
$yearRevenue = (float)$stmt->fetchColumn();

// Doanh thu tháng hiện tại
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE status IN ('delivered', 'shipped', 'confirmed')
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
");
$stmt->execute([(int)date('m'), $currentYear]);
$monthRevenue = (float)$stmt->fetchColumn();

// % ngưỡng đã dùng
$usedPct = $threshold > 0 ? min(100, $yearRevenue / $threshold * 100) : 0;

// Phân loại màu progress bar
$barColor = '#16a34a';
$barLabel = 'An toàn';
if ($usedPct >= 100)      { $barColor = '#dc2626'; $barLabel = 'ĐÃ VƯỢT NGƯỠNG — Phải nộp thuế'; }
elseif ($usedPct >= 80)   { $barColor = '#d97706'; $barLabel = 'Sắp chạm ngưỡng — Cần chú ý'; }
elseif ($usedPct >= 50)   { $barColor = '#0891b2'; $barLabel = 'Đang trong vùng an toàn'; }

// Lịch sử báo cáo
$reports = $pdo->query("
    SELECT * FROM tax_reports ORDER BY period_start DESC, id DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Tổng đã nộp năm nay
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_tax), 0)
    FROM tax_reports
    WHERE status = 'paid' AND YEAR(period_start) = ?
");
$stmt->execute([$currentYear]);
$yearPaidTax = (float)$stmt->fetchColumn();

// Gợi ý kỳ tiếp theo
$nextValue = '';
$nextType  = 'quarter';
if ($usedPct >= 100) {
    $nextType = 'quarter';
    $q = ceil((int)date('m') / 3);
    $nextValue = $currentYear . '-Q' . $q;
}

$statusMap = [
    'draft'    => ['label' => 'Nháp',       'bg' => '#f3f4f6', 'color' => '#4b5563'],
    'declared' => ['label' => 'Đã kê khai', 'bg' => '#dbeafe', 'color' => '#2563eb'],
    'paid'     => ['label' => 'Đã nộp',     'bg' => '#dcfce7', 'color' => '#16a34a'],
];
?>

<div class="admin-page-head">
    <div>
        <h1>Quản lý thuế</h1>
        <div class="subtitle">
            Theo dõi doanh thu, tính thuế GTGT + TNCN và quản lý lịch sử kê khai
        </div>
    </div>
    <div>
        <button type="button" class="btn-adm btn-adm--ghost" onclick="document.getElementById('helpBox').toggleAttribute('hidden')">
            ❓ Hướng dẫn
        </button>
    </div>
</div>

<?php if ($flash): ?>
    <div style="padding:12px 16px;border-radius:8px;margin-bottom:20px;
                background:<?php echo $flash['type'] === 'error' ? '#fee2e2' : '#ecfdf5'; ?>;
                color:<?php echo $flash['type'] === 'error' ? '#b91c1c' : '#047857'; ?>;">
        <?php echo htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- BOX HƯỚNG DẪN                                                 -->
<!-- ============================================================ -->
<div id="helpBox" hidden style="background:#eff6ff;border-left:4px solid #1d4ed8;
     padding:1.25rem 1.5rem;border-radius:8px;margin-bottom:1.5rem;line-height:1.7;font-size:0.9rem">
    <h3 style="margin:0 0 0.75rem;font-family:'Fraunces',serif;font-size:1.05rem;color:#1e3a8a">
        📘 Hiểu nhanh về thuế hộ kinh doanh
    </h3>
    <ul style="margin:0;padding-left:1.25rem;color:#1e3a8a">
        <li><b>Ngưỡng miễn thuế:</b> Doanh thu &lt; 500 triệu/năm → miễn cả GTGT và TNCN.</li>
        <li><b>Vượt 500 triệu:</b> Nộp <b>1% GTGT + 0,5% TNCN = 1,5%</b> trên phần doanh thu vượt ngưỡng.</li>
        <li><b>Kê khai tại:</b> <a href="https://thuedientu.gdt.gov.vn" target="_blank" style="color:#1d4ed8;font-weight:600">thuedientu.gdt.gov.vn</a> (phân hệ CÁ NHÂN) hoặc app <b>eTax Mobile</b>.</li>
        <li><b>Tần suất:</b> Theo quý (mặc định) hoặc theo tháng.</li>
        <li><b>Doanh thu tính thuế:</b> Chỉ tính đơn <b>delivered / shipped / confirmed</b> — không tính đơn cancelled.</li>
        <li><b>Lưu ý:</b> Nếu bán trên Shopee/Lazada, sàn đã khấu trừ và nộp thay — chỉ kê khai phần bán trên website riêng.</li>
    </ul>
</div>

<!-- ============================================================ -->
<!-- SECTION 1: DASHBOARD DOANH THU + NGƯỠNG                        -->
<!-- ============================================================ -->
<div class="admin-card" style="padding:1.75rem;margin-bottom:1.25rem;">
    <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;
               margin:0 0 1.5rem;padding-bottom:0.75rem;
               border-bottom:1px solid var(--admin-border);">
         Tổng quan doanh thu <?php echo $currentYear; ?>
    </h2>

    <!-- Stat cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.75rem;">

        <div style="background:#f8f9fa;padding:1.1rem 1.25rem;border-radius:8px;border:1px solid var(--admin-border);">
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;
                        color:var(--admin-text-muted);margin-bottom:0.4rem;">
                Doanh thu năm nay
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.35rem;
                        font-weight:700;color:#1c1b19;">
                <?php echo fmtMoney($yearRevenue); ?>
            </div>
        </div>

        <div style="background:#f8f9fa;padding:1.1rem 1.25rem;border-radius:8px;border:1px solid var(--admin-border);">
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;
                        color:var(--admin-text-muted);margin-bottom:0.4rem;">
                Doanh thu tháng <?php echo date('m/Y'); ?>
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.35rem;
                        font-weight:700;color:#1c1b19;">
                <?php echo fmtMoney($monthRevenue); ?>
            </div>
        </div>

        <div style="background:#f8f9fa;padding:1.1rem 1.25rem;border-radius:8px;border:1px solid var(--admin-border);">
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;
                        color:var(--admin-text-muted);margin-bottom:0.4rem;">
                Ngưỡng miễn thuế
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.35rem;
                        font-weight:700;color:#1c1b19;">
                <?php echo fmtMoney($threshold); ?>
            </div>
        </div>

        <div style="background:#f8f9fa;padding:1.1rem 1.25rem;border-radius:8px;border:1px solid var(--admin-border);">
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;
                        color:var(--admin-text-muted);margin-bottom:0.4rem;">
                Thuế đã nộp <?php echo $currentYear; ?>
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:1.35rem;
                        font-weight:700;color:#16a34a;">
                <?php echo fmtMoney($yearPaidTax); ?>
            </div>
        </div>

    </div>

    <!-- Progress bar -->
    <div style="background:#f8f9fa;padding:1.25rem;border-radius:8px;border:1px solid var(--admin-border);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem;">
            <div style="font-size:0.85rem;font-weight:600;color:#1c1b19;">
                Tiến độ ngưỡng miễn thuế
            </div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:0.85rem;font-weight:700;
                        color:<?php echo $barColor; ?>;">
                <?php echo number_format($usedPct, 1); ?>% — <?php echo $barLabel; ?>
            </div>
        </div>

        <div style="height:14px;background:#e5e7eb;border-radius:7px;overflow:hidden;">
            <div style="height:100%;width:<?php echo min(100, $usedPct); ?>%;
                        background:<?php echo $barColor; ?>;
                        transition:width 0.6s ease;border-radius:7px;"></div>
        </div>

        <div style="display:flex;justify-content:space-between;margin-top:0.6rem;
                    font-family:'IBM Plex Mono',monospace;font-size:0.78rem;
                    color:var(--admin-text-muted);">
            <span>0đ</span>
            <span>Còn lại: <b style="color:<?php echo $barColor; ?>">
                <?php echo fmtMoney(max(0, $threshold - $yearRevenue)); ?>
            </b></span>
            <span><?php echo fmtMoney($threshold); ?></span>
        </div>
    </div>

    <?php if ($usedPct >= 100): ?>
        <div style="margin-top:1.25rem;padding:1rem 1.25rem;background:#fef2f2;
                    border-left:4px solid #dc2626;border-radius:6px;color:#7f1d1d;font-size:0.9rem;line-height:1.7">
             <b>Đã vượt ngưỡng miễn thuế!</b> Bạn cần kê khai và nộp thuế
            <b><?php echo $vatRate; ?>% GTGT + <?php echo $pitRate; ?>% TNCN</b>
            trên phần doanh thu vượt <?php echo fmtMoney($threshold); ?>.
        </div>
    <?php elseif ($usedPct >= 80): ?>
        <div style="margin-top:1.25rem;padding:1rem 1.25rem;background:#fffbeb;
                    border-left:4px solid #d97706;border-radius:6px;color:#78350f;font-size:0.9rem;line-height:1.7">
            ⚠️ <b>Sắp chạm ngưỡng!</b> Doanh thu đã đạt <?php echo number_format($usedPct, 1); ?>%.
            Chuẩn bị kê khai khi vượt 500 triệu.
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- SECTION 2: CẤU HÌNH THUẾ                                       -->
<!-- ============================================================ -->
<div class="admin-card" style="padding:1.75rem;margin-bottom:1.25rem;">
    <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;
               margin:0 0 1.5rem;padding-bottom:0.75rem;
               border-bottom:1px solid var(--admin-border);">
         Cấu hình thuế
    </h2>

    <form method="POST" action="tax.php">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="save_settings">

        <div class="adm-row">
            <div class="adm-form-group">
                <label>Mã số thuế</label>
                <input type="text" name="tax_code" class="adm-input"
                       value="<?php echo htmlspecialchars($settings['tax_code'] ?? ''); ?>"
                       placeholder="VD: 0123456789" maxlength="50">
            </div>
            <div class="adm-form-group">
                <label>Tên hộ kinh doanh</label>
                <input type="text" name="business_name" class="adm-input"
                       value="<?php echo htmlspecialchars($settings['business_name'] ?? ''); ?>"
                       placeholder="Hộ KD THE ATELIER" maxlength="200">
            </div>
        </div>

        <div class="adm-form-group">
            <label>Chi cục thuế quản lý</label>
            <input type="text" name="tax_office" class="adm-input"
                   value="<?php echo htmlspecialchars($settings['tax_office'] ?? ''); ?>"
                   placeholder="Chi cục Thuế Quận 1, TP.HCM" maxlength="200">
        </div>

        <div class="adm-row">
            <div class="adm-form-group">
                <label>Ngưỡng miễn thuế (đ/năm)</label>
                <input type="number" name="exempt_threshold" class="adm-input"
                       value="<?php echo (int)$threshold; ?>" min="0" step="1000000">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    Mặc định: 500.000.000đ (theo luật 2025)
                </div>
            </div>
            <div class="adm-form-group">
                <label>Kỳ kê khai</label>
                <select name="declaration_period" class="adm-select">
                    <?php
                    $periodOpts = [
                        'monthly'   => 'Hàng tháng',
                        'quarterly' => 'Hàng quý (khuyên dùng)',
                        'yearly'    => 'Hàng năm',
                    ];
                    $curPeriod = $settings['declaration_period'] ?? 'quarterly';
                    foreach ($periodOpts as $k => $v):
                    ?>
                        <option value="<?php echo $k; ?>" <?php echo $curPeriod === $k ? 'selected' : ''; ?>>
                            <?php echo $v; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="adm-row">
            <div class="adm-form-group">
                <label>Thuế suất GTGT (%) — hàng hóa</label>
                <input type="number" name="vat_rate_goods" class="adm-input"
                       value="<?php echo (float)$vatRate; ?>" min="0" max="20" step="0.1">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    Hàng hóa: <b>1%</b>
                </div>
            </div>
            <div class="adm-form-group">
                <label>Thuế suất TNCN (%) — hàng hóa</label>
                <input type="number" name="pit_rate_goods" class="adm-input"
                       value="<?php echo (float)$pitRate; ?>" min="0" max="20" step="0.1">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px;">
                    Hàng hóa: <b>0,5%</b>
                </div>
            </div>
        </div>

        <div class="adm-form-group" style="margin-top:0.5rem">
            <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer">
                <input type="checkbox" name="is_registered" value="1"
                       <?php echo !empty($settings['is_registered']) ? 'checked' : ''; ?>
                       style="width:18px;height:18px;cursor:pointer">
                <span>Đã đăng ký mã số thuế với cơ quan thuế</span>
            </label>
        </div>

        <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;gap:0.75rem">
            <button type="submit" class="btn-adm btn-adm--primary" style="padding:0.85rem 2rem">
                 Lưu cấu hình
            </button>
        </div>
    </form>
</div>

<!-- ============================================================ -->
<!-- SECTION 3: TÍNH THUẾ KỲ                                        -->
<!-- ============================================================ -->
<div class="admin-card" style="padding:1.75rem;margin-bottom:1.25rem;">
    <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;
               margin:0 0 1.5rem;padding-bottom:0.75rem;
               border-bottom:1px solid var(--admin-border);">
         Tạo báo cáo thuế theo kỳ
    </h2>

    <form method="POST" action="tax.php" id="createReportForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="create_report">

        <div class="adm-row">
            <div class="adm-form-group">
                <label>Loại kỳ</label>
                <select name="period_type" id="periodType" class="adm-select">
                    <option value="month"   <?php echo $nextType === 'month'   ? 'selected' : ''; ?>>Theo tháng</option>
                    <option value="quarter" <?php echo $nextType === 'quarter' ? 'selected' : ''; ?>>Theo quý</option>
                    <option value="year"    <?php echo $nextType === 'year'    ? 'selected' : ''; ?>>Theo năm</option>
                </select>
            </div>
            <div class="adm-form-group">
                <label>Kỳ kê khai</label>
                <input type="text" name="period_value" id="periodValue" class="adm-input"
                       value="<?php echo htmlspecialchars($nextValue); ?>"
                       placeholder="VD: 2026-Q1 hoặc 2026-01 hoặc 2026">
                <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:4px">
                    Định dạng: <b>YYYY-MM</b> (tháng) · <b>YYYY-QN</b> (quý) · <b>YYYY</b> (năm)
                </div>
            </div>
        </div>

        <div style="background:#f8f9fa;padding:1rem 1.25rem;border-radius:6px;
                    border:1px solid var(--admin-border);margin-bottom:1rem;
                    font-size:0.85rem;color:var(--admin-text-muted);line-height:1.7">
            <b style="color:#1c1b19">💡 Cách tính:</b><br>
            1. Tính tổng doanh thu <b>cả năm</b> có chứa kỳ → so sánh với ngưỡng <?php echo fmtMoney($threshold); ?><br>
            2. Nếu năm chưa vượt ngưỡng → miễn thuế, báo cáo vẫn tạo nhưng thuế = 0<br>
            3. Nếu năm đã vượt → tính phần vượt, phân bổ theo tỷ lệ doanh thu kỳ/năm<br>
            4. Thuế GTGT = Doanh thu tính thuế × <?php echo $vatRate; ?>% ·
               TNCN = Doanh thu tính thuế × <?php echo $pitRate; ?>%
        </div>

        <div style="display:flex;justify-content:flex-end;gap:0.75rem">
            <button type="submit" class="btn-adm btn-adm--primary" style="padding:0.85rem 2rem">
                 Tính & lưu báo cáo
            </button>
        </div>
    </form>

    <div id="previewBox" style="display:none;margin-top:1.5rem;padding:1.25rem;
                background:#fffbeb;border-left:4px solid #d97706;border-radius:6px">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;
                    color:#92400e;margin-bottom:0.5rem;font-weight:700">
            Xem trước kết quả
        </div>
        <div id="previewContent" style="font-size:0.9rem;color:#1c1b19;line-height:1.9"></div>
    </div>
</div>

<!-- ============================================================ -->
<!-- SECTION 4: LỊCH SỬ BÁO CÁO                                     -->
<!-- ============================================================ -->
<div class="admin-card" style="padding:1.75rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;
                margin-bottom:1.5rem;padding-bottom:0.75rem;
                border-bottom:1px solid var(--admin-border);flex-wrap:wrap;gap:0.75rem">
        <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;margin:0">
             Lịch sử kê khai
        </h2>
        <button type="button" class="btn-adm btn-adm--ghost" onclick="exportCsv()">
             Xuất CSV
        </button>
    </div>

    <?php if (empty($reports)): ?>
        <div style="text-align:center;padding:3rem 1rem;color:var(--admin-text-muted)">
            <div style="font-size:3rem;opacity:0.35;margin-bottom:0.5rem">📭</div>
            <p style="margin:0">Chưa có báo cáo thuế nào. Tạo báo cáo đầu tiên ở mục trên.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="adm-table" id="reportsTable" style="width:100%;border-collapse:collapse;font-size:0.85rem">
                <thead>
                    <tr style="background:#f8f9fa;text-align:left">
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border)">Kỳ</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">Doanh thu</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">Miễn thuế</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">Tính thuế</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">GTGT</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">TNCN</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">Tổng thuế</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border)">Trạng thái</th>
                        <th style="padding:10px 12px;border-bottom:2px solid var(--admin-border);text-align:right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r):
                        $st = $statusMap[$r['status']] ?? $statusMap['draft'];
                    ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:10px 12px;font-family:'IBM Plex Mono',monospace;font-weight:700">
                                <?php echo htmlspecialchars($r['period_value']); ?>
                                <div style="font-size:0.72rem;color:var(--admin-text-muted);font-weight:400;font-family:inherit">
                                    <?php echo date('d/m/Y', strtotime($r['period_start'])); ?>
                                    -
                                    <?php echo date('d/m/Y', strtotime($r['period_end'])); ?>
                                </div>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace">
                                <?php echo fmtMoney($r['total_revenue']); ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace;color:#16a34a">
                                <?php echo fmtMoney($r['exempt_amount']); ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace;color:#d97706">
                                <?php echo fmtMoney($r['taxable_revenue']); ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace">
                                <?php echo fmtMoney($r['vat_amount']); ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace">
                                <?php echo fmtMoney($r['pit_amount']); ?>
                            </td>
                            <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace;font-weight:700;color:#b91c1c">
                                <?php echo fmtMoney($r['total_tax']); ?>
                            </td>
                            <td style="padding:10px 12px">
                                <span style="display:inline-block;padding:3px 10px;border-radius:10px;
                                             background:<?php echo $st['bg']; ?>;color:<?php echo $st['color']; ?>;
                                             font-size:0.75rem;font-weight:600">
                                    <?php echo $st['label']; ?>
                                </span>
                            </td>
                            <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                                <form method="POST" action="tax.php" style="display:inline"
                                      onsubmit="return confirm('Đổi trạng thái báo cáo này?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="report_id" value="<?php echo (int)$r['id']; ?>">
                                    <select name="status" onchange="this.form.submit()"
                                            style="padding:5px 8px;border:1px solid var(--admin-border);
                                                   border-radius:5px;font-size:0.78rem;cursor:pointer;background:#fff">
                                        <option value="draft"    <?php echo $r['status'] === 'draft'    ? 'selected' : ''; ?>>Nháp</option>
                                        <option value="declared" <?php echo $r['status'] === 'declared' ? 'selected' : ''; ?>>Đã kê khai</option>
                                        <option value="paid"     <?php echo $r['status'] === 'paid'     ? 'selected' : ''; ?>>Đã nộp</option>
                                    </select>
                                </form>
                                <form method="POST" action="tax.php" style="display:inline;margin-left:4px"
                                      onsubmit="return confirm('Xóa báo cáo này? Hành động không thể hoàn tác.')">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" title="Xóa"
                                            style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;
                                                   padding:5px 9px;border-radius:5px;cursor:pointer;font-size:0.78rem">
                                        🗑
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
/* ============================================================
   AUTO GENERATE PERIOD VALUE
   ============================================================ */
(function () {
    var typeSel  = document.getElementById('periodType');
    var valueInp = document.getElementById('periodValue');
    if (!typeSel || !valueInp) return;

    function suggestValue() {
        var t = typeSel.value;
        var d = new Date();
        var y = d.getFullYear();
        var m = d.getMonth() + 1;

        if (t === 'month')   return y + '-' + String(m).padStart(2, '0');
        if (t === 'quarter') return y + '-Q' + Math.ceil(m / 3);
        return String(y);
    }

    typeSel.addEventListener('change', function () {
        // Chỉ auto điền nếu input đang trống hoặc có format khác
        var cur = valueInp.value.trim();
        var expectedFmt;
        if (typeSel.value === 'month')   expectedFmt = /^\d{4}-\d{2}$/;
        else if (typeSel.value === 'quarter') expectedFmt = /^\d{4}-Q[1-4]$/;
        else expectedFmt = /^\d{4}$/;

        if (!cur || !expectedFmt.test(cur)) {
            valueInp.value = suggestValue();
        }
    });
})();

/* ============================================================
   XUẤT CSV
   ============================================================ */
function exportCsv() {
    var table = document.getElementById('reportsTable');
    if (!table) {
        alert('Chưa có dữ liệu để xuất.');
        return;
    }

    var rows = [];
    var headers = ['Ky', 'Tu ngay', 'Den ngay', 'Doanh thu',
                   'Mien thue', 'Tinh thue', 'GTGT', 'TNCN',
                   'Tong thue', 'Trang thai'];
    rows.push(headers.join(','));

    var trs = table.querySelectorAll('tbody tr');
    trs.forEach(function (tr) {
        var tds = tr.querySelectorAll('td');
        if (tds.length < 9) return;

        // Kỳ - lấy text đầu, bỏ phần ngày
        var periodText = tds[0].childNodes[0].textContent.trim();

        // Ngày — parse từ sub text
        var dateText = tds[0].querySelector('div')?.textContent.trim() || '';
        var parts = dateText.split('-').map(function (s) { return s.trim(); });

        var revenue = tds[1].textContent.trim().replace(/[^\d]/g, '');
        var exempt  = tds[2].textContent.trim().replace(/[^\d]/g, '');
        var taxable = tds[3].textContent.trim().replace(/[^\d]/g, '');
        var vat     = tds[4].textContent.trim().replace(/[^\d]/g, '');
        var pit     = tds[5].textContent.trim().replace(/[^\d]/g, '');
        var total   = tds[6].textContent.trim().replace(/[^\d]/g, '');
        var status  = tds[7].textContent.trim();

        rows.push([
            '"' + periodText + '"',
            '"' + (parts[0] || '') + '"',
            '"' + (parts[1] || '') + '"',
            revenue, exempt, taxable, vat, pit, total,
            '"' + status + '"'
        ].join(','));
    });

    var csv  = '\uFEFF' + rows.join('\n'); // BOM để Excel đọc đúng UTF-8
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'bao-cao-thue-' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include 'includes/admin_footer.php'; ?>