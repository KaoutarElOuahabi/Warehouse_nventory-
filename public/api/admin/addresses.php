<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AddressService;

Auth::requireRole('admin');

$status = trim((string)($_GET['status'] ?? ''));
$addresses = AddressService::listAll($status !== '' ? $status : null);

Response::ok(['addresses' => $addresses]);
