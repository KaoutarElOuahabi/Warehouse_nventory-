<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\ComparisonService;
use App\Services\AuditService;

Auth::requireRole('admin');

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') Response::error('code is required', 422);

$address = AddressService::findByCode($code);
if (!$address) Response::error('Address not found.', 404);

$cmp = ComparisonService::compareAddress((int)$address['id']);
$history = AuditService::list(['entity_type' => 'address', 'entity_id' => $address['id']], 100);

Response::ok(['address' => $address, 'lines' => $cmp['lines'], 'summary' => $cmp['summary'], 'history' => $history]);
