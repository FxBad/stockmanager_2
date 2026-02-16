<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow only office and admin to add items
requireRole(['office', 'admin']);

// Fetch units from database
$units = getUnits();
$categories = getItemCategories();
$hasLevelColumn = db_has_column('items', 'has_level');

$formState = [
    'name' => '',
    'category' => '',
    'field_stock' => 0,
    'unit' => '',
    'unit_conversion' => 1.0,
    'level_conversion' => 1.0,
    'calculation_mode' => 'combined',
    'daily_consumption' => 0.0,
    'min_days_coverage' => 7,
    'description' => '',
    'has_level' => 0,
    'level' => '',
];

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = saveItemWithHistory($_POST, $_SESSION['user_id'], 'create', ['context' => 'add.php']);

    if ($result['success']) {
        header('Location: manage-items.php?success=1');
        exit;
    }

    if (!empty($result['data']) && is_array($result['data'])) {
        $formState = [
            'name' => $result['data']['name'],
            'category' => $result['data']['category'],
            'field_stock' => $result['data']['field_stock'],
            'unit' => $result['data']['unit'],
            'unit_conversion' => $result['data']['unit_conversion'],
            'level_conversion' => $result['data']['level_conversion'],
            'calculation_mode' => $result['data']['calculation_mode'],
            'daily_consumption' => $result['data']['daily_consumption'],
            'min_days_coverage' => $result['data']['min_days_coverage'],
            'description' => $result['data']['description'],
            'has_level' => $result['data']['has_level'],
            'level' => $result['data']['level_input'],
        ];
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
    <link rel="stylesheet" href="style.css?<?php echo getVersion('style.css'); ?>">
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
                <?php
                $formPrefix = '';
                $formUnits = $units;
                $formCategories = $categories;
                $formShowLevel = $hasLevelColumn;
                $formLevelGroupId = 'level-group';
                include __DIR__ . '/../shared/item-form-fields.php';
                ?>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">Simpan Barang</button>
                    <a href="manage-items.php" class="btn-cancel">Batal</a>
                </div>
            </form>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion('script.js'); ?>"></script>
</body>

</html>