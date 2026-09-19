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
require_fields($data, ['part_number']);
$pn = trim((string)$data['part_number']);

$unit = StockLookupService::unitForPartNumber($pn);

Response::ok(['unit' => $unit]); // null if PN unknown — user must type the unit manually
