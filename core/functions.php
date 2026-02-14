<?php
function estimateDailyConsumptionFromItemHistory($itemId)
{
    static $cache = [];

    $itemId = (int)$itemId;
    if ($itemId <= 0) {
        return null;
    }

    if (array_key_exists($itemId, $cache)) {
        return $cache[$itemId];
    }

    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        $cache[$itemId] = null;
        return null;
    }

    try {
        $stmt = $GLOBALS['pdo']->prepare("SELECT total_stock_old, total_stock_new, changed_at
                                        FROM item_stock_history
                                        WHERE item_id = ? AND total_stock_old IS NOT NULL AND total_stock_new IS NOT NULL
                                        ORDER BY changed_at ASC");
        $stmt->execute([$itemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$itemId] = null;
        return null;
    }

    if (!is_array($rows) || count($rows) < 2) {
        $cache[$itemId] = null;
        return null;
    }

    $rates = [];
    $prevTime = null;
    $prevStock = null;

    foreach ($rows as $row) {
        $currentTime = isset($row['changed_at']) ? strtotime((string)$row['changed_at']) : false;
        $currentStock = isset($row['total_stock_new']) ? (float)$row['total_stock_new'] : null;

        if ($currentTime === false || $currentStock === null) {
            continue;
        }

        if ($prevTime !== null && $prevStock !== null) {
            $elapsedDays = ($currentTime - $prevTime) / 86400;
            $stockUsed = $prevStock - $currentStock;

            // Only consume negative deltas as usage; ignore replenishment jumps.
            if ($elapsedDays > 0 && $stockUsed > 0) {
                $rates[] = $stockUsed / $elapsedDays;
            }
        }

        $prevTime = $currentTime;
        $prevStock = $currentStock;
    }

    if (empty($rates)) {
        $cache[$itemId] = null;
        return null;
    }

    sort($rates, SORT_NUMERIC);
    $count = count($rates);
    $middle = (int)floor($count / 2);

    if ($count % 2 === 0) {
        $median = ($rates[$middle - 1] + $rates[$middle]) / 2;
    } else {
        $median = $rates[$middle];
    }

    $cache[$itemId] = $median > 0 ? $median : null;
    return $cache[$itemId];
}

function estimateDailyConsumptionFromCategory($category)
{
    static $cache = [];

    $category = trim((string)$category);
    if ($category === '') {
        return null;
    }

    if (array_key_exists($category, $cache)) {
        return $cache[$category];
    }

    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        $cache[$category] = null;
        return null;
    }

    try {
        $stmt = $GLOBALS['pdo']->prepare("SELECT daily_consumption
                                                                                FROM items
                                                                                WHERE category = ?
                                                                                    AND daily_consumption > 0
                                                                                    AND " . activeItemsWhereSql() . "
                                                                                ORDER BY daily_consumption ASC");
        $stmt->execute([$category]);
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cache[$category] = null;
        return null;
    }

    if (!is_array($values) || empty($values)) {
        $cache[$category] = null;
        return null;
    }

    $values = array_map('floatval', $values);
    sort($values, SORT_NUMERIC);

    $count = count($values);
    $middle = (int)floor($count / 2);

    if ($count % 2 === 0) {
        $median = ($values[$middle - 1] + $values[$middle]) / 2;
    } else {
        $median = $values[$middle];
    }

    $cache[$category] = $median > 0 ? $median : null;
    return $cache[$category];
}

function resolveDailyConsumption($dailyConsumption, array $context = [])
{
    $manual = (float)$dailyConsumption;
    if ($manual > 0) {
        return [
            'value' => $manual,
            'source' => 'manual',
            'confidence' => 1.0
        ];
    }

    $itemId = isset($context['item_id']) ? (int)$context['item_id'] : 0;
    $category = isset($context['category']) ? (string)$context['category'] : '';
    $effectiveStock = isset($context['effective_stock']) ? (float)$context['effective_stock'] : 0.0;
    $minDaysCoverage = isset($context['min_days_coverage']) ? max(1, (int)$context['min_days_coverage']) : 1;

    $itemEstimated = estimateDailyConsumptionFromItemHistory($itemId);
    if ($itemEstimated !== null && $itemEstimated > 0) {
        return [
            'value' => $itemEstimated,
            'source' => 'item-history',
            'confidence' => 0.85
        ];
    }

    $categoryEstimated = estimateDailyConsumptionFromCategory($category);
    if ($categoryEstimated !== null && $categoryEstimated > 0) {
        return [
            'value' => $categoryEstimated,
            'source' => 'category-median',
            'confidence' => 0.65
        ];
    }

    if ($effectiveStock > 0 && $minDaysCoverage > 0) {
        return [
            'value' => $effectiveStock / $minDaysCoverage,
            'source' => 'rule-based',
            'confidence' => 0.4
        ];
    }

    return [
        'value' => 0.1,
        'source' => 'default',
        'confidence' => 0.2
    ];
}

function calculateDaysCoverage($fieldStock, $warehouseStock, $unitConversion, $dailyConsumption, $itemName = null, $level = null, $hasLevel = false, array $fallbackContext = [])
{
    // Note: $warehouseStock parameter kept for backward compatibility but no longer used
    // If hasLevel is true, coverage uses level-based effective stock:
    // days_coverage = floor(((field_stock * unit_conversion) * (level / 100)) / daily_consumption)
    $isLevelBased = (bool)$hasLevel;

    if ($isLevelBased) {
        if ($level === null || $level === '') {
            return 0;
        }
        $totalStock = ($fieldStock) * $unitConversion;
        $multiplier = ((float)$level) / 100.0;
        $effectiveStock = $totalStock * $multiplier;
    } else {
        $effectiveStock = ($fieldStock) * $unitConversion;
    }

    $fallbackContext['effective_stock'] = $effectiveStock;
    $resolved = resolveDailyConsumption($dailyConsumption, $fallbackContext);
    $effectiveDailyConsumption = isset($resolved['value']) ? (float)$resolved['value'] : 0.0;

    if ($effectiveDailyConsumption <= 0) {
        return 0;
    }

    return floor($effectiveStock / $effectiveDailyConsumption);
}

function determineStatus($daysCoverage, $minDaysCoverage)
{
    $daysCoverage = (float)$daysCoverage;
    $minDaysCoverage = max(1, (int)$minDaysCoverage);

    if ($daysCoverage <= 0) {
        return 'out-stock';
    } elseif ($daysCoverage <= $minDaysCoverage) {
        return 'low-stock';
    } elseif ($daysCoverage <= ($minDaysCoverage * 2)) {
        return 'warning-stock';
    } else {
        return 'in-stock';
    }
}

// Role helpers
function currentUserRole()
{
    return isset($_SESSION['user_role']) ? $_SESSION['user_role'] : null;
}

function isRole($role)
{
    return currentUserRole() === $role;
}

function requireRole(array $allowedRoles = [])
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    if (!in_array(currentUserRole(), $allowedRoles, true)) {
        // Log the denied access attempt (file + optional DB)
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $role = currentUserRole();
        $page = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : basename($_SERVER['PHP_SELF']);
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $ts = date('Y-m-d H:i:s');

        // Ensure logs directory exists
        $logDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'access_denied.log';
        $line = "{$ts} | user_id={$userId} | role={$role} | page={$page} | ip={$ip}" . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // Optional: attempt to write to DB table `access_log` if $pdo available
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $stmt = $GLOBALS['pdo']->prepare("INSERT INTO access_log (user_id, role, page, ip, created_at) VALUES (:user_id, :role, :page, :ip, CURRENT_TIMESTAMP)");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':role' => $role,
                    ':page' => $page,
                    ':ip' => $ip
                ]);
            } catch (Exception $e) {
                // Do not interrupt request flow if DB insert fails
            }
        }

        // Forbidden - render nicer 403 page from template
        http_response_code(403);
        // Prepare variables for template (template expects these names)
        $userId = $userId;
        $role = $role;
        $page = $page;
        $ip = $ip;
        $ts = $ts;
        // Include template if exists
        $tplPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . '403.php';
        if (is_file($tplPath)) {
            include $tplPath;
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>403 — Akses Ditolak</h1><p>Anda tidak memiliki izin untuk mengakses halaman ini.</p><p><a href="index.php">Kembali ke Dasbor</a></p></body></html>';
        }
        exit;
    }
}

// UI helper: returns 'active' if current page matches any in the array
function isActive($files)
{
    $current = basename($_SERVER['PHP_SELF']);
    $files = (array)$files;
    return in_array($current, $files) ? 'active' : '';
}

// Version helper for static assets
function getVersion()
{
    return 'v=' . time();
}

// Translate status codes to human-readable labels (supports Indonesian mapping)
function translateStatus($status, $lang = 'id')
{
    $status = (string)$status;
    $map_en = [
        'in-stock' => 'In Stock',
        'low-stock' => 'Low Stock',
        'warning-stock' => 'Warning',
        'out-stock' => 'Out of Stock',
    ];
    $map_id = [
        'in-stock' => 'Tersedia',
        'low-stock' => 'Stok Rendah',
        'warning-stock' => 'Peringatan',
        'out-stock' => 'Habis',
    ];

    if ($lang === 'id') {
        return isset($map_id[$status]) ? $map_id[$status] : ucwords(str_replace('-', ' ', $status));
    }

    return isset($map_en[$status]) ? $map_en[$status] : ucwords(str_replace('-', ' ', $status));
}

// Check if a table has a specific column (uses global $pdo)
function db_has_column($table, $column)
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) return false;
    try {
        $stmt = $GLOBALS['pdo']->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

// Soft-delete helpers for items table
function itemsSoftDeleteEnabled()
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = db_has_column('items', 'deleted_at');
    return $enabled;
}

function activeItemsWhereSql($alias = '')
{
    if (!itemsSoftDeleteEnabled()) {
        return '1=1';
    }

    $prefix = '';
    if ($alias !== '') {
        $prefix = rtrim($alias, '.') . '.';
    }

    return $prefix . 'deleted_at IS NULL';
}

// Get all active units from database
function getUnits()
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) return [];
    try {
        $stmt = $GLOBALS['pdo']->prepare("SELECT value, label FROM units WHERE is_active = 1 ORDER BY display_order ASC, label ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Return empty array if table doesn't exist yet
        return [];
    }
}
