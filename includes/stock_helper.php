<?php
/**
 * Helper xử lý tồn kho theo size
 */

const ALL_SIZES = ['S', 'M', 'L', 'XL', '2XL'];

/**
 * Lấy tồn kho 1 size cụ thể
 */
function getProductStock(PDO $pdo, int $productId, string $size): int {
    $stmt = $pdo->prepare("SELECT stock FROM product_sizes WHERE product_id = ? AND size = ? LIMIT 1");
    $stmt->execute([$productId, $size]);
    $stock = $stmt->fetchColumn();
    return $stock === false ? 0 : (int)$stock;
}

/**
 * Lấy tồn kho tất cả size của 1 SP
 * @return array ['S' => 10, 'M' => 5, ...]
 */
function getAllStocks(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare("SELECT size, stock FROM product_sizes WHERE product_id = ?");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $result = [];
    foreach (ALL_SIZES as $s) {
        $result[$s] = isset($rows[$s]) ? (int)$rows[$s] : 0;
    }
    return $result;
}

/**
 * Kiểm tra có đủ hàng không
 */
function hasEnoughStock(PDO $pdo, int $productId, string $size, int $qty): bool {
    return getProductStock($pdo, $productId, $size) >= $qty;
}

/**
 * Trừ tồn kho (khi khách mua)
 * Trả về true nếu thành công, false nếu không đủ hàng
 */
function decreaseStock(PDO $pdo, int $productId, string $size, int $qty): bool {
    // Atomic UPDATE — tránh race condition
    $stmt = $pdo->prepare("
        UPDATE product_sizes 
        SET stock = stock - ? 
        WHERE product_id = ? AND size = ? AND stock >= ?
    ");
    $stmt->execute([$qty, $productId, $size, $qty]);
    return $stmt->rowCount() > 0;
}

/**
 * Hoàn tồn kho (khi khách hủy đơn)
 */
function increaseStock(PDO $pdo, int $productId, string $size, int $qty): void {
    $stmt = $pdo->prepare("
        INSERT INTO product_sizes (product_id, size, stock) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)
    ");
    $stmt->execute([$productId, $size, (int)$qty]);
}

/**
 * Đặt tồn kho tuyệt đối (admin nhập tay)
 */
function setStock(PDO $pdo, int $productId, string $size, int $stock): void {
    $stmt = $pdo->prepare("
        INSERT INTO product_sizes (product_id, size, stock) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE stock = VALUES(stock)
    ");
    $stmt->execute([$productId, $size, max(0, (int)$stock)]);
}

/**
 * Tổng tồn kho của 1 SP (cộng dồn các size)
 */
function getTotalStock(PDO $pdo, int $productId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = ?");
    $stmt->execute([$productId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Lấy nhãn cảnh báo tồn kho
 */
function stockLabel(int $stock): array {
    if ($stock <= 0)    return ['Hết hàng', '#b91c1c', '#fee2e2'];
    if ($stock <= 5)    return ['Sắp hết', '#d97706', '#fef3c7'];
    if ($stock <= 20)   return ['Còn hàng', '#166534', '#dcfce7'];
    return ['Sẵn hàng', '#166534', '#dcfce7'];
}