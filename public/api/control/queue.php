<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\ComparisonService;

Auth::requireRole('control', 'admin');

$addresses = AddressService::controlQueue();
$out = [];
foreach ($addresses as $addr) {
    $cmp = ComparisonService::compareAddress((int)$addr['id']);
    $out[] = [
        'code' => $addr['code'],
        'status' => $addr['status'],
        'issue_label' => ComparisonService::issueLabel($cmp),
        'issues' => $cmp['summary']['issues'],
        'updated_at' => $addr['updated_at'],
    ];
}

Response::ok(['count' => count($out), 'addresses' => $out]);
