<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow only office and admin to manage items
requireRole(['office', 'admin']);

$message = '';
$hasLevelColumn = db_has_column('items', 'has_level');
$hasLevelSelect = $hasLevelColumn ? ', i.has_level' : '';

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
            i.id, i.name, i.category, i.field_stock, i.unit_conversion, i.daily_consumption, i.min_days_coverage, i.level, i.status, i.last_updated, i.added_by as added_by_id, i.updated_by as updated_by_id{$hasLevelSelect},
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

        <div class="table-container">
            <div class="table-header">
                <div class="header-actions">
                    <a href="add.php" class="btn-add">
                        <i class='bx bx-plus'></i>
                        Tambah Barang
                    </a>
                </div>
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
                                    <button type="button" class="btn-edit" onclick="editItem(<?php echo $id; ?>)">
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
                            <td colspan="10" class="no-data">Tidak ada barang</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
</body>

</html>