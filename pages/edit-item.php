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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = saveItemWithHistory($_POST, $_SESSION['user_id'], 'update', ['item_id' => $id, 'context' => 'edit-item.php']);

    if ($result['success']) {
        header('Location: manage-items.php?success=1');
        exit;
    }

    if (!empty($result['errors'])) {
        $message = '<div class="alert error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $result['errors'])) . '</li></ul></div>';
    } else {
        $message = '<div class="alert error">Error: ' . htmlspecialchars((string)$result['message']) . '</div>';
    }

    if (isset($result['data']) && is_array($result['data'])) {
        $item['name'] = $result['data']['name'];
        $item['category'] = $result['data']['category'];
        $item['field_stock'] = $result['data']['field_stock'];
        $item['unit'] = $result['data']['unit'];
        $item['unit_conversion'] = $result['data']['unit_conversion'];
        $item['daily_consumption'] = $result['data']['daily_consumption'];
        $item['min_days_coverage'] = $result['data']['min_days_coverage'];
        $item['description'] = $result['data']['description'];
        if ($includeHasLevel) {
            $item['has_level'] = $result['data']['has_level'];
        }
        if ($includeLevel) {
            $item['level'] = $result['data']['level_input'];
        }
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