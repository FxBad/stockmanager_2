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
$searchKeyword = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$addFormState = [
    'name' => '',
    'display_order' => 0,
    'is_active' => 1,
];
$editFormState = [
    'category_id' => '',
    'name' => '',
    'display_order' => 0,
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    try {
        if ($action === 'create_category' || $action === 'update_category') {
            $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
            $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
            $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($action === 'create_category') {
                $addFormState['name'] = $name;
                $addFormState['display_order'] = $displayOrder;
                $addFormState['is_active'] = $isActive;
            } else {
                $editFormState['category_id'] = (string)$categoryId;
                $editFormState['name'] = $name;
                $editFormState['display_order'] = $displayOrder;
                $editFormState['is_active'] = $isActive;
            }

            if ($name === '') {
                throw new Exception('Nama kategori wajib diisi.');
            }
            if (strlen($name) > 100) {
                throw new Exception('Nama kategori maksimal 100 karakter.');
            }
            if ($displayOrder < 0 || $displayOrder > 9999) {
                throw new Exception('Display order harus di antara 0 sampai 9999.');
            }

            if ($action === 'create_category') {
                $stmt = $pdo->prepare('INSERT INTO item_categories (name, display_order, is_active) VALUES (:name, :display_order, :is_active)');
                $stmt->execute([
                    ':name' => $name,
                    ':display_order' => $displayOrder,
                    ':is_active' => $isActive,
                ]);

                $addFormState = [
                    'name' => '',
                    'display_order' => 0,
                    'is_active' => 1,
                ];
                $message = '<div class="alert success">Kategori berhasil ditambahkan.</div>';
            } else {
                if ($categoryId <= 0) {
                    throw new Exception('ID kategori tidak valid.');
                }

                $stmtOld = $pdo->prepare('SELECT id, name FROM item_categories WHERE id = :id LIMIT 1');
                $stmtOld->execute([':id' => $categoryId]);
                $oldCategory = $stmtOld->fetch(PDO::FETCH_ASSOC);
                if (!$oldCategory) {
                    throw new Exception('Kategori tidak ditemukan.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare('UPDATE item_categories SET name = :name, display_order = :display_order, is_active = :is_active, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':name' => $name,
                    ':display_order' => $displayOrder,
                    ':is_active' => $isActive,
                    ':id' => $categoryId,
                ]);

                $oldName = isset($oldCategory['name']) ? (string)$oldCategory['name'] : '';
                if ($oldName !== '' && $oldName !== $name) {
                    $stmtItems = $pdo->prepare('UPDATE items SET category = :new_name WHERE category = :old_name');
                    $stmtItems->execute([
                        ':new_name' => $name,
                        ':old_name' => $oldName,
                    ]);
                }

                $pdo->commit();

                if ($stmt->rowCount() > 0) {
                    $editFormState = [
                        'category_id' => '',
                        'name' => '',
                        'display_order' => 0,
                        'is_active' => 1,
                    ];
                    $message = '<div class="alert success">Kategori berhasil diperbarui.</div>';
                } else {
                    $message = '<div class="alert error">Tidak ada perubahan data atau kategori tidak ditemukan.</div>';
                }
            }
        } elseif ($action === 'delete_category') {
            $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
            if ($categoryId <= 0) {
                throw new Exception('ID kategori tidak valid.');
            }

            $stmtGet = $pdo->prepare('SELECT name FROM item_categories WHERE id = :id LIMIT 1');
            $stmtGet->execute([':id' => $categoryId]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Kategori tidak ditemukan.');
            }

            $categoryName = isset($row['name']) ? (string)$row['name'] : '';

            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM items WHERE category = :category');
            $stmtCheck->execute([':category' => $categoryName]);
            $usedCount = (int)$stmtCheck->fetchColumn();

            if ($usedCount > 0) {
                throw new Exception('Kategori tidak dapat dihapus karena masih digunakan oleh ' . $usedCount . ' barang.');
            }

            $stmtDelete = $pdo->prepare('DELETE FROM item_categories WHERE id = :id');
            $stmtDelete->execute([':id' => $categoryId]);

            if ($stmtDelete->rowCount() > 0) {
                $message = '<div class="alert success">Kategori berhasil dihapus.</div>';
            } else {
                $message = '<div class="alert error">Kategori tidak ditemukan atau sudah terhapus.</div>';
            }
        } elseif ($action === 'sync_categories') {
            $pdo->beginTransaction();

            $stmtCategories = $pdo->query('SELECT name FROM item_categories');
            $masterCategories = $stmtCategories->fetchAll(PDO::FETCH_COLUMN);

            $normalizedMap = [];
            foreach ($masterCategories as $categoryName) {
                $canonicalName = trim((string)$categoryName);
                if ($canonicalName === '') {
                    continue;
                }
                $normalizedMap[strtolower($canonicalName)] = $canonicalName;
            }

            $stmtItems = $pdo->query('SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category <> ""');
            $itemCategories = $stmtItems->fetchAll(PDO::FETCH_COLUMN);

            $syncCount = 0;
            foreach ($itemCategories as $itemCategory) {
                $rawName = trim((string)$itemCategory);
                if ($rawName === '') {
                    continue;
                }

                $normalizedKey = strtolower($rawName);
                if (!isset($normalizedMap[$normalizedKey])) {
                    continue;
                }

                $targetName = $normalizedMap[$normalizedKey];
                if ($targetName === $rawName) {
                    continue;
                }

                $stmtSync = $pdo->prepare('UPDATE items SET category = :target_name WHERE category = :source_name');
                $stmtSync->execute([
                    ':target_name' => $targetName,
                    ':source_name' => $rawName,
                ]);

                $syncCount += (int)$stmtSync->rowCount();
            }

            $pdo->commit();
            $message = '<div class="alert success">Sinkronisasi selesai. ' . $syncCount . ' data barang diperbarui.</div>';
        } else {
            throw new Exception('Aksi tidak dikenali.');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($action === 'create_category') {
            $modalToOpen = 'add-category-modal';
        } elseif ($action === 'update_category') {
            $modalToOpen = 'edit-category-modal';
        }

        if ((int)$e->getCode() === 23000) {
            $message = '<div class="alert error">Nama kategori sudah digunakan. Gunakan nama lain.</div>';
        } else {
            $message = '<div class="alert error">Kesalahan database: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($action === 'create_category') {
            $modalToOpen = 'add-category-modal';
        } elseif ($action === 'update_category') {
            $modalToOpen = 'edit-category-modal';
        }

        $message = '<div class="alert error">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

try {
    $stmt = $pdo->query('SELECT id, name, display_order, is_active, created_at, updated_at FROM item_categories ORDER BY is_active DESC, display_order ASC, name ASC');
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
    $message = '<div class="alert error">Gagal memuat data kategori: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Kategori - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion('style.css'); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        .category-action-hub {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 10px;
            background: #fff;
            box-shadow: var(--box-shadow);
        }

        .category-action-hub__title {
            min-width: 0;
        }

        .category-action-hub__title h2 {
            margin: 0;
            color: var(--brunswick-green);
            font-size: 24px;
            line-height: 1.2;
        }

        .category-action-hub__title p {
            margin: 6px 0 0;
            color: var(--hunter-green);
            font-size: 0.92rem;
            opacity: 0.9;
        }

        .category-action-hub__controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
        }

        .category-quick-search {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 10px;
            min-width: 220px;
            height: 42px;
            border: 1px solid #d6dbd2;
            border-radius: 8px;
            background: #fff;
        }

        .category-quick-search i {
            color: var(--fern-green);
            font-size: 1.05rem;
        }

        .category-quick-search input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            color: var(--brunswick-green);
            font-size: 0.94rem;
        }

        .btn-modal-sync {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid var(--fern-green);
            border-radius: 6px;
            cursor: pointer;
            padding: 10px 14px;
            background-color: #fff;
            color: var(--fern-green);
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-modal-sync:hover {
            background-color: var(--sage);
            border-color: var(--sage);
            color: var(--brunswick-green);
        }

        .btn-modal-sync:disabled {
            cursor: wait;
            opacity: 0.75;
        }

        .table-subinfo {
            margin: 6px 0 0;
            color: var(--hunter-green);
            font-size: 0.88rem;
            min-height: 20px;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 30px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .status-chip i {
            font-size: 1rem;
            line-height: 1;
        }

        .status-chip--active {
            background: var(--hunter-green);
            color: #fff;
            border-color: var(--brunswick-green);
        }

        .status-chip--inactive {
            background: #dc2626;
            color: #fff;
            border-color: #b91c1c;
        }

        @media screen and (max-width: 420px) {
            .status-chip {
                min-height: 28px;
                padding: 4px 9px;
                font-size: 0.75rem;
            }
        }

        @media screen and (max-width: 900px) {
            .category-action-hub {
                flex-wrap: wrap;
                align-items: flex-start;
            }

            .category-action-hub__controls {
                width: 100%;
                flex-wrap: wrap;
            }

            .category-quick-search {
                flex: 1 1 260px;
            }
        }

        @media screen and (max-width: 420px) {
            .category-action-hub {
                padding: 12px;
            }

            .category-action-hub__title h2 {
                font-size: 1.25rem;
            }

            .category-action-hub__title p {
                font-size: 0.85rem;
            }

            .btn-modal-sync,
            .btn-modal-add {
                min-height: 42px;
            }

            .category-quick-search {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="category-action-hub">
            <div class="category-action-hub__title">
                <h2>Kelola Kategori</h2>
                <p>Kelompokkan produk dengan struktur kategori yang rapi dan sinkron untuk menjaga konsistensi data barang.</p>
            </div>

            <div class="category-action-hub__controls">
                <label class="category-quick-search" for="category-quick-search-input" aria-label="Cari kategori">
                    <i class='bx bx-search'></i>
                    <input
                        type="search"
                        id="category-quick-search-input"
                        placeholder="Cari nama atau status kategori..."
                        value="<?php echo htmlspecialchars($searchKeyword); ?>"
                        autocomplete="off">
                </label>

                <form method="POST" id="sync-category-form" class="action-form" onsubmit="return triggerSyncState();">
                    <input type="hidden" name="action" value="sync_categories">
                    <button type="submit" class="btn-modal-sync" id="sync-category-btn">
                        <i class='bx bx-refresh'></i> Sinkronisasi
                    </button>
                </form>

                <button type="button" class="btn-modal-add" onclick="openAddCategoryModal()">
                    <i class='bx bx-plus'></i> Tambah Kategori
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <div class="table-container">
            <div class="table-header">
                <h3>Master Data Kategori</h3>
                <p class="table-subinfo" id="category-search-info"></p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Urutan</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Diperbarui</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="category-table-body">
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $categoryId = isset($category['id']) ? (int)$category['id'] : 0;
                        $isActive = isset($category['is_active']) ? (int)$category['is_active'] : 0;
                        ?>
                        <tr>
                            <td data-label="Nama"><?php echo htmlspecialchars((string)$category['name']); ?></td>
                            <td data-label="Urutan"><?php echo (int)$category['display_order']; ?></td>
                            <td data-label="Status">
                                <span class="status-chip <?php echo $isActive === 1 ? 'status-chip--active' : 'status-chip--inactive'; ?>" aria-label="Status <?php echo $isActive === 1 ? 'aktif' : 'nonaktif'; ?>">
                                    <i class='bx <?php echo $isActive === 1 ? 'bx-check-circle' : 'bx-x-circle'; ?>' aria-hidden="true"></i>
                                    <?php echo $isActive === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                </span>
                            </td>
                            <td data-label="Dibuat"><?php echo htmlspecialchars((string)$category['created_at']); ?></td>
                            <td data-label="Diperbarui"><?php echo htmlspecialchars((string)$category['updated_at']); ?></td>
                            <td data-label="Aksi" class="actions">
                                <div class="actions-inline">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-category-id="<?php echo $categoryId; ?>"
                                        data-name="<?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES); ?>"
                                        data-display-order="<?php echo (int)$category['display_order']; ?>"
                                        data-is-active="<?php echo $isActive; ?>"
                                        onclick="openEditCategoryModal(this)">
                                        <i class='bx bx-edit'></i>
                                    </button>

                                    <form method="POST" class="action-form" onsubmit="return confirm('Yakin ingin menghapus kategori ini?');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?php echo $categoryId; ?>">
                                        <button type="submit" class="btn-delete">
                                            <i class='bx bx-trash'></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6" class="no-data">Belum ada data kategori.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="app-modal-overlay" id="add-category-modal" onclick="closeCategoryModalOnBackdrop(event, 'add-category-modal')">
            <div class="app-modal app-modal--category" role="dialog" aria-modal="true" aria-labelledby="add-category-modal-title">
                <div class="app-modal-header">
                    <h3 id="add-category-modal-title">Tambah Kategori</h3>
                    <button type="button" class="app-modal-close" onclick="closeCategoryModal('add-category-modal')">&times;</button>
                </div>
                <div class="app-modal-body">
                    <form method="POST" class="add-form" id="add-category-form">
                        <input type="hidden" name="action" value="create_category">

                        <div class="form-group">
                            <label for="add_category_name">Nama Kategori</label>
                            <input type="text" id="add_category_name" name="name" maxlength="100" value="<?php echo htmlspecialchars((string)$addFormState['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_category_display_order">Urutan Tampil</label>
                            <input type="number" id="add_category_display_order" name="display_order" min="0" max="9999" value="<?php echo (int)$addFormState['display_order']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="add_category_is_active">
                                <input type="checkbox" id="add_category_is_active" name="is_active" value="1" <?php echo (int)$addFormState['is_active'] === 1 ? 'checked' : ''; ?>>
                                Aktif
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Kategori</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="app-modal-overlay" id="edit-category-modal" onclick="closeCategoryModalOnBackdrop(event, 'edit-category-modal')">
            <div class="app-modal app-modal--category" role="dialog" aria-modal="true" aria-labelledby="edit-category-modal-title">
                <div class="app-modal-header">
                    <h3 id="edit-category-modal-title">Edit Kategori</h3>
                    <button type="button" class="app-modal-close" onclick="closeCategoryModal('edit-category-modal')">&times;</button>
                </div>
                <div class="app-modal-body">
                    <form method="POST" class="add-form" id="edit-category-form">
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" id="edit_category_id" name="category_id" value="<?php echo htmlspecialchars((string)$editFormState['category_id']); ?>">

                        <div class="form-group">
                            <label for="edit_category_name">Nama Kategori</label>
                            <input type="text" id="edit_category_name" name="name" maxlength="100" value="<?php echo htmlspecialchars((string)$editFormState['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_category_display_order">Urutan Tampil</label>
                            <input type="number" id="edit_category_display_order" name="display_order" min="0" max="9999" value="<?php echo (int)$editFormState['display_order']; ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_category_is_active">
                                <input type="checkbox" id="edit_category_is_active" name="is_active" value="1" <?php echo (int)$editFormState['is_active'] === 1 ? 'checked' : ''; ?>>
                                Aktif
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Update Kategori</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="script.js?<?php echo getVersion('script.js'); ?>"></script>
    <script>
        function triggerSyncState() {
            const button = document.getElementById('sync-category-btn');
            if (button) {
                button.disabled = true;
                button.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Sinkronisasi...";
            }
            return true;
        }

        function openAddCategoryModal() {
            const form = document.getElementById('add-category-form');
            if (form) {
                form.reset();
                const orderEl = document.getElementById('add_category_display_order');
                const activeEl = document.getElementById('add_category_is_active');
                if (orderEl) orderEl.value = 0;
                if (activeEl) activeEl.checked = true;
            }
            const modal = document.getElementById('add-category-modal');
            if (modal) modal.classList.add('show');
        }

        function openEditCategoryModal(button) {
            if (!button) return;

            document.getElementById('edit_category_id').value = button.dataset.categoryId || '';
            document.getElementById('edit_category_name').value = button.dataset.name || '';
            document.getElementById('edit_category_display_order').value = button.dataset.displayOrder || 0;

            const activeEl = document.getElementById('edit_category_is_active');
            if (activeEl) {
                activeEl.checked = (button.dataset.isActive || '0') === '1';
            }

            const modal = document.getElementById('edit-category-modal');
            if (modal) modal.classList.add('show');
        }

        function closeCategoryModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.remove('show');
        }

        function closeCategoryModalOnBackdrop(event, modalId) {
            if (event.target && event.target.id === modalId) {
                closeCategoryModal(modalId);
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCategoryModal('add-category-modal');
                closeCategoryModal('edit-category-modal');
            }
        });

        (function() {
            const searchInput = document.getElementById('category-quick-search-input');
            const tableBody = document.getElementById('category-table-body');
            const infoEl = document.getElementById('category-search-info');
            if (!searchInput || !tableBody || !infoEl) {
                return;
            }

            const rows = Array.from(tableBody.querySelectorAll('tr'));
            const dataRows = rows.filter(function(row) {
                return row.querySelector('td[data-label="Nama"]') !== null;
            });

            function renderSearchResultInfo(total, visible, keyword) {
                if (!keyword) {
                    infoEl.textContent = 'Menampilkan ' + total + ' kategori.';
                    return;
                }
                infoEl.textContent = 'Ditemukan ' + visible + ' dari ' + total + ' kategori untuk "' + keyword + '".';
            }

            function applyQuickSearch() {
                const keyword = (searchInput.value || '').trim().toLowerCase();
                let visibleCount = 0;

                dataRows.forEach(function(row) {
                    const nameText = (row.querySelector('td[data-label="Nama"]')?.textContent || '').toLowerCase();
                    const statusText = (row.querySelector('td[data-label="Status"]')?.textContent || '').toLowerCase();
                    const orderText = (row.querySelector('td[data-label="Urutan"]')?.textContent || '').toLowerCase();
                    const haystack = (nameText + ' ' + statusText + ' ' + orderText).trim();
                    const isVisible = keyword === '' || haystack.includes(keyword);

                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) {
                        visibleCount += 1;
                    }
                });

                renderSearchResultInfo(dataRows.length, visibleCount, keyword);
            }

            searchInput.addEventListener('input', applyQuickSearch);
            applyQuickSearch();
        })();

        (function() {
            const modalToOpen = <?php echo json_encode($modalToOpen); ?>;
            if (modalToOpen === 'add-category-modal' || modalToOpen === 'edit-category-modal') {
                closeCategoryModal('add-category-modal');
                closeCategoryModal('edit-category-modal');
                const modal = document.getElementById(modalToOpen);
                if (modal) {
                    modal.classList.add('show');
                }
            }
        })();
    </script>
</body>

</html>