<?php
return [
    'db' => [
        'driver'   => 'sqlite',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'sqlite_path' => __DIR__ . '/../storage/database.sqlite',
    ],
    'app_key' => 'e88cf4d030cdfa45283a1777028509b78f667d9eb4666e07f0360c81b1d56014',
    'app_name' => 'Warehouse Inventory',
    'session_lifetime_minutes' => 480,
];
