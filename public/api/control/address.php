<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\ComparisonService;

$user = Auth::requireRole('control', 'admin');

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    Response::error('code is required', 422);
}

$address = AddressService::findByCode($code);
if (!$address) {
    Response::error('Address not found.', 404);
}

if ($address['status'] === 'COMPLETED_CONTROL_REQUIRED') {
    AddressService::setStatus((int)$address['id'], 'CONTROL_IN_PROGRESS');
    $address = AddressService::findByCode($code);
}

$cmp = ComparisonService::compareAddress((int)$address['id']);

// Controllers CAN see expected quantities (spec 10) — full detail here.
Response::ok([
    'address' => [
        'code' => $address['code'],
        'status' => $address['status'],
        'control_observations' => $address['control_observations'],
    ],
    'lines' => $cmp['lines'],
    'summary' => $cmp['summary'],
]);
