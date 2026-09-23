<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;
use App\Services\ComparisonService;
use App\Services\StockLookupService;

/** Full reconciliation as JSON; admin.js turns it into the SAP-correction workbook. */

Auth::requireRole('admin', 'control');

try {
    $names = [];
    foreach (Database::connection()->query('SELECT id, full_name FROM users')->fetchAll() as $u) {
        $names[(int)$u['id']] = $u['full_name'];
    }
    $name = static fn($id) => $id ? ($names[(int)$id] ?? '') : '';

    $addresses = [];
    $lines = [];
    foreach (AddressService::listAll() as $addr) {
        $cmp = ComparisonService::compareAddress((int)$addr['id']);
        $addresses[] = [
            'code' => $addr['code'],
            'status' => $addr['status'],
            'sap_hus' => count(StockLookupService::expectedForAddress((int)$addr['id'])),
            'counted_lines' => count(array_filter($cmp['lines'], static fn($l) => $l['physical_id'] !== null)),
            'issues' => $cmp['summary']['issues'],
            'completed_by' => $name($addr['completed_by']),
            'completed_at' => $addr['completed_at'],
            'controlled_by' => $name($addr['controlled_by']),
            'controlled_at' => $addr['controlled_at'],
            'control_observations' => $addr['control_observations'],
        ];
        foreach ($cmp['lines'] as $l) {
            $l['address'] = $addr['code'];
            $l['address_status'] = $addr['status'];
            $l['counted_by'] = $name($l['entered_by']);
            $lines[] = $l;
        }
    }

    Response::ok(['generated_at' => Database::now(), 'addresses' => $addresses, 'lines' => $lines]);
} catch (\Throwable $e) {
    error_log('[inventory-app] results export failed: ' . $e->getMessage());
    Response::error('Could not build the results export.', 500);
}
