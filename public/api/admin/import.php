<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\AuditService;
use App\Services\UnitService;

/**
 * Expects JSON: { filename: string, rows: [{address, hu, part_number, unit, quantity}, ...], mode: 'preview'|'commit' }
 * The browser parses the uploaded Excel/CSV with SheetJS and posts rows here as JSON;
 * this endpoint re-validates everything server-side (never trusts the client parse).
 */

$user = Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['rows']);
$rows = $data['rows'];
$filename = trim((string)($data['filename'] ?? 'import.xlsx'));
$mode = ($data['mode'] ?? 'commit') === 'preview' ? 'preview' : 'commit';

if (!is_array($rows) || count($rows) === 0) {
    Response::error('No rows found in the uploaded file.', 422);
}
if (count($rows) > 200000) {
    Response::error('File is too large (max 200,000 rows).', 422);
}

$errors = [];
$clean = [];
$lineNo = 1;
foreach ($rows as $r) {
    $lineNo++; // account for header row = line 1
    $address = trim((string)($r['address'] ?? ''));
    $hu = trim((string)($r['hu'] ?? ''));
    $pn = trim((string)($r['part_number'] ?? ''));
    $unitRaw = trim((string)($r['unit'] ?? ''));
    $qtyRaw = $r['quantity'] ?? null;

    if ($address === '' || $hu === '' || $pn === '' || $unitRaw === '' || $qtyRaw === null || $qtyRaw === '') {
        $errors[] = "Row $lineNo: missing required value(s) (Address, HU, Part Number, Unit, Quantity are all required).";
        continue;
    }
    if (!is_numeric($qtyRaw)) {
        $errors[] = "Row $lineNo: Quantity \"$qtyRaw\" is not a number.";
        continue;
    }
    $unit = UnitService::normalize($unitRaw);
    $qtyValidation = UnitService::validateQuantity($qtyRaw, $unit);
    if (!$qtyValidation['valid']) {
        $errors[] = "Row $lineNo: $qtyValidation[error]";
        continue;
    }

    $clean[] = [
        'address' => $address,
        'hu' => $hu,
        'part_number' => $pn,
        'unit' => $unit,
        'quantity' => $qtyValidation['value'],
    ];
}

// Duplicate HU within the same import is a data problem worth flagging but not fatal —
// last occurrence wins, matching typical spreadsheet corrections.
$huSeen = [];
foreach ($clean as $i => $row) {
    if (isset($huSeen[$row['hu']])) {
        $errors[] = "Note: HU \"{$row['hu']}\" appears more than once in the file; the last row wins.";
    }
    $huSeen[$row['hu']] = $i;
}

if ($mode === 'preview') {
    Response::ok([
        'valid_rows' => count($clean),
        'error_count' => count($errors),
        'errors' => array_slice($errors, 0, 100),
        'sample' => array_slice($clean, 0, 10),
    ]);
}

if (count($clean) === 0) {
    Response::error('No valid rows to import.', 422, ['errors' => array_slice($errors, 0, 100)]);
}

try {
    $pdo = Database::connection();
    $pdo->beginTransaction();

    // Deactivate previous batch(es) — only one active stock snapshot at a time.
    $pdo->exec("UPDATE import_batches SET is_active = 0 WHERE is_active = 1");

    $stmt = $pdo->prepare(
        'INSERT INTO import_batches (filename, row_count, imported_by, is_active, imported_at) VALUES (:fn, :rc, :uid, 1, :now)'
    );
    $stmt->execute([':fn' => $filename, ':rc' => count($clean), ':uid' => $user['id'], ':now' => Database::now()]);
    $batchId = (int)Database::lastInsertId();

    $insertExpected = $pdo->prepare(
        'INSERT INTO expected_stock (batch_id, address_id, address_code, hu, part_number, unit, quantity, created_at)
         VALUES (:batch_id, :address_id, :address_code, :hu, :pn, :unit, :qty, :now)'
    );
    $upsertPartMysql = 'INSERT INTO part_master (part_number, unit, updated_at) VALUES (:pn, :unit, :now)
         ON DUPLICATE KEY UPDATE unit = VALUES(unit), updated_at = VALUES(updated_at)';
    $upsertPartSqlite = 'INSERT INTO part_master (part_number, unit, updated_at) VALUES (:pn, :unit, :now)
         ON CONFLICT(part_number) DO UPDATE SET unit = excluded.unit, updated_at = excluded.updated_at';
    $upsertPart = $pdo->prepare(Database::driver() === 'sqlite' ? $upsertPartSqlite : $upsertPartMysql);

    $addressCache = [];
    $now = Database::now();
    foreach ($clean as $row) {
        if (!isset($addressCache[$row['address']])) {
            $addr = AddressService::getOrCreate($row['address']);
            AddressService::setStatus((int)$addr['id'], $addr['status'], ['known_in_stock' => 1]);
            $addressCache[$row['address']] = (int)$addr['id'];
        }
        $insertExpected->execute([
            ':batch_id' => $batchId,
            ':address_id' => $addressCache[$row['address']],
            ':address_code' => $row['address'],
            ':hu' => $row['hu'],
            ':pn' => $row['part_number'],
            ':unit' => $row['unit'],
            ':qty' => $row['quantity'],
            ':now' => $now,
        ]);
        $upsertPart->execute([':pn' => $row['part_number'], ':unit' => $row['unit'], ':now' => $now]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[inventory-app] import failed: ' . $e->getMessage());
    Response::error('Import failed while writing to the database. No changes were saved.', 500);
}

AuditService::log('import_batch', $batchId, 'import_committed', $user, null, [
    'filename' => $filename, 'row_count' => count($clean), 'error_count' => count($errors),
], "Imported {$filename} — " . count($clean) . ' rows');

Response::ok([
    'batch_id' => $batchId,
    'imported_rows' => count($clean),
    'error_count' => count($errors),
    'errors' => array_slice($errors, 0, 100),
]);
