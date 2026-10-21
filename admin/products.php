<?php
    require_once '../includes/config.php';
    // Gọi file helper tính giá (đảm bảo file này tồn tại để không lỗi)
    if (file_exists('../includes/discount_helper.php')) {
        require_once '../includes/discount_helper.php';
    }
    include 'includes/admin_header.php';

    /* Helper: build URL quay lại giữ filter hiện tại */
    if (!function_exists('productsBackUrl')) {
        function productsBackUrl(): string {
            $p = [];
            if (!empty($_GET['q']))    $p['q']    = $_GET['q'];
            if (!empty($_GET['cat']))  $p['cat']  = (int)$_GET['cat'];
            if (!empty($_GET['sale'])) $p['sale'] = (int)$_GET['sale'];
            if (!empty($_GET['stock_status'])) $p['stock_status'] = $_GET['stock_status'];
            return 'products.php' . ($p ? '?' . http_build_query($p) : '');
        }
    }

    /* ============ XỬ LÝ POST ============ */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Phiên hết hạn.'];
            header('Location: products.php'); exit;
        }

        $action = $_POST['action'] ?? '';

        /* XÓA */
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa sản phẩm #$id"];
            }
            header('Location: products.php'); exit;
        }

        /* TẮT TẤT CẢ GIẢM GIÁ (toàn bộ DB) */
        if ($action === 'clear_all_discounts') {
            try {
                $stmt = $pdo->prepare("UPDATE products SET discount_percent = 0 WHERE discount_percent > 0");
                $stmt->execute();
                $affected = $stmt->rowCount();
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã tắt giảm giá cho $affected sản phẩm"];
            } catch (Exception $e) {
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Có lỗi xảy ra khi tắt giảm giá.'];
            }
            header('Location: products.php?sale=1'); exit;
        }

        /* TẮT GIẢM GIÁ CHO CÁC SP ĐÃ CHỌN */
        if ($action === 'clear_selected_discounts') {
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) $ids = [];
            $ids = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

            if (empty($ids)) {
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Chưa chọn sản phẩm nào.'];
                header('Location: ' . productsBackUrl()); exit;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                $stmt = $pdo->prepare("UPDATE products SET discount_percent = 0 WHERE discount_percent > 0 AND id IN ($placeholders)");
                $stmt->execute($ids);
                $affected = $stmt->rowCount();
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã tắt giảm giá cho $affected sản phẩm đã chọn"];
            } catch (Exception $e) {
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Có lỗi xảy ra khi tắt giảm giá.'];
            }
            header('Location: ' . productsBackUrl()); exit;
        }

        /* LƯU */
        if ($action === 'save') {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $price       = (float)($_POST['price'] ?? 0);
            $costPrice   = (float)($_POST['cost_price'] ?? 0);
            $supplier    = trim($_POST['supplier'] ?? '');
            $productType = trim($_POST['product_type'] ?? '');
            $discount    = max(0, min(100, (int)($_POST['discount_percent'] ?? 0)));
            $imageUrl    = trim($_POST['image_url'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
            $gender      = in_array($_POST['gender'] ?? '', ['male','female','unisex'], true) ? $_POST['gender'] : 'unisex';
            $soldCount   = max(0, (int)($_POST['sold_count'] ?? 0));

            // Stocks
            $stocks = [];
            foreach (ALL_SIZES as $sz) {
                $stocks[$sz] = max(0, (int)($_POST['stock_' . strtolower($sz)] ?? 0));
            }

            if ($name === '' || $price <= 0 || $imageUrl === '') {
                $_SESSION['admin_flash'] = ['type' => 'error', 'msg' => 'Nhập đủ Tên, Giá bán, Ảnh.'];
                header('Location: products.php'); exit;
            }

            if ($id > 0) {
                $pdo->prepare("
                    UPDATE products 
                    SET name=?, price=?, cost_price=?, supplier=?, product_type=?,
                        discount_percent=?, image_url=?, description=?,
                        category_id=?, gender=?, sold_count=?
                    WHERE id=?
                ")->execute([$name, $price, $costPrice, $supplier ?: null, $productType ?: null,
                            $discount, $imageUrl, $description, $categoryId, $gender, $soldCount, $id]);

                foreach ($stocks as $sz => $qty) {
                    setStock($pdo, $id, $sz, $qty);
                }
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật sản phẩm #$id"];
            } else {
                $pdo->prepare("
                    INSERT INTO products 
                        (name, price, cost_price, supplier, product_type, discount_percent,
                        image_url, description, category_id, gender, sold_count, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$name, $price, $costPrice, $supplier ?: null, $productType ?: null,
                            $discount, $imageUrl, $description, $categoryId, $gender, $soldCount]);
                $newId = (int)$pdo->lastInsertId();

                foreach ($stocks as $sz => $qty) {
                    setStock($pdo, $newId, $sz, $qty);
                }
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => 'Đã thêm sản phẩm mới'];
            }
            header('Location: products.php'); exit;
        }
    }

    /* ============ LẤY DỮ LIỆU ============ */
    $search       = trim($_GET['q'] ?? '');
    $category     = (int)($_GET['cat'] ?? 0);
    $sale         = (int)($_GET['sale'] ?? 0);   // 0 = tất cả, 1 = đang giảm giá
    $stock_status = $_GET['stock_status'] ?? ''; // '', 'low', 'old'

    $where = []; $params = [];
    if ($search !== '') {
        $where[] = "p.name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    if ($category > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $category;
    }
    
    // Bộ lọc tồn kho
    if ($stock_status === 'old') {
        $where[] = "p.created_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        $where[] = "(SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) > 0";
    } elseif ($stock_status === 'low') {
        // Tìm những SP có ít nhất 1 size tồn kho từ 1 đến 5
        $where[] = "EXISTS (
            SELECT 1 FROM product_sizes ps2 
            WHERE ps2.product_id = p.id AND ps2.stock > 0 AND ps2.stock <= 5
        )";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS cat_name,
            (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) AS total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        $whereSQL
        ORDER BY p.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lấy stock từng size cho tất cả SP
    $allStocks = [];
    if (!empty($products)) {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stockStmt = $pdo->prepare("
            SELECT product_id, size, stock FROM product_sizes 
            WHERE product_id IN ($placeholders)
        ");
        $stockStmt->execute($ids);
        foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allStocks[$row['product_id']][$row['size']] = (int)$row['stock'];
        }
    }

    /* Lọc theo sự kiện giảm giá ở PHP */
    if ($sale === 1) {
        $products = array_values(array_filter($products, function($p) {
            if (function_exists('calculateProductPrice')) {
                $pricing = calculateProductPrice($p);
                return $pricing['percent'] > 0;
            } else {
                return (int)$p['discount_percent'] > 0;
            }
        }));
    }

    /* Danh mục */
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    /* Helper lấy stock đầy đủ */
    if (!function_exists('stocksOf')) {
        function stocksOf(array $allStocks, int $id): array {
            $result = [];
            foreach (ALL_SIZES as $sz) {
                $result[$sz] = $allStocks[$id][$sz] ?? 0;
            }
            return $result;
        }
    }
    
    // Tính toán thời gian hiện tại để check SP lâu ngày
    $now = time();
    ?>

    <div class="admin-page-head">
        <div>
            <h1>Sản phẩm</h1>
            <div class="subtitle"><?php echo count($products); ?> sản phẩm</div>
        </div>
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;">
            <?php if ($sale === 1): ?>
                <form method="POST" id="clearDiscountForm" style="display:inline-block;margin:0;"
                      onsubmit="return confirm('Tắt giảm giá cho TẤT CẢ sản phẩm đang giảm giá?\n\n(Lưu ý: Sự kiện giảm giá vẫn đang bật sẽ tiếp tục áp dụng cho sản phẩm)');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="clear_all_discounts">
                    <button type="submit" class="btn-adm btn-adm--ghost" style="color:#c2410c;border-color:#c2410c;" title="Đặt discount_percent = 0 cho tất cả SP">
                        🚫 Tắt tất cả giảm giá riêng
                    </button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn-adm btn-adm--primary" onclick="openProductModal()">
                + Thêm sản phẩm mới
            </button>
        </div>
    </div>

    <!-- FILTER -->
    <div class="admin-card" style="padding:1rem 1.25rem;">
        <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:2;min-width:180px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Tìm kiếm
                </label>
                <input type="text" name="q" class="adm-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tên sản phẩm...">
            </div>
            <div style="flex:1;min-width:140px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Danh mục
                </label>
                <select name="cat" class="adm-select">
                    <option value="0">Tất cả</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $category === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:140px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Ưu đãi
                </label>
                <select name="sale" class="adm-select">
                    <option value="0" <?php echo $sale === 0 ? 'selected' : ''; ?>>Tất cả</option>
                    <option value="1" <?php echo $sale === 1 ? 'selected' : ''; ?>>Đang giảm giá</option>
                </select>
            </div>
            <!-- BỘ LỌC TÌNH TRẠNG KHO MỚI -->
            <div style="flex:1;min-width:150px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Tình trạng kho
                </label>
                <select name="stock_status" class="adm-select">
                    <option value="">Tất cả</option>
                    <option value="low" <?php echo $stock_status === 'low' ? 'selected' : ''; ?>>Có size sắp hết (1-5)</option>
                    <option value="old" <?php echo $stock_status === 'old' ? 'selected' : ''; ?>>⏳ Tồn kho lâu (>90 ngày)</option>
                </select>
            </div>
            <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
            <?php if ($search !== '' || $category > 0 || $sale > 0 || $stock_status !== ''): ?>
                <a href="products.php" class="btn-adm btn-adm--ghost">Xóa lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- BULK ACTION BAR -->
    <?php if ($sale === 1 && !empty($products)): ?>
    <div id="bulkBar" class="admin-card" style="padding:0.75rem 1.25rem; margin-top:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; background:#fff7ed; border:1px solid #fed7aa;">
        <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.88rem;font-weight:600;cursor:pointer;color:#1c1b19;">
            <input type="checkbox" id="selectAllCb" style="width:18px;height:18px;accent-color:#e11d48;cursor:pointer;">
            Chọn tất cả SP đang giảm giá
        </label>
        <span style="font-size:0.85rem;color:#4b5563;">Đã chọn: <b id="selCount">0</b></span>
        <form method="POST" id="bulkDiscountForm" style="margin:0;margin-left:auto;" onsubmit="return confirmBulkDiscount();">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="clear_selected_discounts">
            <div id="bulkIds"></div>
            <button type="submit" class="btn-adm btn-adm--ghost" id="bulkBtn" disabled style="color:#c2410c;border-color:#c2410c;opacity:0.55;cursor:not-allowed;">
                🚫 Tắt giảm giá riêng đã chọn
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- PRODUCT GRID -->
    <?php if (empty($products)): ?>
        <div class="admin-card">
            <p style="text-align:center;padding:3rem;color:var(--admin-text-muted);">Không có sản phẩm nào</p>
        </div>
    <?php else: ?>
        <style>
            /* CSS Giữ nguyên */
            .apd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; margin-top: 1rem; }
            .apd-card { background: #fffdf9; border: 1px solid #e8e0d0; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s; position: relative; }
            .apd-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(28,27,25,0.1); }
            .apd-card.is-selected { border-color: #e11d48; box-shadow: 0 0 0 2px rgba(225,29,72,0.25); }

            .apd-image { position: relative; aspect-ratio: 3 / 4; background: #f1f5f9; overflow: hidden; cursor: pointer; }
            .apd-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
            .apd-card:hover .apd-image img { transform: scale(1.04); }

            .apd-checkbox-wrap { position: absolute; top: 0.55rem; left: 0.55rem; z-index: 4; display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; background: rgba(255,255,255,0.96); border-radius: 6px; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.06); transition: transform 0.15s; }
            .apd-checkbox-wrap:hover { transform: scale(1.08); }
            .apd-checkbox-wrap input { width: 18px; height: 18px; cursor: pointer; accent-color: #e11d48; margin: 0; }

            .apd-status { position: absolute; bottom: 0.65rem; left: 0.65rem; padding: 0.3rem 0.65rem; border-radius: 20px; font-family: 'IBM Plex Mono', monospace; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; z-index: 3; display: inline-flex; align-items: center; gap: 4px; }
            .apd-status--in  { background: #dcfce7; color: #166534; }
            .apd-status--low { background: #fef3c7; color: #92400e; }
            .apd-status--out { background: #1c1b19; color: #fffdf9; }

            .apd-discount { position: absolute; top: 0.65rem; right: 0.65rem; background: #e11d48; color: #fff; padding: 0.3rem 0.6rem; border-radius: 4px; font-family: 'IBM Plex Mono', monospace; font-size: 0.7rem; font-weight: 700; z-index: 3; }

            .apd-image__overlay { position: absolute; inset: 0; background: rgba(28,27,25,0.5); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.25s; }
            .apd-card:hover .apd-image__overlay { opacity: 1; }
            .apd-image__overlay span { background: #fffdf9; color: #1c1b19; padding: 0.5rem 1rem; border-radius: 20px; font-family: 'IBM Plex Mono', monospace; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }

            .apd-info { padding: 0.9rem 1rem 0.75rem; flex: 1; display: flex; flex-direction: column; gap: 0.35rem; }
            .apd-name { font-family: 'Inter', sans-serif; font-size: 0.92rem; font-weight: 600; line-height: 1.35; color: #1c1b19; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; cursor: pointer; transition: color 0.2s; }
            .apd-name:hover { color: #a8461f; }
            .apd-price { font-family: 'IBM Plex Mono', monospace; font-size: 0.95rem; font-weight: 600; color: #a8461f; margin-top: auto; }

            .apd-actions { display: flex; border-top: 1px solid #e8e0d0; margin-top: 0.35rem; }
            .apd-actions button, .apd-actions a { flex: 1; padding: 0.6rem 0.5rem; background: transparent; border: none; font-family: 'IBM Plex Mono', monospace; font-size: 0.68rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; cursor: pointer; text-align: center; transition: background 0.2s, color 0.2s; color: #1c1b19; }
            .apd-actions button:hover { background: #f2ece0; }
            .apd-actions .apd-btn-edit:hover { background: #2563eb; color: #fff; }
            .apd-actions .apd-btn-delete:hover { background: #e11d48; color: #fff; }
            .apd-actions .apd-btn-view:hover { background: #1c1b19; color: #fffdf9; }
            .apd-actions > * + * { border-left: 1px solid #e8e0d0; }

            .apd-detail-modal .adm-modal__box { max-width: 820px; }
            .apd-detail-header { display: grid; grid-template-columns: 200px 1fr; gap: 1.5rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #e8e0d0; }
            .apd-detail-header img { width: 100%; aspect-ratio: 3/4; object-fit: cover; border-radius: 8px; background: #f1f5f9; }
            .apd-detail-meta h3 { font-family: 'Fraunces', serif; font-size: 1.35rem; margin: 0 0 0.5rem; color: #1c1b19; }
            .apd-detail-meta .meta-row { display: flex; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 0.35rem; color: #4b5563; }
            .apd-detail-meta .meta-row b { color: #8a8377; min-width: 110px; font-family: 'IBM Plex Mono', monospace; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }

            .apd-detail-section { margin-bottom: 1.5rem; }
            .apd-detail-section__title { font-family: 'IBM Plex Mono', monospace; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #8a8377; margin: 0 0 0.75rem; }

            .apd-stock-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.6rem; }
            .apd-stock-cell { background: #f8f9fa; border: 1px solid #e8e0d0; border-radius: 6px; padding: 0.75rem 0.5rem; text-align: center; }
            .apd-stock-cell .size { font-family: 'IBM Plex Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #1c1b19; display: block; margin-bottom: 0.35rem; }
            .apd-stock-cell .qty { font-family: 'IBM Plex Mono', monospace; font-size: 1.1rem; font-weight: 600; display: block; }
            .apd-stock-cell.out .qty { color: #b91c1c; }
            .apd-stock-cell.low .qty { color: #d97706; }
            .apd-stock-cell.in  .qty { color: #166534; }

            .apd-desc { background: #f8f9fa; padding: 0.85rem 1rem; border-radius: 6px; font-size: 0.88rem; line-height: 1.6; color: #4b5563; white-space: pre-wrap; }
            
            /* CSS BADGE TỒN KHO LÂU */
            .apd-old-stock { display:inline-block; margin-top: 4px; font-size: 0.7rem; color: #c2410c; background: #fff7ed; padding: 2px 6px; border-radius: 4px; border: 1px solid #fed7aa; font-weight: 600; }

            @media (max-width: 768px) {
                .apd-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
                .apd-detail-header { grid-template-columns: 1fr; }
                .apd-stock-grid { grid-template-columns: repeat(3, 1fr); }
            }
        </style>

        <div class="apd-grid" id="apdGrid">
            <?php foreach ($products as $p):
                $pid    = (int)$p['id'];
                $stocks = stocksOf($allStocks, $pid);
                $total  = array_sum($stocks);
                
                // TÍNH GIÁ ÁP DỤNG KHUYẾN MÃI
                if (function_exists('calculateProductPrice')) {
                    $pricing = calculateProductPrice($p);
                    $effective_discount = $pricing['percent'];
                    $final_price = $pricing['final'];
                    $hasDiscount = $effective_discount > 0;
                } else {
                    $effective_discount = (int)$p['discount_percent'];
                    $final_price = $effective_discount > 0 ? $p['price'] * (100 - $effective_discount) / 100 : $p['price'];
                    $pricing = [
                        'source' => $effective_discount > 0 ? 'product' : 'none',
                        'event_name' => ''
                    ];
                    $hasDiscount = $effective_discount > 0;
                }

                // TÌM CÁC SIZE SẮP HẾT (1-5)
                $lowSizes = [];
                foreach ($stocks as $sz => $qty) {
                    if ($qty > 0 && $qty <= 5) {
                        $lowSizes[] = $sz;
                    }
                }

                if ($total <= 0) {
                    $statusCls = 'apd-status--out';
                    $statusTxt = '⛔ Hết hàng';
                } elseif (!empty($lowSizes)) {
                    $statusCls = 'apd-status--low';
                    $displaySizes = array_slice($lowSizes, 0, 2); // Chỉ hiện tối đa 2 size cho gọn
                    $more = count($lowSizes) > 2 ? '+' : '';
                    $statusTxt = '⚠️ Sắp hết size ' . implode(', ', $displaySizes) . $more;
                } else {
                    $statusCls = 'apd-status--in';
                    $statusTxt = '✓ Còn hàng';
                }

                // KIỂM TRA TỒN KHO LÂU NGÀY (> 90 ngày và còn hàng)
                $createdTime = strtotime($p['created_at']);
                $daysInStock = floor(($now - $createdTime) / (60 * 60 * 24));
                $isOldStock = ($daysInStock > 90 && $total > 0);

                // Chuẩn bị JSON cho modal
                $detailJson = [
                    'id'             => $pid,
                    'name'           => $p['name'],
                    'image_url'      => $p['image_url'],
                    'price'          => (float)$p['price'],
                    'final_price'    => (float)$final_price,
                    'cost_price'     => (float)($p['cost_price'] ?? 0),
                    'discount_percent' => (int)$effective_discount,
                    'discount_source'  => $pricing['source'],
                    'event_name'       => $pricing['event_name'],
                    'supplier'       => $p['supplier'] ?? '',
                    'product_type'   => $p['product_type'] ?? '',
                    'category'       => $p['cat_name'] ?? '—',
                    'gender'         => $p['gender'] ?? 'unisex',
                    'sold_count'     => (int)($p['sold_count'] ?? 0),
                    'description'    => $p['description'] ?? '',
                    'stocks'         => $stocks,
                    'low_sizes'      => $lowSizes,
                    'total_stock'    => $total,
                    'days_in_stock'  => $daysInStock,
                    'created_at'     => date('d/m/Y', $createdTime)
                ];
            ?>
                <article class="apd-card" data-id="<?php echo $pid; ?>">
                    <!-- Ảnh + badge -->
                    <div class="apd-image" onclick='viewDetail(<?php echo htmlspecialchars(json_encode($detailJson, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                        <?php if ($hasDiscount && $sale === 1): ?>
                            <label class="apd-checkbox-wrap" onclick="event.stopPropagation();" title="Chọn để tắt giảm giá riêng">
                                <input type="checkbox" class="apd-cb" value="<?php echo $pid; ?>">
                            </label>
                        <?php endif; ?>
                        
                        <span class="apd-status <?php echo $statusCls; ?>"><?php echo $statusTxt; ?></span>
                        
                        <?php if ($hasDiscount): ?>
                            <span class="apd-discount" title="<?php echo $pricing['source'] === 'event' ? 'Sự kiện: ' . htmlspecialchars($pricing['event_name']) : 'Giảm giá sản phẩm'; ?>">
                                -<?php echo (int)$effective_discount; ?>%
                            </span>
                        <?php endif; ?>
                        <img src="../<?php echo htmlspecialchars($p['image_url']); ?>"
                            alt="<?php echo htmlspecialchars($p['name']); ?>"
                            onerror="this.src='https://via.placeholder.com/300x400?text=No+Image'">
                        <div class="apd-image__overlay"><span>👁 Xem chi tiết</span></div>
                    </div>

                    <!-- Tên + giá -->
                    <div class="apd-info">
                        <h3 class="apd-name" onclick='viewDetail(<?php echo htmlspecialchars(json_encode($detailJson, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                            <?php echo htmlspecialchars($p['name']); ?>
                        </h3>
                        
                        <?php if ($isOldStock): ?>
                            <div class="apd-old-stock" title="Sản phẩm đã nhập kho hơn 90 ngày nhưng vẫn chưa bán hết">
                                ⏳ Tồn > <?php echo $daysInStock; ?> ngày
                            </div>
                        <?php endif; ?>

                        <div class="apd-price" style="margin-top: 0.5rem;">
                            <?php if ($hasDiscount): ?>
                                <span style="text-decoration: line-through; color: #8a8377; font-size: 0.8em; font-weight: 400; margin-right: 6px;">
                                    <?php echo number_format($p['price'], 0, ',', '.'); ?>đ
                                </span>
                                <span style="color: #e11d48;">
                                    <?php echo number_format($final_price, 0, ',', '.'); ?>đ
                                </span>
                            <?php else: ?>
                                <?php echo number_format($p['price'], 0, ',', '.'); ?>đ
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Action bar -->
                    <div class="apd-actions">
                        <button type="button" class="apd-btn-view" onclick='viewDetail(<?php echo htmlspecialchars(json_encode($detailJson, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                            Xem
                        </button>
                        <button type="button" class="apd-btn-edit" onclick='editProduct(<?php echo htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                            Sửa
                        </button>
                        <form method="POST" style="flex:1;margin:0;" onsubmit="return confirmDelete('Xóa sản phẩm này?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $pid; ?>">
                            <button type="submit" class="apd-btn-delete" style="width:100%;">Xóa</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============ MODAL XEM CHI TIẾT ============ -->
    <div class="adm-modal apd-detail-modal" id="detailModal">
        <div class="adm-modal__box">
            <button type="button" class="adm-modal__close" onclick="closeDetailModal()">×</button>
            <h2 class="adm-modal__title" id="detailModalTitle">Chi tiết sản phẩm</h2>
            <div id="detailContent"></div>
        </div>
    </div>

    <!-- ============ MODAL SỬA/THÊM ============ -->
    <div class="adm-modal" id="productModal">
        <div class="adm-modal__box" style="max-width:680px;">
            <button type="button" class="adm-modal__close" onclick="closeProductModal()">×</button>
            <h2 class="adm-modal__title" id="productModalTitle">Thêm sản phẩm</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="p_id" value="">

                <div class="adm-form-group">
                    <label>Tên sản phẩm *</label>
                    <input type="text" name="name" id="p_name" class="adm-input" required maxlength="200">
                </div>

                <div class="adm-row">
                    <div class="adm-form-group">
                        <label>Giá bán (VNĐ) *</label>
                        <input type="number" name="price" id="p_price" class="adm-input" required min="0" step="1000">
                    </div>
                    <div class="adm-form-group">
                        <label>Giá nhập (VNĐ)</label>
                        <input type="number" name="cost_price" id="p_cost_price" class="adm-input" min="0" step="1000" value="0">
                    </div>
                </div>

                <div class="adm-form-group">
                    <label>Ảnh sản phẩm (URL hoặc đường dẫn) *</label>
                    <input type="text" name="image_url" id="p_image_url" class="adm-input" required placeholder="images/products/abc.jpg">
                </div>

                <div class="adm-row">
                    <div class="adm-form-group">
                        <label>Giảm giá riêng (%)</label>
                        <input type="number" name="discount_percent" id="p_discount" class="adm-input" min="0" max="100" value="0">
                    </div>
                    <div class="adm-form-group">
                        <label>Phân loại</label>
                        <input type="text" name="product_type" id="p_product_type" class="adm-input" maxlength="50" placeholder="Áo thun, Quần jean...">
                    </div>
                </div>

                <div class="adm-form-group">
                    <label>Nguồn nhập hàng</label>
                    <input type="text" name="supplier" id="p_supplier" class="adm-input" maxlength="100" placeholder="Xưởng may Hà Nội...">
                </div>

                <div class="adm-row">
                    <div class="adm-form-group">
                        <label>Danh mục</label>
                        <select name="category_id" id="p_category_id" class="adm-select">
                            <option value="">— Không chọn —</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adm-form-group">
                        <label>Giới tính</label>
                        <select name="gender" id="p_gender" class="adm-select">
                            <option value="unisex">Unisex</option>
                            <option value="male">Nam</option>
                            <option value="female">Nữ</option>
                        </select>
                    </div>
                </div>

                <div class="adm-form-group">
                    <label>Số lượng đã bán</label>
                    <input type="number" name="sold_count" id="p_sold_count" class="adm-input" min="0" value="0">
                </div>

                <!-- Tồn kho theo size -->
                <div style="background:#f2ece0;padding:1rem;border-radius:6px;margin:1rem 0;">
                    <label style="display:block;font-family:'IBM Plex Mono',monospace;font-size:0.72rem; font-weight:700;text-transform:uppercase;letter-spacing:0.08em; color:#8a8377;margin-bottom:0.75rem;">
                        📦 Tồn kho theo size
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.5rem;">
                        <?php foreach (ALL_SIZES as $sz): ?>
                            <div>
                                <label style="display:block;text-align:center;font-family:'IBM Plex Mono',monospace; font-size:0.7rem;font-weight:600;margin-bottom:0.3rem;">
                                    <?php echo $sz; ?>
                                </label>
                                <input type="number" name="stock_<?php echo strtolower($sz); ?>" id="p_stock_<?php echo strtolower($sz); ?>" class="adm-input" min="0" value="0" style="text-align:center;font-family:'IBM Plex Mono',monospace;">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="adm-form-group">
                    <label>Mô tả</label>
                    <textarea name="description" id="p_description" class="adm-textarea" rows="4"></textarea>
                </div>

                <div style="display:flex;gap:0.75rem;margin-top:1.5rem;">
                    <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem" onclick="closeProductModal()">Hủy</button>
                    <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                        💾 Lưu sản phẩm
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    /* ============================================================
    MODAL XEM CHI TIẾT
    ============================================================ */
    var detailModal = document.getElementById('detailModal');

    function viewDetail(p) {
        document.getElementById('detailModalTitle').textContent = 'Chi tiết: ' + p.name;

        var statusInfo;
        if (p.total_stock <= 0) {
            statusInfo = { text: '⛔ Hết hàng', color: '#b91c1c', bg: '#fee2e2' };
        } else if (p.low_sizes && p.low_sizes.length > 0) {
            statusInfo = { text: '⚠️ Sắp hết size: ' + p.low_sizes.join(', '), color: '#92400e', bg: '#fef3c7' };
        } else {
            statusInfo = { text: '✓ Còn hàng', color: '#166534', bg: '#dcfce7' };
        }

        var genderLabel = { male: 'Nam', female: 'Nữ', unisex: 'Unisex' }[p.gender] || 'Unisex';
        var finalPrice = p.final_price !== undefined ? p.final_price : (p.discount_percent > 0 ? p.price * (100 - p.discount_percent) / 100 : p.price);
        var profit = finalPrice - p.cost_price;

        var html = '';

        /* HEADER: Ảnh + thông tin cơ bản */
        html += '<div class="apd-detail-header">';
        html += '<img src="../' + escapeHtml(p.image_url) + '" alt="" onerror="this.src=\'https://via.placeholder.com/300x400?text=No+Image\'">';
        html += '<div class="apd-detail-meta">';
        html += '<h3>' + escapeHtml(p.name) + '</h3>';
        html += '<div style="margin-bottom:0.75rem;"><span style="background:' + statusInfo.bg + ';color:' + statusInfo.color
            + ';padding:4px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;">' + statusInfo.text + '</span></div>';

        html += '<div class="meta-row"><b>Danh mục</b><span>' + escapeHtml(p.category) + '</span></div>';
        html += '<div class="meta-row"><b>Phân loại</b><span>' + escapeHtml(p.product_type || '—') + '</span></div>';
        html += '<div class="meta-row"><b>Giới tính</b><span>' + genderLabel + '</span></div>';
        html += '<div class="meta-row"><b>Nguồn nhập</b><span>' + escapeHtml(p.supplier || '—') + '</span></div>';
        html += '<div class="meta-row"><b>Đã bán</b><span>' + p.sold_count + ' sản phẩm</span></div>';
        html += '<div class="meta-row"><b>Ngày nhập kho</b><span>' + p.created_at + ' <span style="color:#8a8377">(' + p.days_in_stock + ' ngày trước)</span></span></div>';
        html += '</div></div>';

        /* GIÁ */
        html += '<div class="apd-detail-section">';
        html += '<h4 class="apd-detail-section__title">💰 Giá cả</h4>';
        html += '<div class="apd-stock-grid" style="grid-template-columns:repeat(4,1fr);">';

        html += '<div class="apd-stock-cell"><span class="size">Giá nhập</span><span class="qty" style="color:#8a8377;">'
            + formatVnd(p.cost_price) + '</span></div>';

        html += '<div class="apd-stock-cell"><span class="size">Giá bán</span><span class="qty" style="color:#1c1b19;">'
            + formatVnd(p.price) + '</span></div>';

        html += '<div class="apd-stock-cell"><span class="size">Giảm</span><span class="qty" style="color:#e11d48;">-'
            + p.discount_percent + '%</span></div>';

        html += '<div class="apd-stock-cell"><span class="size">Lãi/sp</span><span class="qty" style="color:'
            + (profit >= 0 ? '#166534' : '#b91c1c') + ';">' + formatVnd(profit) + '</span></div>';

        html += '</div>';

        // Thêm thông tin nguồn giảm giá
        if (p.discount_source === 'event') {
            html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#166534;background:#dcfce7;padding:0.5rem 0.75rem;border-radius:6px;">';
            html += '🎉 Đang áp dụng sự kiện: <b>' + escapeHtml(p.event_name) + '</b>';
            html += '</div>';
        } else if (p.discount_source === 'product') {
            html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#c2410c;background:#fff7ed;padding:0.5rem 0.75rem;border-radius:6px;">';
            html += '🏷️ Đang áp dụng giảm giá riêng của sản phẩm';
            html += '</div>';
        }

        html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#4b5563;">';
        html += '→ Khách trả: <b style="color:#a8461f;font-family:\'IBM Plex Mono\',monospace;">'
            + formatVnd(finalPrice) + '</b> (giá sau giảm)';
        html += '</div>';
        html += '</div>';

        /* TỒN KHO */
        html += '<div class="apd-detail-section">';
        html += '<h4 class="apd-detail-section__title">📦 Tồn kho theo size</h4>';
        html += '<div class="apd-stock-grid">';
        ['S','M','L','XL','2XL'].forEach(function (sz) {
            var qty = p.stocks[sz] || 0;
            var cls = qty <= 0 ? 'out' : (qty <= 5 ? 'low' : 'in');
            html += '<div class="apd-stock-cell ' + cls + '">';
            html += '<span class="size">' + sz + '</span>';
            html += '<span class="qty">' + qty + '</span>';
            html += '</div>';
        });
        html += '</div>';
        html += '<div style="margin-top:0.6rem;font-size:0.85rem;color:#4b5563;">';
        html += '→ Tổng tồn: <b>' + p.total_stock + '</b> sản phẩm';
        html += '</div>';
        html += '</div>';

        /* MÔ TẢ */
        if (p.description) {
            html += '<div class="apd-detail-section">';
            html += '<h4 class="apd-detail-section__title">📝 Mô tả</h4>';
            html += '<div class="apd-desc">' + escapeHtml(p.description) + '</div>';
            html += '</div>';
        }

        document.getElementById('detailContent').innerHTML = html;
        detailModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeDetailModal() {
        detailModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    detailModal.addEventListener('click', function (e) {
        if (e.target === this) closeDetailModal();
    });

    /* Helper */
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function formatVnd(n) {
        return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ';
    }

    /* ============================================================
    MODAL SỬA/THÊM
    ============================================================ */
    var productModal = document.getElementById('productModal');

    function openProductModal() {
        document.getElementById('productModalTitle').textContent = 'Thêm sản phẩm mới';
        document.querySelector('#productModal form').reset();
        document.getElementById('p_id').value = '';
        document.getElementById('p_discount').value = 0;
        document.getElementById('p_cost_price').value = 0;
        document.getElementById('p_sold_count').value = 0;

        ['s','m','l','xl','2xl'].forEach(function (sz) {
            var el = document.getElementById('p_stock_' + sz);
            if (el) el.value = 20;
        });

        productModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function editProduct(p) {
        document.getElementById('productModalTitle').textContent = 'Sửa sản phẩm #' + p.id;
        document.getElementById('p_id').value           = p.id;
        document.getElementById('p_name').value         = p.name;
        document.getElementById('p_price').value        = p.price;
        document.getElementById('p_cost_price').value   = p.cost_price || 0;
        document.getElementById('p_discount').value     = p.discount_percent || 0;
        document.getElementById('p_product_type').value = p.product_type || '';
        document.getElementById('p_supplier').value     = p.supplier || '';
        document.getElementById('p_image_url').value    = p.image_url;
        document.getElementById('p_description').value  = p.description || '';
        document.getElementById('p_category_id').value  = p.category_id || '';
        document.getElementById('p_gender').value       = p.gender || 'unisex';
        document.getElementById('p_sold_count').value   = p.sold_count || 0;

        // Load stock
        ['s','m','l','xl','2xl'].forEach(function (sz) {
            var el = document.getElementById('p_stock_' + sz);
            if (el) el.value = 0;
        });

        fetch('get_product_stock.php?id=' + p.id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.stocks) {
                    ['s','m','l','xl','2xl'].forEach(function (sz) {
                        var el = document.getElementById('p_stock_' + sz);
                        if (el) el.value = data.stocks[sz.toUpperCase()] || 0;
                    });
                }
            })
            .catch(function () {});

        productModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeProductModal() {
        productModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    productModal.addEventListener('click', function (e) {
        if (e.target === this) closeProductModal();
    });

    /* ESC đóng modal */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (detailModal.classList.contains('show')) closeDetailModal();
            if (productModal.classList.contains('show')) closeProductModal();
        }
    });

    /* ============================================================
    CHỌN NHIỀU SP + TẮT GIẢM GIÁ HÀNG LOẠT
    ============================================================ */
    (function () {
        var cbs = Array.prototype.slice.call(document.querySelectorAll('.apd-cb'));
        var bulkBar = document.getElementById('bulkBar');
        if (!cbs.length || !bulkBar) return;

        var selectAllCb = document.getElementById('selectAllCb');
        var selCountEl  = document.getElementById('selCount');
        var bulkBtn     = document.getElementById('bulkBtn');
        var bulkIdsWrap = document.getElementById('bulkIds');

        function refreshUI() {
            var selected = cbs.filter(function (cb) { return cb.checked; });
            selCountEl.textContent = selected.length;

            if (selected.length > 0) {
                bulkBtn.disabled = false;
                bulkBtn.style.opacity = '1';
                bulkBtn.style.cursor = 'pointer';
            } else {
                bulkBtn.disabled = true;
                bulkBtn.style.opacity = '0.55';
                bulkBtn.style.cursor = 'not-allowed';
            }

            if (selectAllCb) {
                var all = cbs.length > 0 && selected.length === cbs.length;
                selectAllCb.checked = all;
                selectAllCb.indeterminate = selected.length > 0 && !all;
            }

            cbs.forEach(function (cb) {
                var card = cb.closest('.apd-card');
                if (!card) return;
                if (cb.checked) card.classList.add('is-selected');
                else            card.classList.remove('is-selected');
            });
        }

        cbs.forEach(function (cb) { cb.addEventListener('change', refreshUI); });

        if (selectAllCb) {
            selectAllCb.addEventListener('change', function () {
                var check = selectAllCb.checked;
                cbs.forEach(function (cb) { cb.checked = check; });
                refreshUI();
            });
        }

        window.confirmBulkDiscount = function () {
            var selected = cbs.filter(function (cb) { return cb.checked; });
            if (!selected.length) {
                alert('Chưa chọn sản phẩm nào.');
                return false;
            }
            if (!confirm('Tắt giảm giá riêng cho ' + selected.length + ' sản phẩm đã chọn?\n\n(Lưu ý: Nếu sản phẩm đang nằm trong Sự kiện giảm giá đang bật, giá vẫn sẽ được giảm theo sự kiện)')) {
                return false;
            }
            bulkIdsWrap.innerHTML = '';
            selected.forEach(function (cb) {
                var inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'ids[]';
                inp.value = cb.value;
                bulkIdsWrap.appendChild(inp);
            });
            return true;
        };

        refreshUI();
    })();
    </script>

<?php include 'includes/admin_footer.php'; ?>