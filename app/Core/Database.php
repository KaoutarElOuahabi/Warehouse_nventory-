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
        self::$driver = strtolower((string)($config['driver'] ?? 'mysql'));

        try {
            if (self::$driver === 'sqlite') {
                $dsn = 'sqlite:' . ($config['sqlite_path'] ?? __DIR__ . '/../storage/database.sqlite');
                $pdo = new PDO($dsn);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $host = $config['host'] ?? '127.0.0.1';
                $port = (int)($config['port'] ?? 3306);
                $database = $config['database'] ?? '';
                $username = $config['username'] ?? '';
                $password = $config['password'] ?? '';

                if ($database === '') {
                    throw new PDOException('Database name is missing. Check DB_NAME or DATABASE_URL.');
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    $database
                );
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed. Check your database settings or Railway environment variables.']));
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
