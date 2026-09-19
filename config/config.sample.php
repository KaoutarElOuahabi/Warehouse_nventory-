<?php
/**
 * Rename this file to config.php (the install wizard does this for you)
 * and fill in your real database credentials.
 */
return [
    'db' => [
        'driver'   => 'mysql',       // 'mysql' for production, 'sqlite' for local testing
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'inventory_app',
        'username' => 'inventory_user',
        'password' => 'change_me',
        'sqlite_path' => __DIR__ . '/../storage/database.sqlite',
    ],
    // Random 32+ character string, unique per install. The install wizard generates one.
    'app_key' => 'CHANGE_THIS_TO_A_RANDOM_SECRET_STRING',
    'app_name' => 'Warehouse Inventory',
    'session_lifetime_minutes' => 480,
];
