<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

/**
 * Addresses the current user worked on today (lines recorded or address
 * completed), for quick re-checking / reopening. No SAP quantities.
 */

$user = Auth::requireRole('entry', 'control', 'admin');

try {
    $today = date('Y-m-d 00:00:00');
    $stmt = Database::connection()->prepare(
        'SELECT a.id, a.code, a.status, a.completed_at, a.completed_by,
                COUNT(pc.id) AS my_lines, MAX(pc.entered_at) AS last_entry
         FROM addresses a
         LEFT JOIN physical_counts pc
           ON pc.address_id = a.id AND pc.entered_by = :uid1 AND pc.is_deleted = 0 AND pc.entered_at >= :today1
         GROUP BY a.id, a.code, a.status, a.completed_at, a.completed_by
         HAVING COUNT(pc.id) > 0 OR (a.completed_by = :uid2 AND a.completed_at >= :today2)'
    );
    $stmt->execute([':uid1' => $user['id'], ':today1' => $today, ':uid2' => $user['id'], ':today2' => $today]);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $completedByMe = (int)$r['completed_by'] === (int)$user['id'] ? (string)$r['completed_at'] : '';
        $rows[] = [
            'code' => $r['code'],
            'status' => $r['status'],
            'my_lines' => (int)$r['my_lines'],
            'last_activity' => max((string)$r['last_entry'], $completedByMe),
        ];
    }
    // Unfinished first (reminder), then most recent activity first.
    usort($rows, static function ($a, $b) {
        $ao = $a['status'] === 'IN_PROGRESS' ? 0 : 1;
        $bo = $b['status'] === 'IN_PROGRESS' ? 0 : 1;
        return $ao <=> $bo ?: strcmp($b['last_activity'], $a['last_activity']);
    });

    Response::ok(['addresses' => $rows]);
} catch (\Throwable $e) {
    error_log('[inventory-app] my_addresses failed: ' . $e->getMessage());
    Response::error('Could not load your addresses of today.', 500);
}
