<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\StockLookupService;

Auth::requireRole('entry', 'control', 'admin');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || strlen($q) < 2) {
    Response::ok(['results' => []]);
}

$results = StockLookupService::searchPartNumbers($q, 15);
Response::ok(['results' => $results]);
