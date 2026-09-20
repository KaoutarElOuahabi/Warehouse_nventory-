<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;

Auth::requireRole('entry', 'control', 'admin');

try {
    $code = trim((string)($_GET['address_code'] ?? ''));
    if ($code === '') {
        Response::error('address_code is required', 422);
    }

    $address = AddressService::findByCode($code);
    if (!$address) {
        Response::ok(['rows' => [], 'address_status' => null]);
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT id, hu, hu_not_available, part_number, unit, quantity, entered_at
         FROM physical_counts WHERE address_id = :aid AND is_deleted = 0 ORDER BY id ASC'
    );
    $stmt->execute([':aid' => $address['id']]);
    $rows = $stmt->fetchAll();

    Response::ok(['rows' => $rows, 'address_status' => $address['status']]);
} catch (\Throwable $e) {
    error_log('[inventory-app] my_counts failed: ' . $e->getMessage());
    Response::error('Could not load the physical counts.', 500);
}
