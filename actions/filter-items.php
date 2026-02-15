<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$context = isset($_GET['context']) ? strtolower(trim((string)$_GET['context'])) : 'view';
if ($context !== 'manage') {
    $context = 'view';
}

if ($context === 'manage' && !(isRole('office') || isRole('admin'))) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$defaultSort = $context === 'manage' ? 'last_updated' : 'name';
$defaultDir = $context === 'manage' ? 'desc' : 'asc';

$sortBy = isset($_GET['sort']) ? (string)$_GET['sort'] : $defaultSort;
$sortDir = isset($_GET['dir']) && strtolower((string)$_GET['dir']) === 'desc' ? 'DESC' : 'ASC';
if (!isset($_GET['dir'])) {
    $sortDir = strtoupper($defaultDir);
}

$validSortColumns = $context === 'manage'
    ? ['name', 'category', 'last_updated']
    : ['name', 'category', 'field_stock', 'last_updated'];
if (!in_array($sortBy, $validSortColumns, true)) {
    $sortBy = $defaultSort;
}

$hasLevelCol = db_has_column('items', 'has_level');
$levelSelect = $hasLevelCol ? ', i.has_level' : '';
$hasLevelConversionCol = db_has_column('items', 'level_conversion');
$levelConversionSelect = $hasLevelConversionCol ? ', i.level_conversion' : ', i.unit_conversion AS level_conversion';
$hasCalculationModeCol = db_has_column('items', 'calculation_mode');
$calculationModeSelect = $hasCalculationModeCol ? ', i.calculation_mode' : ", 'combined' AS calculation_mode";

$query = "SELECT
            i.id, i.name, i.category, i.field_stock, i.unit_conversion{$levelConversionSelect}{$calculationModeSelect}, i.daily_consumption, i.min_days_coverage, i.level, i.status, i.last_updated,
            u.full_name as added_by_name,
            u2.full_name as updated_by_name{$levelSelect}
          FROM items i
          LEFT JOIN users u ON i.added_by = u.id
          LEFT JOIN users u2 ON i.updated_by = u2.id
                    WHERE " . activeItemsWhereSql('i');
$params = [];

if ($category !== '') {
    $query .= ' AND i.category = ?';
    $params[] = $category;
}

if ($status !== '') {
    $query .= ' AND i.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $query .= ' AND i.name LIKE ?';
    $params[] = "%{$search}%";
}

$query .= " ORDER BY i.{$sortBy} {$sortDir}";
if ($sortBy !== 'name') {
    $query .= ', i.name ASC';
}

$showSensitive = isRole('office') || isRole('admin');
$colspan = $context === 'manage' ? 10 : ($showSensitive ? 8 : 7);

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();

    if (empty($items)) {
?>
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="no-data"><?php echo $context === 'manage' ? 'Tidak ada barang' : 'Tidak ada barang ditemukan'; ?></td>
        </tr>
        <?php
    } else {
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : 0;
            $name = isset($item['name']) ? (string)$item['name'] : '';
            $itemCategory = isset($item['category']) ? (string)$item['category'] : '';
            $fieldStock = isset($item['field_stock']) ? (float)$item['field_stock'] : 0;
            $unitConversion = isset($item['unit_conversion']) ? (float)$item['unit_conversion'] : 1;
            $levelConversion = isset($item['level_conversion']) ? (float)$item['level_conversion'] : $unitConversion;
            $dailyConsumption = isset($item['daily_consumption']) ? (float)$item['daily_consumption'] : 0;
            $level = array_key_exists('level', $item) ? $item['level'] : null;
            $calculationMode = isset($item['calculation_mode']) ? (string)$item['calculation_mode'] : 'combined';
            $itemStatus = isset($item['status']) ? (string)$item['status'] : '';
            $hasLevel = isset($item['has_level']) ? (bool)$item['has_level'] : false;

            $daysCoverage = calculateDaysCoverage(
                $fieldStock,
                0,
                $unitConversion,
                $dailyConsumption,
                $name,
                $level,
                $hasLevel,
                [
                    'item_id' => $id,
                    'category' => $itemCategory,
                    'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1,
                    'level_conversion' => $levelConversion,
                    'qty_conversion' => $unitConversion,
                    'calculation_mode' => $calculationMode
                ]
            );

            $effectiveStock = calculateEffectiveStock($fieldStock, $unitConversion, $level, $hasLevel, [
                'level_conversion' => $levelConversion,
                'qty_conversion' => $unitConversion,
                'calculation_mode' => $calculationMode
            ]);

            $resolvedDaily = resolveDailyConsumption($dailyConsumption, [
                'item_id' => $id,
                'category' => $itemCategory,
                'effective_stock' => $effectiveStock,
                'min_days_coverage' => isset($item['min_days_coverage']) ? (int)$item['min_days_coverage'] : 1
            ]);

            if ($context === 'manage') {
        ?>
                <tr data-item-id="<?php echo $id; ?>">
                    <td data-label="Nama Barang"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Kategori"><?php echo htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Stok" class="numeric-col"><?php echo number_format((int)$fieldStock); ?></td>
                    <td data-label="Pemakaian Harian" class="numeric-col"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                    <td data-label="Level (cm)" class="numeric-col"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                    <td data-label="Katahanan" class="numeric-col"><?php echo number_format((int)$daysCoverage); ?> hari</td>
                    <td data-label="Status"><span class="status <?php echo htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(translateStatus($itemStatus, 'id'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td data-label="Terakhir Diperbarui" class="last-login">
                        <?php if (!empty($item['last_updated'])): ?>
                            <span class="timestamp">
                                <i class='bx bx-time-five'></i>
                                <?php echo htmlspecialchars((string)$item['last_updated'], ENT_QUOTES, 'UTF-8'); ?>
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
            <?php
            } else {
            ?>
                <tr>
                    <td data-label="Nama Barang"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Kategori"><?php echo htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Stok" class="numeric-col"><?php echo number_format((int)$fieldStock); ?></td>
                    <?php if ($showSensitive): ?>
                        <td data-label="Pemakaian Harian" class="numeric-col"><?php echo number_format((float)$resolvedDaily['value'], 2); ?><?php echo ((isset($resolvedDaily['source']) && $resolvedDaily['source'] !== 'manual') ? ' (est.)' : ''); ?></td>
                    <?php endif; ?>
                    <td data-label="Level (cm)" class="numeric-col"><?php echo $hasLevel ? (isset($level) ? (int)$level : '-') : '-'; ?></td>
                    <td data-label="Ketahanan di lapangan" class="numeric-col"><?php echo number_format($daysCoverage, 1); ?> Hari</td>
                    <td data-label="Status">
                        <span class="status <?php echo htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(translateStatus($itemStatus, 'id'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td data-label="Terakhir Diperbarui" class="last-login">
                        <?php if (!empty($item['last_updated'])): ?>
                            <span class="timestamp">
                                <i class='bx bx-time-five'></i>
                                <?php echo date('d/m/Y, H:i', strtotime((string)$item['last_updated'])) . ' WIB'; ?>
                            </span>
                        <?php else: ?>
                            <span class="never-login">
                                <i class='bx bx-x-circle'></i>
                                Tidak Pernah
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
<?php
            }
        }
    }

    $html = ob_get_clean();
    echo json_encode(['html' => $html], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
