<?php
    require_once '../includes/config.php';
    
    if (file_exists('../includes/discount_helper.php')) {
        require_once '../includes/discount_helper.php';
    }

    /* ============ AJAX XỬ LÝ TÌM KIẾM SẢN PHẨM CHO KIỂM HÀNG ============ */
    if (isset($_GET['ajax_search_inventory'])) {
        header('Content-Type: application/json; charset=utf-8');
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.image_url,
                    (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) AS total_stock
                FROM products p
                WHERE p.name LIKE ?
                ORDER BY p.name ASC
                LIMIT 10
            ");
            $stmt->execute(["%$q%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($results)) {
                $ids = array_column($results, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stkStmt = $pdo->prepare("SELECT product_id, size, stock FROM product_sizes WHERE product_id IN ($placeholders)");
                $stkStmt->execute($ids);
                $allS = [];
                foreach ($stkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $allS[$row['product_id']][$row['size']] = (int)$row['stock'];
                }
                foreach ($results as &$r) {
                    $r['stocks'] = $allS[$r['id']] ?? [];
                }
            }
            echo json_encode(['ok' => true, 'data' => $results]);
        } else {
            echo json_encode(['ok' => true, 'data' => []]);
        }
        exit;
    }

    /* ============ AJAX LẤY DANH SÁCH PHIẾU KIỂM ============ */
    if (isset($_GET['ajax_inventory_history'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $stmt = $pdo->query("
                SELECT r.id, r.created_at, COUNT(d.id) as total_items
                FROM inventory_check_receipts r
                JOIN inventory_check_details d ON r.id = d.receipt_id
                GROUP BY r.id
                ORDER BY r.created_at DESC
                LIMIT 50
            ");
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $receipts]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Không thể tải lịch sử.']);
        }
        exit;
    }

    /* ============ AJAX LẤY CHI TIẾT MỘT PHIẾU KIỂM ============ */
    if (isset($_GET['ajax_inventory_receipt_details'])) {
        header('Content-Type: application/json; charset=utf-8');
        $rid = (int)($_GET['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                SELECT d.*, p.name, p.image_url 
                FROM inventory_check_details d
                LEFT JOIN products p ON d.product_id = p.id
                WHERE d.receipt_id = ?
                ORDER BY d.id ASC
            ");
            $stmt->execute([$rid]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $details]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Không thể tải chi tiết.']);
        }
        exit;
    }

    include 'includes/admin_header.php';

    if (!function_exists('productsBackUrl')) {
        function productsBackUrl(): string {
            $p = [];
            if (!empty($_GET['q']))    $p['q']    = $_GET['q'];
            if (isset($_GET['cat']) && $_GET['cat'] !== '') $p['cat'] = (int)$_GET['cat'];
            if (!empty($_GET['sale'])) $p['sale'] = (int)$_GET['sale'];
            if (!empty($_GET['stock_status'])) $p['stock_status'] = $_GET['stock_status'];
            if (!empty($_GET['collection'])) $p['collection'] = $_GET['collection'];
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

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã xóa sản phẩm #$id"];
            }
            header('Location: ' . productsBackUrl()); exit;
        }

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

        /* CẬP NHẬT TỒN KHO & TẠO PHIẾU KIỂM */
        if ($action === 'bulk_update_stock') {
            $stocksData = $_POST['stocks'] ?? [];
            $changes = [];
            
            if (is_array($stocksData)) {
                $getOldStmt = $pdo->prepare("SELECT stock FROM product_sizes WHERE product_id = ? AND size = ?");

                foreach ($stocksData as $pid => $sizes) {
                    $pid = (int)$pid;
                    if ($pid > 0 && is_array($sizes)) {
                        foreach ($sizes as $sz => $qty) {
                            if (in_array($sz, ALL_SIZES)) {
                                $newQty = max(0, (int)$qty);
                                $getOldStmt->execute([$pid, $sz]);
                                $oldQty = (int)$getOldStmt->fetchColumn();
                                
                                if ($oldQty !== $newQty) {
                                    $changes[] = [
                                        'pid' => $pid, 'size' => $sz, 'old' => $oldQty, 'new' => $newQty, 'diff' => ($newQty - $oldQty)
                                    ];
                                }
                            }
                        }
                    }
                }

                if (!empty($changes)) {
                    $pdo->prepare("INSERT INTO inventory_check_receipts (created_at) VALUES (NOW())")->execute();
                    $receiptId = $pdo->lastInsertId();
                    
                    $logStmt = $pdo->prepare("INSERT INTO inventory_check_details (receipt_id, product_id, size, old_stock, new_stock, change_amount) VALUES (?, ?, ?, ?, ?, ?)");
                    
                    foreach ($changes as $c) {
                        setStock($pdo, $c['pid'], $c['size'], $c['new']);
                        $logStmt->execute([$receiptId, $c['pid'], $c['size'], $c['old'], $c['new'], $c['diff']]);
                    }
                    $_SESSION['admin_flash'] = ['type' => 'success', 'msg' => "Đã cập nhật kho thành công! (Tạo phiếu kiểm #" . $receiptId . " với " . count($changes) . " thay đổi)"];
                } else {
                    $_SESSION['admin_flash'] = ['type' => 'info', 'msg' => "Không có thay đổi số lượng nào."];
                }
            }
            header('Location: ' . productsBackUrl()); 
            exit;
        }

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
            header('Location: ' . productsBackUrl()); exit;
        }
    }

    /* ============ LẤY DỮ LIỆU ============ */
    $search       = trim($_GET['q'] ?? '');
    $category     = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
    $sale         = (int)($_GET['sale'] ?? 0);
    $stock_status = $_GET['stock_status'] ?? '';
    $collection   = $_GET['collection'] ?? '';

    $where = []; $params = [];
    if ($search !== '') {
        $where[] = "p.name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    if ($category === -1) {
        $where[] = "p.category_id IS NULL";
    } elseif ($category > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $category;
    }
    if ($collection !== '') {
        switch ($collection) {
            case 'giam-gia': $where[] = "p.discount_percent > 0 AND p.id < 100"; break;
            case 'combo-thu-dong': $where[] = "p.category_id = (SELECT id FROM categories WHERE slug='combo' LIMIT 1) AND p.id < 100"; break;
            case 'ban-chay': $where[] = "p.sold_count > 0"; break;
            case 'giam-kich-san': $where[] = "p.id BETWEEN 100 AND 104"; break;
            case 'combo-thoi-trang': $where[] = "p.id BETWEEN 105 AND 109"; break;
            case 'phong-cach-toi-gian': $where[] = "p.id BETWEEN 110 AND 114"; break;
            case 'khuyen-mai-mua-he': $where[] = "p.id BETWEEN 115 AND 119"; break;
            case 'san-pham-moi': $where[] = "p.id BETWEEN 120 AND 124"; break;
        }
    }
    if ($stock_status === 'old') {
        $where[] = "p.created_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        $where[] = "(SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = p.id) > 0";
    } elseif ($stock_status === 'low') {
        $where[] = "EXISTS (SELECT 1 FROM product_sizes ps2 WHERE ps2.product_id = p.id AND ps2.stock > 0 AND ps2.stock <= 5)";
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

    $allStocks = [];
    if (!empty($products)) {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stockStmt = $pdo->prepare("SELECT product_id, size, stock FROM product_sizes WHERE product_id IN ($placeholders)");
        $stockStmt->execute($ids);
        foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allStocks[$row['product_id']][$row['size']] = (int)$row['stock'];
        }
    }

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

    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT section_key, title FROM homepage_sections ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (!function_exists('stocksOf')) {
        function stocksOf(array $allStocks, int $id): array {
            $result = [];
            foreach (ALL_SIZES as $sz) {
                $result[$sz] = $allStocks[$id][$sz] ?? 0;
            }
            return $result;
        }
    }
    
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
                      onsubmit="return confirm('Tắt giảm giá cho TẤT CẢ sản phẩm đang giảm giá?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="clear_all_discounts">
                    <button type="submit" class="btn-adm btn-adm--ghost" style="color:#c2410c;border-color:#c2410c;">
                        <i class="ph ph-prohibit"></i> Tắt tất cả giảm giá riêng
                    </button>
                </form>
            <?php endif; ?>
            
            <button type="button" class="btn-adm btn-adm--ghost" onclick="openHistoryModal()" style="color:#8a8377;border-color:#d1d5db;">
                <i class="ph ph-clock-counter-clockwise"></i> Lịch sử kiểm hàng
            </button>
            <button type="button" class="btn-adm btn-adm--ghost" onclick="openInventoryModal()" style="color:#2563eb;border-color:#2563eb;">
                <i class="ph ph-clipboard-text"></i> Kiểm hàng nhanh
            </button>

            <button type="button" class="btn-adm btn-adm--primary" onclick="openProductModal()">
                <i class="ph ph-plus"></i> Thêm sản phẩm mới
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
                    <option value="-1" <?php echo $category === -1 ? 'selected' : ''; ?>>— Chưa phân loại —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $category === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:150px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Khối trang chủ
                </label>
                <select name="collection" class="adm-select">
                    <option value="">Tất cả</option>
                    <?php foreach ($sections as $so): ?>
                        <option value="<?php echo htmlspecialchars($so['section_key']); ?>" <?php echo $collection === $so['section_key'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($so['title']); ?>
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
            <div style="flex:1;min-width:150px;">
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--admin-text-muted);margin-bottom:0.35rem;letter-spacing:0.08em;">
                    Tình trạng kho
                </label>
                <select name="stock_status" class="adm-select">
                    <option value="">Tất cả</option>
                    <option value="low" <?php echo $stock_status === 'low' ? 'selected' : ''; ?>>Có size sắp hết (1-5)</option>
                    <option value="old" <?php echo $stock_status === 'old' ? 'selected' : ''; ?>>Tồn kho lâu (>90 ngày)</option>
                </select>
            </div>
            <button type="submit" class="btn-adm btn-adm--primary">Lọc</button>
            <?php if ($search !== '' || $category !== 0 || $sale > 0 || $stock_status !== '' || $collection !== ''): ?>
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
                <i class="ph ph-prohibit"></i> Tắt giảm giá riêng đã chọn
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
            .apd-image__overlay span { background: #fffdf9; color: #1c1b19; padding: 0.5rem 1rem; border-radius: 20px; font-family: 'IBM Plex Mono', monospace; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; display: inline-flex; align-items: center; gap: 6px; }

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
            .apd-detail-section__title { font-family: 'IBM Plex Mono', monospace; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #8a8377; margin: 0 0 0.75rem; display: flex; align-items: center; gap: 6px; }

            .apd-stock-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.6rem; }
            .apd-stock-cell { background: #f8f9fa; border: 1px solid #e8e0d0; border-radius: 6px; padding: 0.75rem 0.5rem; text-align: center; }
            .apd-stock-cell .size { font-family: 'IBM Plex Mono', monospace; font-size: 0.75rem; font-weight: 700; color: #1c1b19; display: block; margin-bottom: 0.35rem; }
            .apd-stock-cell .qty { font-family: 'IBM Plex Mono', monospace; font-size: 1.1rem; font-weight: 600; display: block; }
            .apd-stock-cell.out .qty { color: #b91c1c; }
            .apd-stock-cell.low .qty { color: #d97706; }
            .apd-stock-cell.in  .qty { color: #166534; }

            .apd-desc { background: #f8f9fa; padding: 0.85rem 1rem; border-radius: 6px; font-size: 0.88rem; line-height: 1.6; color: #4b5563; white-space: pre-wrap; }
            
            .apd-old-stock { display:inline-flex; align-items:center; gap:4px; margin-top: 4px; font-size: 0.7rem; color: #c2410c; background: #fff7ed; padding: 2px 6px; border-radius: 4px; border: 1px solid #fed7aa; font-weight: 600; }

            .inventory-search-wrap { position: relative; margin-bottom: 1rem; }
            .inventory-search-wrap input { width: 100%; padding: 10px 15px; border: 2px solid #2563eb; border-radius: 8px; font-size: 0.95rem; outline: none; }
            .inventory-search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e8e0d0; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 100; max-height: 250px; overflow-y: auto; display: none; margin-top: 4px; }
            .inventory-search-item { display: flex; align-items: center; gap: 10px; padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1ede6; transition: background 0.2s; }
            .inventory-search-item:hover { background: #f8f9fa; }
            .inventory-search-item img { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; border: 1px solid #e8e0d0; }
            .inventory-search-item .name { font-weight: 600; font-size: 0.9rem; color: #1c1b19; flex: 1; }
            .inventory-search-item .add-btn { background: #2563eb; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 0.75rem; cursor: pointer; font-weight: 600; }
            
            .inventory-table { width: 100%; border-collapse: collapse; }
            .inventory-table th, .inventory-table td { padding: 0.6rem; border-bottom: 1px solid #e8e0d0; text-align: center; }
            .inventory-table th { background: #f8f9fa; font-family: 'IBM Plex Mono', monospace; font-size: 0.75rem; text-transform: uppercase; color: #8a8377; position: sticky; top: 0; z-index: 10; }
            .inventory-table td.prod-info { text-align: left; display: flex; align-items: center; gap: 10px; }
            .inventory-table td.prod-info img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #e8e0d0; }
            .inventory-table input[type="number"] { width: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; text-align: center; font-family: 'IBM Plex Mono', monospace; font-weight: 600; }
            .inventory-table input[type="number"]:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
            .btn-remove-inv { background: #fee2e2; color: #b91c1c; border: none; width: 30px; height: 30px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; }
            .btn-remove-inv:hover { background: #fca5a5; color: #7f1d1d; }

            /* CSS CHO LỊCH SỬ (ACCORDION) */
            .history-receipt-row { cursor: pointer; transition: background 0.2s; }
            .history-receipt-row:hover { background: #f8f9fa; }
            .history-receipt-row.active { background: #eff6ff; border-bottom: none; }
            .history-details-row { display: none; background: #fff; }
            .history-details-row.show { display: table-row; }
            .history-details-container { padding: 1rem; background: #f8f9fa; border-radius: 6px; margin: 0.5rem; border: 1px solid #e5e7eb; }
            .history-change.pos { color: #166534; font-weight: 700; font-family: 'IBM Plex Mono', monospace; background: #dcfce7; padding: 2px 6px; border-radius: 4px; }
            .history-change.neg { color: #b91c1c; font-weight: 700; font-family: 'IBM Plex Mono', monospace; background: #fee2e2; padding: 2px 6px; border-radius: 4px; }

            /* CSS CHO CHI TIẾT PHIẾU KIỂM GIAO DIỆN MỚI (HÀNG NGANG) */
            .receipt-product-row {
                display: flex; align-items: flex-start; gap: 1rem;
                padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px;
                background: #fff; margin-bottom: 0.75rem;
            }
            .receipt-product-info {
                width: 250px; flex-shrink: 0; display: flex; align-items: center; gap: 12px;
            }
            .receipt-product-info img {
                width: 56px; height: 56px; object-fit: cover; border-radius: 8px;
                border: 1px solid #e8e0d0;
            }
            .receipt-product-info .name {
                font-weight: 600; font-size: 0.9rem; color: #1c1b19; line-height: 1.4;
            }
            .receipt-sizes-list {
                display: flex; flex-wrap: wrap; gap: 8px; flex: 1;
            }
            .receipt-size-box {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px;
                padding: 8px 12px; min-width: 60px;
            }
            .receipt-size-box .size-lbl {
                font-weight: 700; font-family: 'IBM Plex Mono', monospace; font-size: 0.75rem;
                color: #4b5563; margin-bottom: 4px;
            }
            .receipt-size-box .qty-new {
                color: #166534; font-weight: 800; font-size: 1.1rem; line-height: 1;
                font-family: 'IBM Plex Mono', monospace;
            }
            .receipt-size-box .qty-old {
                color: #b91c1c; font-size: 0.75rem; text-decoration: line-through;
                font-family: 'IBM Plex Mono', monospace; margin-top: 2px;
            }
            
            @media (max-width: 768px) {
                .apd-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
                .apd-detail-header { grid-template-columns: 1fr; }
                .apd-stock-grid { grid-template-columns: repeat(3, 1fr); }
                .receipt-product-row { flex-direction: column; gap: 0.5rem; }
            }
        </style>

        <div class="apd-grid" id="apdGrid">
            <?php foreach ($products as $p):
                $pid    = (int)$p['id'];
                $stocks = stocksOf($allStocks, $pid);
                $total  = array_sum($stocks);
                
                if (function_exists('calculateProductPrice')) {
                    $pricing = calculateProductPrice($p);
                    $effective_discount = $pricing['percent'];
                    $final_price = $pricing['final'];
                    $hasDiscount = $effective_discount > 0;
                } else {
                    $effective_discount = (int)$p['discount_percent'];
                    $final_price = $effective_discount > 0 ? $p['price'] * (100 - $effective_discount) / 100 : $p['price'];
                    $pricing = ['source' => $effective_discount > 0 ? 'product' : 'none', 'event_name' => ''];
                    $hasDiscount = $effective_discount > 0;
                }

                $lowSizes = [];
                foreach ($stocks as $sz => $qty) {
                    if ($qty > 0 && $qty <= 5) { $lowSizes[] = $sz; }
                }

                if ($total <= 0) {
                    $statusCls = 'apd-status--out';
                    $statusTxt = '<i class="ph ph-x-circle"></i> Hết hàng';
                } elseif (!empty($lowSizes)) {
                    $statusCls = 'apd-status--low';
                    $displaySizes = array_slice($lowSizes, 0, 2);
                    $more = count($lowSizes) > 2 ? '+' : '';
                    $statusTxt = '<i class="ph ph-warning"></i> Sắp hết size ' . implode(', ', $displaySizes) . $more;
                } else {
                    $statusCls = 'apd-status--in';
                    $statusTxt = '<i class="ph ph-check-circle"></i> Còn hàng';
                }

                $createdTime = strtotime($p['created_at']);
                $daysInStock = floor(($now - $createdTime) / (60 * 60 * 24));
                $isOldStock = ($daysInStock > 90 && $total > 0);

                $detailJson = [
                    'id' => $pid, 'name' => $p['name'], 'image_url' => $p['image_url'],
                    'price' => (float)$p['price'], 'final_price' => (float)$final_price,
                    'cost_price' => (float)($p['cost_price'] ?? 0), 'discount_percent' => (int)$effective_discount,
                    'discount_source' => $pricing['source'], 'event_name' => $pricing['event_name'],
                    'supplier' => $p['supplier'] ?? '', 'product_type' => $p['product_type'] ?? '',
                    'category' => $p['cat_name'] ?? '—', 'gender' => $p['gender'] ?? 'unisex',
                    'sold_count' => (int)($p['sold_count'] ?? 0), 'description' => $p['description'] ?? '',
                    'stocks' => $stocks, 'low_sizes' => $lowSizes, 'total_stock' => $total,
                    'days_in_stock' => $daysInStock, 'created_at' => date('d/m/Y', $createdTime)
                ];
            ?>
                <article class="apd-card" data-id="<?php echo $pid; ?>">
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
                        <img src="../<?php echo htmlspecialchars($p['image_url']); ?>" alt="" onerror="this.src='https://via.placeholder.com/300x400?text=No+Image'">
                        <div class="apd-image__overlay"><span><i class="ph ph-eye"></i> Xem chi tiết</span></div>
                    </div>

                    <div class="apd-info">
                        <h3 class="apd-name" onclick='viewDetail(<?php echo htmlspecialchars(json_encode($detailJson, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)'>
                            <?php echo htmlspecialchars($p['name']); ?>
                        </h3>
                        
                        <?php if ($isOldStock): ?>
                            <div class="apd-old-stock" title="Sản phẩm đã nhập kho hơn 90 ngày nhưng vẫn chưa bán hết">
                                <i class="ph ph-clock"></i> Tồn > <?php echo $daysInStock; ?> ngày
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

    <!-- MODAL XEM CHI TIẾT -->
    <div class="adm-modal apd-detail-modal" id="detailModal">
        <div class="adm-modal__box">
            <button type="button" class="adm-modal__close" onclick="closeDetailModal()">&times;</button>
            <h2 class="adm-modal__title" id="detailModalTitle">Chi tiết sản phẩm</h2>
            <div id="detailContent"></div>
        </div>
    </div>

    <!-- MODAL KIỂM HÀNG NHANH -->
    <div class="adm-modal" id="inventoryModal">
        <div class="adm-modal__box" style="max-width:900px; height: 85vh; display:flex; flex-direction:column;">
            <button type="button" class="adm-modal__close" onclick="closeInventoryModal()">&times;</button>
            <h2 class="adm-modal__title">Kiểm hàng nhanh</h2>
            
            <form method="POST" style="flex:1; display:flex; flex-direction:column; overflow:hidden;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="bulk_update_stock">
                
                <div class="inventory-search-wrap">
                    <input type="text" id="inventorySearchInput" placeholder="Gõ tên sản phẩm để tìm và thêm vào bảng kiểm..." autocomplete="off">
                    <div class="inventory-search-results" id="inventorySearchResults"></div>
                </div>

                <div style="flex:1; overflow-y:auto; border:1px solid #e8e0d0; border-radius:6px; margin-bottom:15px; background:#fff;">
                    <table class="inventory-table" id="inventoryTable">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Sản phẩm</th>
                                <?php foreach (ALL_SIZES as $sz): ?>
                                    <th><?php echo $sz; ?></th>
                                <?php endforeach; ?>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                            <tr id="emptyInventoryRow">
                                <td colspan="<?php echo count(ALL_SIZES) + 2; ?>" style="text-align:center; padding: 3rem 1rem; color:#8a8377;">
                                    <i class="ph ph-magnifying-glass" style="font-size:2rem; opacity:0.5; margin-bottom:10px; display:block;"></i>
                                    Chưa có sản phẩm nào. Hãy tìm kiếm ở trên để thêm.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;gap:0.75rem;">
                    <button type="button" class="btn-adm btn-adm--ghost" style="flex:1;justify-content:center;padding:0.85rem" onclick="closeInventoryModal()">Hủy</button>
                    <button type="submit" class="btn-adm btn-adm--primary" style="flex:2;justify-content:center;padding:0.85rem">
                        <i class="ph ph-floppy-disk"></i> Lưu tất cả thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL LỊCH SỬ KIỂM HÀNG (DẠNG ACCORDION) -->
    <div class="adm-modal" id="historyModal">
        <div class="adm-modal__box" style="max-width:900px; height: 80vh; display:flex; flex-direction:column;">
            <button type="button" class="adm-modal__close" onclick="closeHistoryModal()">&times;</button>
            <h2 class="adm-modal__title">Lịch sử kiểm hàng</h2>
            <p style="color:#8a8377; font-size:0.85rem; margin-top:-10px; margin-bottom:15px;">
                Bấm vào từng phiếu để xem chi tiết các sản phẩm đã thay đổi.
            </p>

            <div style="flex:1; overflow-y:auto; border:1px solid #e8e0d0; border-radius:6px; background:#fff;">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Mã Phiếu</th>
                            <th>Thời gian</th>
                            <th>Số lượng SP thay đổi</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr>
                            <td colspan="4" style="text-align:center; padding:3rem; color:#8a8377;">
                                <i class="ph ph-spinner-gap" style="font-size:1.5rem; animation: spin 1s linear infinite; display:inline-block; margin-bottom:10px;"></i><br>
                                Đang tải dữ liệu...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL SỬA/THÊM -->
    <div class="adm-modal" id="productModal">
        <div class="adm-modal__box" style="max-width:680px;">
            <button type="button" class="adm-modal__close" onclick="closeProductModal()">&times;</button>
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

                <div style="background:#f2ece0;padding:1rem;border-radius:6px;margin:1rem 0;">
                    <label style="display:flex;align-items:center;gap:6px;font-family:'IBM Plex Mono',monospace;font-size:0.72rem; font-weight:700;text-transform:uppercase;letter-spacing:0.08em; color:#8a8377;margin-bottom:0.75rem;">
                        <i class="ph ph-package"></i> Tồn kho theo size
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
                        <i class="ph ph-floppy-disk"></i> Lưu sản phẩm
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    /* MODAL XEM CHI TIẾT */
    var detailModal = document.getElementById('detailModal');
    function viewDetail(p) {
        document.getElementById('detailModalTitle').textContent = 'Chi tiết: ' + p.name;
        var statusInfo;
        if (p.total_stock <= 0) {
            statusInfo = { text: '<i class="ph ph-x-circle"></i> Hết hàng', color: '#b91c1c', bg: '#fee2e2' };
        } else if (p.low_sizes && p.low_sizes.length > 0) {
            statusInfo = { text: '<i class="ph ph-warning"></i> Sắp hết size: ' + p.low_sizes.join(', '), color: '#92400e', bg: '#fef3c7' };
        } else {
            statusInfo = { text: '<i class="ph ph-check-circle"></i> Còn hàng', color: '#166534', bg: '#dcfce7' };
        }

        var genderLabel = { male: 'Nam', female: 'Nữ', unisex: 'Unisex' }[p.gender] || 'Unisex';
        var finalPrice = p.final_price !== undefined ? p.final_price : (p.discount_percent > 0 ? p.price * (100 - p.discount_percent) / 100 : p.price);
        var profit = finalPrice - p.cost_price;
        var html = '';

        html += '<div class="apd-detail-header">';
        html += '<img src="../' + escapeHtml(p.image_url) + '" alt="" onerror="this.src=\'https://via.placeholder.com/300x400?text=No+Image\'">';
        html += '<div class="apd-detail-meta">';
        html += '<h3>' + escapeHtml(p.name) + '</h3>';
        html += '<div style="margin-bottom:0.75rem;"><span style="background:' + statusInfo.bg + ';color:' + statusInfo.color
            + ';padding:4px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;">' + statusInfo.text + '</span></div>';
        html += '<div class="meta-row"><b>Danh mục</b><span>' + escapeHtml(p.category) + '</span></div>';
        html += '<div class="meta-row"><b>Phân loại</b><span>' + escapeHtml(p.product_type || '—') + '</span></div>';
        html += '<div class="meta-row"><b>Giới tính</b><span>' + genderLabel + '</span></div>';
        html += '<div class="meta-row"><b>Nguồn nhập</b><span>' + escapeHtml(p.supplier || '—') + '</span></div>';
        html += '<div class="meta-row"><b>Đã bán</b><span>' + p.sold_count + ' sản phẩm</span></div>';
        html += '<div class="meta-row"><b>Ngày nhập kho</b><span>' + p.created_at + ' <span style="color:#8a8377">(' + p.days_in_stock + ' ngày trước)</span></span></div>';
        html += '</div></div>';

        html += '<div class="apd-detail-section">';
        html += '<h4 class="apd-detail-section__title"><i class="ph ph-currency-circle-dollar"></i> Giá cả</h4>';
        html += '<div class="apd-stock-grid" style="grid-template-columns:repeat(4,1fr);">';
        html += '<div class="apd-stock-cell"><span class="size">Giá nhập</span><span class="qty" style="color:#8a8377;">'+ formatVnd(p.cost_price) + '</span></div>';
        html += '<div class="apd-stock-cell"><span class="size">Giá bán</span><span class="qty" style="color:#1c1b19;">'+ formatVnd(p.price) + '</span></div>';
        html += '<div class="apd-stock-cell"><span class="size">Giảm</span><span class="qty" style="color:#e11d48;">-'+ p.discount_percent + '%</span></div>';
        html += '<div class="apd-stock-cell"><span class="size">Lãi/sp</span><span class="qty" style="color:'+ (profit >= 0 ? '#166534' : '#b91c1c') + ';">' + formatVnd(profit) + '</span></div>';
        html += '</div>';
        if (p.discount_source === 'event') {
            html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#166534;background:#dcfce7;padding:0.5rem 0.75rem;border-radius:6px;display:flex;align-items:center;gap:6px;">';
            html += '<i class="ph ph-confetti"></i> Đang áp dụng sự kiện: <b>' + escapeHtml(p.event_name) + '</b></div>';
        } else if (p.discount_source === 'product') {
            html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#c2410c;background:#fff7ed;padding:0.5rem 0.75rem;border-radius:6px;display:flex;align-items:center;gap:6px;">';
            html += '<i class="ph ph-tag"></i> Đang áp dụng giảm giá riêng của sản phẩm</div>';
        }
        html += '<div style="margin-top:0.75rem;font-size:0.85rem;color:#4b5563;">→ Khách trả: <b style="color:#a8461f;font-family:\'IBM Plex Mono\',monospace;">'+ formatVnd(finalPrice) + '</b> (giá sau giảm)</div></div>';

        html += '<div class="apd-detail-section">';
        html += '<h4 class="apd-detail-section__title"><i class="ph ph-package"></i> Tồn kho theo size</h4>';
        html += '<div class="apd-stock-grid">';
        ['S','M','L','XL','2XL'].forEach(function (sz) {
            var qty = p.stocks[sz] || 0;
            var cls = qty <= 0 ? 'out' : (qty <= 5 ? 'low' : 'in');
            html += '<div class="apd-stock-cell ' + cls + '"><span class="size">' + sz + '</span><span class="qty">' + qty + '</span></div>';
        });
        html += '</div><div style="margin-top:0.6rem;font-size:0.85rem;color:#4b5563;">→ Tổng tồn: <b>' + p.total_stock + '</b> sản phẩm</div></div>';

        if (p.description) {
            html += '<div class="apd-detail-section"><h4 class="apd-detail-section__title"><i class="ph ph-note-pencil"></i> Mô tả</h4>';
            html += '<div class="apd-desc">' + escapeHtml(p.description) + '</div></div>';
        }

        document.getElementById('detailContent').innerHTML = html;
        detailModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeDetailModal() { detailModal.classList.remove('show'); document.body.style.overflow = ''; }
    detailModal.addEventListener('click', function (e) { if (e.target === this) closeDetailModal(); });

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function formatVnd(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ'; }

    /* MODAL KIỂM HÀNG NHANH */
    var inventoryModal = document.getElementById('inventoryModal');
    var searchInput = document.getElementById('inventorySearchInput');
    var searchResults = document.getElementById('inventorySearchResults');
    var tableBody = document.getElementById('inventoryTableBody');
    var emptyRow = document.getElementById('emptyInventoryRow');
    var selectedProductIds = new Set();
    var searchTimeout = null;

    function openInventoryModal() {
        inventoryModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        searchInput.value = '';
        searchResults.style.display = 'none';
    }
    function closeInventoryModal() {
        inventoryModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    inventoryModal.addEventListener('click', function (e) { if (e.target === this) closeInventoryModal(); });

    document.addEventListener('click', function(e) {
        if (searchResults && !searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        var q = this.value.trim();
        if (q === '') { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = '<div style="padding:15px; text-align:center; color:#8a8377;"><i class="ph ph-spinner-gap" style="animation:spin 1s linear infinite; display:inline-block;"></i> Đang tìm...</div>';
        searchResults.style.display = 'block';

        searchTimeout = setTimeout(() => {
            fetch('products.php?ajax_search_inventory=1&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.data.length > 0) {
                        let html = '';
                        res.data.forEach(p => {
                            let isAdded = selectedProductIds.has(p.id.toString());
                            let btnHtml = isAdded 
                                ? '<span style="font-size:0.75rem; color:#166534; font-weight:600;"><i class="ph ph-check"></i> Đã thêm</span>'
                                : `<button type="button" class="add-btn" onclick='addInventoryProduct(${JSON.stringify(p).replace(/'/g, "&#39;")})'>+ Thêm</button>`;
                            html += `<div class="inventory-search-item" onclick="${!isAdded ? `addInventoryProduct(${JSON.stringify(p).replace(/'/g, "&#39;")})` : ''}">
                                <img src="../${escapeHtml(p.image_url)}" alt="" onerror="this.src='https://via.placeholder.com/36x36?text=No'">
                                <div class="name">${escapeHtml(p.name)}</div>${btnHtml}</div>`;
                        });
                        searchResults.innerHTML = html;
                    } else {
                        searchResults.innerHTML = '<div style="padding:15px; text-align:center; color:#8a8377;">Không tìm thấy sản phẩm nào.</div>';
                    }
                }).catch(err => { searchResults.innerHTML = '<div style="padding:15px; text-align:center; color:#b91c1c;">Lỗi kết nối.</div>'; });
        }, 300);
    });

    function addInventoryProduct(product) {
        var pid = product.id.toString();
        if (selectedProductIds.has(pid)) return;
        selectedProductIds.add(pid);
        searchResults.style.display = 'none';
        searchInput.value = '';
        if(emptyRow) emptyRow.style.display = 'none';

        var tr = document.createElement('tr');
        tr.id = 'inv-row-' + pid;
        var html = `<td class="prod-info">
                        <img src="../${escapeHtml(product.image_url)}" alt="" onerror="this.src='https://via.placeholder.com/40x40?text=No'">
                        <div><div style="font-weight:600; font-size:0.85rem; line-height:1.2;">${escapeHtml(product.name)}</div>
                        <div style="font-size:0.7rem; color:#8a8377;">ID: #${pid}</div></div></td>`;
        var sizes = ['S','M','L','XL','2XL'];
        sizes.forEach(sz => {
            var stock = product.stocks[sz] || 0;
            html += `<td><input type="number" name="stocks[${pid}][${sz}]" value="${stock}" min="0" max="9999"></td>`;
        });
        html += `<td><button type="button" class="btn-remove-inv" onclick="removeInventoryProduct('${pid}')" title="Bỏ ra khỏi danh sách"><i class="ph ph-trash"></i></button></td>`;
        tr.innerHTML = html;
        tableBody.appendChild(tr);
    }

    function removeInventoryProduct(pid) {
        var tr = document.getElementById('inv-row-' + pid);
        if (tr) tr.remove();
        selectedProductIds.delete(pid.toString());
        if (selectedProductIds.size === 0 && emptyRow) { emptyRow.style.display = 'table-row'; }
    }

    /* MODAL LỊCH SỬ KIỂM HÀNG (DẠNG DANH SÁCH PHIẾU) */
    var historyModal = document.getElementById('historyModal');
    var historyTableBody = document.getElementById('historyTableBody');

    function openHistoryModal() {
        historyModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        historyTableBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem; color:#8a8377;"><i class="ph ph-spinner-gap" style="font-size:1.5rem; animation: spin 1s linear infinite; display:inline-block; margin-bottom:10px;"></i><br>Đang tải danh sách phiếu...</td></tr>';
        
        fetch('products.php?ajax_inventory_history=1')
            .then(r => r.json())
            .then(res => {
                if (res.ok && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(r => {
                        let date = new Date(r.created_at).toLocaleString('vi-VN');
                        html += `<tr class="history-receipt-row" onclick="toggleReceiptDetails(${r.id}, this)">
                            <td style="font-weight:700; color:#2563eb;">#${r.id}</td>
                            <td>${date}</td>
                            <td><span style="background:#eff6ff; color:#1d4ed8; padding:2px 8px; border-radius:99px; font-weight:700; font-size:0.75rem;">${r.total_items} thay đổi</span></td>
                            <td><button type="button" class="btn-adm btn-adm--ghost" style="padding:4px 10px; font-size:0.75rem;">Chi tiết <i class="ph ph-caret-down"></i></button></td>
                        </tr>
                        <tr class="history-details-row" id="details-row-${r.id}">
                            <td colspan="4" style="padding: 0;">
                                <div class="history-details-container" id="details-content-${r.id}" style="margin: 1rem;">
                                    <div style="text-align:center; color:#8a8377; padding:1rem;"><i class="ph ph-spinner-gap" style="animation:spin 1s linear infinite; display:inline-block;"></i> Đang tải chi tiết...</div>
                                </div>
                            </td>
                        </tr>`;
                    });
                    historyTableBody.innerHTML = html;
                } else {
                    historyTableBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem; color:#8a8377;">Chưa có phiếu kiểm hàng nào.</td></tr>';
                }
            })
            .catch(err => { historyTableBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:3rem; color:#b91c1c;">Lỗi tải danh sách phiếu.</td></tr>'; });
    }

    function toggleReceiptDetails(receiptId, rowEl) {
        const detailRow = document.getElementById('details-row-' + receiptId);
        const contentBox = document.getElementById('details-content-' + receiptId);
        
        if (detailRow.classList.contains('show')) {
            detailRow.classList.remove('show');
            rowEl.classList.remove('active');
            return;
        }

        // Đóng tất cả các hàng khác
        document.querySelectorAll('.history-details-row').forEach(el => el.classList.remove('show'));
        document.querySelectorAll('.history-receipt-row').forEach(el => el.classList.remove('active'));
        
        // Mở hàng hiện tại
        detailRow.classList.add('show');
        rowEl.classList.add('active');

        // Nếu đã load rồi thì không load lại
        if (contentBox.dataset.loaded === '1') return;
        
        fetch('products.php?ajax_inventory_receipt_details=1&id=' + receiptId)
            .then(r => r.json())
            .then(res => {
                if (res.ok && res.data.length > 0) {
                    // Gom nhóm dữ liệu theo product_id
                    const groupedData = {};
                    res.data.forEach(item => {
                        if (!groupedData[item.product_id]) {
                            groupedData[item.product_id] = {
                                id: item.product_id,
                                name: item.name || 'Sản phẩm đã xóa',
                                image_url: item.image_url,
                                sizes: {}
                            };
                        }
                        groupedData[item.product_id].sizes[item.size] = {
                            old: item.old_stock,
                            new: item.new_stock,
                            change: item.change_amount
                        };
                    });

                    // Render giao diện mới
                    let html = '';
                    const ALL_SIZES = ['S', 'M', 'L', 'XL', '2XL'];

                    for (const pid in groupedData) {
                        const prod = groupedData[pid];
                        html += `<div class="receipt-product-row">
                            <div class="receipt-product-info">
                                <img src="../${escapeHtml(prod.image_url)}" alt="" onerror="this.src='https://via.placeholder.com/56x56?text=No'">
                                <div class="name">${escapeHtml(prod.name)}</div>
                            </div>
                            <div class="receipt-sizes-list">`;

                        ALL_SIZES.forEach(sz => {
                            if (prod.sizes[sz]) {
                                const s = prod.sizes[sz];
                                // Chỉ hiển thị những size có thay đổi
                                html += `<div class="receipt-size-box">
                                    <div class="size-lbl">${sz}</div>
                                    <div class="qty-new">${s.new}</div>
                                    <div class="qty-old">${s.old}</div>
                                </div>`;
                            }
                        });

                        html += `</div></div>`;
                    }

                    contentBox.innerHTML = html;
                    contentBox.dataset.loaded = '1';
                } else {
                    contentBox.innerHTML = '<div style="color:#b91c1c; padding:1rem;">Không có chi tiết.</div>';
                }
            })
            .catch(err => { contentBox.innerHTML = '<div style="color:#b91c1c; padding:1rem;">Lỗi tải chi tiết.</div>'; });
    }

    function closeHistoryModal() { historyModal.classList.remove('show'); document.body.style.overflow = ''; }
    historyModal.addEventListener('click', function (e) { if (e.target === this) closeHistoryModal(); });

    /* MODAL SỬA/THÊM */
    var productModal = document.getElementById('productModal');
    function openProductModal() {
        document.getElementById('productModalTitle').textContent = 'Thêm sản phẩm mới';
        document.querySelector('#productModal form').reset();
        document.getElementById('p_id').value = '';
        document.getElementById('p_discount').value = 0;
        document.getElementById('p_cost_price').value = 0;
        document.getElementById('p_sold_count').value = 0;
        ['s','m','l','xl','2xl'].forEach(function (sz) { var el = document.getElementById('p_stock_' + sz); if (el) el.value = 20; });
        productModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function editProduct(p) {
        document.getElementById('productModalTitle').textContent = 'Sửa sản phẩm #' + p.id;
        document.getElementById('p_id').value = p.id;
        document.getElementById('p_name').value = p.name;
        document.getElementById('p_price').value = p.price;
        document.getElementById('p_cost_price').value = p.cost_price || 0;
        document.getElementById('p_discount').value = p.discount_percent || 0;
        document.getElementById('p_product_type').value = p.product_type || '';
        document.getElementById('p_supplier').value = p.supplier || '';
        document.getElementById('p_image_url').value = p.image_url;
        document.getElementById('p_description').value = p.description || '';
        document.getElementById('p_category_id').value = p.category_id || '';
        document.getElementById('p_gender').value = p.gender || 'unisex';
        document.getElementById('p_sold_count').value = p.sold_count || 0;
        ['s','m','l','xl','2xl'].forEach(function (sz) { var el = document.getElementById('p_stock_' + sz); if (el) el.value = 0; });
        fetch('get_product_stock.php?id=' + p.id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.stocks) {
                    ['s','m','l','xl','2xl'].forEach(function (sz) {
                        var el = document.getElementById('p_stock_' + sz);
                        if (el) el.value = data.stocks[sz.toUpperCase()] || 0;
                    });
                }
            }).catch(function () {});
        productModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeProductModal() { productModal.classList.remove('show'); document.body.style.overflow = ''; }
    productModal.addEventListener('click', function (e) { if (e.target === this) closeProductModal(); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (detailModal.classList.contains('show')) closeDetailModal();
            if (inventoryModal.classList.contains('show')) closeInventoryModal();
            if (historyModal.classList.contains('show')) closeHistoryModal();
            if (productModal.classList.contains('show')) closeProductModal();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('view')) {
            const viewId = parseInt(urlParams.get('view'), 10);
            if (viewId > 0) {
                const targetCard = document.querySelector('.apd-card[data-id="' + viewId + '"]');
                if (targetCard) {
                    const viewBtn = targetCard.querySelector('.apd-btn-view');
                    if (viewBtn) viewBtn.click();
                }
            }
        }
    });

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
                bulkBtn.disabled = false; bulkBtn.style.opacity = '1'; bulkBtn.style.cursor = 'pointer';
            } else {
                bulkBtn.disabled = true; bulkBtn.style.opacity = '0.55'; bulkBtn.style.cursor = 'not-allowed';
            }
            if (selectAllCb) {
                var all = cbs.length > 0 && selected.length === cbs.length;
                selectAllCb.checked = all;
                selectAllCb.indeterminate = selected.length > 0 && !all;
            }
            cbs.forEach(function (cb) {
                var card = cb.closest('.apd-card');
                if (!card) return;
                if (cb.checked) card.classList.add('is-selected'); else card.classList.remove('is-selected');
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
            if (!selected.length) { alert('Chưa chọn sản phẩm nào.'); return false; }
            if (!confirm('Tắt giảm giá riêng cho ' + selected.length + ' sản phẩm đã chọn?')) return false;
            bulkIdsWrap.innerHTML = '';
            selected.forEach(function (cb) {
                var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
                bulkIdsWrap.appendChild(inp);
            });
            return true;
        };
        refreshUI();
    })();
    </script>

<?php include 'includes/admin_footer.php'; ?>