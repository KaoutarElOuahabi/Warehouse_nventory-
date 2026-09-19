<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AddressService;
use App\Services\ComparisonService;

Auth::requireRole('admin', 'control');

$addresses = AddressService::listAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory_results_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['Address', 'Address Status', 'HU', 'Line Status', 'Expected PN', 'Expected Unit', 'Expected Qty', 'Physical PN', 'Physical Unit', 'Physical Qty']);

foreach ($addresses as $addr) {
    $cmp = ComparisonService::compareAddress((int)$addr['id']);
    if (empty($cmp['lines'])) {
        fputcsv($out, [$addr['code'], $addr['status'], '', '', '', '', '', '', '', '']);
        continue;
    }
    foreach ($cmp['lines'] as $line) {
        fputcsv($out, [
            $addr['code'], $addr['status'], $line['hu'] ?? '(HU not available)', $line['status'],
            $line['expected_part_number'], $line['expected_unit'], $line['expected_quantity'],
            $line['physical_part_number'], $line['physical_unit'], $line['physical_quantity'],
        ]);
    }
}
fclose($out);
exit;
