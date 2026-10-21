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
   ĐẢM BẢO BẢNG TỒN TẠI
   ============================================================ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_settings (
            id INT PRIMARY KEY DEFAULT 1,
            bank_bin        VARCHAR(20)  NOT NULL DEFAULT '970436',
            bank_name       VARCHAR(100) NOT NULL DEFAULT 'Vietcombank',
            account_no      VARCHAR(50)  NOT NULL DEFAULT '',
            account_name    VARCHAR(100) NOT NULL DEFAULT '',
            qr_image        VARCHAR(255) NULL,
            transfer_note   VARCHAR(200) NULL,
            instruction     TEXT         NULL,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO bank_settings (id) VALUES (1)");
} catch (Exception $e) {}

/* ============================================================
   HELPER RESPOND
   ============================================================ */
function respond($ok, $msg) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['admin_flash'] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
    header('Location: settings.php');
    exit;
}

/* ============================================================
   XỬ LÝ POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1️⃣ CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        respond(false, 'Phiên làm việc hết hạn, vui lòng thử lại.');
    }

    // 2️⃣ ✅ YÊU CẦU VERIFY PIN + OTP
    if (empty($_SESSION['settings_pin_verified'])) {
        respond(false, 'Vui lòng xác minh mã PIN trước khi lưu.');
    }
    if (empty($_SESSION['settings_otp_verified'])) {
        respond(false, 'Vui lòng xác minh mã OTP trước khi lưu.');
    }

    // 3️⃣ Lấy dữ liệu
    $bankBin      = trim($_POST['bank_bin'] ?? '970436');
    $bankName     = trim($_POST['bank_name'] ?? '');
    $accountNo    = trim($_POST['account_no'] ?? '');
    $accountName  = trim($_POST['account_name'] ?? '');
    $transferNote = trim($_POST['transfer_note'] ?? '');
    $instruction  = trim($_POST['instruction'] ?? '');
    $qrImage      = trim($_POST['qr_image_current'] ?? '');

    /* ===== Upload QR ===== */
    if (!empty($_FILES['qr_file']['tmp_name']) && ($_FILES['qr_file']['error'] ?? 4) === UPLOAD_ERR_OK) {
        $qrDir = __DIR__ . '/../uploads/qr/';
        if (!is_dir($qrDir)) @mkdir($qrDir, 0755, true);

        $tmpName = $_FILES['qr_file']['tmp_name'];
        $size    = (int)$_FILES['qr_file']['size'];
        $maxSize = 3 * 1024 * 1024;

        $mime = function_exists('mime_content_type')
            ? mime_content_type($tmpName)
            : ($_FILES['qr_file']['type'] ?? '');

        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if ($size > $maxSize) {
            respond(false, 'Ảnh QR vượt quá 3MB.');
        }
        if (!in_array($mime, $allowedMime, true)) {
            respond(false, 'Định dạng ảnh không hợp lệ.');
        }

        $ext = 'jpg';
        if ($mime === 'image/png')  $ext = 'png';
        if ($mime === 'image/webp') $ext = 'webp';
        if ($mime === 'image/gif')  $ext = 'gif';

        $filename = 'qr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $qrDir . $filename;

        if (move_uploaded_file($tmpName, $dest)) {
            if (!empty($qrImage)) {
                $oldPath = __DIR__ . '/../' . ltrim($qrImage, '/');
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $qrImage = 'uploads/qr/' . $filename;
        }
    }

    /* ===== Xóa QR ===== */
    if (!empty($_POST['delete_qr'])) {
        if (!empty($qrImage)) {
            $oldPath = __DIR__ . '/../' . ltrim($qrImage, '/');
            if (file_exists($oldPath)) @unlink($oldPath);
        }
        $qrImage = '';
    }

    /* ===== Validate BIN ===== */
    if (!preg_match('/^\d{6}$/', $bankBin)) {
        $bankBin = '970436';
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE bank_settings SET
                bank_bin = ?,
                bank_name = ?,
                account_no = ?,
                account_name = ?,
                qr_image = ?,
                transfer_note = ?,
                instruction = ?,
                updated_at = NOW()
            WHERE id = 1
        ");
        $stmt->execute([
            $bankBin,
            $bankName ?: null,
            $accountNo ?: null,
            $accountName ?: null,
            $qrImage ?: null,
            $transferNote ?: null,
            $instruction ?: null,
        ]);

        // 4️⃣ ✅ Clear verify sau khi lưu thành công (buộc lần sau verify lại)
        unset($_SESSION['settings_pin_verified'], $_SESSION['settings_otp_verified']);

        respond(true, '✅ Đã lưu thông tin tài khoản nhận tiền');

    } catch (Exception $e) {
        respond(false, 'Lỗi: ' . $e->getMessage());
    }
}

/* ============================================================
   LẤY DỮ LIỆU
   ============================================================ */
$bank = $pdo->query("SELECT * FROM bank_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$bank) {
    $bank = [
        'bank_bin' => '970436', 'bank_name' => 'Vietcombank',
        'account_no' => '', 'account_name' => '', 'qr_image' => '',
        'transfer_note' => '', 'instruction' => '',
    ];
}

$bankList = [
    '970436' => 'Vietcombank', '970407' => 'Techcombank', '970422' => 'MB Bank',
    '970416' => 'ACB',         '970418' => 'BIDV',       '970441' => 'VIB',
    '970403' => 'Sacombank',   '970423' => 'TPBank',     '970432' => 'VPBank',
    '970415' => 'VietinBank',  '970405' => 'Agribank',   '970426' => 'MSB',
    '970448' => 'OCB',         '970443' => 'SHB',        '971025' => 'Ví MoMo',
];

// Flash message
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
?>

<div class="admin-page-head">
    <div>
        <h1>Tài khoản nhận tiền</h1>
        <div class="subtitle">
            Cấu hình ngân hàng và mã QR hiển thị khi khách chọn <b>Chuyển khoản</b>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;
                         padding:2px 8px;background:#fef3c7;color:#92400e;
                         border-radius:10px;font-size:0.72rem;font-weight:700;">
                🔒 Yêu cầu PIN + OTP khi lưu
            </span>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div style="padding:12px 16px;border-radius:8px;margin-bottom:20px;
                background:<?php echo $flash['type'] === 'error' ? '#fee2e2' : '#ecfdf5'; ?>;
                color:<?php echo $flash['type'] === 'error' ? '#b91c1c' : '#047857'; ?>;">
        <?php echo htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="settingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="qr_image_current" value="<?php echo htmlspecialchars($bank['qr_image'] ?? ''); ?>">

    <div class="admin-card" style="padding:1.75rem;margin-bottom:1.25rem;">
        <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;
                   margin:0 0 1.5rem;padding-bottom:0.75rem;
                   border-bottom:1px solid var(--admin-border);">
            🏦 Thông tin ngân hàng
        </h2>

        <div class="adm-form-group">
            <label>Ngân hàng (chọn mã BIN)</label>
            <select name="bank_bin" id="bankBinSelect" class="adm-select" style="font-size:0.95rem;">
                <?php foreach ($bankList as $bin => $name): ?>
                    <option value="<?php echo $bin; ?>"
                            <?php echo ($bank['bank_bin'] ?? '') === $bin ? 'selected' : ''; ?>>
                        <?php echo $bin . ' — ' . $name; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="font-size:0.75rem;color:var(--admin-text-muted);margin-top:6px;">
                💡 Mã BIN dùng để sinh QR động — nếu bạn upload ảnh QR riêng thì mã này chỉ mang tính tham khảo.
            </div>
        </div>

        <div class="adm-row">
            <div class="adm-form-group">
                <label>Tên ngân hàng (hiển thị cho khách)</label>
                <input type="text" name="bank_name" class="adm-input"
                       value="<?php echo htmlspecialchars($bank['bank_name'] ?? ''); ?>"
                       placeholder="Vietcombank — CN Sài Gòn" maxlength="100">
            </div>
            <div class="adm-form-group">
                <label>Số tài khoản</label>
                <input type="text" name="account_no" class="adm-input"
                       value="<?php echo htmlspecialchars($bank['account_no'] ?? ''); ?>"
                       placeholder="0123456789012" maxlength="50">
            </div>
        </div>

        <div class="adm-form-group">
            <label>Chủ tài khoản (viết không dấu)</label>
            <input type="text" name="account_name" class="adm-input"
                   value="<?php echo htmlspecialchars($bank['account_name'] ?? ''); ?>"
                   placeholder="THE ATELIER STORE" maxlength="100">
        </div>

        <div class="adm-form-group">
            <label>Ghi chú khi khách chuyển khoản</label>
            <input type="text" name="transfer_note" class="adm-input"
                   value="<?php echo htmlspecialchars($bank['transfer_note'] ?? ''); ?>"
                   placeholder="Vui lòng ghi rõ Họ tên + SĐT khi chuyển khoản" maxlength="200">
        </div>

        <div class="adm-form-group">
            <label>Hướng dẫn sau khi chuyển khoản</label>
            <textarea name="instruction" class="adm-textarea" rows="2" maxlength="300"
                      placeholder="Sau khi chuyển khoản, hệ thống sẽ gửi mã OTP..."><?php echo htmlspecialchars($bank['instruction'] ?? ''); ?></textarea>
        </div>
    </div>

    <div class="admin-card" style="padding:1.75rem;margin-bottom:1.25rem;">
        <h2 style="font-family:'Fraunces',serif;font-size:1.15rem;font-weight:600;
                   margin:0 0 1rem;padding-bottom:0.75rem;
                   border-bottom:1px solid var(--admin-border);">
            📱 Mã QR chuyển khoản
        </h2>

        <p style="font-size:0.85rem;color:var(--admin-text-muted);margin:0 0 1.25rem;line-height:1.6;">
            Bạn có 2 lựa chọn:
        </p>

        <ul style="font-size:0.85rem;color:var(--admin-text-muted);margin:0 0 1.5rem;padding-left:1.5rem;line-height:1.9;">
            <li><b style="color:var(--admin-text);">Cách 1 — Upload ảnh QR tĩnh:</b> dùng ảnh QR có sẵn, khách quét bình thường, không tự điền số tiền.</li>
            <li><b style="color:var(--admin-text);">Cách 2 — Để trống:</b> hệ thống tự sinh <b>QR động VietQR</b> — khách quét sẽ <b>tự điền số tiền + nội dung</b>.</li>
        </ul>

        <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:none;">
                <div id="qrPreviewBox" style="
                    width:200px;height:200px;
                    border:2px dashed var(--admin-border);
                    border-radius:10px;
                    background:#f8f9fa;
                    display:flex;align-items:center;justify-content:center;
                    overflow:hidden;position:relative;">
                    <?php if (!empty($bank['qr_image'])): ?>
                        <img id="qrPreviewImg" src="../<?php echo htmlspecialchars($bank['qr_image']); ?>"
                             alt="QR" style="width:100%;height:100%;object-fit:contain;padding:10px;background:#fff;">
                    <?php else: ?>
                        <div id="qrPreviewEmpty" style="text-align:center;color:var(--admin-text-muted);font-size:0.75rem;padding:1rem;line-height:1.5;">
                            <div style="font-size:2.5rem;margin-bottom:0.5rem;opacity:0.35;">📷</div>
                            Chưa có ảnh QR<br>
                            <span style="font-size:0.68rem;opacity:0.7;">(Đang dùng QR động)</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="flex:1;min-width:260px;">
                <div class="adm-form-group">
                    <label>Chọn ảnh QR</label>
                    <input type="file" name="qr_file" id="qrFileInput"
                           accept="image/png,image/jpeg,image/webp,image/gif"
                           style="width:100%;padding:0.75rem;border:1px solid var(--admin-border);
                                  border-radius:6px;font-size:0.85rem;background:#fff;cursor:pointer;">
                    <div style="font-size:0.72rem;color:var(--admin-text-muted);margin-top:6px;">
                        JPG, PNG, WEBP, GIF · Tối đa 3MB
                    </div>
                </div>

                <div id="qrFileInfo" style="font-size:0.8rem;color:var(--admin-text-muted);
                                            padding:0.6rem 0.85rem;background:#f8f9fa;
                                            border-radius:6px;margin-top:0.5rem;display:none;"></div>

                <?php if (!empty($bank['qr_image'])): ?>
                    <label style="display:inline-flex;align-items:center;gap:0.5rem;margin-top:1rem;
                                  font-size:0.85rem;color:#b91c1c;cursor:pointer;user-select:none;
                                  padding:0.6rem 0.9rem;background:#fef2f2;border-radius:6px;
                                  border:1px solid #fecaca;">
                        <input type="checkbox" name="delete_qr" value="1">
                        🗑 Xóa ảnh QR hiện tại (chuyển về QR động)
                    </label>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="position:sticky;bottom:0;background:rgba(255,253,249,0.95);
                backdrop-filter:blur(8px);padding:1rem 0;
                border-top:1px solid var(--admin-border);
                display:flex;gap:0.75rem;justify-content:flex-end;">
        <button type="reset" class="btn-adm btn-adm--ghost" style="padding:0.85rem 1.5rem;">
            Khôi phục
        </button>
        <button type="submit" class="btn-adm btn-adm--primary" style="padding:0.85rem 2rem;">
            🔒 Lưu thay đổi (PIN + OTP)
        </button>
    </div>
</form>

<!-- ============================================================ -->
<!-- MODAL PIN                                                     -->
<!-- ============================================================ -->
<div class="otp-modal-overlay" id="pinModal" aria-hidden="true">
    <div class="otp-modal" role="dialog" aria-modal="true">
        <button type="button" class="otp-modal__close" id="pinCloseBtn" aria-label="Đóng">&times;</button>

        <div class="otp-modal__icon" id="pinIcon">🔐</div>
        <h2 class="otp-modal__title" id="pinTitle">Xác minh mã PIN</h2>
        <p class="otp-modal__subtitle" id="pinSubtitle">
            Nhập mã PIN 6 số để xác nhận thay đổi
        </p>

        <div id="pinVerifyBlock">
            <div class="otp-inputs" id="pinInputsVerify">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="0" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="1" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="2" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="3" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="4" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="5" autocomplete="off">
            </div>
        </div>

        <div id="pinSetupBlock" hidden>
            <p style="text-align:center;font-family:var(--font-mono);font-size:0.78rem;color:var(--color-stone);margin:0 0 0.5rem">
                Nhập PIN 6 số
            </p>
            <div class="otp-inputs" id="pinInputsSetup">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="0" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="1" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="2" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="3" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="4" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="5" autocomplete="off">
            </div>

            <p style="text-align:center;font-family:var(--font-mono);font-size:0.78rem;color:var(--color-stone);margin:1rem 0 0.5rem">
                Nhập lại PIN
            </p>
            <div class="otp-inputs" id="pinInputsConfirm">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="0" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="1" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="2" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="3" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="4" autocomplete="off">
                <input type="text" class="otp-input pin-input" maxlength="1" inputmode="numeric" data-idx="5" autocomplete="off">
            </div>
        </div>

        <button type="button" class="btn-otp-confirm" id="btnPinConfirm" disabled style="margin-top:1.25rem">
            XÁC NHẬN PIN
        </button>

        <p class="otp-error-msg" id="pinErrorMsg"></p>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL OTP                                                     -->
<!-- ============================================================ -->
<div class="otp-modal-overlay" id="otpModal" aria-hidden="true">
    <div class="otp-modal" role="dialog" aria-modal="true">
        <button type="button" class="otp-modal__close" id="otpCloseBtn" aria-label="Đóng">&times;</button>

        <div class="otp-modal__icon">📧</div>
        <h2 class="otp-modal__title">Xác minh OTP</h2>
        <p class="otp-modal__subtitle">
            Mã OTP đã được gửi tới email<br>
            <span class="otp-modal__phone" id="otpEmailDisplay">*******</span>
        </p>

        <div class="otp-inputs" id="otpInputs">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="0" autocomplete="off">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="1" autocomplete="off">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="2" autocomplete="off">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="3" autocomplete="off">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="4" autocomplete="off">
            <input type="text" class="otp-input" maxlength="1" inputmode="numeric" data-idx="5" autocomplete="off">
        </div>

        <button type="button" class="btn-otp-confirm" id="btnOtpConfirm" disabled>
            XÁC NHẬN OTP
        </button>

        <p class="otp-error-msg" id="otpErrorMsg"></p>

        <button type="button" class="otp-help-link" id="resendOtpBtn">
            Chưa nhận được mã? Gửi lại
        </button>
    </div>
</div>

<!-- LOADING OVERLAY -->
<div class="otp-loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="otp-loading-box" id="loadingBox">
        <div class="hourglass-spinner"></div>
        <p class="otp-loading-text" id="loadingText">Đang xử lý...</p>
        <p class="otp-loading-subtext" id="loadingSubtext">Vui lòng không đóng cửa sổ này</p>
    </div>
</div>

<!-- TOAST -->
<div id="settingsToast" style="
    position:fixed;top:1.5rem;left:50%;transform:translateX(-50%) translateY(-20px);
    background:#166534;color:#fff;padding:0.95rem 1.75rem;font-family:var(--font-mono,monospace);
    font-size:0.85rem;box-shadow:0 10px 25px rgba(0,0,0,0.15);z-index:99999;
    opacity:0;transition:opacity .3s,transform .3s;border-radius:6px;
    display:flex;align-items:center;gap:0.75rem;max-width:90vw;" hidden>
    <span id="settingsToastIcon" style="
        display:inline-flex;align-items:center;justify-content:center;
        width:24px;height:24px;background:rgba(255,255,255,0.2);
        border-radius:50%;font-weight:700;flex:none;">✓</span>
    <span id="settingsToastMsg">Thành công!</span>
</div>

<script>
(function () {
    /* ============================================================
       DOM refs
       ============================================================ */
    var form           = document.getElementById('settingsForm');
    var loadingOv      = document.getElementById('loadingOverlay');
    var loadingBox     = document.getElementById('loadingBox');
    var loadingText    = document.getElementById('loadingText');
    var loadingSub     = document.getElementById('loadingSubtext');

    // PIN
    var pinModal        = document.getElementById('pinModal');
    var pinCloseBtn     = document.getElementById('pinCloseBtn');
    var pinIcon         = document.getElementById('pinIcon');
    var pinTitle        = document.getElementById('pinTitle');
    var pinSubtitle     = document.getElementById('pinSubtitle');
    var pinVerifyBlock  = document.getElementById('pinVerifyBlock');
    var pinSetupBlock   = document.getElementById('pinSetupBlock');
    var pinInputsVerify  = document.querySelectorAll('#pinInputsVerify .pin-input');
    var pinInputsSetup   = document.querySelectorAll('#pinInputsSetup .pin-input');
    var pinInputsConfirm = document.querySelectorAll('#pinInputsConfirm .pin-input');
    var btnPinConfirm   = document.getElementById('btnPinConfirm');
    var pinErrorMsg     = document.getElementById('pinErrorMsg');

    // OTP
    var otpModal      = document.getElementById('otpModal');
    var otpInputs     = document.querySelectorAll('#otpInputs .otp-input');
    var otpEmailDisp  = document.getElementById('otpEmailDisplay');
    var btnOtpConfirm = document.getElementById('btnOtpConfirm');
    var otpErrorMsg   = document.getElementById('otpErrorMsg');
    var otpCloseBtn   = document.getElementById('otpCloseBtn');
    var resendOtpBtn  = document.getElementById('resendOtpBtn');

    // State
    var pinVerified = false;
    var otpVerified = false;
    var otpSending  = false;
    var pinHasPin   = false;
    var pinMode     = 'verify';

    if (!form) return;

    /* ============================================================
       Helpers
       ============================================================ */
    function showLoading(text, sub) {
        loadingBox.classList.remove('is-success');
        loadingText.textContent = text || 'Đang xử lý...';
        loadingSub.textContent  = sub || 'Vui lòng không đóng cửa sổ này';
        loadingOv.classList.add('show');
    }
    function showSuccess(text, sub) {
        loadingBox.classList.add('is-success');
        loadingText.textContent = text || '✅ Thành công!';
        loadingSub.textContent  = sub || '';
        loadingOv.classList.add('show');
    }
    function hideLoading() {
        loadingOv.classList.remove('show');
        loadingBox.classList.remove('is-success');
    }
    function delayThen(ms, cb) { setTimeout(cb, ms); }

    function showToast(msg, isError) {
        var t = document.getElementById('settingsToast');
        var i = document.getElementById('settingsToastIcon');
        var m = document.getElementById('settingsToastMsg');
        i.textContent = isError ? '⚠' : '✓';
        m.textContent = msg;
        t.style.background = isError ? '#b91c1c' : '#166534';
        t.hidden = false;
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            t.style.transform = 'translateX(-50%) translateY(0)';
        });
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(function () { t.hidden = true; }, 350);
        }, 3500);
    }

    /* ============================================================
       PIN inputs
       ============================================================ */
    function attachPinInputHandlers(inputs, nextGroup) {
        inputs.forEach(function (input, idx) {
            input.addEventListener('compositionstart', function (e) { e.preventDefault(); });
            input.addEventListener('compositionend', function () {
                this.value = (this.value || '').replace(/\D/g, '').slice(-1);
                this.classList.toggle('filled', !!this.value);
                checkPinAllFilled();
            });
            input.addEventListener('beforeinput', function (e) {
                if (e.inputType === 'insertText' || e.inputType === 'insertCompositionText') {
                    if (!/^\d$/.test(e.data || '')) e.preventDefault();
                }
            });
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 1);
                if (this.value) {
                    this.classList.add('filled');
                    if (idx < inputs.length - 1) inputs[idx + 1].focus();
                    else if (nextGroup) nextGroup[0]?.focus();
                } else this.classList.remove('filled');
                checkPinAllFilled();
            });
            input.addEventListener('keydown', function (e) {
                if (['Backspace','Delete','Tab','Enter','ArrowLeft','ArrowRight','Home','End'].includes(e.key)) {
                    if (e.key === 'Backspace' && !this.value && idx > 0) inputs[idx - 1].focus();
                    return;
                }
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                if (!/^\d$/.test(e.key)) e.preventDefault();
            });
            input.addEventListener('paste', function (e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text')
                    .replace(/\D/g, '').slice(0, 6);
                if (!pasted) return;
                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = pasted[i] || '';
                    inputs[i].classList.toggle('filled', !!pasted[i]);
                }
                if (nextGroup && pasted.length >= 6) nextGroup[0]?.focus();
                else inputs[Math.min(pasted.length, inputs.length - 1)].focus();
                checkPinAllFilled();
            });
        });
    }

    attachPinInputHandlers(pinInputsVerify);
    attachPinInputHandlers(pinInputsSetup, pinInputsConfirm);
    attachPinInputHandlers(pinInputsConfirm);

    function getPinValue(inputs) {
        return Array.from(inputs).map(function (i) { return i.value; }).join('');
    }
    function clearPinInputs(inputs) {
        inputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
    }
    function checkPinAllFilled() {
        var filled = (pinMode === 'verify')
            ? getPinValue(pinInputsVerify).length === 6
            : getPinValue(pinInputsSetup).length === 6 && getPinValue(pinInputsConfirm).length === 6;
        btnPinConfirm.disabled = !filled;
    }

    function openPinModal() {
        pinModal.classList.add('show');
        pinErrorMsg.textContent = '';
        clearPinInputs(pinInputsVerify);
        clearPinInputs(pinInputsSetup);
        clearPinInputs(pinInputsConfirm);

        fetch('../ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=status&context=settings'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            pinHasPin = data.has_pin === true;
            pinMode = pinHasPin ? 'verify' : 'setup';

            if (pinMode === 'verify') {
                pinIcon.textContent = '🔐';
                pinTitle.textContent = 'Xác minh mã PIN';
                pinSubtitle.textContent = 'Nhập mã PIN 6 số để xác nhận thay đổi';
                pinVerifyBlock.hidden = false;
                pinSetupBlock.hidden = true;
                setTimeout(function () { pinInputsVerify[0]?.focus(); }, 300);
            } else {
                pinIcon.textContent = '🆕';
                pinTitle.textContent = 'Thiết lập mã PIN';
                pinSubtitle.textContent = 'Đây là lần đầu bạn dùng. Vui lòng tạo mã PIN 6 số.';
                pinVerifyBlock.hidden = true;
                pinSetupBlock.hidden = false;
                setTimeout(function () { pinInputsSetup[0]?.focus(); }, 300);
            }
            checkPinAllFilled();
        })
        .catch(function () {
            pinErrorMsg.textContent = 'Không thể kiểm tra trạng thái PIN.';
        });
    }
    function closePinModal() { pinModal.classList.remove('show'); }

    /* ============================================================
       OTP inputs
       ============================================================ */
    otpInputs.forEach(function (input, idx) {
        input.addEventListener('compositionstart', function (e) { e.preventDefault(); });
        input.addEventListener('compositionend', function () {
            this.value = (this.value || '').replace(/\D/g, '').slice(-1);
            this.classList.toggle('filled', !!this.value);
            checkAllFilled();
        });
        input.addEventListener('beforeinput', function (e) {
            if (e.inputType === 'insertText' || e.inputType === 'insertCompositionText') {
                if (!/^\d$/.test(e.data || '')) e.preventDefault();
            }
        });
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value) {
                this.classList.add('filled');
                if (idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
            } else this.classList.remove('filled');
            checkAllFilled();
        });
        input.addEventListener('keydown', function (e) {
            if (['Backspace','Delete','Tab','Enter','ArrowLeft','ArrowRight','Home','End'].includes(e.key)) {
                if (e.key === 'Backspace' && !this.value && idx > 0) otpInputs[idx - 1].focus();
                if (e.key === 'ArrowLeft' && idx > 0) otpInputs[idx - 1].focus();
                if (e.key === 'ArrowRight' && idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
                return;
            }
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (!/^\d$/.test(e.key)) e.preventDefault();
        });
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData('text')
                .replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            for (var i = 0; i < otpInputs.length; i++) {
                otpInputs[i].value = pasted[i] || '';
                otpInputs[i].classList.toggle('filled', !!pasted[i]);
            }
            var next = Math.min(pasted.length, otpInputs.length - 1);
            otpInputs[next].focus();
            checkAllFilled();
        });
    });

    function checkAllFilled() {
        var filled = true;
        otpInputs.forEach(function (i) { if (!i.value) filled = false; });
        btnOtpConfirm.disabled = !filled;
    }

    function openOtpModal() {
        otpModal.classList.add('show');
        otpErrorMsg.textContent = '';
        otpInputs.forEach(function (i) { i.value = ''; i.classList.remove('filled', 'error'); });
        btnOtpConfirm.disabled = true;
        btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
        setTimeout(function () { otpInputs[0]?.focus(); }, 300);
    }
    function closeOtpModal() { otpModal.classList.remove('show'); }

    function sendOtp() {
        if (otpSending) return;
        otpSending = true;

        fetch('../ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send&context=settings'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            otpSending = false;
            if (!data.ok) { showToast(data.msg || 'Không thể gửi OTP.', true); return; }
            if (otpEmailDisp) otpEmailDisp.textContent = data.email_mask || data.phone_mask || '*******';
            openOtpModal();
        })
        .catch(function () {
            otpSending = false;
            showToast('Không thể kết nối tới máy chủ.', true);
        });
    }

    /* ============================================================
       Form submit — bắt buộc PIN + OTP
       ============================================================ */
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!pinVerified) {
            openPinModal();
            return;
        }
        if (!otpVerified) {
            showLoading('Đang gửi OTP...', 'Vui lòng chờ trong giây lát');
            delayThen(1500, function () {
                hideLoading();
                sendOtp();
            });
            return;
        }
        // Đã verify cả 2 → submit thật
        submitForm();
    });

    /* ============================================================
       PIN confirm
       ============================================================ */
    btnPinConfirm?.addEventListener('click', function () {
        var pin, pinConfirm = '';
        var action;

        if (pinMode === 'verify') {
            pin = getPinValue(pinInputsVerify);
            action = 'verify';
        } else {
            pin = getPinValue(pinInputsSetup);
            pinConfirm = getPinValue(pinInputsConfirm);
            action = 'set';
        }

        btnPinConfirm.disabled = true;
        btnPinConfirm.textContent = 'Đang xử lý...';

        var body = 'action=' + action + '&pin=' + encodeURIComponent(pin) + '&context=settings';
        if (pinConfirm) body += '&pin_confirm=' + encodeURIComponent(pinConfirm);

        fetch('../ajax/pin_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                pinVerified = true;
                closePinModal();
                showToast('✅ Xác minh PIN thành công — đang gửi OTP...', false);

                showLoading('Đang gửi OTP...', 'Vui lòng chờ trong giây lát');
                delayThen(1500, function () {
                    hideLoading();
                    sendOtp();
                });
            } else {
                pinErrorMsg.textContent = data.msg || 'Có lỗi xảy ra.';
                (pinMode === 'verify' ? pinInputsVerify : pinInputsSetup).forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });
                clearPinInputs(pinInputsVerify);
                clearPinInputs(pinInputsSetup);
                clearPinInputs(pinInputsConfirm);
                btnPinConfirm.disabled = true;
                btnPinConfirm.textContent = 'XÁC NHẬN PIN';
                setTimeout(function () {
                    if (pinMode === 'verify') pinInputsVerify[0]?.focus();
                    else pinInputsSetup[0]?.focus();
                }, 300);
            }
        })
        .catch(function () {
            pinErrorMsg.textContent = 'Không thể kết nối tới máy chủ.';
            btnPinConfirm.disabled = false;
            btnPinConfirm.textContent = 'XÁC NHẬN PIN';
        });
    });

    pinCloseBtn?.addEventListener('click', function () {
        if (confirm('Bạn muốn hủy xác minh? Thay đổi sẽ không được lưu.')) closePinModal();
    });

    /* ============================================================
       OTP confirm
       ============================================================ */
    btnOtpConfirm?.addEventListener('click', function () {
        var code = Array.from(otpInputs).map(function (i) { return i.value; }).join('');
        if (code.length !== 6) return;

        btnOtpConfirm.disabled = true;
        btnOtpConfirm.textContent = 'Đang xác minh...';

        fetch('../ajax/otp_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=verify&code=' + encodeURIComponent(code) + '&context=settings'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                otpVerified = true;
                closeOtpModal();
                showSuccess('✅ Đã xác minh!', 'Đang lưu thay đổi...');
                delayThen(1200, function () {
                    hideLoading();
                    submitForm();
                });
            } else {
                otpErrorMsg.textContent = data.msg || 'Mã không đúng.';
                otpInputs.forEach(function (i) {
                    i.classList.add('error');
                    setTimeout(function () { i.classList.remove('error'); }, 400);
                });
                btnOtpConfirm.disabled = false;
                btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
            }
        })
        .catch(function () {
            otpErrorMsg.textContent = 'Không thể kết nối tới máy chủ.';
            btnOtpConfirm.disabled = false;
            btnOtpConfirm.textContent = 'XÁC NHẬN OTP';
        });
    });

    otpCloseBtn?.addEventListener('click', function () {
        if (confirm('Bạn muốn hủy xác minh? Thay đổi sẽ không được lưu.')) closeOtpModal();
    });

    resendOtpBtn?.addEventListener('click', function () {
        if (otpSending) return;
        sendOtp();
    });

    /* ============================================================
       Submit form bằng AJAX
       ============================================================ */
    function submitForm() {
        var fd = new FormData(form);

        fetch('settings.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                showToast(data.msg || '✅ Đã lưu thành công!', false);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                showToast(data.msg || 'Có lỗi xảy ra.', true);
            }
        })
        .catch(function () {
            showToast('Không thể kết nối tới máy chủ.', true);
        });
    }

    /* ============================================================
       Preview QR
       ============================================================ */
    var qrInput = document.getElementById('qrFileInput');
    var qrBox   = document.getElementById('qrPreviewBox');
    var qrInfo  = document.getElementById('qrFileInfo');

    qrInput?.addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) { if (qrInfo) qrInfo.style.display = 'none'; return; }

        if (file.size > 3 * 1024 * 1024) {
            showToast('Ảnh quá lớn. Vui lòng chọn ảnh nhỏ hơn 3MB.', true);
            this.value = '';
            if (qrInfo) qrInfo.style.display = 'none';
            return;
        }

        if (qrInfo) {
            qrInfo.style.display = 'block';
            qrInfo.innerHTML = '📎 <b>' + file.name + '</b> (' + Math.round(file.size / 1024) + ' KB)';
        }

        var reader = new FileReader();
        reader.onload = function (e) {
            if (qrBox) {
                qrBox.innerHTML = '<img src="' + e.target.result + '" ' +
                    'style="width:100%;height:100%;object-fit:contain;padding:10px;background:#fff;">';
            }
        };
        reader.readAsDataURL(file);
    });

    /* ============================================================
       ESC đóng modal
       ============================================================ */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (pinModal.classList.contains('show')) closePinModal();
        if (otpModal.classList.contains('show')) closeOtpModal();
    });
})();
</script>

<?php include 'includes/admin_footer.php'; ?>