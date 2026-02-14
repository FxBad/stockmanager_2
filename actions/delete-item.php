<?php
session_start();
// Capture any accidental output (warnings / includes) so we can return
// a clean JSON response. We'll discard buffer contents before sending JSON.
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

function send_json($payload, $code = 200)
{
    // Discard any buffered output to keep response clean
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    // Ensure output is flushed and buffering ended
    if (ob_get_level()) ob_end_flush();
    exit;
}

// Require roles
if (!isset($_SESSION['user_id']) || !in_array(currentUserRole(), ['admin', 'office'], true)) {
    send_json(['success' => false, 'message' => 'Forbidden'], 403);
}

// Only accept POST and JSON or form submissions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Accept both application/json and form-encoded
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $input = $json;
    }
}

if (!isset($input['item_id'])) {
    send_json(['success' => false, 'message' => 'Missing item_id'], 400);
}

$itemId = (int)$input['item_id'];
if ($itemId <= 0) {
    send_json(['success' => false, 'message' => 'Invalid item id'], 400);
}

try {
    if (!itemsSoftDeleteEnabled()) {
        throw new Exception('Soft-delete is not enabled. Please run DB migration for deleted_at/deleted_by columns.');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Fetch current active item data
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ? AND ' . activeItemsWhereSql() . ' FOR UPDATE');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->rollBack();
        send_json(['success' => false, 'message' => 'Item not found'], 404);
    }

    // Prepare history data
    $totalStock = ($item['field_stock']) * $item['unit_conversion'];
    $daysCoverage = calculateDaysCoverage(
        $item['field_stock'],
        0,
        $item['unit_conversion'],
        $item['daily_consumption'],
        isset($item['name']) ? $item['name'] : null,
        isset($item['level']) ? $item['level'] : null,
        isset($item['has_level']) ? (bool)$item['has_level'] : false,
        [
            'item_id' => $itemId,
            'category' => isset($item['category']) ? $item['category'] : '',
            'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
        ]
    );

    $resolvedDaily = resolveDailyConsumption($item['daily_consumption'], [
        'item_id' => $itemId,
        'category' => isset($item['category']) ? $item['category'] : '',
        'effective_stock' => $totalStock,
        'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
    ]);

    $histHasLevel = db_has_column('item_stock_history', 'level');
    $histSqlBase = 'INSERT INTO item_stock_history
            (item_id, item_name, category, action, field_stock_old, field_stock_new, warehouse_stock_old, warehouse_stock_new, status_old, status_new, total_stock_old, total_stock_new, days_coverage_old, days_coverage_new, unit, unit_conversion, daily_consumption';
    if ($histHasLevel) $histSqlBase .= ', level';
    $histSqlBase .= ', min_days_coverage, changed_by, note)
            VALUES
            (:item_id, :item_name, :category, :action, :field_stock_old, NULL, :warehouse_stock_old, NULL, :status_old, NULL, :total_stock_old, NULL, :days_coverage_old, NULL, :unit, :unit_conversion, :daily_consumption';
    if ($histHasLevel) $histSqlBase .= ', :level';
    $histSqlBase .= ', :min_days_coverage, :changed_by, :note)';

    $histStmt = $pdo->prepare($histSqlBase);
    $histParams = [
        ':item_id' => $itemId,
        ':action' => 'delete',
        ':field_stock_old' => $item['field_stock'],
        ':warehouse_stock_old' => 0,
        ':status_old' => $item['status'],
        ':total_stock_old' => $totalStock,
        ':days_coverage_old' => $daysCoverage,
        ':unit' => ($item['unit'] !== null ? $item['unit'] : ''),
        ':unit_conversion' => $item['unit_conversion'],
        ':daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $item['daily_consumption'],
        ':min_days_coverage' => $item['min_days_coverage'],
        ':item_name' => $item['name'],
        ':category' => $item['category'],
        ':changed_by' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
        ':note' => 'soft-deleted via UI (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')'
    ];
    if ($histHasLevel) {
        $histParams[':level'] = isset($item['level']) ? $item['level'] : null;
    }
    $histStmt->execute($histParams);

    // Soft delete item (archive)
    $includeDeletedBy = db_has_column('items', 'deleted_by');
    if ($includeDeletedBy) {
        $delStmt = $pdo->prepare('UPDATE items SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE id = ? AND ' . activeItemsWhereSql());
        $delStmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $itemId]);
    } else {
        $delStmt = $pdo->prepare('UPDATE items SET deleted_at = NOW(), updated_by = ? WHERE id = ? AND ' . activeItemsWhereSql());
        $delStmt->execute([$_SESSION['user_id'], $itemId]);
    }

    $pdo->commit();

    send_json(['success' => true, 'message' => 'Item archived'], 200);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log full error for debugging
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'error.log';
    $ts = date('Y-m-d H:i:s');
    $msg = "{$ts} | delete-item error: " . $e->getMessage() . "\n";
    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);

    // Return generic message to client
    send_json(['success' => false, 'message' => 'Server error occurred'], 500);
}
