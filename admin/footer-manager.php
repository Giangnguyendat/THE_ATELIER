<?php
require_once '../includes/config.php';
global $pdo;
include 'includes/admin_header.php';

// Danh sách các tab chính sách
$policyTabs = [
    'return'     => 'Chính sách đổi trả',
    'privacy'    => 'Chính sách bảo mật',
    'terms'      => 'Điều khoản dịch vụ',
    'shipping'   => 'Chính sách vận chuyển',
    'payment'    => 'Chính sách thanh toán',
    'size-guide' => 'Hướng dẫn chọn size',
    'faq'        => 'Câu hỏi thường gặp',
];

$activeTab = $_GET['tab'] ?? 'general';

// Xử lý lưu dữ liệu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
        header('Location: footer-manager.php?tab=' . $activeTab); exit;
    }

    if ($activeTab === 'general') {
        $about = trim($_POST['about_text'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $fb = trim($_POST['facebook'] ?? '');
        $ig = trim($_POST['instagram'] ?? '');
        $tt = trim($_POST['tiktok'] ?? '');
        $yt = trim($_POST['youtube'] ?? '');
        $copy = trim($_POST['copyright'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE footer_settings 
            SET about_text = ?, phone = ?, email = ?, address = ?, 
                facebook = ?, instagram = ?, tiktok = ?, youtube = ?, copyright = ?
            WHERE id = 1
        ");
        $stmt->execute([$about, $phone, $email, $address, $fb, $ig, $tt, $yt, $copy]);
    } 
    else if (array_key_exists($activeTab, $policyTabs)) {
        // Lưu nội dung chính sách
        $colName = 'policy_' . str_replace('-', '_', $activeTab);
        $content = $_POST['policy_content'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE footer_settings SET $colName = ? WHERE id = 1");
        $stmt->execute([$content]);
    }

    $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã cập nhật thông tin thành công!'];
    header('Location: footer-manager.php?tab=' . $activeTab);
    exit;
}

// Lấy dữ liệu
$footer = $pdo->query("SELECT * FROM footer_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

/* ============================================================
   HELPER HIỂN THỊ: icon SVG dùng riêng cho trang này
   ============================================================ */
if (!function_exists('fmIcon')) {
    function fmIcon($name, $size = 18) {
        static $p = [
            'settings'  => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h2"/><path d="M10 17h10"/><circle cx="8" cy="17" r="2"/>',
            'file'      => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/>',
            'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'save'      => '<path d="M5 3h11l4 4v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M8 3v5h7V3"/><path d="M8 21v-7h8v7"/>',
            'phone'     => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'pin'       => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
            'facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
            'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
            'tiktok'    => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
            'youtube'   => '<rect x="2.5" y="5" width="19" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
            'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
            'heading'   => '<path d="M6 4v16"/><path d="M18 4v16"/><path d="M6 12h12"/>',
            'list'      => '<path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
            'quote'     => '<path d="M5 6h14v9H9l-4 4z"/>',
            'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        ];
        $d = $p[$name] ?? $p['info'];
        $s = (int)$size;
        return '<svg class="fm-ico" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" '
             . 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true" focusable="false">' . $d . '</svg>';
    }
}

$isGeneral  = ($activeTab === 'general');
$tabLabel   = $isGeneral ? 'Thông tin chung' : ($policyTabs[$activeTab] ?? '');
$activeSafe = htmlspecialchars($activeTab);
?>

<style>
    .fm-ico { display: block; flex-shrink: 0; }

    /* ============ BỐ CỤC 2 CỘT ============ */
    .fm-layout {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        gap: 28px;
        align-items: start;
        max-width: 1280px;
    }
    .fm-main { min-width: 0; }

    /* ============ ĐIỀU HƯỚNG BÊN TRÁI ============ */
    .fm-nav {
        position: sticky;
        top: 0;
        background: var(--admin-card);
        border: 1px solid var(--admin-border);
        border-radius: 12px;
        padding: 10px;
    }
    .fm-nav__group {
        padding: 12px 12px 6px;
        font-size: 0.66rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--admin-text-muted);
        font-weight: 600;
    }
    .fm-nav__group:first-child { padding-top: 6px; }
    .fm-nav a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        color: var(--admin-text);
        font-size: 0.88rem;
        font-weight: 500;
        text-decoration: none;
        transition: background 0.15s, color 0.15s;
    }
    .fm-nav a .fm-ico { color: var(--admin-text-muted); transition: color 0.15s; }
    .fm-nav a:hover { background: #fbf7ef; }
    .fm-nav a.active {
        background: var(--admin-sidebar-bg);
        color: #fffdf9;
        font-weight: 600;
    }
    .fm-nav a.active .fm-ico { color: #f0a47f; }

    /* ============ CARD ============ */
    .fm-card {
        background: var(--admin-card);
        border: 1px solid var(--admin-border);
        border-radius: 12px;
        padding: 26px 28px;
        margin-bottom: 18px;
    }
    .fm-card__head { margin-bottom: 20px; }
    .fm-card__title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0 0 4px;
        color: var(--admin-text);
    }
    .fm-card__desc { font-size: 0.84rem; color: var(--admin-text-muted); margin: 0; }

    /* ============ FORM ============ */
    .fm-field { margin-bottom: 18px; }
    .fm-field:last-child { margin-bottom: 0; }
    .fm-label {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--admin-text);
        margin-bottom: 6px;
    }
    .fm-hint { font-size: 0.76rem; font-weight: 400; color: var(--admin-text-muted); }
    .fm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }

    .fm-input, .fm-textarea {
        width: 100%;
        padding: 11px 14px;
        border: 1px solid var(--admin-border);
        border-radius: 8px;
        background: #fff;
        color: var(--admin-text);
        font-family: inherit;
        font-size: 0.92rem;
        line-height: 1.5;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .fm-textarea { resize: vertical; min-height: 110px; line-height: 1.65; }
    .fm-input::placeholder, .fm-textarea::placeholder { color: #b8b1a3; }
    .fm-input:focus, .fm-textarea:focus {
        outline: none;
        border-color: var(--admin-accent);
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.12);
    }

    /* Ô nhập có icon phía trước */
    .fm-group {
        display: flex;
        align-items: stretch;
        border: 1px solid var(--admin-border);
        border-radius: 8px;
        background: #fff;
        overflow: hidden;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .fm-group:focus-within {
        border-color: var(--admin-accent);
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.12);
    }
    .fm-group__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        background: #faf6ee;
        border-right: 1px solid var(--admin-border);
        color: var(--admin-text-muted);
        flex-shrink: 0;
    }
    .fm-group:focus-within .fm-group__icon { color: var(--admin-accent); }
    .fm-group .fm-input { border: none; border-radius: 0; box-shadow: none; }
    .fm-group .fm-input:focus { box-shadow: none; }

    /* ============ SOẠN THẢO CHÍNH SÁCH ============ */
    .fm-tip {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        background: #f3f6fe;
        border: 1px solid #dbe4fb;
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 18px;
        font-size: 0.84rem;
        color: #2f3f66;
        line-height: 1.6;
    }
    .fm-tip .fm-ico { color: #2f55c9; margin-top: 2px; }
    .fm-tip b { color: #1d2b4d; }

    .fm-quick {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }
    .fm-quick__label { font-size: 0.8rem; color: var(--admin-text-muted); margin-right: 4px; }
    .fm-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 12px;
        border: 1px solid var(--admin-border);
        border-radius: 999px;
        background: #fff;
        color: var(--admin-text);
        font-family: inherit;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        transition: border-color 0.15s, color 0.15s, background 0.15s;
    }
    .fm-chip:hover { border-color: var(--admin-accent); color: var(--admin-accent); background: #fffaf5; }

    /* ============ TINYMCE — ÉP KHUNG SOẠN THẢO CAO ============ */
    .fm-main .tox.tox-tinymce {
        width: 100% !important;
        min-height: 720px;
        border: 1px solid var(--admin-border);
        border-radius: 10px;
    }
    .fm-main .tox .tox-editor-container { min-height: 0; }
    .fm-main .tox .tox-edit-area { flex: 1 1 auto; min-height: 0; }
    .fm-main .tox .tox-edit-area__iframe {
        height: 100% !important;
        min-height: 0 !important;
        max-height: none !important;
    }

    /* ============ THANH THAO TÁC DÍNH DƯỚI ============ */
    .fm-bar {
        position: sticky;
        bottom: 0;
        z-index: 20;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 8px;
        padding: 14px 20px;
        background: rgba(255, 253, 249, 0.96);
        -webkit-backdrop-filter: blur(10px);
        backdrop-filter: blur(10px);
        border: 1px solid var(--admin-border);
        border-radius: 12px;
        box-shadow: 0 -6px 24px rgba(28, 27, 25, 0.08);
    }
    .fm-bar__left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .fm-bar__right { display: flex; align-items: center; gap: 10px; }
    .fm-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.82rem;
        color: var(--admin-text-muted);
    }
    .fm-status::before {
        content: '';
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #16a34a;
    }
    .fm-status[data-state="dirty"] { color: #92400e; font-weight: 600; }
    .fm-status[data-state="dirty"]::before { background: #f59e0b; }
    .fm-kbd {
        font-family: 'IBM Plex Mono', ui-monospace, monospace;
        font-size: 0.72rem;
        color: var(--admin-text-muted);
        border: 1px solid var(--admin-border);
        border-radius: 5px;
        padding: 2px 6px;
        background: #fff;
    }
    .fm-bar .btn-adm { padding: 11px 20px; font-size: 0.88rem; }
    .fm-bar .btn-adm:disabled { opacity: 0.6; cursor: not-allowed; }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 960px) {
        .fm-layout { grid-template-columns: 1fr; gap: 16px; }
        .fm-nav {
            position: static;
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding: 8px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .fm-nav::-webkit-scrollbar { display: none; }
        .fm-nav__group { display: none; }
        .fm-nav a { flex-shrink: 0; white-space: nowrap; }
        .fm-main .tox.tox-tinymce { min-height: 560px; }
    }
    @media (max-width: 640px) {
        .fm-card { padding: 20px 16px; }
        .fm-grid { grid-template-columns: 1fr; gap: 18px; }
        .fm-bar { padding: 12px 14px; }
        .fm-bar__right { width: 100%; }
        .fm-bar__right .btn-adm { flex: 1; justify-content: center; }
        .fm-kbd-wrap { display: none; }
    }
</style>

<div class="admin-page-head">
    <div>
        <h1>Quản lý Footer & Chính sách</h1>
        <div class="subtitle">Chỉnh sửa nội dung chân trang và các trang chính sách</div>
    </div>
</div>

<div class="fm-layout">

    <!-- ============ ĐIỀU HƯỚNG ============ -->
    <nav class="fm-nav" aria-label="Mục chỉnh sửa">
        <div class="fm-nav__group">Chân trang</div>
        <a href="footer-manager.php?tab=general" class="<?php echo $activeTab === 'general' ? 'active' : ''; ?>">
            <?php echo fmIcon('settings'); ?> Thông tin chung
        </a>

        <div class="fm-nav__group">Trang chính sách</div>
        <?php foreach ($policyTabs as $key => $label): ?>
            <a href="footer-manager.php?tab=<?php echo $key; ?>" class="<?php echo $activeTab === $key ? 'active' : ''; ?>">
                <?php echo fmIcon('file'); ?> <?php echo $label; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ============ NỘI DUNG ============ -->
    <div class="fm-main">
        <form method="POST" id="fmForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <?php if ($activeTab === 'general'): ?>

                <!-- ===== GIỚI THIỆU ===== -->
                <div class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__title">Giới thiệu cửa hàng</h3>
                        <p class="fm-card__desc">Đoạn mô tả ngắn hiển thị ở chân trang.</p>
                    </div>
                    <div class="fm-field">
                        <label class="fm-label" for="fmAbout">
                            Đoạn giới thiệu (About text)
                            <span class="fm-hint" id="fmAboutCount">0 ký tự</span>
                        </label>
                        <textarea name="about_text" id="fmAbout" class="fm-textarea" rows="4"
                                  placeholder="Ví dụ: THE ATELIER là thương hiệu thời trang..."><?php echo htmlspecialchars($footer['about_text'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- ===== LIÊN HỆ ===== -->
                <div class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__title">Thông tin liên hệ</h3>
                        <p class="fm-card__desc">Khách hàng sẽ dùng các thông tin này để liên lạc với cửa hàng.</p>
                    </div>
                    <div class="fm-grid">
                        <div class="fm-field">
                            <label class="fm-label" for="fmPhone">Số điện thoại (Hotline)</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('phone'); ?></span>
                                <input type="text" name="phone" id="fmPhone" class="fm-input" inputmode="tel"
                                       placeholder="0912 345 678"
                                       value="<?php echo htmlspecialchars($footer['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="fmEmail">Email hỗ trợ</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('mail'); ?></span>
                                <input type="email" name="email" id="fmEmail" class="fm-input"
                                       placeholder="support@example.com"
                                       value="<?php echo htmlspecialchars($footer['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="fm-field" style="margin-top:18px;">
                        <label class="fm-label" for="fmAddress">Địa chỉ cửa hàng</label>
                        <div class="fm-group">
                            <span class="fm-group__icon"><?php echo fmIcon('pin'); ?></span>
                            <input type="text" name="address" id="fmAddress" class="fm-input"
                                   placeholder="Số nhà, đường, quận/huyện, tỉnh/thành phố"
                                   value="<?php echo htmlspecialchars($footer['address'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <!-- ===== MẠNG XÃ HỘI ===== -->
                <div class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__title">Mạng xã hội</h3>
                        <p class="fm-card__desc">Dán đường dẫn đầy đủ của trang. Để trống nếu chưa dùng kênh nào.</p>
                    </div>
                    <div class="fm-grid">
                        <div class="fm-field">
                            <label class="fm-label" for="fmFb">Facebook</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('facebook'); ?></span>
                                <input type="text" name="facebook" id="fmFb" class="fm-input"
                                       placeholder="https://facebook.com/ten-trang"
                                       value="<?php echo htmlspecialchars($footer['facebook'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="fmIg">Instagram</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('instagram'); ?></span>
                                <input type="text" name="instagram" id="fmIg" class="fm-input"
                                       placeholder="https://instagram.com/ten-tai-khoan"
                                       value="<?php echo htmlspecialchars($footer['instagram'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="fmTt">TikTok</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('tiktok'); ?></span>
                                <input type="text" name="tiktok" id="fmTt" class="fm-input"
                                       placeholder="https://tiktok.com/@ten-tai-khoan"
                                       value="<?php echo htmlspecialchars($footer['tiktok'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="fm-field">
                            <label class="fm-label" for="fmYt">YouTube</label>
                            <div class="fm-group">
                                <span class="fm-group__icon"><?php echo fmIcon('youtube'); ?></span>
                                <input type="text" name="youtube" id="fmYt" class="fm-input"
                                       placeholder="https://youtube.com/@ten-kenh"
                                       value="<?php echo htmlspecialchars($footer['youtube'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== BẢN QUYỀN ===== -->
                <div class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__title">Bản quyền</h3>
                    </div>
                    <div class="fm-field">
                        <label class="fm-label" for="fmCopy">Dòng bản quyền (Copyright)</label>
                        <input type="text" name="copyright" id="fmCopy" class="fm-input"
                               placeholder="© 2026 THE ATELIER. All rights reserved."
                               value="<?php echo htmlspecialchars($footer['copyright'] ?? ''); ?>">
                    </div>
                </div>

            <?php else: 
                $colName = 'policy_' . str_replace('-', '_', $activeTab);
                $content = $footer[$colName] ?? '';
            ?>
                <!-- ===== KHU VỰC CHÍNH SÁCH ===== -->
                <div class="fm-card">
                    <div class="fm-card__head">
                        <h3 class="fm-card__title">Nội dung: <?php echo $tabLabel; ?></h3>
                        <p class="fm-card__desc">Nội dung này hiển thị ở trang Hỗ trợ khách hàng của website.</p>
                    </div>

                    <div class="fm-tip">
                        <?php echo fmIcon('info', 18); ?>
                        <div>
                            Mỗi mục lớn nên bắt đầu bằng <b>Tiêu đề mục</b> (chọn trong ô “Đoạn văn” trên thanh công cụ),
                            sau đó là đoạn văn hoặc danh sách. Website sẽ tự định dạng, bạn không cần chỉnh màu hay cỡ chữ.
                        </div>
                    </div>

                    <div class="fm-quick">
                        <span class="fm-quick__label">Chèn nhanh:</span>
                        <button type="button" class="fm-chip" data-snippet="heading"><?php echo fmIcon('heading', 15); ?> Tiêu đề mục</button>
                        <button type="button" class="fm-chip" data-snippet="list"><?php echo fmIcon('list', 15); ?> Danh sách</button>
                        <button type="button" class="fm-chip" data-snippet="note"><?php echo fmIcon('quote', 15); ?> Ghi chú lưu ý</button>
                    </div>

                    <div class="fm-field">
                        <!-- Thêm class rich-editor để kích hoạt TinyMCE -->
                        <textarea name="policy_content" id="policyContent" class="fm-textarea rich-editor" rows="20"><?php echo htmlspecialchars($content); ?></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ THANH THAO TÁC (dính dưới) ============ -->
            <div class="fm-bar">
                <div class="fm-bar__left">
                    <span class="fm-status" id="fmStatus" data-state="clean">Chưa có thay đổi</span>
                    <span class="fm-kbd-wrap"><span class="fm-kbd">Ctrl + S</span></span>
                </div>
                <div class="fm-bar__right">
                    <?php if ($activeTab !== 'general'): ?>
                        <a href="../support.php?tab=<?php echo $activeSafe; ?>" target="_blank" class="btn-adm btn-adm--ghost">
                            <?php echo fmIcon('eye', 16); ?> Xem trước trên web
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="btn-adm btn-adm--primary" id="fmSave">
                        <?php echo fmIcon('save', 16); ?> <span id="fmSaveText">Lưu thay đổi</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TÍCH HỢP TRÌNH SOẠN THẢO VĂN BẢN (TINYMCE) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var form   = document.getElementById('fmForm');
    var status = document.getElementById('fmStatus');
    var saveBtn  = document.getElementById('fmSave');
    var saveText = document.getElementById('fmSaveText');
    var dirty = false, submitting = false;

    /* ---------- Trạng thái "chưa lưu" ---------- */
    function setDirty() {
        if (dirty) return;
        dirty = true;
        status.setAttribute('data-state', 'dirty');
        status.textContent = 'Có thay đổi chưa lưu';
    }

    if (form) {
        form.addEventListener('input', setDirty);

        form.addEventListener('submit', function () {
            submitting = true;
            saveBtn.disabled = true;
            saveText.textContent = 'Đang lưu...';
        });

        /* Ctrl/Cmd + S để lưu */
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        });

        /* Cảnh báo khi rời trang mà chưa lưu */
        window.addEventListener('beforeunload', function (e) {
            if (dirty && !submitting) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    /* ---------- Đếm ký tự ô giới thiệu ---------- */
    var about = document.getElementById('fmAbout');
    var aboutCount = document.getElementById('fmAboutCount');
    if (about && aboutCount) {
        var upd = function () { aboutCount.textContent = about.value.length + ' ký tự'; };
        about.addEventListener('input', upd);
        upd();
    }

    /* ---------- TinyMCE ---------- */
    if (document.querySelector('textarea.rich-editor')) {
        tinymce.init({
            selector: 'textarea.rich-editor',
            height: Math.max(720, window.innerHeight - 240),
            min_height: 720,
            resize: true,
            menubar: false, 
            branding: false, 
            promotion: false,
            plugins: 'lists link image table code help wordcount',
            block_formats: 'Đoạn văn=p; Tiêu đề lớn=h2; Tiêu đề mục=h3; Tiêu đề nhỏ=h4',
            toolbar: 'undo redo | blocks | ' +
                     'bold italic forecolor | alignleft aligncenter ' +
                     'alignright alignjustify | bullist numlist outdent indent | ' +
                     'link image table | removeformat | code help',
            
            // ĐÂY LÀ PHẦN QUAN TRỌNG ĐỂ FIX LỖI FONT CHỮ
            content_style: `
                @import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..700&family=Inter:wght@400;500;600&display=swap');
                
                body { 
                    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
                    font-size: 15px; 
                    line-height: 1.7; 
                    color: #1c1b19; 
                    padding: 16px 24px; 
                }
                
                h2, h3, h4 { 
                    font-family: 'Fraunces', 'Playfair Display', Georgia, serif; 
                    font-weight: 600; 
                    color: #1c1b19; 
                    margin-top: 1.5em; 
                    margin-bottom: 0.5em; 
                    line-height: 1.25; 
                }
                h2 { font-size: 1.8rem; } 
                h3 { font-size: 1.45rem; } 
                h4 { font-size: 1.15rem; }
                
                p { margin: 0 0 1em; }
                ul, ol { margin-top: 0; margin-bottom: 1em; padding-left: 1.5em; }
                li { margin-bottom: 0.25em; }
                
                blockquote { 
                    border-left: 3px solid #a8461f; 
                    margin: 1.2em 0; 
                    padding: 8px 0 8px 16px; 
                    color: #4b5563; 
                    background: #faf8f4; 
                    font-style: italic; 
                }
                
                table { border-collapse: collapse; width: 100%; margin-bottom: 1em; }
                th, td { border: 1px solid #e8e0d0; padding: 8px 12px; text-align: left; }
                th { background: #fbf9f5; font-weight: 600; }
                a { color: #a8461f; text-decoration: underline; }
            `,
            
            setup: function (editor) {
                editor.on('change', function () {
                    editor.save();
                });
                editor.on('input change undo redo', setDirty);
            }
        });

        /* Nút chèn nhanh */
        var snippets = {
            heading: '<h3>Tiêu đề mục</h3><p>Nội dung của mục này...</p>',
            list:    '<ul><li>Ý thứ nhất</li><li>Ý thứ hai</li><li>Ý thứ ba</li></ul>',
            note:    '<blockquote><p>Lưu ý: ...</p></blockquote>'
        };
        document.querySelectorAll('.fm-chip[data-snippet]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ed = tinymce.get('policyContent');
                if (!ed) return;
                ed.focus();
                ed.insertContent(snippets[btn.getAttribute('data-snippet')] || '');
                setDirty();
            });
        });
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>