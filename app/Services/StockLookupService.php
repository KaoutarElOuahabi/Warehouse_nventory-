<?php
namespace App\Services;

use App\Core\Database;

/**
 * Read-only lookups against the currently active imported stock.
 * IMPORTANT: methods here must never be exposed wholesale to 'entry' role
 * responses — only the specific fields needed (part_number, unit), never
 * quantity. Controllers are responsible for stripping quantity before
 * returning to 'entry' users.
 */
class StockLookupService
{
    /** Find an HU anywhere in the active imported stock (address-independent scan). */
    public static function findByHu(string $hu): ?array
    {
        $forms = hu_forms($hu);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT es.* FROM expected_stock es
             INNER JOIN import_batches b ON b.id = es.batch_id AND b.is_active = 1
             WHERE es.hu IN (' . hu_placeholders($forms) . ')
             ORDER BY es.id DESC LIMIT 1'
        );
        $stmt->execute($forms);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findPartByNumber(string $pn): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT part_number, unit FROM part_master WHERE part_number = :pn LIMIT 1');
        $stmt->execute([':pn' => $pn]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $stmt = $pdo->prepare(
            'SELECT es.part_number, es.unit FROM expected_stock es
             INNER JOIN import_batches b ON b.id = es.batch_id AND b.is_active = 1
             WHERE es.part_number = :pn ORDER BY es.id DESC LIMIT 1'
        );
        $stmt->execute([':pn' => $pn]);
        return $stmt->fetch() ?: null;
    }

    public static function unitForPartNumber(string $pn): ?string
    {
        $row = self::findPartByNumber($pn);
        return $row['unit'] ?? null;
    }

    public static function searchPartNumbers(string $query, int $limit = 15): array
    {
        $pdo = Database::connection();
        $like = '%' . $query . '%';
        $stmt = $pdo->prepare(
            'SELECT part_number, unit FROM part_master WHERE part_number LIKE :q ORDER BY part_number LIMIT :lim'
        );
        $stmt->bindValue(':q', $like);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function expectedForAddress(int $addressId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT es.* FROM expected_stock es
             INNER JOIN import_batches b ON b.id = es.batch_id AND b.is_active = 1
             WHERE es.address_id = :aid'
        );
        $stmt->execute([':aid' => $addressId]);
        return $stmt->fetchAll();
    }
}
