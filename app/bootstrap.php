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

/**
 * Handling Units: 300 + 6 digits (e.g. 300660525) or 1000 + 6 digits (e.g. 1000058164).
 * Labels/SAP may add an "H" prefix or a leading 0 (H300660525, 0300660525): those are the
 * same HU and are stored in the short form. Mirrored in entry.js (normalizeHu).
 */
const HU_PATTERN = '/^(300\d{6}|1000\d{6})$/';

function normalize_hu(string $value): string
{
    $v = strtoupper(preg_replace('/\s+/', '', $value));
    if (strpos($v, 'H') === 0) {
        $v = substr($v, 1);
    }
    if (preg_match('/^0300\d{6}$/', $v)) {
        $v = substr($v, 1);
    }
    return $v;
}

/**
 * Every way the same HU can be written (300…, 0300…, H300…, H0300…), for SQL
 * "hu IN (...)" lookups, so rows saved in any form still match.
 */
function hu_forms(string $hu): array
{
    $short = normalize_hu($hu);
    $forms = [$short, 'H' . $short];
    if (strpos($short, '300') === 0) {
        array_push($forms, '0' . $short, 'H0' . $short);
    }
    return array_values(array_unique($forms));
}

/** "?,?,?" placeholders for hu_forms(). */
function hu_placeholders(array $forms): string
{
    return implode(',', array_fill(0, count($forms), '?'));
}

function is_hu_format(string $value): bool
{
    return (bool)preg_match(HU_PATTERN, normalize_hu($value));
}

/** Returns the HU in its stored (short) form, or stops the request with a clear error. */
function validate_hu_format(string $hu): string
{
    if (!is_hu_format($hu)) {
        Response::error("\"$hu\" is not a Handling Unit. An HU is 300 + 6 digits (e.g. 300660525, also H300660525 or 0300660525) or 1000 + 6 digits.", 422);
    }
    return normalize_hu($hu);
}
