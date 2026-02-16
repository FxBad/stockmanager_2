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
$categories = getItemCategories();

// Cache column checks
$includeLevel = db_has_column('items', 'level');
$includeHasLevel = db_has_column('items', 'has_level');

$formState = [
    'name' => isset($item['name']) ? (string)$item['name'] : '',
    'category' => isset($item['category']) ? (string)$item['category'] : '',
    'field_stock' => isset($item['field_stock']) ? (int)$item['field_stock'] : 0,
    'unit' => isset($item['unit']) ? (string)$item['unit'] : '',
    'unit_conversion' => isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1.0,
    'level_conversion' => isset($item['level_conversion']) ? (float)$item['level_conversion'] : (isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1.0),
    'calculation_mode' => isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined',
    'daily_consumption' => isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0.0,
    'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 7,
    'description' => isset($item['description']) ? (string)$item['description'] : '',
    'has_level' => isset($item['has_level']) ? (int)$item['has_level'] : 0,
    'level' => isset($item['level']) ? (string)$item['level'] : '',
];

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
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Barang - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion('style.css'); ?>">
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
                <?php
                $formPrefix = '';
                $formUnits = $units;
                $formCategories = $categories;
                $formShowLevel = $includeHasLevel;
                $formLevelGroupId = 'level-group';
                include __DIR__ . '/../shared/item-form-fields.php';
                ?>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">Simpan Perubahan</button>
                    <a href="manage-items.php" class="btn-cancel">Batal</a>
                </div>
            </form>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion('script.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hasLevelCb = document.getElementById('has_level');
            const levelGroup = document.getElementById('level-group');
            const unitConversionGroup = document.getElementById('unit-group-conversion');
            const levelConversionGroup = document.getElementById('level-group-conversion');
            const levelModeGroup = document.getElementById('level-group-mode');
            const calculationMode = document.getElementById('calculation_mode');
            const customConversionGroup = document.getElementById('level-group-custom-conversion');
            const customConversionInput = document.getElementById('custom_conversion_factor');

            function updateLevelUI() {
                if (!hasLevelCb || !levelGroup) {
                    return;
                }

                if (!hasLevelCb.checked && calculationMode) {
                    calculationMode.value = 'combined';
                }

                const calcMode = calculationMode ? String(calculationMode.value || 'combined').toLowerCase() : 'combined';
                const useMultiplied = hasLevelCb.checked && calcMode === 'multiplied';

                levelGroup.style.display = hasLevelCb.checked ? 'block' : 'none';
                if (unitConversionGroup) unitConversionGroup.style.display = useMultiplied ? 'none' : 'block';
                if (levelConversionGroup) levelConversionGroup.style.display = hasLevelCb.checked && !useMultiplied ? 'block' : 'none';
                if (levelModeGroup) levelModeGroup.style.display = hasLevelCb.checked ? 'block' : 'none';
                if (customConversionGroup) customConversionGroup.style.display = useMultiplied ? 'block' : 'none';
                if (customConversionInput) customConversionInput.required = useMultiplied;
            }

            if (hasLevelCb && levelGroup) {
                hasLevelCb.addEventListener('change', function() {
                    updateLevelUI();
                });
            }

            if (calculationMode) {
                calculationMode.addEventListener('change', function() {
                    updateLevelUI();
                });
            }

            updateLevelUI();
        });
    </script>

</body>

</html>