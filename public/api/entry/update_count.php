<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\AuditService;
use App\Services\StockLookupService;
use App\Services\UnitService;

/**
 * Lets a counter correct ONE line they recorded (quantity, or part number when
 * the HU isn't in SAP) or delete that single line. The address itself is never
 * reset. Locked once Control has taken the address.
 */

$user = Auth::requireRole('entry', 'control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['id', 'action']);
$action = (string)$data['action'];
if (!in_array($action, ['edit', 'delete'], true)) {
    Response::error('Unknown action.', 422);
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM physical_counts WHERE id = :id AND is_deleted = 0');
    $stmt->execute([':id' => (int)$data['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        Response::error('This line no longer exists (it may already have been deleted). Refresh the list.', 404);
    }

    $address = AddressService::findById((int)$row['address_id']);
    if (AddressService::isLockedForEntry($address)) {
        Response::error(AddressService::lockedMessage($address), 409);
    }
    if ($user['role'] === 'entry' && (int)$row['entered_by'] !== (int)$user['id']) {
        Response::error('You can only change lines you recorded yourself. Ask Control to correct this line.', 403);
    }

    $now = Database::now();
    $label = 'HU ' . ($row['hu'] ?? 'not available');

    if ($action === 'delete') {
        $pdo->prepare('UPDATE physical_counts SET is_deleted = 1, deleted_by = :uid, deleted_at = :now WHERE id = :id')
            ->execute([':uid' => $user['id'], ':now' => $now, ':id' => $row['id']]);
        AddressService::markCountingActivity((int)$address['id']);
        AuditService::log('physical_count', (int)$row['id'], 'entry_deleted', $user, $row, null,
            "Line deleted by counter at address {$address['code']} ($label)");
        Response::ok(['message' => 'Line deleted.']);
    }

    $partNumber = $row['part_number'];
    $unit = $row['unit'];
    $newPn = array_key_exists('part_number', $data) ? trim((string)$data['part_number']) : $partNumber;
    if ($newPn !== $partNumber) {
        $huInSap = !$row['hu_not_available'] && $row['hu'] !== null && StockLookupService::findByHu($row['hu']) !== null;
        if ($huInSap) {
            Response::error('The Part Number of this HU comes from SAP and cannot be changed. If the HU was wrong, delete the line and scan the correct HU.', 422);
        }
        $master = StockLookupService::findPartByNumber($newPn);
        if (!$master) {
            Response::error("Part Number \"$newPn\" is not in master data.", 422);
        }
        $partNumber = $master['part_number'];
        $unit = $master['unit'];
    }

    $validation = UnitService::validateQuantity($data['quantity'] ?? (float)$row['quantity'], $unit);
    if (!$validation['valid']) {
        Response::error($validation['error'], 422);
    }

    $pdo->prepare(
        'UPDATE physical_counts SET part_number = :pn, unit = :unit, quantity = :qty,
         last_edited_by = :uid, last_edited_at = :now WHERE id = :id'
    )->execute([
        ':pn' => $partNumber, ':unit' => $unit, ':qty' => $validation['value'],
        ':uid' => $user['id'], ':now' => $now, ':id' => $row['id'],
    ]);
    AddressService::markCountingActivity((int)$address['id']);
    AuditService::log('physical_count', (int)$row['id'], 'entry_edited', $user, $row, [
        'part_number' => $partNumber, 'unit' => $unit, 'quantity' => $validation['value'],
    ], "Line corrected by counter at address {$address['code']} ($label)");

    Response::ok(['message' => 'Line updated.']);
} catch (\Throwable $e) {
    error_log('[inventory-app] update_count failed: ' . $e->getMessage());
    Response::error('Could not update the line. Nothing was changed — please try again.', 500);
}
