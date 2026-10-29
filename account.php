<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   1. LẤY DỮ LIỆU
   ============================================================ */

$stmtUser = $pdo->prepare("SELECT full_name, email, phone, address, created_at FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$_SESSION['user']  = array_merge($_SESSION['user'] ?? [], $user);
$_SESSION['email'] = $user['email'] ?? '';

// Lấy địa chỉ Shop
$shopAddress = '123 Đường Thời Trang, Phường Bến Thành, Quận 1, TP.HCM';
$shopPhone = '1900 123 456';
try {
    $stmtFooter = $pdo->query("SELECT address, phone FROM footer_settings WHERE id = 1");
    $footerData = $stmtFooter->fetch(PDO::FETCH_ASSOC);
    if (!empty($footerData['address'])) $shopAddress = $footerData['address'];
    if (!empty($footerData['phone'])) $shopPhone = $footerData['phone'];
} catch (Exception $e) {}

// Tất cả đơn hàng
$stmtOrders = $pdo->prepare("
    SELECT * FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmtOrders->execute([$userId]);
$allOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

$orderIds = array_column($allOrders, 'id');

// Query 1 lần: tất cả items
$itemsByOrder = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.name, p.image_url
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
    ");
    $stmtItems->execute($orderIds);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsByOrder[$it['order_id']][] = $it;
    }
}

// Query 1 lần: reviews user đã viết
$reviewedKeys = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtRv = $pdo->prepare("
        SELECT order_id, product_id
        FROM reviews
        WHERE user_id = ? AND order_id IN ($placeholders)
    ");
    $stmtRv->execute(array_merge([$userId], $orderIds));
    foreach ($stmtRv->fetchAll(PDO::FETCH_ASSOC) as $rv) {
        $reviewedKeys[$rv['order_id'] . '_' . $rv['product_id']] = true;
    }
}

// Query 1 lần: return requests (lấy request mới nhất cho mỗi đơn)
$returnsByOrder = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtRet = $pdo->prepare("
        SELECT id, order_id, return_code, status, refund_amount, refund_method, created_at
        FROM return_requests
        WHERE user_id = ? AND order_id IN ($placeholders)
        ORDER BY created_at DESC
    ");
    $stmtRet->execute(array_merge([$userId], $orderIds));
    foreach ($stmtRet->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($returnsByOrder[$r['order_id']])) {
            $returnsByOrder[$r['order_id']] = $r;
        }
    }
}

/* ============================================================
   2. PHÂN LOẠI ĐƠN
   ============================================================ */
$activeStatuses = ['pending', 'processing', 'confirmed', 'shipped'];
$myOrders = array_values(array_filter($allOrders, function ($o) use ($activeStatuses) {
    return in_array($o['status'], $activeStatuses, true);
}));

$historyOrders = array_values(array_filter($allOrders, function ($o) {
    return in_array($o['status'], ['delivered', 'cancelled'], true);
}));

$countMy      = count($myOrders);
$countHistory = count($historyOrders);

/* ============================================================
   3. STATUS MAP
   ============================================================ */
$statusMap = [
    'pending'    => ['text' => 'Chờ xử lý',          'color' => '#d97706', 'bg' => '#fef3c7'],
    'processing' => ['text' => 'Đang xử lý',         'color' => '#2563eb', 'bg' => '#dbeafe'],
    'confirmed'  => ['text' => 'Đã xác nhận',        'color' => '#37423a', 'bg' => '#e8e0d0'],
    'shipped'    => ['text' => 'Đang giao hàng',     'color' => '#0891b2', 'bg' => '#cff4fc'],
    'delivered'  => ['text' => 'Đã giao thành công', 'color' => '#16a34a', 'bg' => '#dcfce7'],
    'cancelled'  => ['text' => 'Đã hủy',             'color' => '#dc2626', 'bg' => '#fee2e2'],
];

$editableStatuses = ['pending', 'processing', 'confirmed'];

/* ============================================================
   4. HELPER: FORMAT MÃ ĐƠN HOÀN
   ============================================================ */
function formatReturnCode(array $r): string {
    if (!empty($r['return_code'])) return $r['return_code'];
    $date = !empty($r['created_at']) ? date('Ymd', strtotime($r['created_at'])) : date('Ymd');
    return 'RT' . $date . '-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
}

/* ============================================================
   5. HELPER RENDER 1 CARD ĐƠN HÀNG
   ============================================================ */
function renderOrderCard(
    array $order,
    array $items,
    array $statusMap,
    array $editableStatuses,
    array $reviewedKeys,
    array $returnsByOrder = []
): string {
    $statusInfo = $statusMap[$order['status']] ?? ['text' => $order['status'], 'color' => '#888', 'bg' => '#f5f5f5'];
    $canModify  = in_array($order['status'], $editableStatuses, true);

    // Payment method (Phosphor icon + label)
    $pmMap = [
        'cod'    => ['icon' => 'ph-money',       'label' => 'COD'],
        'bank'   => ['icon' => 'ph-bank',        'label' => 'Chuyển khoản'],
        'card'   => ['icon' => 'ph-credit-card', 'label' => 'Thẻ'],
        'wallet' => ['icon' => 'ph-wallet',      'label' => 'Atelier Pay'],
    ];
    $pm = $pmMap[$order['payment_method'] ?? 'cod'] ?? $pmMap['cod'];
    $payMethod = '<i class="ph ' . $pm['icon'] . '"></i> ' . $pm['label'];

    $payStatus = $order['payment_status'] ?? 'unpaid';
    $payBadge = [
        'unpaid'         => ['text' => 'Chưa thanh toán', 'color' => '#b91c1c', 'bg' => '#fef2f2', 'icon' => '<i class="ph ph-hourglass"></i>'],
        'pending'        => ['text' => 'Chờ thanh toán',  'color' => '#c2410c', 'bg' => '#fff7ed', 'icon' => '<i class="ph ph-clock"></i>'],
        'paid'           => ['text' => 'Đã thanh toán',   'color' => '#166534', 'bg' => '#ecfdf5', 'icon' => '<i class="ph ph-check-circle"></i>'],
        'refund_pending' => ['text' => 'Chờ hoàn tiền',   'color' => '#7c3216', 'bg' => '#fdf3ee', 'icon' => '<i class="ph ph-arrow-counter-clockwise"></i>'],
    ][$payStatus] ?? ['text' => $payStatus, 'color' => '#57534e', 'bg' => '#f5f5f4', 'icon' => '<i class="ph ph-minus"></i>'];

    $canPayNow = $order['payment_method'] === 'bank'
              && in_array($payStatus, ['unpaid', 'pending'], true)
              && in_array($order['status'], ['pending', 'processing', 'confirmed'], true);

    $isDelivered = ($order['status'] === 'delivered');

    // Trả hàng
    $returnInfo = $returnsByOrder[$order['id']] ?? null;
    $canReturn  = false;
    if ($isDelivered && !$returnInfo) {
        $dt = strtotime($order['delivered_at'] ?? $order['updated_at'] ?? $order['created_at']);
        if (time() - $dt <= 7 * 86400) {
            $canReturn = true;
        }
    }

    // Return status map (icon HTML thay vì emoji)
    $rMap = [
        'pending'   => ['Chờ duyệt trả hàng',  '#d97706', '#fef3c7', '<i class="ph ph-hourglass"></i>'],
        'approved'  => ['Đã duyệt trả hàng',   '#2563eb', '#dbeafe', '<i class="ph ph-check-circle"></i>'],
        'refunding' => ['Đang hoàn tiền',      '#c2410c', '#fff7ed', '<i class="ph ph-arrows-clockwise"></i>'],
        'refunded'  => ['Đã hoàn tiền',        '#166534', '#ecfdf5', '<i class="ph ph-check-circle"></i>'],
        'rejected'  => ['Từ chối trả hàng',    '#b91c1c', '#fef2f2', '<i class="ph ph-x-circle"></i>'],
        'cancelled' => ['Đã hủy yêu cầu',      '#57534e', '#f5f5f4', '<i class="ph ph-minus"></i>'],
    ];

    ob_start(); ?>
    <div class="order-item-wrapper" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;">

        <!-- HEADER -->
        <div onclick="toggleOrderDetail(<?php echo (int)$order['id']; ?>)" class="order-summary-row" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;cursor:pointer;background:#fff;transition:background 0.2s;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;">
                <span style="font-weight:700;font-size:15px;color:#111827;">#<?php echo (int)$order['id']; ?></span>
                <span style="color:#6b7280;font-size:13px;">
                    <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                </span>
                <?php if (!empty($order['cancel_reason'])): ?>
                    <span style="background:#fee2e2;color:#991b1b;font-size:11px;padding:2px 8px;border-radius:10px;">
                        Lý do: <?php echo sanitize($order['cancel_reason']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span style="font-size:15px;font-weight:600;color:#dc2626;">
                    <?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ
                </span>

                <!-- Badge trạng thái đơn -->
                <span style="background:<?php echo $statusInfo['bg']; ?>;color:<?php echo $statusInfo['color']; ?>;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;">
                    <?php echo $statusInfo['text']; ?>
                </span>

                <!-- Badge thanh toán -->
                <?php if (!$isDelivered): ?>
                    <span style="background:<?php echo $payBadge['bg']; ?>;color:<?php echo $payBadge['color']; ?>;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <?php echo $payBadge['icon']; ?> <?php echo $payBadge['text']; ?>
                    </span>
                <?php endif; ?>

                <!-- Badge trả hàng + mã đơn -->
                <?php if ($returnInfo):
                    $rInfo = $rMap[$returnInfo['status']] ?? ['Đang xử lý', '#57534e', '#f5f5f4', '<i class="ph ph-minus"></i>'];
                    $rCode = formatReturnCode($returnInfo);
                ?>
                    <span style="background:<?php echo $rInfo[2]; ?>;color:<?php echo $rInfo[1]; ?>;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;font-family:'IBM Plex Mono',monospace;display:inline-flex;align-items:center;gap:4px;">
                        <?php echo $rInfo[3]; ?> <?php echo sanitize($rCode); ?>
                    </span>
                <?php endif; ?>

                <!-- Nút thanh toán ngay -->
                <?php if ($canPayNow): ?>
                    <a href="payment.php?order_id=<?php echo (int)$order['id']; ?>" onclick="event.stopPropagation();" style="background:#a8461f;color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(168,70,31,0.25);">
                        <i class="ph ph-credit-card"></i> Thanh toán ngay
                    </a>
                <?php endif; ?>

                <button type="button" style="background:#f3f4f6;border:1px solid #d1d5db;padding:6px 12px;border-radius:6px;font-size:13px;cursor:pointer;color:#374151;font-weight:500;display:inline-flex;align-items:center;gap:4px;">
                    Xem chi tiết <span id="arrow-<?php echo (int)$order['id']; ?>"><i class="ph ph-caret-down"></i></span>
                </button>
            </div>
        </div>

        <!-- BODY -->
        <div id="detail-<?php echo (int)$order['id']; ?>" style="display:none;border-top:1px solid #e5e7eb;background:#fafafa;padding:20px;">

            <!-- Thông tin người nhận -->
            <div style="background:#fff;padding:15px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:15px;">
                <h4 style="font-size:14px;color:#374151;margin:0 0 10px 0;text-transform:uppercase;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <i class="ph ph-map-pin"></i> Thông tin người nhận
                </h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:14px;color:#4b5563;">
                    <div><strong>Người nhận:</strong> <?php echo sanitize($order['full_name']); ?></div>
                    <div><strong>Số điện thoại:</strong> <?php echo sanitize($order['phone']); ?></div>
                    <div style="grid-column:span 2;"><strong>Địa chỉ:</strong> <?php echo sanitize($order['address']); ?></div>
                    <?php if (!empty($order['note'])): ?>
                        <div style="grid-column:span 2;font-style:italic;color:#6b7280;">
                            <strong>Ghi chú:</strong> "<?php echo sanitize($order['note']); ?>"
                        </div>
                    <?php endif; ?>
                    <div><strong>Phương thức:</strong> <?php echo $payMethod; ?></div>
                    <?php if (!$isDelivered): ?>
                        <div>
                            <strong>Trạng thái TT:</strong>
                            <span style="background:<?php echo $payBadge['bg']; ?>;color:<?php echo $payBadge['color']; ?>;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;margin-left:4px;display:inline-flex;align-items:center;gap:3px;">
                                <?php echo $payBadge['icon']; ?> <?php echo $payBadge['text']; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($payStatus === 'paid' && !empty($order['paid_at'])): ?>
                        <div style="grid-column:span 2;font-size:13px;color:#166534;display:flex;align-items:center;gap:6px;">
                            <i class="ph ph-check-circle"></i> <strong>Thanh toán lúc:</strong> <?php echo date('d/m/Y H:i', strtotime($order['paid_at'])); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['card_id'])): ?>
                        <div style="grid-column:span 2;font-family:'IBM Plex Mono', monospace;font-size:12px;color:#6b7280;">
                            <strong>ID thẻ:</strong> •••• <?php echo substr($order['card_id'], -4); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chi tiết sản phẩm -->
            <div style="background:#fff;padding:15px;border-radius:8px;border:1px solid #e5e7eb;">
                <h4 style="font-size:14px;color:#374151;margin:0 0 12px 0;text-transform:uppercase;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <i class="ph ph-shopping-bag"></i> Chi tiết sản phẩm
                </h4>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($items as $item): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px dashed #f3f4f6;padding-bottom:8px;">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <img src="<?php echo sanitize($item['image_url']); ?>" alt="" style="width:45px;height:45px;object-fit:cover;border-radius:6px;border:1px solid #eee;">
                                <div>
                                    <div style="font-weight:600;font-size:14px;color:#1f2937;">
                                        <?php echo sanitize($item['name']); ?>
                                    </div>
                                    <div style="font-size:12px;color:#6b7280;">
                                        Size: <strong><?php echo sanitize($item['size']); ?></strong>
                                        | SL: <strong>x<?php echo (int)$item['quantity']; ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div style="font-size:14px;font-weight:600;color:#111827;">
                                <?php echo number_format($item['price'] * $item['quantity'], 0, ',', '.'); ?>đ
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Khối thanh toán ngay -->
            <?php if ($canPayNow): ?>
                <div style="margin-top:15px;padding:14px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700;color:#c2410c;font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                                <i class="ph ph-hourglass"></i> Đơn hàng chưa được thanh toán
                            </div>
                            <div style="font-size:13px;color:#7c3216;">
                                Vui lòng thanh toán <b><?php echo number_format($order['total_amount'], 0, ',', '.'); ?>đ</b>
                                để đơn hàng được xử lý nhanh hơn.
                            </div>
                        </div>
                        <a href="payment.php?order_id=<?php echo (int)$order['id']; ?>" style="background:#a8461f;color:#fff;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 2px 8px rgba(168,70,31,0.3);display:inline-flex;align-items:center;gap:6px;">
                            <i class="ph ph-credit-card"></i> Thanh toán ngay
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canModify): ?>
                <div style="display:flex;gap:10px;margin-top:15px;">
                    <button type="button" class="btn-order-edit" data-order='<?php echo htmlspecialchars(json_encode([
                        'id'      => (int)$order['id'],
                        'name'    => $order['full_name'],
                        'phone'   => $order['phone'],
                        'address' => $order['address'],
                        'note'    => $order['note'] ?? '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>' style="flex:1;background:#fff;color:#1c1b19;border:1px solid #1c1b19;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                        <i class="ph ph-pencil-simple"></i> Sửa đơn hàng
                    </button>
                    <button type="button" class="btn-order-cancel" data-order-id="<?php echo (int)$order['id']; ?>" style="flex:1;background:#fff;color:#dc2626;border:1px solid #dc2626;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                        <i class="ph ph-x-circle"></i> Hủy đơn hàng
                    </button>
                </div>

            <?php elseif ($order['status'] === 'shipped'): ?>
                <!-- NÚT ĐÃ NHẬN ĐƯỢC HÀNG -->
                <div style="margin-top:15px; padding:15px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; text-align:center;">
                    <p style="margin:0 0 12px; font-size:14px; color:#0369a1; font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;">
                        <i class="ph ph-truck"></i> Đơn hàng đang trên đường giao đến bạn.
                    </p>
                    <button type="button" class="btn-order-receive" data-order-id="<?php echo (int)$order['id']; ?>" style="width:100%; background:#0891b2; color:#fff; border:none; padding:12px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 2px 6px rgba(8,145,178,0.25); transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="ph ph-check-circle"></i> Đã nhận được hàng
                    </button>
                </div>

            <?php elseif ($order['status'] === 'delivered'): ?>
                <div style="margin-top:15px;">

                    <!-- KHỐI TRẢ HÀNG / HOÀN TIỀN -->
                    <?php if ($returnInfo):
                        $rInfo   = $rMap[$returnInfo['status']] ?? ['Đang xử lý', '#57534e', '#f5f5f4', '<i class="ph ph-minus"></i>'];
                        $rCode   = formatReturnCode($returnInfo);
                        $rMethod = ($returnInfo['refund_method'] ?? 'wallet') === 'bank' ? 'Chuyển khoản' : 'Ví Atelier Pay';
                    ?>
                        <div style="padding:14px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:0.85rem;">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                                <div style="min-width:0;flex:1;">
                                    <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;font-weight:700;margin-bottom:4px;">
                                        Mã đơn hoàn
                                    </div>
                                    <div style="font-family:'IBM Plex Mono',monospace;font-size:1rem;font-weight:700;color:#a8461f;margin-bottom:6px;">
                                        <?php echo sanitize($rCode); ?>
                                    </div>
                                    <div style="font-size:0.78rem;color:#6b7280;">
                                        Đơn gốc <b>#<?php echo (int)$order['id']; ?></b>
                                        · <?php echo date('d/m/Y H:i', strtotime($returnInfo['created_at'])); ?>
                                    </div>
                                    <div style="font-size:0.9rem;font-weight:700;color:#1c1b19;margin-top:6px;">
                                        Hoàn: <?php echo number_format($returnInfo['refund_amount'], 0, ',', '.'); ?>đ
                                        <span style="font-weight:400;color:#6b7280;font-size:0.82rem;">· qua <?php echo $rMethod; ?></span>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                                    <span style="background:<?php echo $rInfo[2]; ?>;color:<?php echo $rInfo[1]; ?>;padding:6px 14px;border-radius:12px;font-size:0.78rem;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;">
                                        <?php echo $rInfo[3]; ?> <?php echo $rInfo[0]; ?>
                                    </span>
                                    <button type="button"
                                            onclick="printReturnWaybill('<?php echo htmlspecialchars($rCode); ?>', 'SPX', '<?php echo htmlspecialchars(addslashes($order['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['phone'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'])); ?>', <?php echo (int)$order['id']; ?>)"
                                            style="background:#fff; border:1px solid #d1d5db; color:#374151; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; transition: all 0.2s;">
                                        <i class="ph ph-printer"></i> In lại mã vận
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($canReturn): ?>
                        <div style="display:flex; gap:10px; margin-bottom:0.85rem;">
                            <a href="return.php?order_id=<?php echo (int)$order['id']; ?>" style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px;background:#fff;color:#a8461f;border:1px solid #a8461f;border-radius:8px;font-size:0.88rem;font-weight:600;text-decoration:none;box-sizing:border-box;" onmouseover="this.style.background='#a8461f';this.style.color='#fff';" onmouseout="this.style.background='#fff';this.style.color='#a8461f';">
                                <i class="ph ph-arrows-clockwise"></i> Yêu cầu trả hàng / Hoàn tiền
                            </a>
                            <a href="return-policy.php" target="_blank" title="Xem chính sách đổi trả" style="display:flex;align-items:center;justify-content:center;padding:11px 14px;background:#f3f4f6;color:#4b5563;border:1px solid #d1d5db;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;">
                                <i class="ph ph-book-open"></i>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Đánh giá sản phẩm -->
                    <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;margin-bottom:0.5rem;display:flex;align-items:center;gap:6px;">
                        <i class="ph ph-star"></i> Đánh giá sản phẩm
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.4rem;">
                        <?php foreach ($items as $it):
                            $key = $order['id'] . '_' . $it['product_id'];
                            $isReviewed = isset($reviewedKeys[$key]);
                        ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.75rem;background:#f8f9fa;border-radius:6px;font-size:0.85rem;">
                                <div style="display:flex;align-items:center;gap:0.6rem;flex:1;min-width:0;">
                                    <img src="<?php echo sanitize($it['image_url']); ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px;">
                                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <?php echo sanitize($it['name']); ?>
                                    </div>
                                </div>
                                <?php if ($isReviewed): ?>
                                    <span style="font-size:0.75rem;color:#166534;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                                        <i class="ph ph-check-circle"></i> Đã đánh giá
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="btn-review-trigger" data-product-id="<?php echo (int)$it['product_id']; ?>" data-product-name="<?php echo htmlspecialchars($it['name'], ENT_QUOTES); ?>" data-order-id="<?php echo (int)$order['id']; ?>" style="background:#166534;color:#fff;border:none;padding:0.35rem 0.85rem;border-radius:6px;font-size:0.75rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                                        <i class="ph ph-star"></i> Đánh giá
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($order['status'] === 'cancelled'): ?>
                <div style="margin-top:12px;padding:10px 14px;background:#fef2f2;border-radius:6px;font-size:13px;color:#991b1b;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;">
                    <i class="ph ph-x-circle"></i> Đơn hàng đã hủy
                    <?php if ($payStatus === 'refund_pending'): ?>
                        <span style="font-size:12px;display:inline-flex;align-items:center;gap:4px;">
                            · <i class="ph ph-arrow-counter-clockwise"></i> Đang chờ hoàn tiền
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
<?php include 'includes/header.php'; ?>

<style>
    /* ============================================================
       PHOSPHOR ICON BASE ALIGNMENT
       ============================================================ */
    .ph { vertical-align: -0.125em; }

    .account-tab-btn {
        background: transparent;
        border: none;
        padding: 10px 18px;
        font-family: var(--font-mono, monospace);
        font-size: 0.8rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #6b7280;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .account-tab-btn i { font-size: 1.05rem; }
    .account-tab-btn:hover { color: #1c1b19; }
    .account-tab-btn.active {
        color: #1c1b19;
        border-bottom-color: #a8461f;
        font-weight: 600;
    }
    .account-tab-btn .badge {
        background: #e5e7eb;
        color: #4b5563;
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 700;
        letter-spacing: 0;
    }
    .account-tab-btn.active .badge {
        background: #a8461f;
        color: #fff;
    }
    .order-summary-row:hover { background: #f9fafb !important; }

    .order-toast {
        position: fixed;
        top: 1.5rem;
        left: 50%;
        transform: translateX(-50%) translateY(-20px);
        background: #166534;
        color: #fff;
        padding: 0.95rem 1.75rem;
        font-family: var(--font-mono, monospace);
        font-size: 0.85rem;
        letter-spacing: 0.03em;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        z-index: 9999;
        opacity: 0;
        transition: opacity 0.3s ease, transform 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        max-width: 90vw;
        border-radius: 6px;
    }
    .order-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .order-toast.error { background: #b91c1c; }
    .order-toast__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        flex: none;
    }
    .order-toast__icon i { font-size: 1rem; }

    #starPicker {
        display: flex;
        gap: 0.5rem;
        font-size: 2.2rem;
        cursor: pointer;
        user-select: none;
    }
    #starPicker span {
        color: #d1d5db;
        transition: color 0.15s, transform 0.15s;
        line-height: 1;
    }
    #starPicker span:hover { transform: scale(1.15); }

    .info-field {
        margin-bottom: 15px;
        position: relative;
    }
    .info-field label {
        color: #666;
        font-size: 13px;
        display: block;
        margin-bottom: 4px;
    }
    .info-field .value {
        font-size: 14px;
        color: #333;
        word-break: break-word;
    }
    .btn-edit-profile {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 11px;
        background: #1c1b19;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        margin-bottom: 10px;
        transition: background 0.2s;
    }
    .btn-edit-profile:hover { background: #37423a; }

    /* Modal close &times; -> Phosphor */
    .modal-close i { font-size: 1.25rem; }

    /* Empty state icon */
    .account-empty-icon {
        font-size: 48px;
        margin-bottom: 12px;
        color: #d1d5db;
    }
</style>

<main class="account-page-wrapper" style="padding: 40px 0; background-color: #f8f9fa;">
    <div class="container" style="max-width: 1100px; margin: 0 auto; padding: 0 15px;">
        <h1 style="margin-bottom: 30px; font-size: 26px; font-weight: 700; color: #222;">Tài Khoản Của Tôi</h1>

        <div style="display: grid; grid-template-columns: 300px 1fr; gap: 30px;">

            <!-- CỘT TRÁI -->
            <div style="background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.05);height:fit-content;">
                <h2 style="font-size:18px;font-weight:600;margin-bottom:20px;border-bottom:2px solid #eee;padding-bottom:10px;">
                    Thông tin tài khoản
                </h2>

                <div class="info-field">
                    <label>Họ và tên:</label>
                    <div class="value" id="profileFullName">
                        <strong><?php echo sanitize($user['full_name'] ?? 'Chưa cập nhật'); ?></strong>
                    </div>
                </div>

                <div class="info-field">
                    <label>Email:</label>
                    <div class="value" id="profileEmail">
                        <?php echo sanitize($user['email'] ?? 'N/A'); ?>
                    </div>
                </div>

                <div class="info-field">
                    <label>Số điện thoại:</label>
                    <div class="value" id="profilePhone">
                        <?php echo sanitize($user['phone'] ?? 'Chưa cập nhật'); ?>
                    </div>
                </div>

                <div class="info-field" style="margin-bottom:20px;">
                    <label>Địa chỉ mặc định:</label>
                    <div class="value" id="profileAddress">
                        <?php echo sanitize($user['address'] ?? 'Chưa cập nhật'); ?>
                    </div>
                </div>

                <button type="button" class="btn-edit-profile" id="btnEditProfile">
                    <i class="ph ph-pencil-simple"></i> Sửa thông tin cá nhân
                </button>

                <a href="logout.php" style="display:flex;align-items:center;justify-content:center;gap:8px;text-align:center;background:#fff1f0;color:#ff4d4f;padding:10px;border-radius:8px;text-decoration:none;font-weight:600;border:1px solid #ffa39e;">
                    <i class="ph ph-sign-out"></i> Đăng xuất
                </a>
            </div>

            <!-- CỘT PHẢI -->
            <div style="background:#fff;padding:0;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.05);overflow:hidden;">

                <!-- TAB HEADERS -->
                <div style="display:flex;border-bottom:2px solid #eee;padding:0 20px;gap:4px;">
                    <button type="button" class="account-tab-btn active" id="tabBtnMy" onclick="switchAccountTab('my')">
                        <i class="ph ph-package"></i> Đơn hàng của tôi
                        <span class="badge"><?php echo (int)$countMy; ?></span>
                    </button>
                    <button type="button" class="account-tab-btn" id="tabBtnHistory" onclick="switchAccountTab('history')">
                        <i class="ph ph-clock-counter-clockwise"></i> Lịch sử mua hàng
                        <span class="badge"><?php echo (int)$countHistory; ?></span>
                    </button>
                </div>

                <!-- TAB 1 -->
                <div id="tabContentMy" style="padding:25px;">
                    <?php if (empty($myOrders)): ?>
                        <div style="text-align:center;padding:60px 20px;color:#6b7280;">
                            <div class="account-empty-icon"><i class="ph ph-tray"></i></div>
                            <p style="margin:0 0 6px;font-size:16px;color:#374151;font-weight:600;">
                                Bạn không có đơn hàng nào đang xử lý
                            </p>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <?php foreach ($myOrders as $order):
                                $items = $itemsByOrder[$order['id']] ?? [];
                                echo renderOrderCard($order, $items, $statusMap, $editableStatuses, $reviewedKeys, $returnsByOrder);
                            endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 2 -->
                <div id="tabContentHistory" style="padding:25px;display:none;">
                    <?php if (empty($historyOrders)): ?>
                        <div style="text-align:center;padding:60px 20px;color:#6b7280;">
                            <div class="account-empty-icon"><i class="ph ph-tray"></i></div>
                            <p style="margin:0;font-size:16px;color:#374151;font-weight:600;">
                                Chưa có đơn hàng nào đã giao thành công
                            </p>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <?php foreach ($historyOrders as $order):
                                $items = $itemsByOrder[$order['id']] ?? [];
                                echo renderOrderCard($order, $items, $statusMap, $editableStatuses, $reviewedKeys, $returnsByOrder);
                            endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ============ MODAL SỬA THÔNG TIN CÁ NHÂN ============ -->
<div class="modal-overlay" id="editProfileModal" aria-hidden="true">
    <div class="modal-content" style="max-width:520px;padding:2rem;">
        <button type="button" class="modal-close" id="closeEditProfileModal" aria-label="Đóng"><i class="ph ph-x"></i></button>
        <h2 class="modal-title" style="margin-bottom:0.5rem;display:flex;align-items:center;gap:8px;">
            <i class="ph ph-pencil-simple"></i> Sửa thông tin cá nhân
        </h2>
        <p style="font-size:0.85rem;color:#6b7280;margin:0 0 1.25rem;">
            Cập nhật họ tên, số điện thoại và địa chỉ mặc định của bạn.
        </p>

        <form id="editProfileForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Họ và tên *</label>
                <input type="text" name="full_name" id="profile_full_name" required maxlength="100" value="<?php echo sanitize($user['full_name'] ?? ''); ?>" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Email (không thể thay đổi)</label>
                <input type="email" id="profile_email_readonly" value="<?php echo sanitize($user['email'] ?? ''); ?>" readonly disabled style="width:100%;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;background:#f8f9fa;color:#6b7280;cursor:not-allowed;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Số điện thoại</label>
                <input type="tel" name="phone" id="profile_phone" maxlength="20" value="<?php echo sanitize($user['phone'] ?? ''); ?>" placeholder="0912 345 678" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Địa chỉ mặc định</label>
                <textarea name="address" id="profile_address" rows="3" maxlength="500" placeholder="Số nhà, tên đường, phường/xã, quận/huyện, tỉnh/thành phố..." style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;"><?php echo sanitize($user['address'] ?? ''); ?></textarea>
            </div>

            <p id="editProfileErrorMsg" style="color:#b91c1c;font-size:13px;margin:8px 0 0;min-height:1em;"></p>

            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="button" id="editProfileCancelBtn" style="flex:1;padding:11px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-weight:600;cursor:pointer;">
                    Hủy
                </button>
                <button type="submit" style="flex:2;padding:11px;background:#1c1b19;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="ph ph-floppy-disk"></i> Lưu thay đổi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL SỬA ĐƠN ============ -->
<div class="modal-overlay" id="editOrderModal" aria-hidden="true">
    <div class="modal-content" style="max-width:520px;padding:2rem;">
        <button type="button" class="modal-close" id="closeEditModal" aria-label="Đóng"><i class="ph ph-x"></i></button>
        <h2 class="modal-title" style="margin-bottom:0.5rem;">Sửa thông tin giao hàng</h2>
        <p style="font-size:0.85rem;color:#6b7280;margin:0 0 1.25rem;">
            Chỉ được sửa <b>người nhận, SĐT, địa chỉ, ghi chú</b>. Sản phẩm không thể thay đổi.
        </p>

        <form id="editOrderForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="update_order">
            <input type="hidden" name="order_id" id="edit_order_id" value="">

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Người nhận *</label>
                <input type="text" name="full_name" id="edit_full_name" required maxlength="100" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Số điện thoại *</label>
                <input type="tel" name="phone" id="edit_phone" required maxlength="20" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Địa chỉ giao hàng *</label>
                <textarea name="address" id="edit_address" required rows="3" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;"></textarea>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Ghi chú</label>
                <textarea name="note" id="edit_note" rows="2" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;" placeholder="Ví dụ: giao giờ hành chính..."></textarea>
            </div>

            <p id="editErrorMsg" style="color:#b91c1c;font-size:13px;margin:8px 0 0;min-height:1em;"></p>

            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="button" id="editCancelBtn" style="flex:1;padding:11px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-weight:600;cursor:pointer;">
                    Đóng
                </button>
                <button type="submit" style="flex:2;padding:11px;background:#1c1b19;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="ph ph-floppy-disk"></i> Lưu thay đổi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL HỦY ĐƠN ============ -->
<div class="modal-overlay" id="cancelOrderModal" aria-hidden="true">
    <div class="modal-content" style="max-width:520px;padding:2rem;">
        <button type="button" class="modal-close" id="closeCancelModal" aria-label="Đóng"><i class="ph ph-x"></i></button>
        <h2 class="modal-title" style="margin-bottom:0.5rem;color:#b91c1c;display:flex;align-items:center;gap:8px;">
            <i class="ph ph-x-circle"></i> Hủy đơn hàng
        </h2>
        <p style="font-size:0.85rem;color:#6b7280;margin:0 0 1.25rem;">
            Bạn chắc chắn muốn hủy đơn hàng này? Hành động này không thể hoàn tác.
        </p>

        <form id="cancelOrderForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="order_id" id="cancel_order_id" value="">

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Lý do hủy đơn *</label>
                <select name="cancel_reason" id="cancel_reason" required style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:#fff;">
                    <option value="">-- Chọn lý do --</option>
                    <option value="Đổi ý, không muốn mua nữa">Đổi ý, không muốn mua nữa</option>
                    <option value="Đặt nhầm sản phẩm / số lượng">Đặt nhầm sản phẩm / số lượng</option>
                    <option value="Muốn thay đổi địa chỉ giao hàng">Muốn thay đổi địa chỉ giao hàng</option>
                    <option value="Tìm được giá tốt hơn nơi khác">Tìm được giá tốt hơn nơi khác</option>
                    <option value="Thời gian giao hàng quá lâu">Thời gian giao hàng quá lâu</option>
                    <option value="Khác">Khác</option>
                </select>
            </div>

            <div id="otherReasonGroup" hidden style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Lý do cụ thể</label>
                <textarea name="cancel_reason_other" id="cancel_reason_other" rows="2" maxlength="200" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;" placeholder="Vui lòng ghi rõ lý do..."></textarea>
            </div>

            <p id="cancelErrorMsg" style="color:#b91c1c;font-size:13px;margin:8px 0 0;min-height:1em;"></p>

            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="button" id="cancelKeepBtn" style="flex:1;padding:11px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-weight:600;cursor:pointer;">
                    Giữ đơn
                </button>
                <button type="submit" style="flex:2;padding:11px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="ph ph-x-circle"></i> Xác nhận hủy
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL ĐÁNH GIÁ ============ -->
<div class="modal-overlay" id="reviewModal" aria-hidden="true">
    <div class="modal-content" style="max-width:560px;padding:2rem;">
        <button type="button" class="modal-close" id="closeReviewModal" aria-label="Đóng"><i class="ph ph-x"></i></button>

        <h2 class="modal-title" style="margin-bottom:0.5rem;display:flex;align-items:center;gap:8px;">
            <i class="ph ph-star"></i> Đánh giá sản phẩm
        </h2>
        <p id="reviewProductName" style="font-size:0.9rem;color:#6b7280;margin:0 0 1.5rem;display:flex;align-items:center;gap:6px;">
            <i class="ph ph-package"></i> <span id="reviewProductNameText"></span>
        </p>

        <form id="reviewForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="product_id" id="review_product_id">
            <input type="hidden" name="order_id" id="review_order_id">
            <input type="hidden" name="rating" id="review_rating" value="5">

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;margin-bottom:0.5rem;">
                    Chất lượng sản phẩm *
                </label>
                <div id="starPicker">
                    <span data-star="1">★</span>
                    <span data-star="2">★</span>
                    <span data-star="3">★</span>
                    <span data-star="4">★</span>
                    <span data-star="5">★</span>
                </div>
                <div id="ratingText" style="font-size:0.8rem;color:#6b7280;margin-top:0.35rem;"></div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;margin-bottom:0.5rem;">
                    Nhận xét của bạn
                </label>
                <textarea name="content" id="review_content" rows="4" maxlength="2000" placeholder="Chia sẻ trải nghiệm của bạn về sản phẩm..." style="width:100%;padding:0.85rem;border:1px solid #d1d5db;border-radius:8px;font-family:inherit;font-size:0.9rem;resize:vertical;"></textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:#8a8377;margin-bottom:0.5rem;">
                    Ảnh thực tế (tối đa 5 ảnh, mỗi ảnh ≤ 5MB)
                </label>
                <input type="file" name="images[]" id="review_images" accept="image/*" multiple style="width:100%;padding:0.6rem;border:1px dashed #d1d5db;border-radius:8px;font-size:0.85rem;background:#f8f9fa;">
                <div id="imagePreview" style="display:flex;gap:0.4rem;margin-top:0.6rem;flex-wrap:wrap;"></div>
            </div>

            <p id="reviewErrorMsg" style="color:#b91c1c;font-size:0.82rem;margin:0.5rem 0 0;min-height:1em;"></p>

            <div style="display:flex;gap:0.75rem;margin-top:1.25rem;">
                <button type="button" id="reviewCancelBtn" style="flex:1;padding:0.85rem;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-weight:600;cursor:pointer;">
                    Hủy
                </button>
                <button type="submit" id="reviewSubmitBtn" style="flex:2;padding:0.85rem;background:#166534;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="ph ph-paper-plane-tilt"></i> Gửi đánh giá
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL IN LẠI MÃ VẬN ĐƠN ============ -->
<div id="returnWaybillModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #f3f4f6; border-radius: 12px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 20px; position: relative; animation: modalFadeIn 0.2s ease;">
        <!-- Nút đóng -->
        <button type="button" onclick="closeReturnWaybill()" style="position: absolute; top: 10px; right: 10px; background: #fff; border: 1px solid #d1d5db; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10;">
            <i class="ph ph-x" style="font-size: 1.1rem;"></i>
        </button>

        <div style="text-align: center; margin-bottom: 15px;">
            <h3 style="margin: 0 0 10px; color: #1c1b19;">Mã Vận Đơn Trả Hàng</h3>
            <button type="button" onclick="window.print()" style="background: #166534; color: #fff; padding: 8px 16px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <i class="ph ph-printer"></i> In Mã Vận Đơn
            </button>
        </div>

        <!-- KHUNG TEM IN -->
        <div id="modalPrintArea" style="background: #fff; padding: 15px; border: 1px solid #ccc;">
            <div style="border: 2px solid #000; padding: 15px; font-family: Arial, sans-serif; color: #000;">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 10px;">
                    <div id="modalWbLogo" style="font-size: 28px; font-weight: 900; color: #ee4d2d; text-transform: uppercase;">SPX</div>
                    <div style="text-align: center; flex: 1; padding-left: 15px;">
                        <img id="modalWbBarcode" src="" style="width: 100%; max-width: 280px; height: 40px; object-fit: contain;">
                        <div id="modalWbTrackingNo" style="font-size: 14px; font-weight: bold; margin-top: 4px;"></div>
                        <div id="modalWbOrderId" style="font-size: 10px; color: #555; margin-top: 2px;"></div>
                    </div>
                </div>

                <div style="display: flex; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; gap: 10px;">
                    <div style="flex: 1; font-size: 12px; line-height: 1.4; border-right: 1px dashed #000; padding-right: 10px;">
                        <div style="font-size:10px; color:#555; margin-bottom:2px;">TỪ (Người gửi):</div>
                        <b id="modalWbFromName"></b><br>
                        <span id="modalWbFromPhone"></span><br>
                        <span id="modalWbFromAddress"></span>
                    </div>
                    <div style="flex: 1; font-size: 12px; line-height: 1.4;">
                        <div style="font-size:10px; color:#555; margin-bottom:2px;">ĐẾN (Shop):</div>
                        <b>THE ATELIER STORE</b><br>
                        <?= htmlspecialchars($shopAddress ?? '123 Đường Thời Trang, Phường Bến Thành, Quận 1, TP.HCM') ?><br>
                        <b>SĐT:</b> <?= htmlspecialchars($shopPhone ?? '1900 123 456') ?>
                    </div>
                </div>

                <div style="text-align: center; font-size: 22px; font-weight: bold; border-bottom: 1px solid #000; padding: 8px 0; margin-bottom: 10px; letter-spacing: 2px;">HÀNG HOÀN</div>

                <div style="display: flex; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; gap: 10px;">
                    <div style="flex: 1; font-size: 12px; line-height: 1.4; padding-right: 10px; border-right: 1px dashed #000;">
                        <b>Nội dung:</b><br>
                        Sản phẩm hoàn trả từ đơn hàng #<span id="modalWbOrderIdContent"></span>
                    </div>
                    <div style="width: 110px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <img id="modalWbQr" src="" style="width: 100px; height: 100px; object-fit: contain;">
                        <div id="modalWbQrCode" style="font-size: 11px; font-weight: bold; margin-top: 4px; word-break: break-all;"></div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                    <div>
                        <div style="font-size:11px; color:#555;">Loại đơn:</div>
                        <div style="font-size: 16px; font-weight: bold;">HÀNG HOÀN</div>
                    </div>
                    <div style="border: 1px solid #000; padding: 5px 10px; font-size: 10px; text-align: center; min-width: 120px;">
                        <div style="margin-bottom:25px;">Chữ ký người nhận</div>
                        <div style="font-size:8px; color:#555;">(Xác nhận hàng nguyên vẹn)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

    /* CSS đảm bảo khi bấm in, chỉ in phần tem vận đơn */
    @media print {
        body * { visibility: hidden; }
        #returnWaybillModal { position: static !important; background: none !important; padding: 0 !important; display: block !important; }
        #modalPrintArea, #modalPrintArea * { visibility: visible; }
        #modalPrintArea { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; border: none; }
        .no-print { display: none !important; }
    }
</style>

<!-- TOAST -->
<div id="orderToast" class="order-toast" hidden>
    <span class="order-toast__icon" id="orderToastIcon"><i class="ph ph-check"></i></span>
    <span id="orderToastMsg">Thành công!</span>
</div>

<script>
/* ============================================================
   TOGGLE ACCORDION
   ============================================================ */
function toggleOrderDetail(orderId) {
    var detailDiv = document.getElementById('detail-' + orderId);
    var arrowSpan = document.getElementById('arrow-' + orderId);
    if (!detailDiv) return;

    if (detailDiv.style.display === 'none' || detailDiv.style.display === '') {
        detailDiv.style.display = 'block';
        if (arrowSpan) arrowSpan.innerHTML = '<i class="ph ph-caret-up"></i>';
    } else {
        detailDiv.style.display = 'none';
        if (arrowSpan) arrowSpan.innerHTML = '<i class="ph ph-caret-down"></i>';
    }
}

/* ============================================================
   TAB SWITCH
   ============================================================ */
function switchAccountTab(tab) {
    var btnMy      = document.getElementById('tabBtnMy');
    var btnHistory = document.getElementById('tabBtnHistory');
    var contentMy  = document.getElementById('tabContentMy');
    var contentHis = document.getElementById('tabContentHistory');

    if (tab === 'my') {
        btnMy.classList.add('active');
        btnHistory.classList.remove('active');
        contentMy.style.display = 'block';
        contentHis.style.display = 'none';
    } else {
        btnHistory.classList.add('active');
        btnMy.classList.remove('active');
        contentHis.style.display = 'block';
        contentMy.style.display = 'none';
    }
}

/* ============================================================
   HÀM IN LẠI MÃ VẬN ĐƠN
   ============================================================ */
function printReturnWaybill(returnCode, carrier, name, phone, address, orderId) {
    var modal = document.getElementById('returnWaybillModal');
    modal.style.display = 'flex';

    // Điền thông tin người gửi
    document.getElementById('modalWbFromName').textContent = name;
    document.getElementById('modalWbFromPhone').textContent = phone;
    document.getElementById('modalWbFromAddress').textContent = address;
    document.getElementById('modalWbOrderId').textContent = 'Mã đơn gốc: #' + orderId;
    document.getElementById('modalWbOrderIdContent').textContent = orderId;

    // Tạo mã vận đơn ảo dựa trên mã đơn hoàn
    var prefix = carrier === 'SPX' ? 'SPXVN' : (carrier === 'GHN' ? 'GHN' : (carrier === 'VIETTEL' ? 'VTP' : 'GHTK'));
    var numbersOnly = returnCode.replace(/\D/g,'');
    var trackingNo = prefix + numbersOnly;

    var logoEl = document.getElementById('modalWbLogo');
    logoEl.textContent = carrier === 'VIETTEL' ? 'VTP' : carrier;
    logoEl.style.color = carrier === 'SPX' ? '#ee4d2d' : (carrier === 'GHN' ? '#0055aa' : '#000');

    document.getElementById('modalWbTrackingNo').textContent = trackingNo;
    document.getElementById('modalWbBarcode').src = 'https://barcode.tec-it.com/barcode.ashx?data=' + trackingNo + '&code=Code128&translate-esc=on&dpi=96&height=40&text=no';

    document.getElementById('modalWbQrCode').textContent = trackingNo.substring(0, 12);
    document.getElementById('modalWbQr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + trackingNo;
}

function closeReturnWaybill() {
    document.getElementById('returnWaybillModal').style.display = 'none';
}

/* ============================================================
   MODAL + AJAX
   ============================================================ */
(function () {
    function showToast(msg, isError) {
        var toast = document.getElementById('orderToast');
        var icon  = document.getElementById('orderToastIcon');
        var text  = document.getElementById('orderToastMsg');

        icon.innerHTML = isError
            ? '<i class="ph ph-warning"></i>'
            : '<i class="ph ph-check"></i>';
        text.textContent = msg;
        toast.className = 'order-toast show' + (isError ? ' error' : '');
        setTimeout(function () { toast.className = 'order-toast'; }, 3500);
    }

    /* ============================================================
       MODAL SỬA THÔNG TIN CÁ NHÂN
       ============================================================ */
    var editProfileModal = document.getElementById('editProfileModal');
    var editProfileForm  = document.getElementById('editProfileForm');
    var editProfileErr   = document.getElementById('editProfileErrorMsg');

    function openEditProfile() {
        document.getElementById('profile_full_name').value = document.getElementById('profileFullName').innerText.trim();
        document.getElementById('profile_phone').value     = document.getElementById('profilePhone').innerText.trim() === 'Chưa cập nhật' ? '' : document.getElementById('profilePhone').innerText.trim();
        document.getElementById('profile_address').value   = document.getElementById('profileAddress').innerText.trim() === 'Chưa cập nhật' ? '' : document.getElementById('profileAddress').innerText.trim();
        editProfileErr.textContent = '';
        editProfileModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeEditProfile() {
        editProfileModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('btnEditProfile')?.addEventListener('click', openEditProfile);
    document.getElementById('closeEditProfileModal')?.addEventListener('click', closeEditProfile);
    document.getElementById('editProfileCancelBtn')?.addEventListener('click', closeEditProfile);
    editProfileModal?.addEventListener('click', function (e) {
        if (e.target === editProfileModal) closeEditProfile();
    });

    editProfileForm?.addEventListener('submit', function (e) {
        e.preventDefault();

        var btn = editProfileForm.querySelector('button[type="submit"]');
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Đang lưu...';
        editProfileErr.textContent = '';

        fetch('ajax/update_profile.php', {
            method: 'POST',
            body: new FormData(editProfileForm)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                document.getElementById('profileFullName').innerHTML = '<strong>' + (data.data.full_name || 'Chưa cập nhật') + '</strong>';
                document.getElementById('profilePhone').textContent    = data.data.phone   || 'Chưa cập nhật';
                document.getElementById('profileAddress').textContent  = data.data.address || 'Chưa cập nhật';

                closeEditProfile();
                showToast(data.msg || 'Cập nhật thành công!', false);
            } else {
                editProfileErr.textContent = data.msg || 'Có lỗi xảy ra.';
            }
        })
        .catch(function () {
            editProfileErr.textContent = 'Không thể kết nối tới máy chủ.';
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });

    /* ============================================================
       MODAL SỬA ĐƠN
       ============================================================ */
    var editModal = document.getElementById('editOrderModal');
    var editForm  = document.getElementById('editOrderForm');
    var editErr   = document.getElementById('editErrorMsg');

    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-order-edit');
        if (!btn) return;
        try {
            var data = JSON.parse(btn.dataset.order);
            document.getElementById('edit_order_id').value = data.id;
            document.getElementById('edit_full_name').value = data.name || '';
            document.getElementById('edit_phone').value     = data.phone || '';
            document.getElementById('edit_address').value   = data.address || '';
            document.getElementById('edit_note').value      = data.note || '';
            editErr.textContent = '';
            editModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        } catch (err) { console.error(err); }
    });

    function closeEdit() {
        editModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    document.getElementById('closeEditModal')?.addEventListener('click', closeEdit);
    document.getElementById('editCancelBtn')?.addEventListener('click', closeEdit);
    editModal?.addEventListener('click', function (e) { if (e.target === editModal) closeEdit(); });

    editForm?.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = editForm.querySelector('button[type="submit"]');
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Đang lưu...';

        fetch('ajax/order_actions.php', { method: 'POST', body: new FormData(editForm) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeEdit();
                showToast(data.msg || 'Cập nhật đơn hàng thành công!', false);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                editErr.textContent = data.msg || 'Có lỗi xảy ra.';
                btn.disabled = false;
                btn.innerHTML = old;
            }
        })
        .catch(function () {
            editErr.textContent = 'Không thể kết nối tới máy chủ.';
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });

    /* ============================================================
       MODAL HỦY
       ============================================================ */
    var cancelModal = document.getElementById('cancelOrderModal');
    var cancelForm  = document.getElementById('cancelOrderForm');
    var cancelErr   = document.getElementById('cancelErrorMsg');
    var reasonSel   = document.getElementById('cancel_reason');
    var otherGroup  = document.getElementById('otherReasonGroup');

    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-order-cancel');
        if (!btn) return;
        cancelForm.reset();
        document.getElementById('cancel_order_id').value = btn.dataset.orderId;
        otherGroup.hidden = true;
        cancelErr.textContent = '';
        cancelModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    });

    reasonSel?.addEventListener('change', function () {
        otherGroup.hidden = (this.value !== 'Khác');
    });

    function closeCancel() {
        cancelModal.classList.remove('show');
        document.body.style.overflow = '';
    }
    document.getElementById('closeCancelModal')?.addEventListener('click', closeCancel);
    document.getElementById('cancelKeepBtn')?.addEventListener('click', closeCancel);
    cancelModal?.addEventListener('click', function (e) { if (e.target === cancelModal) closeCancel(); });

    cancelForm?.addEventListener('submit', function (e) {
        e.preventDefault();

        if (reasonSel.value === 'Khác') {
            var other = document.getElementById('cancel_reason_other').value.trim();
            if (!other) {
                cancelErr.textContent = 'Vui lòng nhập lý do cụ thể.';
                return;
            }
            reasonSel.value = other;
        }

        if (!confirm('Bạn chắc chắn muốn hủy đơn hàng này?\nHành động này không thể hoàn tác.')) return;

        var btn = cancelForm.querySelector('button[type="submit"]');
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Đang xử lý...';

        fetch('ajax/order_actions.php', { method: 'POST', body: new FormData(cancelForm) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeCancel();
                showToast(data.msg || 'Hủy đơn hàng thành công!', false);
                setTimeout(function () { location.reload(); }, 1800);
            } else {
                cancelErr.textContent = data.msg || 'Có lỗi xảy ra.';
                btn.disabled = false;
                btn.innerHTML = old;
            }
        })
        .catch(function () {
            cancelErr.textContent = 'Không thể kết nối tới máy chủ.';
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });

    /* ============================================================
       XỬ LÝ NÚT ĐÃ NHẬN ĐƯỢC HÀNG
       ============================================================ */
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-order-receive');
        if (!btn) return;

        if (!confirm('Xác nhận bạn đã nhận được hàng?\nĐơn hàng sẽ chuyển sang trạng thái Đã giao thành công và bạn có thể đánh giá sản phẩm.')) return;

        var orderId = btn.dataset.orderId;
        var oldHTML = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="ph ph-hourglass"></i> Đang xử lý...';

        var fd = new FormData();
        fd.append('action', 'receive_order');
        fd.append('order_id', orderId);
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('ajax/order_actions.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                showToast(data.msg || 'Đã xác nhận nhận hàng!', false);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                showToast(data.msg || 'Có lỗi xảy ra.', true);
                btn.disabled = false;
                btn.innerHTML = oldHTML;
            }
        })
        .catch(function () {
            showToast('Không thể kết nối tới máy chủ.', true);
            btn.disabled = false;
            btn.innerHTML = oldHTML;
        });
    });

    /* ============================================================
       MODAL ĐÁNH GIÁ
       ============================================================ */
    var reviewModal  = document.getElementById('reviewModal');
    var reviewForm   = document.getElementById('reviewForm');
    var starPicker   = document.getElementById('starPicker');
    var ratingText   = document.getElementById('ratingText');
    var reviewErr    = document.getElementById('reviewErrorMsg');
    var imageInput   = document.getElementById('review_images');
    var imagePreview = document.getElementById('imagePreview');

    var ratingLabels = {
        1: 'Rất tệ (1/5)',
        2: 'Tệ (2/5)',
        3: 'Bình thường (3/5)',
        4: 'Tốt (4/5)',
        5: 'Rất tốt (5/5)',
    };

    function setRating(rating) {
        document.getElementById('review_rating').value = rating;
        var stars = starPicker.querySelectorAll('span');
        stars.forEach(function (s, i) {
            s.style.color = i < rating ? '#f59e0b' : '#d1d5db';
        });
        ratingText.textContent = ratingLabels[rating] || '';
    }

    starPicker?.addEventListener('click', function (e) {
        var star = e.target.closest('span[data-star]');
        if (star) setRating(parseInt(star.dataset.star, 10));
    });

    starPicker?.addEventListener('mouseover', function (e) {
        var star = e.target.closest('span[data-star]');
        if (!star) return;
        var hover = parseInt(star.dataset.star, 10);
        starPicker.querySelectorAll('span').forEach(function (s, i) {
            s.style.color = i < hover ? '#fbbf24' : '#d1d5db';
        });
    });

    starPicker?.addEventListener('mouseleave', function () {
        setRating(parseInt(document.getElementById('review_rating').value, 10) || 5);
    });

    imageInput?.addEventListener('change', function () {
        imagePreview.innerHTML = '';
        var files = Array.from(this.files).slice(0, 5);

        files.forEach(function (file) {
            if (!file.type.startsWith('image/')) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = document.createElement('img');
                img.src = ev.target.result;
                img.style.cssText = 'width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;';
                imagePreview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    });

    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-review-trigger');
        if (!btn) return;

        document.getElementById('review_product_id').value = btn.dataset.productId;
        document.getElementById('review_order_id').value   = btn.dataset.orderId;

        // Set textContent an toàn cho tên SP (dataset đã decode entity — tránh dùng innerHTML)
        document.getElementById('reviewProductNameText').textContent = btn.dataset.productName;

        reviewForm.reset();
        imagePreview.innerHTML = '';
        reviewErr.textContent = '';
        setRating(5);

        reviewModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    });

    function closeReview() {
        reviewModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('closeReviewModal')?.addEventListener('click', closeReview);
    document.getElementById('reviewCancelBtn')?.addEventListener('click', closeReview);
    reviewModal?.addEventListener('click', function (e) {
        if (e.target === reviewModal) closeReview();
    });

    reviewForm?.addEventListener('submit', function (e) {
        e.preventDefault();

        var btn = document.getElementById('reviewSubmitBtn');
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Đang gửi...';
        reviewErr.textContent = '';

        fetch('ajax/submit_review.php', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                closeReview();
                showToast(data.msg || 'Cảm ơn bạn đã đánh giá!', false);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                reviewErr.textContent = data.msg || 'Có lỗi xảy ra.';
                btn.disabled = false;
                btn.innerHTML = old;
            }
        })
        .catch(function () {
            reviewErr.textContent = 'Không thể kết nối tới máy chủ.';
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });

    setRating(5);

    /* ESC */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (editProfileModal.classList.contains('show')) closeEditProfile();
        if (editModal.classList.contains('show'))   closeEdit();
        if (cancelModal.classList.contains('show')) closeCancel();
        if (reviewModal.classList.contains('show')) closeReview();
        if (document.getElementById('returnWaybillModal').style.display === 'flex') closeReturnWaybill();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>