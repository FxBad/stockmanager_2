<?php
session_start();
require_once __DIR__ . '/../cache_control.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';


$message = '';
$allowedRoles = ['field', 'office', 'admin'];
$roleScopes = [
    'field' => ['Input Data'],
    'office' => ['Inventaris', 'Pelaporan'],
    'admin' => ['Inventaris', 'Pelaporan', 'Input Data', 'Manajemen Pengguna'],
];
$modalToOpen = '';
$addUserState = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'role' => 'field',
];
$editUserState = [
    'user_id' => '',
    'username' => '',
    'full_name' => '',
    'email' => '',
    'role' => 'field',
];

function buildAlert($type, $text)
{
    return '<div class="alert ' . $type . '">' . htmlspecialchars($text) . '</div>';
}

function getRoleScopeClass($scope)
{
    $normalized = strtolower(trim((string)$scope));
    if ($normalized === 'inventaris') {
        return 'scope-inventory';
    }
    if ($normalized === 'pelaporan') {
        return 'scope-report';
    }
    if ($normalized === 'input data') {
        return 'scope-input';
    }

    return 'scope-admin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    try {
        if ($action === 'create_user') {
            $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
            $fullName = isset($_POST['full_name']) ? trim((string)$_POST['full_name']) : '';
            $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
            $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
            $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';
            $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'field';

            $addUserState['username'] = $username;
            $addUserState['full_name'] = $fullName;
            $addUserState['email'] = $email;
            $addUserState['role'] = $role;

            if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
                throw new Exception('Username harus 3-30 karakter (huruf, angka, underscore).');
            }
            if ($fullName === '' || strlen($fullName) > 100) {
                throw new Exception('Nama lengkap tidak boleh kosong atau terlalu panjang.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Alamat email tidak valid.');
            }
            if (!in_array($role, $allowedRoles, true)) {
                throw new Exception('Role pengguna tidak valid.');
            }
            if ($password === '' || strlen($password) < 6) {
                throw new Exception('Password minimal 6 karakter.');
            }
            if ($password !== $confirmPassword) {
                throw new Exception('Konfirmasi password tidak cocok.');
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                throw new Exception('Username sudah terdaftar.');
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                throw new Exception('Email sudah digunakan.');
            }

            $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, status) VALUES (:username, :password, :full_name, :email, :role, :status)');
            $stmt->execute([
                ':username' => $username,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':full_name' => $fullName,
                ':email' => $email,
                ':role' => $role,
                ':status' => 'active',
            ]);

            $addUserState = [
                'username' => '',
                'full_name' => '',
                'email' => '',
                'role' => 'field',
            ];

            $message = buildAlert('success', 'Pengguna berhasil ditambahkan.');
        } elseif ($action === 'update_user') {
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
            $fullName = isset($_POST['full_name']) ? trim((string)$_POST['full_name']) : '';
            $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
            $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
            $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';
            $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'field';

            $editUserState['user_id'] = (string)$userId;
            $editUserState['username'] = $username;
            $editUserState['full_name'] = $fullName;
            $editUserState['email'] = $email;
            $editUserState['role'] = $role;

            if ($userId <= 0) {
                throw new Exception('ID pengguna tidak valid.');
            }
            if ($userId === (int)$_SESSION['user_id']) {
                throw new Exception('Tidak dapat mengedit akun Anda sendiri dari halaman ini.');
            }
            if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
                throw new Exception('Username harus 3-30 karakter (huruf, angka, underscore).');
            }
            if ($fullName === '' || strlen($fullName) > 100) {
                throw new Exception('Nama lengkap tidak boleh kosong atau terlalu panjang.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Alamat email tidak valid.');
            }
            if (!in_array($role, $allowedRoles, true)) {
                throw new Exception('Role pengguna tidak valid.');
            }
            if ($password !== '') {
                if (strlen($password) < 6) {
                    throw new Exception('Password baru minimal 6 karakter.');
                }
                if ($password !== $confirmPassword) {
                    throw new Exception('Konfirmasi password baru tidak cocok.');
                }
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
            $stmt->execute([':username' => $username, ':id' => $userId]);
            if ($stmt->fetch()) {
                throw new Exception('Username sudah digunakan pengguna lain.');
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $stmt->execute([':email' => $email, ':id' => $userId]);
            if ($stmt->fetch()) {
                throw new Exception('Email sudah digunakan pengguna lain.');
            }

            if ($password !== '') {
                $stmt = $pdo->prepare('UPDATE users SET username = :username, full_name = :full_name, email = :email, role = :role, password = :password WHERE id = :id');
                $stmt->execute([
                    ':username' => $username,
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':role' => $role,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $userId,
                ]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET username = :username, full_name = :full_name, email = :email, role = :role WHERE id = :id');
                $stmt->execute([
                    ':username' => $username,
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':role' => $role,
                    ':id' => $userId,
                ]);
            }

            if ($stmt->rowCount() > 0) {
                $editUserState = [
                    'user_id' => '',
                    'username' => '',
                    'full_name' => '',
                    'email' => '',
                    'role' => 'field',
                ];
                $message = buildAlert('success', 'Pengguna berhasil diperbarui.');
            } else {
                $message = buildAlert('error', 'Tidak ada perubahan data atau pengguna tidak ditemukan.');
            }
        } elseif ($action === 'toggle_status') {
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $currentStatus = isset($_POST['current_status']) ? $_POST['current_status'] : '';

            if ($userId <= 0) {
                throw new Exception('Invalid user ID');
            }

            if ($userId === (int)$_SESSION['user_id']) {
                throw new Exception('Tidak dapat mengubah status akun Anda sendiri.');
            }

            $stmtRole = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $stmtRole->execute([$userId]);
            $roleRow = $stmtRole->fetch(PDO::FETCH_ASSOC);
            if (!$roleRow) {
                throw new Exception('User tidak ditemukan.');
            }
            if ($roleRow['role'] === 'admin') {
                throw new Exception('Tidak dapat mengubah status akun admin.');
            }

            $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

            $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role != 'admin'");
            $stmt->execute([':status' => $newStatus, ':id' => $userId]);

            if ($stmt->rowCount() > 0) {
                $message = buildAlert('success', 'Status pengguna berhasil diperbarui.');
            } else {
                $message = buildAlert('error', 'Pengguna tidak ditemukan atau status tidak berubah.');
            }
        } else {
            throw new Exception('Aksi tidak dikenali.');
        }
    } catch (Exception $e) {
        if ($action === 'create_user') {
            $modalToOpen = 'add-user-modal';
        } elseif ($action === 'update_user') {
            $modalToOpen = 'edit-user-modal';
        }

        if (is_writable(__DIR__ . '/../logs')) {
            error_log('manage-users error: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/error.log');
        }
        $message = buildAlert('error', $e->getMessage());
    }
}

// Fetch all users except current admin
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id != ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    if (is_writable(__DIR__ . '/../logs')) {
        error_log('manage-users fetch error: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/error.log');
    }
    $message = buildAlert('error', 'Gagal memuat daftar pengguna.');
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion(); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        .users-header-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .btn-add-user {
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

        .btn-add-user:hover {
            background-color: var(--hunter-green);
        }

        .user-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .user-modal-overlay.show {
            display: flex;
        }

        .user-modal {
            width: 100%;
            max-width: 560px;
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .user-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #eee;
        }

        .user-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
        }

        .user-modal-close {
            border: none;
            background: transparent;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .user-modal-body {
            padding: 16px;
        }

        .role-cell {
            min-width: 220px;
        }

        .role-scope-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }

        .scope-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .scope-chip.scope-inventory {
            background-color: #eef7f2;
            color: #1f6d50;
            border-color: #cde7da;
        }

        .scope-chip.scope-report {
            background-color: #eff3fb;
            color: #1f4f87;
            border-color: #d0def5;
        }

        .scope-chip.scope-input {
            background-color: #fff6e9;
            color: #8a5a15;
            border-color: #f2dfbf;
        }

        .scope-chip.scope-admin {
            background-color: #f8eefc;
            color: #6d2f91;
            border-color: #e6d3f2;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../shared/nav.php'; ?>

    <main class="main-container">
        <div class="main-title">
            <h2>Kelola Pengguna</h2>
        </div>

        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <div class="users-header-actions">
            <button type="button" class="btn-add-user" onclick="openAddUserModal()">
                <i class='bx bx-user-plus'></i> Tambah Pengguna
            </button>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h3>Pengguna Sistem</h3>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Terakhir Masuk</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $uid = isset($user['id']) ? (int)$user['id'] : 0;
                        $username = isset($user['username']) ? htmlspecialchars($user['username']) : '';
                        $fullName = isset($user['full_name']) ? htmlspecialchars($user['full_name']) : '';
                        $email = isset($user['email']) ? htmlspecialchars($user['email']) : '';
                        $role = isset($user['role']) ? preg_replace('/[^a-z0-9_\-]/i', '', $user['role']) : 'user';
                        $roleScopeItems = isset($roleScopes[$role]) ? $roleScopes[$role] : ['Akses Dasar'];
                        $status = isset($user['status']) ? preg_replace('/[^a-z0-9_\-]/i', '', $user['status']) : 'inactive';
                        $lastLogin = !empty($user['last_login']) ? htmlspecialchars($user['last_login']) : '';
                    ?>
                        <tr>
                            <td><?php echo $username; ?></td>
                            <td><?php echo $fullName; ?></td>
                            <td><?php echo $email; ?></td>
                            <td class="role-cell">
                                <span class="role <?php echo $role; ?>"><?php echo ucfirst($role); ?></span>
                                <div class="role-scope-list" aria-label="Cakupan akses role <?php echo htmlspecialchars(ucfirst($role)); ?>">
                                    <?php foreach ($roleScopeItems as $scopeItem): ?>
                                        <?php $scopeClass = getRoleScopeClass($scopeItem); ?>
                                        <span class="scope-chip <?php echo $scopeClass; ?>"><?php echo htmlspecialchars($scopeItem); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status <?php echo $status; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                            <td data-timestamp="<?php echo $lastLogin; ?>" class="last-login">
                                <?php if ($lastLogin): ?>
                                    <span class="timestamp">
                                        <i class='bx bx-time-five'></i>
                                        <?php echo $lastLogin; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="never-login">
                                        <i class='bx bx-x-circle'></i>
                                        Tidak Pernah
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions-inline">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-user-id="<?php echo $uid; ?>"
                                        data-username="<?php echo htmlspecialchars((string)$user['username'], ENT_QUOTES); ?>"
                                        data-full-name="<?php echo htmlspecialchars((string)$user['full_name'], ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars((string)$user['email'], ENT_QUOTES); ?>"
                                        data-role="<?php echo htmlspecialchars((string)$user['role'], ENT_QUOTES); ?>"
                                        onclick="openEditUserModal(this)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    <form method="POST" class="action-form" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status pengguna ini?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $status; ?>">
                                        <button type="submit" class="btn-toggle <?php echo $status; ?>">
                                            <i class='bx bx-toggle-<?php echo $status === 'active' ? 'right' : 'left'; ?>'></i>
                                            <?php echo $status === 'active' ? 'Nonaktifkan' : 'Aktifkan'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="user-modal-overlay" id="add-user-modal" onclick="closeUserModalOnBackdrop(event, 'add-user-modal')">
            <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="add-user-modal-title">
                <div class="user-modal-header">
                    <h3 id="add-user-modal-title">Tambah Pengguna</h3>
                    <button type="button" class="user-modal-close" onclick="closeUserModal('add-user-modal')">&times;</button>
                </div>
                <div class="user-modal-body">
                    <form method="POST" class="add-form" id="add-user-form">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-group">
                            <label for="add_username">Username</label>
                            <input type="text" id="add_username" name="username" maxlength="30" value="<?php echo htmlspecialchars((string)$addUserState['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="add_full_name">Nama Lengkap</label>
                            <input type="text" id="add_full_name" name="full_name" maxlength="100" value="<?php echo htmlspecialchars((string)$addUserState['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="add_email">Email</label>
                            <input type="email" id="add_email" name="email" maxlength="150" value="<?php echo htmlspecialchars((string)$addUserState['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="add_role">Role</label>
                            <select id="add_role" name="role" required>
                                <option value="field" <?php echo $addUserState['role'] === 'field' ? 'selected' : ''; ?>>Field</option>
                                <option value="office" <?php echo $addUserState['role'] === 'office' ? 'selected' : ''; ?>>Office</option>
                                <option value="admin" <?php echo $addUserState['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_password">Password</label>
                            <input type="password" id="add_password" name="password" minlength="6" required>
                        </div>
                        <div class="form-group">
                            <label for="add_confirm_password">Konfirmasi Password</label>
                            <input type="password" id="add_confirm_password" name="confirm_password" minlength="6" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Simpan Pengguna</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="user-modal-overlay" id="edit-user-modal" onclick="closeUserModalOnBackdrop(event, 'edit-user-modal')">
            <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="edit-user-modal-title">
                <div class="user-modal-header">
                    <h3 id="edit-user-modal-title">Edit Pengguna</h3>
                    <button type="button" class="user-modal-close" onclick="closeUserModal('edit-user-modal')">&times;</button>
                </div>
                <div class="user-modal-body">
                    <form method="POST" class="add-form" id="edit-user-form">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" id="edit_user_id" value="<?php echo htmlspecialchars((string)$editUserState['user_id']); ?>">
                        <div class="form-group">
                            <label for="edit_username">Username</label>
                            <input type="text" id="edit_username" name="username" maxlength="30" value="<?php echo htmlspecialchars((string)$editUserState['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_full_name">Nama Lengkap</label>
                            <input type="text" id="edit_full_name" name="full_name" maxlength="100" value="<?php echo htmlspecialchars((string)$editUserState['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email" maxlength="150" value="<?php echo htmlspecialchars((string)$editUserState['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_role">Role</label>
                            <select id="edit_role" name="role" required>
                                <option value="field" <?php echo $editUserState['role'] === 'field' ? 'selected' : ''; ?>>Field</option>
                                <option value="office" <?php echo $editUserState['role'] === 'office' ? 'selected' : ''; ?>>Office</option>
                                <option value="admin" <?php echo $editUserState['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_password">Password Baru (opsional)</label>
                            <input type="password" id="edit_password" name="password" minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="edit_confirm_password">Konfirmasi Password Baru</label>
                            <input type="password" id="edit_confirm_password" name="confirm_password" minlength="6">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Update Pengguna</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script>
        function openAddUserModal() {
            const form = document.getElementById('add-user-form');
            if (form) {
                form.reset();
            }
            const modal = document.getElementById('add-user-modal');
            if (modal) {
                modal.classList.add('show');
            }
        }

        function openEditUserModal(button) {
            if (!button) {
                return;
            }

            document.getElementById('edit_user_id').value = button.dataset.userId || '';
            document.getElementById('edit_username').value = button.dataset.username || '';
            document.getElementById('edit_full_name').value = button.dataset.fullName || '';
            document.getElementById('edit_email').value = button.dataset.email || '';
            document.getElementById('edit_role').value = button.dataset.role || 'field';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_confirm_password').value = '';

            const modal = document.getElementById('edit-user-modal');
            if (modal) {
                modal.classList.add('show');
            }
        }

        function closeUserModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
            }
        }

        function closeUserModalOnBackdrop(event, modalId) {
            if (event.target && event.target.id === modalId) {
                closeUserModal(modalId);
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeUserModal('add-user-modal');
                closeUserModal('edit-user-modal');
            }
        });

        (function() {
            const modalToOpen = <?php echo json_encode($modalToOpen); ?>;
            if (modalToOpen === 'add-user-modal' || modalToOpen === 'edit-user-modal') {
                closeUserModal('add-user-modal');
                closeUserModal('edit-user-modal');
                const modal = document.getElementById(modalToOpen);
                if (modal) {
                    modal.classList.add('show');
                }
            }
        })();
    </script>
</body>

</html>