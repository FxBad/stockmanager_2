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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = saveItemWithHistory($_POST, $_SESSION['user_id'], 'create', ['context' => 'add.php']);

    if ($result['success']) {
        header('Location: manage-items.php?success=1');
        exit;
    }

    if (!empty($result['errors'])) {
        $message = '<div class="alert error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $result['errors'])) . '</li></ul></div>';
    } else {
        $message = '<div class="alert error">Error: ' . htmlspecialchars((string)$result['message']) . '</div>';
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