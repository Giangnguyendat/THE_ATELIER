<?php
require_once 'includes/config.php';

// Khởi tạo CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// 1. XỬ LÝ AJAX THÊM VÀO GIỎ HÀNG
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_add_to_cart') {
    // ✅ Buffer ngay đầu để chặn output rác
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    // Kiểm tra CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Lỗi bảo mật: Token không hợp lệ!']);
        exit;
    }

    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity  = isset($_POST['quantity'])   ? max(1, (int)$_POST['quantity']) : 1;
    $size      = isset($_POST['size'])       ? trim($_POST['size']) : 'M';

    $allowedSizes = ['S', 'M', 'L', 'XL', '2XL'];
    if (!in_array($size, $allowedSizes, true)) {
        $size = 'M';
    }

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm không hợp lệ!']);
        exit;
    }

    // Lấy thông tin sản phẩm
    $stmt = $pdo->prepare("SELECT id, name, price, discount_percent, image_url, description, gender FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại!']);
        exit;
    }

    // ✅ KIỂM TRA TỒN KHO
    $available = function_exists('getProductStock')
        ? getProductStock($pdo, $productId, $size)
        : 999;

    if ($available <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Size ' . $size . ' của "' . $product['name'] . '" đã hết hàng!',
        ]);
        exit;
    }

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $cartKey = $productId . '_' . $size;
    $inCart  = isset($_SESSION['cart'][$cartKey]) ? (int)$_SESSION['cart'][$cartKey]['quantity'] : 0;

    if ($inCart + $quantity > $available) {
        $canAdd = max(0, $available - $inCart);
        echo json_encode([
            'success' => false,
            'message' => $canAdd > 0
                ? "Chỉ có thể thêm {$canAdd} sản phẩm nữa (kho còn {$available}, giỏ đã có {$inCart})."
                : "Bạn đã có {$inCart} sản phẩm size {$size} trong giỏ, kho chỉ còn {$available} cái.",
        ]);
        exit;
    }

    // Tính giá
    $finalPrice = function_exists('calculateProductPrice')
        ? calculateProductPrice($product)['final']
        : ((float)$product['discount_percent'] > 0
            ? (float)$product['price'] * (1 - (float)$product['discount_percent'] / 100)
            : (float)$product['price']);

    if (isset($_SESSION['cart'][$cartKey])) {
        $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
    } else {
        $_SESSION['cart'][$cartKey] = [
            'id'          => (int)$product['id'],
            'product_id'  => (int)$product['id'],
            'name'        => $product['name'],
            'price'       => $finalPrice,
            'image_url'   => $product['image_url'],
            'quantity'    => $quantity,
            'size'        => $size,
            'description' => $product['description'] ?? '',
        ];
    }

    $totalCartItems = array_sum(array_column($_SESSION['cart'], 'quantity'));

    // ✅ Xóa buffer trước khi gửi JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();

    echo json_encode([
        'success'     => true,
        'message'     => 'Đã thêm sản phẩm vào giỏ hàng thành công!',
        'total_items' => $totalCartItems,
    ]);
    exit;
}

// ==========================================
// 2. LỌC, SẮP XẾP VÀ PHÂN TRANG
// ==========================================
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$search   = isset($_GET['search'])   ? trim($_GET['search']) : '';
$sort     = isset($_GET['sort'])     ? $_GET['sort'] : 'newest';
$page     = isset($_GET['page'])     ? max(1, (int)$_GET['page']) : 1;
$perPage  = 12;

$where  = [];
$params = [];

if ($category > 0) {
    $where[]  = "p.category_id = ?";
    $params[] = $category;
}

if ($search !== '') {
    $where[]  = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sortOptions = [
    'price_asc'   => 'p.price ASC',
    'price_desc'  => 'p.price DESC',
    'name_asc'    => 'p.name ASC',
    'name_desc'   => 'p.name DESC',
    'best_seller' => 'p.sold_count DESC',
    'discount'    => 'p.discount_percent DESC',
    'newest'      => 'p.created_at DESC',
];

if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'newest';
}
$orderBy = $sortOptions[$sort];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSQL");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page       = min(max(1, $page), $totalPages);
$offset     = ($page - 1) * $perPage;

$sql = "SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        $whereSQL
        ORDER BY $orderBy
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key + 1, $val);
}
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Lấy rating cho TẤT CẢ SP — 1 query duy nhất
$ratingsByProduct = [];
if (!empty($products)) {
    $ids = array_column($products, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rvStmt = $pdo->prepare("
        SELECT product_id, COUNT(*) AS total, COALESCE(AVG(rating), 0) AS avg_r
        FROM reviews WHERE product_id IN ($ph)
        GROUP BY product_id
    ");
    $rvStmt->execute($ids);
    foreach ($rvStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ratingsByProduct[(int)$row['product_id']] = [
            'total' => (int)$row['total'],
            'avg'   => round((float)$row['avg_r'], 1),
        ];
    }
}

// Danh mục
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<main class="products-page-wrapper">
    <div class="products-container">

        <!-- Hero Banner Carousel -->
        <header class="hero-slider">
            <div class="slider-wrapper">
                <div class="slide active" style="background-image: url('images/products/banners/banner1.jpg');">
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <h1 class="page-title">Sản Phẩm Mới</h1>
                        <p class="page-subtitle">Khám phá bộ sưu tập phong cách hiện đại và tối giản</p>
                    </div>
                </div>
                <div class="slide" style="background-image: url('images/products/banners/banner2.jpg');">
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <h1 class="page-title">Ưu Đãi Đặc Biệt</h1>
                        <p class="page-subtitle">Giảm giá lên đến 50% cho các sản phẩm hot nhất</p>
                    </div>
                </div>
                <div class="slide" style="background-image: url('images/products/banners/banner5.jpg');">
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <h1 class="page-title">Thời Trang Nam & Nữ</h1>
                        <p class="page-subtitle">Đa dạng mẫu mã, chất liệu cao cấp chuẩn xu hướng</p>
                    </div>
                </div>
            </div>
            <div class="slider-dots">
                <span class="dot active"></span>
                <span class="dot"></span>
                <span class="dot"></span>
            </div>
        </header>

        <!-- Thanh Lọc & Tìm Kiếm -->
        <div class="filter-card">
            <form method="get" action="products.php" class="filter-form">
                <div class="filter-group filter-search">
                    <input type="text" name="search" placeholder="Tìm kiếm tên hoặc mô tả..." value="<?php echo sanitize($search); ?>">
                </div>

                <div class="filter-group">
                    <select name="category">
                        <option value="0">Tất cả danh mục</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $category === (int)$cat['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select name="sort">
                        <option value="newest"      <?php echo $sort === 'newest'      ? 'selected' : ''; ?>>Mới nhất</option>
                        <option value="price_asc"   <?php echo $sort === 'price_asc'   ? 'selected' : ''; ?>>Giá: Thấp đến Cao</option>
                        <option value="price_desc"  <?php echo $sort === 'price_desc'  ? 'selected' : ''; ?>>Giá: Cao đến Thấp</option>
                        <option value="name_asc"    <?php echo $sort === 'name_asc'    ? 'selected' : ''; ?>>Tên: A - Z</option>
                        <option value="name_desc"   <?php echo $sort === 'name_desc'   ? 'selected' : ''; ?>>Tên: Z - A</option>
                        <option value="best_seller" <?php echo $sort === 'best_seller' ? 'selected' : ''; ?>>Bán chạy nhất</option>
                        <option value="discount"    <?php echo $sort === 'discount'    ? 'selected' : ''; ?>>Giảm giá nhiều nhất</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Lọc Sản Phẩm</button>
                    <?php if ($category > 0 || $search !== ''): ?>
                        <a href="products.php" class="btn-reset">Xóa bộ lọc</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ĐẾM KẾT QUẢ -->
        <?php if (!empty($products)): ?>
            <div class="products-count">
                Tìm thấy <b><?php echo (int)$totalProducts; ?></b> sản phẩm
                <?php if ($search !== ''): ?>
                    cho "<b><?php echo sanitize($search); ?></b>"
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Danh Sách Sản Phẩm -->
        <?php if (empty($products)): ?>
            <div class="no-product-card">
                <div class="no-product-icon">&#128092;</div>
                <h3>Không tìm thấy sản phẩm phù hợp</h3>
                <p>Thử tìm kiếm với từ khóa khác hoặc bỏ các điều kiện lọc hiện tại.</p>
                <a href="products.php" class="btn-primary">Xem tất cả sản phẩm</a>
            </div>
        <?php else: ?>
            <div class="product-grid product-grid--listing">
                <?php foreach ($products as $product):
                    $pid = (int)$product['id'];

                    // Tính giá
                    if (function_exists('calculateProductPrice')) {
                        $pricing = calculateProductPrice($product);
                    } else {
                        $disc  = (float)$product['discount_percent'];
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

                    // Stock
                    $stocks       = function_exists('getAllStocks') ? getAllStocks($pdo, $pid) : [];
                    $totalStock   = array_sum($stocks);
                    $isOutOfStock = $totalStock <= 0;

                    // Rating
                    $rv = $ratingsByProduct[$pid] ?? ['total' => 0, 'avg' => 0];
                    $avgRating    = $rv['avg'];
                    $totalReviews = $rv['total'];

                    $savedAmount = $pricing['original'] - $pricing['final'];
                    $savedPct    = $pricing['percent'];
                ?>
                    <article class="pc <?php echo $isOutOfStock ? 'pc--oos' : ''; ?>">
                        <!-- MEDIA -->
                        <div class="pc__media <?php echo $isOutOfStock ? '' : 'quick-view-trigger'; ?>" data-pid="<?php echo $pid; ?>"
                             data-product='<?php echo htmlspecialchars(json_encode([
                                 'id'          => $pid,
                                 'name'        => $product['name'],
                                 'category'    => $product['category_name'] ?? 'Thời trang',
                                 'image'       => $product['image_url'],
                                 'price'       => number_format($pricing['final'], 0, ',', '.') . 'đ',
                                 'oldPrice'    => $savedPct > 0 ? number_format($pricing['original'], 0, ',', '.') . 'đ' : '',
                                 'description' => $product['description'] ?? 'Sản phẩm cao cấp mang phong cách hiện đại.',
                                 'stocks'      => $stocks,
                                 'totalStock'  => $totalStock,
                             ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>'>

                            <a href="product-details.php?id=<?php echo $pid; ?>" class="pc__media-link">
                                <img src="<?php echo sanitize($product['image_url']); ?>"
                                     alt="<?php echo sanitize($product['name']); ?>"
                                     loading="lazy"
                                     class="pc__img">
                            </a>

                            <!-- Badges -->
                            <div class="pc__badges">
                                <?php if ($savedPct > 0): ?>
                                    <span class="pc__badge pc__badge--sale">-<?php echo (int)$savedPct; ?>%</span>
                                <?php endif; ?>
                                <?php if ($isOutOfStock): ?>
                                    <span class="pc__badge pc__badge--oos">Hết hàng</span>
                                <?php elseif ($totalStock <= 5): ?>
                                    <span class="pc__badge pc__badge--low">Còn <?php echo (int)$totalStock; ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Action buttons -->
                            <?php if (!$isOutOfStock): ?>
                                <div class="pc__actions">
                                    <button type="button" class="pc__action" title="Yêu thích" aria-label="Yêu thích">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                        </svg>
                                    </button>
                                    <button type="button" class="pc__action" title="Xem nhanh" aria-label="Xem nhanh"
                                            onclick="event.stopPropagation(); document.querySelector('.quick-view-trigger[data-pid=\'<?php echo $pid; ?>\']')?.click();">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <!-- Quick view / out of stock overlay -->
                            <span class="pc__quick-add" style="pointer-events:none; opacity:0;"></span>
                            <div class="pc__overlay-cta">
                                <?php if ($isOutOfStock): ?>
                                    <span class="pc__quick-add" style="background:#1c1b19;color:#fffdf9;">Hết hàng</span>
                                <?php else: ?>
                                    <span class="pc__quick-add">👁 Xem nhanh</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- BODY -->
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
                                <?php echo $isOutOfStock ? 'Xem sản phẩm' : 'Xem chi tiết'; ?>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M5 12h14M12 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Phân Trang -->
            <?php if ($totalPages > 1): ?>
                <nav class="pagination-nav">
                    <ul class="pagination">
                        <?php
                        $queryParams = $_GET;
                        if ($page > 1):
                            $queryParams['page'] = $page - 1;
                        ?>
                            <li><a href="?<?php echo http_build_query($queryParams); ?>" class="page-link">&laquo; Trước</a></li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++):
                            $queryParams['page'] = $i;
                        ?>
                            <li>
                                <a href="?<?php echo http_build_query($queryParams); ?>"
                                   class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages):
                            $queryParams['page'] = $page + 1;
                        ?>
                            <li><a href="?<?php echo http_build_query($queryParams); ?>" class="page-link">Sau &raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</main>

<!-- ================= MODAL QUICK VIEW ================= -->
<div class="modal-overlay" id="quickViewModal" aria-hidden="true" role="dialog">
    <div class="modal-content">
        <button type="button" class="modal-close" id="closeModalBtn" aria-label="Đóng">&times;</button>

        <div class="modal-body">
            <div class="modal-product-image">
                <img id="modalProductImg" src="" alt="Hình sản phẩm">
            </div>

            <div class="modal-product-details">
                <span class="modal-category" id="modalProductCategory">Category</span>
                <h2 class="modal-title" id="modalProductName">Tên Sản Phẩm</h2>

                <div class="modal-price-box">
                    <span class="modal-price" id="modalProductPrice">0đ</span>
                    <span class="modal-old-price" id="modalProductOldPrice"></span>
                </div>

                <p class="modal-description" id="modalProductDesc">Mô tả sản phẩm...</p>

                <form id="quickAddToCartForm" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="ajax_add_to_cart">
                    <input type="hidden" name="product_id" id="modalProductId" value="">

                    <div class="size-selection-group">
                        <label class="size-label">Kích thước (Size):</label>
                        <div class="size-options" id="modalSizeOptions">
                            <label class="size-btn" data-size="S">
                                <input type="radio" name="size" value="S">
                                <span>S <small class="size-stock" data-size-stock="S">-</small></span>
                            </label>
                            <label class="size-btn" data-size="M">
                                <input type="radio" name="size" value="M" checked>
                                <span>M <small class="size-stock" data-size-stock="M">-</small></span>
                            </label>
                            <label class="size-btn" data-size="L">
                                <input type="radio" name="size" value="L">
                                <span>L <small class="size-stock" data-size-stock="L">-</small></span>
                            </label>
                            <label class="size-btn" data-size="XL">
                                <input type="radio" name="size" value="XL">
                                <span>XL <small class="size-stock" data-size-stock="XL">-</small></span>
                            </label>
                            <label class="size-btn" data-size="2XL">
                                <input type="radio" name="size" value="2XL">
                                <span>2XL <small class="size-stock" data-size-stock="2XL">-</small></span>
                            </label>
                        </div>
                        <p id="modalStockNote" style="font-family:var(--font-mono);font-size:0.78rem;margin-top:0.6rem;"></p>
                    </div>

                    <div class="modal-action-row">
                        <div class="modal-quantity-selector">
                            <button type="button" class="btn-modal-qty" id="qtyMinus" aria-label="Giảm số lượng">&minus;</button>
                            <input type="number" name="quantity" id="modalQtyInput" value="1" min="1">
                            <button type="button" class="btn-modal-qty" id="qtyPlus" aria-label="Tăng số lượng">&plus;</button>
                        </div>

                        <button type="submit" class="btn-modal-add-cart" id="btnSubmitCart">&#128722; Thêm vào giỏ hàng</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- CSS bổ sung cho listing -->
<style>
    /* Grid cho listing page — dùng chung .pc */
    .product-grid--listing {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 3rem;
    }
    @media (max-width: 1080px) { .product-grid--listing { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 820px)  { .product-grid--listing { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px)  { .product-grid--listing { grid-template-columns: 1fr; } }

    /* Badge hết hàng + còn ít */
    .pc__badge--oos { background: #1c1b19; color: #fffdf9; }
    .pc__badge--low { background: #f59e0b; color: #fff; }

    /* Card out-of-stock */
    .pc--oos .pc__img { filter: grayscale(0.6) brightness(0.85); }
    .pc--oos .pc__media-link { cursor: pointer; }
    .pc--oos:hover .pc__img { transform: none; }

.pc__overlay-cta {
    position: absolute;
    left: 0.7rem;
    right: 0.7rem;
    bottom: 0.7rem;
    z-index: 5;                     /* ← cao hơn thẻ <a> ảnh */
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.3s, transform 0.3s;
    pointer-events: none;           /* mặc định không cho click */
}
.pc:hover .pc__overlay-cta {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;           /* ← CHỈ cho click khi đã hiện */
}

/* Nút "Xem nhanh" */
.pc__quick-add {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.7rem;
    background: var(--color-white);
    color: var(--color-ink);
    font-family: var(--font-mono);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    border-radius: 6px;
    cursor: pointer;                /* ← con trỏ pointer */
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: background 0.2s, color 0.2s;
}
.pc__quick-add:hover {
    background: var(--color-ink);
    color: var(--color-ivory);
}

    /* Đếm kết quả */
    .products-count {
        font-family: var(--font-mono);
        font-size: 0.82rem;
        color: var(--color-stone);
        margin-bottom: 1.25rem;
        letter-spacing: 0.02em;
    }
    .products-count b { color: var(--color-ink); font-weight: 700; }

    /* Size trong modal */
    .size-btn span {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        min-height: 52px;
        padding: 4px 8px;
    }
    .size-btn .size-stock {
        font-family: var(--font-mono, monospace);
        font-size: 0.62rem;
        opacity: 0.7;
        letter-spacing: 0.02em;
    }
    .size-btn.is-disabled { opacity: 0.35; cursor: not-allowed; }
    .size-btn.is-disabled span { text-decoration: line-through; }
    .size-btn.is-disabled input { pointer-events: none; }
</style>

<script src="js/chatbot.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    /* ============================================================
       HERO SLIDER
       ============================================================ */
    const slides = document.querySelectorAll('.slide');
    const dots = document.querySelectorAll('.dot');
    let currentSlide = 0;
    const slideInterval = 4000;

    function showSlide(index) {
        slides.forEach(s => s.classList.remove('active'));
        dots.forEach(d => d.classList.remove('active'));
        slides[index]?.classList.add('active');
        dots[index]?.classList.add('active');
    }

    function nextSlide() {
        if (slides.length > 0) {
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
        }
    }

    let autoSlide = setInterval(nextSlide, slideInterval);

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            currentSlide = index;
            showSlide(currentSlide);
            clearInterval(autoSlide);
            autoSlide = setInterval(nextSlide, slideInterval);
        });
    });

    /* ============================================================
       QUICK VIEW MODAL
       ============================================================ */
    const modal = document.getElementById('quickViewModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const quickViewTriggers = document.querySelectorAll('.quick-view-trigger');
    const qtyInput = document.getElementById('modalQtyInput');
    const modalStockNote = document.getElementById('modalStockNote');
    const sizeBtns = document.querySelectorAll('#modalSizeOptions .size-btn');

    let currentStocks = {};

    quickViewTriggers.forEach(trigger => {
        trigger.addEventListener('click', () => {
            try {
                const data = JSON.parse(trigger.dataset.product);

                document.getElementById('modalProductId').value           = data.id;
                document.getElementById('modalProductName').textContent   = data.name;
                document.getElementById('modalProductCategory').textContent = data.category;
                document.getElementById('modalProductImg').src            = data.image;
                document.getElementById('modalProductPrice').textContent  = data.price;
                document.getElementById('modalProductOldPrice').textContent = data.oldPrice;
                document.getElementById('modalProductDesc').textContent   = data.description;

                currentStocks = data.stocks || {};

                updateSizeStockUI();
                qtyInput.value = 1;
                selectFirstAvailableSize();

                modal.classList.add('show');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            } catch (err) {
                console.error('JSON parse error:', err);
            }
        });
    });

    function updateSizeStockUI() {
        sizeBtns.forEach(btn => {
            const size = btn.dataset.size;
            const stock = parseInt(currentStocks[size] || 0);
            const isDisabled = stock <= 0;
            const radio = btn.querySelector('input[type="radio"]');

            const stockEl = btn.querySelector('.size-stock');
            if (stockEl) {
                stockEl.textContent = isDisabled ? 'Hết' : '(' + stock + ')';
            }

            btn.classList.toggle('is-disabled', isDisabled);
            if (isDisabled) {
                radio.disabled = true;
                radio.checked = false;
            } else {
                radio.disabled = false;
            }
        });
    }

    function selectFirstAvailableSize() {
        const order = ['M', 'L', 'S', 'XL', '2XL'];
        for (const sz of order) {
            if ((currentStocks[sz] || 0) > 0) {
                const radio = document.querySelector(`#modalSizeOptions input[value="${sz}"]`);
                if (radio) {
                    radio.checked = true;
                    break;
                }
            }
        }
        updateQtyLimit();
    }

    function updateQtyLimit() {
        const checked = document.querySelector('#modalSizeOptions input[name="size"]:checked');
        if (!checked) {
            qtyInput.disabled = true;
            qtyInput.value = 1;
            if (modalStockNote) {
                modalStockNote.textContent = '⚠️ Không có size nào còn hàng';
                modalStockNote.style.color = '#b91c1c';
            }
            document.getElementById('btnSubmitCart').disabled = true;
            return;
        }

        const size = checked.value;
        const stock = parseInt(currentStocks[size] || 0);

        document.getElementById('btnSubmitCart').disabled = false;

        if (stock <= 0) {
            qtyInput.max = 1;
            qtyInput.value = 1;
            qtyInput.disabled = true;
            if (modalStockNote) {
                modalStockNote.textContent = '⚠️ Size ' + size + ' đã hết hàng';
                modalStockNote.style.color = '#b91c1c';
            }
        } else {
            qtyInput.disabled = false;
            qtyInput.max = stock;
            if (parseInt(qtyInput.value) > stock) {
                qtyInput.value = stock;
            }
            if (modalStockNote) {
                if (stock <= 5) {
                    modalStockNote.textContent = '🔥 Chỉ còn ' + stock + ' sản phẩm size ' + size;
                    modalStockNote.style.color = '#d97706';
                } else {
                    modalStockNote.textContent = '✓ Còn ' + stock + ' sản phẩm size ' + size;
                    modalStockNote.style.color = '#166534';
                }
            }
        }
    }

    document.querySelectorAll('#modalSizeOptions input[name="size"]').forEach(radio => {
        radio.addEventListener('change', updateQtyLimit);
    });

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    closeModalBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.getElementById('qtyMinus')?.addEventListener('click', () => {
        let val = parseInt(qtyInput.value) || 1;
        if (val > 1) qtyInput.value = val - 1;
    });
    document.getElementById('qtyPlus')?.addEventListener('click', () => {
        let val = parseInt(qtyInput.value) || 1;
        let max = parseInt(qtyInput.max) || 99;
        if (val < max) qtyInput.value = val + 1;
    });

    qtyInput?.addEventListener('input', function () {
        let max = parseInt(this.max) || 99;
        let val = parseInt(this.value) || 1;
        if (val > max) this.value = max;
        if (val < 1) this.value = 1;
    });

    /* ============================================================
       TOAST
       ============================================================ */
    function showToast(message, isError) {
        const toast = document.createElement('div');
        toast.className = 'toast-notification' + (isError ? ' error' : '');
        toast.innerHTML = `<span>${isError ? '⚠' : '✓'}</span> ${message}`;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /* ============================================================
       AJAX ADD TO CART
       ============================================================ */
    const quickAddToCartForm = document.getElementById('quickAddToCartForm');
    quickAddToCartForm?.addEventListener('submit', function (e) {
        e.preventDefault();

        const submitBtn = document.getElementById('btnSubmitCart');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Đang xử lý...';

        fetch('products.php', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, false);
                closeModal();

                document.querySelectorAll('.cart-count, #cartBadge, .badge-cart').forEach(badge => {
                    badge.textContent = data.total_items;
                });
            } else {
                showToast(data.message || 'Có lỗi xảy ra.', true);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        })
        .catch(err => {
            console.error('AJAX Error:', err);
            showToast('Không thể kết nối tới máy chủ.', true);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>