<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow only office and admin to manage items
requireRole(['office', 'admin']);

$message = '';
$units = getUnits();

$itemsHasLevelFlag = db_has_column('items', 'has_level');
$itemsHasLevelValue = db_has_column('items', 'level');
$itemsHasWarehouseStock = db_has_column('items', 'warehouse_stock');
$itemsHasCalculationType = db_has_column('items', 'calculation_type');
$histHasLevel = db_has_column('item_stock_history', 'level');
$histHasWarehouseOld = db_has_column('item_stock_history', 'warehouse_stock_old');
$histHasWarehouseNew = db_has_column('item_stock_history', 'warehouse_stock_new');

$hasLevelSelect = $itemsHasLevelFlag ? ', i.has_level' : '';
$levelSelect = $itemsHasLevelValue ? ', i.level' : ', NULL AS level';

$modalToOpen = '';
$addItemState = [
    'name' => '',
    'category' => '',
    'field_stock' => 0,
    'unit' => '',
    'unit_conversion' => 1.0,
    'daily_consumption' => 0.0,
    'min_days_coverage' => 7,
    'description' => '',
    'has_level' => 0,
    'level' => '',
];
$editItemState = [
    'item_id' => '',
    'name' => '',
    'category' => '',
    'field_stock' => 0,
    'unit' => '',
    'unit_conversion' => 1.0,
    'daily_consumption' => 0.0,
    'min_days_coverage' => 7,
    'description' => '',
    'has_level' => 0,
    'level' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string)$_POST['action']);

    try {
        if ($action !== 'create_item' && $action !== 'update_item') {
            throw new Exception('Aksi tidak dikenali.');
        }

        $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $categoryInput = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
        $fieldStock = isset($_POST['field_stock']) && is_numeric($_POST['field_stock']) ? (int)$_POST['field_stock'] : 0;
        $unit = isset($_POST['unit']) ? trim((string)$_POST['unit']) : '';
        $unitConversion = isset($_POST['unit_conversion']) && is_numeric($_POST['unit_conversion']) ? round((float)$_POST['unit_conversion'], 1) : 1.0;
        $dailyConsumption = isset($_POST['daily_consumption']) && is_numeric($_POST['daily_consumption']) ? round((float)$_POST['daily_consumption'], 1) : 0.0;
        $minDaysCoverage = isset($_POST['min_days_coverage']) && is_numeric($_POST['min_days_coverage']) ? (int)$_POST['min_days_coverage'] : 1;
        $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $hasLevel = $itemsHasLevelFlag ? (isset($_POST['has_level']) ? 1 : 0) : 0;
        $levelInput = isset($_POST['level']) ? trim((string)$_POST['level']) : '';
        $levelVal = null;

        if ($action === 'create_item') {
            $addItemState = [
                'name' => $name,
                'category' => $categoryInput,
                'field_stock' => $fieldStock,
                'unit' => $unit,
                'unit_conversion' => $unitConversion,
                'daily_consumption' => $dailyConsumption,
                'min_days_coverage' => $minDaysCoverage,
                'description' => $description,
                'has_level' => $hasLevel,
                'level' => $levelInput,
            ];
            $modalToOpen = 'add-item-modal';
        } else {
            $editItemState = [
                'item_id' => (string)$itemId,
                'name' => $name,
                'category' => $categoryInput,
                'field_stock' => $fieldStock,
                'unit' => $unit,
                'unit_conversion' => $unitConversion,
                'daily_consumption' => $dailyConsumption,
                'min_days_coverage' => $minDaysCoverage,
                'description' => $description,
                'has_level' => $hasLevel,
                'level' => $levelInput,
            ];
            $modalToOpen = 'edit-item-modal';
        }

        $validationErrors = [];

        if ($name === '') {
            $validationErrors[] = 'Nama barang harus diisi.';
        }
        if ($categoryInput === '') {
            $validationErrors[] = 'Kategori harus diisi.';
        }
        if ($unit === '') {
            $validationErrors[] = 'Satuan harus dipilih.';
        }
        if ($fieldStock < 0) {
            $validationErrors[] = 'Stok tidak boleh negatif.';
        }
        if ($unitConversion <= 0) {
            $validationErrors[] = 'Faktor konversi harus lebih dari 0.';
        }
        if ($dailyConsumption < 0) {
            $validationErrors[] = 'Konsumsi harian tidak boleh negatif.';
        }
        if ($minDaysCoverage < 1) {
            $validationErrors[] = 'Minimum periode minimal 1 hari.';
        }

        if ($itemsHasLevelFlag && $hasLevel) {
            if ($levelInput === '') {
                $validationErrors[] = 'Level wajib diisi saat mode level aktif.';
            } elseif (!ctype_digit($levelInput)) {
                $validationErrors[] = 'Level tidak valid. Masukkan angka bulat (cm).';
            } else {
                $levelVal = (int)$levelInput;
            }
        }

        if (!empty($validationErrors)) {
            $message = '<div class="alert error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $validationErrors)) . '</li></ul></div>';
            throw new Exception('__validation_stop__');
        }

        $warehouseStock = 0;
        $totalStockNew = ($fieldStock + $warehouseStock) * $unitConversion;

        if ($action === 'create_item') {
            $daysCoverageNew = calculateDaysCoverage(
                $fieldStock,
                0,
                $unitConversion,
                $dailyConsumption,
                $name,
                $levelVal,
                (bool)$hasLevel,
                [
                    'category' => $categoryInput,
                    'min_days_coverage' => $minDaysCoverage
                ]
            );
            $statusNew = determineStatus($daysCoverageNew, $minDaysCoverage);

            $resolvedDaily = resolveDailyConsumption($dailyConsumption, [
                'category' => $categoryInput,
                'effective_stock' => $totalStockNew,
                'min_days_coverage' => $minDaysCoverage
            ]);

            $pdo->beginTransaction();

            $columns = [
                'name',
                'category',
                'field_stock',
                'unit',
                'unit_conversion',
                'daily_consumption',
                'min_days_coverage',
                'description',
                'added_by',
                'status'
            ];
            $placeholders = [
                ':name',
                ':category',
                ':field_stock',
                ':unit',
                ':unit_conversion',
                ':daily_consumption',
                ':min_days_coverage',
                ':description',
                ':added_by',
                ':status'
            ];

            if ($itemsHasWarehouseStock) {
                array_splice($columns, 3, 0, 'warehouse_stock');
                array_splice($placeholders, 3, 0, ':warehouse_stock');
            }
            if ($itemsHasCalculationType) {
                $insertPos = count($columns) - 1;
                array_splice($columns, $insertPos, 0, 'calculation_type');
                array_splice($placeholders, $insertPos, 0, ':calculation_type');
            }
            if ($itemsHasLevelValue) {
                array_splice($columns, 8, 0, 'level');
                array_splice($placeholders, 8, 0, ':level');
            }
            if ($itemsHasLevelFlag) {
                array_splice($columns, 8, 0, 'has_level');
                array_splice($placeholders, 8, 0, ':has_level');
            }

            $stmt = $pdo->prepare('INSERT INTO items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
            $params = [
                ':name' => $name,
                ':category' => $categoryInput,
                ':field_stock' => $fieldStock,
                ':unit' => $unit,
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':description' => $description,
                ':added_by' => $_SESSION['user_id'],
                ':status' => $statusNew,
            ];

            if ($itemsHasWarehouseStock) {
                $params[':warehouse_stock'] = $warehouseStock;
            }
            if ($itemsHasCalculationType) {
                $params[':calculation_type'] = 'daily_consumption';
            }
            if ($itemsHasLevelValue) {
                $params[':level'] = $levelVal;
            }
            if ($itemsHasLevelFlag) {
                $params[':has_level'] = $hasLevel;
            }

            $stmt->execute($params);
            $newItemId = (int)$pdo->lastInsertId();

            $histColumns = [
                'item_id',
                'item_name',
                'category',
                'action',
                'field_stock_old',
                'field_stock_new',
                'status_old',
                'status_new',
                'total_stock_old',
                'total_stock_new',
                'days_coverage_old',
                'days_coverage_new',
                'unit',
                'unit_conversion',
                'daily_consumption',
                'min_days_coverage',
                'changed_by',
                'note'
            ];
            $histValues = [
                ':item_id',
                ':item_name',
                ':category',
                ':action',
                'NULL',
                ':field_stock_new',
                'NULL',
                ':status_new',
                'NULL',
                ':total_stock_new',
                'NULL',
                ':days_coverage_new',
                ':unit',
                ':unit_conversion',
                ':daily_consumption',
                ':min_days_coverage',
                ':changed_by',
                ':note'
            ];

            if ($histHasWarehouseOld) {
                array_splice($histColumns, 6, 0, 'warehouse_stock_old');
                array_splice($histValues, 6, 0, 'NULL');
            }
            if ($histHasWarehouseNew) {
                $insertPos = $histHasWarehouseOld ? 7 : 6;
                array_splice($histColumns, $insertPos, 0, 'warehouse_stock_new');
                array_splice($histValues, $insertPos, 0, ':warehouse_stock_new');
            }
            if ($histHasLevel) {
                array_splice($histColumns, 15, 0, 'level');
                array_splice($histValues, 15, 0, ':level');
            }

            $histStmt = $pdo->prepare('INSERT INTO item_stock_history (' . implode(', ', $histColumns) . ') VALUES (' . implode(', ', $histValues) . ')');
            $histParams = [
                ':item_id' => $newItemId,
                ':item_name' => $name,
                ':category' => $categoryInput,
                ':action' => 'insert',
                ':field_stock_new' => $fieldStock,
                ':status_new' => $statusNew,
                ':total_stock_new' => $totalStockNew,
                ':days_coverage_new' => $daysCoverageNew,
                ':unit' => $unit,
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':changed_by' => $_SESSION['user_id'],
                ':note' => 'initial insert (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')',
            ];

            if ($histHasWarehouseNew) {
                $histParams[':warehouse_stock_new'] = $warehouseStock;
            }
            if ($histHasLevel) {
                $histParams[':level'] = $levelVal;
            }

            $histStmt->execute($histParams);
            $pdo->commit();

            $message = '<div class="alert success">Barang berhasil ditambahkan.</div>';
            $modalToOpen = '';
        } else {
            if ($itemId <= 0) {
                throw new Exception('ID barang tidak valid.');
            }

            $stmtOld = $pdo->prepare('SELECT * FROM items WHERE id = ? AND ' . activeItemsWhereSql());
            $stmtOld->execute([$itemId]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new Exception('Barang tidak ditemukan atau sudah diarsipkan.');
            }

            $pdo->beginTransaction();

            $newTotal = ($fieldStock) * $unitConversion;
            $newDays = calculateDaysCoverage(
                $fieldStock,
                0,
                $unitConversion,
                $dailyConsumption,
                $name,
                $levelVal,
                (bool)$hasLevel,
                [
                    'item_id' => $itemId,
                    'category' => $categoryInput,
                    'min_days_coverage' => $minDaysCoverage
                ]
            );
            $resolvedDaily = resolveDailyConsumption($dailyConsumption, [
                'item_id' => $itemId,
                'category' => $categoryInput,
                'effective_stock' => $newTotal,
                'min_days_coverage' => $minDaysCoverage
            ]);
            $newStatus = determineStatus($newDays, $minDaysCoverage);

            $set = [
                'name = :name',
                'category = :category',
                'field_stock = :field_stock',
                'unit = :unit',
                'unit_conversion = :unit_conversion',
                'daily_consumption = :daily_consumption',
                'min_days_coverage = :min_days_coverage',
                'description = :description',
                'updated_by = :updated_by',
                'status = :status'
            ];
            if ($itemsHasLevelValue) {
                $set[] = 'level = :level';
            }
            if ($itemsHasLevelFlag) {
                $set[] = 'has_level = :has_level';
            }

            $stmtUpdate = $pdo->prepare('UPDATE items SET ' . implode(', ', $set) . ' WHERE id = :id AND ' . activeItemsWhereSql());
            $updateParams = [
                ':name' => $name,
                ':category' => $categoryInput,
                ':field_stock' => $fieldStock,
                ':unit' => $unit,
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':description' => $description,
                ':updated_by' => $_SESSION['user_id'],
                ':status' => $newStatus,
                ':id' => $itemId,
            ];
            if ($itemsHasLevelValue) {
                $updateParams[':level'] = $levelVal;
            }
            if ($itemsHasLevelFlag) {
                $updateParams[':has_level'] = $hasLevel;
            }

            $stmtUpdate->execute($updateParams);

            $oldTotal = ((float)($old['field_stock'] ?? 0)) * ((float)($old['unit_conversion'] ?? 1));
            $oldHasLevel = isset($old['has_level']) ? (bool)$old['has_level'] : false;
            $oldDays = calculateDaysCoverage(
                (float)($old['field_stock'] ?? 0),
                0,
                (float)($old['unit_conversion'] ?? 1),
                (float)($old['daily_consumption'] ?? 0),
                (string)($old['name'] ?? ''),
                array_key_exists('level', $old) ? $old['level'] : null,
                $oldHasLevel,
                [
                    'item_id' => $itemId,
                    'category' => (string)($old['category'] ?? ''),
                    'min_days_coverage' => (int)($old['min_days_coverage'] ?? 1)
                ]
            );

            $histCols = [
                'item_id',
                'item_name',
                'category',
                'action',
                'field_stock_old',
                'field_stock_new',
                'status_old',
                'status_new',
                'total_stock_old',
                'total_stock_new',
                'days_coverage_old',
                'days_coverage_new',
                'unit',
                'unit_conversion',
                'daily_consumption',
                'min_days_coverage',
                'changed_by',
                'note'
            ];
            $histVals = [
                ':item_id',
                ':item_name',
                ':category',
                ':action',
                ':field_stock_old',
                ':field_stock_new',
                ':status_old',
                ':status_new',
                ':total_stock_old',
                ':total_stock_new',
                ':days_coverage_old',
                ':days_coverage_new',
                ':unit',
                ':unit_conversion',
                ':daily_consumption',
                ':min_days_coverage',
                ':changed_by',
                ':note'
            ];

            if ($histHasWarehouseOld) {
                array_splice($histCols, 6, 0, 'warehouse_stock_old');
                array_splice($histVals, 6, 0, ':warehouse_stock_old');
            }
            if ($histHasWarehouseNew) {
                $insertPos = $histHasWarehouseOld ? 7 : 6;
                array_splice($histCols, $insertPos, 0, 'warehouse_stock_new');
                array_splice($histVals, $insertPos, 0, ':warehouse_stock_new');
            }
            if ($histHasLevel) {
                array_splice($histCols, 15, 0, 'level');
                array_splice($histVals, 15, 0, ':level');
            }

            $histStmt = $pdo->prepare('INSERT INTO item_stock_history (' . implode(', ', $histCols) . ') VALUES (' . implode(', ', $histVals) . ')');
            $histParams = [
                ':item_id' => $itemId,
                ':item_name' => $name,
                ':category' => $categoryInput,
                ':action' => 'update',
                ':field_stock_old' => (float)($old['field_stock'] ?? 0),
                ':field_stock_new' => $fieldStock,
                ':status_old' => (string)($old['status'] ?? ''),
                ':status_new' => $newStatus,
                ':total_stock_old' => $oldTotal,
                ':total_stock_new' => $newTotal,
                ':days_coverage_old' => $oldDays,
                ':days_coverage_new' => $newDays,
                ':unit' => $unit,
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':changed_by' => $_SESSION['user_id'],
                ':note' => 'updated via manage-items modal (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')',
            ];

            if ($histHasWarehouseOld) {
                $histParams[':warehouse_stock_old'] = 0;
            }
            if ($histHasWarehouseNew) {
                $histParams[':warehouse_stock_new'] = 0;
            }
            if ($histHasLevel) {
                $histParams[':level'] = $levelVal;
            }

            $histStmt->execute($histParams);
            $pdo->commit();

            $message = '<div class="alert success">Barang berhasil diperbarui.</div>';
            $modalToOpen = '';
        }
    } catch (Exception $e) {
        if ($e->getMessage() !== '__validation_stop__') {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = '<div class="alert error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Handle item deletion (hardened)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item']) && isset($_POST['item_id'])) {
    $itemId = (int) $_POST['item_id'];
    if ($itemId <= 0) {
        $message = '<div class="alert error">ID barang tidak valid.</div>';
    } else {
        try {
            if (!itemsSoftDeleteEnabled()) {
                throw new Exception('Soft-delete belum aktif. Jalankan migrasi database terlebih dahulu.');
            }

            if (db_has_column('items', 'deleted_by')) {
                $stmt = $pdo->prepare("UPDATE items SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE id = ? AND " . activeItemsWhereSql());
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $itemId]);
            } else {
                $stmt = $pdo->prepare("UPDATE items SET deleted_at = NOW(), updated_by = ? WHERE id = ? AND " . activeItemsWhereSql());
                $stmt->execute([$_SESSION['user_id'], $itemId]);
            }
            if ($stmt->rowCount() > 0) {
                $message = '<div class="alert success">Barang berhasil diarsipkan!</div>';
            } else {
                $message = '<div class="alert error">Barang tidak ditemukan atau sudah diarsipkan.</div>';
            }
        } catch (Exception $e) {
            $message = '<div class="alert error">Gagal mengarsipkan barang: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Initialize filters
$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

// Get sort parameter (default to 'last_updated' if not specified)
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'last_updated';
$sortDir = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'ASC' : 'DESC';

// Valid sort columns to prevent SQL injection
$validSortColumns = ['name', 'category', 'last_updated']; // Tetap sertakan last_updated

// Verify sort parameter is valid
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'last_updated'; // Default fallback
}

$query = "SELECT 
            i.id, i.name, i.category, i.field_stock, i.unit, i.unit_conversion, i.daily_consumption, i.min_days_coverage, i.description{$levelSelect}, i.status, i.last_updated, i.added_by as added_by_id, i.updated_by as updated_by_id{$hasLevelSelect},
            u.username AS added_by_name, u2.username AS updated_by_name
        FROM items i
        LEFT JOIN users u ON i.added_by = u.id
        LEFT JOIN users u2 ON i.updated_by = u2.id
    WHERE " . activeItemsWhereSql('i');
$params = [];

if ($category) {
    $query .= " AND i.category = ?";
    $params[] = $category;
}

if ($status) {
    $query .= " AND i.status = ?";
    $params[] = $status;
}

if ($search) {
    $query .= " AND i.name LIKE ?";
    $params[] = "%$search%";
}

// PRIMARY SORT
$query .= " ORDER BY i.{$sortBy} {$sortDir}";

// SECONDARY SORT: if sorting by something other than name and it's not last_updated, add name as secondary sort
if ($sortBy !== 'name' && $sortBy !== 'last_updated') {
    $query .= ", i.name ASC";
}

// Execute query with error handling
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get categories for filter
    $categories = $pdo->query("SELECT DISTINCT category FROM items WHERE " . activeItemsWhereSql() . " ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $items = [];
    $categories = [];
    $message = '<div class="alert error">DB error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <title>Kelola Barang - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
    <style>
        .items-header-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .btn-add-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            padding: 10px 14px;
            background-color: var(--fern-green);
            color: #fff;
            font-weight: 600;
        }

        .btn-add-item:hover {
            background-color: var(--hunter-green);
        }

        .item-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .item-modal-overlay.show {
            display: flex;
        }

        .item-modal {
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
        }

        .item-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #eee;
        }

        .item-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
        }

        .item-modal-close {
            border: none;
            background: transparent;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .item-modal-body {
            padding: 16px;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Kelola Barang</h2>
        </div>

        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <div class="items-header-actions">
            <button type="button" class="btn-add-item" onclick="openAddItemModal()">
                <i class='bx bx-plus'></i> Tambah Barang
            </button>
        </div>

        <div class="table-container">
            <div class="table-header">
                <form method="GET" class="table-filters" data-filter-context="manage" data-default-sort="last_updated" data-default-dir="desc">
                    <div class="search-box">
                        <i class='bx bx-search'></i>
                        <input type="text" name="search" id="search-input" placeholder="Cari barang..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                        <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="Hapus pencarian">&times;</button>
                        <div id="autocomplete-list" class="autocomplete-items"></div>
                    </div>
                    <div class="filter-group">
                        <select name="category">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="">Semua Status</option>
                            <option value="in-stock" <?php echo $status === 'in-stock' ? 'selected' : ''; ?>><?php echo translateStatus('in-stock', 'id'); ?></option>
                            <option value="low-stock" <?php echo $status === 'low-stock' ? 'selected' : ''; ?>><?php echo translateStatus('low-stock', 'id'); ?></option>
                            <option value="warning-stock" <?php echo $status === 'warning-stock' ? 'selected' : ''; ?>><?php echo translateStatus('warning-stock', 'id'); ?></option>
                            <option value="out-stock" <?php echo $status === 'out-stock' ? 'selected' : ''; ?>><?php echo translateStatus('out-stock', 'id'); ?></option>
                        </select>
                        <button type="submit" class="btn-filter">Terapkan Filter</button>
                    </div>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>
                            <a href="?search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&sort=name&dir=<?= ($sortBy === 'name' && $sortDir === 'DESC') ? 'asc' : 'desc' ?>" class="th-sort">
                                Nama Barang
                                <?php if ($sortBy === 'name'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&sort=category&dir=<?= ($sortBy === 'category' && $sortDir === 'DESC') ? 'asc' : 'desc' ?>" class="th-sort">
                                Kategori
                                <?php if ($sortBy === 'category'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Stok</th>
                        <th>Pemakaian Harian</th>
                        <th>Level (cm)</th>
                        <th>Ketahanan di lapangan</th>
                        <th>Status</th>
                        <th>Terakhir Diperbarui</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        // Normalize fields
                        $id = isset($item['id']) ? (int)$item['id'] : 0;
                        $name = isset($item['name']) ? (string)$item['name'] : '';
                        $category = isset($item['category']) ? (string)$item['category'] : '';
                        $field_stock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
                        $unit_conversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
                        $daily_consumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
                        $level = array_key_exists('level', $item) ? $item['level'] : null;
                        $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;
                        $status = isset($item['status']) ? (string)$item['status'] : '';

                        $daysCoverage = calculateDaysCoverage(
                            $field_stock,
                            0,
                            $unit_conversion,
                            $daily_consumption,
                            $name,
                            $level,
                            $hasLevel,
                            [
                                'item_id' => $id,
                                'category' => $category,
                                'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
                            ]
                        );

                        $resolvedDaily = resolveDailyConsumption($daily_consumption, [
                            'item_id' => $id,
                            'category' => $category,
                            'effective_stock' => ($field_stock * $unit_conversion),
                            'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
                        ]);
                    ?>
                        <tr data-item-id="<?php echo $id; ?>">
                            <td data-label="Nama Barang"><?php echo htmlspecialchars($name); ?></td>
                            <td data-label="Kategori"><?php echo htmlspecialchars($category); ?></td>
                            <td data-label="Stok"><?php echo number_format((int)$field_stock); ?></td>
                            <td data-label="Pemakaian Harian"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                            <td data-label="Level (cm)"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                            <td data-label="Katahanan"><?php echo number_format((int)$daysCoverage); ?> hari</td>
                            <td data-label="Status"><span class="status <?php echo htmlspecialchars($status); ?>"><?php echo translateStatus($status, 'id'); ?></span></td>
                            <td data-label="Terakhir Diperbarui" class="last-login">
                                <?php if (!empty($item['last_updated'])): ?>
                                    <span class="timestamp">
                                        <i class='bx bx-time-five'></i>
                                        <?php echo htmlspecialchars($item['last_updated']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="never-login">
                                        <i class='bx bx-x-circle'></i>
                                        Tidak Pernah
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="actions">
                                <div class="actions-inline">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-item-id="<?php echo $id; ?>"
                                        data-name="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES); ?>"
                                        data-category="<?php echo htmlspecialchars((string)$item['category'], ENT_QUOTES); ?>"
                                        data-field-stock="<?php echo (int)$item['field_stock']; ?>"
                                        data-unit="<?php echo htmlspecialchars((string)$item['unit'], ENT_QUOTES); ?>"
                                        data-unit-conversion="<?php echo number_format((float)$item['unit_conversion'], 1, '.', ''); ?>"
                                        data-daily-consumption="<?php echo number_format((float)$item['daily_consumption'], 1, '.', ''); ?>"
                                        data-min-days-coverage="<?php echo (int)$item['min_days_coverage']; ?>"
                                        data-description="<?php echo htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES); ?>"
                                        data-has-level="<?php echo (isset($item['has_level']) && (int)$item['has_level'] === 1) ? '1' : '0'; ?>"
                                        data-level="<?php echo isset($item['level']) ? (int)$item['level'] : ''; ?>"
                                        onclick="openEditItemModal(this)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    <form class="action-form" method="POST" onsubmit="return false;">
                                        <input type="hidden" name="item_id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="delete_item" value="1">
                                        <button type="button" class="btn-delete confirm-delete">
                                            <i class='bx bx-trash'></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="9" class="no-data">Tidak ada barang</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="item-modal-overlay" id="add-item-modal" onclick="closeItemModalOnBackdrop(event, 'add-item-modal')">
            <div class="item-modal" role="dialog" aria-modal="true" aria-labelledby="add-item-modal-title">
                <div class="item-modal-header">
                    <h3 id="add-item-modal-title">Tambah Barang</h3>
                    <button type="button" class="item-modal-close" onclick="closeItemModal('add-item-modal')">&times;</button>
                </div>
                <div class="item-modal-body">
                    <form method="POST" class="add-form" id="add-item-form">
                        <input type="hidden" name="action" value="create_item">

                        <div class="form-group">
                            <label for="add_name">Nama Barang</label>
                            <input type="text" id="add_name" name="name" value="<?php echo htmlspecialchars((string)$addItemState['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_category">Kategori</label>
                            <select id="add_category" name="category" required>
                                <option value="">Pilih Kategori</option>
                                <option value="Chemical" <?php echo $addItemState['category'] === 'Chemical' ? 'selected' : ''; ?>>Chemical</option>
                                <option value="Lube Oil" <?php echo $addItemState['category'] === 'Lube Oil' ? 'selected' : ''; ?>>Lube Oil</option>
                                <option value="Toothbelts" <?php echo $addItemState['category'] === 'Toothbelts' ? 'selected' : ''; ?>>Toothbelts</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add_field_stock">Stok</label>
                            <input type="number" id="add_field_stock" name="field_stock" min="0" value="<?php echo (int)$addItemState['field_stock']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_unit">Satuan</label>
                            <select id="add_unit" name="unit" required>
                                <?php if (empty($units)): ?>
                                    <option value="" disabled selected>Belum ada kategori unit</option>
                                <?php else: ?>
                                    <?php foreach ($units as $unitOption): ?>
                                        <option value="<?php echo htmlspecialchars($unitOption['value']); ?>" <?php echo $addItemState['unit'] === $unitOption['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unitOption['label']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add_unit_conversion">Faktor Konversi Satuan</label>
                            <input type="number" id="add_unit_conversion" name="unit_conversion" min="0.1" step="0.1" value="<?php echo number_format((float)$addItemState['unit_conversion'], 1, '.', ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_daily_consumption">Konsumsi Harian</label>
                            <input type="number" id="add_daily_consumption" name="daily_consumption" min="0" step="0.1" value="<?php echo number_format((float)$addItemState['daily_consumption'], 1, '.', ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_min_days_coverage">Minimum Periode (hari)</label>
                            <input type="number" id="add_min_days_coverage" name="min_days_coverage" min="1" value="<?php echo (int)$addItemState['min_days_coverage']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_description">Keterangan</label>
                            <textarea id="add_description" name="description" rows="3"><?php echo htmlspecialchars((string)$addItemState['description']); ?></textarea>
                        </div>

                        <?php if ($itemsHasLevelFlag): ?>
                            <div class="form-group">
                                <label for="add_has_level">
                                    <input type="checkbox" id="add_has_level" name="has_level" value="1" <?php echo (int)$addItemState['has_level'] === 1 ? 'checked' : ''; ?>>
                                    Gunakan indikator level untuk kalkulasi ketahanan
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="form-group" id="add-level-group" style="<?php echo (int)$addItemState['has_level'] === 1 ? '' : 'display:none;'; ?>">
                            <label for="add_level">Level (cm)</label>
                            <input type="number" id="add_level" name="level" min="0" step="1" value="<?php echo htmlspecialchars((string)$addItemState['level']); ?>">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Barang</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="item-modal-overlay" id="edit-item-modal" onclick="closeItemModalOnBackdrop(event, 'edit-item-modal')">
            <div class="item-modal" role="dialog" aria-modal="true" aria-labelledby="edit-item-modal-title">
                <div class="item-modal-header">
                    <h3 id="edit-item-modal-title">Edit Barang</h3>
                    <button type="button" class="item-modal-close" onclick="closeItemModal('edit-item-modal')">&times;</button>
                </div>
                <div class="item-modal-body">
                    <form method="POST" class="add-form" id="edit-item-form">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" id="edit_item_id" name="item_id" value="<?php echo htmlspecialchars((string)$editItemState['item_id']); ?>">

                        <div class="form-group">
                            <label for="edit_name">Nama Barang</label>
                            <input type="text" id="edit_name" name="name" value="<?php echo htmlspecialchars((string)$editItemState['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_category">Kategori</label>
                            <select id="edit_category" name="category" required>
                                <option value="Chemical" <?php echo $editItemState['category'] === 'Chemical' ? 'selected' : ''; ?>>Chemical</option>
                                <option value="Lube Oil" <?php echo $editItemState['category'] === 'Lube Oil' ? 'selected' : ''; ?>>Lube Oil</option>
                                <option value="Toothbelts" <?php echo $editItemState['category'] === 'Toothbelts' ? 'selected' : ''; ?>>Toothbelts</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_field_stock">Stok</label>
                            <input type="number" id="edit_field_stock" name="field_stock" min="0" value="<?php echo (int)$editItemState['field_stock']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_unit">Satuan</label>
                            <select id="edit_unit" name="unit" required>
                                <?php if (empty($units)): ?>
                                    <option value="" disabled selected>Belum ada kategori unit</option>
                                <?php else: ?>
                                    <?php foreach ($units as $unitOption): ?>
                                        <option value="<?php echo htmlspecialchars($unitOption['value']); ?>" <?php echo $editItemState['unit'] === $unitOption['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unitOption['label']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_unit_conversion">Faktor Konversi Satuan</label>
                            <input type="number" id="edit_unit_conversion" name="unit_conversion" min="0.1" step="0.1" value="<?php echo number_format((float)$editItemState['unit_conversion'], 1, '.', ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_daily_consumption">Konsumsi Harian</label>
                            <input type="number" id="edit_daily_consumption" name="daily_consumption" min="0" step="0.1" value="<?php echo number_format((float)$editItemState['daily_consumption'], 1, '.', ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_min_days_coverage">Minimum Periode (hari)</label>
                            <input type="number" id="edit_min_days_coverage" name="min_days_coverage" min="1" value="<?php echo (int)$editItemState['min_days_coverage']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_description">Keterangan</label>
                            <textarea id="edit_description" name="description" rows="3"><?php echo htmlspecialchars((string)$editItemState['description']); ?></textarea>
                        </div>

                        <?php if ($itemsHasLevelFlag): ?>
                            <div class="form-group">
                                <label for="edit_has_level">
                                    <input type="checkbox" id="edit_has_level" name="has_level" value="1" <?php echo (int)$editItemState['has_level'] === 1 ? 'checked' : ''; ?>>
                                    Gunakan indikator level untuk kalkulasi ketahanan
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="form-group" id="edit-level-group" style="<?php echo (int)$editItemState['has_level'] === 1 ? '' : 'display:none;'; ?>">
                            <label for="edit_level">Level (cm)</label>
                            <input type="number" id="edit_level" name="level" min="0" step="1" value="<?php echo htmlspecialchars((string)$editItemState['level']); ?>">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script>
        function openAddItemModal() {
            const form = document.getElementById('add-item-form');
            if (form) {
                form.reset();
                const addFieldStock = document.getElementById('add_field_stock');
                const addUnitConv = document.getElementById('add_unit_conversion');
                const addDaily = document.getElementById('add_daily_consumption');
                const addMinDays = document.getElementById('add_min_days_coverage');
                if (addFieldStock) addFieldStock.value = 0;
                if (addUnitConv) addUnitConv.value = '1.0';
                if (addDaily) addDaily.value = '0.0';
                if (addMinDays) addMinDays.value = 7;
            }
            updateLevelGroup('add_has_level', 'add-level-group');
            closeItemModal('edit-item-modal');
            const modal = document.getElementById('add-item-modal');
            if (modal) modal.classList.add('show');
        }

        function openEditItemModal(button) {
            if (!button) return;

            document.getElementById('edit_item_id').value = button.dataset.itemId || '';
            document.getElementById('edit_name').value = button.dataset.name || '';
            document.getElementById('edit_category').value = button.dataset.category || '';
            document.getElementById('edit_field_stock').value = button.dataset.fieldStock || 0;
            document.getElementById('edit_unit').value = button.dataset.unit || '';
            document.getElementById('edit_unit_conversion').value = button.dataset.unitConversion || '1.0';
            document.getElementById('edit_daily_consumption').value = button.dataset.dailyConsumption || '0.0';
            document.getElementById('edit_min_days_coverage').value = button.dataset.minDaysCoverage || 1;
            document.getElementById('edit_description').value = button.dataset.description || '';

            const editHasLevel = document.getElementById('edit_has_level');
            const editLevel = document.getElementById('edit_level');
            if (editHasLevel) {
                editHasLevel.checked = (button.dataset.hasLevel || '0') === '1';
            }
            if (editLevel) {
                editLevel.value = button.dataset.level || '';
            }
            updateLevelGroup('edit_has_level', 'edit-level-group');

            closeItemModal('add-item-modal');
            const modal = document.getElementById('edit-item-modal');
            if (modal) modal.classList.add('show');
        }

        function closeItemModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.remove('show');
        }

        function closeItemModalOnBackdrop(event, modalId) {
            if (event.target && event.target.id === modalId) {
                closeItemModal(modalId);
            }
        }

        function updateLevelGroup(checkboxId, groupId) {
            const checkbox = document.getElementById(checkboxId);
            const group = document.getElementById(groupId);
            if (!group) return;
            if (!checkbox) {
                group.style.display = 'none';
                return;
            }
            group.style.display = checkbox.checked ? 'block' : 'none';
        }

        const addHasLevel = document.getElementById('add_has_level');
        if (addHasLevel) {
            addHasLevel.addEventListener('change', function() {
                updateLevelGroup('add_has_level', 'add-level-group');
            });
        }

        const editHasLevel = document.getElementById('edit_has_level');
        if (editHasLevel) {
            editHasLevel.addEventListener('change', function() {
                updateLevelGroup('edit_has_level', 'edit-level-group');
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeItemModal('add-item-modal');
                closeItemModal('edit-item-modal');
            }
        });

        (function() {
            updateLevelGroup('add_has_level', 'add-level-group');
            updateLevelGroup('edit_has_level', 'edit-level-group');
            const modalToOpen = <?php echo json_encode($modalToOpen); ?>;
            if (modalToOpen === 'add-item-modal' || modalToOpen === 'edit-item-modal') {
                const modal = document.getElementById(modalToOpen);
                if (modal) {
                    modal.classList.add('show');
                }
            }
        })();
    </script>
</body>

</html>