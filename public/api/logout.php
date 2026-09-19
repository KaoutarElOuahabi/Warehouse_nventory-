<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AuditService;

$user = Auth::user();
if ($user) {
    AuditService::log('user', (int)$user['id'], 'logout', $user);
}
Auth::logout();
Response::ok();
