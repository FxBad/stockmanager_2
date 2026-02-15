<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!(isRole('office') || isRole('admin'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
if ($sinceId < 0) {
    $sinceId = 0;
}

$formatAuditChangedFields = static function (array $row): array {
    $changed = [];

    if (array_key_exists('field_stock_old', $row) && array_key_exists('field_stock_new', $row) && (string)$row['field_stock_old'] !== (string)$row['field_stock_new']) {
        $changed[] = 'Stok Lapangan';
    }
    if (array_key_exists('status_old', $row) && array_key_exists('status_new', $row) && (string)$row['status_old'] !== (string)$row['status_new']) {
        $changed[] = 'Status';
    }
    if (array_key_exists('total_stock_old', $row) && array_key_exists('total_stock_new', $row) && (string)$row['total_stock_old'] !== (string)$row['total_stock_new']) {
        $changed[] = 'Total Stok';
    }
    if (array_key_exists('days_coverage_old', $row) && array_key_exists('days_coverage_new', $row) && (string)$row['days_coverage_old'] !== (string)$row['days_coverage_new']) {
        $changed[] = 'Ketahanan Hari';
    }

    if (isset($row['note']) && trim((string)$row['note']) !== '') {
        $changed[] = 'Catatan';
    }

    $action = strtolower((string)($row['action'] ?? ''));
    if ($action === 'create') {
        $changed[] = 'Item Baru';
    } elseif ($action === 'archive' || $action === 'delete') {
        $changed[] = 'Arsip';
    }

    if (empty($changed)) {
        $changed[] = 'Perubahan Data';
    }

    return array_values(array_unique($changed));
};

try {
    $stmt = $pdo->prepare("SELECT
            h.id,
            h.item_id,
            h.item_name,
            h.action,
            h.field_stock_old,
            h.field_stock_new,
            h.status_old,
            h.status_new,
            h.total_stock_old,
            h.total_stock_new,
            h.days_coverage_old,
            h.days_coverage_new,
            h.level,
            h.note,
            h.changed_at,
            u.username AS changed_by_username,
            u.full_name AS changed_by_full_name
        FROM item_stock_history h
        LEFT JOIN users u ON h.changed_by = u.id
        WHERE h.id > ?
        ORDER BY h.id ASC
        LIMIT 30");
    $stmt->execute([$sinceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payloadRows = [];
    $lastId = $sinceId;

    foreach ($rows as $row) {
        $rowId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($rowId > $lastId) {
            $lastId = $rowId;
        }

        $changedByLabel = trim((string)($row['changed_by_full_name'] ?? ''));
        if ($changedByLabel === '') {
            $changedByLabel = trim((string)($row['changed_by_username'] ?? 'System'));
        }

        $payloadRows[] = [
            'id' => $rowId,
            'item_id' => isset($row['item_id']) ? (int)$row['item_id'] : 0,
            'item_name' => (string)($row['item_name'] ?? 'Item'),
            'action' => (string)($row['action'] ?? '-'),
            'changed_by' => $changedByLabel !== '' ? $changedByLabel : 'System',
            'changed_at' => (string)($row['changed_at'] ?? '-'),
            'changed_fields' => $formatAuditChangedFields($row),
        ];
    }

    echo json_encode([
        'success' => true,
        'last_id' => $lastId,
        'rows' => $payloadRows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Audit feed error']);
}
