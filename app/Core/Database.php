<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = Config::get('db');
        self::$driver = $config['driver'];

        try {
            if ($config['driver'] === 'sqlite') {
                $dsn = 'sqlite:' . $config['sqlite_path'];
                $pdo = new PDO($dsn);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['host'],
                    $config['port'] ?? 3306,
                    $config['database']
                );
                $pdo = new PDO($dsn, $config['username'], $config['password']);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed. Please check config/config.php.']));
        }

        self::$instance = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    /** Returns the correct "now" SQL and helps format datetimes consistently. */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function lastInsertId(): string
    {
        return self::$instance->lastInsertId();
    }
}
