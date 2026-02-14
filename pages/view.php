<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

$categories = getItemCategories();

// Initialize filters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get sort parameter (default to 'name' if not specified)
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sortDir = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'DESC' : 'ASC';

// Valid sort columns to prevent SQL injection
$validSortColumns = ['name', 'category', 'field_stock', 'last_updated'];

// Verify sort parameter is valid
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'name'; // Default fallback
}

$hasLevelCol = db_has_column('items', 'has_level');
$levelSelect = $hasLevelCol ? ', i.has_level' : '';
$hasLevelConversionCol = db_has_column('items', 'level_conversion');
$levelConversionSelect = $hasLevelConversionCol ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
$hasCalculationModeCol = db_has_column('items', 'calculation_mode');
$calculationModeSelect = $hasCalculationModeCol ? ', i.calculation_mode' : ", 'combined' AS calculation_mode";

// Build query
$query = "SELECT 
           i.id, i.name, i.category, i.field_stock, i.unit_conversion{$levelConversionSelect}{$calculationModeSelect}, i.daily_consumption, i.min_days_coverage, i.level, i.status, i.last_updated, i.added_by as added_by_id, i.updated_by as updated_by_id,
           u.full_name as added_by_name,
           u2.full_name as updated_by_name{$levelSelect}
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

// PRIMARY SORT: by the selected column and direction
$query .= " ORDER BY i.{$sortBy} {$sortDir}";

// SECONDARY SORT: if sorting by something other than name, add name as secondary sort
if ($sortBy !== 'name') {
    $query .= ", i.name ASC";
}

// Execute query with error handling
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // On error, fallback to empty results and categories
    $items = [];
}

$criticalStatuses = ['low-stock', 'warning-stock', 'out-stock'];
$criticalItemsCount = 0;
foreach ($items as $summaryItem) {
    $summaryStatus = isset($summaryItem['status']) ? (string)$summaryItem['status'] : '';
    if (in_array($summaryStatus, $criticalStatuses, true)) {
        $criticalItemsCount++;
    }
}

$activeFilters = [];
if ($search !== '') {
    $activeFilters[] = 'Cari: ' . $search;
}
if ($category !== '') {
    $activeFilters[] = 'Kategori: ' . $category;
}
if ($status !== '') {
    $activeFilters[] = 'Status: ' . translateStatus($status, 'id');
}

$totalItemsCount = count($items);
$activeFilterCount = count($activeFilters);
$sortDirValue = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';

// Show warehouse stock and daily consumption only to office and admin
$showSensitive = isRole('office') || isRole('admin');

// Compute colspan for empty-state row (total visible columns):
// Columns: Name, Category, Stock, [Daily Consumption], Level, Coverage, Status, Last Updated
// Count them programmatically for clarity
$colCount = 3; // Name, Category, Stock
if ($showSensitive) {
    $colCount += 1; // Daily Consumption
}
$colCount += 4; // Level, Coverage, Status, Last Updated
$colspan = $colCount;
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

    <title>Lihat Stok - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
</head>

<body class="view-page">
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Lihat Data Stok</h2>
        </div>

        <div class="table-container">
            <section class="view-summary" aria-label="Ringkasan data stok">
                <article class="summary-card">
                    <p class="summary-label">Total Item Ditampilkan</p>
                    <p class="summary-value" id="summary-total-items"><?php echo number_format($totalItemsCount); ?></p>
                </article>
                <article class="summary-card summary-card-critical">
                    <p class="summary-label">Item Stok Kritis</p>
                    <p class="summary-value" id="summary-critical-items"><?php echo number_format($criticalItemsCount); ?></p>
                </article>
                <article class="summary-card summary-card-filters">
                    <p class="summary-label" id="summary-filter-count-label">Filter Aktif (<?php echo $activeFilterCount; ?>)</p>
                    <div class="summary-filter-actions">
                        <div id="active-filter-chips" class="summary-filter-chips" aria-label="Filter aktif saat ini">
                            <?php if ($activeFilterCount > 0): ?>
                                <?php foreach ($activeFilters as $activeFilter): ?>
                                    <span class="summary-filter-chip"><?php echo htmlspecialchars($activeFilter); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="summary-muted" id="summary-filter-empty">Semua data tanpa filter</p>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="reset-filters-btn" class="btn-reset-filters">Reset Semua</button>
                    </div>
                </article>
            </section>

            <!-- Search and Filter Section -->
            <div class="table-header">
                <form method="GET" class="table-filters" data-filter-context="view" data-default-sort="name" data-default-dir="asc">
                    <div class="filter-primary-row">
                        <div class="search-box">
                            <i class='bx bx-search'></i>
                            <input type="text" name="search" id="search-input" placeholder="Cari barang..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                            <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="Hapus pencarian">&times;</button>
                            <div id="autocomplete-list" class="autocomplete-items"></div>
                        </div>

                        <div class="filter-group filter-group-primary">
                            <select name="category" id="filter-category">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="status" id="filter-status">
                                <option value="">Semua Status</option>
                                <option value="in-stock" <?php echo $status === 'in-stock' ? 'selected' : ''; ?>><?php echo translateStatus('in-stock', 'id'); ?></option>
                                <option value="low-stock" <?php echo $status === 'low-stock' ? 'selected' : ''; ?>><?php echo translateStatus('low-stock', 'id'); ?></option>
                                <option value="warning-stock" <?php echo $status === 'warning-stock' ? 'selected' : ''; ?>><?php echo translateStatus('warning-stock', 'id'); ?></option>
                                <option value="out-stock" <?php echo $status === 'out-stock' ? 'selected' : ''; ?>><?php echo translateStatus('out-stock', 'id'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-advanced-row">
                        <button type="button" id="toggle-advanced-filters" class="btn-filter-advanced" aria-expanded="false" aria-controls="advanced-filters-panel">
                            <i class='bx bx-slider-alt'></i>
                            Filter Lainnya
                        </button>
                        <div id="advanced-filters-panel" class="advanced-filters-panel" hidden>
                            <div class="filter-group">
                                <label for="advanced-sort">Urutkan</label>
                                <select name="sort" id="advanced-sort">
                                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Nama Barang</option>
                                    <option value="category" <?php echo $sortBy === 'category' ? 'selected' : ''; ?>>Kategori</option>
                                    <option value="field_stock" <?php echo $sortBy === 'field_stock' ? 'selected' : ''; ?>>Stok</option>
                                    <option value="last_updated" <?php echo $sortBy === 'last_updated' ? 'selected' : ''; ?>>Terakhir Diperbarui</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="advanced-dir">Arah</label>
                                <select name="dir" id="advanced-dir">
                                    <option value="asc" <?php echo $sortDirValue === 'asc' ? 'selected' : ''; ?>>Naik (A-Z / Lama-Baru)</option>
                                    <option value="desc" <?php echo $sortDirValue === 'desc' ? 'selected' : ''; ?>>Turun (Z-A / Baru-Lama)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <noscript><button type="submit" class="btn-filter">Terapkan Filter</button></noscript>
                </form>
            </div>

            <!-- Data Table -->
            <table>
                <thead>
                    <tr>
                        <th>
                            <a href="?search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&sort=name&dir=<?= ($sortBy === 'name' && $sortDir === 'ASC') ? 'desc' : 'asc' ?>" class="th-sort">
                                Nama Barang
                                <?php if ($sortBy === 'name'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&sort=category&dir=<?= ($sortBy === 'category' && $sortDir === 'ASC') ? 'desc' : 'asc' ?>" class="th-sort">
                                Kategori
                                <?php if ($sortBy === 'category'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="col-number">Stok</th>
                        <?php if ($showSensitive): ?>
                            <th class="col-number">Pemakaian Harian</th>
                        <?php endif; ?>
                        <th class="col-number">Level (cm)</th>
                        <th class="col-number">Ketahanan di lapangan</th>
                        <th>Status</th>
                        <th>
                            <a href="?search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&sort=last_updated&dir=<?= ($sortBy === 'last_updated' && $sortDir === 'ASC') ? 'desc' : 'asc' ?>" class="th-sort">
                                Terakhir Diperbarui
                                <?php if ($sortBy === 'last_updated'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="<?php echo $colspan; ?>" class="no-data">Tidak ada barang ditemukan</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item):
                            $name = isset($item['name']) ? (string)$item['name'] : '';
                            $itemCategory = isset($item['category']) ? (string)$item['category'] : '';
                            $field_stock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
                            $unit_conversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
                            $level_conversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unit_conversion;
                            $daily_consumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
                            $level = array_key_exists('level', $item) ? $item['level'] : null;
                            $calculation_mode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
                            $itemStatus = isset($item['status']) ? (string)$item['status'] : '';
                            $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;

                            $totalStock = calculateEffectiveStock($field_stock, $unit_conversion, $level, $hasLevel, [
                                'level_conversion' => $level_conversion,
                                'qty_conversion' => $unit_conversion,
                                'calculation_mode' => $calculation_mode
                            ]);

                            $daysCoverage = calculateDaysCoverage(
                                $field_stock,
                                0,
                                $unit_conversion,
                                $daily_consumption,
                                $name,
                                $level,
                                $hasLevel,
                                [
                                    'item_id' => isset($item['id']) ? (int)$item['id'] : 0,
                                    'category' => $itemCategory,
                                    'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1,
                                    'level_conversion' => $level_conversion,
                                    'qty_conversion' => $unit_conversion,
                                    'calculation_mode' => $calculation_mode
                                ]
                            );

                            $resolvedDaily = resolveDailyConsumption($daily_consumption, [
                                'item_id' => isset($item['id']) ? (int)$item['id'] : 0,
                                'category' => $itemCategory,
                                'effective_stock' => $totalStock,
                                'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
                            ]);

                            $statusIconClass = 'bx-info-circle';
                            if ($itemStatus === 'in-stock') {
                                $statusIconClass = 'bx-check-circle';
                            } elseif ($itemStatus === 'low-stock') {
                                $statusIconClass = 'bx-error-circle';
                            } elseif ($itemStatus === 'warning-stock') {
                                $statusIconClass = 'bx-error';
                            } elseif ($itemStatus === 'out-stock') {
                                $statusIconClass = 'bx-x-circle';
                            }
                        ?>
                            <tr>
                                <td data-label="Nama Barang" class="col-text"><?php echo htmlspecialchars($name); ?></td>
                                <td data-label="Kategori" class="col-text"><?php echo htmlspecialchars($itemCategory); ?></td>
                                <td data-label="Stok" class="col-number"><?php echo number_format((int)$field_stock); ?></td>
                                <?php if ($showSensitive): ?>
                                    <td data-label="Pemakaian Harian" class="col-number"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                                <?php endif; ?>
                                <td data-label="Level (cm)" class="col-number"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                                <td data-label="Ketahanan di lapangan" class="col-number"><?php echo number_format($daysCoverage, 1); ?> Hari</td>
                                <td data-label="Status" class="col-text">
                                    <span class="status <?php echo htmlspecialchars($itemStatus); ?>">
                                        <i class='bx <?php echo htmlspecialchars($statusIconClass); ?>' aria-hidden="true"></i>
                                        <span><?php echo translateStatus($itemStatus, 'id'); ?></span>
                                    </span>
                                </td>
                                <td data-label="Terakhir Diperbarui" class="last-login col-text">
                                    <?php if (!empty($item['last_updated'])): ?>
                                        <span class="timestamp">
                                            <i class='bx bx-time-five'></i>
                                            <?php echo date('d/m/Y, H:i', strtotime($item['last_updated'])) . ' WIB'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="never-login">
                                            <i class='bx bx-x-circle'></i>
                                            Tidak Pernah
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
</body>

</html>