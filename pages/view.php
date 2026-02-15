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
$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$allowedPerPage = [10, 25, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

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

$countQuery = "SELECT COUNT(*) FROM items i WHERE " . activeItemsWhereSql('i');
$params = [];

if ($category) {
    $query .= " AND i.category = ?";
    $countQuery .= " AND i.category = ?";
    $params[] = $category;
}

if ($status) {
    $query .= " AND i.status = ?";
    $countQuery .= " AND i.status = ?";
    $params[] = $status;
}

if ($search) {
    $query .= " AND i.name LIKE ?";
    $countQuery .= " AND i.name LIKE ?";
    $params[] = "%$search%";
}

$totalItems = 0;
$totalPages = 1;
$offset = 0;

try {
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalItems / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
} catch (Exception $e) {
    $items = [];
}

// PRIMARY SORT: by the selected column and direction
$query .= " ORDER BY i.{$sortBy} {$sortDir}";

// SECONDARY SORT: if sorting by something other than name, add name as secondary sort
if ($sortBy !== 'name') {
    $query .= ", i.name ASC";
}

$query .= " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$baseQueryParams = [
    'search' => $search,
    'category' => $category,
    'status' => $status,
    'sort' => $sortBy,
    'dir' => strtolower($sortDir),
    'per_page' => $perPage,
];

$buildQuery = function (array $overrides = []) use ($baseQueryParams): string {
    $next = array_merge($baseQueryParams, $overrides);

    foreach (['search', 'category', 'status'] as $optionalKey) {
        if (!isset($next[$optionalKey]) || $next[$optionalKey] === '') {
            unset($next[$optionalKey]);
        }
    }

    if (isset($next['page']) && (int)$next['page'] <= 1) {
        unset($next['page']);
    }

    $queryString = http_build_query($next);
    return $queryString ? ('?' . $queryString) : '';
};

// Execute query with error handling
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // On error, fallback to empty results and categories
    $items = [];
}

$prefetchedItemHistoryDaily = [];
if (!empty($items)) {
    $itemIds = [];
    foreach ($items as $row) {
        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($id > 0) {
            $itemIds[] = $id;
        }
    }
    if (!empty($itemIds)) {
        $prefetchedItemHistoryDaily = prefetchItemHistoryDailyConsumption($itemIds);
    }
}

// Show warehouse stock and daily consumption only to office and admin
$showSensitive = isRole('office') || isRole('admin');

// Compute colspan for empty-state row (total visible columns):
// Columns: Name, Category, Field Stock, [Warehouse Stock], [Total Stock], [Daily Consumption], Level, Coverage, Status, Last Updated
// Count them programmatically for clarity
$colCount = 3; // Name, Category, Field Stock
if ($showSensitive) {
    $colCount += 3; // Warehouse Stock, Total Stock, Daily Consumption
}
$colCount += 4; // Level, Coverage, Status, Last Updated
$colspan = $colCount;
$hasActiveFilter = ($search !== '' || $category !== '' || $status !== '');
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

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Lihat Data Stok</h2>
        </div>

        <div class="table-container">
            <!-- Search and Filter Section -->
            <div class="table-header">
                <form method="GET" class="table-filters" data-filter-context="view" data-default-sort="name" data-default-dir="asc" data-server-grid="1">
                    <div class="search-box">
                        <i class='bx bx-search'></i>
                        <input type="text" name="search" id="search-input" placeholder="Cari barang..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                        <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="Hapus pencarian">&times;</button>
                        <div id="autocomplete-list" class="autocomplete-items"></div>
                    </div>

                    <div class="filter-group">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars((string)$sortBy); ?>">
                        <input type="hidden" name="dir" value="<?php echo strtolower((string)$sortDir); ?>">
                        <input type="hidden" name="page" id="filter-page-input" value="1">
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

                        <select name="per_page" aria-label="Jumlah data per halaman">
                            <?php foreach ($allowedPerPage as $size): ?>
                                <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> / halaman</option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn-filter">Terapkan Filter</button>

                        <button type="button" id="reset-filter-btn" class="btn-filter">Reset Filter</button>
                    </div>

                    <div id="active-filter-chips" class="active-filter-chips" aria-live="polite"></div>

                    <div id="filter-result-count" class="filter-result-count" aria-live="polite">
                        <?php echo number_format((int)$totalItems); ?> item ditemukan
                    </div>
                </form>
            </div>

            <!-- Data Table -->
            <table>
                <!-- Table Headers -->
                <thead>
                    <tr>
                        <!-- Sortable Item Name column -->
                        <th>
                            <a href="<?php echo htmlspecialchars($buildQuery([
                                            'sort' => 'name',
                                            'dir' => ($sortBy === 'name' && $sortDir === 'ASC') ? 'desc' : 'asc',
                                            'page' => 1
                                        ])); ?>" class="th-sort">
                                Nama Barang
                                <?php if ($sortBy === 'name'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>

                        <!-- Sortable Category column -->
                        <th>
                            <a href="<?php echo htmlspecialchars($buildQuery([
                                            'sort' => 'category',
                                            'dir' => ($sortBy === 'category' && $sortDir === 'ASC') ? 'desc' : 'asc',
                                            'page' => 1
                                        ])); ?>" class="th-sort">
                                Kategori
                                <?php if ($sortBy === 'category'): ?>
                                    <i class='bx bx-<?= $sortDir === 'ASC' ? 'up' : 'down' ?>-arrow-alt'></i>
                                <?php else: ?>
                                    <i class='bx bx-sort-alt-2'></i>
                                <?php endif; ?>
                            </a>
                        </th>

                        <!-- Regular columns -->
                        <th class="numeric-col">Stok</th>
                        <?php if ($showSensitive): ?>
                            <th class="numeric-col">Pemakaian Harian</th>
                        <?php endif; ?>
                        <th class="numeric-col">Level (cm)</th>
                        <th class="numeric-col">Ketahanan di lapangan</th>
                        <th>Status</th>

                        <!-- Sortable Last Updated column -->
                        <th>
                            <a href="<?php echo htmlspecialchars($buildQuery([
                                            'sort' => 'last_updated',
                                            'dir' => ($sortBy === 'last_updated' && $sortDir === 'ASC') ? 'desc' : 'asc',
                                            'page' => 1
                                        ])); ?>" class="th-sort">
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
                            <td colspan="<?php echo $colspan; ?>" class="no-data">
                                <div class="empty-state">
                                    <?php if ($hasActiveFilter): ?>
                                        <p class="empty-state-title">Tidak ada barang yang cocok dengan filter saat ini</p>
                                        <p class="empty-state-desc">Coba ubah kata kunci pencarian, kategori, atau status agar data kembali muncul.</p>
                                        <button type="button" class="btn-filter empty-reset-btn">Reset Filter</button>
                                    <?php else: ?>
                                        <p class="empty-state-title">Data barang belum tersedia</p>
                                        <p class="empty-state-desc">Tambahkan data inventaris terlebih dahulu agar daftar stok dapat ditampilkan.</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item):
                            // Normalize and cast values
                            $itemId = isset($item['id']) ? (int)$item['id'] : 0;
                            $name = isset($item['name']) ? (string)$item['name'] : '';
                            $itemCategory = isset($item['category']) ? (string)$item['category'] : '';
                            $field_stock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
                            $unit_conversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
                            $level_conversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unit_conversion;
                            $daily_consumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
                            $min_days_coverage = isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1;
                            $level = array_key_exists('level', $item) ? $item['level'] : null;
                            $calculation_mode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
                            $status = isset($item['status']) ? (string)$item['status'] : '';

                            $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;

                            $totalStock = calculateEffectiveStock($field_stock, $unit_conversion, $level, $hasLevel, [
                                'level_conversion' => $level_conversion,
                                'qty_conversion' => $unit_conversion,
                                'calculation_mode' => $calculation_mode
                            ]);

                            $dailyContext = [
                                'item_id' => $itemId,
                                'category' => $itemCategory,
                                'effective_stock' => $totalStock,
                                'min_days_coverage' => $min_days_coverage,
                                'prefetched_item_history_daily' => array_key_exists($itemId, $prefetchedItemHistoryDaily) ? $prefetchedItemHistoryDaily[$itemId] : null
                            ];

                            $resolvedDaily = resolveDailyConsumption($daily_consumption, $dailyContext);

                            $daysCoverage = calculateDaysCoverage(
                                $field_stock,
                                0,
                                $unit_conversion,
                                isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : 0,
                                $name,
                                $level,
                                $hasLevel,
                                [
                                    'item_id' => $itemId,
                                    'category' => $itemCategory,
                                    'min_days_coverage' => $min_days_coverage,
                                    'level_conversion' => $level_conversion,
                                    'qty_conversion' => $unit_conversion,
                                    'calculation_mode' => $calculation_mode,
                                    'prefetched_item_history_daily' => array_key_exists($itemId, $prefetchedItemHistoryDaily) ? $prefetchedItemHistoryDaily[$itemId] : null
                                ]
                            );

                            $coverageClass = ((float)$daysCoverage < (float)$min_days_coverage) ? ' coverage-risk' : '';
                        ?>
                            <tr>
                                <td data-label="Nama Barang"><?php echo htmlspecialchars($name); ?></td>
                                <td data-label="Kategori"><?php echo htmlspecialchars($itemCategory); ?></td>
                                <td data-label="Stok" class="numeric-col"><?php echo number_format((int)$field_stock); ?></td>
                                <?php if ($showSensitive): ?>
                                    <td data-label="Pemakaian Harian" class="numeric-col"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                                <?php endif; ?>
                                <td data-label="Level (cm)" class="numeric-col"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                                <td data-label="Ketahanan di lapangan" class="numeric-col<?php echo $coverageClass; ?>"><?php echo number_format($daysCoverage, 1); ?> Hari</td>
                                <td data-label="Status">
                                    <span class="status <?php echo htmlspecialchars($status); ?>">
                                        <?php echo translateStatus($status, 'id'); ?>
                                    </span>
                                </td>
                                <td data-label="Terakhir Diperbarui" class="last-login">
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

            <div class="table-pagination" aria-label="Navigasi halaman">
                <?php if ($page > 1): ?>
                    <a class="btn-page" href="<?php echo htmlspecialchars($buildQuery(['page' => $page - 1])); ?>" aria-label="Halaman sebelumnya">&laquo; Sebelumnya</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                ?>
                    <a class="btn-page <?php echo $pageNumber === $page ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildQuery(['page' => $pageNumber])); ?>"><?php echo $pageNumber; ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="btn-page" href="<?php echo htmlspecialchars($buildQuery(['page' => $page + 1])); ?>" aria-label="Halaman berikutnya">Berikutnya &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
</body>

</html>