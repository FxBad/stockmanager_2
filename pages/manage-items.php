<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Allow only office and admin to manage items
requireRole(['office', 'admin']);

$message = '';
$units = getUnits();
$itemCategories = getItemCategories();

$formatAuditChangedFields = static function (array $row): array {
    $changed = [];

    if (array_key_exists('field_stock_old', $row) && array_key_exists('field_stock_new', $row) && (string)$row['field_stock_old'] !== (string)$row['field_stock_new']) {
        $changed[] = 'Stok Lapangan';
    }
    if (array_key_exists('status_old', $row) && array_key_exists('status_new', $row) && (string)$row['status_old'] !== (string)$row['status_new']) {
        $changed[] = 'Status';
    }
    if (array_key_exists('total_stock_old', $row) && array_key_exists('total_stock_new', $row) && (string)$row['total_stock_old'] !== (string)$row['total_stock_new']) {
        $changed[] = 'Total Stok';
    }
    if (array_key_exists('days_coverage_old', $row) && array_key_exists('days_coverage_new', $row) && (string)$row['days_coverage_old'] !== (string)$row['days_coverage_new']) {
        $changed[] = 'Ketahanan Hari';
    }

    if (isset($row['note']) && trim((string)$row['note']) !== '') {
        $changed[] = 'Catatan';
    }

    $action = strtolower((string)($row['action'] ?? ''));
    if ($action === 'create') {
        $changed[] = 'Item Baru';
    } elseif ($action === 'archive' || $action === 'delete') {
        $changed[] = 'Arsip';
    }

    if (empty($changed)) {
        $changed[] = 'Perubahan Data';
    }

    return array_values(array_unique($changed));
};

$latestAuditRows = [];
$latestAuditMaxId = 0;

try {
    $auditStmt = $pdo->query("SELECT
            h.id,
            h.item_id,
            h.item_name,
            h.action,
            h.field_stock_old,
            h.field_stock_new,
            h.status_old,
            h.status_new,
            h.total_stock_old,
            h.total_stock_new,
            h.days_coverage_old,
            h.days_coverage_new,
            h.level,
            h.note,
            h.changed_at,
            u.username AS changed_by_username,
            u.full_name AS changed_by_full_name
        FROM item_stock_history h
        LEFT JOIN users u ON h.changed_by = u.id
        ORDER BY h.id DESC
        LIMIT 15");

    $latestAuditRows = $auditStmt ? $auditStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!empty($latestAuditRows)) {
        $latestAuditMaxId = (int)$latestAuditRows[0]['id'];
    }
} catch (Exception $e) {
    $latestAuditRows = [];
    $latestAuditMaxId = 0;
}

$itemsHasLevelFlag = db_has_column('items', 'has_level');
$itemsHasLevelValue = db_has_column('items', 'level');
$itemsHasLevelConversion = db_has_column('items', 'level_conversion');
$itemsHasCalculationMode = db_has_column('items', 'calculation_mode');

$hasLevelSelect = $itemsHasLevelFlag ? ', i.has_level' : '';
$levelSelect = $itemsHasLevelValue ? ', i.level' : ', NULL AS level';
$levelConversionSelect = $itemsHasLevelConversion ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
$calculationModeSelect = $itemsHasCalculationMode ? ', i.calculation_mode' : ", 'combined' AS calculation_mode";

$modalToOpen = '';
$addItemState = [
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
$editItemState = [
    'item_id' => '',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string)$_POST['action']);

    if ($action === 'create_item' || $action === 'update_item') {
        $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $mode = $action === 'create_item' ? 'create' : 'update';
        $result = saveItemWithHistory($_POST, $_SESSION['user_id'], $mode, ['item_id' => $itemId, 'context' => 'manage-items.php']);

        $modalToOpen = $mode === 'create' ? 'add-item-modal' : 'edit-item-modal';

        if (!empty($result['data']) && is_array($result['data'])) {
            if ($mode === 'create') {
                $addItemState = [
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
            } else {
                $editItemState = [
                    'item_id' => (string)$itemId,
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

        if ($result['success']) {
            $modalToOpen = '';
            $message = '<div class="alert success">' . ($mode === 'create' ? 'Barang berhasil ditambahkan.' : 'Barang berhasil diperbarui.') . '</div>';
        } else {
            if (!empty($result['errors'])) {
                $message = '<div class="alert error"><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $result['errors'])) . '</li></ul></div>';
            } else {
                $message = '<div class="alert error">Error: ' . htmlspecialchars((string)$result['message']) . '</div>';
            }
        }
    } elseif ($action === 'bulk_manage_items') {
        $selectedIdsRaw = $_POST['selected_item_ids'] ?? [];
        if (!is_array($selectedIdsRaw)) {
            $selectedIdsRaw = [];
        }

        $selectedIds = [];
        foreach ($selectedIdsRaw as $rawId) {
            $itemId = (int)$rawId;
            if ($itemId > 0) {
                $selectedIds[$itemId] = $itemId;
            }
        }
        $selectedIds = array_values($selectedIds);

        if (empty($selectedIds)) {
            $message = '<div class="alert error">Pilih minimal satu barang untuk aksi massal.</div>';
        } else {
            $bulkAction = isset($_POST['bulk_action']) ? trim((string)$_POST['bulk_action']) : '';
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

            try {
                $pdo->beginTransaction();

                if ($bulkAction === 'update_status') {
                    $bulkStatus = isset($_POST['bulk_status']) ? trim((string)$_POST['bulk_status']) : '';
                    $validStatuses = ['in-stock', 'low-stock', 'warning-stock', 'out-stock'];
                    if (!in_array($bulkStatus, $validStatuses, true)) {
                        throw new Exception('Status massal tidak valid.');
                    }

                    $sql = "UPDATE items SET status = ?, updated_by = ?, last_updated = NOW() WHERE id IN ({$placeholders}) AND " . activeItemsWhereSql();
                    $paramsBulk = array_merge([$bulkStatus, $_SESSION['user_id']], $selectedIds);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($paramsBulk);
                    $affected = (int)$stmt->rowCount();
                    $message = '<div class="alert success">Status berhasil diperbarui untuk ' . $affected . ' barang.</div>';
                } elseif ($bulkAction === 'update_category') {
                    $bulkCategory = isset($_POST['bulk_category']) ? trim((string)$_POST['bulk_category']) : '';
                    if ($bulkCategory === '' || !in_array($bulkCategory, $itemCategories, true)) {
                        throw new Exception('Kategori massal tidak valid.');
                    }

                    $sql = "UPDATE items SET category = ?, updated_by = ?, last_updated = NOW() WHERE id IN ({$placeholders}) AND " . activeItemsWhereSql();
                    $paramsBulk = array_merge([$bulkCategory, $_SESSION['user_id']], $selectedIds);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($paramsBulk);
                    $affected = (int)$stmt->rowCount();
                    $message = '<div class="alert success">Kategori berhasil diperbarui untuk ' . $affected . ' barang.</div>';
                } elseif ($bulkAction === 'archive') {
                    if (!itemsSoftDeleteEnabled()) {
                        throw new Exception('Soft-delete belum aktif. Jalankan migrasi database terlebih dahulu.');
                    }

                    if (db_has_column('items', 'deleted_by')) {
                        $sql = "UPDATE items SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, last_updated = NOW() WHERE id IN ({$placeholders}) AND " . activeItemsWhereSql();
                        $paramsBulk = array_merge([$_SESSION['user_id'], $_SESSION['user_id']], $selectedIds);
                    } else {
                        $sql = "UPDATE items SET deleted_at = NOW(), updated_by = ?, last_updated = NOW() WHERE id IN ({$placeholders}) AND " . activeItemsWhereSql();
                        $paramsBulk = array_merge([$_SESSION['user_id']], $selectedIds);
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($paramsBulk);
                    $affected = (int)$stmt->rowCount();
                    $message = '<div class="alert success">Berhasil mengarsipkan ' . $affected . ' barang.</div>';
                } else {
                    throw new Exception('Aksi massal tidak dikenali.');
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '<div class="alert error">Aksi massal gagal: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
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

$expandedRaw = isset($_GET['expanded']) ? trim((string)$_GET['expanded']) : '';
$expandedRows = [];
if ($expandedRaw !== '') {
    foreach (explode(',', $expandedRaw) as $rawId) {
        $parsedId = (int)trim($rawId);
        if ($parsedId > 0) {
            $expandedRows[$parsedId] = $parsedId;
        }
    }
    $expandedRows = array_values($expandedRows);
}

$allowedPerPage = [10, 25, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

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
            i.id, i.name, i.category, i.field_stock, i.unit, i.unit_conversion{$levelConversionSelect}{$calculationModeSelect}, i.daily_consumption, i.min_days_coverage, i.description{$levelSelect}, i.status, i.last_updated, i.added_by as added_by_id, i.updated_by as updated_by_id{$hasLevelSelect},
            u.username AS added_by_name, u2.username AS updated_by_name
        FROM items i
        LEFT JOIN users u ON i.added_by = u.id
        LEFT JOIN users u2 ON i.updated_by = u2.id
    WHERE " . activeItemsWhereSql('i');
$countQuery = "SELECT COUNT(*)
    FROM items i
    WHERE " . activeItemsWhereSql('i');
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
    $categories = $itemCategories;
    $message = '<div class="alert error">DB error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// PRIMARY SORT
$query .= " ORDER BY i.{$sortBy} {$sortDir}";

// SECONDARY SORT: if sorting by something other than name and it's not last_updated, add name as secondary sort
if ($sortBy !== 'name' && $sortBy !== 'last_updated') {
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
    'expanded' => !empty($expandedRows) ? implode(',', $expandedRows) : '',
];

$buildQuery = function (array $overrides = []) use ($baseQueryParams): string {
    $next = array_merge($baseQueryParams, $overrides);

    foreach (['search', 'category', 'status', 'expanded'] as $optionalKey) {
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
    $categories = $itemCategories;
} catch (Exception $e) {
    $items = [];
    $categories = $itemCategories;
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

        <div class="modal-header-actions">
            <button type="button" class="btn-modal-add" onclick="openAddItemModal()">
                <i class='bx bx-plus'></i> Tambah Barang
            </button>
        </div>

        <div class="table-container">
            <section class="embedded-audit-panel" id="embedded-audit-panel" data-last-audit-id="<?php echo (int)$latestAuditMaxId; ?>">
                <div class="embedded-audit-header">
                    <h3>Panel Audit</h3>
                    <span class="embedded-audit-subtitle">Pemantauan perubahan data real-time</span>
                </div>
                <ul class="embedded-audit-list" id="embedded-audit-list">
                    <?php if (!empty($latestAuditRows)): ?>
                        <?php foreach ($latestAuditRows as $auditRow):
                            $changedByLabel = trim((string)($auditRow['changed_by_full_name'] ?? ''));
                            if ($changedByLabel === '') {
                                $changedByLabel = trim((string)($auditRow['changed_by_username'] ?? 'System'));
                            }
                            $changedFields = $formatAuditChangedFields($auditRow);
                        ?>
                            <li class="embedded-audit-item" data-audit-id="<?php echo (int)$auditRow['id']; ?>">
                                <div class="embedded-audit-top">
                                    <strong><?php echo htmlspecialchars((string)($auditRow['item_name'] ?? 'Item')); ?></strong>
                                    <span class="embedded-audit-time"><?php echo htmlspecialchars((string)($auditRow['changed_at'] ?? '-')); ?></span>
                                </div>
                                <div class="embedded-audit-meta">
                                    <span>Oleh: <?php echo htmlspecialchars($changedByLabel); ?></span>
                                    <span>Aksi: <?php echo htmlspecialchars((string)($auditRow['action'] ?? '-')); ?></span>
                                </div>
                                <div class="embedded-audit-fields">
                                    <?php foreach ($changedFields as $fieldLabel): ?>
                                        <span class="embedded-audit-badge"><?php echo htmlspecialchars((string)$fieldLabel); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="embedded-audit-empty" id="embedded-audit-empty">Belum ada riwayat perubahan data.</li>
                    <?php endif; ?>
                </ul>
            </section>

            <div class="table-header">
                <form method="GET" class="table-filters" data-filter-context="manage" data-default-sort="last_updated" data-default-dir="desc" data-server-grid="1">
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
                        <input type="hidden" name="expanded" id="expanded-state-input" value="<?php echo htmlspecialchars(!empty($expandedRows) ? implode(',', $expandedRows) : ''); ?>">
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
                    </div>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th class="bulk-select-header">
                            <input type="checkbox" id="select-all-items" aria-label="Pilih semua item di halaman ini">
                        </th>
                        <th>
                            <a href="<?php echo htmlspecialchars($buildQuery([
                                            'sort' => 'name',
                                            'dir' => ($sortBy === 'name' && $sortDir === 'DESC') ? 'asc' : 'desc',
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
                        <th>
                            <a href="<?php echo htmlspecialchars($buildQuery([
                                            'sort' => 'category',
                                            'dir' => ($sortBy === 'category' && $sortDir === 'DESC') ? 'asc' : 'desc',
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
                        <th>Stok</th>
                        <th>Pemakaian Harian</th>
                        <th>Ketahanan di lapangan</th>
                        <th>Status</th>
                        <th>Detail</th>
                        <th>Aksi</th>
                        <th>Terakhir Diperbarui</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        // Normalize fields
                        $id = isset($item['id']) ? (int)$item['id'] : 0;
                        $name = isset($item['name']) ? (string)$item['name'] : '';
                        $itemCategory = isset($item['category']) ? (string)$item['category'] : '';
                        $field_stock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
                        $unit_conversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
                        $daily_consumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
                        $level_conversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unit_conversion;
                        $level = array_key_exists('level', $item) ? $item['level'] : null;
                        $calculation_mode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
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
                                'category' => $itemCategory,
                                'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1,
                                'level_conversion' => $level_conversion,
                                'qty_conversion' => $unit_conversion,
                                'calculation_mode' => $calculation_mode
                            ]
                        );

                        $effectiveStock = calculateEffectiveStock($field_stock, $unit_conversion, $level, $hasLevel, [
                            'level_conversion' => $level_conversion,
                            'qty_conversion' => $unit_conversion,
                            'calculation_mode' => $calculation_mode
                        ]);

                        $resolvedDaily = resolveDailyConsumption($daily_consumption, [
                            'item_id' => $id,
                            'category' => $itemCategory,
                            'effective_stock' => $effectiveStock,
                            'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
                        ]);

                        $isRowExpanded = in_array($id, $expandedRows, true);
                    ?>
                        <tr data-item-id="<?php echo $id; ?>" class="item-main-row">
                            <td data-label="Pilih" class="bulk-select-cell">
                                <input type="checkbox" class="bulk-item-checkbox" value="<?php echo $id; ?>" aria-label="Pilih <?php echo htmlspecialchars($name); ?>">
                            </td>
                            <td data-label="Nama Barang"><?php echo htmlspecialchars($name); ?></td>
                            <td data-label="Kategori"><?php echo htmlspecialchars($itemCategory); ?></td>
                            <td data-label="Stok"><?php echo number_format((int)$field_stock); ?></td>
                            <td data-label="Pemakaian Harian"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                            <td data-label="Katahanan"><?php echo number_format((int)$daysCoverage); ?> hari</td>
                            <td data-label="Status"><span class="status <?php echo htmlspecialchars($status); ?>"><?php echo translateStatus($status, 'id'); ?></span></td>
                            <td data-label="Detail" class="item-expand-cell">
                                <button
                                    type="button"
                                    class="btn-page row-expand-toggle"
                                    data-target="details-row-<?php echo $id; ?>"
                                    aria-expanded="<?php echo $isRowExpanded ? 'true' : 'false'; ?>"
                                    aria-controls="details-row-<?php echo $id; ?>">
                                    <?php echo $isRowExpanded ? 'Tutup' : 'Detail'; ?>
                                </button>
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
                                        data-level-conversion="<?php echo number_format((float)($item['level_conversion'] ?? $item['unit_conversion']), 1, '.', ''); ?>"
                                        data-calculation-mode="<?php echo htmlspecialchars((string)($item['calculation_mode'] ?? 'combined'), ENT_QUOTES); ?>"
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
                        </tr>
                        <tr id="details-row-<?php echo $id; ?>" class="item-details-row" <?php echo $isRowExpanded ? '' : 'hidden'; ?>>
                            <td colspan="10">
                                <div class="item-details-grid">
                                    <div class="item-detail"><span class="label">Level (cm)</span><span class="value"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></span></div>
                                    <div class="item-detail"><span class="label">Mode Hitung</span><span class="value"><?php echo htmlspecialchars((string)$calculation_mode); ?></span></div>
                                    <div class="item-detail"><span class="label">Konversi Unit</span><span class="value"><?php echo number_format((float)$unit_conversion, 1, '.', ''); ?></span></div>
                                    <div class="item-detail"><span class="label">Konversi Level</span><span class="value"><?php echo number_format((float)$level_conversion, 1, '.', ''); ?></span></div>
                                    <div class="item-detail"><span class="label">Min. Hari Coverage</span><span class="value"><?php echo (int)($item['min_days_coverage'] ?? 0); ?></span></div>
                                    <div class="item-detail"><span class="label">Ditambahkan Oleh</span><span class="value"><?php echo htmlspecialchars((string)($item['added_by_name'] ?? '-')); ?></span></div>
                                    <div class="item-detail item-detail--full"><span class="label">Deskripsi</span><span class="value"><?php echo htmlspecialchars((string)($item['description'] ?? '-')); ?></span></div>
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

            <div class="bulk-action-sticky" id="bulk-action-bar" hidden>
                <form method="POST" id="bulk-action-form" class="bulk-action-form">
                    <input type="hidden" name="action" value="bulk_manage_items">
                    <div id="bulk-selected-inputs"></div>

                    <div class="bulk-action-summary">
                        <strong id="bulk-selected-count">0</strong> item dipilih
                    </div>

                    <div class="bulk-action-controls">
                        <select id="bulk_action" name="bulk_action" aria-label="Pilih aksi massal">
                            <option value="update_status">Ubah Status</option>
                            <option value="update_category">Ubah Kategori</option>
                            <option value="archive">Arsipkan</option>
                        </select>

                        <select id="bulk_status" name="bulk_status" aria-label="Pilih status massal">
                            <option value="in-stock"><?php echo translateStatus('in-stock', 'id'); ?></option>
                            <option value="low-stock"><?php echo translateStatus('low-stock', 'id'); ?></option>
                            <option value="warning-stock"><?php echo translateStatus('warning-stock', 'id'); ?></option>
                            <option value="out-stock"><?php echo translateStatus('out-stock', 'id'); ?></option>
                        </select>

                        <select id="bulk_category" name="bulk_category" aria-label="Pilih kategori massal" style="display:none;">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn-filter" id="bulk-apply-btn">Terapkan</button>
                        <button type="button" class="btn-page" id="bulk-clear-selection">Bersihkan Pilihan</button>
                    </div>
                </form>
            </div>

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

        <div class="app-modal-overlay" id="add-item-modal" onclick="closeItemModalOnBackdrop(event, 'add-item-modal')">
            <div class="app-modal app-modal--item" role="dialog" aria-modal="true" aria-labelledby="add-item-modal-title">
                <div class="app-modal-header">
                    <h3 id="add-item-modal-title">Tambah Barang</h3>
                    <button type="button" class="app-modal-close" onclick="closeItemModal('add-item-modal')">&times;</button>
                </div>
                <div class="app-modal-body">
                    <form method="POST" class="add-form" id="add-item-form">
                        <input type="hidden" name="action" value="create_item">
                        <?php
                        $formPrefix = 'add_';
                        $formState = $addItemState;
                        $formUnits = $units;
                        $formCategories = $itemCategories;
                        $formShowLevel = $itemsHasLevelFlag;
                        $formLevelGroupId = 'add-level-group';
                        include __DIR__ . '/../shared/item-form-fields.php';
                        ?>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Barang</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="app-modal-overlay" id="edit-item-modal" onclick="closeItemModalOnBackdrop(event, 'edit-item-modal')">
            <div class="app-modal app-modal--item" role="dialog" aria-modal="true" aria-labelledby="edit-item-modal-title">
                <div class="app-modal-header">
                    <h3 id="edit-item-modal-title">Edit Barang</h3>
                    <button type="button" class="app-modal-close" onclick="closeItemModal('edit-item-modal')">&times;</button>
                </div>
                <div class="app-modal-body">
                    <form method="POST" class="add-form" id="edit-item-form">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" id="edit_item_id" name="item_id" value="<?php echo htmlspecialchars((string)$editItemState['item_id']); ?>">
                        <?php
                        $formPrefix = 'edit_';
                        $formState = $editItemState;
                        $formUnits = $units;
                        $formCategories = $itemCategories;
                        $formShowLevel = $itemsHasLevelFlag;
                        $formLevelGroupId = 'edit-level-group';
                        include __DIR__ . '/../shared/item-form-fields.php';
                        ?>

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
                const addLevelConv = document.getElementById('add_level_conversion');
                const addCalcMode = document.getElementById('add_calculation_mode');
                const addCustomConv = document.getElementById('add_custom_conversion_factor');
                const addDaily = document.getElementById('add_daily_consumption');
                const addMinDays = document.getElementById('add_min_days_coverage');
                if (addFieldStock) addFieldStock.value = 0;
                if (addUnitConv) addUnitConv.value = '1.0';
                if (addLevelConv) addLevelConv.value = '1.0';
                if (addCalcMode) addCalcMode.value = 'combined';
                if (addCustomConv) addCustomConv.value = '1.0';
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
            const editLevelConversion = document.getElementById('edit_level_conversion');
            if (editLevelConversion) {
                editLevelConversion.value = button.dataset.levelConversion || button.dataset.unitConversion || '1.0';
            }
            const editCalcMode = document.getElementById('edit_calculation_mode');
            if (editCalcMode) {
                editCalcMode.value = button.dataset.calculationMode || 'combined';
            }
            const editCustomConversion = document.getElementById('edit_custom_conversion_factor');
            if (editCustomConversion) {
                editCustomConversion.value = button.dataset.levelConversion || button.dataset.unitConversion || '1.0';
            }
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
            const unitConversionGroup = document.getElementById(checkboxId.replace('has_level', 'unit-group-conversion'));
            const conversionGroup = document.getElementById(groupId + '-conversion');
            const modeGroup = document.getElementById(groupId + '-mode');
            const customConversionGroup = document.getElementById(groupId + '-custom-conversion');
            const modeSelectId = checkboxId.replace('has_level', 'calculation_mode');
            const modeSelect = document.getElementById(modeSelectId);
            const customConversionInput = document.getElementById(modeSelectId.replace('calculation_mode', 'custom_conversion_factor'));
            if (!group) return;
            if (!checkbox) {
                group.style.display = 'none';
                if (unitConversionGroup) unitConversionGroup.style.display = 'block';
                if (conversionGroup) conversionGroup.style.display = 'none';
                if (modeGroup) modeGroup.style.display = 'none';
                if (customConversionGroup) customConversionGroup.style.display = 'none';
                if (customConversionInput) customConversionInput.required = false;
                if (modeSelect) modeSelect.value = 'combined';
                return;
            }
            const levelEnabled = checkbox.checked;
            if (!levelEnabled && modeSelect) modeSelect.value = 'combined';
            const calcMode = modeSelect ? String(modeSelect.value || 'combined').toLowerCase() : 'combined';
            const useMultiplied = levelEnabled && calcMode === 'multiplied';

            group.style.display = levelEnabled ? 'block' : 'none';
            if (unitConversionGroup) unitConversionGroup.style.display = useMultiplied ? 'none' : 'block';
            if (conversionGroup) conversionGroup.style.display = levelEnabled && !useMultiplied ? 'block' : 'none';
            if (modeGroup) modeGroup.style.display = levelEnabled ? 'block' : 'none';
            if (customConversionGroup) customConversionGroup.style.display = useMultiplied ? 'block' : 'none';
            if (customConversionInput) customConversionInput.required = useMultiplied;
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

        const addCalcMode = document.getElementById('add_calculation_mode');
        if (addCalcMode) {
            addCalcMode.addEventListener('change', function() {
                updateLevelGroup('add_has_level', 'add-level-group');
            });
        }

        const editCalcMode = document.getElementById('edit_calculation_mode');
        if (editCalcMode) {
            editCalcMode.addEventListener('change', function() {
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

        (function() {
            const selectAll = document.getElementById('select-all-items');
            const rowCheckboxes = Array.from(document.querySelectorAll('.bulk-item-checkbox'));
            const bulkBar = document.getElementById('bulk-action-bar');
            const selectedCountEl = document.getElementById('bulk-selected-count');
            const selectedInputs = document.getElementById('bulk-selected-inputs');
            const bulkAction = document.getElementById('bulk_action');
            const bulkStatus = document.getElementById('bulk_status');
            const bulkCategory = document.getElementById('bulk_category');
            const bulkForm = document.getElementById('bulk-action-form');
            const clearSelectionBtn = document.getElementById('bulk-clear-selection');

            if (!bulkBar || !bulkForm || !selectedInputs) return;

            function getSelectedIds() {
                return rowCheckboxes
                    .filter(function(checkbox) {
                        return checkbox.checked;
                    })
                    .map(function(checkbox) {
                        return checkbox.value;
                    });
            }

            function syncHiddenInputs(selectedIds) {
                selectedInputs.innerHTML = '';
                selectedIds.forEach(function(id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_item_ids[]';
                    input.value = String(id);
                    selectedInputs.appendChild(input);
                });
            }

            function syncActionFields() {
                const action = bulkAction ? bulkAction.value : 'update_status';
                if (bulkStatus) {
                    bulkStatus.style.display = action === 'update_status' ? 'inline-block' : 'none';
                    bulkStatus.required = action === 'update_status';
                }
                if (bulkCategory) {
                    bulkCategory.style.display = action === 'update_category' ? 'inline-block' : 'none';
                    bulkCategory.required = action === 'update_category';
                }
            }

            function syncBulkBar() {
                const selectedIds = getSelectedIds();
                const selectedCount = selectedIds.length;

                if (selectedCountEl) {
                    selectedCountEl.textContent = String(selectedCount);
                }

                syncHiddenInputs(selectedIds);

                if (bulkBar) {
                    bulkBar.hidden = selectedCount < 1;
                }

                if (selectAll) {
                    if (selectedCount < 1) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    } else if (selectedCount === rowCheckboxes.length) {
                        selectAll.checked = true;
                        selectAll.indeterminate = false;
                    } else {
                        selectAll.checked = false;
                        selectAll.indeterminate = true;
                    }
                }
            }

            function clearSelection() {
                rowCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = false;
                });
                syncBulkBar();
            }

            rowCheckboxes.forEach(function(checkbox) {
                checkbox.addEventListener('change', syncBulkBar);
            });

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    const shouldSelect = !!selectAll.checked;
                    rowCheckboxes.forEach(function(checkbox) {
                        checkbox.checked = shouldSelect;
                    });
                    syncBulkBar();
                });
            }

            if (bulkAction) {
                bulkAction.addEventListener('change', syncActionFields);
            }

            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', clearSelection);
            }

            bulkForm.addEventListener('submit', function(event) {
                const selectedIds = getSelectedIds();
                if (selectedIds.length < 1) {
                    event.preventDefault();
                    if (typeof showModal === 'function') {
                        showModal({
                            title: 'Info',
                            message: 'Pilih minimal satu barang sebelum menjalankan aksi massal.',
                            type: 'info',
                            okText: 'OK'
                        });
                    }
                    return;
                }

                syncHiddenInputs(selectedIds);
                const currentAction = bulkAction ? bulkAction.value : '';
                if (currentAction !== 'archive') {
                    return;
                }

                event.preventDefault();
                if (typeof showModal === 'function') {
                    showModal({
                        title: 'Konfirmasi',
                        message: 'Arsipkan semua item yang dipilih?',
                        type: 'warning',
                        okText: 'Arsipkan',
                        cancelText: 'Batal',
                        showCancel: true,
                        callback: function(ok) {
                            if (!ok) return;
                            bulkForm.submit();
                        }
                    });
                } else {
                    bulkForm.submit();
                }
            });

            syncActionFields();
            syncBulkBar();
        })();

        (function() {
            const toggles = document.querySelectorAll('.row-expand-toggle');
            const expandedStateInput = document.getElementById('expanded-state-input');
            if (!toggles.length) return;

            function getExpandedIdsFromDom() {
                const expandedIds = [];
                toggles.forEach(function(btn) {
                    if (btn.getAttribute('aria-expanded') !== 'true') return;
                    const targetId = btn.getAttribute('data-target') || '';
                    const rowId = parseInt(String(targetId).replace('details-row-', ''), 10);
                    if (!Number.isNaN(rowId) && rowId > 0) {
                        expandedIds.push(rowId);
                    }
                });
                return expandedIds;
            }

            function syncExpandedStateUrl() {
                const expandedIds = getExpandedIdsFromDom();
                const expandedValue = expandedIds.join(',');

                if (expandedStateInput) {
                    expandedStateInput.value = expandedValue;
                }

                const url = new URL(window.location.href);
                if (expandedValue) {
                    url.searchParams.set('expanded', expandedValue);
                } else {
                    url.searchParams.delete('expanded');
                }

                history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
            }

            toggles.forEach(function(toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    const targetId = toggleBtn.getAttribute('data-target');
                    if (!targetId) return;

                    const detailsRow = document.getElementById(targetId);
                    if (!detailsRow) return;

                    const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
                    const nextExpanded = !isExpanded;

                    detailsRow.hidden = !nextExpanded;
                    toggleBtn.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
                    toggleBtn.textContent = nextExpanded ? 'Tutup' : 'Detail';
                    syncExpandedStateUrl();
                });
            });

            syncExpandedStateUrl();
        })();

        (function() {
            const panel = document.getElementById('embedded-audit-panel');
            const listEl = document.getElementById('embedded-audit-list');
            if (!panel || !listEl) return;

            let lastAuditId = parseInt(panel.getAttribute('data-last-audit-id') || '0', 10) || 0;

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function renderAuditItem(item) {
                const fields = Array.isArray(item.changed_fields) ? item.changed_fields : [];
                const fieldBadges = fields.map(function(field) {
                    return '<span class="embedded-audit-badge">' + escapeHtml(field) + '</span>';
                }).join('');

                return '<li class="embedded-audit-item" data-audit-id="' + Number(item.id || 0) + '">' +
                    '<div class="embedded-audit-top">' +
                    '<strong>' + escapeHtml(item.item_name || 'Item') + '</strong>' +
                    '<span class="embedded-audit-time">' + escapeHtml(item.changed_at || '-') + '</span>' +
                    '</div>' +
                    '<div class="embedded-audit-meta">' +
                    '<span>Oleh: ' + escapeHtml(item.changed_by || 'System') + '</span>' +
                    '<span>Aksi: ' + escapeHtml(item.action || '-') + '</span>' +
                    '</div>' +
                    '<div class="embedded-audit-fields">' + fieldBadges + '</div>' +
                    '</li>';
            }

            function trimAuditList(maxItems) {
                const nodes = listEl.querySelectorAll('.embedded-audit-item');
                if (nodes.length <= maxItems) return;
                for (let idx = maxItems; idx < nodes.length; idx++) {
                    nodes[idx].remove();
                }
            }

            function prependAuditItems(items) {
                if (!Array.isArray(items) || items.length < 1) return;

                const emptyEl = document.getElementById('embedded-audit-empty');
                if (emptyEl) {
                    emptyEl.remove();
                }

                items.forEach(function(item) {
                    listEl.insertAdjacentHTML('afterbegin', renderAuditItem(item));
                    const rowId = Number(item.id || 0);
                    if (rowId > lastAuditId) {
                        lastAuditId = rowId;
                    }
                });

                panel.setAttribute('data-last-audit-id', String(lastAuditId));
                trimAuditList(30);
            }

            function pollAuditFeed() {
                fetch('actions/audit-feed.php?since_id=' + encodeURIComponent(String(lastAuditId)), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Audit feed request gagal');
                        }
                        return response.json();
                    })
                    .then(function(payload) {
                        if (!payload || payload.success !== true) {
                            return;
                        }
                        if (Array.isArray(payload.rows) && payload.rows.length > 0) {
                            prependAuditItems(payload.rows);
                        }
                    })
                    .catch(function() {
                    });
            }

            window.setInterval(pollAuditFeed, 15000);
        })();
    </script>
</body>

</html>