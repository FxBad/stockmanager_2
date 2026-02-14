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

function calculateEffectiveStock($fieldStock, $unitConversion, $level = null, $hasLevel = false, array $context = [])
{
    $qtyStock = max(0.0, (float)$fieldStock);
    $qtyConversion = max(0.0, (float)$unitConversion);

    if (!(bool)$hasLevel) {
        return $qtyStock * $qtyConversion;
    }

    $levelStock = ($level === null || $level === '') ? 0.0 : max(0.0, (float)$level);
    $levelConversion = isset($context['level_conversion']) && is_numeric($context['level_conversion'])
        ? max(0.0, (float)$context['level_conversion'])
        : $qtyConversion;
    $qtyConversionCombined = isset($context['qty_conversion']) && is_numeric($context['qty_conversion'])
        ? max(0.0, (float)$context['qty_conversion'])
        : $qtyConversion;
    $calculationMode = isset($context['calculation_mode']) ? strtolower((string)$context['calculation_mode']) : 'combined';

    if ($calculationMode === 'multiplied') {
        return $levelConversion * $levelStock * $qtyStock;
    }

    return ($levelStock * $levelConversion) + ($qtyStock * $qtyConversionCombined);
}

function calculateDaysCoverage($fieldStock, $warehouseStock, $unitConversion, $dailyConsumption, $itemName = null, $level = null, $hasLevel = false, array $fallbackContext = [])
{
    // Note: $warehouseStock parameter kept for backward compatibility but no longer used
    // Coverage always derives from effective stock:
    // - combined: effective_stock = (level * level_conversion) + (field_stock * qty_conversion)
    // - multiplied: effective_stock = (level * custom_conversion_factor) * field_stock
    // days_coverage = floor(effective_stock / daily_consumption)
    $effectiveStock = calculateEffectiveStock($fieldStock, $unitConversion, $level, $hasLevel, $fallbackContext);

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

function itemSchemaFlags()
{
    static $flags = null;

    if ($flags !== null) {
        return $flags;
    }

    $flags = [
        'items_has_level_flag' => db_has_column('items', 'has_level'),
        'items_has_level_value' => db_has_column('items', 'level'),
        'items_has_level_conversion' => db_has_column('items', 'level_conversion'),
        'items_has_calculation_mode' => db_has_column('items', 'calculation_mode'),
        'items_has_warehouse_stock' => db_has_column('items', 'warehouse_stock'),
        'items_has_calculation_type' => db_has_column('items', 'calculation_type'),
        'hist_has_level' => db_has_column('item_stock_history', 'level'),
        'hist_has_warehouse_old' => db_has_column('item_stock_history', 'warehouse_stock_old'),
        'hist_has_warehouse_new' => db_has_column('item_stock_history', 'warehouse_stock_new'),
    ];

    return $flags;
}

function normalizeItemInput(array $input, array $schema)
{
    $hasLevelChecked = false;
    if (!empty($schema['items_has_level_flag'])) {
        $hasLevelChecked = isset($input['has_level']) && (string)$input['has_level'] !== '0';
    }

    $levelInput = isset($input['level']) ? trim((string)$input['level']) : '';
    $levelVal = null;
    if ($levelInput !== '' && ctype_digit($levelInput)) {
        $levelVal = (int)$levelInput;
    }

    $qtyConversion = isset($input['unit_conversion']) && is_numeric($input['unit_conversion']) ? round((float)$input['unit_conversion'], 1) : 1.0;
    $levelConversion = isset($input['level_conversion']) && is_numeric($input['level_conversion'])
        ? round((float)$input['level_conversion'], 1)
        : $qtyConversion;
    $customConversionFactor = isset($input['custom_conversion_factor']) && is_numeric($input['custom_conversion_factor'])
        ? round((float)$input['custom_conversion_factor'], 1)
        : null;
    $calculationModeRaw = isset($input['calculation_mode']) ? strtolower(trim((string)$input['calculation_mode'])) : 'combined';
    $calculationMode = in_array($calculationModeRaw, ['combined', 'multiplied'], true) ? $calculationModeRaw : 'combined';
    $effectiveLevelConversion = ($calculationMode === 'multiplied' && $customConversionFactor !== null)
        ? $customConversionFactor
        : $levelConversion;

    return [
        'name' => isset($input['name']) ? trim((string)$input['name']) : '',
        'category' => isset($input['category']) ? trim((string)$input['category']) : '',
        'field_stock' => isset($input['field_stock']) && is_numeric($input['field_stock']) ? (int)$input['field_stock'] : 0,
        'unit' => isset($input['unit']) ? trim((string)$input['unit']) : '',
        'unit_conversion' => $qtyConversion,
        'level_conversion' => $levelConversion,
        'custom_conversion_factor' => $customConversionFactor,
        'effective_level_conversion' => $effectiveLevelConversion,
        'calculation_mode' => $calculationMode,
        'daily_consumption' => isset($input['daily_consumption']) && is_numeric($input['daily_consumption']) ? round((float)$input['daily_consumption'], 1) : 0.0,
        'min_days_coverage' => isset($input['min_days_coverage']) && is_numeric($input['min_days_coverage']) ? (int)$input['min_days_coverage'] : 1,
        'description' => isset($input['description']) ? trim((string)$input['description']) : '',
        'has_level' => $hasLevelChecked ? 1 : 0,
        'level_input' => $levelInput,
        'level' => $levelVal,
    ];
}

function validateItemInput(array $normalized, array $schema)
{
    $errors = [];

    if ($normalized['name'] === '') {
        $errors[] = 'Nama barang harus diisi.';
    }
    if ($normalized['category'] === '') {
        $errors[] = 'Kategori harus diisi.';
    } elseif (!isItemCategoryValid($normalized['category'])) {
        $errors[] = 'Kategori tidak terdaftar pada Master Data kategori.';
    }
    if ($normalized['unit'] === '') {
        $errors[] = 'Satuan harus dipilih.';
    }
    if ($normalized['field_stock'] < 0) {
        $errors[] = 'Stok tidak boleh negatif.';
    }
    if ($normalized['unit_conversion'] <= 0) {
        $errors[] = 'Faktor konversi harus lebih dari 0.';
    }
    if ($normalized['calculation_mode'] === 'combined' && $normalized['level_conversion'] <= 0) {
        $errors[] = 'Faktor konversi level harus lebih dari 0.';
    }
    if (!in_array($normalized['calculation_mode'], ['combined', 'multiplied'], true)) {
        $errors[] = 'Mode perhitungan tidak valid.';
    }
    if ($normalized['calculation_mode'] === 'multiplied') {
        if (!isset($normalized['custom_conversion_factor']) || $normalized['custom_conversion_factor'] === null || (float)$normalized['custom_conversion_factor'] <= 0) {
            $errors[] = 'Faktor konversi kustom wajib diisi dan harus lebih dari 0 untuk mode multiplied.';
        }
    }
    if ($normalized['daily_consumption'] < 0) {
        $errors[] = 'Konsumsi harian tidak boleh negatif.';
    }
    if ($normalized['min_days_coverage'] < 1) {
        $errors[] = 'Minimum periode minimal 1 hari.';
    }

    if (!empty($schema['items_has_level_flag'])) {
        if ($normalized['has_level'] === 1) {
            if ($normalized['level_input'] === '') {
                $errors[] = 'Level wajib diisi saat mode level aktif.';
            } elseif (!ctype_digit($normalized['level_input'])) {
                $errors[] = 'Level tidak valid. Masukkan angka bulat (cm).';
            }
        } elseif ($normalized['level_input'] !== '') {
            $errors[] = 'Level hanya boleh diisi jika mode level aktif.';
        }

        if ($normalized['has_level'] !== 1 && $normalized['calculation_mode'] === 'multiplied') {
            $errors[] = 'Mode multiplied hanya boleh digunakan jika indikator level aktif.';
        }
    }

    return $errors;
}

function saveItemWithHistory(array $input, $userId, $mode = 'create', array $options = [])
{
    $result = [
        'success' => false,
        'message' => '',
        'errors' => [],
        'item_id' => null,
        'data' => null,
    ];

    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        $result['message'] = 'Koneksi database tidak tersedia.';
        return $result;
    }

    $pdo = $GLOBALS['pdo'];
    $schema = itemSchemaFlags();
    $normalized = normalizeItemInput($input, $schema);
    $result['data'] = $normalized;

    $mode = strtolower(trim((string)$mode));
    if ($mode !== 'create' && $mode !== 'update') {
        $result['message'] = 'Mode operasi item tidak valid.';
        return $result;
    }

    $itemId = isset($options['item_id']) ? (int)$options['item_id'] : 0;
    if ($mode === 'update' && $itemId <= 0) {
        $result['message'] = 'ID barang tidak valid.';
        return $result;
    }

    $errors = validateItemInput($normalized, $schema);
    if (!empty($errors)) {
        $result['errors'] = $errors;
        return $result;
    }

    $warehouseStock = 0;

    try {
        if ($mode === 'create') {
            $totalStockNew = calculateEffectiveStock(
                $normalized['field_stock'],
                $normalized['unit_conversion'],
                $normalized['level'],
                (bool)$normalized['has_level'],
                [
                    'level_conversion' => $normalized['effective_level_conversion'],
                    'qty_conversion' => $normalized['unit_conversion'],
                    'calculation_mode' => $normalized['calculation_mode']
                ]
            );
            $daysCoverageNew = calculateDaysCoverage(
                $normalized['field_stock'],
                0,
                $normalized['unit_conversion'],
                $normalized['daily_consumption'],
                $normalized['name'],
                $normalized['level'],
                (bool)$normalized['has_level'],
                [
                    'category' => $normalized['category'],
                    'min_days_coverage' => $normalized['min_days_coverage'],
                    'level_conversion' => $normalized['effective_level_conversion'],
                    'qty_conversion' => $normalized['unit_conversion'],
                    'calculation_mode' => $normalized['calculation_mode']
                ]
            );
            $statusNew = determineStatus($daysCoverageNew, $normalized['min_days_coverage']);
            $resolvedDaily = resolveDailyConsumption($normalized['daily_consumption'], [
                'category' => $normalized['category'],
                'effective_stock' => $totalStockNew,
                'min_days_coverage' => $normalized['min_days_coverage']
            ]);

            $pdo->beginTransaction();

            $columns = [
                'name',
                'category',
                'field_stock',
                'unit',
                'unit_conversion',
                'level_conversion',
                'calculation_mode',
                'daily_consumption',
                'min_days_coverage',
                'description',
                'added_by',
                'status'
            ];
            $placeholders = [
                ':name',
                ':category',
                ':field_stock',
                ':unit',
                ':unit_conversion',
                ':level_conversion',
                ':calculation_mode',
                ':daily_consumption',
                ':min_days_coverage',
                ':description',
                ':added_by',
                ':status'
            ];

            if (!empty($schema['items_has_warehouse_stock'])) {
                array_splice($columns, 3, 0, 'warehouse_stock');
                array_splice($placeholders, 3, 0, ':warehouse_stock');
            }
            if (!empty($schema['items_has_calculation_type'])) {
                $insertPos = count($columns) - 1;
                array_splice($columns, $insertPos, 0, 'calculation_type');
                array_splice($placeholders, $insertPos, 0, ':calculation_type');
            }
            if (empty($schema['items_has_calculation_mode'])) {
                $idx = array_search('calculation_mode', $columns, true);
                if ($idx !== false) {
                    array_splice($columns, $idx, 1);
                    array_splice($placeholders, $idx, 1);
                }
            }
            if (!empty($schema['items_has_level_value'])) {
                array_splice($columns, 8, 0, 'level');
                array_splice($placeholders, 8, 0, ':level');
            }
            if (!empty($schema['items_has_level_flag'])) {
                array_splice($columns, 8, 0, 'has_level');
                array_splice($placeholders, 8, 0, ':has_level');
            }
            if (empty($schema['items_has_level_conversion'])) {
                $idx = array_search('level_conversion', $columns, true);
                if ($idx !== false) {
                    array_splice($columns, $idx, 1);
                    array_splice($placeholders, $idx, 1);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
            $params = [
                ':name' => $normalized['name'],
                ':category' => $normalized['category'],
                ':field_stock' => $normalized['field_stock'],
                ':unit' => $normalized['unit'],
                ':unit_conversion' => $normalized['unit_conversion'],
                ':level_conversion' => $normalized['effective_level_conversion'],
                ':calculation_mode' => $normalized['calculation_mode'],
                ':daily_consumption' => $normalized['daily_consumption'],
                ':min_days_coverage' => $normalized['min_days_coverage'],
                ':description' => $normalized['description'],
                ':added_by' => (int)$userId,
                ':status' => $statusNew,
            ];

            if (empty($schema['items_has_level_conversion'])) {
                unset($params[':level_conversion']);
            }
            if (empty($schema['items_has_calculation_mode'])) {
                unset($params[':calculation_mode']);
            }

            if (!empty($schema['items_has_warehouse_stock'])) {
                $params[':warehouse_stock'] = $warehouseStock;
            }
            if (!empty($schema['items_has_calculation_type'])) {
                $params[':calculation_type'] = 'daily_consumption';
            }
            if (!empty($schema['items_has_level_value'])) {
                $params[':level'] = $normalized['level'];
            }
            if (!empty($schema['items_has_level_flag'])) {
                $params[':has_level'] = $normalized['has_level'];
            }

            $stmt->execute($params);
            $newItemId = (int)$pdo->lastInsertId();

            $history = buildItemHistoryInsert(
                $schema,
                [
                    'item_id' => $newItemId,
                    'item_name' => $normalized['name'],
                    'category' => $normalized['category'],
                    'action' => 'insert',
                    'field_stock_old' => null,
                    'field_stock_new' => $normalized['field_stock'],
                    'status_old' => null,
                    'status_new' => $statusNew,
                    'total_stock_old' => null,
                    'total_stock_new' => $totalStockNew,
                    'days_coverage_old' => null,
                    'days_coverage_new' => $daysCoverageNew,
                    'unit' => $normalized['unit'],
                    'unit_conversion' => $normalized['unit_conversion'],
                    'daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $normalized['daily_consumption'],
                    'min_days_coverage' => $normalized['min_days_coverage'],
                    'changed_by' => (int)$userId,
                    'note' => 'initial insert (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')',
                    'warehouse_stock_old' => null,
                    'warehouse_stock_new' => $warehouseStock,
                    'level' => $normalized['level'],
                ]
            );

            $histStmt = $pdo->prepare($history['sql']);
            $histStmt->execute($history['params']);

            $pdo->commit();
            $result['success'] = true;
            $result['item_id'] = $newItemId;
            return $result;
        }

        $stmtOld = $pdo->prepare('SELECT * FROM items WHERE id = ? AND ' . activeItemsWhereSql());
        $stmtOld->execute([$itemId]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            $result['message'] = 'Barang tidak ditemukan atau sudah diarsipkan.';
            return $result;
        }

        $pdo->beginTransaction();

        $newTotal = calculateEffectiveStock(
            $normalized['field_stock'],
            $normalized['unit_conversion'],
            $normalized['level'],
            (bool)$normalized['has_level'],
            [
                'level_conversion' => $normalized['effective_level_conversion'],
                'qty_conversion' => $normalized['unit_conversion'],
                'calculation_mode' => $normalized['calculation_mode']
            ]
        );
        $newDays = calculateDaysCoverage(
            $normalized['field_stock'],
            0,
            $normalized['unit_conversion'],
            $normalized['daily_consumption'],
            $normalized['name'],
            $normalized['level'],
            (bool)$normalized['has_level'],
            [
                'item_id' => $itemId,
                'category' => $normalized['category'],
                'min_days_coverage' => $normalized['min_days_coverage'],
                'level_conversion' => $normalized['effective_level_conversion'],
                'qty_conversion' => $normalized['unit_conversion'],
                'calculation_mode' => $normalized['calculation_mode']
            ]
        );
        $resolvedDaily = resolveDailyConsumption($normalized['daily_consumption'], [
            'item_id' => $itemId,
            'category' => $normalized['category'],
            'effective_stock' => $newTotal,
            'min_days_coverage' => $normalized['min_days_coverage']
        ]);
        $newStatus = determineStatus($newDays, $normalized['min_days_coverage']);

        $set = [
            'name = :name',
            'category = :category',
            'field_stock = :field_stock',
            'unit = :unit',
            'unit_conversion = :unit_conversion',
            'level_conversion = :level_conversion',
            'calculation_mode = :calculation_mode',
            'daily_consumption = :daily_consumption',
            'min_days_coverage = :min_days_coverage',
            'description = :description',
            'updated_by = :updated_by',
            'status = :status'
        ];
        if (!empty($schema['items_has_level_value'])) {
            $set[] = 'level = :level';
        }
        if (!empty($schema['items_has_level_flag'])) {
            $set[] = 'has_level = :has_level';
        }
        if (empty($schema['items_has_level_conversion'])) {
            $idx = array_search('level_conversion = :level_conversion', $set, true);
            if ($idx !== false) {
                array_splice($set, $idx, 1);
            }
        }
        if (empty($schema['items_has_calculation_mode'])) {
            $idx = array_search('calculation_mode = :calculation_mode', $set, true);
            if ($idx !== false) {
                array_splice($set, $idx, 1);
            }
        }

        $stmtUpdate = $pdo->prepare('UPDATE items SET ' . implode(', ', $set) . ' WHERE id = :id AND ' . activeItemsWhereSql());
        $updateParams = [
            ':name' => $normalized['name'],
            ':category' => $normalized['category'],
            ':field_stock' => $normalized['field_stock'],
            ':unit' => $normalized['unit'],
            ':unit_conversion' => $normalized['unit_conversion'],
            ':level_conversion' => $normalized['effective_level_conversion'],
            ':calculation_mode' => $normalized['calculation_mode'],
            ':daily_consumption' => $normalized['daily_consumption'],
            ':min_days_coverage' => $normalized['min_days_coverage'],
            ':description' => $normalized['description'],
            ':updated_by' => (int)$userId,
            ':status' => $newStatus,
            ':id' => $itemId,
        ];
        if (empty($schema['items_has_level_conversion'])) {
            unset($updateParams[':level_conversion']);
        }
        if (empty($schema['items_has_calculation_mode'])) {
            unset($updateParams[':calculation_mode']);
        }
        if (!empty($schema['items_has_level_value'])) {
            $updateParams[':level'] = $normalized['level'];
        }
        if (!empty($schema['items_has_level_flag'])) {
            $updateParams[':has_level'] = $normalized['has_level'];
        }

        $stmtUpdate->execute($updateParams);

        $oldTotal = calculateEffectiveStock(
            (float)($old['field_stock'] ?? 0),
            (float)($old['unit_conversion'] ?? 1),
            array_key_exists('level', $old) ? $old['level'] : null,
            isset($old['has_level']) ? (bool)$old['has_level'] : false,
            [
                'level_conversion' => isset($old['level_conversion']) ? (float)$old['level_conversion'] : (float)($old['unit_conversion'] ?? 1),
                'qty_conversion' => (float)($old['unit_conversion'] ?? 1),
                'calculation_mode' => isset($old['calculation_mode']) ? $old['calculation_mode'] : 'combined'
            ]
        );
        $oldHasLevel = isset($old['has_level']) ? (bool)$old['has_level'] : false;
        $oldDays = calculateDaysCoverage(
            (float)($old['field_stock'] ?? 0),
            0,
            (float)($old['unit_conversion'] ?? 1),
            (float)($old['daily_consumption'] ?? 0),
            (string)($old['name'] ?? ''),
            array_key_exists('level', $old) ? $old['level'] : null,
            $oldHasLevel,
            [
                'item_id' => $itemId,
                'category' => (string)($old['category'] ?? ''),
                'min_days_coverage' => (int)($old['min_days_coverage'] ?? 1),
                'level_conversion' => isset($old['level_conversion']) ? (float)$old['level_conversion'] : (float)($old['unit_conversion'] ?? 1),
                'qty_conversion' => (float)($old['unit_conversion'] ?? 1),
                'calculation_mode' => isset($old['calculation_mode']) ? $old['calculation_mode'] : 'combined'
            ]
        );

        $history = buildItemHistoryInsert(
            $schema,
            [
                'item_id' => $itemId,
                'item_name' => $normalized['name'],
                'category' => $normalized['category'],
                'action' => 'update',
                'field_stock_old' => (float)($old['field_stock'] ?? 0),
                'field_stock_new' => $normalized['field_stock'],
                'status_old' => (string)($old['status'] ?? ''),
                'status_new' => $newStatus,
                'total_stock_old' => $oldTotal,
                'total_stock_new' => $newTotal,
                'days_coverage_old' => $oldDays,
                'days_coverage_new' => $newDays,
                'unit' => $normalized['unit'],
                'unit_conversion' => $normalized['unit_conversion'],
                'daily_consumption' => isset($resolvedDaily['value']) ? (float)$resolvedDaily['value'] : $normalized['daily_consumption'],
                'min_days_coverage' => $normalized['min_days_coverage'],
                'changed_by' => (int)$userId,
                'note' => 'updated via centralized handler (consumption source: ' . (isset($resolvedDaily['source']) ? $resolvedDaily['source'] : 'manual') . ')',
                'warehouse_stock_old' => $warehouseStock,
                'warehouse_stock_new' => $warehouseStock,
                'level' => $normalized['level'],
            ]
        );

        $histStmt = $pdo->prepare($history['sql']);
        $histStmt->execute($history['params']);

        $pdo->commit();
        $result['success'] = true;
        $result['item_id'] = $itemId;
        return $result;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['message'] = $e->getMessage();
        return $result;
    }
}

function buildItemHistoryInsert(array $schema, array $payload)
{
    $columns = [
        'item_id',
        'item_name',
        'category',
        'action',
        'field_stock_old',
        'field_stock_new',
        'status_old',
        'status_new',
        'total_stock_old',
        'total_stock_new',
        'days_coverage_old',
        'days_coverage_new',
        'unit',
        'unit_conversion',
        'daily_consumption',
        'min_days_coverage',
        'changed_by',
        'note'
    ];

    $values = [
        ':item_id',
        ':item_name',
        ':category',
        ':action',
        ':field_stock_old',
        ':field_stock_new',
        ':status_old',
        ':status_new',
        ':total_stock_old',
        ':total_stock_new',
        ':days_coverage_old',
        ':days_coverage_new',
        ':unit',
        ':unit_conversion',
        ':daily_consumption',
        ':min_days_coverage',
        ':changed_by',
        ':note'
    ];

    if (!empty($schema['hist_has_warehouse_old'])) {
        array_splice($columns, 6, 0, 'warehouse_stock_old');
        array_splice($values, 6, 0, ':warehouse_stock_old');
    }
    if (!empty($schema['hist_has_warehouse_new'])) {
        $insertPos = !empty($schema['hist_has_warehouse_old']) ? 7 : 6;
        array_splice($columns, $insertPos, 0, 'warehouse_stock_new');
        array_splice($values, $insertPos, 0, ':warehouse_stock_new');
    }
    if (!empty($schema['hist_has_level'])) {
        array_splice($columns, 15, 0, 'level');
        array_splice($values, 15, 0, ':level');
    }

    $params = [
        ':item_id' => $payload['item_id'],
        ':item_name' => $payload['item_name'],
        ':category' => $payload['category'],
        ':action' => $payload['action'],
        ':field_stock_old' => $payload['field_stock_old'],
        ':field_stock_new' => $payload['field_stock_new'],
        ':status_old' => $payload['status_old'],
        ':status_new' => $payload['status_new'],
        ':total_stock_old' => $payload['total_stock_old'],
        ':total_stock_new' => $payload['total_stock_new'],
        ':days_coverage_old' => $payload['days_coverage_old'],
        ':days_coverage_new' => $payload['days_coverage_new'],
        ':unit' => $payload['unit'],
        ':unit_conversion' => $payload['unit_conversion'],
        ':daily_consumption' => $payload['daily_consumption'],
        ':min_days_coverage' => $payload['min_days_coverage'],
        ':changed_by' => $payload['changed_by'],
        ':note' => $payload['note'],
    ];

    if (!empty($schema['hist_has_warehouse_old'])) {
        $params[':warehouse_stock_old'] = $payload['warehouse_stock_old'];
    }
    if (!empty($schema['hist_has_warehouse_new'])) {
        $params[':warehouse_stock_new'] = $payload['warehouse_stock_new'];
    }
    if (!empty($schema['hist_has_level'])) {
        $params[':level'] = $payload['level'];
    }

    return [
        'sql' => 'INSERT INTO item_stock_history (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
        'params' => $params,
    ];
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

function formatRelativeTimeId($datetime, $nowTs = null)
{
    if (empty($datetime)) {
        return 'Belum pernah diperbarui';
    }

    $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    if ($timestamp === false || $timestamp <= 0) {
        return 'Belum pernah diperbarui';
    }

    if ($nowTs === null) {
        $nowTs = time();
    }

    $diff = (int)$nowTs - (int)$timestamp;

    if ($diff <= 29) {
        return 'Baru saja';
    }

    if ($diff < 3600) {
        $minutes = max(1, (int)floor($diff / 60));
        return 'Diperbarui ' . $minutes . ' menit lalu';
    }

    if ($diff < 86400) {
        $hours = max(1, (int)floor($diff / 3600));
        return 'Diperbarui ' . $hours . ' jam lalu';
    }

    if ($diff < 604800) {
        $days = max(1, (int)floor($diff / 86400));
        return 'Diperbarui ' . $days . ' hari lalu';
    }

    return 'Diperbarui pada ' . date('d/m/Y, H:i', $timestamp) . ' WIB';
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

function db_has_table($table)
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) return false;
    try {
        $stmt = $GLOBALS['pdo']->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
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

function getItemCategories()
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) return [];

    try {
        if (!db_has_table('item_categories')) {
            return [];
        }

        $sql = "SELECT name FROM item_categories WHERE is_active = 1 ORDER BY display_order ASC, name ASC";

        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($value) {
            return trim((string)$value);
        }, $rows), function ($value) {
            return $value !== '';
        }));
    } catch (Exception $e) {
        return [];
    }
}

function isItemCategoryValid($categoryName)
{
    $categoryName = trim((string)$categoryName);
    if ($categoryName === '') {
        return false;
    }

    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        return false;
    }

    if (!db_has_table('item_categories')) {
        return false;
    }

    try {
        $stmt = $GLOBALS['pdo']->prepare('SELECT id FROM item_categories WHERE name = :name AND is_active = 1 LIMIT 1');
        $stmt->execute([':name' => $categoryName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}
