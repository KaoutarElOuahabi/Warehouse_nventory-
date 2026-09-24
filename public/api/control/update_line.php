<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\AuditService;
use App\Services\ComparisonService;
use App\Services\StockLookupService;
use App\Services\UnitService;

$user = Auth::requireRole('control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['address_code', 'action']);

$code = trim((string)$data['address_code']);
$action = trim((string)$data['action']);

try {
    $address = AddressService::findByCode($code);
    if (!$address) {
        Response::error('Address not found.', 404);
    }
    $addressId = (int)$address['id'];
    $pdo = Database::connection();

    switch ($action) {
        case 'correct': {
            require_fields($data, ['physical_id']);
            $stmt = $pdo->prepare('SELECT * FROM physical_counts WHERE id = :id AND address_id = :aid AND is_deleted = 0');
            $stmt->execute([':id' => (int)$data['physical_id'], ':aid' => $addressId]);
            $row = $stmt->fetch();
            if (!$row) Response::error('Physical count line not found.', 404);

            $hu = array_key_exists('hu', $data) ? trim((string)$data['hu']) : (string)($row['hu'] ?? '');
            if ($hu !== '') {
                $hu = validate_hu_format($hu);
                if ($hu !== normalize_hu((string)$row['hu']) && huActiveAt($pdo, $addressId, $hu)) {
                    Response::error("HU $hu is already recorded at this address.", 409);
                }
            }
            $master = StockLookupService::findPartByNumber(array_key_exists('part_number', $data) ? trim((string)$data['part_number']) : $row['part_number']);
            if (!$master) {
                Response::error('Part Number is not in master data.', 422);
            }
            $pn = $master['part_number'];
            $unit = $master['unit'];
            $qtyRaw = array_key_exists('quantity', $data) ? $data['quantity'] : (float)$row['quantity'];

            $validation = UnitService::validateQuantity($qtyRaw, $unit);
            if (!$validation['valid']) Response::error($validation['error'], 422);

            $huNotAvailable = $hu !== '' ? 0 : (int)$row['hu_not_available'];

            $stmt = $pdo->prepare(
                'UPDATE physical_counts SET hu = :hu, hu_not_available = :huna, part_number = :pn, unit = :unit, quantity = :qty,
                 last_edited_by = :uid, last_edited_at = :now WHERE id = :id'
            );
            $stmt->execute([
                ':hu' => $hu !== '' ? $hu : null, ':huna' => $huNotAvailable, ':pn' => $pn, ':unit' => $unit, ':qty' => $validation['value'],
                ':uid' => $user['id'], ':now' => Database::now(), ':id' => $row['id'],
            ]);

            AuditService::log('physical_count', (int)$row['id'], 'control_corrected', $user, $row, [
                'hu' => $hu, 'part_number' => $pn, 'unit' => $unit, 'quantity' => $validation['value'],
            ], $data['note'] ?? null);
            break;
        }

        case 'add': {
            require_fields($data, ['part_number', 'quantity']);
            $hu = trim((string)($data['hu'] ?? ''));
            if ($hu !== '') {
                $hu = validate_hu_format($hu);
                if (huActiveAt($pdo, $addressId, $hu)) {
                    Response::error("HU $hu is already recorded at this address — edit that line instead.", 409);
                }
            }
            $master = StockLookupService::findPartByNumber(trim((string)$data['part_number']));
            if (!$master) {
                Response::error('Part Number is not in master data.', 422);
            }
            $data['part_number'] = $master['part_number'];
            $unit = $master['unit'];
            $validation = UnitService::validateQuantity($data['quantity'], $unit);
            if (!$validation['valid']) Response::error($validation['error'], 422);

            $stmt = $pdo->prepare(
                'INSERT INTO physical_counts (address_id, hu, hu_not_available, part_number, unit, quantity, source, entered_by, entered_at, is_deleted)
                 VALUES (:aid, :hu, 0, :pn, :unit, :qty, :src, :uid, :now, 0)'
            );
            $stmt->execute([
                ':aid' => $addressId, ':hu' => $hu !== '' ? $hu : null, ':pn' => $data['part_number'],
                ':unit' => $unit, ':qty' => $validation['value'], ':src' => 'control_added',
                ':uid' => $user['id'], ':now' => Database::now(),
            ]);
            $newId = (int)Database::lastInsertId();
            AuditService::log('physical_count', $newId, 'control_added', $user, null, [
                'hu' => $hu, 'part_number' => $data['part_number'], 'unit' => $unit, 'quantity' => $validation['value'],
            ], $data['note'] ?? 'Missing physical stock added by control');
            break;
        }

        case 'remove': {
            require_fields($data, ['physical_id']);
            $stmt = $pdo->prepare('SELECT * FROM physical_counts WHERE id = :id AND address_id = :aid AND is_deleted = 0');
            $stmt->execute([':id' => (int)$data['physical_id'], ':aid' => $addressId]);
            $row = $stmt->fetch();
            if (!$row) Response::error('Physical count line not found.', 404);

            $stmt = $pdo->prepare(
                'UPDATE physical_counts SET is_deleted = 1, deleted_by = :uid, deleted_at = :now WHERE id = :id'
            );
            $stmt->execute([':uid' => $user['id'], ':now' => Database::now(), ':id' => $row['id']]);

            AuditService::log('physical_count', (int)$row['id'], 'control_removed', $user, $row, null, $data['note'] ?? null);
            break;
        }

        case 'confirm_difference': {
            AuditService::log(
                'address', $addressId, 'control_confirmed_difference', $user, null,
                ['hu' => $data['hu'] ?? null],
                $data['note'] ?? 'Controller confirmed the physical difference is real.'
            );
            break;
        }

        default:
            Response::error('Unknown action.', 422);
    }

    $cmp = ComparisonService::compareAddress($addressId);

    Response::ok(['lines' => $cmp['lines'], 'summary' => $cmp['summary']]);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        Response::error('This HU is already recorded at this address.', 409);
    }
    error_log('[inventory-app] control update failed: ' . $e->getMessage());
    Response::error('Control update failed while querying the database.', 500);
} catch (\Throwable $e) {
    error_log('[inventory-app] control update failed: ' . $e->getMessage());
    Response::error('Control update failed while querying the database.', 500);
}

function huActiveAt(\PDO $pdo, int $addressId, string $hu): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM physical_counts WHERE address_id = ? AND hu IN (' . hu_placeholders(hu_forms($hu)) . ') AND is_deleted = 0 LIMIT 1');
    $stmt->execute(array_merge([$addressId], hu_forms($hu)));
    return (bool)$stmt->fetchColumn();
}
