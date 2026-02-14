<?php
// Script untuk refresh status semua item agar sesuai stok terbaru
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Ambil semua item
$stmt = $pdo->query('SELECT * FROM items WHERE ' . activeItemsWhereSql());
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($items as $item) {
    $field = (int)($item['field_stock'] ?? 0);
    $warehouse = 0; // Warehouse stock removed from system
    $unitConv = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1.0;
    $daily = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0.0;
    $levelConversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unitConv;
    $calculationMode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
    $minDays = isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1;
    $level = isset($item['level']) ? $item['level'] : null;
    $name = isset($item['name']) ? $item['name'] : null;
    $category = isset($item['category']) ? $item['category'] : '';
    $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;

    $days = calculateDaysCoverage($field, $warehouse, $unitConv, $daily, $name, $level, $hasLevel, [
        'item_id' => isset($item['id']) ? (int)$item['id'] : 0,
        'category' => $category,
        'min_days_coverage' => $minDays,
        'level_conversion' => $levelConversion,
        'qty_conversion' => $unitConv,
        'calculation_mode' => $calculationMode
    ]);
    $status = determineStatus($days, $minDays);

    if ($status !== $item['status']) {
        $upd = $pdo->prepare('UPDATE items SET status = :status WHERE id = :id AND ' . activeItemsWhereSql());
        $upd->execute([':status' => $status, ':id' => $item['id']]);
        $updated++;
    }
}

echo "Status diperbarui untuk $updated item.";
