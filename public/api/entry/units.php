<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\UnitService;

Auth::requireRole('entry', 'control', 'admin');

$pdo = Database::connection();
$stmt = $pdo->query("SELECT DISTINCT unit FROM part_master WHERE unit IS NOT NULL AND unit <> '' ORDER BY unit");
$units = array_column($stmt->fetchAll(), 'unit');

Response::ok(['units' => $units, 'decimal_units' => UnitService::decimalUnits()]);
