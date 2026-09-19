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

$row = StockLookupService::findByHu($hu);

if (!$row) {
    // Spec 6: unknown HU — do not block, ask for PN + qty manually.
    Response::ok(['found' => false]);
}

// Spec 2 & 15: NEVER include quantity here — Data Entry must not see expected qty.
Response::ok([
    'found' => true,
    'part_number' => $row['part_number'],
    'unit' => $row['unit'],
]);
