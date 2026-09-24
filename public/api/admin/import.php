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
 * A row with only an Address registers an empty bin so it can be counted too.
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
$warnings = [];
$byHu = [];
$emptyBins = [];
$lineNo = 1;
foreach ($rows as $r) {
    $lineNo++; // account for header row = line 1
    if (!is_array($r)) {
        $errors[] = "Row $lineNo: unreadable row.";
        continue;
    }
    $address = trim((string)($r['address'] ?? ''));
    // SAP / labels write the same HU as 300…, 0300… or H300…: store one form so scans match.
    $hu = normalize_hu(trim((string)($r['hu'] ?? '')));
    $pn = trim((string)($r['part_number'] ?? ''));
    $unitRaw = trim((string)($r['unit'] ?? ''));
    $qtyRaw = $r['quantity'] ?? null;
    $qtyBlank = $qtyRaw === null || (is_string($qtyRaw) && trim($qtyRaw) === '');

    if (is_hu_format($address)) {
        $errors[] = "Row $lineNo: Address \"$address\" looks like an HU number — check the column order in the file.";
        continue;
    }
    if ($hu === '' && $pn === '' && $unitRaw === '' && $qtyBlank) {
        if ($address !== '') {
            $emptyBins[$address] = true;
        }
        continue;
    }
    if ($address === '' || $hu === '' || $pn === '' || $unitRaw === '') {
        $errors[] = "Row $lineNo: missing required value(s) (Address, HU, Part Number, Unit are required).";
        continue;
    }
    $unit = UnitService::normalize($unitRaw);
    $qtyValidation = UnitService::validateQuantity($qtyBlank ? 0 : $qtyRaw, $unit);
    if (!$qtyValidation['valid']) {
        $errors[] = "Row $lineNo (HU $hu): {$qtyValidation['error']}";
        continue;
    }
    if (!is_hu_format($hu)) {
        $warnings[] = "Row $lineNo: HU \"$hu\" is not a valid HU (300 + 6 digits or 1000 + 6 digits) — it cannot be scanned in data entry.";
    }

    $row = ['address' => $address, 'hu' => $hu, 'part_number' => $pn, 'unit' => $unit, 'quantity' => $qtyValidation['value']];
    if (isset($byHu[$hu])) {
        $prev = $byHu[$hu];
        if ($prev['address'] !== $address) {
            $warnings[] = "Row $lineNo: HU \"$hu\" is listed at {$prev['address']} and at $address — only $address (last row) is kept.";
            $byHu[$hu] = $row;
        } elseif (strcasecmp($prev['part_number'], $pn) !== 0) {
            $warnings[] = "Row $lineNo: HU \"$hu\" contains several part numbers ({$prev['part_number']}, $pn). Mixed HUs are not supported — only $pn (last row) is kept.";
            $byHu[$hu] = $row;
        } else {
            $byHu[$hu]['quantity'] = round($prev['quantity'] + $row['quantity'], 4);
            $warnings[] = "Row $lineNo: HU \"$hu\" / $pn appears more than once at $address — quantities added up to {$byHu[$hu]['quantity']} $unit.";
        }
        continue;
    }
    $byHu[$hu] = $row;
}
$clean = array_values($byHu);
foreach ($clean as $row) {
    unset($emptyBins[$row['address']]);
}
$warningCount = count($warnings);
if ($warningCount > 100) {
    $warnings = array_slice($warnings, 0, 100);
    $warnings[] = '… and ' . ($warningCount - 100) . ' more.';
}

if ($mode === 'preview') {
    Response::ok([
        'valid_rows' => count($clean),
        'empty_bins' => count($emptyBins),
        'error_count' => count($errors),
        'errors' => array_slice($errors, 0, 100),
        'warnings' => $warnings,
        'sample' => array_slice($clean, 0, 10),
    ]);
}

if (count($clean) === 0 && count($emptyBins) === 0) {
    Response::error('No valid rows to import.', 422, ['errors' => array_slice($errors, 0, 100)]);
}

try {
    $pdo = Database::connection();
    $pdo->beginTransaction();

    // The new file fully replaces the previous stock: expected stock, part master and
    // the address list. Only the latest file is valid for data entry.
    $pdo->exec('DELETE FROM expected_stock');
    $pdo->exec('DELETE FROM part_master');

    // Drop addresses that are not in the new file, except bins that already hold
    // live counts — deleting those would silently wipe counting work (counts cascade).
    $newCodes = array_fill_keys(array_merge(array_column($clean, 'address'), array_keys($emptyBins)), true);
    $removeIds = [];
    $keptWithCounts = 0;
    $existing = $pdo->query(
        'SELECT a.id, a.code,
                (SELECT COUNT(*) FROM physical_counts pc WHERE pc.address_id = a.id AND pc.is_deleted = 0) AS live_counts
         FROM addresses a'
    )->fetchAll();
    foreach ($existing as $a) {
        if (isset($newCodes[$a['code']])) {
            continue;
        }
        if ((int)$a['live_counts'] > 0) {
            $keptWithCounts++;
            continue;
        }
        $removeIds[] = (int)$a['id'];
    }
    foreach (array_chunk($removeIds, 500) as $chunk) {
        $pdo->exec('DELETE FROM physical_counts WHERE address_id IN (' . implode(',', $chunk) . ')');
        $pdo->exec('DELETE FROM addresses WHERE id IN (' . implode(',', $chunk) . ')');
    }

    // Keep the historical batch record but leave only the latest import as the active snapshot.
    $pdo->exec("UPDATE import_batches SET is_active = 0 WHERE is_active = 1");

    $stmt = $pdo->prepare(
        'INSERT INTO import_batches (filename, row_count, imported_by, is_active, imported_at) VALUES (:fn, :rc, :uid, 1, :now)'
    );
    $stmt->execute([':fn' => $filename, ':rc' => count($clean), ':uid' => $user['id'], ':now' => Database::now()]);
    $batchId = (int)Database::lastInsertId();

    // Batched writes: one round trip per chunk instead of several per row, so a
    // file with thousands of HUs imports in seconds.
    $now = Database::now();
    $chunkSize = Database::driver() === 'sqlite' ? 100 : 500;

    $pdo->exec('UPDATE addresses SET known_in_stock = 0');
    $addressIds = [];
    foreach ($pdo->query('SELECT id, code FROM addresses')->fetchAll() as $a) {
        $addressIds[$a['code']] = (int)$a['id'];
    }
    $fileCodes = array_keys($newCodes);
    $missing = array_values(array_filter($fileCodes, static fn($c) => !isset($addressIds[$c])));
    foreach (array_chunk($missing, $chunkSize) as $chunk) {
        $stmt = $pdo->prepare(
            'INSERT INTO addresses (code, status, known_in_stock, created_at, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), "(?, 'NOT_STARTED', 1, ?, ?)"))
        );
        $params = [];
        foreach ($chunk as $code) {
            array_push($params, $code, $now, $now);
        }
        $stmt->execute($params);
    }
    if ($missing) {
        foreach ($pdo->query('SELECT id, code FROM addresses')->fetchAll() as $a) {
            $addressIds[$a['code']] = (int)$a['id'];
        }
    }
    $fileIds = array_map(static fn($c) => $addressIds[$c], $fileCodes);
    foreach (array_chunk($fileIds, $chunkSize) as $chunk) {
        $pdo->exec('UPDATE addresses SET known_in_stock = 1 WHERE id IN (' . implode(',', $chunk) . ')');
    }

    foreach (array_chunk($clean, $chunkSize) as $chunk) {
        $stmt = $pdo->prepare(
            'INSERT INTO expected_stock (batch_id, address_id, address_code, hu, part_number, unit, quantity, created_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'))
        );
        $params = [];
        foreach ($chunk as $row) {
            array_push($params, $batchId, $addressIds[$row['address']], $row['address'], $row['hu'],
                $row['part_number'], $row['unit'], $row['quantity'], $now);
        }
        $stmt->execute($params);
    }

    // part_master was emptied above: one row per part number (last unit in the file wins).
    $parts = [];
    foreach ($clean as $row) {
        $parts[$row['part_number']] = $row['unit'];
    }
    foreach (array_chunk($parts, $chunkSize, true) as $chunk) {
        $stmt = $pdo->prepare(
            'INSERT INTO part_master (part_number, unit, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?, ?, ?)'))
        );
        $params = [];
        foreach ($chunk as $pn => $unit) {
            array_push($params, (string)$pn, $unit, $now);
        }
        $stmt->execute($params);
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
    'filename' => $filename, 'row_count' => count($clean), 'empty_bins' => count($emptyBins), 'error_count' => count($errors),
    'addresses_removed' => count($removeIds), 'addresses_kept_with_counts' => $keptWithCounts,
], "Imported {$filename} — " . count($clean) . ' rows, ' . count($emptyBins) . ' empty bins, '
    . count($removeIds) . ' old addresses removed');

Response::ok([
    'batch_id' => $batchId,
    'imported_rows' => count($clean),
    'empty_bins' => count($emptyBins),
    'addresses_removed' => count($removeIds),
    'addresses_kept_with_counts' => $keptWithCounts,
    'error_count' => count($errors),
    'errors' => array_slice($errors, 0, 100),
]);
