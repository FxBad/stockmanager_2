<?php
session_start();
require_once __DIR__ . '/../cache_control.php';

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';
// Determine whether to show sensitive fields (early)
$showSensitive = isRole('office') || isRole('admin');

$kpiTargets = [
    'in-stock' => 80.0,
    'low-stock' => 15.0,
    'out-stock' => 5.0,
];

$inStock = 0;
$lowStock = 0;
$outStock = 0;
$totalItems = 0;
$recentUpdates = [];

$dashboardCacheKey = 'dashboard_summary_v1';
$dashboardCacheTtl = 45;
$dashboardSchemaKey = 'dashboard_schema_flags_v1';
$dashboardSchemaTtl = 86400;
$nowTs = time();
$cacheEntry = isset($_SESSION[$dashboardCacheKey]) && is_array($_SESSION[$dashboardCacheKey])
    ? $_SESSION[$dashboardCacheKey]
    : null;

$useCache = false;
if ($cacheEntry && isset($cacheEntry['cached_at'], $cacheEntry['inStock'], $cacheEntry['lowStock'], $cacheEntry['outStock'], $cacheEntry['recentUpdates'])) {
    $cacheAge = $nowTs - (int)$cacheEntry['cached_at'];
    if ($cacheAge >= 0 && $cacheAge <= $dashboardCacheTtl) {
        $inStock = (int)$cacheEntry['inStock'];
        $lowStock = (int)$cacheEntry['lowStock'];
        $outStock = (int)$cacheEntry['outStock'];
        $recentUpdates = is_array($cacheEntry['recentUpdates']) ? $cacheEntry['recentUpdates'] : [];
        $useCache = true;
    }
}

// Consolidated counts (single query) and safe fetch of recent updates
if (!$useCache) {
    try {
        $stmt = $pdo->query(
            "SELECT
                SUM(status = 'in-stock') AS instock,
                SUM(status IN ('low-stock', 'warning-stock')) AS lowstock,
                SUM(status = 'out-stock') AS outstock
            FROM items
            WHERE " . activeItemsWhereSql()
        );
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        $inStock = isset($counts['instock']) ? (int)$counts['instock'] : 0;
        $lowStock = isset($counts['lowstock']) ? (int)$counts['lowstock'] : 0;
        $outStock = isset($counts['outstock']) ? (int)$counts['outstock'] : 0;

        $schemaFlags = isset($_SESSION[$dashboardSchemaKey]) && is_array($_SESSION[$dashboardSchemaKey])
            ? $_SESSION[$dashboardSchemaKey]
            : null;

        $schemaFresh = false;
        if ($schemaFlags && isset($schemaFlags['cached_at'])) {
            $schemaFresh = (($nowTs - (int)$schemaFlags['cached_at']) >= 0) && (($nowTs - (int)$schemaFlags['cached_at']) <= $dashboardSchemaTtl);
        }

        if (!$schemaFresh) {
            $schemaFlags = [
                'cached_at' => $nowTs,
                'has_level' => db_has_column('items', 'has_level'),
                'has_level_conversion' => db_has_column('items', 'level_conversion'),
                'has_calculation_mode' => db_has_column('items', 'calculation_mode'),
            ];
            $_SESSION[$dashboardSchemaKey] = $schemaFlags;
        }

        $hasLevelCol = !empty($schemaFlags['has_level']);
        $hasLevelConversionCol = !empty($schemaFlags['has_level_conversion']);
        $hasCalculationModeCol = !empty($schemaFlags['has_calculation_mode']);

        $levelSelect = $hasLevelCol ? ', i.has_level' : '';
        $levelConversionSelect = $hasLevelConversionCol ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
        $calculationModeSelect = $hasCalculationModeCol ? ', i.calculation_mode' : ", 'combined' AS calculation_mode";

        // Get recent updates with critical-first priority then newest updates
        $stmt = $pdo->query(
            "SELECT i.id, i.name, i.category, i.field_stock, i.unit_conversion{$levelConversionSelect}{$calculationModeSelect}, i.daily_consumption, i.min_days_coverage, i.level, i.status, i.last_updated, u.username AS updated_by_name{$levelSelect}
             FROM items i
             LEFT JOIN users u ON i.updated_by = u.id
                WHERE " . activeItemsWhereSql('i') . "
             ORDER BY
                CASE
                    WHEN i.status = 'out-stock' THEN 1
                    WHEN i.status IN ('low-stock', 'warning-stock') THEN 2
                    WHEN i.status = 'in-stock' THEN 3
                    ELSE 4
                END,
                COALESCE(i.last_updated, '1970-01-01 00:00:00') DESC,
                i.id DESC
             LIMIT 12"
        );
        $recentUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($recentUpdates)) {
            $recentUpdates = [];
        }

        $_SESSION[$dashboardCacheKey] = [
            'cached_at' => $nowTs,
            'inStock' => $inStock,
            'lowStock' => $lowStock,
            'outStock' => $outStock,
            'recentUpdates' => $recentUpdates,
        ];
    } catch (Exception $e) {
        // On DB error, fallback to zero counts and empty recent updates
        $inStock = $lowStock = $outStock = 0;
        $recentUpdates = [];
    }
}

$totalItems = max(0, $inStock + $lowStock + $outStock);

$pctInStock = $totalItems > 0 ? ($inStock / $totalItems) * 100 : 0;
$pctLowStock = $totalItems > 0 ? ($lowStock / $totalItems) * 100 : 0;
$pctOutStock = $totalItems > 0 ? ($outStock / $totalItems) * 100 : 0;

$achievementInStock = $kpiTargets['in-stock'] > 0 ? ($pctInStock / $kpiTargets['in-stock']) * 100 : 0;
$achievementLowStock = $pctLowStock > 0 ? ($kpiTargets['low-stock'] / $pctLowStock) * 100 : 100;
$achievementOutStock = $pctOutStock > 0 ? ($kpiTargets['out-stock'] / $pctOutStock) * 100 : 100;

$deltaInStock = $pctInStock - $kpiTargets['in-stock'];
$deltaLowStock = $kpiTargets['low-stock'] - $pctLowStock;
$deltaOutStock = $kpiTargets['out-stock'] - $pctOutStock;

$criticalCount = $lowStock + $outStock;
$criticalPercentage = $totalItems > 0 ? ($criticalCount / $totalItems) * 100 : 0;
$serviceLevelPercentage = 100 - $criticalPercentage;
$defaultVisibleLimit = 5;

$formatDelta = static function ($value) {
    return ($value >= 0 ? '+' : '') . number_format($value, 1) . '%';
};

$formatPercent = static function ($value) {
    return number_format($value, 1) . '%';
};

$statusIcons = [
    'in-stock' => 'bx-check-circle',
    'low-stock' => 'bx-error',
    'warning-stock' => 'bx-error-alt',
    'out-stock' => 'bx-x-circle',
];
?>

<!DOCTYPE html>
<!-- Coding By CodingNepal - codingnepalweb.com -->
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <script>
        document.documentElement.classList.add('dashboard-loading');
    </script>

    <title>StockManager</title>
    <!-- CSS -->
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>" />
    <!-- Boxicons CSS -->
    <link
        href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css"
        rel="stylesheet" />
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <!-- Header Text -->
        <div class="main-title">
            <h2>Dashboard</h2>
        </div>

        <!-- Cards -->
        <div class="card-container">
            <div class="card card-instock" onclick="location.href='view.php?status=in-stock'">
                <div class="card-inner">
                    <i class="bx bx-trending-up"></i>
                    <span class="card-title">Tersedia</span>
                </div>
                <h3><?php echo $inStock; ?></h3>
                <div class="kpi-meta">
                    <span class="kpi-achievement">Capaian <?php echo $formatPercent($achievementInStock); ?></span>
                    <span class="kpi-delta <?php echo $deltaInStock >= 0 ? 'positive' : 'negative'; ?>">Delta <?php echo htmlspecialchars($formatDelta($deltaInStock)); ?></span>
                </div>
            </div>
            <div class="card card-lowstock" onclick="location.href='view.php?status=low-stock'">
                <div class="card-inner">
                    <i class="bx bx-trending-down"></i>
                    <span class="card-title">Stok Rendah</span>
                </div>
                <h3><?php echo $lowStock; ?></h3>
                <div class="kpi-meta">
                    <span class="kpi-achievement">Capaian <?php echo $formatPercent($achievementLowStock); ?></span>
                    <span class="kpi-delta <?php echo $deltaLowStock >= 0 ? 'positive' : 'negative'; ?>">Delta <?php echo htmlspecialchars($formatDelta($deltaLowStock)); ?></span>
                </div>
            </div>
            <div class="card card-outstock" onclick="location.href='view.php?status=out-stock'">
                <div class="card-inner">
                    <i class="bx bx-error-circle"></i>
                    <span class="card-title">Habis</span>
                </div>
                <h3><?php echo $outStock; ?></h3>
                <div class="kpi-meta">
                    <span class="kpi-achievement">Capaian <?php echo $formatPercent($achievementOutStock); ?></span>
                    <span class="kpi-delta <?php echo $deltaOutStock >= 0 ? 'positive' : 'negative'; ?>">Delta <?php echo htmlspecialchars($formatDelta($deltaOutStock)); ?></span>
                </div>
            </div>
        </div>

        <div class="exception-summary <?php echo $criticalCount > 0 ? 'is-exception' : 'is-normal'; ?>">
            <?php if ($criticalCount > 0): ?>
                <div class="exception-copy">
                    <h3>Butuh Tindakan Cepat</h3>
                    <p><?php echo number_format($criticalCount); ?> item kritis terdeteksi. Prioritaskan pembaruan stok untuk mencegah stockout.</p>
                </div>
                <div class="exception-actions">
                    <a class="exception-link" href="update-stock.php">Perbarui Stok Sekarang</a>
                    <a class="exception-link ghost" href="view.php?status=out-stock">Lihat Item Habis</a>
                </div>
            <?php else: ?>
                <div class="exception-copy">
                    <h3>Kondisi Terkendali</h3>
                    <p>Tidak ada item kritis saat ini. Ringkasan ditampilkan untuk verifikasi cepat.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-toggle-wrap">
            <button type="button" id="dashboard-detail-toggle" class="detail-toggle-btn" data-expanded="0" aria-expanded="false">
                Tampilkan Detail Lanjutan
            </button>
        </div>

        <section class="trend-section dashboard-detail-panel" aria-label="Visualisasi tren stok" hidden>
            <div class="trend-header">
                <h3>Tren Kondisi Stok</h3>
                <span class="trend-caption">Total Item Aktif: <?php echo number_format($totalItems); ?></span>
            </div>
            <div class="trend-grid">
                <div class="trend-card">
                    <p class="trend-label">Service Level</p>
                    <p class="trend-value"><?php echo $formatPercent($serviceLevelPercentage); ?></p>
                    <div class="trend-bar" role="img" aria-label="Service level <?php echo $formatPercent($serviceLevelPercentage); ?>">
                        <span style="width: <?php echo max(0, min(100, $serviceLevelPercentage)); ?>%;"></span>
                    </div>
                </div>
                <div class="trend-card">
                    <p class="trend-label">Rasio Item Kritis</p>
                    <p class="trend-value"><?php echo $formatPercent($criticalPercentage); ?></p>
                    <div class="trend-bar danger" role="img" aria-label="Rasio item kritis <?php echo $formatPercent($criticalPercentage); ?>">
                        <span style="width: <?php echo max(0, min(100, $criticalPercentage)); ?>%;"></span>
                    </div>
                </div>
            </div>
            <div class="trend-breakdown" role="list" aria-label="Distribusi status stok">
                <div class="trend-break-item" role="listitem">
                    <span class="label">Tersedia</span>
                    <div class="segment"><span class="in-stock" style="width: <?php echo max(0, min(100, $pctInStock)); ?>%;"></span></div>
                    <span class="value"><?php echo $formatPercent($pctInStock); ?></span>
                </div>
                <div class="trend-break-item" role="listitem">
                    <span class="label">Stok Rendah</span>
                    <div class="segment"><span class="low-stock" style="width: <?php echo max(0, min(100, $pctLowStock)); ?>%;"></span></div>
                    <span class="value"><?php echo $formatPercent($pctLowStock); ?></span>
                </div>
                <div class="trend-break-item" role="listitem">
                    <span class="label">Habis</span>
                    <div class="segment"><span class="out-stock" style="width: <?php echo max(0, min(100, $pctOutStock)); ?>%;"></span></div>
                    <span class="value"><?php echo $formatPercent($pctOutStock); ?></span>
                </div>
            </div>
        </section>

        <!-- Table -->
        <div class="table-container">
            <div class="table-heading-row">
                <h3>Prioritas Operasional</h3>
                <div class="dashboard-table-tools dashboard-detail-panel" hidden>
                    <input type="text" id="dashboard-item-search" placeholder="Cari item..." aria-label="Cari item" />
                    <div class="dashboard-filter-buttons" role="group" aria-label="Filter prioritas item">
                        <button type="button" class="active" data-filter="all">Semua</button>
                        <button type="button" data-filter="critical">Kritis</button>
                        <button type="button" data-filter="in-stock">Tersedia</button>
                    </div>
                </div>
            </div>
            <!-- Add data-labels for mobile -->
            <table class="dashboard-operational-table">
                <thead>
                    <tr>
                        <th>Prioritas</th>
                        <th>Nama Barang</th>
                        <th>Kategori</th>
                        <th>Stok</th>
                        <th>Level (cm)</th>
                        <th>Ketahanan di lapangan</th>
                        <th>Status</th>
                        <th>Terakhir Diperbarui</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $defaultCriticalShown = 0;
                    $defaultNormalShown = 0;
                    ?>
                    <?php foreach ($recentUpdates as $item):
                        // Normalize and cast fields to safe types
                        $name = isset($item['name']) ? (string)$item['name'] : '';
                        $category = isset($item['category']) ? (string)$item['category'] : '';
                        $field_stock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
                        $unit_conversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
                        $level_conversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unit_conversion;
                        $daily_consumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
                        $level = array_key_exists('level', $item) ? $item['level'] : null;
                        $calculation_mode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
                        $status = isset($item['status']) ? (string)$item['status'] : '';
                        $itemId = isset($item['id']) ? (int)$item['id'] : 0;

                        // Determine if item uses level-based calculation
                        $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;
                        $isCritical = in_array($status, ['low-stock', 'warning-stock', 'out-stock'], true);
                        $priorityLabel = ($status === 'out-stock') ? 'P1 Kritis' : (($isCritical) ? 'P2 Kritis' : 'P3 Normal');
                        $statusIcon = isset($statusIcons[$status]) ? $statusIcons[$status] : 'bx-info-circle';

                        $isDefaultVisible = false;
                        if ($criticalCount > 0) {
                            if ($isCritical && $defaultCriticalShown < $defaultVisibleLimit) {
                                $isDefaultVisible = true;
                                $defaultCriticalShown++;
                            }
                        } else {
                            if ($defaultNormalShown < $defaultVisibleLimit) {
                                $isDefaultVisible = true;
                                $defaultNormalShown++;
                            }
                        }

                        $daysCoverage = calculateDaysCoverage(
                            $field_stock,
                            0,
                            $unit_conversion,
                            $daily_consumption,
                            $name,
                            $level,
                            $hasLevel,
                            [
                                'item_id' => $itemId,
                                'category' => $category,
                                'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1,
                                'level_conversion' => $level_conversion,
                                'qty_conversion' => $unit_conversion,
                                'calculation_mode' => $calculation_mode
                            ]
                        );
                    ?>
                        <tr data-status="<?php echo htmlspecialchars($status); ?>" data-critical="<?php echo $isCritical ? '1' : '0'; ?>" data-default-visible="<?php echo $isDefaultVisible ? '1' : '0'; ?>" data-search="<?php echo htmlspecialchars(strtolower(trim($name . ' ' . $category))); ?>" class="<?php echo $isCritical ? 'critical-row' : ''; ?>">
                            <td data-label="Prioritas"><span class="priority-badge <?php echo $isCritical ? 'critical' : 'normal'; ?>"><?php echo $priorityLabel; ?></span></td>
                            <td data-label="Nama Barang"><?php echo htmlspecialchars($name); ?></td>
                            <td data-label="Kategori"><?php echo htmlspecialchars($category); ?></td>
                            <td data-label="Stok"><?php echo number_format((int)$field_stock); ?></td>
                            <td data-label="Level (cm)"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                            <td data-label="Ketahanan di lapangan"><?php echo number_format((int)$daysCoverage); ?> Hari</td>
                            <td data-label="Status">
                                <span class="status <?php echo htmlspecialchars($status); ?>">
                                    <i class='bx <?php echo htmlspecialchars($statusIcon); ?>' aria-hidden="true"></i>
                                    <?php echo translateStatus($status, 'id'); ?>
                                </span>
                            </td>
                            <td data-label="Terakhir Diperbarui" class="last-login">
                                <?php if (!empty($item['last_updated'])): ?>
                                    <span class="timestamp">
                                        <i class='bx bx-time-five'></i>
                                        <?php echo htmlspecialchars(formatRelativeTimeId($item['last_updated'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="never-login">
                                        <i class='bx bx-x-circle'></i>
                                        Belum pernah diperbarui
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="actions-cell">
                                <a class="action-link" href="update-stock.php">Perbarui</a>
                                <?php if ($showSensitive && $itemId > 0): ?>
                                    <a class="action-link ghost" href="edit-item.php?id=<?php echo $itemId; ?>">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentUpdates)): ?>
                        <tr>
                            <td colspan="9" class="empty-row">Belum ada data stok untuk ditampilkan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- JavaScript -->
    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script src="transition.js"></script>
</body>

</html>