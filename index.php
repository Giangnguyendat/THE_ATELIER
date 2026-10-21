<?php require_once 'includes/config.php'; ?>
<?php include 'includes/header.php'; ?>

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
                        <a class="banner__link" href="<?php echo sanitize($banner['link']); ?>">
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
            <button type="button" class="banner__arrow banner__arrow--prev" id="bannerPrev" aria-label="Ảnh trước">❮</button>
            <button type="button" class="banner__arrow banner__arrow--next" id="bannerNext" aria-label="Ảnh sau">❯</button>
            <div class="banner__dots" id="bannerDots" role="tablist" aria-label="Chọn banner"></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>


    <!-- ============ HELPER: Render Product Card ============ -->
    <?php
    /**
     * Hàm render 1 product card đẹp cho homepage
     * @param array $product  Dữ liệu sản phẩm
     * @param PDO   $pdo      Kết nối DB (để query rating)
     * @param string $variant 'sale' | 'combo' | 'best' | 'default'
     */
    function renderHomeProductCard(array $product, PDO $pdo, string $variant = 'default'): string {
        $pid = (int)$product['id'];

        // Tính giá
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

        // Lấy rating từ DB
        $rvStmt = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS avg_r
            FROM reviews WHERE product_id = ?
        ");
        $rvStmt->execute([$pid]);
        $rv = $rvStmt->fetch(PDO::FETCH_ASSOC);
        $totalReviews = (int)$rv['total'];
        $avgRating = round((float)$rv['avg_r'], 1);

        // Tính % tiết kiệm
        $savedAmount = $pricing['original'] - $pricing['final'];
        $savedPct = $pricing['percent'];

        // Badge riêng theo variant
        $badges = [];
        if ($savedPct > 0) {
            $badges[] = '<span class="pc__badge pc__badge--sale">-'.$savedPct.'%</span>';
        }
        if ($variant === 'best') {
            $badges[] = '<span class="pc__badge pc__badge--best">🔥 Bán chạy</span>';
        }
        if ($variant === 'combo') {
            $badges[] = '<span class="pc__badge pc__badge--combo">Combo</span>';
        }

        ob_start(); ?>
        <article class="pc">
            <!-- MEDIA -->
            <div class="pc__media">
                <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__media-link">
                    <img src="<?php echo sanitize($product['image_url']); ?>"
                         alt="<?php echo sanitize($product['name']); ?>"
                         loading="lazy"
                         class="pc__img">
                </a>

                <!-- Badges -->
                <?php if (!empty($badges)): ?>
                    <div class="pc__badges"><?php echo implode('', $badges); ?></div>
                <?php endif; ?>

                <!-- Action buttons (hiện khi hover) -->
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

                <!-- Quick add to cart (slide up khi hover) -->
                <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__quick-add">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    Chọn mua
                </a>
            </div>

            <!-- BODY -->
            <div class="pc__body">
                <!-- Category + sold -->
                <div class="pc__meta">
                    <span class="pc__category"><?php echo sanitize($product['category_name'] ?? 'Thời trang'); ?></span>
                    <?php if ((int)$product['sold_count'] > 0): ?>
                        <span class="pc__sold">· Đã bán <?php echo (int)$product['sold_count']; ?></span>
                    <?php endif; ?>
                </div>

                <!-- Title -->
                <h3 class="pc__title">
                    <a href="product-details.php?id=<?php echo $pid; ?>">
                        <?php echo sanitize($product['name']); ?>
                    </a>
                </h3>

                <!-- Rating -->
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

                <!-- Price -->
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

                <!-- CTA -->
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


    <!-- ============ ĐANG GIẢM GIÁ ============ -->
    <?php
    $saleProducts = $pdo->query("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.discount_percent > 0
        ORDER BY p.discount_percent DESC
        LIMIT 4
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($saleProducts)):
    ?>
    <section class="product-section">
        <div class="section-head">
            <span class="tag-eyebrow">Ưu đãi có hạn</span>
            <div class="section-head-row">
                <h2 class="section-title">Đang giảm giá</h2>
                <a href="products.php?sort=discount" class="view-more">
                    Xem tất cả <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
        <div class="product-grid product-grid--home">
            <?php foreach ($saleProducts as $product): ?>
                <?php echo renderHomeProductCard($product, $pdo, 'sale'); ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>


    <!-- ============ COMBO THU ĐÔNG ============ -->
    <?php
    $comboProducts = $pdo->query("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.category_id = (SELECT id FROM categories WHERE slug='combo' LIMIT 1)
        ORDER BY p.created_at DESC
        LIMIT 4
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($comboProducts)):
    ?>
    <section class="product-section">
        <div class="section-head">
            <span class="tag-eyebrow">Bộ sưu tập theo mùa</span>
            <div class="section-head-row">
                <h2 class="section-title">Combo thu đông</h2>
                <a href="products.php?category=<?php echo (int)$comboProducts[0]['category_id']; ?>" class="view-more">
                    Xem tất cả <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
        <div class="product-grid product-grid--home">
            <?php foreach ($comboProducts as $product): ?>
                <?php echo renderHomeProductCard($product, $pdo, 'combo'); ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>


    <!-- ============ BÁN CHẠY ============ -->
    <?php
    $bestSellers = $pdo->query("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.sold_count > 0
        ORDER BY p.sold_count DESC
        LIMIT 4
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($bestSellers)):
    ?>
    <section class="product-section">
        <div class="section-head">
            <span class="tag-eyebrow">Được yêu thích nhất</span>
            <div class="section-head-row">
                <h2 class="section-title">Bán chạy nhất</h2>
                <a href="products.php?sort=best_seller" class="view-more">
                    Xem tất cả <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
        <div class="product-grid product-grid--home">
            <?php foreach ($bestSellers as $product): ?>
                <?php echo renderHomeProductCard($product, $pdo, 'best'); ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

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