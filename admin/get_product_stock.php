<?php
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['ok' => false]);
    exit;
}

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode([
    'ok'     => true,
    'stocks' => getAllStocks($pdo, $productId),
]);