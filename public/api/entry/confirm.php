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
 * SECURITY (spec 15): this endpoint NEVER returns expected/imported quantity,
 * the numerical gap, or the expected HU list to 'entry' users. Only a
 * generic MATCH / DIFFERENCE signal is returned.
 */

$user = Auth::requireRole('entry', 'control', 'admin');

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
if ($partNumber === '') {
    Response::error('Part Number is required.', 422);
}

$address = AddressService::getOrCreate($addressCode);
$addressId = (int)$address['id'];
AddressService::markInProgress($addressId);

// Determine the authoritative unit: prefer the system's own record over
// whatever the client sends, so a tampered client value can't bypass the
// decimal/whole-number validation rule.
$authoritativeUnit = null;
$expectedForHu = null;
if (!$huNotAvailable && $hu !== '') {
    $expectedForHu = StockLookupService::findByHu($hu);
    if ($expectedForHu) {
        $authoritativeUnit = $expectedForHu['unit'];
        // keep the system's PN for a known HU rather than trusting client-echoed PN
        $partNumber = $expectedForHu['part_number'];
    }
}
if ($authoritativeUnit === null) {
    $authoritativeUnit = StockLookupService::unitForPartNumber($partNumber);
}
if ($authoritativeUnit === null) {
    $authoritativeUnit = $clientUnit !== '' ? UnitService::normalize($clientUnit) : 'PCS';
}

$validation = UnitService::validateQuantity($rawQuantity, $authoritativeUnit);
if (!$validation['valid']) {
    Response::error($validation['error'], 422);
}
$quantity = $validation['value'];

$pdo = Database::connection();

// If this HU was already recorded at this address (not deleted), overwrite it
// rather than creating a duplicate line.
$existing = null;
if (!$huNotAvailable && $hu !== '') {
    $stmt = $pdo->prepare(
        'SELECT * FROM physical_counts WHERE address_id = :aid AND hu = :hu AND is_deleted = 0 LIMIT 1'
    );
    $stmt->execute([':aid' => $addressId, ':hu' => $hu]);
    $existing = $stmt->fetch() ?: null;
}

$now = Database::now();

if ($existing) {
    $before = $existing;
    $stmt = $pdo->prepare(
        'UPDATE physical_counts SET part_number = :pn, unit = :unit, quantity = :qty,
         last_edited_by = :uid, last_edited_at = :now WHERE id = :id'
    );
    $stmt->execute([
        ':pn' => $partNumber, ':unit' => $authoritativeUnit, ':qty' => $quantity,
        ':uid' => $user['id'], ':now' => $now, ':id' => $existing['id'],
    ]);
    $physicalId = (int)$existing['id'];
    AuditService::log('physical_count', $physicalId, 'entry_updated', $user, $before, [
        'part_number' => $partNumber, 'unit' => $authoritativeUnit, 'quantity' => $quantity,
    ], "Address $addressCode / HU $hu re-scanned by data entry");
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO physical_counts
         (address_id, hu, hu_not_available, part_number, unit, quantity, source, entered_by, entered_at, is_deleted)
         VALUES (:aid, :hu, :huna, :pn, :unit, :qty, :src, :uid, :now, 0)'
    );
    $stmt->execute([
        ':aid' => $addressId,
        ':hu' => ($huNotAvailable || $hu === '') ? null : $hu,
        ':huna' => $huNotAvailable ? 1 : 0,
        ':pn' => $partNumber,
        ':unit' => $authoritativeUnit,
        ':qty' => $quantity,
        ':src' => 'data_entry',
        ':uid' => $user['id'],
        ':now' => $now,
    ]);
    $physicalId = (int)Database::lastInsertId();
    AuditService::log('physical_count', $physicalId, 'entry_created', $user, null, [
        'address_code' => $addressCode, 'hu' => $hu ?: null, 'hu_not_available' => $huNotAvailable,
        'part_number' => $partNumber, 'unit' => $authoritativeUnit, 'quantity' => $quantity,
    ]);
}

// Spec 5: immediate blind signal, only when this exact HU has a known expected
// line at THIS address. No values are ever disclosed to the caller.
$signal = 'RECORDED';
if ($expectedForHu !== null && (int)$expectedForHu['address_id'] === $addressId) {
    $sameQty = abs((float)$expectedForHu['quantity'] - $quantity) < 0.0005;
    $signal = $sameQty ? 'MATCH' : 'DIFFERENCE';
}

// running count of HUs recorded at this address (for the on-screen counter)
$stmt = $pdo->prepare('SELECT COUNT(*) as c FROM physical_counts WHERE address_id = :aid AND is_deleted = 0');
$stmt->execute([':aid' => $addressId]);
$count = (int)$stmt->fetch()['c'];

Response::ok([
    'signal' => $signal, // RECORDED | MATCH | DIFFERENCE — never a number
    'part_number' => $partNumber,
    'unit' => $authoritativeUnit,
    'hus_recorded' => $count,
]);
