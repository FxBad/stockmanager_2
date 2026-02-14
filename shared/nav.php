<?php
require_once __DIR__ . '/../functions.php';
$current = basename($_SERVER['PHP_SELF']);
$role = currentUserRole();
$isAdmin = $role === 'admin';
$isOffice = $role === 'office';
$isField = $role === 'field';
?>
<nav>
    <div class="logo">
        <i class="bx bx-menu menu-icon"></i>
        <span class="logo-name">StockManager</span>
    </div>
    <div class="sidebar">
        <div class="logo">
            <i class="bx bx-menu menu-icon"></i>
            <span class="logo-name">StockManager</span>
        </div>
        <div class="sidebar-content">
            <ul class="lists">
                <!-- Common Menu Items -->
                <li class="list <?php echo isActive(['index.php']); ?>">
                    <a href="index.php" class="nav-link">
                        <i class="bx bx-home-alt icon"></i>
                        <span class="link">Dashboard</span>
                    </a>
                </li>
                <li class="list <?php echo isActive(['view.php']); ?>">
                    <a href="view.php" class="nav-link">
                        <i class="bx bx-list-ul icon"></i>
                        <span class="link">Lihat Data</span>
                    </a>
                </li>
                <li class="list <?php echo isActive(['update-stock.php']); ?>">
                    <a href="update-stock.php" class="nav-link">
                        <i class="bx bx-refresh icon"></i>
                        <span class="link">Pembaruan Stok</span>
                    </a>
                </li>

                <!-- Role-based Menu Items -->
                <?php if ($isAdmin || $isOffice): ?>
                    <li class="list nav-separator">
                        <span class="separator-text">Manajemen</span>
                    </li>

                    <li class="list <?php echo isActive(['manage-items.php', 'add.php', 'edit-item.php']); ?>">
                        <a href="manage-items.php" class="nav-link">
                            <i class="bx bx-package icon"></i>
                            <span class="link">Kelola Barang</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <li class="list <?php echo isActive(['manage-units.php']); ?>">
                        <a href="manage-units.php" class="nav-link">
                            <i class="bx bx-ruler icon"></i>
                            <span class="link">Kelola Satuan</span>
                        </a>
                    </li>

                    <li class="list <?php echo isActive(['manage-users.php']); ?>">
                        <a href="manage-users.php" class="nav-link">
                            <i class="bx bx-user icon"></i>
                            <span class="link">Kelola Pengguna</span>
                        </a>
                    </li>
                <?php endif; ?>

            </ul>

            <div class="bottom-content">
                <li class="list">
                    <a href="logout.php" class="nav-link">
                        <i class="bx bx-log-out icon"></i>
                        <span class="link">Keluar</span>
                    </a>
                </li>
            </div>
        </div>
    </div>
</nav>
<div class="overlay"></div>