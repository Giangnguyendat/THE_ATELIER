<?php
/**
 * SITE FOOTER
 * Bao gồm: brand, links, contact, nút gửi yêu cầu hỗ trợ (mở Modal)
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$__csrf = $_SESSION['csrf_token'];
// Lấy dữ liệu Footer từ Database
$footerData = [];
try {
    $footerData = $pdo->query("SELECT * FROM footer_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    // Bỏ qua nếu bảng chưa tồn tại
}

// Hàm hỗ trợ lấy dữ liệu an toàn (nếu DB rỗng thì dùng mặc định)
$fd = function($key, $default = '') use ($footerData) {
    return htmlspecialchars($footerData[$key] ?? $default);
};
?>
</main> <!-- Đóng thẻ main từ header.php -->

<style>
    /* ============================================================
       THE ATELIER — SITE FOOTER
       ============================================================ */
    .site-footer {
        --f-ink:       #1c1b19;
        --f-ivory:     #f2ece0;
        --f-ivory-dim: #e8e0d0;
        --f-rust:      #a8461f;
        --f-rust-deep: #7c3216;
        --f-gold:      #b98a1d;
        --f-stone:     #8a8377;
        --f-border:    #2e2b26;

        background: var(--f-ink);
        color: var(--f-ivory);
        padding: 4rem 0 2rem;
        margin-top: auto;
        font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.9rem;
        line-height: 1.65;
        position: relative;
    }

    .site-footer::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--f-rust) 0%, var(--f-gold) 100%);
    }

    .site-footer a {
        color: var(--f-ivory);
        text-decoration: none;
        transition: color 0.2s ease;
    }
    .site-footer a:hover { color: var(--f-rust); }

    /* ---- Container ---- */
    .sf-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* ---- Grid ---- */
    .sf-grid {
        display: grid;
        grid-template-columns: 1.6fr 1fr 1fr 1.6fr;
        gap: 3rem;
        margin-bottom: 3rem;
    }
    @media (max-width: 1024px) { .sf-grid { grid-template-columns: 1fr 1fr; gap: 2.5rem; } }
    @media (max-width: 640px)  { .sf-grid { grid-template-columns: 1fr; gap: 2rem; } }

    /* ---- Brand column ---- */
    .sf-brand__logo {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.85rem;
        font-weight: 600;
        font-style: italic;
        letter-spacing: -0.01em;
        color: var(--f-ivory);
        display: inline-block;
        margin-bottom: 1rem;
        line-height: 1;
    }
    .sf-brand__logo .dot { color: var(--f-rust); font-style: normal; }
    .sf-brand__tagline {
        font-family: 'IBM Plex Mono', ui-monospace, monospace;
        font-size: 0.72rem;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: var(--f-stone);
        margin: 0 0 1.25rem;
    }
    .sf-brand__desc {
        color: var(--f-ivory);
        opacity: 0.75;
        margin: 0 0 1.5rem;
        font-size: 0.875rem;
    }

    /* ---- Socials ---- */
    .sf-socials { display: flex; gap: 0.6rem; flex-wrap: wrap; }
    .sf-social-btn {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: rgba(242, 236, 224, 0.06);
        border: 1px solid var(--f-border);
        color: var(--f-ivory);
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.25s ease;
    }
    .sf-social-btn:hover {
        background: var(--f-rust); border-color: var(--f-rust); color: #fff;
        transform: translateY(-2px);
    }
    .sf-social-btn svg { width: 18px; height: 18px; fill: currentColor; }

    /* ---- Column titles ---- */
    .sf-title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.05rem; font-weight: 600;
        color: var(--f-ivory);
        margin: 0 0 1.25rem; letter-spacing: 0.01em;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid var(--f-border);
        display: inline-block;
    }

    /* ---- Links ---- */
    .sf-links { list-style: none; padding: 0; margin: 0; }
    .sf-links li { margin-bottom: 0.7rem; }
    .sf-links a {
        color: var(--f-ivory); opacity: 0.75; font-size: 0.875rem;
        display: inline-block; transition: all 0.2s ease;
    }
    .sf-links a:hover { opacity: 1; color: var(--f-rust); padding-left: 4px; }

    /* ---- Contact info ---- */
    .sf-contact { list-style: none; padding: 0; margin: 0 0 1.5rem; }
    .sf-contact li {
        display: flex; align-items: flex-start; gap: 0.65rem;
        margin-bottom: 0.7rem; font-size: 0.875rem;
        opacity: 0.75; line-height: 1.5;
    }
    .sf-contact svg {
        width: 16px; height: 16px; flex-shrink: 0; margin-top: 3px;
        stroke: var(--f-rust); fill: none; stroke-width: 1.8;
        stroke-linecap: round; stroke-linejoin: round;
    }

    /* ---- Support Trigger Button ---- */
    .sf-support-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 12px 20px;
        background: rgba(168, 70, 31, 0.15);
        border: 1px solid var(--f-rust);
        color: var(--f-ivory);
        font-family: 'IBM Plex Mono', monospace;
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        justify-content: center;
    }
    .sf-support-trigger:hover {
        background: var(--f-rust);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(168, 70, 31, 0.3);
    }

    /* ---- Bottom bar ---- */
    .sf-bottom {
        border-top: 1px solid var(--f-border);
        padding-top: 1.75rem;
        display: flex; flex-wrap: wrap; justify-content: space-between;
        align-items: center; gap: 1rem;
        font-size: 0.82rem; color: var(--f-stone);
    }
    .sf-bottom__copy { margin: 0; font-family: 'IBM Plex Mono', monospace; letter-spacing: 0.02em; }
    .sf-bottom__links { display: flex; flex-wrap: wrap; gap: 1.5rem; }
    .sf-bottom__links a { color: var(--f-stone); font-size: 0.82rem; }
    .sf-bottom__links a:hover { color: var(--f-rust); }
    @media (max-width: 640px) {
        .sf-bottom { flex-direction: column; text-align: center; }
        .sf-bottom__links { justify-content: center; gap: 1rem; }
    }

    /* ============================================================
       MODAL HỖ TRỢ (Popup)
       ============================================================ */
    .sf-modal {
        position: fixed;
        inset: 0;
        background: rgba(28, 27, 25, 0.75);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        z-index: 100000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }
    .sf-modal.show {
        display: flex;
        animation: sfFadeIn 0.25s ease;
    }
    @keyframes sfFadeIn { from { opacity: 0; } to { opacity: 1; } }

    .sf-modal-box {
        background: #fffdf9;
        color: #1c1b19;
        border-radius: 16px;
        width: 100%;
        max-width: 480px;
        padding: 2rem;
        position: relative;
        box-shadow: 0 24px 60px rgba(0,0,0,0.3);
        animation: sfSlideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes sfSlideUp {
        from { opacity: 0; transform: translateY(30px) scale(0.95); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .sf-modal-close {
        position: absolute;
        top: 1rem; right: 1rem;
        width: 32px; height: 32px;
        border-radius: 50%;
        border: none;
        background: #f2ece0;
        color: #1c1b19;
        font-size: 1.2rem;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all 0.15s ease;
    }
    .sf-modal-close:hover { background: #e8e0d0; transform: rotate(90deg); }

    .sf-modal-title {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.4rem;
        font-weight: 600;
        margin: 0 0 0.5rem;
        color: #1c1b19;
    }
    .sf-modal-desc {
        font-size: 0.85rem;
        color: #6b7280;
        margin: 0 0 1.5rem;
        line-height: 1.5;
    }

    .sf-modal-form { display: flex; flex-direction: column; gap: 12px; }
    
    .sf-modal-input {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.9rem;
        font-family: inherit;
        color: #1c1b19;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
        box-sizing: border-box;
    }
    .sf-modal-input:focus {
        border-color: #a8461f;
        box-shadow: 0 0 0 3px rgba(168, 70, 31, 0.1);
    }
    textarea.sf-modal-input { resize: vertical; min-height: 100px; }

    .sf-modal-btn {
        padding: 14px;
        background: #a8461f;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 4px;
    }
    .sf-modal-btn:hover:not(:disabled) { background: #8f3a18; }
    .sf-modal-btn:disabled { opacity: 0.7; cursor: not-allowed; }

    .sf-support-msg {
        font-size: 0.82rem;
        margin: 0.5rem 0 0;
        min-height: 1em;
        display: none;
        font-weight: 600;
        text-align: center;
    }
    .sf-support-msg.is-success { color: #166534; display: block; }
    .sf-support-msg.is-error   { color: #b91c1c; display: block; }
</style>

<footer class="site-footer">
    <div class="sf-container">
        <div class="sf-grid">

            <!-- CỘT 1: BRAND -->
            <div class="sf-brand">
                <a href="index.php" class="sf-brand__logo">
                    The Atelier<span class=""> .</span>
                </a>
                <p class="sf-brand__tagline">Lookbook · Editorial Fashion</p>
                <p class="sf-brand__desc">
    <?php echo $fd('about_text', 'Thời trang chất lượng cao với phong cách editorial tinh tế. Mỗi sản phẩm là một câu chuyện — chúng tôi kể bằng chất liệu, đường may và những chi tiết nhỏ nhất.'); ?>
</p>

                <div class="sf-socials">
                    <a href="#" class="sf-social-btn" title="Facebook" aria-label="Facebook">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.5-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.77l-.44 2.89h-2.33v6.99A10 10 0 0 0 22 12z"/></svg>
                    </a>
                    <a href="#" class="sf-social-btn" title="Instagram" aria-label="Instagram">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.05.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.05.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.05-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.05-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.13 1.38C1.35 2.68.93 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.79.72 1.46 1.38 2.13.67.66 1.34 1.08 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.3 1.46-.72 2.13-1.38.66-.67 1.08-1.34 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.3-.79-.72-1.46-1.38-2.13-.67-.66-1.34-1.08-2.13-1.38-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zm0 10.16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm7.84-10.4a1.44 1.44 0 1 1-2.88 0 1.44 1.44 0 0 1 2.88 0z"/></svg>
                    </a>
                    <a href="#" class="sf-social-btn" title="TikTok" aria-label="TikTok">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.3 0 .59.05.86.13V9.4a6.33 6.33 0 0 0-.86-.05A6.34 6.34 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.04-.1z"/></svg>
                    </a>
                    <a href="#" class="sf-social-btn" title="YouTube" aria-label="YouTube">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M23.5 6.2a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.51A3.02 3.02 0 0 0 .5 6.2C0 8.08 0 12 0 12s0 3.92.5 5.8a3.02 3.02 0 0 0 2.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 0 0 2.12-2.14C24 15.92 24 12 24 12s0-3.92-.5-5.8zM9.6 15.6V8.4l6.27 3.6L9.6 15.6z"/></svg>
                    </a>
                </div>
            </div>

            <!-- CỘT 2: KHÁM PHÁ -->
            <div>
                <h4 class="sf-title">Khám Phá</h4>
                <ul class="sf-links">
                    <li><a href="index.php">Trang chủ</a></li>
                    <li><a href="products.php">Tất cả sản phẩm</a></li>
                    <li><a href="products.php?sort=discount">Đang giảm giá</a></li>
                    <li><a href="products.php?sort=best_seller">Bán chạy nhất</a></li>
                    <li><a href="products.php?gender=female">Bộ sưu tập Nữ</a></li>
                    <li><a href="products.php?gender=male">Bộ sưu tập Nam</a></li>
                    <li><a href="products.php?gender=unisex">Unisex</a></li>
                </ul>
            </div>

            <!-- CỘT 3: HỖ TRỢ -->
            <div>
                <h4 class="sf-title">Hỗ Trợ</h4>
                <ul class="sf-links">
                    <li><a href="support.php?tab=size-guide">Hướng dẫn chọn size</a></li>
                    <li><a href="support.php?tab=return">Chính sách đổi trả</a></li>
                    <li><a href="support.php?tab=privacy">Chính sách bảo mật</a></li>
                    <li><a href="support.php?tab=terms">Điều khoản dịch vụ</a></li>
                    <li><a href="support.php?tab=shipping">Chính sách vận chuyển</a></li>
                    <li><a href="support.php?tab=payment">Chính sách thanh toán</a></li>
                    <li><a href="support.php?tab=faq">Câu hỏi thường gặp</a></li>
                    <li><a href="account.php">Tra cứu đơn hàng</a></li>
                </ul>
            </div>

            <!-- CỘT 4: LIÊN HỆ & NÚT HỖ TRỢ -->
            <div>
                <h4 class="sf-title">Liên Hệ</h4>

                <ul class="sf-contact">
    <li>
        <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span><?php echo $fd('address', 'số 6 phố Vũ Đức Úy, phường Thanh Liệt, Hà Nội'); ?></span>
    </li>
    <li>
        <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $fd('phone', '1900123456')); ?>">
            <?php echo $fd('phone', '1900 123 456'); ?>
        </a>
    </li>
    <li>
        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <a href="mailto:<?php echo $fd('email', 'hello@theatelier.vn'); ?>">
            <?php echo $fd('email', 'hello@theatelier.vn'); ?>
        </a>
    </li>
</ul>

                <!-- Nút mở Modal -->
                <button type="button" class="sf-support-trigger" id="openSupportModal">
                     Gửi yêu cầu hỗ trợ
                </button>
                
                <a href="support.php" class="sf-support-link" style="display:block;text-align:center;margin-top:1rem;font-size:0.75rem;color:#b98a1d;text-decoration:none;">
                    Xem thêm các chủ đề hỗ trợ →
                </a>
            </div>

        </div><!-- /.sf-grid -->

        <!-- BOTTOM -->
        <div class="sf-bottom">
            <p class="sf-bottom__copy">
                <?php echo $fd('copyright', '© ' . date('Y') . ' THE ATELIER — Made with care.'); ?>
            </p>
            <div class="sf-bottom__links">
                <a href="support.php?tab=privacy">Quyền riêng tư</a>
                <a href="support.php?tab=terms">Điều khoản</a>
                <a href="support.php?tab=payment">Thanh toán</a>
                <a href="support.php?tab=shipping">Vận chuyển</a>
            </div>
        </div>
    </div>
</footer>

<!-- MODAL HỖ TRỢ -->
<div class="sf-modal" id="supportModal" aria-hidden="true">
    <div class="sf-modal-box" role="dialog" aria-modal="true">
        <button type="button" class="sf-modal-close" id="closeSupportModal" aria-label="Đóng">&times;</button>
        
        <h3 class="sf-modal-title">Gửi yêu cầu hỗ trợ</h3>
        <p class="sf-modal-desc">Vui lòng để lại thông tin, chúng tôi sẽ phản hồi bạn qua email trong thời gian sớm nhất.</p>

        <form class="sf-modal-form" id="sfSupportForm" novalidate>
            <input type="text"
                   class="sf-modal-input"
                   id="sfSupportName"
                   placeholder="Họ tên *"
                   autocomplete="name"
                   maxlength="100"
                   required>

            <input type="email"
                   class="sf-modal-input"
                   id="sfSupportEmail"
                   placeholder="Email *"
                   autocomplete="email"
                   maxlength="150"
                   required>

            <textarea class="sf-modal-input"
                      id="sfSupportMsg"
                      placeholder="Nội dung cần hỗ trợ (ít nhất 10 ký tự) *"
                      rows="3"
                      maxlength="500"
                      required></textarea>

            <button type="submit" class="sf-modal-btn" id="sfSupportBtn">
                Gửi yêu cầu ngay
            </button>
        </form>

        <p class="sf-support-msg" id="sfSupportResponse"></p>
    </div>
</div>

<script>
(function () {
    var modal    = document.getElementById('supportModal');
    var openBtn  = document.getElementById('openSupportModal');
    var closeBtn = document.getElementById('closeSupportModal');
    
    var form     = document.getElementById('sfSupportForm');
    var nameEl   = document.getElementById('sfSupportName');
    var emailEl  = document.getElementById('sfSupportEmail');
    var msgEl    = document.getElementById('sfSupportMsg');
    var submitBtn= document.getElementById('sfSupportBtn');
    var respEl   = document.getElementById('sfSupportResponse');

    // Mở/Đóng Modal
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            setTimeout(function() { nameEl.focus(); }, 100);
        });
    }

    function closeModal() {
        modal.classList.remove('show');
        document.body.style.overflow = '';
        setTimeout(function() {
            respEl.className = 'sf-support-msg';
            respEl.textContent = '';
        }, 300);
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Click ra ngoài để đóng
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }
    
    // ESC để đóng
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
    });

    // Xử lý submit form
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            respEl.className = 'sf-support-msg';
            respEl.textContent = '';

            var name  = (nameEl.value || '').trim();
            var email = (emailEl.value || '').trim();
            var msg   = (msgEl.value || '').trim();

            if (!name) {
                showMsg('error', '⚠ Vui lòng nhập họ tên.');
                nameEl.focus(); return;
            }
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
                showMsg('error', '⚠ Email không hợp lệ.');
                emailEl.focus(); return;
            }
            if (msg.length < 10) {
                showMsg('error', '⚠ Nội dung cần ít nhất 10 ký tự.');
                msgEl.focus(); return;
            }

            submitBtn.disabled = true;
            var oldTxt = submitBtn.textContent;
            submitBtn.textContent = '⏳ Đang gửi...';

            var fd = new FormData();
            fd.append('csrf_token', <?php echo json_encode($__csrf); ?>);
            fd.append('name', name);
            fd.append('email', email);
            fd.append('subject', 'other');
            fd.append('message', msg);

            fetch('ajax/submit_support.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) {
                        showMsg('success', '✓ ' + (d.msg || 'Đã gửi yêu cầu thành công!'));
                        form.reset();
                        setTimeout(closeModal, 2500);
                    } else {
                        showMsg('error', '⚠ ' + (d.msg || 'Có lỗi xảy ra.'));
                        submitBtn.disabled = false;
                        submitBtn.textContent = oldTxt;
                    }
                })
                .catch(function () {
                    showMsg('error', '⚠ Không kết nối được server.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = oldTxt;
                });
        });
    }

    function showMsg(type, text) {
        respEl.className = 'sf-support-msg ' + (type === 'success' ? 'is-success' : 'is-error');
        respEl.textContent = text;
    }
})();
</script>

</body>
</html>