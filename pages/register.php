<?php
session_start();
require_once __DIR__ . '/../cache_control.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// Check if this is admin creating new user
$isAdminCreating = isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin';

// If not admin and already logged in, redirect to dashboard
if (!$isAdminCreating && isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Preserve submitted values on error
$preserveUsername = '';
$preserveFullName = '';
$preserveEmail = '';
$preserveRole = 'field';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $preserveUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        $preserveFullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $preserveEmail = isset($_POST['email']) ? trim($_POST['email']) : '';

        // Get role from admin or default to 'field'
        $allowedRoles = ['field', 'office', 'admin'];
        if ($isAdminCreating && isset($_POST['role']) && in_array($_POST['role'], $allowedRoles, true)) {
            $preserveRole = $_POST['role'];
        } else {
            $preserveRole = 'field';
        }

        // Basic validation
        if ($preserveUsername === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $preserveUsername)) {
            throw new Exception('Username harus 3-30 karakter (huruf, angka, underscore).');
        }

        if ($preserveFullName === '' || strlen($preserveFullName) > 100) {
            throw new Exception('Nama lengkap tidak boleh kosong atau terlalu panjang.');
        }

        if (!filter_var($preserveEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Alamat email tidak valid.');
        }

        if ($password === '' || $password !== $confirm_password) {
            throw new Exception('Password kosong atau konfirmasi password tidak cocok.');
        }

        if (strlen($password) < 6) {
            throw new Exception('Password minimal 6 karakter.');
        }

        // Check uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $preserveUsername]);
        if ($stmt->fetch()) {
            throw new Exception('Username sudah terdaftar.');
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $preserveEmail]);
        if ($stmt->fetch()) {
            throw new Exception('Email sudah digunakan.');
        }

        // Insert new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, password, full_name, email, role, status) 
                VALUES (:username, :password, :full_name, :email, :role, 'active')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':username' => $preserveUsername,
            ':password' => $hashed_password,
            ':full_name' => $preserveFullName,
            ':email' => $preserveEmail,
            ':role' => $preserveRole
        ]);

        $success = 'Registrasi pengguna berhasil!';

        // Redirect after successful creation for admins
        if ($isAdminCreating) {
            header('Location: manage-users.php?success=1');
            exit;
        }

        // Clear preserved fields for normal registration
        $preserveUsername = $preserveFullName = $preserveEmail = '';
        $preserveRole = 'field';
    } catch (PDOException $e) {
        // Log detailed DB error server-side
        if (is_writable(__DIR__ . '/../logs')) {
            error_log('register.php PDO error: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/error.log');
        }
        // Map common SQLSTATE duplicate error to friendly message
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            $error = 'Akun dengan username atau email tersebut sudah ada.';
        } else {
            $error = 'Terjadi kesalahan server saat membuat akun.';
        }
    } catch (Exception $e) {
        // Log and show friendly message
        if (is_writable(__DIR__ . '/../logs')) {
            error_log('register.php error: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/error.log');
        }
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <title>Daftar - StockManager</title>
    <link rel="stylesheet" href="style.css?<?php echo getVersion('style.css'); ?>">
    <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
</head>

<body class="login-page">
    <div class="login-container">
        <form method="POST" action="" class="login-form register-form">
            <h2><?php echo $isAdminCreating ? 'Tambah Pengguna Baru' : 'Buat Akun'; ?></h2>

            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($success); ?>
                    <?php if (!$isAdminCreating): ?>
                        <a href="login.php">Klik di sini untuk masuk</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Nama Pengguna</label>
                <div class="input-group">
                    <i class='bx bx-user'></i>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($preserveUsername); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="full_name">Nama Lengkap</label>
                <div class="input-group">
                    <i class='bx bx-id-card'></i>
                    <input type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars($preserveFullName); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-group">
                    <i class='bx bx-envelope'></i>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($preserveEmail); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <div class="input-group">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" id="password" name="password" required>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Konfirmasi Kata Sandi</label>
                <div class="input-group">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <?php if ($isAdminCreating): ?>
                <div class="form-group">
                    <label for="role">Peran Pengguna</label>
                    <div class="input-group">
                        <i class='bx bx-shield'></i>
                        <select name="role" id="role" required>
                            <option value="field" <?php echo $preserveRole === 'field' ? 'selected' : ''; ?>>Field</option>
                            <option value="office" <?php echo $preserveRole === 'office' ? 'selected' : ''; ?>>Office</option>
                            <option value="admin" <?php echo $preserveRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn-login">
                <?php echo $isAdminCreating ? 'Tambah Pengguna' : 'Daftar'; ?>
            </button>

            <?php if ($isAdminCreating): ?>
                <a href="manage-users.php" class="btn-back">Kembali ke Kelola Pengguna</a>
            <?php else: ?>
                <p class="text-center">
                    Sudah punya akun? <a href="login.php">Masuk di sini</a>
                </p>
            <?php endif; ?>
        </form>
    </div>
    <script src="script.js?<?php echo getVersion('script.js'); ?>"></script>
</body>

</html>