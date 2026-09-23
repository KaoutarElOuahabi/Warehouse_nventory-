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

// Master data is the only rule for addresses (any format, kept as text).
$address = AddressService::findByCode($code);
if (!$address) {
    if (is_hu_format($code)) {
        Response::error("$code is a Handling Unit, not an address. Select the address from the list.", 422);
    }
    Response::error('Address not found in master data. Select a valid address from the list.', 422);
}
if (AddressService::isLockedForEntry($address)) {
    Response::error(AddressService::lockedMessage($address), 409);
}

AddressService::markInProgress((int)$address['id']);
$address = AddressService::findById((int)$address['id']);

Response::ok([
    'address' => [
        'code' => $address['code'],
        'status' => $address['status'],
    ],
]);
