<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isRole('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hanya admin yang dapat mengelola master data.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

function normalizeUnitValueInline($value)
{
    $value = trim((string)$value);
    $value = strtolower($value);
    return preg_replace('/\s+/', '_', $value);
}

try {
    if ($action === 'quick_create_category') {
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
        $isActive = isset($_POST['is_active']) && (string)$_POST['is_active'] === '1' ? 1 : 0;

        if ($name === '') {
            throw new Exception('Nama kategori wajib diisi.');
        }
        if (strlen($name) > 100) {
            throw new Exception('Nama kategori maksimal 100 karakter.');
        }
        if ($displayOrder < 0 || $displayOrder > 9999) {
            throw new Exception('Display order kategori harus di antara 0 sampai 9999.');
        }

        $stmtExists = $pdo->prepare('SELECT id FROM item_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmtExists->execute([':name' => $name]);
        if ($stmtExists->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Nama kategori sudah digunakan.');
        }

        $stmtInsert = $pdo->prepare('INSERT INTO item_categories (name, display_order, is_active) VALUES (:name, :display_order, :is_active)');
        $stmtInsert->execute([
            ':name' => $name,
            ':display_order' => $displayOrder,
            ':is_active' => $isActive,
        ]);

        echo json_encode([
            'success' => true,
            'type' => 'category',
            'message' => 'Kategori berhasil ditambahkan.',
            'item' => [
                'value' => $name,
                'label' => $name,
                'is_active' => $isActive,
            ],
        ]);
        exit;
    }

    if ($action === 'quick_create_unit') {
        $rawValue = isset($_POST['value']) ? (string)$_POST['value'] : '';
        $value = normalizeUnitValueInline($rawValue);
        $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
        $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;

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
            throw new Exception('Display order satuan harus di antara 0 sampai 9999.');
        }

        $stmtExists = $pdo->prepare('SELECT id FROM units WHERE value = :value LIMIT 1');
        $stmtExists->execute([':value' => $value]);
        if ($stmtExists->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Value satuan sudah digunakan.');
        }

        $stmtInsert = $pdo->prepare('INSERT INTO units (value, label, display_order, is_active) VALUES (:value, :label, :display_order, 1)');
        $stmtInsert->execute([
            ':value' => $value,
            ':label' => $label,
            ':display_order' => $displayOrder,
        ]);

        echo json_encode([
            'success' => true,
            'type' => 'unit',
            'message' => 'Satuan berhasil ditambahkan.',
            'item' => [
                'value' => $value,
                'label' => $label,
            ],
        ]);
        exit;
    }

    throw new Exception('Aksi tidak dikenali.');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
