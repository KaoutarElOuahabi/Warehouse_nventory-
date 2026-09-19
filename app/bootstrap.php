<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors to API responses

require_once __DIR__ . '/Core/Config.php';
require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/Auth.php';
require_once __DIR__ . '/Core/Response.php';
require_once __DIR__ . '/Services/AuditService.php';
require_once __DIR__ . '/Services/UnitService.php';
require_once __DIR__ . '/Services/StockLookupService.php';
require_once __DIR__ . '/Services/AddressService.php';
require_once __DIR__ . '/Services/ComparisonService.php';

use App\Core\Auth;
use App\Core\Response;

Auth::start();

set_exception_handler(function ($e) {
    error_log('[inventory-app] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('An unexpected server error occurred.', 500);
});

/** Reads and decodes a JSON request body; returns [] if empty/invalid. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_fields(array $data, array $fields): void
{
    foreach ($fields as $f) {
        if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) {
            Response::error("Missing required field: $f", 422);
        }
    }
}
