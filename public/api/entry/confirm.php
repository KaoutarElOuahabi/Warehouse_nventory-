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

try {
    $address = AddressService::findByCode($addressCode);
    if (!$address) {
        Response::error('Address not found in master data. Use a known address from the imported stock.', 422);
    }
    $addressId = (int)$address['id'];
    if (AddressService::isLockedForEntry($address)) {
        Response::error(AddressService::lockedMessage($address), 409);
    }

    $authoritativeUnit = null;
    $expectedForHu = null;
    if (!$huNotAvailable && $hu !== '') {
        $expectedForHu = StockLookupService::findByHu($hu);
        if ($expectedForHu) {
            $authoritativeUnit = $expectedForHu['unit'];
            $partNumber = $expectedForHu['part_number'];
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

    $pdo = Database::connection();
    $now = Database::now();

    $existing = null;
    if (!$huNotAvailable && $hu !== '') {
        $stmt = $pdo->prepare(
            'SELECT * FROM physical_counts WHERE address_id = :aid AND hu = :hu AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([':aid' => $addressId, ':hu' => $hu]);
        $existing = $stmt->fetch() ?: null;
    }

    if (Database::driver() === 'sqlite') {
        // Local testing only (never concurrent multi-user traffic) — plain
        // select-then-write is fine here.
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE physical_counts SET part_number = :pn, unit = :unit, quantity = :qty,
                 last_edited_by = :uid, last_edited_at = :now WHERE id = :id'
            );
            $stmt->execute([
                ':pn' => $partNumber, ':unit' => $authoritativeUnit, ':qty' => $quantity,
                ':uid' => $user['id'], ':now' => $now, ':id' => $existing['id'],
            ]);
            $physicalId = (int)$existing['id'];
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
        }
    } else {
        // MySQL (production): atomic upsert keyed on the (address_id, hu_active)
        // uniqueness guard added by migrate.php. Two concurrent submits for the
        // same HU can no longer both insert — the second one updates the row
        // the first one just created instead of creating a duplicate.
        // `id = LAST_INSERT_ID(id)` makes lastInsertId() return the right row's
        // id whether this statement inserted or updated.
        $stmt = $pdo->prepare(
            'INSERT INTO physical_counts
             (address_id, hu, hu_not_available, part_number, unit, quantity, source, entered_by, entered_at, is_deleted)
             VALUES (:aid, :hu, :huna, :pn, :unit, :qty, :src, :uid, :now, 0)
             ON DUPLICATE KEY UPDATE
               id = LAST_INSERT_ID(id),
               part_number = VALUES(part_number),
               unit = VALUES(unit),
               quantity = VALUES(quantity),
               last_edited_by = VALUES(entered_by),
               last_edited_at = VALUES(entered_at)'
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
    }

    AddressService::markCountingActivity($addressId);

    if ($existing) {
        AuditService::log('physical_count', $physicalId, 'entry_updated', $user, $existing, [
            'part_number' => $partNumber, 'unit' => $authoritativeUnit, 'quantity' => $quantity,
        ], "Address $addressCode / HU $hu re-scanned by data entry");
    } else {
        AuditService::log('physical_count', $physicalId, 'entry_created', $user, null, [
            'address_code' => $addressCode, 'hu' => $hu ?: null, 'hu_not_available' => $huNotAvailable,
            'part_number' => $partNumber, 'unit' => $authoritativeUnit, 'quantity' => $quantity,
        ]);
    }

    $signal = 'RECORDED';
    if ($expectedForHu !== null && (int)$expectedForHu['address_id'] === $addressId) {
        $sameQty = abs((float)$expectedForHu['quantity'] - $quantity) < 0.0005;
        $signal = $sameQty ? 'MATCH' : 'DIFFERENCE';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) as c FROM physical_counts WHERE address_id = :aid AND is_deleted = 0');
    $stmt->execute([':aid' => $addressId]);
    $count = (int)$stmt->fetch()['c'];

    Response::ok([
        'signal' => $signal,
        'part_number' => $partNumber,
        'unit' => $authoritativeUnit,
        'hus_recorded' => $count,
    ]);
} catch (\Throwable $e) {
    error_log('[inventory-app] entry confirm failed: ' . $e->getMessage());
    Response::error('Entry confirmation failed while querying the database.', 500);
}
