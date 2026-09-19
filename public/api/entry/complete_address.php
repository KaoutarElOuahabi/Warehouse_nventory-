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

$result = ComparisonService::recomputeAndSetStatus(
    (int)$address['id'],
    'COMPLETED_OK',
    'COMPLETED_CONTROL_REQUIRED'
);

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
    ['requires_control' => $result['requires_control'], 'summary' => $result['summary']],
    "Address $code marked complete by data entry"
);

// Spec 5/15: no HU-level detail, no quantities — just the outcome.
Response::ok([
    'address_code' => $code,
    'status' => $result['requires_control'] ? 'COMPLETED_CONTROL_REQUIRED' : 'COMPLETED_OK',
    'message' => $result['requires_control']
        ? 'Address completed. A difference was detected — this address has been sent to Control.'
        : 'Address completed. Everything matches.',
]);
