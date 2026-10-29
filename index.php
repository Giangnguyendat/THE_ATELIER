<?php require_once 'includes/config.php'; ?>
<?php include 'includes/header.php'; ?>

<style>
    /* THÊM CSS ĐỂ CUỘN MƯỢT */
    html {
        scroll-behavior: smooth;
        /* Bù trừ khoảng 90px để header không che mất tiêu đề khi cuộn */
        scroll-padding-top: 90px; 
    }
</style>

<?php
// TỰ ĐỘNG TẠO BẢNG VÀ SEED DỮ LIỆU NẾU CHƯA CÓ
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `homepage_sections` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(150) NOT NULL,
      `subtitle` varchar(150) DEFAULT NULL,
      `link` varchar(255) DEFAULT NULL,
      `section_key` varchar(100) NOT NULL,
      `display_order` int(11) DEFAULT 0,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `section_key` (`section_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $count = $pdo->query("SELECT COUNT(*) FROM homepage_sections")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['Đang giảm giá', 'Ưu đãi có hạn', 'products.php?collection=giam-gia', 'giam-gia', 1],
            ['Combo thu đông', 'Bộ sưu tập theo mùa', 'products.php?collection=combo-thu-dong', 'combo-thu-dong', 2],
            ['Bán chạy nhất', 'Được yêu thích nhất', 'products.php?collection=ban-chay', 'ban-chay', 3],
            ['Giảm kịch sàn', 'Xả kho giá sốc', 'products.php?collection=giam-kich-san', 'giam-kich-san', 4],
            ['Combo đồ thời trang', 'Tiết kiệm hơn khi mua set', 'products.php?collection=combo-thoi-trang', 'combo-thoi-trang', 5],
            ['Phong cách tối giản', 'Thanh lịch & Tinh tế', 'products.php?collection=phong-cach-toi-gian', 'phong-cach-toi-gian', 6],
            ['Khuyến mãi mùa hè', 'Rực rỡ & Năng động', 'products.php?collection=khuyen-mai-mua-he', 'khuyen-mai-mua-he', 7],
            ['Sản phẩm mới', 'Vừa cập bến', 'products.php?collection=san-pham-moi', 'san-pham-moi', 8],
        ];
        $stmt = $pdo->prepare("INSERT INTO homepage_sections (title, subtitle, link, section_key, display_order) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }
    }
} catch (Exception $e) {
    // Bỏ qua nếu có lỗi (ví dụ không có quyền tạo bảng)
}
?>

<div class="container-custom">

    <!-- ============ BANNER SLIDER ============ -->
    <?php
    $banners = $pdo->query("SELECT * FROM banners WHERE active = 1 AND position = 'homepage' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $bannerCount = count($banners);
    ?>
    <?php if (!empty($banners)): ?>
    <section class="hero-banner" aria-label="Khuyến mãi">
        <div class="banner" data-autoplay="4500">
            <div class="banner__viewport">
                <ul class="banner__track" id="bannerTrack">
                    <?php foreach ($banners as $i => $banner): ?>
                    <li class="banner__slide" role="group" aria-roledescription="slide" aria-label="<?php echo ($i + 1) . '/' . $bannerCount; ?>">
                        <!-- LINK NHẬN TỪ DATABASE -->
                        <a class="banner__link" href="<?php echo sanitize($banner['link'] ?: '#'); ?>">
                            <img class="banner__image"
                                 src="<?php echo sanitize($banner['image_url']); ?>"
                                 alt="<?php echo sanitize($banner['title']); ?>"
                                 <?php echo $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
                            <div class="banner__overlay"></div>
                            <?php if (!empty($banner['title'])): ?>
                            <div class="banner__caption">
                                <span class="banner__eyebrow">Bộ sưu tập mới</span>
                                <h2 class="banner__headline"><?php echo sanitize($banner['title']); ?></h2>
                            </div>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($bannerCount > 1): ?>
            <button type="button" class="banner__arrow banner__arrow--prev" id="bannerPrev" aria-label="Ảnh trước">
                <i class="ph ph-caret-left"></i>
            </button>
            <button type="button" class="banner__arrow banner__arrow--next" id="bannerNext" aria-label="Ảnh sau">
                <i class="ph ph-caret-right"></i>
            </button>
            <div class="banner__dots" id="bannerDots" role="tablist" aria-label="Chọn banner"></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>


    <!-- ============ HELPER: Render Product Card ============ -->
    <?php
    function renderHomeProductCard(array $product, PDO $pdo, string $variant = 'default'): string {
        $pid = (int)$product['id'];

        if (function_exists('calculateProductPrice')) {
            $pricing = calculateProductPrice($product);
        } else {
            $disc = (float)($product['discount_percent'] ?? 0);
            $price = (float)$product['price'];
            $final = $disc > 0 ? $price * (1 - $disc / 100) : $price;
            $pricing = [
                'original'   => $price,
                'final'      => $final,
                'percent'    => (int)$disc,
                'source'     => $disc > 0 ? 'product' : 'none',
                'event_name' => '',
            ];
        }

        $rvStmt = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS avg_r
            FROM reviews WHERE product_id = ?
        ");
        $rvStmt->execute([$pid]);
        $rv = $rvStmt->fetch(PDO::FETCH_ASSOC);
        $totalReviews = (int)$rv['total'];
        $avgRating = round((float)$rv['avg_r'], 1);

        $savedAmount = $pricing['original'] - $pricing['final'];
        $savedPct = $pricing['percent'];

        $badges = [];
        if ($savedPct > 0) {
            $badges[] = '<span class="pc__badge pc__badge--sale">-'.$savedPct.'%</span>';
        }
        if ($variant === 'best') {
            $badges[] = '<span class="pc__badge pc__badge--best"> Bán chạy</span>';
        }
        if ($variant === 'combo') {
            $badges[] = '<span class="pc__badge pc__badge--combo">Combo</span>';
        }

        ob_start(); ?>
        <article class="pc">
            <div class="pc__media">
                <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__media-link">
                    <img src="<?php echo sanitize($product['image_url']); ?>"
                         alt="<?php echo sanitize($product['name']); ?>"
                         loading="lazy"
                         class="pc__img">
                </a>

                <?php if (!empty($badges)): ?>
                    <div class="pc__badges"><?php echo implode('', $badges); ?></div>
                <?php endif; ?>

                <div class="pc__actions">
                    <button type="button" class="pc__action" title="Yêu thích" aria-label="Yêu thích">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </button>
                    <button type="button" class="pc__action" title="Xem nhanh" aria-label="Xem nhanh">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>

                <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__quick-add">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    Chọn mua
                </a>
            </div>

            <div class="pc__body">
                <div class="pc__meta">
                    <span class="pc__category"><?php echo sanitize($product['category_name'] ?? 'Thời trang'); ?></span>
                    <?php if ((int)$product['sold_count'] > 0): ?>
                        <span class="pc__sold">· Đã bán <?php echo (int)$product['sold_count']; ?></span>
                    <?php endif; ?>
                </div>

                <h3 class="pc__title">
                    <a href="product-details.php?id=<?php echo $pid; ?>">
                        <?php echo sanitize($product['name']); ?>
                    </a>
                </h3>

                <div class="pc__rating">
                    <?php if ($totalReviews > 0): ?>
                        <span class="pc__stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg width="12" height="12" viewBox="0 0 24 24"
                                     fill="<?php echo $i <= round($avgRating) ? '#f59e0b' : '#e5e7eb'; ?>"
                                     stroke="none">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                            <?php endfor; ?>
                        </span>
                        <span class="pc__rating-num"><?php echo number_format($avgRating, 1); ?></span>
                        <span class="pc__rating-count">(<?php echo $totalReviews; ?>)</span>
                    <?php else: ?>
                        <span class="pc__rating-empty">Chưa có đánh giá</span>
                    <?php endif; ?>
                </div>

                <div class="pc__price">
                    <div class="pc__price-main">
                        <span class="pc__price-current"><?php echo number_format($pricing['final'], 0, ',', '.'); ?><u>đ</u></span>
                        <?php if ($savedPct > 0): ?>
                            <span class="pc__price-old"><?php echo number_format($pricing['original'], 0, ',', '.'); ?>đ</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($savedAmount > 0): ?>
                        <div class="pc__price-save">
                            Tiết kiệm <b><?php echo number_format($savedAmount, 0, ',', '.'); ?>đ</b>
                        </div>
                    <?php endif; ?>
                </div>

                <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__cta">
                    Xem chi tiết
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }
    ?>


    <!-- ============ RENDER CÁC KHỐI ĐỘNG TỪ DATABASE ============ -->
    <?php
    $sections = $pdo->query("SELECT * FROM homepage_sections ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sections as $sec) {
        $key = $sec['section_key'];
        $variant = 'default';
        $products = [];

        // Query sản phẩm dựa trên section_key
        if ($key === 'giam-gia') {
            $variant = 'sale';
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.discount_percent > 0 AND p.id < 100
                ORDER BY p.discount_percent DESC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'combo-thu-dong') {
            $variant = 'combo';
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.category_id = (SELECT id FROM categories WHERE slug='combo' LIMIT 1)
                  AND p.id < 100
                ORDER BY p.created_at DESC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'ban-chay') {
            $variant = 'best';
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.sold_count > 0
                ORDER BY p.sold_count DESC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'giam-kich-san') {
            $variant = 'sale';
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id BETWEEN 100 AND 104
                ORDER BY p.discount_percent DESC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'combo-thoi-trang') {
            $variant = 'combo';
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id BETWEEN 105 AND 109
                ORDER BY p.id ASC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'phong-cach-toi-gian') {
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id BETWEEN 110 AND 114
                ORDER BY p.id ASC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'khuyen-mai-mua-he') {
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id BETWEEN 115 AND 119
                ORDER BY p.id ASC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($key === 'san-pham-moi') {
            $products = $pdo->query("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id BETWEEN 120 AND 124
                ORDER BY p.created_at DESC
                LIMIT 4
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($products)) continue;
    ?>
        <section class="product-section" id="<?php echo htmlspecialchars($key); ?>">
            <div class="section-head">
                <span class="tag-eyebrow"><?php echo htmlspecialchars($sec['subtitle'] ?? ''); ?></span>
                <div class="section-head-row">
                    <h2 class="section-title"><?php echo htmlspecialchars($sec['title']); ?></h2>
                    <?php if (!empty($sec['link'])): ?>
                    <a href="<?php echo htmlspecialchars($sec['link']); ?>" class="view-more">
                        Xem tất cả <span aria-hidden="true">→</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="product-grid product-grid--home">
                <?php foreach ($products as $product): ?>
                    <?php echo renderHomeProductCard($product, $pdo, $variant); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php } ?>


    <div class="view-all">
        <a href="products.php" class="btn-view-all">Khám phá tất cả sản phẩm</a>
    </div>
</div>

<script src="js/chatbot.js"></script>
<script>
(function () {
    var root = document.querySelector('.banner');
    if (!root) return;
    var track = root.querySelector('.banner__track');
    var slides = Array.prototype.slice.call(root.querySelectorAll('.banner__slide'));
    var prevBtn = root.querySelector('.banner__arrow--prev');
    var nextBtn = root.querySelector('.banner__arrow--next');
    var dotsContainer = root.querySelector('.banner__dots');
    var autoplayDelay = parseInt(root.getAttribute('data-autoplay'), 10) || 4500;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!track || slides.length < 2) return;

    var index = 0, timer = null, dots = [];

    if (dotsContainer) {
        slides.forEach(function (_, i) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'banner__dot';
            dot.setAttribute('role', 'tab');
            dot.setAttribute('aria-label', 'Xem banner ' + (i + 1));
            if (i === 0) dot.setAttribute('aria-current', 'true');
            dot.addEventListener('click', function () { goTo(i); });
            dotsContainer.appendChild(dot);
        });
        dots = Array.prototype.slice.call(dotsContainer.querySelectorAll('.banner__dot'));
    }

    function render() {
        track.style.transform = 'translateX(-' + (index * 100) + '%)';
        dots.forEach(function (dot, i) {
            i === index ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current');
        });
    }
    function goTo(i) { index = ((i % slides.length) + slides.length) % slides.length; render(); restart(); }
    function next() { goTo(index + 1); }
    function prev() { goTo(index - 1); }
    function start() { if (reduceMotion) return; stop(); timer = setInterval(function () { index = (index + 1) % slides.length; render(); }, autoplayDelay); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function restart() { stop(); start(); }

    if (nextBtn) nextBtn.addEventListener('click', next);
    if (prevBtn) prevBtn.addEventListener('click', prev);
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });

    var touchStartX = null;
    track.addEventListener('touchstart', function (e) { touchStartX = e.touches[0].clientX; stop(); }, { passive: true });
    track.addEventListener('touchend', function (e) {
        if (touchStartX === null) return;
        var delta = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(delta) > 40) delta < 0 ? next() : prev();
        else start();
        touchStartX = null;
    });

    render();
    start();
})();
</script>

<?php include 'includes/footer.php'; ?>