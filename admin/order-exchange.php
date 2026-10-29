<?php
require_once '../includes/config.php';

$action = $_GET['action'] ?? '';

/* ============================================================
   AJAX: tìm sản phẩm
   ============================================================ */
if ($action === 'search_products') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'products'=>[]]); exit; }
    try {
        $like = '%' . mb_strtolower($q) . '%';
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.image_url, p.price, p.discount_percent,
                   CASE WHEN p.discount_percent > 0
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100)
                        ELSE p.price END AS final_price
            FROM products p
            WHERE LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ?
            ORDER BY p.sold_count DESC, p.id DESC
            LIMIT 20
        ");
        $stmt->execute([$like, $like]);
        echo json_encode(['ok'=>true, 'products'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ============================================================
   AJAX: lấy size còn hàng
   ============================================================ */
if ($action === 'get_product_sizes') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT size, stock FROM product_sizes
            WHERE product_id = ? AND stock > 0
            ORDER BY FIELD(size, 'S','M','L','XL','2XL')
        ");
        $stmt->execute([$pid]);
        echo json_encode(['ok'=>true, 'sizes'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ============================================================
   POST: tạo đơn đổi trả
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_exchange') {
    $originalOrderId = (int)($_POST['original_order_id'] ?? 0);
    $exchangeItems   = $_POST['items'] ?? [];
    $note            = trim($_POST['note'] ?? '');
    $paymentMethod   = $_POST['payment_method'] ?? 'cod';

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$originalOrderId]);
    $originalOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$originalOrder)                                die('Không tìm thấy đơn gốc');
    if ($originalOrder['order_type'] === 'exchange')    die('Không thể tạo đơn đổi từ đơn đổi');
    if ($originalOrder['status'] === 'cancelled')       die('Đơn đã hủy, không thể đổi');

    $validItems = []; $oldTotal = 0; $newTotal = 0;

    foreach ($exchangeItems as $ei) {
        $oldItemId    = (int)($ei['original_item_id'] ?? 0);
        $newProductId = (int)($ei['new_product_id'] ?? 0);
        $newSize      = trim($ei['new_size'] ?? '');
        $qty          = max(1, (int)($ei['quantity'] ?? 1));

        // Lấy item cũ — JOIN products để có name
        $stmt = $pdo->prepare("
            SELECT oi.*, p.name AS p_name, p.image_url AS p_img
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.id = ? AND oi.order_id = ?
        ");
        $stmt->execute([$oldItemId, $originalOrderId]);
        $oldItem = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldItem) continue;
        if ($qty > (int)$oldItem['quantity']) $qty = (int)$oldItem['quantity'];

        // Lấy sản phẩm mới
        $stmt = $pdo->prepare("
            SELECT p.*, CASE WHEN p.discount_percent > 0
                THEN ROUND(p.price * (100 - p.discount_percent) / 100)
                ELSE p.price END AS final_price
            FROM products p WHERE p.id = ?
        ");
        $stmt->execute([$newProductId]);
        $newProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$newProduct) continue;

        // Kiểm tra tồn kho
        $stmt = $pdo->prepare("SELECT stock FROM product_sizes WHERE product_id = ? AND size = ?");
        $stmt->execute([$newProductId, $newSize]);
        $szRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$szRow || (int)$szRow['stock'] < $qty) continue;

        $oldPrice = (float)$oldItem['price'] * $qty;
        $newPrice = (float)$newProduct['final_price'] * $qty;
        $oldTotal += $oldPrice; $newTotal += $newPrice;

        $validItems[] = compact('oldItem', 'newProduct', 'newSize', 'qty', 'oldPrice', 'newPrice');
    }

    if (empty($validItems)) die('Không có sản phẩm hợp lệ để đổi');

    $priceDifference = $newTotal - $oldTotal;
    if      ($priceDifference > 0) $paymentStatus = 'unpaid';
    elseif  ($priceDifference < 0) $paymentStatus = 'refund_pending';
    else                           $paymentStatus = 'paid';

    $pdo->beginTransaction();
    try {
        // Tạo đơn đổi — total_amount = giá trị SP mới, price_difference = chênh lệch
        $stmt = $pdo->prepare("
            INSERT INTO orders
              (user_id, full_name, phone, address, note,
               payment_method, payment_status,
               status, order_type, parent_order_id, price_difference,
               total_amount, discount_amount, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'exchange', ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
    $originalOrder['user_id'], $originalOrder['full_name'],
    $originalOrder['phone'],   $originalOrder['address'],
    "Đổi trả từ đơn #$originalOrderId" . ($note ? " — $note" : ''),
    $paymentMethod, $paymentStatus,
    $originalOrderId, $priceDifference,
    abs($priceDifference),   // ← chỉ tính phần chênh lệch
]);
        $newOrderId = (int)$pdo->lastInsertId();

        foreach ($validItems as $vi) {
            // order_items KHÔNG có name/image_url — chỉ lưu product_id
            $stmt = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, size, variant_id, quantity, price)
                VALUES (?, ?, ?, NULL, ?, ?)
            ");
            $stmt->execute([
                $newOrderId, $vi['newProduct']['id'], $vi['newSize'],
                $vi['qty'], $vi['newPrice'] / $vi['qty'],
            ]);
            $newItemId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO order_exchanges
                  (exchange_order_id, original_order_item_id, new_order_item_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$newOrderId, $vi['oldItem']['id'], $newItemId]);

            // Trừ tồn kho size
            $stmt = $pdo->prepare("UPDATE product_sizes SET stock = stock - ? WHERE product_id = ? AND size = ?");
            $stmt->execute([$vi['qty'], $vi['newProduct']['id'], $vi['newSize']]);
        }

        $pdo->commit();
        header("Location: order-detail.php?id=$newOrderId&created=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Lỗi: ' . $e->getMessage());
    }
}

/* ============================================================
   RENDER TRANG
   ============================================================ */
include 'includes/admin_header.php';

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { echo '<p>Thiếu ID đơn hàng</p>'; include 'includes/admin_footer.php'; exit; }

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order)                              { echo '<p>Không tìm thấy đơn hàng</p>'; include 'includes/admin_footer.php'; exit; }
if ($order['order_type'] === 'exchange')  { echo '<p>Không thể tạo đơn đổi từ đơn đổi trả.</p>'; include 'includes/admin_footer.php'; exit; }
if ($order['status'] === 'cancelled')     { echo '<p>Đơn đã hủy, không thể đổi trả.</p>'; include 'includes/admin_footer.php'; exit; }
if ($order['status'] !== 'delivered') {
    echo '<div style="max-width:520px;margin:3rem auto;padding:2rem;background:#fff;border:1px solid #fed7aa;border-radius:12px;text-align:center;font-family:sans-serif;">'
       . '<div style="font-size:2rem;color:#f59e0b;margin-bottom:.75rem;"><i class="ph ph-warning-circle"></i></div>'
       . '<h2 style="margin:0 0 .5rem;color:#92400e;font-size:1.15rem;">Chưa thể đổi trả</h2>'
       . '<p style="margin:0;color:#78716c;line-height:1.6;">Đơn <b>#' . (int)$order['id'] . '</b> đang ở trạng thái <b>' . htmlspecialchars($order['status']) . '</b>.<br>'
       . 'Chỉ có thể tạo đơn đổi trả khi đơn đã <b>giao thành công</b>.</p>'
       . '<a href="order-detail.php?id=' . (int)$order['id'] . '" style="display:inline-flex;align-items:center;gap:6px;margin-top:1.25rem;padding:.6rem 1.1rem;background:#a8461f;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:.88rem;">'
       . '<i class="ph ph-arrow-left"></i> Quay lại đơn hàng</a>'
       . '</div>';
    include 'includes/admin_footer.php';
    exit;
}
// JOIN products để có name + image_url (vì order_items không có)
$stmt = $pdo->prepare("
    SELECT oi.id, oi.product_id, oi.size, oi.quantity, oi.price,
           p.name AS product_name, p.image_url AS product_img
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT id, status, price_difference, payment_status, created_at
    FROM orders WHERE parent_order_id = ? AND order_type = 'exchange'
    ORDER BY created_at DESC
");
$stmt->execute([$orderId]);
$existingExchanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .ex-page { max-width: 1080px; margin: 0 auto; padding: 1rem 0 3rem; }
    .ex-head h1 { font-family: 'Playfair Display', Georgia, serif; font-size: 1.8rem; margin: 0 0 .35rem; }
    .ex-head .sub { color: #8a8377; font-size: .88rem; }
    .ex-card { background:#fff; border:1px solid #efeae0; border-radius:12px; padding:1.25rem 1.5rem; margin-bottom:1.25rem; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .ex-card-title { font-size:.82rem; text-transform:uppercase; letter-spacing:.08em; color:#8a8377; font-weight:700; margin-bottom:1rem; }
    .ex-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; }
    .ex-info-item .lbl { font-size:.72rem; color:#8a8377; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px; }
    .ex-info-item .val { font-weight:600; font-family:'IBM Plex Mono',monospace; }

    .ex-table { width:100%; border-collapse:collapse; font-size:.88rem; }
    .ex-table thead th { text-align:left; padding:.7rem .75rem; background:#fbf9f5; border-bottom:1px solid #efeae0; font-size:.72rem; text-transform:uppercase; color:#8a8377; font-weight:700; }
    .ex-table tbody td { padding:.85rem .75rem; border-bottom:1px solid #efeae0; vertical-align:top; }
    .ex-table tbody tr:last-child td { border-bottom:none; }
    .ex-row--checked { background:#fef9f3; }
    .ex-sub-row td { padding:1rem 1.5rem !important; background:#faf8f4; border-top:1px dashed #ddd5c7; }
    .ex-img { width:50px; height:50px; object-fit:cover; border-radius:6px; background:#f5f2ec; }

    .ex-options { display:flex; flex-direction:column; gap:.75rem; }
    .ex-opt-group { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; }
    .ex-opt-group label { display:inline-flex; align-items:center; gap:.35rem; cursor:pointer; font-size:.88rem; }
    .ex-select, .ex-input { padding:.45rem .7rem; border:1px solid #ddd5c7; border-radius:6px; font-family:inherit; font-size:.85rem; background:#fff; }
    .ex-select:focus, .ex-input:focus { outline:none; border-color:#a8461f; box-shadow:0 0 0 3px rgba(168,70,31,.1); }
    .btn-pick { padding:.5rem 1rem; background:#fff; border:1px solid #ddd5c7; border-radius:6px; cursor:pointer; font-family:inherit; font-size:.85rem; font-weight:600; }
    .btn-pick:hover { background:#fbf9f5; }
    .selected-prod { display:flex; align-items:center; gap:.75rem; padding:.6rem; background:#fff; border:1px solid #efeae0; border-radius:8px; margin-top:.5rem; }
    .selected-prod img { width:44px; height:44px; object-fit:cover; border-radius:6px; }
    .selected-prod:empty { display:none; }

    .ex-summary { background:#faf8f4; border:1px solid #efeae0; border-radius:10px; padding:1rem 1.25rem; margin-top:1rem; }
    .ex-summary-row { display:flex; justify-content:space-between; padding:.35rem 0; font-size:.9rem; }
    .ex-summary-total { border-top:2px solid #efeae0; padding-top:.75rem; margin-top:.5rem; font-weight:700; font-size:1.05rem; }
    .diff-pos { color:#15803d; }
    .diff-neg { color:#b91c1c; }
    .ex-actions { display:flex; gap:.75rem; justify-content:flex-end; margin-top:1.5rem; }
    .ex-actions .btn-adm { padding:.65rem 1.25rem; }

    .ps-modal { position:fixed; inset:0; background:rgba(36,33,29,.6); display:none; align-items:center; justify-content:center; z-index:9999; padding:1rem; }
    .ps-modal.show { display:flex; }
    .ps-box { background:#fff; border-radius:12px; width:100%; max-width:720px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; }
    .ps-head { padding:1rem 1.25rem; border-bottom:1px solid #efeae0; }
    .ps-head h3 { margin:0; font-size:1.1rem; }
    .ps-input { width:100%; padding:.65rem .85rem; border:1px solid #ddd5c7; border-radius:8px; font-family:inherit; font-size:.9rem; margin-top:.75rem; }
    .ps-results { overflow-y:auto; padding:.5rem; }
    .ps-item { display:flex; align-items:center; gap:.75rem; padding:.65rem; border-radius:8px; cursor:pointer; }
    .ps-item:hover { background:#faf8f4; }
    .ps-item img { width:50px; height:50px; object-fit:cover; border-radius:6px; background:#f5f2ec; }
    .ps-item .name { font-weight:600; font-size:.88rem; }
    .ps-item .price { font-family:'IBM Plex Mono',monospace; color:#a8461f; font-size:.82rem; margin-top:2px; }
    .ps-empty { text-align:center; padding:2rem; color:#8a8377; }
</style>

<div class="ex-page">
    <div class="ex-head">
        <h1>Tạo đơn đổi trả</h1>
        <div class="sub">Đơn gốc: <b>#<?= (int)$order['id'] ?></b> — <?= htmlspecialchars($order['full_name']) ?></div>
    </div>

    <?php if (!empty($existingExchanges)): ?>
    <div class="ex-card" style="border-color:#fecaca;background:#fef2f2;">
        <div class="ex-card-title" style="color:#991b1b;">Đơn này đã có đơn đổi trả</div>
        <?php foreach ($existingExchanges as $ex): ?>
            <div style="font-size:.88rem;margin-bottom:.35rem;">
                <a href="order-detail.php?id=<?= (int)$ex['id'] ?>" target="_blank" style="color:#b91c1c;font-weight:600;">
                    #<?= (int)$ex['id'] ?>
                </a>
                — <?= htmlspecialchars($ex['status']) ?>
                — chênh lệch: <b><?= ((float)$ex['price_difference'] > 0 ? '+' : '') ?><?= number_format((float)$ex['price_difference'],0,',','.') ?>đ</b>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="ex-card">
        <div class="ex-card-title">Thông tin đơn gốc</div>
        <div class="ex-info-grid">
            <div class="ex-info-item"><div class="lbl">Khách hàng</div><div class="val"><?= htmlspecialchars($order['full_name']) ?></div></div>
            <div class="ex-info-item"><div class="lbl">SĐT</div><div class="val"><?= htmlspecialchars($order['phone']) ?></div></div>
            <div class="ex-info-item"><div class="lbl">Tổng đơn gốc</div><div class="val"><?= number_format((float)$order['total_amount'],0,',','.') ?>đ</div></div>
            <div class="ex-info-item"><div class="lbl">Trạng thái</div><div class="val"><?= htmlspecialchars($order['status']) ?></div></div>
        </div>
    </div>

    <form method="POST" id="exForm">
        <input type="hidden" name="action" value="create_exchange">
        <input type="hidden" name="original_order_id" value="<?= (int)$order['id'] ?>">

        <div class="ex-card">
            <div class="ex-card-title">Chọn sản phẩm muốn đổi</div>
            <table class="ex-table">
                <thead>
                    <tr>
                        <th style="width:40px;"></th>
                        <th style="width:60px;"></th>
                        <th>Sản phẩm</th>
                        <th style="width:80px;">Size</th>
                        <th style="width:60px;">SL</th>
                        <th style="width:110px;">Giá</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr class="ex-row" data-idx="<?= $idx ?>">
                        <td><input type="checkbox" class="item-check"
                                   data-idx="<?= $idx ?>"
                                   data-item-id="<?= (int)$it['id'] ?>"
                                   data-product-id="<?= (int)$it['product_id'] ?>"
                                   data-size="<?= htmlspecialchars($it['size']) ?>"
                                   data-qty="<?= (int)$it['quantity'] ?>"
                                   data-price="<?= (float)$it['price'] ?>"></td>
                        <td>
                            <?php if (!empty($it['product_img'])): ?>
                                <img src="../<?= htmlspecialchars($it['product_img']) ?>" class="ex-img" onerror="this.style.display='none'">
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($it['product_name'] ?? '(SP đã xóa)') ?></div>
                            <div style="font-size:.78rem;color:#8a8377;margin-top:2px;">Đơn giá: <?= number_format((float)$it['price'],0,',','.') ?>đ</div>
                        </td>
                        <td><?= htmlspecialchars($it['size']) ?></td>
                        <td>x<?= (int)$it['quantity'] ?></td>
                        <td style="font-family:'IBM Plex Mono',monospace;"><?= number_format((float)$it['price'] * (int)$it['quantity'],0,',','.') ?>đ</td>
                    </tr>
                    <tr class="ex-sub-row" data-sub-idx="<?= $idx ?>" style="display:none;">
                        <td colspan="6">
                            <div class="ex-options">
                                <div class="ex-opt-group">
                                    <label><input type="radio" name="opt_<?= $idx ?>" value="size" checked> Đổi size</label>
                                    <label><input type="radio" name="opt_<?= $idx ?>" value="product"> Đổi sản phẩm khác</label>
                                </div>

                                <div class="product-picker-<?= $idx ?>" style="display:none;">
                                    <button type="button" class="btn-pick" onclick="openProductSearch(<?= $idx ?>)">Chọn sản phẩm khác</button>
                                    <div class="selected-prod selected-prod-<?= $idx ?>"></div>
                                    <input type="hidden" name="items[<?= $idx ?>][new_product_id]"
                                           class="new-product-id-<?= $idx ?>" value="<?= (int)$it['product_id'] ?>">
                                </div>

                                <div class="ex-opt-group">
                                    <label style="font-size:.85rem;">Size:
                                        <select name="items[<?= $idx ?>][new_size]"
                                                class="ex-select size-select-<?= $idx ?>">
                                            <option value="">-- Đang tải --</option>
                                        </select>
                                    </label>
                                    <label style="font-size:.85rem;">Số lượng đổi:
                                        <input type="number" name="items[<?= $idx ?>][quantity]"
                                               class="ex-input qty-input-<?= $idx ?>"
                                               value="<?= (int)$it['quantity'] ?>"
                                               min="1" max="<?= (int)$it['quantity'] ?>" style="width:80px;">
                                    </label>
                                    <input type="hidden" name="items[<?= $idx ?>][original_item_id]" value="<?= (int)$it['id'] ?>">
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ex-card">
            <div class="ex-card-title">Thông tin bổ sung</div>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;color:#8a8377;margin-bottom:.35rem;">Phương thức thanh toán (cho phần chênh lệch)</label>
                <select name="payment_method" class="ex-select" style="width:100%;max-width:300px;">
                    <option value="cod">COD</option>
                    <option value="bank">Chuyển khoản</option>
                    <option value="card">Thẻ</option>
                    <option value="wallet">Atelier Pay</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.82rem;color:#8a8377;margin-bottom:.35rem;">Ghi chú</label>
                <textarea name="note" class="ex-input" rows="2" style="width:100%;max-width:600px;"
                          placeholder="VD: Khách muốn đổi size L vì mặc chật..."></textarea>
            </div>
        </div>

        <div class="ex-summary" id="exSummary" style="display:none;">
            <div class="ex-summary-row"><span>Giá trị sản phẩm cũ:</span><b id="oldTotal">0đ</b></div>
            <div class="ex-summary-row"><span>Giá trị sản phẩm mới:</span><b id="newTotal">0đ</b></div>
            <div class="ex-summary-row ex-summary-total"><span>Chênh lệch:</span><b id="diffTotal">0đ</b></div>
            <div style="font-size:.82rem;color:#8a8377;margin-top:.5rem;" id="diffNote"></div>
        </div>

        <div class="ex-actions">
            <a href="orders.php" class="btn-adm btn-adm--ghost">Hủy</a>
            <button type="submit" class="btn-adm btn-adm--primary" id="submitBtn" disabled>Tạo đơn đổi trả</button>
        </div>
    </form>
</div>

<!-- Modal chọn sản phẩm -->
<div class="ps-modal" id="psModal">
    <div class="ps-box">
        <div class="ps-head">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3>Chọn sản phẩm đổi</h3>
                <button type="button" onclick="closeProductSearch()"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;">&times;</button>
            </div>
            <input type="text" class="ps-input" id="psInput" placeholder="Tìm theo tên sản phẩm...">
        </div>
        <div class="ps-results" id="psResults">
            <div class="ps-empty">Nhập từ khóa để tìm sản phẩm</div>
        </div>
    </div>
</div>

<script>
var currentSearchIdx = null;
var searchTimeout   = null;
var selectedProducts = {};

document.querySelectorAll('[data-sub-idx] input, [data-sub-idx] select').forEach(function (el) {
    el.disabled = true;
});

document.querySelectorAll('.item-check').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var idx = this.dataset.idx;
        var subRow = document.querySelector('[data-sub-idx="' + idx + '"]');
        var mainRow = document.querySelector('.ex-row[data-idx="' + idx + '"]');
        if (this.checked) {
            subRow.style.display = '';
            mainRow.classList.add('ex-row--checked');
            subRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = false; });
            loadSizes(idx, this.dataset.productId);
        } else {
            subRow.style.display = 'none';
            mainRow.classList.remove('ex-row--checked');
            subRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = true; });
            delete selectedProducts[idx];
        }
        updateSummary();
    });
});

document.querySelectorAll('[data-sub-idx] input[type="radio"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var subRow = this.closest('[data-sub-idx]');
        var idx = subRow.dataset.subIdx;
        var picker = subRow.querySelector('.product-picker-' + idx);
        var newPid = subRow.querySelector('.new-product-id-' + idx);
        var origPid = document.querySelector('.item-check[data-idx="' + idx + '"]').dataset.productId;
        if (this.value === 'size') {
            picker.style.display = 'none';
            newPid.value = origPid;
            delete selectedProducts[idx];
            loadSizes(idx, origPid);
        } else {
            picker.style.display = '';
            subRow.querySelector('.size-select-' + idx).innerHTML = '<option value="">-- Chọn sản phẩm trước --</option>';
        }
        updateSummary();
    });
});

function loadSizes(idx, productId) {
    var sel = document.querySelector('.size-select-' + idx);
    sel.innerHTML = '<option value="">Đang tải...</option>';
    fetch('order-exchange.php?action=get_product_sizes&product_id=' + productId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok || !data.sizes || data.sizes.length === 0) {
                sel.innerHTML = '<option value="">Hết hàng</option>';
                return;
            }
            var html = '';
            data.sizes.forEach(function (s) {
                html += '<option value="' + s.size + '">' + s.size + ' (còn ' + s.stock + ')</option>';
            });
            sel.innerHTML = html;
        })
        .catch(function () { sel.innerHTML = '<option value="">Lỗi tải size</option>'; });
}

function openProductSearch(idx) {
    currentSearchIdx = idx;
    document.getElementById('psModal').classList.add('show');
    document.getElementById('psInput').value = '';
    document.getElementById('psInput').focus();
    document.getElementById('psResults').innerHTML = '<div class="ps-empty">Nhập từ khóa để tìm sản phẩm</div>';
}
function closeProductSearch() {
    document.getElementById('psModal').classList.remove('show');
    currentSearchIdx = null;
}

document.getElementById('psInput').addEventListener('input', function () {
    var q = this.value.trim();
    clearTimeout(searchTimeout);
    if (q.length < 2) {
        document.getElementById('psResults').innerHTML = '<div class="ps-empty">Nhập ít nhất 2 ký tự</div>';
        return;
    }
    searchTimeout = setTimeout(function () {
        fetch('order-exchange.php?action=search_products&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok || !data.products || data.products.length === 0) {
                    document.getElementById('psResults').innerHTML = '<div class="ps-empty">Không tìm thấy sản phẩm</div>';
                    return;
                }
                var html = '';
                data.products.forEach(function (p) {
                    var payload = escapeHtml(JSON.stringify({
                        id: p.id, name: p.name, price: p.final_price, image_url: p.image_url || ''
                    }));
                    html += '<div class="ps-item" data-product="' + payload + '">';
                    html += '  <img src="../' + (p.image_url || '') + '" onerror="this.style.display=\'none\'">';
                    html += '  <div><div class="name">' + escapeHtml(p.name) + '</div>';
                    html += '  <div class="price">' + formatVnd(p.final_price) + '</div></div>';
                    html += '</div>';
                });
                document.getElementById('psResults').innerHTML = html;
                document.querySelectorAll('.ps-item').forEach(function (el) {
                    el.addEventListener('click', function () {
                        pickProduct(JSON.parse(this.dataset.product));
                    });
                });
            });
    }, 300);
});

function pickProduct(p) {
    if (currentSearchIdx === null) return;
    var idx = currentSearchIdx;
    document.querySelector('.new-product-id-' + idx).value = p.id;
    selectedProducts[idx] = p;
    var box = document.querySelector('.selected-prod-' + idx);
    box.innerHTML = '<img src="../' + p.image_url + '" onerror="this.style.display=\'none\'">' +
        '<div style="flex:1;"><div style="font-weight:600;">' + escapeHtml(p.name) + '</div>' +
        '<div style="font-family:\'IBM Plex Mono\',monospace;color:#a8461f;font-size:.85rem;">' +
        formatVnd(p.price) + '</div></div>';
    loadSizes(idx, p.id);
    closeProductSearch();
    updateSummary();
}

function updateSummary() {
    var oldTotal = 0, newTotal = 0, count = 0;
    document.querySelectorAll('.item-check:checked').forEach(function (cb) {
        var idx = cb.dataset.idx;
        var origQty = parseInt(cb.dataset.qty);
        var origPrice = parseFloat(cb.dataset.price);
        var qtyInp = document.querySelector('.qty-input-' + idx);
        var qty = qtyInp ? (parseInt(qtyInp.value) || 0) : origQty;
        if (qty > origQty) qty = origQty;
        if (qty < 1) qty = 1;

        oldTotal += origPrice * qty;
        var newPid = document.querySelector('.new-product-id-' + idx).value;
        var price = origPrice;
        if (selectedProducts[idx] && selectedProducts[idx].id == newPid) {
            price = selectedProducts[idx].price;
        }
        newTotal += price * qty;
        count++;
    });

    var summary = document.getElementById('exSummary');
    var submitBtn = document.getElementById('submitBtn');
    if (count === 0) { summary.style.display = 'none'; submitBtn.disabled = true; return; }
    summary.style.display = ''; submitBtn.disabled = false;

    document.getElementById('oldTotal').textContent = formatVnd(oldTotal);
    document.getElementById('newTotal').textContent = formatVnd(newTotal);

    var diff = newTotal - oldTotal;
    var diffEl = document.getElementById('diffTotal');
    var noteEl = document.getElementById('diffNote');
    diffEl.className = '';
    if (diff > 0) {
        diffEl.textContent = '+' + formatVnd(diff);
        diffEl.classList.add('diff-pos');
        noteEl.textContent = 'Khách cần thanh toán thêm ' + formatVnd(diff);
    } else if (diff < 0) {
        diffEl.textContent = '-' + formatVnd(Math.abs(diff));
        diffEl.classList.add('diff-neg');
        noteEl.textContent = 'Shop cần hoàn lại ' + formatVnd(Math.abs(diff)) + ' cho khách';
    } else {
        diffEl.textContent = '0đ';
        noteEl.textContent = 'Không có chênh lệch';
    }
}

document.querySelectorAll('[class*="qty-input-"]').forEach(function (el) {
    el.addEventListener('input', updateSummary);
});

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}
function formatVnd(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + 'đ'; }

document.getElementById('psModal').addEventListener('click', function (e) {
    if (e.target === this) closeProductSearch();
});
</script>

<?php include 'includes/admin_footer.php'; ?>