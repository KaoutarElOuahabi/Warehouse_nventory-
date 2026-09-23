<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\StockLookupService;
use App\Services\UnitService;

/**
 * Spec 5: after entering Quantity, the backend performs the comparison BEFORE
 * the count is finally saved, so the user can recheck physically if a
 * difference is detected. This endpoint validates + signals but does NOT
 * persist anything and never reveals the expected value.
 */

Auth::requireRole('entry', 'control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['address_code', 'part_number', 'quantity']);

$addressCode = trim((string)$data['address_code']);
$huNotAvailable = !empty($data['hu_not_available']);
$hu = isset($data['hu']) ? trim((string)$data['hu']) : '';
$partNumber = trim((string)$data['part_number']);
$rawQuantity = $data['quantity'];
$clientUnit = isset($data['unit']) ? trim((string)$data['unit']) : '';

if (!$huNotAvailable && $hu === '') {
    Response::error('Handling Unit is required unless "HU NOT AVAILABLE" is checked.', 422);
}
if (!$huNotAvailable) {
    validate_hu_format($hu);
}
if ($partNumber === '') {
    Response::error('Part Number is required.', 422);
}

$masterPart = StockLookupService::findPartByNumber($partNumber);
if (!$masterPart) {
    Response::error('Part Number is not in master data. Only known part numbers are allowed.', 422);
}
$partNumber = $masterPart['part_number'];

$address = AddressService::findByCode($addressCode);
if (!$address) {
    Response::error('Address not found in master data. Use a known address from the imported stock.', 422);
}
$addressId = (int)$address['id'];
if (AddressService::isLockedForEntry($address)) {
    Response::error(AddressService::lockedMessage($address), 409);
}

// Physical counts only (never the SAP quantity): lets the UI ask before an
// existing count is replaced, or warn that the HU was counted elsewhere.
$alreadyHere = null;
$countedElsewhere = [];
if (!$huNotAvailable) {
    $stmt = Database::connection()->prepare(
        'SELECT pc.address_id, pc.quantity, pc.unit, a.code FROM physical_counts pc
         INNER JOIN addresses a ON a.id = pc.address_id
         WHERE pc.hu = :hu AND pc.is_deleted = 0'
    );
    $stmt->execute([':hu' => $hu]);
    foreach ($stmt->fetchAll() as $row) {
        if ((int)$row['address_id'] === $addressId) {
            $alreadyHere = ['quantity' => (float)$row['quantity'], 'unit' => $row['unit']];
        } else {
            $countedElsewhere[] = $row['code'];
        }
    }
}

$authoritativeUnit = null;
$expectedForHu = null;
if (!$huNotAvailable && $hu !== '') {
    $expectedForHu = StockLookupService::findByHu($hu);
    if ($expectedForHu) {
        $authoritativeUnit = $expectedForHu['unit'];
    }
}
if ($authoritativeUnit === null) {
    $authoritativeUnit = $masterPart['unit'];
}
if ($authoritativeUnit === null) {
    if ($clientUnit === '') {
        Response::error('Unit of measure could not be determined from master data. Please select a unit manually.', 422);
    }
    $authoritativeUnit = UnitService::normalize($clientUnit);
}

$validation = UnitService::validateQuantity($rawQuantity, $authoritativeUnit);
if (!$validation['valid']) {
    Response::error($validation['error'], 422);
}
$quantity = $validation['value'];

$signal = 'RECORDED';
if ($expectedForHu !== null && $addressId !== null && (int)$expectedForHu['address_id'] === $addressId) {
    $sameQty = abs((float)$expectedForHu['quantity'] - $quantity) < 0.0005;
    $signal = $sameQty ? 'MATCH' : 'DIFFERENCE';
}

Response::ok([
    'signal' => $signal,
    'unit' => $authoritativeUnit,
    'quantity' => $quantity,
    'already_here' => $alreadyHere,
    'counted_elsewhere' => array_values(array_unique($countedElsewhere)),
]);
