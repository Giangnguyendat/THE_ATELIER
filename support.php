<?php
require_once 'includes/config.php';

/* ============================================================
   TAB CONFIG
   ============================================================ */
$tabs = [
    'return'     => ['🔄', 'Chính sách đổi trả'],
    'privacy'    => ['🔒', 'Chính sách bảo mật'],
    'terms'      => ['📜', 'Điều khoản dịch vụ'],
    'shipping'   => ['🚚', 'Vận chuyển'],
    'payment'    => ['💳', 'Thanh toán'],
    'size-guide' => ['📏', 'Hướng dẫn chọn size'],
    'faq'        => ['❓', 'Câu hỏi thường gặp'],
    'support'    => ['📨', 'Gửi yêu cầu hỗ trợ'], // ✅ Thêm mục này vào cuối
];

$activeTab = $_GET['tab'] ?? 'return';
if (!isset($tabs[$activeTab])) $activeTab = 'return';

// ============================================================
// LẤY NỘI DUNG TỪ DATABASE
// ============================================================
$dbContent = '';
$current = [
    'title'    => $tabs[$activeTab][1],
    'subtitle' => '',
    'intro'    => '',
    'sections' => []
];

// Chỉ lấy dữ liệu chính sách nếu KHÔNG PHẢI tab "support"
if ($activeTab !== 'support') {
    try {
        $stmt = $pdo->query("SELECT * FROM footer_settings WHERE id = 1");
        $footerData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $colName = 'policy_' . str_replace('-', '_', $activeTab);
        $dbContent = $footerData[$colName] ?? '';
    } catch (Exception $e) {}

    if (!empty($dbContent)) {
        $current['sections'][] = [
            'type'  => 'text',
            'title' => '', 
            'items' => [$dbContent]
        ];
    } else {
        $current['sections'][] = [
            'type'  => 'text',
            'title' => 'Nội dung đang được cập nhật',
            'items' => ['<p style="color:#8a8377; font-style:italic;">Chính sách này hiện đang được THE ATELIER cập nhật. Vui lòng quay lại sau hoặc liên hệ hotline để được hỗ trợ.</p>']
        ];
    }
}

/* ============================================================
   FORM HỖ TRỢ — CSRF
   ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLogged = isLoggedIn();
$prefillName  = '';
$prefillEmail = '';
$prefillPhone = '';
if ($isLogged) {
    $stmtU = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $u = $stmtU->fetch(PDO::FETCH_ASSOC) ?: [];
    $prefillName  = $u['full_name'] ?? '';
    $prefillEmail = $u['email'] ?? '';
    $prefillPhone = $u['phone'] ?? '';
}
?>
<?php include 'includes/header.php'; ?>

<style>
    /* ============================================================
       THE ATELIER — SUPPORT PAGE (2-COLUMN LAYOUT)
       ============================================================ */
    @import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400;1,9..144,500&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap');

    .sp-page {
        --c-ink:    #1c1b19;
        --c-ivory:  #f2ece0;
        --c-rust:   #a8461f;
        --c-stone:  #8a8377;
        --c-border: #e8e0d0;
        --c-white:  #fffdf9;
        
        background: var(--c-ivory);
        color: var(--c-ink);
        font-family: 'Inter', -apple-system, sans-serif;
        font-size: 16px;
        line-height: 1.65;
        min-height: 60vh;
        padding: 40px 0 80px;
    }

    .sp-wrap { max-width: 1140px; margin: 0 auto; padding: 0 20px; }

    /* ============ HEADER ============ */
    .sp-hero { margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid var(--c-border); }
    .sp-hero__label {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.72rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--c-rust);
        margin-bottom: 12px;
        display: block;
        font-weight: 600;
    }
    .sp-hero h1 {
        font-family: 'Fraunces', Georgia, serif;
        font-size: clamp(2rem, 3.5vw, 2.8rem);
        font-weight: 400;
        margin: 0;
        color: var(--c-ink);
        letter-spacing: -0.02em;
        line-height: 1.1;
    }
    .sp-hero h1 em { font-style: italic; color: var(--c-rust); }

    /* ============ LAYOUT (2 CỘT) ============ */
    .sp-layout {
        display: grid;
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 40px;
        align-items: start;
    }

    /* ============ SIDEBAR TABS (DỌC) ============ */
    .sp-sidebar {
        position: sticky;
        top: 100px;
        background: var(--c-white);
        border: 1px solid var(--c-border);
        border-radius: 12px;
        padding: 12px;
        box-shadow: 0 4px 15px rgba(28,27,25,0.03);
    }

    .sp-sidebar__title {
        font-size: 0.75rem;
        font-family: 'IBM Plex Mono', monospace;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--c-stone);
        margin: 8px 12px 12px;
        font-weight: 600;
    }

    .sp-tabs {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .sp-tab {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 8px;
        color: #4b5563;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.18s ease;
        border-left: 3px solid transparent;
    }

    .sp-tab:hover {
        background: #faf6ee;
        color: var(--c-ink);
    }

    .sp-tab.active {
        background: #fdf7f3;
        color: var(--c-rust);
        font-weight: 600;
        border-left-color: var(--c-rust);
    }

    .sp-tab__icon { font-size: 1.1rem; }

    /* Đường phân cách cho mục Hỗ trợ */
    .sp-tab--support {
        margin-top: 8px;
        border-top: 1px dashed var(--c-border);
        padding-top: 16px;
        border-radius: 0 0 8px 8px;
    }

    /* ============ CONTENT CHÍNH ============ */
    .sp-main {
        background: var(--c-white);
        border: 1px solid var(--c-border);
        border-radius: 12px;
        padding: 40px 48px;
        box-shadow: 0 4px 20px rgba(28,27,25,0.03);
    }

    /* Style cho HTML do Admin nhập */
    .sp-prose {
        font-size: 1rem;
        color: #37423a;
        line-height: 1.8;
    }
    
    .sp-prose > :first-child { margin-top: 0; }
    .sp-prose > :last-child { margin-bottom: 0; }

    .sp-prose h1, .sp-prose h2, .sp-prose h3, .sp-prose h4 {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 500;
        color: var(--c-ink);
        line-height: 1.3;
        margin-top: 1.8em;
        margin-bottom: 0.6em;
    }
    .sp-prose h1 { font-size: 1.8rem; border-bottom: 2px solid var(--c-border); padding-bottom: 10px; }
    .sp-prose h2 { font-size: 1.5rem; color: var(--c-rust); }
    .sp-prose h3 { font-size: 1.25rem; }
    .sp-prose h4 { font-size: 1.1rem; }

    .sp-prose p { margin-bottom: 1.2em; }
    .sp-prose strong, .sp-prose b { color: var(--c-ink); font-weight: 600; }
    .sp-prose a { color: var(--c-rust); text-decoration: underline; text-underline-offset: 3px; }
    
    .sp-prose ul, .sp-prose ol { margin: 0 0 1.2em; padding-left: 1.5em; }
    .sp-prose li { margin-bottom: 0.6em; }
    .sp-prose li::marker { color: var(--c-rust); font-weight: bold; }

    /* Bảng */
    .sp-prose table {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5em 0;
        font-size: 0.92rem;
        border: 1px solid var(--c-border);
        border-radius: 8px;
        overflow: hidden;
    }
    .sp-prose th, .sp-prose td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--c-border);
        text-align: left;
    }
    .sp-prose th {
        background: #faf6ee;
        color: var(--c-ink);
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .sp-prose tr:last-child td { border-bottom: none; }
    .sp-prose tr:hover td { background: #faf6ee; }

    /* Blockquote / Lưu ý */
    .sp-prose blockquote {
        margin: 1.5em 0;
        padding: 16px 24px;
        background: #fdf7f3;
        border-left: 4px solid var(--c-rust);
        border-radius: 0 8px 8px 0;
        color: #7c3216;
        font-style: italic;
    }

    /* ============ FORM HỖ TRỢ (Nằm trong cột phải) ============ */
    .sp-form-box {
        background: #fdfbf7;
        border: 1px solid var(--c-border);
        border-radius: 12px;
        padding: 30px 40px 40px;
        position: relative;
    }
    .sp-form-box::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--c-rust) 0%, #b98a1d 100%);
        border-radius: 12px 12px 0 0;
    }
    .sp-form-header {
        margin-bottom: 28px;
    }
    .sp-form-header__label {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.7rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--c-rust);
        margin-bottom: 8px;
        display: block;
        font-weight: 600;
    }
    .sp-form-header__title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.8rem;
        font-weight: 400;
        margin: 0 0 8px;
        color: var(--c-ink);
    }
    .sp-form-header__title em { font-style: italic; color: var(--c-rust); }
    .sp-form-header__desc {
        font-size: 0.95rem;
        color: #6b7280;
        margin: 0;
        line-height: 1.6;
    }
    .sp-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    .sp-field--full { grid-column: span 2; }
    .sp-field label {
        display: block;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--c-stone);
        margin-bottom: 8px;
    }
    .sp-field label .req { color: var(--c-rust); margin-left: 2px; }
    .sp-field input,
    .sp-field select,
    .sp-field textarea {
        width: 100%;
        padding: 12px 16px;
        background: var(--c-white);
        border: 1px solid var(--c-border);
        border-radius: 8px;
        color: var(--c-ink);
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        transition: all 0.2s;
        box-sizing: border-box;
    }
    .sp-field textarea { resize: vertical; min-height: 140px; line-height: 1.6; }
    .sp-field input::placeholder,
    .sp-field textarea::placeholder { color: #9ca3af; }
    .sp-field input:focus,
    .sp-field select:focus,
    .sp-field textarea:focus {
        border-color: var(--c-rust);
        background: var(--c-white);
        box-shadow: 0 0 0 3px rgba(168,70,31,0.1);
    }
    .sp-field select {
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'><path d='M3 4.5l3 3 3-3' fill='none' stroke='%238a8377' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
        background-repeat: no-repeat;
        background-position: right 14px center;
        background-size: 11px;
        padding-right: 38px;
    }
    .sp-form-footer {
        margin-top: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }
    .sp-form-msg {
        font-size: 0.85rem;
        min-height: 1em;
        font-family: 'IBM Plex Mono', monospace;
        font-weight: 600;
    }
    .sp-form-msg.is-success { color: #166534; }
    .sp-form-msg.is-error   { color: #b91c1c; }
    .sp-form-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 14px 32px;
        background: var(--c-rust);
        color: var(--c-white);
        border: none;
        border-radius: 6px;
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.25s;
        box-shadow: 0 4px 16px rgba(168,70,31,0.25);
    }
    .sp-form-btn:hover:not(:disabled) {
        background: #8f3a18;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(168,70,31,0.35);
    }
    .sp-form-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    /* ============ RESPONSIVE (Mobile) ============ */
    @media (max-width: 900px) {
        .sp-layout { grid-template-columns: 1fr; gap: 24px; }
        
        .sp-sidebar {
            position: static;
            padding: 0;
            background: transparent;
            border: none;
            box-shadow: none;
        }
        
        .sp-sidebar__title { display: none; }

        /* Tabs dọc chuyển thành Grid 2 cột cho gọn trên mobile */
        .sp-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .sp-tab {
            background: var(--c-white);
            border: 1px solid var(--c-border);
            border-left: 1px solid var(--c-border);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.85rem;
            justify-content: center;
            text-align: center;
        }

        .sp-tab.active {
            background: var(--c-ink);
            color: var(--c-white);
            border-color: var(--c-ink);
        }
        
        /* Xử lý nút Hỗ trợ trên mobile */
        .sp-tab--support {
            grid-column: span 2;
            margin-top: 4px;
            border-top: none;
            padding-top: 10px;
        }
        
        .sp-main { padding: 24px 20px; }
        .sp-form-box { padding: 24px 20px; }
        .sp-form-grid { grid-template-columns: 1fr; }
        .sp-field--full { grid-column: span 1; }
        .sp-form-btn { width: 100%; justify-content: center; }
    }
</style>

<main class="sp-page">
    <div class="sp-wrap">

        <!-- HEADER -->
        <section class="sp-hero">
            <span class="sp-hero__label">THE ATELIER · Hỗ trợ khách hàng</span>
            <h1><?php
                $words = explode(' ', $current['title']);
                $last  = array_pop($words);
                echo htmlspecialchars(implode(' ', $words)) . ' <em>' . htmlspecialchars($last) . '</em>';
            ?></h1>
        </section>

        <!-- LAYOUT 2 CỘT -->
        <div class="sp-layout">

            <!-- CỘT TRÁI: SIDEBAR TABS -->
            <aside class="sp-sidebar">
                <div class="sp-sidebar__title">Danh mục hỗ trợ</div>
                <nav class="sp-tabs">
                    <?php foreach ($tabs as $key => $info): 
                        // Thêm class đặc biệt cho tab 'support'
                        $isSupport = ($key === 'support');
                        $classList = 'sp-tab';
                        if ($key === $activeTab) $classList .= ' active';
                        if ($isSupport) $classList .= ' sp-tab--support';
                    ?>
                        <a href="support.php?tab=<?php echo $key; ?>" class="<?php echo $classList; ?>">
                            <span class="sp-tab__icon"><?php echo $info[0]; ?></span>
                            <?php echo htmlspecialchars($info[1]); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <!-- CỘT PHẢI: NỘI DUNG HOẶC FORM -->
            <div class="sp-main">
                
                <?php if ($activeTab === 'support'): ?>
                    <!-- ============ HIỂN THỊ FORM HỖ TRỢ ============ -->
                    <div class="sp-form-box">
                        <div class="sp-form-header">
                            <span class="sp-form-header__label">Cần hỗ trợ thêm?</span>
                            <h2 class="sp-form-header__title">Gửi <em>yêu cầu</em> tới admin</h2>
                            <p class="sp-form-header__desc">
                                Vui lòng điền thông tin vào form bên dưới. Chúng tôi sẽ kiểm tra và phản hồi bạn qua email trong vòng <b>24 giờ làm việc</b>.
                            </p>
                        </div>

                        <form id="supportForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                            <div class="sp-form-grid">
                                <div class="sp-field">
                                    <label>Họ tên <span class="req">*</span></label>
                                    <input type="text" name="name" id="spName" maxlength="100" required
                                           value="<?php echo htmlspecialchars($prefillName); ?>"
                                           placeholder="Nguyễn Văn A">
                                </div>
                                <div class="sp-field">
                                    <label>Email <span class="req">*</span></label>
                                    <input type="email" name="email" id="spEmail" maxlength="150" required
                                           value="<?php echo htmlspecialchars($prefillEmail); ?>"
                                           placeholder="email@example.com">
                                </div>
                                <div class="sp-field">
                                    <label>Số điện thoại</label>
                                    <input type="tel" name="phone" id="spPhone" maxlength="20"
                                           value="<?php echo htmlspecialchars($prefillPhone); ?>"
                                           placeholder="0912 345 678">
                                </div>
                                <div class="sp-field">
                                    <label>Chủ đề <span class="req">*</span></label>
                                    <select name="subject" id="spSubject" required>
                                        <option value="">-- Chọn chủ đề --</option>
                                        <option value="order">Vấn đề về đơn hàng</option>
                                        <option value="return">Yêu cầu đổi trả / hoàn tiền</option>
                                        <option value="payment">Thanh toán / ví Atelier Pay</option>
                                        <option value="product">Sản phẩm / size / chất liệu</option>
                                        <option value="shipping">Vận chuyển / giao hàng</option>
                                        <option value="account">Tài khoản / mật khẩu</option>
                                        <option value="other">Khác</option>
                                    </select>
                                </div>
                                <div class="sp-field sp-field--full">
                                    <label>Nội dung <span class="req">*</span></label>
                                    <textarea name="message" id="spMessage" maxlength="2000" required
                                              placeholder="Mô tả chi tiết vấn đề bạn gặp phải (kèm mã đơn hàng nếu có)..."></textarea>
                                </div>
                            </div>

                            <div class="sp-form-footer">
                                <div class="sp-form-msg" id="spFormMsg"></div>
                                <button type="submit" class="sp-form-btn" id="spSubmitBtn">
                                    📨 Gửi yêu cầu
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- ============ HIỂN THỊ CHÍNH SÁCH ============ -->
                    
                    <?php if (!empty($current['intro'])): ?>
                        <p style="font-size:1.05rem; color:var(--c-ink); margin-bottom:30px; font-style:italic;">
                            <?php echo htmlspecialchars($current['intro']); ?>
                        </p>
                    <?php endif; ?>

                    <?php foreach ($current['sections'] as $secIdx => $sec): ?>
                        <?php if ($sec['type'] === 'text'): ?>
                            <div class="sp-prose">
                                <?php foreach ($sec['items'] as $li): ?>
                                    <?php echo $li; /* Render HTML từ Database */ ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                <?php endif; ?>
                
            </div>

        </div>
    </div>
</main>

<script>
(function () {
    var form = document.getElementById('supportForm');
    if (!form) return;

    var msgEl = document.getElementById('spFormMsg');
    var btn   = document.getElementById('spSubmitBtn');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msgEl.className = 'sp-form-msg';
        msgEl.textContent = '';

        var name    = document.getElementById('spName').value.trim();
        var email   = document.getElementById('spEmail').value.trim();
        var phone   = document.getElementById('spPhone').value.trim();
        var subject = document.getElementById('spSubject').value;
        var message = document.getElementById('spMessage').value.trim();

        if (!name) { showErr('Vui lòng nhập họ tên.'); return; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) { showErr('Email không hợp lệ.'); return; }
        if (!subject) { showErr('Vui lòng chọn chủ đề.'); return; }
        if (message.length < 10) { showErr('Nội dung cần ít nhất 10 ký tự.'); return; }

        btn.disabled = true;
        var oldTxt = btn.textContent;
        btn.textContent = '⏳ Đang gửi...';

        var fd = new FormData(form);

        fetch('ajax/submit_support.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    msgEl.textContent = '✓ ' + (d.msg || 'Đã gửi yêu cầu thành công!');
                    msgEl.classList.add('is-success');
                    form.reset();
                    if (<?php echo $isLogged ? 'true' : 'false'; ?>) {
                        document.getElementById('spName').value  = <?php echo json_encode($prefillName); ?>;
                        document.getElementById('spEmail').value = <?php echo json_encode($prefillEmail); ?>;
                        document.getElementById('spPhone').value = <?php echo json_encode($prefillPhone); ?>;
                    }
                    setTimeout(function () {
                        msgEl.className = 'sp-form-msg';
                    }, 6000);
                } else {
                    showErr(d.msg || 'Có lỗi xảy ra.');
                }
            })
            .catch(function () {
                showErr('Không thể kết nối tới máy chủ.');
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = oldTxt;
            });
    });

    function showErr(msg) {
        msgEl.textContent = '⚠ ' + msg;
        msgEl.classList.add('is-error');
    }
})();
</script>

<?php include 'includes/footer.php'; ?>