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

function normalizeUnitValue($value)
{
    $value = trim((string)$value);
    $value = strtolower($value);
    return preg_replace('/\s+/', '_', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

        if ($action === 'create_unit' || $action === 'update_unit') {
            $unitId = isset($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0;
            $rawValue = isset($_POST['value']) ? $_POST['value'] : '';
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;

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
        if ((int)$e->getCode() === 23000) {
            $message = '<div class="alert error">Value satuan sudah digunakan. Gunakan value yang berbeda.</div>';
        } else {
            $message = '<div class="alert error">Kesalahan database: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } catch (Exception $e) {
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
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        .unit-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 16px;
        }

        .unit-modal-overlay.show {
            display: flex;
        }

        .unit-modal {
            background: var(--white);
            border-radius: 10px;
            width: 100%;
            max-width: 700px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .unit-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--cultured);
        }

        .unit-modal-header h3 {
            margin: 0;
        }

        .unit-modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--charcoal);
        }

        .unit-modal-body {
            padding: 18px;
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

        <div class="table-container">
            <div class="table-header">
                <h3>Daftar Satuan</h3>
                <div class="header-actions">
                    <button type="button" class="btn-add" onclick="openAddModal()">
                        <i class='bx bx-plus'></i>
                        Tambah Satuan
                    </button>
                </div>
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
                                        onclick="fillEditForm(<?php echo $unitId; ?>, '<?php echo htmlspecialchars((string)$unit['value'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)$unit['label'], ENT_QUOTES); ?>', <?php echo (int)$unit['display_order']; ?>)">
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

        <div id="add-unit-modal" class="unit-modal-overlay" aria-hidden="true">
            <div class="unit-modal" role="dialog" aria-modal="true" aria-labelledby="add-unit-title">
                <div class="unit-modal-header">
                    <h3 id="add-unit-title">Tambah Satuan</h3>
                    <button type="button" class="unit-modal-close" onclick="closeAddModal()" aria-label="Tutup">&times;</button>
                </div>
                <div class="unit-modal-body">
                    <form method="POST" class="add-form">
                        <input type="hidden" name="action" value="create_unit">
                        <div class="form-group">
                            <label for="add_value">Value (unik)</label>
                            <input type="text" id="add_value" name="value" maxlength="50" pattern="[a-zA-Z0-9_\-\s]+" placeholder="contoh: drum" required>
                        </div>
                        <div class="form-group">
                            <label for="add_label">Label</label>
                            <input type="text" id="add_label" name="label" maxlength="100" placeholder="contoh: Drum" required>
                        </div>
                        <div class="form-group">
                            <label for="add_display_order">Urutan Tampil</label>
                            <input type="number" id="add_display_order" name="display_order" min="0" max="9999" value="0" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Satuan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="edit-unit-modal" class="unit-modal-overlay" aria-hidden="true">
            <div class="unit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-unit-title">
                <div class="unit-modal-header">
                    <h3 id="edit-unit-title">Edit Satuan</h3>
                    <button type="button" class="unit-modal-close" onclick="closeEditModal()" aria-label="Tutup">&times;</button>
                </div>
                <div class="unit-modal-body">
                    <form method="POST" class="add-form" id="edit-unit-form">
                        <input type="hidden" name="action" value="update_unit">
                        <input type="hidden" id="edit_unit_id" name="unit_id" value="">
                        <div class="form-group">
                            <label for="edit_value">Value (unik)</label>
                            <input type="text" id="edit_value" name="value" maxlength="50" pattern="[a-zA-Z0-9_\-\s]+" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_label">Label</label>
                            <input type="text" id="edit_label" name="label" maxlength="100" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_display_order">Urutan Tampil</label>
                            <input type="number" id="edit_display_order" name="display_order" min="0" max="9999" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Update Satuan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script>
        function openAddModal() {
            document.getElementById('add-unit-modal').classList.add('show');
        }

        function closeAddModal() {
            document.getElementById('add-unit-modal').classList.remove('show');
        }

        function openEditModal() {
            document.getElementById('edit-unit-modal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('edit-unit-modal').classList.remove('show');
        }

        function fillEditForm(id, value, label, displayOrder) {
            document.getElementById('edit_unit_id').value = id;
            document.getElementById('edit_value').value = value;
            document.getElementById('edit_label').value = label;
            document.getElementById('edit_display_order').value = displayOrder;
            openEditModal();
        }

        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('unit-modal-overlay')) {
                event.target.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php if (isset($_POST['action']) && $_POST['action'] === 'create_unit' && strpos($message, 'alert error') !== false): ?>
                openAddModal();
            <?php elseif (isset($_POST['action']) && $_POST['action'] === 'update_unit' && strpos($message, 'alert error') !== false): ?>
                openEditModal();
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_unit'): ?>
            <?php if (isset($_POST['unit_id'], $_POST['value'], $_POST['label'], $_POST['display_order'])): ?>
                fillEditForm(
                    <?php echo (int)$_POST['unit_id']; ?>,
                    <?php echo json_encode((string)$_POST['value']); ?>,
                    <?php echo json_encode((string)$_POST['label']); ?>,
                    <?php echo (int)$_POST['display_order']; ?>
                );
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_unit'): ?>
            <?php if (strpos($message, 'alert error') !== false): ?>
                document.getElementById('add_value').value = <?php echo json_encode(isset($_POST['value']) ? (string)$_POST['value'] : ''); ?>;
                document.getElementById('add_label').value = <?php echo json_encode(isset($_POST['label']) ? (string)$_POST['label'] : ''); ?>;
                document.getElementById('add_display_order').value = <?php echo (int)(isset($_POST['display_order']) ? $_POST['display_order'] : 0); ?>;
            <?php endif; ?>
        <?php endif; ?>
    </script>
</body>

</html>