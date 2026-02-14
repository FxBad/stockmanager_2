<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get search term
$term = isset($_GET['term']) ? trim($_GET['term']) : '';

// Return empty array if term is too short
if (strlen($term) < 1) {
    echo json_encode([]);
    exit;
}

try {
    // Search for items matching the term
    $stmt = $pdo->prepare("
        SELECT DISTINCT name 
        FROM items 
                WHERE name LIKE ?
                    AND " . activeItemsWhereSql() . "
        ORDER BY name ASC 
        LIMIT 10
    ");

    $stmt->execute(["%$term%"]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Return as JSON
    header('Content-Type: application/json');
    echo json_encode($items);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
