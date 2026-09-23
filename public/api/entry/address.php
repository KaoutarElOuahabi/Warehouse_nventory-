<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;

Auth::requireRole('entry', 'control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['code']);
$code = strtoupper(trim((string)$data['code']));
if ($code === '') {
    Response::error('Address code cannot be empty.', 422);
}
if (!preg_match('/^[A-Z]-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $code)) {
    Response::error('Address format invalid. Use a known address like A-01-01. HU numbers are not valid addresses.', 422);
}

$address = AddressService::findByCode($code);
if (!$address) {
    Response::error('Address not found in master data. Use a known address from the imported stock.', 422);
}

AddressService::markInProgress((int)$address['id']);
$address = AddressService::findById((int)$address['id']);

Response::ok([
    'address' => [
        'code' => $address['code'],
        'status' => $address['status'],
    ],
]);
