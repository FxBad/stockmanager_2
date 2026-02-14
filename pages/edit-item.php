<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow office and admin to edit item data
requireRole(['office', 'admin']);

$message = '';
$item = null;
$errors = [];

// Validate and cast id
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: manage-items.php');
    exit;
}

// Get item data with error handling
try {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? AND " . activeItemsWhereSql());
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        header('Location: manage-items.php');
        exit;
    }
} catch (Exception $e) {
    // On DB error redirect to list (avoid showing raw DB errors here)
    header('Location: manage-items.php');
    exit;
}

// Fetch units from database
$units = getUnits();

// Cache column checks
$includeLevel = db_has_column('items', 'level');
$includeHasLevel = db_has_column('items', 'has_level');
$histHasLevel = db_has_column('item_stock_history', 'level');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate level: only allowed if has_level is checked
        $levelInput = isset($_POST['level']) ? trim((string)$_POST['level']) : null;
        $hasLevelInput = isset($_POST['has_level']);
        $levelVal = null;

        if ($hasLevelInput && $levelInput !== null && $levelInput !== '') {
            if (!ctype_digit($levelInput)) {
                throw new Exception('Level tidak valid. Masukkan angka bulat (cm).');
            }
            $levelVal = (int)$levelInput;
        }

        // Fetch current item for history
        $stmtGet = $pdo->prepare('SELECT * FROM items WHERE id = ? AND ' . activeItemsWhereSql());
        $stmtGet->execute([$id]);
        $old = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            throw new Exception('Item tidak ditemukan atau sudah diarsipkan.');
        }

        $pdo->beginTransaction();

        // Update items; include `level` if column exists
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
        if ($includeLevel) {
            $set[] = 'level = :level';
        }
        if ($includeHasLevel) {
            $set[] = 'has_level = :has_level';
        }

        $sql = "UPDATE items SET " . implode(",\n                ", $set) . "\n                WHERE id = :id AND " . activeItemsWhereSql();

        $stmt = $pdo->prepare($sql);
        $unitConversionInput = isset($_POST['unit_conversion']) && is_numeric($_POST['unit_conversion']) ? round((float)$_POST['unit_conversion'], 1) : 1.0;
        $dailyConsumptionInput = isset($_POST['daily_consumption']) && is_numeric($_POST['daily_consumption']) ? round((float)$_POST['daily_consumption'], 1) : 0.0;
        $params = [
            ':name' => isset($_POST['name']) ? trim($_POST['name']) : '',
            ':category' => isset($_POST['category']) ? trim($_POST['category']) : '',
            ':field_stock' => isset($_POST['field_stock']) && is_numeric($_POST['field_stock']) ? (int)$_POST['field_stock'] : 0,
            ':unit' => isset($_POST['unit']) ? trim($_POST['unit']) : '',
            ':unit_conversion' => $unitConversionInput,
            ':daily_consumption' => $dailyConsumptionInput,
            ':min_days_coverage' => isset($_POST['min_days_coverage']) && is_numeric($_POST['min_days_coverage']) ? (int)$_POST['min_days_coverage'] : 1,
            ':description' => isset($_POST['description']) ? trim($_POST['description']) : '',
            ':updated_by' => $_SESSION['user_id'],
            ':status' => null, // akan diisi setelah $newStatus dihitung
            ':id' => $id
        ];
        if ($includeLevel) {
            $params[':level'] = $levelVal;
        }
        if ($includeHasLevel) {
            $params[':has_level'] = $hasLevelInput ? 1 : 0;
        }

        // compute old/new totals dan status BARU sebelum update
        $oldTotal = (($old['field_stock'] ?? 0)) * ($old['unit_conversion'] ?? 1);
        $oldHasLevel = isset($old['has_level']) ? (bool)$old['has_level'] : (strtoupper(trim($old['name'] ?? '')) === 'DMDS');
        $oldDays = calculateDaysCoverage(
            $old['field_stock'],
            0,
            $old['unit_conversion'],
            $old['daily_consumption'],
            isset($old['name']) ? $old['name'] : null,
            isset($old['level']) ? $old['level'] : null,
            $oldHasLevel,
            [
                'item_id' => $id,
                'category' => isset($old['category']) ? $old['category'] : '',
                'min_days_coverage' => isset($old['min_days_coverage']) ? (int)$old['min_days_coverage'] : 1
            ]
        );

        $newField = (int)($params[':field_stock']);
        $newWarehouse = 0; // Warehouse stock removed from system
        $newUnitConv = (float)($params[':unit_conversion']);
        $newDaily = (float)($params[':daily_consumption']);
        $newMinDays = (int)($params[':min_days_coverage']);

        $newTotal = ($newField) * $newUnitConv;
        $newDays = calculateDaysCoverage(
            $newField,
            0,
            $newUnitConv,
            $newDaily,
            isset($params[':name']) ? $params[':name'] : null,
            isset($params[':level']) ? (int)$params[':level'] : null,
            $hasLevelInput,
            [
                'item_id' => $id,
                'category' => isset($params[':category']) ? $params[':category'] : '',
                'min_days_coverage' => $newMinDays
            ]
        );

        $resolvedDaily = resolveDailyConsumption($newDaily, [
            'item_id' => $id,
            'category' => isset($params[':category']) ? $params[':category'] : '',
            'effective_stock' => $newTotal,
            'min_days_coverage' => $newMinDays
        ]);
        $newStatus = determineStatus($newDays, $newMinDays);
        $params[':status'] = $newStatus;

        $stmt->execute($params);

        $cols = [
            'item_id',
            'item_name',
            'category',
            'action',
            'field_stock_old',
            'field_stock_new',
            'warehouse_stock_old',
            'warehouse_stock_new',
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

        $vals = [
            ':item_id',
            ':item_name',
            ':category',
            ':action',
            ':field_stock_old',
            ':field_stock_new',
            ':warehouse_stock_old',
            ':warehouse_stock_new',
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

        if ($histHasLevel) {
            $cols[] = 'level';
            $vals[] = ':level';
        }

        $histSql = 'INSERT INTO item_stock_history (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';

        $histStmt = $pdo->prepare($histSql);

        $histParams = [
            ':item_id' => $id,
            ':item_name' => $params[':name'],
            ':category' => $params[':category'],
            ':action' => 'update',
            ':field_stock_old' => $old['field_stock'],
            ':field_stock_new' => $newField,
            ':warehouse_stock_old' => 0,
            ':warehouse_stock_new' => 0,
            ':status_old' => $old['status'],
            ':status_new' => $newStatus,
            ':total_stock_old' => $oldTotal,
            ':total_stock_new' => $newTotal,
            ':days_coverage_old' => $oldDays,
            ':days_coverage_new' => $newDays,
            ':unit' => (isset($params[':unit']) && $params[':unit'] !== null ? $params[':unit'] : ''),
            ':unit_conversion' => $newUnitConv,
            ':daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $newDaily,
            ':min_days_coverage' => $newMinDays,
            ':changed_by' => $_SESSION['user_id'],
            ':note' => 'updated via edit (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')'
        ];

        if ($histHasLevel) {
            $histParams[':level'] = $levelVal;
        }

        $histStmt->execute($histParams);


        $pdo->commit();

        header('Location: manage-items.php?success=1');
        exit;
    } catch (Exception $e) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Barang - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="form-container">
            <div class="form-header">
                <h2>Ubah Barang</h2>
            </div>

            <?php if ($message): ?>
                <?php echo $message; ?>
            <?php endif; ?>

            <form method="POST" class="add-form">
                <div class="form-group">
                    <label for="name">Nama Barang</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($item['name']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="category">Kategori</label>
                    <select id="category" name="category" required>
                        <option value="Chemical" <?php echo $item['category'] === 'Chemical' ? 'selected' : ''; ?>>Chemical</option>
                        <option value="Lube Oil" <?php echo $item['category'] === 'Lube Oil' ? 'selected' : ''; ?>>Lube Oil</option>
                        <option value="Toothbelts" <?php echo $item['category'] === 'Toothbelts' ? 'selected' : ''; ?>>Toothbelts</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="field_stock">Stok</label>
                    <input type="number" id="field_stock" name="field_stock" value="<?php echo $item['field_stock']; ?>" min="0" required>
                </div>

                <div class="form-group">
                    <label for="unit">Satuan</label>
                    <select id="unit" name="unit" required>
                        <?php if (empty($units)): ?>
                            <option value="" disabled>Belum ada kategori</option>
                            <option value="<?php echo htmlspecialchars($item['unit']); ?>" selected><?php echo htmlspecialchars($item['unit']); ?></option>
                        <?php else: ?>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo htmlspecialchars($unit['value']); ?>" <?php echo $item['unit'] === $unit['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit['label']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="unit_conversion">Faktor Konversi Satuan</label>
                    <input type="number" id="unit_conversion" name="unit_conversion" value="<?php echo number_format((float)$item['unit_conversion'], 1, '.', ''); ?>" min="0.1" step="0.1" required>
                </div>

                <div class="form-group">
                    <label for="daily_consumption">Konsumsi Harian</label>
                    <input type="number" id="daily_consumption" name="daily_consumption" value="<?php echo number_format((float)$item['daily_consumption'], 1, '.', ''); ?>" min="0" step="0.1" required>
                </div>

                <div class="form-group">
                    <label for="min_days_coverage">Minimum Periode (hari)</label>
                    <input type="number" id="min_days_coverage" name="min_days_coverage" value="<?php echo $item['min_days_coverage']; ?>" min="1" required>
                </div>

                <div class="form-group">
                    <label for="description">Keterangan</label>
                    <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($item['description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="has_level">
                        <?php
                        $hasLvl = (!empty($item['has_level']) || (strtoupper(trim($item['name'])) === 'DMDS'));
                        ?>
                        <input type="checkbox" id="has_level" name="has_level" <?php echo $hasLvl ? 'checked' : ''; ?>>
                        Mode Level (Perhitungan stok berdasarkan ketinggian cm)
                    </label>
                </div>

                <div class="form-group" id="level-group" style="<?php echo $hasLvl ? '' : 'display:none;'; ?>">
                    <label for="level">Level (cm)</label>
                    <input type="number" id="level" name="level" min="0" step="1" value="<?php echo isset($item['level']) ? (int)$item['level'] : ''; ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">Simpan Perubahan</button>
                    <a href="manage-items.php" class="btn-cancel">Batal</a>
                </div>
            </form>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hasLevelCb = document.getElementById('has_level');
            const levelGroup = document.getElementById('level-group');
            if (hasLevelCb && levelGroup) {
                hasLevelCb.addEventListener('change', function() {
                    if (this.checked) {
                        levelGroup.style.display = 'block';
                    } else {
                        levelGroup.style.display = 'none';
                        // clear value if hidden? optional.
                    }
                });
            }
        });
    </script>

</body>

</html>