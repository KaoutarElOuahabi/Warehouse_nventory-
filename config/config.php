<?php
return [
    'db' => [
        'driver' => getenv('DB_DRIVER') ?: getenv('DATABASE_DRIVER') ?: 'mysql',
        'host' => getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306),
        'database' => getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: getenv('DB_DATABASE') ?: 'inventory_app',
        'username' => getenv('DB_USERNAME') ?: getenv('MYSQL_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '',
        'sqlite_path' => getenv('DB_SQLITE_PATH') ?: __DIR__ . '/../storage/database.sqlite',
    ],
    'app_key' => getenv('APP_KEY') ?: getenv('APP_SECRET') ?: 'change-me-in-production',
    'app_name' => getenv('APP_NAME') ?: 'Warehouse Inventory',
    'session_lifetime_minutes' => (int) (getenv('SESSION_LIFETIME_MINUTES') ?: 480),
];
