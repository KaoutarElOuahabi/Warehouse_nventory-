<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;

Auth::requireRole('entry', 'control', 'admin');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    Response::ok(['results' => []]);
}

$results = AddressService::searchByCode($q, 12);
Response::ok(['results' => $results]);
