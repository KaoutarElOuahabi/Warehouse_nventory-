<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AddressService;

Auth::requireRole('admin');

$pdo = Database::connection();
$counts = AddressService::dashboardCounts();

$batch = $pdo->query("SELECT * FROM import_batches WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
$totalAddresses = array_sum($counts);
$huCount = (int)$pdo->query('SELECT COUNT(*) c FROM expected_stock es INNER JOIN import_batches b ON b.id=es.batch_id AND b.is_active=1')->fetch()['c'];
$physicalCount = (int)$pdo->query('SELECT COUNT(*) c FROM physical_counts WHERE is_deleted = 0')->fetch()['c'];
$userCount = (int)$pdo->query('SELECT COUNT(*) c FROM users WHERE active = 1')->fetch()['c'];

Response::ok([
    'address_counts' => $counts,
    'total_addresses' => $totalAddresses,
    'active_batch' => $batch ?: null,
    'expected_hu_count' => $huCount,
    'physical_count_count' => $physicalCount,
    'active_user_count' => $userCount,
]);
