<?php
require_once '../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền.']); exit;
}

$action = $_REQUEST['action'] ?? '';

/* ============================================================
   1. TÌM KIẾM SẢN PHẨM
   ============================================================ */
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    $like = '%' . mb_strtolower($q) . '%';
    
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.image_url, 
                   CASE WHEN p.discount_percent > 0 
                        THEN ROUND(p.price * (100 - p.discount_percent) / 100) 
                        ELSE p.price END AS final_price
            FROM products p
            WHERE LOWER(p.name) LIKE ?
            ORDER BY p.id DESC 
            LIMIT 15
        ");
        $stmt->execute([$like]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'products' => $products]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Lỗi SQL: ' . $e->getMessage()]);
    }
    exit;
}

/* ============================================================
   2. LẤY SIZE CÒN HÀNG CỦA SẢN PHẨM
   ============================================================ */
if ($action === 'sizes') {
    $pid = (int)($_GET['product_id'] ?? 0);
    
    try {
        $stmt = $pdo->prepare("
            SELECT size, stock 
            FROM product_sizes 
            WHERE product_id = ? AND stock > 0 
            ORDER BY FIELD(size, 'S','M','L','XL','2XL')
        ");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'sizes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

/* ============================================================
   3. KIỂM TRA VOUCHER (MÃ GIẢM GIÁ)
   ============================================================ */
if ($action === 'check_voucher') {
    $code = mb_strtoupper(trim($_GET['code'] ?? ''));
    $subtotal = (float)($_GET['subtotal'] ?? 0);

    if (!$code || $subtotal <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ.']); exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ?");
        $stmt->execute([$code]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$v) {
            echo json_encode(['ok' => false, 'msg' => 'Mã giảm giá không tồn tại.']); exit;
        }

        $today = date('Y-m-d');
        if ($v['valid_from'] > $today) {
            echo json_encode(['ok' => false, 'msg' => 'Mã chưa đến thời gian sử dụng.']); exit;
        }
        if ($v['valid_to'] < $today) {
            echo json_encode(['ok' => false, 'msg' => 'Mã đã hết hạn sử dụng.']); exit;
        }

        $usageLimit = (int)$v['usage_limit'];
        $usedCount = (int)$v['used_count'];
        if ($usageLimit > 0 && $usedCount >= $usageLimit) {
            echo json_encode(['ok' => false, 'msg' => 'Mã đã hết lượt sử dụng.']); exit;
        }

        if ($subtotal < (float)$v['min_order_amount']) {
            echo json_encode(['ok' => false, 'msg' => 'Đơn hàng chưa đạt mức tối thiểu ' . number_format($v['min_order_amount'], 0, ',', '.') . 'đ.']); exit;
        }

        // Tính toán giảm giá
        $discount = $subtotal * ((float)$v['discount_percent'] / 100);
        if (!empty($v['max_discount_amount'])) {
            $discount = min($discount, (float)$v['max_discount_amount']);
        }

        echo json_encode([
            'ok' => true,
            'voucher' => [
                'id' => $v['id'],
                'code' => $v['code'],
                'min_order_amount' => (float)$v['min_order_amount']
            ],
            'discount' => $discount
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

/* ============================================================
   4. TẠO ĐƠN HÀNG MỚI
   ============================================================ */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => 'Phiên làm việc hết hạn.']); exit;
    }

    $full_name      = trim($_POST['full_name'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $note           = trim($_POST['note'] ?? '');
    $payMethod      = $_POST['payment_method'] ?? 'cod';
    $payStatus      = $_POST['payment_status'] ?? 'unpaid';
    $orderStatus    = $_POST['order_status'] ?? 'pending';
    $cart           = json_decode($_POST['cart'] ?? '[]', true);
    $shippingInfo   = json_decode($_POST['shipping_info'] ?? '{}', true);
    
    $voucherCode    = trim($_POST['voucher_code'] ?? '');
    $discountAmount = (float)($_POST['discount_amount'] ?? 0);

    if (!$full_name || !$phone || !$address || empty($cart)) {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập đủ thông tin và sản phẩm.']); exit;
    }
    
    if (!in_array($orderStatus, ['pending', 'delivered'], true)) {
        $orderStatus = 'pending';
    }

    $paymentNote = null;
    if ($payMethod === 'card') {
        $cardNumber = trim($_POST['card_number'] ?? '');
        $cardName   = trim($_POST['card_name'] ?? '');
        $last4 = $cardNumber ? substr(preg_replace('/\D/', '', $cardNumber), -4) : '****';
        $paymentNote = "Thanh toán thẻ (Tên: $cardName - Số thẻ: **** **** **** $last4)";
    }

    if (($shippingInfo['type'] ?? '') === 'delivery') {
        $note = trim("Giao qua đơn vị vận chuyển. " . $note);
    } else {
        $note = trim("Khách lấy tại cửa hàng. " . $note);
    }

    try {
        $pdo->beginTransaction();
        
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $totalAmount = $subtotal - $discountAmount;
        if ($totalAmount < 0) $totalAmount = 0;

        $paidAt = ($payStatus === 'paid') ? date('Y-m-d H:i:s') : null;
        $deliveredAt = ($orderStatus === 'delivered') ? date('Y-m-d H:i:s') : null;

        // Insert order
        $stmt = $pdo->prepare("
            INSERT INTO orders 
            (full_name, phone, address, note, payment_method, payment_status, paid_at, payment_note, 
             voucher_code, discount_amount, total_amount, status, delivered_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $full_name, $phone, $address, $note, $payMethod, $payStatus, $paidAt, $paymentNote,
            $voucherCode ?: null, $discountAmount, $totalAmount, $orderStatus, $deliveredAt
        ]);
        $orderId = (int)$pdo->lastInsertId();

        // Insert items & Update stock
        $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, size, quantity, price) VALUES (?, ?, ?, ?, ?)");
        $stmtStock = $pdo->prepare("UPDATE product_sizes SET stock = stock - ? WHERE product_id = ? AND size = ?");
        $stmtSold = $pdo->prepare("UPDATE products SET sold_count = sold_count + ? WHERE id = ?");

        foreach ($cart as $item) {
            $stmtItem->execute([$orderId, $item['product_id'], $item['size'], $item['quantity'], $item['price']]);
            $stmtStock->execute([$item['quantity'], $item['product_id'], $item['size']]);
            $stmtSold->execute([$item['quantity'], $item['product_id']]);
        }

        // Lưu log voucher
        if ($voucherCode && $discountAmount > 0) {
            $stmtV = $pdo->prepare("SELECT id FROM vouchers WHERE code = ?");
            $stmtV->execute([$voucherCode]);
            $vId = $stmtV->fetchColumn();

            if ($vId) {
                $pdo->prepare("
                    INSERT INTO voucher_usages (voucher_id, voucher_code, user_id, order_id, discount_amount, status, created_at)
                    VALUES (?, ?, NULL, ?, ?, 'applied', NOW())
                ")->execute([$vId, $voucherCode, $orderId, $discountAmount]);

                $pdo->prepare("UPDATE vouchers SET used_count = used_count + 1 WHERE id = ?")->execute([$vId]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'order_id' => $orderId, 'msg' => '✅ Đã tạo đơn hàng thành công!']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Lỗi: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Hành động không hợp lệ.']);