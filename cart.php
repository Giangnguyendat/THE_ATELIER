<?php
require_once 'includes/config.php';

/* ===== Khởi tạo CSRF ===== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   ✅ HELPER: ĐỒNG BỘ GIỎ HÀNG VÀO DB
   - Nếu giỏ rỗng → xóa record trong cart_storage
   - Nếu còn sản phẩm → update lại
   ============================================================ */
function syncCartToDb(PDO $pdo): void {
    if (empty($_SESSION['cart'])) {
        if (function_exists('clearCartFromDb')) {
            clearCartFromDb($pdo);
        } else {
            // Fallback: xóa trực tiếp
            try {
                $ownerKey = function_exists('getCartOwnerKey') ? getCartOwnerKey() : null;
                if ($ownerKey) {
                    $pdo->prepare("DELETE FROM cart_storage WHERE owner_key = ?")
                        ->execute([$ownerKey]);
                }
            } catch (Exception $e) {
                error_log('syncCartToDb (clear) error: ' . $e->getMessage());
            }
        }
    } else {
        if (function_exists('saveCartToDb')) {
            saveCartToDb($pdo);
        }
    }
}

/* ===== XỬ LÝ POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['error'] = 'Phiên làm việc hết hạn, vui lòng thử lại.';
        header('Location: cart.php');
        exit;
    }

    $action   = $_POST['action']   ?? '';
    $cart_key = $_POST['cart_key'] ?? '';

    /* =====================================================
       1. THÊM VÀO GIỎ — đến từ product-details.php
       ===================================================== */
    if ($action === 'add_to_cart') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $size      = trim($_POST['size'] ?? 'M');
        $quantity  = max(1, min(99, (int)($_POST['quantity'] ?? 1)));

        if ($productId <= 0) {
            $_SESSION['error'] = 'Sản phẩm không hợp lệ.';
            header('Location: products.php');
            exit;
        }

        // Whitelist size
        if (!in_array($size, ALL_SIZES, true)) {
            $size = 'M';
        }

        // Lấy thông tin sản phẩm
        $stmt = $pdo->prepare("SELECT id, name, price, discount_percent, image_url FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $_SESSION['error'] = 'Sản phẩm không tồn tại.';
            header('Location: products.php');
            exit;
        }

        // ✅ KIỂM TRA TỒN KHO — 3 tầng
        $available = getProductStock($pdo, $productId, $size);

        if ($available <= 0) {
            $_SESSION['error'] = 'Size ' . $size . ' của "' . $product['name'] . '" đã hết hàng.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'product-details.php?id=' . $productId));
            exit;
        }

        if (empty($_SESSION['cart'])) $_SESSION['cart'] = [];
        $key    = $productId . '_' . $size;
        $inCart = isset($_SESSION['cart'][$key]) ? (int)$_SESSION['cart'][$key]['quantity'] : 0;

        if ($inCart + $quantity > $available) {
            $canAdd = max(0, $available - $inCart);
            if ($canAdd <= 0) {
                $_SESSION['error'] = 'Bạn đã có ' . $inCart . ' sản phẩm size ' . $size
                                   . ' trong giỏ. Kho chỉ còn ' . $available . ' cái.';
            } else {
                $_SESSION['error'] = 'Chỉ có thể thêm tối đa ' . $canAdd . ' sản phẩm nữa '
                                   . '(kho còn ' . $available . ' cái, giỏ đã có ' . $inCart . ').';
            }
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'product-details.php?id=' . $productId));
            exit;
        }

        // ✅ ĐÃ QUA KIỂM TRA — thêm vào giỏ
        $finalPrice = $product['discount_percent'] > 0
            ? $product['price'] * (100 - $product['discount_percent']) / 100
            : $product['price'];

        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$key] = [
                'product_id' => $productId,
                'name'       => $product['name'],
                'price'      => $finalPrice,
                'image_url'  => $product['image_url'],
                'size'       => $size,
                'quantity'   => $quantity,
            ];
        }

        // ✅ Sync DB
        syncCartToDb($pdo);

        $_SESSION['flash_message'] = [
            'type'    => 'success',
            'message' => 'Đã thêm "' . $product['name'] . '" (Size ' . $size . ') vào giỏ hàng!',
        ];

        $back = $_SERVER['HTTP_REFERER'] ?? 'product-details.php?id=' . $productId;
        header('Location: ' . $back);
        exit;
    }

    /* =====================================================
       2. UPDATE / REMOVE — từ trang cart
       ===================================================== */
    if ($action === 'update' || $action === 'remove') {

        if (isset($_SESSION['cart'][$cart_key])) {

            if ($action === 'update') {
                $quantity = (int)($_POST['quantity'] ?? 1);
                if ($quantity < 1)  $quantity = 1;
                if ($quantity > 99) $quantity = 99;
                $_SESSION['cart'][$cart_key]['quantity'] = $quantity;

            } elseif ($action === 'remove') {
                unset($_SESSION['cart'][$cart_key]);

                // Nếu cart rỗng hoàn toàn → xóa hẳn key 'cart' khỏi session
                if (empty($_SESSION['cart'])) {
                    unset($_SESSION['cart']);
                }
            }

            // ✅ Sync DB sau khi thay đổi
            syncCartToDb($pdo);
        }

        header('Location: cart.php');
        exit;
    }

    /* =====================================================
       3. PROCEED CHECKOUT
       ===================================================== */
    if ($action === 'proceed_checkout') {
        $selected_items = $_POST['selected_items'] ?? [];
        if (empty($selected_items)) {
            $_SESSION['error'] = 'Vui lòng chọn ít nhất một sản phẩm để thanh toán!';
            header('Location: cart.php');
            exit;
        }
        $_SESSION['selected_cart_keys'] = $selected_items;
        header('Location: checkout.php');
        exit;
    }

    header('Location: cart.php');
    exit;
}

$cart        = $_SESSION['cart'] ?? [];
$total_items = count($cart);

// Flash message
$flash = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);
?>
<?php include 'includes/header.php'; ?>

<main class="cart-page-wrapper">
    <div class="cart-container">

        <header class="cart-header">
            <h1 class="cart-title">Giỏ Hàng Của Bạn</h1>
            <p class="cart-subtitle">
                <?php echo $total_items > 0
                    ? "Bạn đang có {$total_items} loại sản phẩm trong giỏ hàng"
                    : "Giỏ hàng hiện đang trống"; ?>
            </p>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : 'success'; ?>"
                 style="padding:12px 16px;border-radius:8px;margin-bottom:20px;
                        background:<?php echo $flash['type'] === 'error' ? '#fee2e2' : '#ecfdf5'; ?>;
                        color:<?php echo $flash['type'] === 'error' ? '#b91c1c' : '#047857'; ?>;">
                <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger" style="color:#ef4444;background:#fee2e2;padding:12px;border-radius:8px;margin-bottom:20px;">
                <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($cart)): ?>
            <div class="cart-empty-state">
                <div class="empty-icon">&#128722;</div>
                <h2>Chưa có sản phẩm nào</h2>
                <p>Khám phá các sản phẩm tuyệt vời của chúng tôi và thêm vào giỏ hàng nhé!</p>
                <a href="products.php" class="btn-continue-shopping">Tiếp tục mua sắm</a>
            </div>
        <?php else: ?>
            <form method="post" id="cartCheckoutForm" class="cart-layout">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="proceed_checkout">

                <div class="cart-items-section">
                    <div class="cart-table-wrapper">
                        <table class="cart-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;text-align:center;">
                                        <input type="checkbox" id="selectAllCheckbox" checked style="width:18px;height:18px;cursor:pointer;">
                                    </th>
                                    <th>Sản phẩm</th>
                                    <th>Đơn giá</th>
                                    <th>Số lượng</th>
                                    <th>Thành tiền</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cart as $key => $item):
                                    $p_id    = $item['product_id'] ?? 0;
                                    $p_name  = $item['name']       ?? 'Sản phẩm';
                                    $p_price = $item['price']      ?? 0;
                                    $p_qty   = $item['quantity']   ?? 1;
                                    $p_size  = $item['size']       ?? 'M';
                                    $p_img   = !empty($item['image_url']) ? $item['image_url'] : 'images/default-product.jpg';
                                    $p_desc  = $item['description'] ?? 'Sản phẩm cao cấp mang phong cách hiện đại, thoải mái và tinh tế.';
                                    $subtotal = $p_price * $p_qty;
                                ?>
                                <tr class="cart-item-row" data-price="<?php echo $p_price; ?>" data-key="<?php echo $key; ?>">
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="selected_items[]" value="<?php echo $key; ?>"
                                               class="cart-item-checkbox" checked style="width:18px;height:18px;cursor:pointer;">
                                    </td>
                                    <td class="col-product">
                                        <div class="product-info-cell">
                                            <a href="javascript:void(0)"
                                               class="cart-item-preview-trigger"
                                               data-key="<?php echo $key; ?>"
                                               data-id="<?php echo (int)$p_id; ?>"
                                               data-name="<?php echo sanitize($p_name); ?>"
                                               data-price="<?php echo number_format($p_price, 0, ',', '.'); ?>đ"
                                               data-image="<?php echo sanitize($p_img); ?>"
                                               data-size="<?php echo sanitize($p_size); ?>"
                                               data-qty="<?php echo (int)$p_qty; ?>"
                                               data-desc="<?php echo sanitize($p_desc); ?>">
                                                <img src="<?php echo sanitize($p_img); ?>" alt="<?php echo sanitize($p_name); ?>" class="cart-product-img">
                                            </a>
                                            <div class="product-details">
                                                <a href="javascript:void(0)"
                                                   class="product-name cart-item-preview-trigger"
                                                   data-key="<?php echo $key; ?>"
                                                   data-id="<?php echo (int)$p_id; ?>"
                                                   data-name="<?php echo sanitize($p_name); ?>"
                                                   data-price="<?php echo number_format($p_price, 0, ',', '.'); ?>đ"
                                                   data-image="<?php echo sanitize($p_img); ?>"
                                                   data-size="<?php echo sanitize($p_size); ?>"
                                                   data-qty="<?php echo (int)$p_qty; ?>"
                                                   data-desc="<?php echo sanitize($p_desc); ?>">
                                                    <?php echo sanitize($p_name); ?>
                                                </a>
                                                <span class="product-size" style="display:block;font-size:.85rem;color:#666;margin-top:4px;">
                                                    Kích thước: <strong><?php echo sanitize($p_size); ?></strong>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="col-price"><?php echo number_format($p_price, 0, ',', '.'); ?>đ</td>
                                    <td class="col-quantity">
                                        <div class="quantity-control">
                                            <button type="button" class="btn-qty btn-minus" data-key="<?php echo $key; ?>">&minus;</button>
                                            <input type="number" name="quantity_input" value="<?php echo (int)$p_qty; ?>" min="1"
                                                   class="qty-input" data-key="<?php echo $key; ?>">
                                            <button type="button" class="btn-qty btn-plus" data-key="<?php echo $key; ?>">&plus;</button>
                                        </div>
                                    </td>
                                    <td class="col-subtotal">
                                        <strong class="item-subtotal-text"><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</strong>
                                    </td>
                                    <td class="col-action">
                                        <button type="button" class="btn-remove btn-remove-item" data-key="<?php echo $key; ?>" title="Xóa sản phẩm">&times;</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cart-actions-bar">
                        <a href="products.php" class="link-back">&larr; Tiếp tục xem sản phẩm</a>
                    </div>
                </div>

                <aside class="cart-summary-section">
                    <div class="summary-card">
                        <h2 class="summary-title">Tóm Tắt Đơn Hàng</h2>

                        <div class="summary-row">
                            <span>Đã chọn</span>
                            <span id="selectedCountText">0 sản phẩm</span>
                        </div>
                        <div class="summary-row">
                            <span>Tạm tính</span>
                            <span id="selectedSubtotalText">0đ</span>
                        </div>
                        <div class="summary-row">
                            <span>Phí vận chuyển</span>
                            <span class="free-shipping">Tính khi thanh toán</span>
                        </div>
                        <div class="summary-divider"></div>
                        <div class="summary-row total-row">
                            <span>Tổng tiền</span>
                            <span class="total-amount" id="selectedTotalText">0đ</span>
                        </div>

                        <button type="submit" class="btn-checkout" id="btnCheckoutSubmit">Tiến Hành Thanh Toán</button>
                    </div>
                </aside>
            </form>
        <?php endif; ?>
    </div>
</main>

<!-- FORM ẨN UPDATE / REMOVE -->
<form id="singleActionForm" method="post" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action"     id="singleAction"   value="">
    <input type="hidden" name="cart_key"   id="singleCartKey"  value="">
    <input type="hidden" name="quantity"   id="singleQuantity" value="1">
</form>

<!-- MODAL CHI TIẾT -->
<div class="modal-overlay" id="cartProductModal">
    <div class="modal-content">
        <button type="button" class="modal-close" id="closeCartModalBtn" aria-label="Đóng">&times;</button>
        <div class="modal-body">
            <div class="modal-product-image">
                <img id="modalCartProductImg" src="" alt="Hình sản phẩm">
            </div>
            <div class="modal-product-details">
                <span class="modal-category" style="color:#6b7280;font-size:.85rem;font-weight:600;text-transform:uppercase;">Chi tiết giỏ hàng</span>
                <h2 class="modal-title" id="modalCartProductName" style="margin:8px 0 12px;font-size:1.5rem;color:#111827;">Tên Sản Phẩm</h2>
                <div class="modal-price-box" style="margin-bottom:16px;">
                    <span class="modal-price" id="modalCartProductPrice" style="font-size:1.35rem;font-weight:700;color:#ef4444;">0đ</span>
                </div>
                <p class="modal-description" id="modalCartProductDesc" style="color:#4b5563;line-height:1.6;margin-bottom:20px;font-size:.95rem;">Mô tả sản phẩm...</p>
                <div class="modal-info-group" style="margin-bottom:15px;background:#f9fafb;padding:12px 16px;border-radius:8px;border:1px solid #e5e7eb;">
                    <p style="margin:0 0 6px;color:#374151;">Size đã chọn: <strong id="modalCartProductSize" style="color:#111827;">M</strong></p>
                    <p style="margin:0;color:#374151;">Số lượng trong giỏ: <strong id="modalCartProductQty" style="color:#111827;">1</strong></p>
                </div>
                <div style="display:flex;gap:12px;margin-top:20px;">
                    <button type="button" id="modalDeleteBtn" class="btn-remove-from-cart"
                            style="flex:1;background:#ef4444;color:#fff;border:none;padding:14px 20px;border-radius:8px;font-weight:600;cursor:pointer;">
                        &#128465; Xóa khỏi giỏ hàng
                    </button>
                    <a id="modalDetailLink" href="#" class="btn-view-full"
                       style="display:inline-flex;align-items:center;justify-content:center;background:#f3f4f6;color:#374151;text-decoration:none;padding:14px 20px;border-radius:8px;font-weight:600;">
                        Xem trang chi tiết
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const itemCheckboxes     = document.querySelectorAll('.cart-item-checkbox');
    const selectAllCheckbox  = document.getElementById('selectAllCheckbox');
    const selectedCountText  = document.getElementById('selectedCountText');
    const selectedSubtotalText = document.getElementById('selectedSubtotalText');
    const selectedTotalText  = document.getElementById('selectedTotalText');
    const btnCheckoutSubmit  = document.getElementById('btnCheckoutSubmit');

    function formatCurrency(amount) {
        return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
    }

    function updateCartTotals() {
        let totalAmount = 0, totalItemsCount = 0, checkedCount = 0;

        itemCheckboxes.forEach(cb => {
            if (cb.checked) {
                checkedCount++;
                const row = cb.closest('.cart-item-row');
                const price = parseFloat(row.dataset.price) || 0;
                const qty = parseInt(row.querySelector('.qty-input').value) || 1;
                totalAmount += price * qty;
                totalItemsCount += qty;
            }
        });

        if (selectedCountText)    selectedCountText.textContent    = totalItemsCount + ' sản phẩm';
        if (selectedSubtotalText) selectedSubtotalText.textContent = formatCurrency(totalAmount);
        if (selectedTotalText)    selectedTotalText.textContent    = formatCurrency(totalAmount);

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = (checkedCount === itemCheckboxes.length && itemCheckboxes.length > 0);
        }

        if (btnCheckoutSubmit) {
            const disabled = checkedCount === 0;
            btnCheckoutSubmit.disabled = disabled;
            btnCheckoutSubmit.style.opacity = disabled ? '0.5' : '1';
            btnCheckoutSubmit.style.cursor  = disabled ? 'not-allowed' : 'pointer';
        }
    }

    selectAllCheckbox?.addEventListener('change', e => {
        itemCheckboxes.forEach(cb => cb.checked = e.target.checked);
        updateCartTotals();
    });
    itemCheckboxes.forEach(cb => cb.addEventListener('change', updateCartTotals));

    function submitSingleAction(action, cartKey, quantity = 1) {
        document.getElementById('singleAction').value   = action;
        document.getElementById('singleCartKey').value  = cartKey;
        document.getElementById('singleQuantity').value = quantity;
        document.getElementById('singleActionForm').submit();
    }

    // Tăng / giảm
    document.querySelectorAll('.quantity-control').forEach(control => {
        const input = control.querySelector('.qty-input');
        const key = input.dataset.key;

        control.querySelector('.btn-minus')?.addEventListener('click', () => {
            let val = parseInt(input.value) || 1;
            if (val > 1) submitSingleAction('update', key, val - 1);
        });
        control.querySelector('.btn-plus')?.addEventListener('click', () => {
            let val = parseInt(input.value) || 1;
            submitSingleAction('update', key, val + 1);
        });
        input?.addEventListener('change', () => {
            let val = parseInt(input.value) || 1;
            if (val < 1) val = 1;
            if (val > 99) val = 99;
            submitSingleAction('update', key, val);
        });
    });

    // Xóa
    document.querySelectorAll('.btn-remove-item').forEach(btn => {
        btn.addEventListener('click', () => {
            if (confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?')) {
                submitSingleAction('remove', btn.dataset.key);
            }
        });
    });

    // Modal
    const modal = document.getElementById('cartProductModal');
    const closeModalBtn = document.getElementById('closeCartModalBtn');
    const triggers = document.querySelectorAll('.cart-item-preview-trigger');
    let currentModalKey = null;

    triggers.forEach(trigger => {
        trigger.addEventListener('click', e => {
            e.preventDefault();
            const data = trigger.dataset;
            currentModalKey = data.key;
            document.getElementById('modalCartProductName').textContent  = data.name;
            document.getElementById('modalCartProductPrice').textContent = data.price;
            document.getElementById('modalCartProductImg').src           = data.image;
            document.getElementById('modalCartProductSize').textContent  = data.size;
            document.getElementById('modalCartProductQty').textContent   = data.qty;
            document.getElementById('modalCartProductDesc').textContent  = data.desc;

            document.getElementById('modalDetailLink').href = 'product-details.php?id=' + data.id;

            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    });

    document.getElementById('modalDeleteBtn')?.addEventListener('click', () => {
        if (currentModalKey && confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?')) {
            submitSingleAction('remove', currentModalKey);
        }
    });

    function closeModal() {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    closeModalBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    updateCartTotals();
});
</script>

<?php include 'includes/footer.php'; ?>