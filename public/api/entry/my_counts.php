<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\StockLookupService;

$user = Auth::requireRole('entry', 'control', 'admin');

try {
    $code = trim((string)($_GET['address_code'] ?? ''));
    if ($code === '') {
        Response::error('address_code is required', 422);
    }

    $address = AddressService::findByCode($code);
    if (!$address) {
        Response::ok(['rows' => [], 'address_status' => null, 'locked' => false]);
    }
    $locked = AddressService::isLockedForEntry($address);

    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT pc.id, pc.hu, pc.hu_not_available, pc.part_number, pc.unit, pc.quantity, pc.entered_at,
                pc.entered_by, u.full_name AS entered_by_name
         FROM physical_counts pc LEFT JOIN users u ON u.id = pc.entered_by
         WHERE pc.address_id = :aid AND pc.is_deleted = 0 ORDER BY pc.id DESC'
    );
    $stmt->execute([':aid' => $address['id']]);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $own = (int)$r['entered_by'] === (int)$user['id'];
        $huInSap = !$r['hu_not_available'] && $r['hu'] !== null && StockLookupService::findByHu($r['hu']) !== null;
        $rows[] = [
            'id' => (int)$r['id'],
            'hu' => $r['hu'],
            'hu_not_available' => (bool)$r['hu_not_available'],
            'part_number' => $r['part_number'],
            'unit' => $r['unit'],
            'quantity' => (float)$r['quantity'],
            'entered_at' => $r['entered_at'],
            'entered_by_name' => $r['entered_by_name'],
            'own' => $own,
            'can_edit' => !$locked && ($own || $user['role'] !== 'entry'),
            'pn_editable' => !$huInSap,
        ];
    }

    Response::ok(['rows' => $rows, 'address_status' => $address['status'], 'locked' => $locked]);
} catch (\Throwable $e) {
    error_log('[inventory-app] my_counts failed: ' . $e->getMessage());
    Response::error('Could not load the physical counts.', 500);
}
