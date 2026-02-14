<?php
// Variables expected when included:
// $userId, $role, $page, $ip, $ts
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>403 Forbidden</title>
  <link rel="stylesheet" href="/style.css?<?php echo isset($GLOBALS['version']) ? $GLOBALS['version'] : time(); ?>">
  <link href="https://unpkg.com/boxicons@2.1.2/css/boxicons.min.css" rel="stylesheet">
  <style>
    /* Small, self-contained styles to ensure page looks good even if site CSS differs */
    .error-page{display:flex;align-items:center;justify-content:center;min-height:80vh;padding:24px}
    .error-card{max-width:820px;width:100%;background:var(--card-bg,#fff);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.08);padding:32px;display:flex;gap:24px;align-items:center}
    .error-illustration{font-size:72px;color:var(--muted,#e55353)}
    .error-content h1{margin:0 0 8px;font-size:28px}
    .error-content p{margin:0 0 16px;color:var(--muted,#666)}
    .error-actions{display:flex;gap:12px;margin-top:12px}
    .btn-primary{background:#3b82f6;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none}
    .btn-secondary{background:transparent;border:1px solid #ddd;color:#333;padding:10px 14px;border-radius:8px;text-decoration:none}
    .meta{margin-top:14px;font-size:12px;color:#888}
    @media(max-width:640px){.error-card{flex-direction:column;text-align:center}}
  </style>
</head>
<body>
  <div class="error-page">
    <div class="error-card">
      <div class="error-illustration">
        <i class='bx bx-error-circle'></i>
      </div>
      <div class="error-content">
        <h1>Akses Ditolak</h1>
        <p>Maaf, Anda tidak memiliki izin untuk membuka halaman ini. Jika Anda rasa ini sebuah kesalahan, hubungi administrator sistem.</p>
        <div class="error-actions">
          <a class="btn-primary" href="/index.php">Kembali ke Dashboard</a>
        </div>
        <div class="meta">
          <div>Waktu: <?php echo htmlspecialchars($ts); ?></div>
          <div>Halaman: <?php echo htmlspecialchars($page); ?></div>
          <div>User ID: <?php echo (int)$userId; ?> &middot; Role: <?php echo htmlspecialchars($role); ?> &middot; IP: <?php echo htmlspecialchars($ip); ?></div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
