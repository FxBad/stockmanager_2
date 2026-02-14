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

// Handle user status toggle
if (isset($_POST['toggle_status'])) {
    try {
        // Validate inputs
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $currentStatus = isset($_POST['current_status']) ? $_POST['current_status'] : '';

        if ($userId <= 0) {
            throw new Exception('Invalid user ID');
        }

        // Prevent toggling your own account or any admin account
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

        $message = "<script>showModal({title: 'Sukses', message: 'User status updated successfully!', type: 'success'});</script>";
    } catch (Exception $e) {
        // Log server-side error (if writable)
        if (is_writable(__DIR__ . '/../logs')) {
            error_log('manage-users error: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/error.log');
        }
        $message = "<script>showModal({title: 'Error', message: '" . addslashes(htmlspecialchars($e->getMessage())) . "', type: 'error'});</script>";
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
    $message = "<script>showModal({title: 'Error', message: 'Gagal memuat daftar pengguna.', type: 'error'});</script>";
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

        <div class="table-container">
            <div class="table-header">
                <h3>Pengguna Sistem</h3>
                <a href="register.php" class="btn-add">
                    <i class='bx bx-user-plus'></i>
                    Tambah Pengguna
                </a>
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
                        $status = isset($user['status']) ? preg_replace('/[^a-z0-9_\-]/i', '', $user['status']) : 'inactive';
                        $lastLogin = !empty($user['last_login']) ? htmlspecialchars($user['last_login']) : '';
                    ?>
                        <tr>
                            <td><?php echo $username; ?></td>
                            <td><?php echo $fullName; ?></td>
                            <td><?php echo $email; ?></td>
                            <td><span class="role <?php echo $role; ?>"><?php echo ucfirst($role); ?></span></td>
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
                                <form method="POST" class="action-form" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status pengguna ini?');">
                                    <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $status; ?>">
                                    <button type="submit" name="toggle_status" class="btn-toggle <?php echo $status; ?>">
                                        <i class='bx bx-toggle-<?php echo $status === 'active' ? 'right' : 'left'; ?>'></i>
                                        <?php echo $status === 'active' ? 'Nonaktifkan' : 'Aktifkan'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    <script src="script.js?<?php echo getVersion(); ?>"></script>
    <script>
        // showModal(title, message, type) - type: 'success', 'error', etc.
        function showModal(title, message, type = 'info') {
            // Remove existing modal if present
            const oldModal = document.getElementById('custom-modal');
            if (oldModal) oldModal.remove();
            const modal = document.createElement('div');
            modal.id = 'custom-modal';
            modal.style.position = 'fixed';
            modal.style.top = 0;
            modal.style.left = 0;
            modal.style.width = '100vw';
            modal.style.height = '100vh';
            modal.style.background = 'rgba(0,0,0,0.3)';
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            modal.style.zIndex = 9999;
            modal.innerHTML = `
            <div style="background:#fff;min-width:320px;max-width:90vw;padding:24px 20px 16px 20px;border-radius:8px;box-shadow:0 2px 16px rgba(0,0,0,0.15);text-align:center;">
                <h2 style="margin-top:0;color:${type==='success'?'#2e7d32':type==='error'?'#c62828':'#333'};font-size:1.3em;">${title}</h2>
                <div style="margin:12px 0 18px 0;font-size:1.1em;">${message}</div>
                <button onclick="document.getElementById('custom-modal').remove();" style="padding:7px 22px;font-size:1em;border:none;border-radius:4px;background:${type==='success'?'#43a047':type==='error'?'#e53935':'#1976d2'};color:#fff;cursor:pointer;">Tutup</button>
            </div>
        `;
            document.body.appendChild(modal);
        }
    </script>
</body>

</html>