<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\StockLookupService;

Auth::requireRole('entry', 'control', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['hu']);
$hu = trim((string)$data['hu']);
validate_hu_format($hu);

$row = StockLookupService::findByHu($hu);

if (!$row) {
    Response::ok(['found' => false]);
}

Response::ok([
    'found' => true,
    'address_code' => $row['address_code'],
    'part_number' => $row['part_number'],
    'unit' => $row['unit'],
]);
