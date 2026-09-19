<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;

$user = Auth::requireRole('admin');
$pdo = Database::connection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, username, full_name, role, active, created_at FROM users ORDER BY username ASC');
    Response::ok(['users' => $stmt->fetchAll()]);
}

$data = json_body();
$action = $data['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    require_fields($data, ['username', 'password', 'full_name', 'role']);
    $username = trim((string)$data['username']);
    $role = trim((string)$data['role']);
    if (!in_array($role, ['admin', 'control', 'entry'], true)) {
        Response::error('Invalid role.', 422);
    }
    if (strlen((string)$data['password']) < 6) {
        Response::error('Password must be at least 6 characters.', 422);
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $stmt->execute([':u' => $username]);
    if ($stmt->fetch()) {
        Response::error('That username already exists.', 422);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, active, created_at, updated_at)
         VALUES (:u, :p, :fn, :r, 1, :now, :now)'
    );
    $now = Database::now();
    $stmt->execute([
        ':u' => $username, ':p' => password_hash($data['password'], PASSWORD_DEFAULT),
        ':fn' => trim((string)$data['full_name']), ':r' => $role, ':now' => $now,
    ]);
    $newId = (int)Database::lastInsertId();
    AuditService::log('user', $newId, 'user_created', $user, null, ['username' => $username, 'role' => $role]);
    Response::ok(['id' => $newId]);
}

if ($method === 'POST' && $action === 'update') {
    require_fields($data, ['id']);
    $id = (int)$data['id'];
    $fields = [];
    $params = [':id' => $id];
    if (isset($data['full_name'])) { $fields[] = 'full_name = :fn'; $params[':fn'] = trim((string)$data['full_name']); }
    if (isset($data['role']) && in_array($data['role'], ['admin', 'control', 'entry'], true)) {
        $fields[] = 'role = :r'; $params[':r'] = $data['role'];
    }
    if (isset($data['active'])) { $fields[] = 'active = :a'; $params[':a'] = $data['active'] ? 1 : 0; }
    if (!empty($data['password'])) {
        if (strlen((string)$data['password']) < 6) Response::error('Password must be at least 6 characters.', 422);
        $fields[] = 'password_hash = :ph'; $params[':ph'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    if (!$fields) Response::error('Nothing to update.', 422);
    $fields[] = 'updated_at = :now'; $params[':now'] = Database::now();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();
    if (!$before) Response::error('User not found.', 404);

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    AuditService::log('user', $id, 'user_updated', $user, ['role' => $before['role'], 'active' => $before['active']], $data);
    Response::ok();
}

Response::error('Unsupported request.', 405);
