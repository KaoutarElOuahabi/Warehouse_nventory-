<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;

/**
 * Starts a fresh counting cycle: soft-deletes all physical counts and resets
 * every address to NOT_STARTED. Imported stock (expected_stock) is untouched.
 * Requires the admin to type the confirmation phrase to avoid accidental wipes.
 */

$user = Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
if (trim((string)($data['confirm'] ?? '')) !== 'RESET') {
    Response::error('Type RESET to confirm starting a new counting cycle.', 422);
}

try {
    $pdo = Database::connection();
    $pdo->beginTransaction();
    $now = Database::now();
    $stmt = $pdo->prepare(
        "UPDATE physical_counts SET is_deleted = 1, deleted_by = :uid, deleted_at = :now WHERE is_deleted = 0"
    );
    $stmt->execute([':uid' => $user['id'], ':now' => $now]);
    $affected = $stmt->rowCount();

    $pdo->exec(
        "UPDATE addresses SET status = 'NOT_STARTED', completed_by = NULL, completed_at = NULL,
         controlled_by = NULL, controlled_at = NULL, control_observations = NULL, updated_at = " .
        ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "'$now'" : "'$now'")
    );
    $pdo->commit();
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[inventory-app] reset_cycle failed: ' . $e->getMessage());
    Response::error('Reset failed. No changes were saved.', 500);
}

AuditService::log('system', null, 'cycle_reset', $user, null, ['physical_counts_cleared' => $affected], 'New counting cycle started by admin');

Response::ok(['physical_counts_cleared' => $affected]);
