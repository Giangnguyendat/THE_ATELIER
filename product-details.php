<?php
require_once 'includes/config.php';

if (!function_exists('attr_encode')) {
    function attr_encode($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) {
    header('Location: products.php');
    exit;
}

/* ============ LẤY SẢN PHẨM ============ */
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: products.php');
    exit;
}

/* ============ TÍNH GIÁ ============ */
if (function_exists('calculateProductPrice')) {
    $pricing = calculateProductPrice($product);
} else {
    $rawDiscount = (float)$product['discount_percent'];
    $rawPrice    = (float)$product['price'];
    $finalRaw    = $rawDiscount > 0 ? $rawPrice * (1 - $rawDiscount / 100) : $rawPrice;

    $pricing = [
        'original'   => $rawPrice,
        'final'      => $finalRaw,
        'saved'      => $rawPrice - $finalRaw,
        'percent'    => (int)$rawDiscount,
        'source'     => $rawDiscount > 0 ? 'product' : 'none',
        'event_name' => '',
    ];
}

$finalPrice    = $pricing['final'];
$originalPrice = $pricing['original'];
$discountPct   = $pricing['percent'];
$eventName     = $pricing['event_name'] ?? '';
$isEvent       = ($pricing['source'] === 'event');
$savedAmount   = $originalPrice - $finalPrice;

/* ============ STOCK ============ */
$stocks = function_exists('getAllStocks')
    ? getAllStocks($pdo, $productId)
    : array_fill_keys(['S','M','L','XL','2XL'], 0);

$totalStock = array_sum($stocks);

/* ============ ĐÁNH GIÁ ============ */
$rvStats = $pdo->prepare("
    SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS avg_rating
    FROM reviews WHERE product_id = ?
");
$rvStats->execute([$productId]);
$rvInfo        = $rvStats->fetch(PDO::FETCH_ASSOC);
$avgRating     = round((float)$rvInfo['avg_rating'], 1);
$totalReviews  = (int)$rvInfo['total'];

// Danh sách reviews
$rvList = $pdo->prepare("
    SELECT r.*, u.full_name
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.product_id = ?
    ORDER BY r.created_at DESC
    LIMIT 50
");
$rvList->execute([$productId]);
$reviews = $rvList->fetchAll(PDO::FETCH_ASSOC);

// Ảnh của reviews
$reviewImages = [];
if (!empty($reviews)) {
    $ids = array_column($reviews, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $imgStmt = $pdo->prepare("SELECT review_id, image_url FROM review_images WHERE review_id IN ($placeholders)");
    $imgStmt->execute($ids);
    foreach ($imgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reviewImages[$row['review_id']][] = $row['image_url'];
    }
}

// Đếm số sao 5→1
$starCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($reviews as $r) {
    $starCounts[(int)$r['rating']]++;
}

/* ============ SẢN PHẨM LIÊN QUAN ============ */
$relatedStmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.category_id = ? AND p.id != ?
    ORDER BY p.sold_count DESC, RAND()
    LIMIT 4
");
$relatedStmt->execute([$product['category_id'], $productId]);
$relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);

include 'includes/header.php';
?>

<style>
    /* CSS riêng cho khối phản hồi của Admin */
    .admin-reply-box {
        margin-top: 12px;
        background: #fdfaf6;
        border: 1px solid #e8e0d0;
        border-left: 3px solid #a8461f;
        border-radius: 8px;
        padding: 12px 16px;
    }
    .admin-reply-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 6px;
    }
    .admin-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #a8461f;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 4px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .admin-reply-date {
        font-size: 0.75rem;
        color: #8a8377;
        font-family: 'IBM Plex Mono', monospace;
    }
    .admin-reply-content {
        font-size: 0.88rem;
        color: #1c1b19;
        line-height: 1.6;
    }
</style>

<main class="pd-wrap">

    <!-- ===== Breadcrumb ===== -->
    <nav class="pd-breadcrumb" aria-label="Breadcrumb">
        <a href="index.php">Trang chủ</a>
        <span>/</span>
        <a href="products.php?category=<?php echo (int)$product['category_id']; ?>">
            <?php echo attr_encode($product['category_name'] ?? 'Sản phẩm'); ?>
        </a>
        <span>/</span>
        <span class="pd-breadcrumb__current"><?php echo attr_encode($product['name']); ?></span>
    </nav>

    <?php if ($flash): ?>
        <div class="pd-flash pd-flash--<?php echo attr_encode($flash['type']); ?>">
            <?php echo attr_encode($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- ===== Layout chính ===== -->
    <div class="pd-layout">

        <!-- ẢNH -->
        <div class="pd-gallery">
            <div class="pd-gallery__main">
                <img src="<?php echo attr_encode($product['image_url']); ?>"
                     alt="<?php echo attr_encode($product['name']); ?>"
                     fetchpriority="high">
                <?php if ($discountPct > 0): ?>
                    <span class="pd-ribbon">-<?php echo (int)$discountPct; ?>%</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- THÔNG TIN -->
        <div class="pd-info">
            <span class="pd-badge"><?php echo attr_encode($product['category_name'] ?? 'Chưa phân loại'); ?></span>
            <h1 class="pd-title"><?php echo attr_encode($product['name']); ?></h1>

            <!-- Meta stats: đã bán + đánh giá -->
            <div class="pd-meta-stats">
                <div class="pd-stat">
                    <span class="pd-stat__icon">🔥</span>
                    <span class="pd-stat__text">
                        <b><?php echo (int)$product['sold_count']; ?></b> đã bán
                    </span>
                </div>

                <div class="pd-stat">
                    <span class="pd-stat__icon">⭐</span>
                    <span class="pd-stat__text">
                        <?php if ($totalReviews > 0): ?>
                            <b><?php echo number_format($avgRating, 1); ?></b>/5
                            <span style="color:var(--color-stone);">
                                (<?php echo $totalReviews; ?> đánh giá)
                            </span>
                        <?php else: ?>
                            <span style="color:var(--color-stone);">Chưa có đánh giá</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($totalStock > 0 && $totalStock <= 10): ?>
                    <div class="pd-stat">
                        <span class="pd-stat__icon">📦</span>
                        <span class="pd-stat__text" style="color:#d97706;">
                            Chỉ còn <b><?php echo $totalStock; ?></b> sản phẩm
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isEvent && $eventName): ?>
                <div class="event-banner">
                    🎉 Đang diễn ra: <strong><?php echo attr_encode($eventName); ?></strong>
                    — giảm <strong><?php echo (int)$discountPct; ?>%</strong> cho sản phẩm này!
                </div>
            <?php endif; ?>

            <div class="pd-price">
                <span class="pd-price__sale"><?php echo number_format($finalPrice, 0, ',', '.'); ?>₫</span>
                <?php if ($discountPct > 0): ?>
                    <span class="pd-price__original"><?php echo number_format($originalPrice, 0, ',', '.'); ?>₫</span>
                    <span class="pd-price__save">Tiết kiệm <?php echo number_format($savedAmount, 0, ',', '.'); ?>₫</span>
                <?php endif; ?>
            </div>

            <div class="pd-desc">
                <?php echo nl2br(attr_encode($product['description'] ?? 'Sản phẩm chưa có mô tả chi tiết.')); ?>
            </div>

            <!-- FORM THÊM GIỎ -->
            <form action="cart.php" method="post" class="pd-form" id="pdAddToCartForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">

                <!-- SIZE -->
                <div class="pd-field">
                    <label class="pd-label">
                        <span>Chọn kích thước</span>
                        <a href="support.php?tab=size-guide" target="_blank"
                           style="color:#a8461f;text-decoration:none;font-weight:600;letter-spacing:0.06em;font-size:0.7rem;">
                            Bảng size →
                        </a>
                    </label>

                    <div class="pd-sizes">
                        <?php foreach (ALL_SIZES as $sz):
                            $stock    = (int)($stocks[$sz] ?? 0);
                            $disabled = $stock <= 0;

                            // Auto-check size M, hoặc size đầu tiên còn hàng
                            $checked = '';
                            if (!$disabled && $sz === 'M') {
                                $checked = 'checked';
                            }
                        ?>
                            <label class="pd-size <?php echo $disabled ? 'is-disabled' : ''; ?>">
                                <input type="radio" name="size" value="<?php echo $sz; ?>"
                                       <?php echo $checked; ?>
                                       <?php echo $disabled ? 'disabled' : ''; ?>>
                                <span data-size="<?php echo $sz; ?>">
                                    <small><?php echo $disabled ? 'Hết' : $stock; ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p id="pdStockNote"></p>
                </div>

                <!-- SỐ LƯỢNG -->
                <div class="pd-field">
                    <label class="pd-label">Số lượng</label>
                    <div class="pd-qty">
                        <button type="button" class="pd-qty__btn" data-step="-1" aria-label="Giảm">−</button>
                        <input type="number" name="quantity" id="pdQty" value="1" min="1" max="99">
                        <button type="button" class="pd-qty__btn" data-step="1" aria-label="Tăng">+</button>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="pd-actions">
                    <button type="submit" class="pd-btn pd-btn--cart">
                        🛒 Thêm vào giỏ hàng
                    </button>
                    <a href="cart.php" class="pd-btn pd-btn--ghost">Xem giỏ hàng</a>
                </div>
            </form>

            <ul class="pd-perks">
                <li>✓ Miễn phí vận chuyển đơn từ 500.000₫</li>
                <li>✓ Đổi trả trong 7 ngày</li>
                <li>✓ Hàng chính hãng 100%</li>
            </ul>
        </div>
    </div>

    <!-- ===== SẢN PHẨM LIÊN QUAN ===== -->
    <?php if (!empty($relatedProducts)): ?>
        <section class="pd-related">
            <div class="section-head">
                <span class="tag-eyebrow">Có thể bạn thích</span>
                <div class="section-head-row">
                    <h2 class="section-title">Sản phẩm liên quan</h2>
                    <a href="products.php?category=<?php echo (int)$product['category_id']; ?>" class="view-more">
                        Xem tất cả <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>

            <div class="product-grid">
                <?php foreach ($relatedProducts as $rel):
                    if (function_exists('calculateProductPrice')) {
                        $relPricing = calculateProductPrice($rel);
                    } else {
                        $rDiscount = (float)$rel['discount_percent'];
                        $rPrice    = (float)$rel['price'];
                        $rFinal    = $rDiscount > 0 ? $rPrice * (1 - $rDiscount / 100) : $rPrice;
                        $relPricing = [
                            'original'   => $rPrice,
                            'final'      => $rFinal,
                            'percent'    => (int)$rDiscount,
                            'source'     => $rDiscount > 0 ? 'product' : 'none',
                            'event_name' => '',
                        ];
                    }
                    $relId = (int)$rel['id'];
                ?>
                    <article class="product-card">
                        <a href="product-details.php?id=<?php echo $relId; ?>" class="product-image">
                            <?php if ($relPricing['percent'] > 0): ?>
                                <span class="ribbon">-<?php echo (int)$relPricing['percent']; ?>%</span>
                            <?php endif; ?>
                            <img src="<?php echo attr_encode($rel['image_url']); ?>"
                                 alt="<?php echo attr_encode($rel['name']); ?>"
                                 loading="lazy">
                            <div class="product-actions">
                                <span class="btn-view">Xem nhanh</span>
                            </div>
                        </a>
                        <div class="product-info">
                            <h3 class="product-name">
                                <a href="product-details.php?id=<?php echo $relId; ?>">
                                    <?php echo attr_encode($rel['name']); ?>
                                </a>
                            </h3>
                            <div class="price-tag">
                                <span class="price-sale"><?php echo number_format($relPricing['final'], 0, ',', '.'); ?>₫</span>
                                <?php if ($relPricing['percent'] > 0): ?>
                                    <span class="price-original"><?php echo number_format($relPricing['original'], 0, ',', '.'); ?>₫</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($relPricing['source'] === 'event' && !empty($relPricing['event_name'])): ?>
                                <span class="event-badge">🎉 <?php echo attr_encode($relPricing['event_name']); ?></span>
                            <?php endif; ?>
                            <a href="product-details.php?id=<?php echo $relId; ?>" class="btn-detail">Xem chi tiết</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- ============ ĐÁNH GIÁ SẢN PHẨM ============ -->
    <!-- ============================================================ -->
    <section class="pd-reviews" id="reviews">
        <div class="section-head">
            <span class="tag-eyebrow">Khách hàng nói gì</span>
            <div class="section-head-row">
                <h2 class="section-title">
                    Đánh giá sản phẩm
                    <?php if ($totalReviews > 0): ?>
                        <span style="font-family:var(--font-mono);font-size:1rem;color:var(--color-stone);font-weight:500;">
                            (<?php echo $totalReviews; ?>)
                        </span>
                    <?php endif; ?>
                </h2>
            </div>
        </div>

        <?php if (empty($reviews)): ?>
            <div class="pd-no-reviews">
                <div style="font-size:3rem;opacity:0.4;margin-bottom:0.5rem;">💬</div>
                <p style="margin:0;font-size:1rem;color:#374151;font-weight:600;">
                    Chưa có đánh giá nào
                </p>
                <p style="margin:0.35rem 0 0;font-size:0.88rem;">
                    Hãy là người đầu tiên đánh giá sản phẩm này!
                </p>
            </div>
        <?php else: ?>

            <!-- SUMMARY -->
            <div class="pd-review-summary">
                <div class="pd-review-summary__score">
                    <div class="pd-review-summary__avg"><?php echo number_format($avgRating, 1); ?></div>
                    <div class="pd-review-summary__stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span style="color:<?php echo $i <= round($avgRating) ? '#f59e0b' : '#d1d5db'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="pd-review-summary__total"><?php echo $totalReviews; ?> đánh giá</div>
                </div>

                <div class="pd-review-summary__bars">
                    <?php for ($star = 5; $star >= 1; $star--):
                        $count = $starCounts[$star];
                        $pct = $totalReviews > 0 ? ($count / $totalReviews * 100) : 0;
                    ?>
                        <div class="pd-rating-bar">
                            <span class="pd-rating-bar__label"><?php echo $star; ?> ★</span>
                            <div class="pd-rating-bar__track">
                                <div class="pd-rating-bar__fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <span class="pd-rating-bar__count"><?php echo $count; ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- LIST -->
            <div class="pd-review-list">
                <?php foreach ($reviews as $rv):
                    $imgs = $reviewImages[$rv['id']] ?? [];
                    $name = $rv['full_name'] ?? 'Khách hàng';
                    $initial = mb_substr($name, 0, 1);
                ?>
                    <article class="pd-review-item">
                        <header class="pd-review-item__head">
                            <div class="pd-review-item__avatar">
                                <?php echo strtoupper(htmlspecialchars($initial)); ?>
                            </div>
                            <div class="pd-review-item__user">
                                <div class="pd-review-item__name">
                                    <?php echo htmlspecialchars($name); ?>
                                </div>
                                <div class="pd-review-item__date">
                                    <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?>
                                </div>
                            </div>
                            <div class="pd-review-item__rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span style="color:<?php echo $i <= (int)$rv['rating'] ? '#f59e0b' : '#d1d5db'; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                        </header>

                        <?php if (!empty($rv['content'])): ?>
                            <p class="pd-review-item__content">
                                <?php echo nl2br(htmlspecialchars($rv['content'])); ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($imgs)): ?>
                            <div class="pd-review-item__images">
                                <?php foreach ($imgs as $img): ?>
                                    <img src="<?php echo htmlspecialchars($img); ?>"
                                         alt="Ảnh đánh giá"
                                         onclick="openImageZoom(this.src)"
                                         loading="lazy">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- KHỐI PHẢN HỒI CỦA ADMIN -->
                        <?php 
                        // Kiểm tra nếu có cột admin_reply trong database
                        $adminReply = $rv['admin_reply'] ?? $rv['reply'] ?? null; 
                        $adminReplyAt = $rv['admin_replied_at'] ?? $rv['reply_at'] ?? null; 
                        
                        if (!empty($adminReply)): 
                        ?>
                            <div class="admin-reply-box">
                                <div class="admin-reply-header">
                                    <span class="admin-badge">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                        Phản hồi từ THE ATELIER
                                    </span>
                                    <?php if (!empty($adminReplyAt)): ?>
                                        <span class="admin-reply-date">
                                            <?php echo date('d/m/Y', strtotime($adminReplyAt)); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-reply-content">
                                    <?php echo nl2br(htmlspecialchars($adminReply)); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<!-- Zoom ảnh review -->
<div id="imageZoomModal" class="image-zoom-modal" onclick="closeImageZoom()">
    <img id="imageZoomTarget" src="" alt="">
</div>

<!-- Script tăng/giảm số lượng + stock -->
<script>
(function () {
    var qty = document.getElementById('pdQty');
    if (!qty) return;

    var STOCKS = <?php echo json_encode($stocks); ?>;
    var SIZE_ORDER = ['S', 'M', 'L', 'XL', '2XL'];

    function getSelectedSize() {
        var el = document.querySelector('.pd-size input[name="size"]:checked');
        return el ? el.value : null;
    }

    /* ---------- Sync class .is-selected ---------- */
    function syncSizeSelection() {
        document.querySelectorAll('.pd-size').forEach(function (lbl) {
            var radio = lbl.querySelector('input[type="radio"]');
            if (!radio) return;
            if (radio.checked) {
                lbl.classList.add('is-selected');
            } else {
                lbl.classList.remove('is-selected');
            }
        });
    }

    /* ---------- Update số lượng tối đa + note ---------- */
    function updateMaxQty() {
        var size = getSelectedSize();
        var stock = size ? (parseInt(STOCKS[size]) || 0) : 0;

        qty.max = Math.max(1, stock);
        if (parseInt(qty.value) > stock) {
            qty.value = Math.max(1, stock);
        }

        var note = document.getElementById('pdStockNote');
        if (note) {
            if (!size) {
                note.textContent = '⚠️ Vui lòng chọn size';
                note.style.background = '#fef2f2';
                note.style.color = '#b91c1c';
            } else if (stock <= 0) {
                note.textContent = '⚠️ Size ' + size + ' đã hết hàng';
                note.style.background = '#fef2f2';
                note.style.color = '#b91c1c';
            } else if (stock <= 5) {
                note.textContent = '🔥 Chỉ còn ' + stock + ' sản phẩm size ' + size;
                note.style.background = '#fff7ed';
                note.style.color = '#c2410c';
            } else {
                note.textContent = '✓ Còn ' + stock + ' sản phẩm size ' + size;
                note.style.background = '#f0fdf4';
                note.style.color = '#166534';
            }
        }
    }

    /* ---------- Bind size radio ---------- */
    document.querySelectorAll('.pd-size input[name="size"]').forEach(function (r) {
        r.addEventListener('change', function () {
            syncSizeSelection();
            updateMaxQty();
        });
    });

    /* ---------- Auto chọn size M hoặc size đầu tiên còn hàng ---------- */
    if (!getSelectedSize()) {
        // Ưu tiên M
        var mRadio = document.querySelector('.pd-size input[value="M"]');
        if (mRadio && !mRadio.disabled) {
            mRadio.checked = true;
        } else {
            // Fallback: size đầu tiên còn hàng
            for (var i = 0; i < SIZE_ORDER.length; i++) {
                var sz = SIZE_ORDER[i];
                if ((parseInt(STOCKS[sz]) || 0) > 0) {
                    var radio = document.querySelector('.pd-size input[value="' + sz + '"]');
                    if (radio && !radio.disabled) {
                        radio.checked = true;
                        break;
                    }
                }
            }
        }
    }

    /* Sync lần đầu */
    syncSizeSelection();
    updateMaxQty();

    /* ---------- Qty buttons ---------- */
    document.querySelectorAll('.pd-qty__btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var step = parseInt(btn.dataset.step, 10) || 0;
            var v = parseInt(qty.value, 10) || 1;
            var max = parseInt(qty.max, 10) || 99;
            v = Math.max(1, Math.min(max, v + step));
            qty.value = v;
        });
    });

    /* ---------- Validate form ---------- */
    var form = document.getElementById('pdAddToCartForm');
    form?.addEventListener('submit', function (e) {
        var size = getSelectedSize();
        if (!size) {
            e.preventDefault();
            alert('Vui lòng chọn kích thước sản phẩm.');
            return false;
        }
        var stock = parseInt(STOCKS[size]) || 0;
        if (stock <= 0) {
            e.preventDefault();
            alert('Size ' + size + ' đã hết hàng. Vui lòng chọn size khác.');
            return false;
        }
        var q = parseInt(qty.value, 10) || 1;
        if (q > stock) {
            e.preventDefault();
            alert('Chỉ còn ' + stock + ' sản phẩm size ' + size + '.');
            return false;
        }
    });
})();

/* ---------- Zoom ảnh review ---------- */
function openImageZoom(src) {
    document.getElementById('imageZoomTarget').src = src;
    document.getElementById('imageZoomModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeImageZoom() {
    document.getElementById('imageZoomModal').classList.remove('show');
    document.body.style.overflow = '';
}
</script>

<?php include 'includes/footer.php'; ?>