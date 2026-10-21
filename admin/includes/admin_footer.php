<!-- KHÔNG ĐÓNG admin-main__inner Ở ĐÂY NỮA ĐỂ ĐƯA FOOTER VÀO TRONG -->
    
<?php 
// Tự động lấy dữ liệu Footer từ Database
global $pdo;
$footerData = [];
if (isset($pdo)) {
    try {
        $footerData = $pdo->query("SELECT * FROM footer_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<style>
/* ============================================================ 
   ADMIN DASHBOARD FOOTER (Dùng chung)
   ============================================================ */
.dash-footer {
    background: #1c1b19;
    color: #f2ece0;
    border-radius: 16px;
    padding: 2rem 2rem 1.5rem;
    box-shadow: 0 18px 40px -22px rgba(28, 27, 25, .55);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    margin-top: 3rem; /* Tạo khoảng cách với nội dung phía trên */
    margin-bottom: 1rem;
}
.dash-footer::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #a8461f 0%, #b98a1d 100%);
}
.df-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}
.df-brand h2 {
    font-family: 'Fraunces', serif;
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0 0 .5rem;
    color: #fffdf9;
}
.df-brand h2 span { color: #a8461f; }
.df-brand p {
    font-size: 0.85rem;
    color: #8a8377;
    line-height: 1.6;
    max-width: 400px;
    margin: 0 0 1.2rem;
}

/* SOCIAL ICONS */
.df-socials { display: flex; gap: 10px; }
.df-socials a {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(242, 236, 224, 0.08);
    display: flex; align-items: center; justify-content: center;
    color: #f2ece0;
    text-decoration: none;
    transition: all 0.2s;
}
.df-socials a:hover { background: #a8461f; transform: translateY(-2px); }

.df-contact h3 {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #8a8377;
    margin: 0 0 1rem;
}
.df-contact ul {
    list-style: none; padding: 0; margin: 0;
    font-size: 0.85rem;
    line-height: 1.8;
    color: #e8e0d0;
}
.df-contact li { display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-start; }
.df-contact svg { flex-shrink: 0; margin-top: 3px; color: #a8461f; }

.df-bottom {
    border-top: 1px solid rgba(255, 253, 249, 0.1);
    padding-top: 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.78rem;
    color: #8a8377;
}
.df-manage-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(242, 236, 224, 0.1);
    color: #f2ece0;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.df-manage-btn:hover { background: #a8461f; color: #fff; }

/* ---------- RESPONSIVE ---------- */
@media (max-width: 768px) {
    .df-grid { grid-template-columns: 1fr; gap: 1.5rem; }
    .df-bottom { flex-direction: column; gap: 1rem; text-align: center; }
}
</style>

<footer class="dash-footer no-print">
    <div class="df-grid">
        <!-- CỘT TRÁI: BRAND + SOCIAL -->
        <div class="df-brand">
            <h2>THE ATELIER<span>.</span></h2>
            <p><?php echo htmlspecialchars($footerData['about_text'] ?? 'Thời trang chất lượng cao với phong cách editorial tinh tế. Mỗi sản phẩm là một câu chuyện — chúng tôi kể bằng chất liệu, đường may và những chi tiết nhỏ nhất.'); ?></p>
            
            <div class="df-socials">
                <!-- Facebook -->
                <a href="<?php echo htmlspecialchars($footerData['facebook'] ?? '#'); ?>" target="_blank" title="Facebook">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
                <!-- Instagram -->
                <a href="<?php echo htmlspecialchars($footerData['instagram'] ?? '#'); ?>" target="_blank" title="Instagram">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                </a>
                <!-- TikTok -->
                <a href="<?php echo htmlspecialchars($footerData['tiktok'] ?? '#'); ?>" target="_blank" title="TikTok">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
                </a>
                <!-- YouTube -->
                <a href="<?php echo htmlspecialchars($footerData['youtube'] ?? '#'); ?>" target="_blank" title="YouTube">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>
                </a>
            </div>
        </div>
        
        <!-- CỘT PHẢI: LIÊN HỆ -->
        <div class="df-contact">
            <h3>Liên hệ</h3>
            <ul>
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span><?php echo htmlspecialchars($footerData['address'] ?? '123 Đường Thời Trang, Q.1, TP.HCM'); ?></span>
                </li>
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span><?php echo htmlspecialchars($footerData['phone'] ?? '1900 123 456'); ?></span>
                </li>
                <li>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span><?php echo htmlspecialchars($footerData['email'] ?? 'hello@theatelier.vn'); ?></span>
                </li>
            </ul>
        </div>
    </div>
    
    <!-- DÒNG BẢN QUYỀN + NÚT QUẢN LÝ -->
    <div class="df-bottom">
        <div><?php echo htmlspecialchars($footerData['copyright'] ?? '© ' . date('Y') . ' THE ATELIER. All rights reserved.'); ?></div>
        <a href="footer-manager.php" class="df-manage-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h2"/><path d="M10 17h10"/><circle cx="8" cy="17" r="2"/></svg>
            Quản lý Footer
        </a>
    </div>
</footer>

<!-- ĐÓNG admin-main__inner VÀ admin-main TẠI ĐÂY -->
</div><!-- /.admin-main__inner (nếu có) -->
</main><!-- /.admin-main -->
</div><!-- /.admin-layout -->

<!-- TOAST CHUNG -->
<div class="adm-toast" id="admToast">
    <span id="admToastIcon"></span>
    <span id="admToastMsg"></span>
</div>

<script>
// Định nghĩa sẵn các icon SVG cho Toast để JS có thể gọi
const toastIcons = {
    success: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>`,
    error: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`
};

function showAdmToast(msg, isError) {
    var t = document.getElementById('admToast');
    var iconWrapper = document.getElementById('admToastIcon');
    
    // Chèn SVG tương ứng
    iconWrapper.innerHTML = isError ? toastIcons.error : toastIcons.success;
    
    document.getElementById('admToastMsg').textContent = msg;
    t.className = 'adm-toast show' + (isError ? ' error' : '');
    
    setTimeout(function () { t.className = 'adm-toast'; }, 3000);
}

function confirmDelete(msg) {
    return confirm(msg || 'Bạn chắc chắn muốn xóa? Hành động này không thể hoàn tác.');
}
</script>

</body>
</html>
<?php
// ✅ Xả buffer ra khi kết thúc trang
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>