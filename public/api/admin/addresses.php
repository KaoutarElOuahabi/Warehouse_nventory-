<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

Auth::requireRole('admin');

/**
 * Address list with what each bin holds: SAP stock from the active import
 * (HUs, part numbers) and the physical lines counted so far.
 * One aggregate query, so it stays fast for thousands of addresses.
 */

$status = trim((string)($_GET['status'] ?? ''));

$sql = "SELECT a.id, a.code, a.status, a.known_in_stock,
               COALESCE(es.sap_hus, 0) AS sap_hus,
               COALESCE(es.sap_pns, 0) AS sap_pns,
               COALESCE(pc.counted_lines, 0) AS counted_lines
        FROM addresses a
        LEFT JOIN (
            SELECT e.address_id, COUNT(*) AS sap_hus, COUNT(DISTINCT e.part_number) AS sap_pns
            FROM expected_stock e
            INNER JOIN import_batches b ON b.id = e.batch_id AND b.is_active = 1
            GROUP BY e.address_id
        ) es ON es.address_id = a.id
        LEFT JOIN (
            SELECT address_id, COUNT(*) AS counted_lines
            FROM physical_counts WHERE is_deleted = 0
            GROUP BY address_id
        ) pc ON pc.address_id = a.id";
$params = [];
if ($status !== '') {
    $sql .= ' WHERE a.status = :status';
    $params[':status'] = $status;
}
$sql .= ' ORDER BY a.code ASC';

$stmt = Database::connection()->prepare($sql);
$stmt->execute($params);
$addresses = array_map(static function ($r) {
    return [
        'code' => $r['code'],
        'status' => $r['status'],
        'in_stock_file' => (int)$r['known_in_stock'] === 1 || (int)$r['sap_hus'] > 0,
        'sap_hus' => (int)$r['sap_hus'],
        'sap_pns' => (int)$r['sap_pns'],
        'counted_lines' => (int)$r['counted_lines'],
    ];
}, $stmt->fetchAll());

Response::ok(['addresses' => $addresses]);
