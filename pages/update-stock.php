<?php
session_start();
// Start output buffering to capture accidental output (whitespace, BOM, warnings)
ob_start();
require_once __DIR__ . '/../cache_control.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

$message = '';

// Determine current role for UI rendering
$currentRole = currentUserRole();
$isFieldUser = ($currentRole === 'field');
$has_level_flag_column = db_has_column('items', 'has_level');
$has_level_conversion_column = db_has_column('items', 'level_conversion');
$has_calculation_mode_column = db_has_column('items', 'calculation_mode');
$has_level_flag_select = $has_level_flag_column ? ', i.has_level' : '';
$level_conversion_select = $has_level_conversion_column ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
$calculation_mode_select = $has_calculation_mode_column ? ', i.calculation_mode' : ", 'combined' AS calculation_mode";
$categories = getItemCategories();

// Fetch all active items (only needed columns) with error handling
try {
    $stmt = $pdo->query("SELECT i.id, i.name, i.category, i.field_stock, i.unit, i.unit_conversion{$level_conversion_select}{$calculation_mode_select}, i.daily_consumption, i.min_days_coverage, i.level, i.status, i.added_by AS added_by_id, u.username AS added_by_name{$has_level_flag_select}
                            FROM items i
                            LEFT JOIN users u ON i.added_by = u.id
                            WHERE " . activeItemsWhereSql('i') . "
                            ORDER BY i.name");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $items = [];
    $message = '<div class="alert error">DB error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Setelah $items di-fetch, buat map untuk nilai awal
$itemsMap = [];
foreach ($items as $it) {
    $itemsMap[$it['id']] = $it;
}

// Cache column existence checks to avoid repeated metadata queries
$has_level_column = db_has_column('items', 'level');
$schemaFlags = itemSchemaFlags();

// Handle form submission
// Allow field, office and admin to access update stock
requireRole(['field', 'office', 'admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Basic POST validation
        if (!isset($_POST['field_stock']) || !is_array($_POST['field_stock'])) {
            throw new Exception('Invalid submission: missing field_stock data');
        }

        $pdo->beginTransaction();

        // Siapkan prepared statement. If `level` column exists, update it too.
        if ($has_level_column) {
            $updateSql = "
                UPDATE items
                SET field_stock = :field_stock,
                    status = :status,
                    level = :level,
                    updated_by = :user_id
                WHERE id = :id
                AND " . activeItemsWhereSql() . "
                AND (field_stock <> :field_stock
                    OR status <> :status
                    OR IFNULL(level, '') <> IFNULL(:level, ''))
                ";
        } else {
            $updateSql = "
                UPDATE items
                SET field_stock = :field_stock,
                    status = :status,
                    updated_by = :user_id
                WHERE id = :id
                AND " . activeItemsWhereSql() . "
                AND (field_stock <> :field_stock
                    OR status <> :status)
                ";
        }
        $updateStmt = $pdo->prepare($updateSql);

        // Collect updated items for AJAX responses (single-row or multiple)
        $updatedItems = [];

        foreach ($_POST['field_stock'] as $itemId => $fieldStockPost) {
            $itemId = (int)$itemId;

            // Ambil nilai awal dari map (cepat, tanpa SELECT per item)
            if (!isset($itemsMap[$itemId])) {
                continue;
            }
            $orig = $itemsMap[$itemId];
            $itemHasLevel = isset($orig['has_level']) ? (bool)$orig['has_level'] : false;

            $fieldStock = (int)$fieldStockPost;

            // Handle level input only for items with has_level = 1
            $levelValue = null;
            if (isset($_POST['level'][$itemId]) && $_POST['level'][$itemId] !== '') {
                // Validate integer
                $lvlRaw = $_POST['level'][$itemId];
                if (!is_numeric($lvlRaw) || intval($lvlRaw) != $lvlRaw || intval($lvlRaw) < 0) {
                    throw new Exception('Invalid level value for item ID ' . $itemId);
                }
                $levelValue = (int)$lvlRaw;
            }
            if (!$itemHasLevel && $levelValue !== null) {
                throw new Exception('Level can only be set for items with has_level enabled (item ID ' . $itemId . ')');
            }

            $effectiveLevel = ($levelValue !== null) ? $levelValue : (isset($orig['level']) ? $orig['level'] : null);
            $levelConversion = isset($orig['level_conversion']) ? (float)$orig['level_conversion'] : (float)$orig['unit_conversion'];
            $calculationMode = isset($orig['calculation_mode']) ? (string)$orig['calculation_mode'] : 'combined';

            // Warehouse stock removed from system
            $warehouseStock = 0;

            // Hitung status baru berdasar rules
            $totalStock = $fieldStock;
            $convertedStock = $totalStock * (float)$orig['unit_conversion'];
            $daysCoverage = calculateDaysCoverage(
                $fieldStock,
                0,
                (float)$orig['unit_conversion'],
                (float)$orig['daily_consumption'],
                isset($orig['name']) ? $orig['name'] : null,
                $effectiveLevel,
                $itemHasLevel,
                [
                    'item_id' => $itemId,
                    'category' => isset($orig['category']) ? $orig['category'] : '',
                    'min_days_coverage' => isset($orig['min_days_coverage']) ? (int)$orig['min_days_coverage'] : 1,
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => (float)$orig['unit_conversion'],
                    'calculation_mode' => $calculationMode
                ]
            );

            $status = determineStatus($daysCoverage, (int)$orig['min_days_coverage']);

            // Skip jika tidak ada perubahan sama sekali
            if (
                $fieldStock === (int)$orig['field_stock'] &&
                $status === $orig['status'] &&
                (
                    !$has_level_column ||
                    $levelValue === (isset($orig['level']) ? (int)$orig['level'] : null)
                )
            ) {
                continue;
            }

            // Eksekusi update hanya untuk item yang berubah
            $execParams = [
                ':field_stock' => $fieldStock,
                ':status' => $status,
                ':user_id' => $_SESSION['user_id'],
                ':id' => $itemId
            ];
            if ($has_level_column) {
                // If level not provided in POST, keep original
                $execParams[':level'] = $effectiveLevel;
            }
            $updateStmt->execute($execParams);

            // Record this updated item so we can return its final values in JSON for AJAX
            $updatedItems[] = [
                'id' => $itemId,
                'field_stock' => $fieldStock,
                'warehouse_stock' => 0,
                'level' => (isset($execParams[':level']) ? $execParams[':level'] : null),
                'total' => $fieldStock
            ];

            $totalOld = calculateEffectiveStock(
                $orig['field_stock'],
                (float)$orig['unit_conversion'],
                isset($orig['level']) ? $orig['level'] : null,
                $itemHasLevel,
                [
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => (float)$orig['unit_conversion'],
                    'calculation_mode' => $calculationMode
                ]
            );
            $totalNew = calculateEffectiveStock(
                $fieldStock,
                (float)$orig['unit_conversion'],
                $effectiveLevel,
                $itemHasLevel,
                [
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => (float)$orig['unit_conversion'],
                    'calculation_mode' => $calculationMode
                ]
            );
            $daysOld = calculateDaysCoverage(
                $orig['field_stock'],
                0,
                $orig['unit_conversion'],
                $orig['daily_consumption'],
                isset($orig['name']) ? $orig['name'] : null,
                isset($orig['level']) ? $orig['level'] : null,
                $itemHasLevel,
                [
                    'item_id' => $itemId,
                    'category' => isset($orig['category']) ? $orig['category'] : '',
                    'min_days_coverage' => isset($orig['min_days_coverage']) ? (int)$orig['min_days_coverage'] : 1,
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => (float)$orig['unit_conversion'],
                    'calculation_mode' => $calculationMode
                ]
            );
            $daysNew = calculateDaysCoverage(
                $fieldStock,
                0,
                $orig['unit_conversion'],
                $orig['daily_consumption'],
                isset($orig['name']) ? $orig['name'] : null,
                $effectiveLevel,
                $itemHasLevel,
                [
                    'item_id' => $itemId,
                    'category' => isset($orig['category']) ? $orig['category'] : '',
                    'min_days_coverage' => isset($orig['min_days_coverage']) ? (int)$orig['min_days_coverage'] : 1,
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => (float)$orig['unit_conversion'],
                    'calculation_mode' => $calculationMode
                ]
            );

            $resolvedDaily = resolveDailyConsumption($orig['daily_consumption'], [
                'item_id' => $itemId,
                'category' => isset($orig['category']) ? $orig['category'] : '',
                'effective_stock' => $totalNew,
                'min_days_coverage' => isset($orig['min_days_coverage']) ? (int)$orig['min_days_coverage'] : 1
            ]);

            $historyInsert = buildItemHistoryInsert($schemaFlags, [
                'item_id' => $itemId,
                'item_name' => $orig['name'],
                'category' => $orig['category'],
                'action' => 'update',
                'field_stock_old' => $orig['field_stock'],
                'field_stock_new' => $fieldStock,
                'warehouse_stock_old' => 0,
                'warehouse_stock_new' => 0,
                'status_old' => $orig['status'],
                'status_new' => $status,
                'total_stock_old' => $totalOld,
                'total_stock_new' => $totalNew,
                'days_coverage_old' => $daysOld,
                'days_coverage_new' => $daysNew,
                'unit' => (isset($orig['unit']) && $orig['unit'] !== null ? $orig['unit'] : ''),
                'unit_conversion' => $orig['unit_conversion'],
                'daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $orig['daily_consumption'],
                'level' => ($levelValue !== null) ? $levelValue : (isset($orig['level']) ? $orig['level'] : null),
                'min_days_coverage' => $orig['min_days_coverage'],
                'changed_by' => $_SESSION['user_id'],
                'note' => 'bulk stock update (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')'
            ]);
            $histStmt = $pdo->prepare($historyInsert['sql']);
            $histStmt->execute($historyInsert['params']);
        }

        $pdo->commit();
        $message = '<div class="alert success">Stock quantities updated successfully!</div>';
        // If AJAX request, return JSON with updated values
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // Remove any previous accidental output (whitespace, BOM, includes)
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Stock quantities updated successfully!',
                'updated' => $updatedItems
            ]);
            exit;
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = '<div class="alert error">DB Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'DB Error: ' . $e->getMessage()
            ]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = '<div class="alert error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Perbarui Stok - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
</head>

<body>
    <!-- Copy your navigation code here -->
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Perbarui Jumlah Stok</h2>
            <p class="note">Catatan : <br> Data yang ditambahkan hanya untuk barang yang masih tersegel dan tidak berkarat.</p>
        </div>

        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <div class="table-container">
            <div class="filter-row">
                <label for="category-filter">Filter Kategori:</label>
                <select id="category-filter">
                    <option value="">-- Semua Kategori --</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="filter-count"></span>
            </div>
            <form method="POST" action="" id="update-stock-form">
                <table>
                    <thead>
                        <tr>
                            <th>Nama Barang</th>
                            <th>Kategori</th>
                            <th>Stok</th>
                            <th>Level (cm)</th>
                            <?php if (! $isFieldUser): ?>
                                <th>Stok Total</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $totalStock = $item['field_stock'];
                        ?>
                            <tr data-item-id="<?php echo $item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                                <td data-label="Nama Barang">
                                    <div class="item-name-wrap">
                                        <span><?php echo htmlspecialchars($item['name']); ?></span>
                                        <span class="row-dirty-indicator" aria-live="polite" role="status">
                                            <span class="row-dirty-text">Belum disimpan</span>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Kategori"><?php echo htmlspecialchars($item['category']); ?></td>
                                <td data-label="Stok">
                                    <div class="quantity-control">
                                        <button type="button" class="btn-qty" onclick="decrementQty('field_<?php echo $item['id']; ?>')">
                                            <i class='bx bx-minus'></i>
                                        </button>
                                        <input
                                            type="number"
                                            id="field_<?php echo $item['id']; ?>"
                                            name="field_stock[<?php echo $item['id']; ?>]"
                                            value="<?php echo htmlspecialchars($item['field_stock']); ?>"
                                            data-original-value="<?php echo htmlspecialchars($item['field_stock']); ?>"
                                            min="0"
                                            required>
                                        <button type="button" class="btn-qty" onclick="incrementQty('field_<?php echo $item['id']; ?>')">
                                            <i class='bx bx-plus'></i>
                                        </button>
                                    </div>
                                </td>
                                <td data-label="Level (cm)">
                                    <?php if (isset($item['has_level']) && (int)$item['has_level'] === 1): ?>
                                        <input type="number" id="level_<?php echo $item['id']; ?>" name="level[<?php echo $item['id']; ?>]" value="<?php echo isset($item['level']) && $item['level'] !== null ? (int)$item['level'] : ''; ?>" data-original-value="<?php echo isset($item['level']) && $item['level'] !== null ? (int)$item['level'] : ''; ?>" min="0" step="1">
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <?php if (! $isFieldUser): ?>
                                    <td data-label="Stok Total">
                                        <span id="total_<?php echo $item['id']; ?>"><?php echo ($isFieldUser ? $item['field_stock'] : $totalStock); ?></span>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="batch-save-sticky" role="status" aria-live="polite">
                    <div class="batch-save-summary" id="batch-save-summary">
                        0 item berubah • 0 field diubah
                    </div>
                    <button type="submit" class="btn-submit batch-save-btn" id="batch-save-btn" disabled data-default-text="Simpan Semua Perubahan (0)">
                        Simpan Semua Perubahan (0)
                    </button>
                </div>
                <noscript>
                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Simpan Semua Perubahan</button>
                    </div>
                </noscript>
            </form>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion(); ?>"></script>
</body>

</html>