<?php
namespace App\Core;

/**
 * Session-based authentication and role gating.
 *
 * SECURITY NOTE: Role is read from the server-side session only — never
 * trusted from client input. Every API endpoint that returns reconciliation
 * data (expected quantities, differences) must call Auth::requireRole()
 * with 'admin' and/or 'control' — 'entry' users are never allowed through.
 */
class Auth
{
    private static function isSecureRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $scheme = $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? '';
        return (!empty($https) && strtolower($https) !== 'off')
            || strtolower((string)$proto) === 'https'
            || strtolower((string)$scheme) === 'https'
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = (int)Config::get('session_lifetime_minutes', 480) * 60;
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => self::isSecureRequest(),
            ]);
            session_name('inv_session');
            session_start();
        }
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $lifetime = (int)Config::get('session_lifetime_minutes', 480) * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $lifetime) {
            self::logout();
            return false;
        }
        // A deactivated user is logged out at once, and a role/name change by the
        // admin applies immediately instead of at the next login.
        $stmt = Database::connection()->prepare('SELECT username, full_name, role, active FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row || !(int)$row['active']) {
            self::logout();
            return false;
        }
        $_SESSION['username'] = $row['username'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
        ];
    }

    public static function requireAuth(): array
    {
        if (!self::check()) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/api/') === false) {
                header('Location: /');
                exit;
            }
            Response::error('Not authenticated. Please log in again.', 401);
        }
        return self::user();
    }

    /** @param string ...$roles One or more roles allowed to access this endpoint */
    public static function requireRole(string ...$roles): array
    {
        $user = self::requireAuth();
        if (!in_array($user['role'], $roles, true)) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/api/') === false) {
                header('Location: /');
                exit;
            }
            Response::error('You do not have permission to perform this action.', 403);
        }
        return $user;
    }
}
