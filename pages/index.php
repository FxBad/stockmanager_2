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

// Consolidated counts (single query) and safe fetch of recent updates
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

    // Check for has_level column
    $hasLevelCol = db_has_column('items', 'has_level');
    $levelSelect = $hasLevelCol ? ', i.has_level' : '';
    $hasLevelConversionCol = db_has_column('items', 'level_conversion');
    $levelConversionSelect = $hasLevelConversionCol ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
    $hasCalculationModeCol = db_has_column('items', 'calculation_mode');
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
         LIMIT 8"
    );
    $recentUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($recentUpdates)) {
        $recentUpdates = [];
    }
} catch (Exception $e) {
    // On DB error, fallback to zero counts and empty recent updates
    $inStock = $lowStock = $outStock = 0;
    $recentUpdates = [];
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

$formatDelta = static function ($value) {
    return ($value >= 0 ? '+' : '') . number_format($value, 1) . '%';
};

$formatPercent = static function ($value) {
    return number_format($value, 1) . '%';
};
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

        <section class="trend-section" aria-label="Visualisasi tren stok">
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
                <div class="dashboard-table-tools">
                    <input type="text" id="dashboard-item-search" placeholder="Cari item..." aria-label="Cari item" />
                    <div class="dashboard-filter-buttons" role="group" aria-label="Filter prioritas item">
                        <button type="button" class="active" data-filter="all">Semua</button>
                        <button type="button" data-filter="critical">Kritis</button>
                        <button type="button" data-filter="in-stock">Tersedia</button>
                    </div>
                </div>
            </div>
            <!-- Add data-labels for mobile -->
            <table>
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
                        $priorityLabel = ($status === 'out-stock') ? 'P1' : (($isCritical) ? 'P2' : 'P3');

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
                        <tr data-status="<?php echo htmlspecialchars($status); ?>" data-critical="<?php echo $isCritical ? '1' : '0'; ?>" data-search="<?php echo htmlspecialchars(strtolower(trim($name . ' ' . $category))); ?>" class="<?php echo $isCritical ? 'critical-row' : ''; ?>">
                            <td data-label="Prioritas"><span class="priority-badge <?php echo $isCritical ? 'critical' : 'normal'; ?>"><?php echo $priorityLabel; ?></span></td>
                            <td data-label="Nama Barang"><?php echo htmlspecialchars($name); ?></td>
                            <td data-label="Kategori"><?php echo htmlspecialchars($category); ?></td>
                            <td data-label="Stok"><?php echo number_format((int)$field_stock); ?></td>
                            <td data-label="Level (cm)"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                            <td data-label="Ketahanan di lapangan"><?php echo number_format((int)$daysCoverage); ?> Hari</td>
                            <td data-label="Status">
                                <span class="status <?php echo htmlspecialchars($status); ?>">
                                    <?php echo translateStatus($status, 'id'); ?>
                                </span>
                            </td>
                            <td data-label="Terakhir Diperbarui" class="last-login">
                                <?php if (!empty($item['last_updated'])): ?>
                                    <span class="timestamp">
                                        <i class='bx bx-time-five'></i>
                                        <?php
                                        // Ensure date formatting is handled in PHP
                                        echo date('d/m/Y, H:i', strtotime($item['last_updated'])) . ' WIB';
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="never-login">
                                        <i class='bx bx-x-circle'></i>
                                        Tidak Pernah
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