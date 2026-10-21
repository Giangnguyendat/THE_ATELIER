<?php
require_once '../includes/config.php';
include 'includes/admin_header.php';

$voucherId = (int)($_GET['voucher_id'] ?? 0);

$where = $voucherId > 0 ? 'WHERE vu.voucher_id = ?' : '';
$params = $voucherId > 0 ? [$voucherId] : [];

$stmt = $pdo->prepare("
    SELECT vu.*, u.full_name AS user_name, u.email AS user_email
    FROM voucher_usages vu
    LEFT JOIN users u ON u.id = vu.user_id
    $where
    ORDER BY vu.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$usages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-page-head">
    <div>
        <h1>Lịch sử dùng voucher</h1>
        <div class="subtitle"><?php echo count($usages); ?> lượt sử dụng</div>
    </div>
</div>

<div class="admin-card" style="overflow-x:auto;">
    <table class="adm-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Voucher</th>
                <th>Người dùng</th>
                <th>Đơn hàng</th>
                <th>Giảm giá</th>
                <th>Trạng thái</th>
                <th>Thời gian</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usages as $u): ?>
                <tr>
                    <td>#<?php echo (int)$u['id']; ?></td>
                    <td><b><?php echo htmlspecialchars($u['voucher_code']); ?></b></td>
                    <td>
                        <?php echo htmlspecialchars($u['user_name'] ?? 'Khách'); ?><br>
                        <small style="color:#8a8377;"><?php echo htmlspecialchars($u['user_email'] ?? ''); ?></small>
                    </td>
                    <td>
                        <a href="orders.php?q=<?php echo (int)$u['order_id']; ?>">
                            #<?php echo (int)$u['order_id']; ?>
                        </a>
                    </td>
                    <td><?php echo number_format($u['discount_amount'], 0, ',', '.'); ?>đ</td>
                    <td>
                        <?php
                        $statusMap = [
                            'applied'   => ['Đang áp dụng', 'adm-badge--green'],
                            'cancelled' => ['Đã hủy',       'adm-badge--red'],
                            'refunded'  => ['Đã hoàn',      'adm-badge--gray'],
                        ];
                        $s = $statusMap[$u['status']] ?? ['—', 'adm-badge--gray'];
                        ?>
                        <span class="adm-badge <?php echo $s[1]; ?>"><?php echo $s[0]; ?></span>
                    </td>
                    <td><?php echo date('d/m/Y H:i', strtotime($u['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/admin_footer.php'; ?>