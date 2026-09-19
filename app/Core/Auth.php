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
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = (int)Config::get('session_lifetime_minutes', 480) * 60;
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
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
            Response::error('Not authenticated. Please log in again.', 401);
        }
        return self::user();
    }

    /** @param string ...$roles One or more roles allowed to access this endpoint */
    public static function requireRole(string ...$roles): array
    {
        $user = self::requireAuth();
        if (!in_array($user['role'], $roles, true)) {
            Response::error('You do not have permission to perform this action.', 403);
        }
        return $user;
    }
}
