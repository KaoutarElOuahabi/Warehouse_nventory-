<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\AuditService;
use App\Services\ComparisonService;

$user = Auth::requireRole('entry', 'control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['address_code']);
$code = trim((string)$data['address_code']);

$address = AddressService::findByCode($code);
if (!$address) {
    Response::error('Unknown address — scan it first.', 404);
}
if (AddressService::isLockedForEntry($address)) {
    Response::error(AddressService::lockedMessage($address), 409);
}

$stmt = \App\Core\Database::connection()->prepare('SELECT COUNT(*) c FROM physical_counts WHERE address_id = :aid AND is_deleted = 0');
$stmt->execute([':aid' => $address['id']]);
$lineCount = (int)$stmt->fetch()['c'];
$confirmedEmpty = $lineCount === 0;
if ($confirmedEmpty && empty($data['confirm_empty'])) {
    Response::error('No HU recorded at this address. Confirm that the address is physically EMPTY.', 422, ['needs_empty_confirmation' => true]);
}

$result = ComparisonService::compareAddress((int)$address['id']);

AddressService::setStatus((int)$address['id'], $result['requires_control'] ? 'COMPLETED_CONTROL_REQUIRED' : 'COMPLETED_OK', [
    'completed_by' => $user['id'],
    'completed_at' => \App\Core\Database::now(),
]);

AuditService::log(
    'address',
    (int)$address['id'],
    'completed_by_entry',
    $user,
    null,
    ['requires_control' => $result['requires_control'], 'summary' => $result['summary'], 'confirmed_empty' => $confirmedEmpty],
    $confirmedEmpty ? "Address $code confirmed physically EMPTY by data entry" : "Address $code marked complete by data entry"
);

// Spec 5/15: no HU-level detail, no quantities — just the outcome.
Response::ok([
    'address_code' => $code,
    'status' => $result['requires_control'] ? 'COMPLETED_CONTROL_REQUIRED' : 'COMPLETED_OK',
    'message' => $result['requires_control']
        ? 'Address completed. A difference was detected — this address has been sent to Control.'
        : 'Address completed. Everything matches.',
]);
