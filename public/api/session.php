<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;

$user = Auth::user();
if (!$user) {
    Response::json(['authenticated' => false]);
}
Response::json(['authenticated' => true, 'user' => $user]);
