<?php
namespace App\Services;

use App\Core\Database;

class AddressService
{
    public static function findByCode(string $code): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM addresses WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Get an address row by code, creating it (status NOT_STARTED) if it doesn't exist yet. */
    public static function getOrCreate(string $code): array
    {
        $existing = self::findByCode($code);
        if ($existing) {
            return $existing;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO addresses (code, status, known_in_stock, created_at, updated_at)
             VALUES (:code, :status, 0, :now, :now)'
        );
        $now = Database::now();
        $stmt->execute([':code' => $code, ':status' => 'NOT_STARTED', ':now' => $now]);
        return self::findByCode($code);
    }

    public static function markInProgress(int $addressId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE addresses SET status = 'IN_PROGRESS', updated_at = :now
             WHERE id = :id AND status = 'NOT_STARTED'"
        );
        $stmt->execute([':id' => $addressId, ':now' => Database::now()]);
    }

    public static function setStatus(int $addressId, string $status, array $extra = []): void
    {
        $pdo = Database::connection();
        $fields = ['status = :status', 'updated_at = :now'];
        $params = [':status' => $status, ':now' => Database::now(), ':id' => $addressId];
        foreach ($extra as $col => $val) {
            $fields[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        $sql = 'UPDATE addresses SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public static function listAll(?string $statusFilter = null): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM addresses';
        $params = [];
        if ($statusFilter) {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $statusFilter;
        }
        $sql .= ' ORDER BY code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function controlQueue(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            "SELECT * FROM addresses WHERE status IN ('COMPLETED_CONTROL_REQUIRED','CONTROL_IN_PROGRESS')
             ORDER BY updated_at ASC"
        );
        return $stmt->fetchAll();
    }

    public static function dashboardCounts(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM addresses GROUP BY status');
        $rows = $stmt->fetchAll();
        $counts = [
            'NOT_STARTED' => 0, 'IN_PROGRESS' => 0, 'COMPLETED_OK' => 0,
            'COMPLETED_CONTROL_REQUIRED' => 0, 'CONTROL_IN_PROGRESS' => 0, 'CONTROLLED' => 0,
        ];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int)$r['cnt'];
        }
        return $counts;
    }
}
