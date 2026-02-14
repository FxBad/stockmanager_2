<?php
$formPrefix = isset($formPrefix) ? (string)$formPrefix : '';
$formState = isset($formState) && is_array($formState) ? $formState : [];
$formUnits = isset($formUnits) && is_array($formUnits) ? $formUnits : [];
$formCategories = isset($formCategories) && is_array($formCategories) ? $formCategories : [];
$formShowLevel = isset($formShowLevel) ? (bool)$formShowLevel : false;
$formLevelGroupId = isset($formLevelGroupId) ? (string)$formLevelGroupId : ($formPrefix . 'level-group');

$nameValue = isset($formState['name']) ? (string)$formState['name'] : '';
$categoryValue = isset($formState['category']) ? (string)$formState['category'] : '';
$fieldStockValue = isset($formState['field_stock']) ? (int)$formState['field_stock'] : 0;
$unitValue = isset($formState['unit']) ? (string)$formState['unit'] : '';
$unitConversionValue = isset($formState['unit_conversion']) ? (float)$formState['unit_conversion'] : 1.0;
$levelConversionValue = isset($formState['level_conversion']) ? (float)$formState['level_conversion'] : $unitConversionValue;
$calculationModeValue = isset($formState['calculation_mode']) ? (string)$formState['calculation_mode'] : 'combined';
$customConversionValue = isset($formState['custom_conversion_factor']) && is_numeric($formState['custom_conversion_factor'])
    ? (float)$formState['custom_conversion_factor']
    : $levelConversionValue;
$dailyConsumptionValue = isset($formState['daily_consumption']) ? (float)$formState['daily_consumption'] : 0.0;
$minDaysCoverageValue = isset($formState['min_days_coverage']) ? (int)$formState['min_days_coverage'] : 7;
$descriptionValue = isset($formState['description']) ? (string)$formState['description'] : '';
$hasLevelValue = isset($formState['has_level']) ? (int)$formState['has_level'] : 0;
$levelValue = isset($formState['level']) ? (string)$formState['level'] : '';

$normalizedCategories = [];
foreach ($formCategories as $categoryOption) {
    $categoryOption = trim((string)$categoryOption);
    if ($categoryOption !== '') {
        $normalizedCategories[] = $categoryOption;
    }
}
$normalizedCategories = array_values(array_unique($normalizedCategories));

if ($categoryValue !== '' && !in_array($categoryValue, $normalizedCategories, true)) {
    $normalizedCategories[] = $categoryValue;
}

$hasMatchingUnit = false;
foreach ($formUnits as $unitOption) {
    if (isset($unitOption['value']) && (string)$unitOption['value'] === $unitValue) {
        $hasMatchingUnit = true;
        break;
    }
}
?>
<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'name'); ?>">Nama Barang</label>
    <input type="text" id="<?php echo htmlspecialchars($formPrefix . 'name'); ?>" name="name" value="<?php echo htmlspecialchars($nameValue); ?>" required>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'category'); ?>">Kategori</label>
    <select id="<?php echo htmlspecialchars($formPrefix . 'category'); ?>" name="category" required>
        <option value="">Pilih Kategori</option>
        <?php foreach ($normalizedCategories as $categoryOption): ?>
            <option value="<?php echo htmlspecialchars($categoryOption); ?>" <?php echo $categoryValue === $categoryOption ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($categoryOption); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'field_stock'); ?>">Stok</label>
    <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'field_stock'); ?>" name="field_stock" min="0" value="<?php echo $fieldStockValue; ?>" required>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'unit'); ?>">Satuan</label>
    <select id="<?php echo htmlspecialchars($formPrefix . 'unit'); ?>" name="unit" required>
        <?php if (empty($formUnits)): ?>
            <option value="" disabled selected>Belum ada kategori unit</option>
            <?php if ($unitValue !== ''): ?>
                <option value="<?php echo htmlspecialchars($unitValue); ?>" selected><?php echo htmlspecialchars($unitValue); ?></option>
            <?php endif; ?>
        <?php else: ?>
            <?php foreach ($formUnits as $unitOption): ?>
                <?php $optionValue = isset($unitOption['value']) ? (string)$unitOption['value'] : ''; ?>
                <?php $optionLabel = isset($unitOption['label']) ? (string)$unitOption['label'] : $optionValue; ?>
                <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $unitValue === $optionValue ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($optionLabel); ?>
                </option>
            <?php endforeach; ?>
            <?php if ($unitValue !== '' && !$hasMatchingUnit): ?>
                <option value="<?php echo htmlspecialchars($unitValue); ?>" selected><?php echo htmlspecialchars($unitValue); ?></option>
            <?php endif; ?>
        <?php endif; ?>
    </select>
</div>

<div class="form-group" id="<?php echo htmlspecialchars($formPrefix . 'unit-group-conversion'); ?>">
    <label for="<?php echo htmlspecialchars($formPrefix . 'unit_conversion'); ?>">Faktor Konversi Satuan</label>
    <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'unit_conversion'); ?>" name="unit_conversion" min="0.1" step="0.1" value="<?php echo number_format($unitConversionValue, 1, '.', ''); ?>" required>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'daily_consumption'); ?>">Konsumsi Harian</label>
    <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'daily_consumption'); ?>" name="daily_consumption" min="0" step="0.1" value="<?php echo number_format($dailyConsumptionValue, 1, '.', ''); ?>" required>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'min_days_coverage'); ?>">Minimum Periode (hari)</label>
    <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'min_days_coverage'); ?>" name="min_days_coverage" min="1" value="<?php echo $minDaysCoverageValue; ?>" required>
</div>

<div class="form-group">
    <label for="<?php echo htmlspecialchars($formPrefix . 'description'); ?>">Keterangan</label>
    <textarea id="<?php echo htmlspecialchars($formPrefix . 'description'); ?>" name="description" rows="3"><?php echo htmlspecialchars($descriptionValue); ?></textarea>
</div>

<?php if ($formShowLevel): ?>
    <div class="form-group">
        <label for="<?php echo htmlspecialchars($formPrefix . 'has_level'); ?>">
            <input type="checkbox" id="<?php echo htmlspecialchars($formPrefix . 'has_level'); ?>" name="has_level" value="1" <?php echo $hasLevelValue === 1 ? 'checked' : ''; ?>>
            Gunakan indikator level untuk kalkulasi ketahanan
        </label>
    </div>

    <div class="form-group" id="<?php echo htmlspecialchars($formLevelGroupId); ?>" style="<?php echo $hasLevelValue === 1 ? '' : 'display:none;'; ?>">
        <label for="<?php echo htmlspecialchars($formPrefix . 'level'); ?>">Level (cm)</label>
        <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'level'); ?>" name="level" min="0" step="1" value="<?php echo htmlspecialchars($levelValue); ?>">
    </div>

    <div class="form-group" id="<?php echo htmlspecialchars($formLevelGroupId . '-conversion'); ?>" style="<?php echo $hasLevelValue === 1 ? '' : 'display:none;'; ?>">
        <label for="<?php echo htmlspecialchars($formPrefix . 'level_conversion'); ?>">Faktor Konversi Level</label>
        <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'level_conversion'); ?>" name="level_conversion" min="0.1" step="0.1" value="<?php echo number_format($levelConversionValue, 1, '.', ''); ?>">
    </div>

    <div class="form-group" id="<?php echo htmlspecialchars($formLevelGroupId . '-mode'); ?>" style="<?php echo $hasLevelValue === 1 ? '' : 'display:none;'; ?>">
        <label for="<?php echo htmlspecialchars($formPrefix . 'calculation_mode'); ?>">Mode Perhitungan Level</label>
        <select id="<?php echo htmlspecialchars($formPrefix . 'calculation_mode'); ?>" name="calculation_mode">
            <option value="combined" <?php echo $calculationModeValue === 'combined' ? 'selected' : ''; ?>>Combined (level×konversi + stok×konversi)</option>
            <option value="multiplied" <?php echo $calculationModeValue === 'multiplied' ? 'selected' : ''; ?>>Multiplied (konversi×level×stok)</option>
        </select>
    </div>

    <div class="form-group" id="<?php echo htmlspecialchars($formLevelGroupId . '-custom-conversion'); ?>" style="<?php echo ($hasLevelValue === 1 && $calculationModeValue === 'multiplied') ? '' : 'display:none;'; ?>">
        <label for="<?php echo htmlspecialchars($formPrefix . 'custom_conversion_factor'); ?>">Faktor Konversi Kustom (Multiplied)</label>
        <input type="number" id="<?php echo htmlspecialchars($formPrefix . 'custom_conversion_factor'); ?>" name="custom_conversion_factor" min="0.1" step="0.1" value="<?php echo number_format($customConversionValue, 1, '.', ''); ?>" <?php echo ($hasLevelValue === 1 && $calculationModeValue === 'multiplied') ? 'required' : ''; ?>>
    </div>
<?php endif; ?>