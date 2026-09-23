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
$code = trim((string)$data['code']);
if ($code === '') {
    Response::error('Address code cannot be empty.', 422);
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
