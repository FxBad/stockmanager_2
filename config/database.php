<?php
// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Force MySQL session timezone to WIB (UTC+7)
    $pdo->exec("SET time_zone = '+07:00'");
    // Verify session timezone (log if it fails to apply)
    $tzStmt = $pdo->query("SELECT @@session.time_zone AS session_tz");
    $tzRow = $tzStmt ? $tzStmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!isset($tzRow['session_tz']) || $tzRow['session_tz'] !== '+07:00') {
        $reportedTz = $tzRow['session_tz'] ?? 'unknown';
        error_log("[StockManager] MySQL session timezone not set to +07:00; reported={$reportedTz}");
    }
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
