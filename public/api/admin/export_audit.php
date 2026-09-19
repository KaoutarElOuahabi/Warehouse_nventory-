<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AuditService;

Auth::requireRole('admin');

$rows = AuditService::list([], 5000, 0);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_trail_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['Date/Time', 'Entity Type', 'Entity ID', 'Action', 'Actor', 'Before', 'After', 'Notes']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['created_at'], $r['entity_type'], $r['entity_id'], $r['action'], $r['actor_name'],
        $r['before_json'], $r['after_json'], $r['notes'],
    ]);
}
fclose($out);
exit;
