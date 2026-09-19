<?php
namespace App\Services;

use App\Core\Database;

class AuditService
{
    public static function log(
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $actor,
        $before = null,
        $after = null,
        ?string $notes = null
    ): void {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (entity_type, entity_id, action, actor_id, actor_name, before_json, after_json, notes, created_at)
             VALUES (:entity_type, :entity_id, :action, :actor_id, :actor_name, :before_json, :after_json, :notes, :created_at)'
        );
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':action' => $action,
            ':actor_id' => $actor['id'] ?? null,
            ':actor_name' => $actor['full_name'] ?? ($actor['username'] ?? 'system'),
            ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            ':notes' => $notes,
            ':created_at' => Database::now(),
        ]);
    }

    public static function list(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :entity_type';
            $params[':entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = :entity_id';
            $params[':entity_id'] = $filters['entity_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filters['to'];
        }
        $sql = 'SELECT * FROM audit_log';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
