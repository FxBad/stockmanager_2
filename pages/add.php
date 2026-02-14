<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow only office and admin to add items
requireRole(['office', 'admin']);

// Fetch units from database
$units = getUnits();
$hasLevelColumn = db_has_column('items', 'has_level');

$message = '';
// Collect validation errors
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Normalize and validate inputs
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $category = isset($_POST['category']) ? trim($_POST['category']) : '';

        if ($name === '') {
            $errors[] = 'Nama barang harus diisi.';
        }

        $hasLevel = $hasLevelColumn ? (isset($_POST['has_level']) ? 1 : 0) : 0;

        // Validate level: required only for level-based items
        $levelInput = isset($_POST['level']) ? trim((string)$_POST['level']) : null;
        $levelVal = null;
        if ($hasLevel && ($levelInput === null || $levelInput === '')) {
            $errors[] = 'Level wajib diisi saat indikator level diaktifkan.';
        }

        if ($levelInput !== null && $levelInput !== '') {
            if (!ctype_digit($levelInput)) {
                $errors[] = 'Level tidak valid. Masukkan angka bulat (cm).';
            } else {
                $levelVal = (int)$levelInput;
            }
        }

        if (!$hasLevel && $levelVal !== null) {
            $errors[] = 'Level hanya boleh diisi jika indikator level diaktifkan.';
        }

        $fieldStock = isset($_POST['field_stock']) && is_numeric($_POST['field_stock']) ? (int)$_POST['field_stock'] : 0;
        $warehouseStock = 0; // Warehouse stock removed from system
        $unit = isset($_POST['unit']) ? trim($_POST['unit']) : '';
        $unitConversion = isset($_POST['unit_conversion']) && is_numeric($_POST['unit_conversion']) ? round((float)$_POST['unit_conversion'], 1) : 1.0;
        $dailyConsumption = isset($_POST['daily_consumption']) && is_numeric($_POST['daily_consumption']) ? round((float)$_POST['daily_consumption'], 1) : 0.0;
        $minDaysCoverage = isset($_POST['min_days_coverage']) && is_numeric($_POST['min_days_coverage']) ? (int)$_POST['min_days_coverage'] : 1;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        // If validation failed, build message and skip DB work
        if (!empty($errors)) {
            $message = '<div class="alert error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $errors)) . '</li></ul></div>';
            // Do not attempt DB insert
        } else {
            // Cache metadata checks
            $includeLevel = db_has_column('items', 'level');
            $histHasLevel = db_has_column('item_stock_history', 'level');

            // Compute totals and coverage using existing helper
            $totalStockNew = ($fieldStock + $warehouseStock) * $unitConversion;
            $daysCoverageNew = calculateDaysCoverage(
                $fieldStock,
                $warehouseStock,
                $unitConversion,
                $dailyConsumption,
                $name,
                $levelVal,
                (bool)$hasLevel,
                [
                    'category' => $category,
                    'min_days_coverage' => $minDaysCoverage
                ]
            );
            $statusNew = determineStatus($daysCoverageNew, $minDaysCoverage);

            $resolvedDaily = resolveDailyConsumption($dailyConsumption, [
                'category' => $category,
                'effective_stock' => $totalStockNew,
                'min_days_coverage' => $minDaysCoverage
            ]);

            // Use transaction: insert item (including status), then write audit history
            $pdo->beginTransaction();

            // Build INSERT dynamically to include `level` if the items table has that column
            $includeLevel = db_has_column('items', 'level');
            $columns = [
                'name',
                'category',
                'field_stock',
                'warehouse_stock',
                'unit',
                'unit_conversion',
                'daily_consumption',
                'min_days_coverage',
                'description',
                'added_by',
                'calculation_type',
                'status'
            ];
            $placeholders = [
                ':name',
                ':category',
                ':field_stock',
                ':warehouse_stock',
                ':unit',
                ':unit_conversion',
                ':daily_consumption',
                ':min_days_coverage',
                ':description',
                ':added_by',
                "'daily_consumption'",
                ':status'
            ];

            if ($includeLevel) {
                // insert level before calculation_type for readability
                array_splice($columns, 9, 0, 'level');
                array_splice($placeholders, 9, 0, ':level');
            }
            if ($hasLevelColumn) {
                array_splice($columns, 9, 0, 'has_level');
                array_splice($placeholders, 9, 0, ':has_level');
            }

            $sql = "INSERT INTO items (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $pdo->prepare($sql);

            $params = [
                ':name' => $name,
                ':category' => $category,
                ':field_stock' => $fieldStock,
                ':warehouse_stock' => $warehouseStock,
                ':unit' => $unit,
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':description' => $description,
                ':added_by' => $_SESSION['user_id'],
                ':status' => $statusNew
            ];

            if ($includeLevel) {
                $params[':level'] = $levelVal;
            }
            if ($hasLevelColumn) {
                $params[':has_level'] = $hasLevel;
            }

            $stmt->execute($params);

            $newItemId = $pdo->lastInsertId();

            // Insert audit record for initial insert
            $histSql = 'INSERT INTO item_stock_history
            (item_id, item_name, category, action, field_stock_old, field_stock_new, warehouse_stock_old, warehouse_stock_new, status_old, status_new, total_stock_old, total_stock_new, days_coverage_old, days_coverage_new, unit, unit_conversion, daily_consumption, min_days_coverage, changed_by, note)
            VALUES
            (:item_id, :item_name, :category, :action, NULL, :field_stock_new, NULL, :warehouse_stock_new, NULL, :status_new, NULL, :total_stock_new, NULL, :days_coverage_new, :unit, :unit_conversion, :daily_consumption, :min_days_coverage, :changed_by, :note)';
            // If `level` column exists in item_stock_history, include it
            if ($histHasLevel) {
                $histSql = 'INSERT INTO item_stock_history
                (item_id, item_name, category, action, field_stock_old, field_stock_new, warehouse_stock_old, warehouse_stock_new, status_old, status_new, total_stock_old, total_stock_new, days_coverage_old, days_coverage_new, unit, unit_conversion, daily_consumption, level, min_days_coverage, changed_by, note)
                VALUES
                (:item_id, :item_name, :category, :action, NULL, :field_stock_new, NULL, :warehouse_stock_new, NULL, :status_new, NULL, :total_stock_new, NULL, :days_coverage_new, :unit, :unit_conversion, :daily_consumption, :level, :min_days_coverage, :changed_by, :note)';
            }

            $histStmt = $pdo->prepare($histSql);
            $histParams = [
                ':item_id' => $newItemId,
                ':item_name' => $name,
                ':category' => $category,
                ':action' => 'insert',
                ':field_stock_new' => $fieldStock,
                ':warehouse_stock_new' => $warehouseStock,
                ':status_new' => $statusNew,
                ':total_stock_new' => $totalStockNew,
                ':days_coverage_new' => $daysCoverageNew,
                ':unit' => ($unit !== null ? $unit : ''),
                ':unit_conversion' => $unitConversion,
                ':daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $dailyConsumption,
                ':min_days_coverage' => $minDaysCoverage,
                ':changed_by' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
                ':note' => 'initial insert (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')'
            ];

            // Include level if column exists
            if ($histHasLevel) {
                $histParams[':level'] = $levelVal;
            }

            $histStmt->execute($histParams);

            $pdo->commit();

            // After successful insertion, redirect to manage-items.php
            header('Location: manage-items.php?success=1');
            exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '<div class="alert error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
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

    <title>Tambah Barang - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <section class="overlay"></section>

    <main class="main-container">
        <div class="main-title">
            <h2>Tambah Barang</h2>
        </div>

        <div class="form-container">
            <div class="form-header">
                <h2>Tambah Barang</h2>
            </div>

            <?php if ($message): ?>
                <?php echo $message; ?>
            <?php endif; ?>

            <form method="POST" class="add-form">
                <div class="form-group">
                    <label for="name">Nama Barang</label>
                    <input type="text" id="name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="category">Kategori</label>
                    <select id="category" name="category" required>
                        <option value="">Pilih Kategori</option>
                        <option value="Chemical">Chemical</option>
                        <option value="Lube Oil">Lube Oil</option>
                        <option value="Toothbelts">Toothbelts</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="field_stock">Stok</label>
                    <input type="number" id="field_stock" name="field_stock" min="0" value="0" required>
                </div>

                <div class="form-group">
                    <label for="unit">Satuan</label>
                    <select id="unit" name="unit" required>
                        <?php if (empty($units)): ?>
                            <option value="" disabled selected>Belum ada kategori unit</option>
                        <?php else: ?>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo htmlspecialchars($unit['value']); ?>"><?php echo htmlspecialchars($unit['label']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="form-group">
                        <label for="unit_conversion">Faktor Konversi Satuan</label>
                        <input type="number" id="unit_conversion" name="unit_conversion" min="0.1" step="0.1" value="1.0" required>
                    </div>

                    <div class="form-group">
                        <label for="daily_consumption">Konsumsi Harian</label>
                        <input type="number" id="daily_consumption" name="daily_consumption" min="0" step="0.1" value="0.0" required>
                    </div>

                    <div class="form-group">
                        <label for="min_days_coverage">Minimum Periode (hari)</label>
                        <input type="number" id="min_days_coverage" name="min_days_coverage" min="1" value="7" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Keterangan</label>
                        <textarea id="description" name="description" rows="3"></textarea>
                    </div>

                    <?php if ($hasLevelColumn): ?>
                        <div class="form-group">
                            <label for="has_level">
                                <input type="checkbox" id="has_level" name="has_level" value="1">
                                Gunakan indikator level untuk kalkulasi ketahanan
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="form-group" id="level-group" style="display:none;">
                        <label for="level">Level (cm)</label>
                        <input type="number" id="level" name="level" min="0" step="1" value="">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Simpan Barang</button>
                        <a href="manage-items.php" class="btn-cancel">Batal</a>
                    </div>
            </form>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
</body>

</html>