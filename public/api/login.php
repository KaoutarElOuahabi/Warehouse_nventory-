<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$data = json_body();
require_fields($data, ['username', 'password']);

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u AND active = 1');
$stmt->execute([':u' => trim($data['username'])]);
$user = $stmt->fetch();

if (!$user || !password_verify($data['password'], $user['password_hash'])) {
    AuditService::log('user', $user['id'] ?? null, 'login_failed', null, null, null, 'username=' . $data['username']);
    Response::error('Invalid username or password.', 401);
}

Auth::login($user);
AuditService::log('user', (int)$user['id'], 'login', Auth::user());

Response::ok([
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ],
]);
