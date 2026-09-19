<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\AuditService;
use App\Services\ComparisonService;

$user = Auth::requireRole('control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['address_code']);
$code = trim((string)$data['address_code']);
$observations = trim((string)($data['observations'] ?? ''));

$address = AddressService::findByCode($code);
if (!$address) {
    Response::error('Address not found.', 404);
}
$addressId = (int)$address['id'];

$before = $address;
$cmp = ComparisonService::compareAddress($addressId);

AddressService::setStatus($addressId, 'CONTROLLED', [
    'controlled_by' => $user['id'],
    'controlled_at' => \App\Core\Database::now(),
    'control_observations' => $observations !== '' ? $observations : null,
]);

AuditService::log(
    'address', $addressId, 'control_validated', $user, $before,
    ['status' => 'CONTROLLED', 'summary' => $cmp['summary']],
    $observations !== '' ? $observations : null
);

Response::ok([
    'address_code' => $code,
    'status' => 'CONTROLLED',
    'remaining_issues' => $cmp['summary']['issues'],
]);
