<?php
namespace App\Core;

class Config
{
    private static ?array $data = null;

    private static function envValue(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value !== null && $value !== '') ? $value : $default;
    }

    private static function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return [];
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'mysql'));
        $database = ltrim((string)($parts['path'] ?? ''), '/');

        return [
            'driver' => $scheme === 'sqlite' ? 'sqlite' : 'mysql',
            'host' => $parts['host'],
            'port' => (int)($parts['port'] ?? 3306),
            'database' => $database,
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
        ];
    }

    private static function buildRuntimeConfig(): array
    {
        $db = [
            'driver' => self::envValue('DB_DRIVER', self::envValue('DATABASE_DRIVER', 'mysql')),
            'host' => self::envValue('DB_HOST', self::envValue('MYSQLHOST', '127.0.0.1')),
            'port' => (int) self::envValue('DB_PORT', self::envValue('MYSQLPORT', 3306)),
            'database' => self::envValue('DB_NAME', self::envValue('MYSQL_DATABASE', self::envValue('DB_DATABASE', 'inventory_app'))),
            'username' => self::envValue('DB_USERNAME', self::envValue('MYSQL_USERNAME', 'root')),
            'password' => self::envValue('DB_PASSWORD', self::envValue('MYSQL_PASSWORD', '')),
            'sqlite_path' => self::envValue('DB_SQLITE_PATH', __DIR__ . '/../storage/database.sqlite'),
        ];

        $databaseUrl = self::envValue('DATABASE_URL', self::envValue('MYSQL_URL', ''));
        if ($databaseUrl !== '') {
            $parsed = self::parseDatabaseUrl($databaseUrl);
            if ($parsed !== []) {
                $db = array_merge($db, $parsed);
            }
        }

        return [
            'db' => $db,
            'app_key' => self::envValue('APP_KEY', self::envValue('APP_SECRET', 'change-me-in-production')),
            'app_name' => self::envValue('APP_NAME', 'Warehouse Inventory'),
            'session_lifetime_minutes' => (int) self::envValue('SESSION_LIFETIME_MINUTES', 480),
        ];
    }

    public static function hasRuntimeConfig(): bool
    {
        $databaseUrl = self::envValue('DATABASE_URL', self::envValue('MYSQL_URL', ''));
        $dbDriver = self::envValue('DB_DRIVER', self::envValue('DATABASE_DRIVER', ''));
        $dbHost = self::envValue('DB_HOST', self::envValue('MYSQLHOST', ''));
        return $databaseUrl !== '' || $dbDriver !== '' || $dbHost !== '';
    }

    private static function load(): array
    {
        if (self::$data === null) {
            $path = __DIR__ . '/../../config/config.php';
            $config = file_exists($path) ? require $path : [];

            if (!is_array($config) || $config === []) {
                $config = self::buildRuntimeConfig();
            } else {
                $runtime = self::buildRuntimeConfig();
                $config = array_replace_recursive($runtime, $config);
            }

            self::$data = $config;
        }
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        $data = self::load();
        return $data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::load();
    }
}
