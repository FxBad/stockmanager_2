<?php
session_start();
require_once __DIR__ . '/../cache_control.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

$message = '';
$modalToOpen = '';
$addFormState = [
    'value' => '',
    'label' => '',
    'display_order' => 0,
];
$editFormState = [
    'unit_id' => '',
    'value' => '',
    'label' => '',
    'display_order' => 0,
];

function normalizeUnitValue($value)
{
    $value = trim((string)$value);
    $value = strtolower($value);
    return preg_replace('/\s+/', '_', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    try {
        if ($action === 'create_unit' || $action === 'update_unit') {
            $unitId = isset($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
            $rawValue = isset($_POST['value']) ? $_POST['value'] : '';
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;

            if ($action === 'create_unit') {
                $addFormState['value'] = trim((string)$rawValue);
                $addFormState['label'] = $label;
                $addFormState['display_order'] = $displayOrder;
            } else {
                $editFormState['unit_id'] = (string)$unitId;
                $editFormState['value'] = trim((string)$rawValue);
                $editFormState['label'] = $label;
                $editFormState['display_order'] = $displayOrder;
            }

            $value = normalizeUnitValue($rawValue);

            if ($label === '') {
                throw new Exception('Label satuan wajib diisi.');
            }
            if (strlen($label) > 100) {
                throw new Exception('Label satuan maksimal 100 karakter.');
            }

            if ($value === '') {
                throw new Exception('Value satuan wajib diisi.');
            }
            if (!preg_match('/^[a-z0-9_\-]{1,50}$/', $value)) {
                throw new Exception('Value satuan hanya boleh huruf kecil, angka, underscore, atau dash (maks 50 karakter).');
            }

            if ($displayOrder < 0 || $displayOrder > 9999) {
                throw new Exception('Display order harus di antara 0 sampai 9999.');
            }

            if ($action === 'create_unit') {
                $stmt = $pdo->prepare('INSERT INTO units (value, label, display_order, is_active) VALUES (:value, :label, :display_order, 1)');
                $stmt->execute([
                    ':value' => $value,
                    ':label' => $label,
                    ':display_order' => $displayOrder,
                ]);
                $addFormState = [
                    'value' => '',
                    'label' => '',
                    'display_order' => 0,
                ];
                $message = '<div class="alert success">Satuan berhasil ditambahkan.</div>';
            } else {
                if ($unitId <= 0) {
                    throw new Exception('ID satuan tidak valid.');
                }

                $stmt = $pdo->prepare('UPDATE units SET value = :value, label = :label, display_order = :display_order, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':value' => $value,
                    ':label' => $label,
                    ':display_order' => $displayOrder,
                    ':id' => $unitId,
                ]);

                if ($stmt->rowCount() > 0) {
                    $editFormState = [
                        'unit_id' => '',
                        'value' => '',
                        'label' => '',
                        'display_order' => 0,
                    ];
                    $message = '<div class="alert success">Satuan berhasil diperbarui.</div>';
                } else {
                    $message = '<div class="alert error">Tidak ada perubahan data atau satuan tidak ditemukan.</div>';
                }
            }
        } elseif ($action === 'toggle_status') {
            $unitId = isset($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
            $currentStatus = isset($_POST['current_status']) ? (int)$_POST['current_status'] : 0;

            if ($unitId <= 0) {
                throw new Exception('ID satuan tidak valid.');
            }

            $newStatus = $currentStatus === 1 ? 0 : 1;
            $stmt = $pdo->prepare('UPDATE units SET is_active = :is_active, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':is_active' => $newStatus,
                ':id' => $unitId,
            ]);

            if ($stmt->rowCount() > 0) {
                $message = '<div class="alert success">Status satuan berhasil diperbarui.</div>';
            } else {
                $message = '<div class="alert error">Satuan tidak ditemukan atau status tidak berubah.</div>';
            }
        } else {
            throw new Exception('Aksi tidak dikenali.');
        }
    } catch (PDOException $e) {
        if ($action === 'create_unit') {
            $modalToOpen = 'add-unit-modal';
        } elseif ($action === 'update_unit') {
            $modalToOpen = 'edit-unit-modal';
        }

        if ((int)$e->getCode() === 23000) {
            $message = '<div class="alert error">Value satuan sudah digunakan. Gunakan value yang berbeda.</div>';
        } else {
            $message = '<div class="alert error">Kesalahan database: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } catch (Exception $e) {
        if ($action === 'create_unit') {
            $modalToOpen = 'add-unit-modal';
        } elseif ($action === 'update_unit') {
            $modalToOpen = 'edit-unit-modal';
        }

        $message = '<div class="alert error">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

try {
    $stmt = $pdo->query('SELECT id, value, label, display_order, is_active, created_at, updated_at FROM units ORDER BY is_active DESC, display_order ASC, label ASC');
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $units = [];
    $message = '<div class="alert error">Gagal memuat data satuan: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Satuan - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion('style.css'); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        .units-header-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .btn-add-unit {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            padding: 10px 14px;
            background-color: var(--fern-green);
            color: #fff;
            font-weight: 600;
        }

        .btn-add-unit:hover {
            background-color: var(--hunter-green);
        }

        .unit-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .unit-modal-overlay.show {
            display: flex;
        }

        .unit-modal {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .unit-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #eee;
        }

        .unit-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
        }

        .unit-modal-close {
            border: none;
            background: transparent;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .unit-modal-body {
            padding: 16px;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Kelola Satuan</h2>
        </div>

        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <div class="units-header-actions">
            <button type="button" class="btn-add-unit" onclick="openAddUnitModal()">
                <i class='bx bx-plus'></i> Tambah Satuan
            </button>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h3>Daftar Satuan</h3>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Value</th>
                        <th>Label</th>
                        <th>Urutan</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Diperbarui</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($units as $unit): ?>
                        <?php
                        $unitId = isset($unit['id']) ? (int)$unit['id'] : 0;
                        $isActive = isset($unit['is_active']) ? (int)$unit['is_active'] : 0;
                        ?>
                        <tr>
                            <td data-label="Value"><?php echo htmlspecialchars((string)$unit['value']); ?></td>
                            <td data-label="Label"><?php echo htmlspecialchars((string)$unit['label']); ?></td>
                            <td data-label="Urutan"><?php echo (int)$unit['display_order']; ?></td>
                            <td data-label="Status">
                                <span class="status <?php echo $isActive === 1 ? 'active' : 'inactive'; ?>">
                                    <?php echo $isActive === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                </span>
                            </td>
                            <td data-label="Dibuat"><?php echo htmlspecialchars((string)$unit['created_at']); ?></td>
                            <td data-label="Diperbarui"><?php echo htmlspecialchars((string)$unit['updated_at']); ?></td>
                            <td data-label="Aksi" class="actions">
                                <div class="actions-inline">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-unit-id="<?php echo $unitId; ?>"
                                        data-value="<?php echo htmlspecialchars((string)$unit['value'], ENT_QUOTES); ?>"
                                        data-label="<?php echo htmlspecialchars((string)$unit['label'], ENT_QUOTES); ?>"
                                        data-display-order="<?php echo (int)$unit['display_order']; ?>"
                                        onclick="openEditUnitModal(this)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    <form method="POST" class="action-form" onsubmit="return confirm('Ubah status satuan ini?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="unit_id" value="<?php echo $unitId; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $isActive; ?>">
                                        <button type="submit" class="btn-toggle <?php echo $isActive === 1 ? 'active' : 'inactive'; ?>">
                                            <i class='bx bx-toggle-<?php echo $isActive === 1 ? 'right' : 'left'; ?>'></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($units)): ?>
                        <tr>
                            <td colspan="7" class="no-data">Belum ada data satuan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="unit-modal-overlay" id="add-unit-modal" onclick="closeModalOnBackdrop(event, 'add-unit-modal')">
            <div class="unit-modal" role="dialog" aria-modal="true" aria-labelledby="add-unit-modal-title">
                <div class="unit-modal-header">
                    <h3 id="add-unit-modal-title">Tambah Satuan</h3>
                    <button type="button" class="unit-modal-close" onclick="closeUnitModal('add-unit-modal')">&times;</button>
                </div>
                <div class="unit-modal-body">
                    <form method="POST" class="add-form" id="add-unit-form">
                        <input type="hidden" name="action" value="create_unit">
                        <div class="form-group">
                            <label for="add_value">Value (unik)</label>
                            <input type="text" id="add_value" name="value" maxlength="50" pattern="[a-zA-Z0-9_\-\s]+" placeholder="contoh: drum" value="<?php echo htmlspecialchars((string)$addFormState['value']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="add_label">Label</label>
                            <input type="text" id="add_label" name="label" maxlength="100" placeholder="contoh: Drum" value="<?php echo htmlspecialchars((string)$addFormState['label']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="add_display_order">Urutan Tampil</label>
                            <input type="number" id="add_display_order" name="display_order" min="0" max="9999" value="<?php echo (int)$addFormState['display_order']; ?>" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Satuan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="unit-modal-overlay" id="edit-unit-modal" onclick="closeModalOnBackdrop(event, 'edit-unit-modal')">
            <div class="unit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-unit-modal-title">
                <div class="unit-modal-header">
                    <h3 id="edit-unit-modal-title">Edit Satuan</h3>
                    <button type="button" class="unit-modal-close" onclick="closeUnitModal('edit-unit-modal')">&times;</button>
                </div>
                <div class="unit-modal-body">
                    <form method="POST" class="add-form" id="edit-unit-form">
                        <input type="hidden" name="action" value="update_unit">
                        <input type="hidden" id="edit_unit_id" name="unit_id" value="<?php echo htmlspecialchars((string)$editFormState['unit_id']); ?>">
                        <div class="form-group">
                            <label for="edit_value">Value (unik)</label>
                            <input type="text" id="edit_value" name="value" maxlength="50" pattern="[a-zA-Z0-9_\-\s]+" value="<?php echo htmlspecialchars((string)$editFormState['value']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_label">Label</label>
                            <input type="text" id="edit_label" name="label" maxlength="100" value="<?php echo htmlspecialchars((string)$editFormState['label']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_display_order">Urutan Tampil</label>
                            <input type="number" id="edit_display_order" name="display_order" min="0" max="9999" value="<?php echo (int)$editFormState['display_order']; ?>" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Update Satuan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion('script.js'); ?>"></script>
    <script>
        function openAddUnitModal() {
            const addForm = document.getElementById('add-unit-form');
            if (addForm) {
                addForm.reset();
                const addDisplayOrder = document.getElementById('add_display_order');
                if (addDisplayOrder) {
                    addDisplayOrder.value = 0;
                }
            }

            const modal = document.getElementById('add-unit-modal');
            if (modal) {
                modal.classList.add('show');
            }
        }

        function openEditUnitModal(button) {
            if (!button) {
                return;
            }

            document.getElementById('edit_unit_id').value = button.dataset.unitId || '';
            document.getElementById('edit_value').value = button.dataset.value || '';
            document.getElementById('edit_label').value = button.dataset.label || '';
            document.getElementById('edit_display_order').value = button.dataset.displayOrder || 0;

            const modal = document.getElementById('edit-unit-modal');
            if (modal) {
                modal.classList.add('show');
            }
        }

        function closeUnitModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
            }
        }

        function closeModalOnBackdrop(event, modalId) {
            if (event.target && event.target.id === modalId) {
                closeUnitModal(modalId);
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeUnitModal('add-unit-modal');
                closeUnitModal('edit-unit-modal');
            }
        });

        (function() {
            const modalToOpen = <?php echo json_encode($modalToOpen); ?>;
            if (modalToOpen === 'add-unit-modal' || modalToOpen === 'edit-unit-modal') {
                closeUnitModal('add-unit-modal');
                closeUnitModal('edit-unit-modal');
                const modal = document.getElementById(modalToOpen);
                if (modal) {
                    modal.classList.add('show');
                }
            }
        })();
    </script>
</body>

</html>