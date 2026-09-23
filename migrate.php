<?php

declare(strict_types=1);

$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
$user = getenv('MYSQL_USERNAME') ?: getenv('MYSQLUSER') ?: getenv('DB_USERNAME') ?: 'root';
$password = getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
$database = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'railway';

if (!$host || !$database) {
    fwrite(STDERR, "Missing MySQL env configuration. Required: MYSQLHOST and MYSQL_DATABASE (or DB_HOST/DB_NAME).\n");
    exit(1);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $database);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schema = __DIR__ . '/database/schema.mysql.sql';
    if (!file_exists($schema)) {
        fwrite(STDERR, "Schema file not found: $schema\n");
        exit(1);
    }

    $sql = file_get_contents($schema);
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n?/', $sql) ?: []), static fn($s) => $s !== '');

    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement . ';');
    }

    // physical_counts may already exist from before this uniqueness guard was
    // introduced (CREATE TABLE IF NOT EXISTS above is a no-op on it in that
    // case). Add it here so two concurrent submits for the same address+HU
    // can no longer both insert instead of one inserting and one updating.
    // Non-fatal: if pre-existing duplicate rows make the ALTER fail, deploy
    // still proceeds — clear those duplicates and redeploy to pick it up.
    try {
        $hasCol = $pdo->query(
            "SELECT COUNT(*) c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'physical_counts' AND column_name = 'hu_active'"
        )->fetch();
        if ((int)$hasCol['c'] === 0) {
            $pdo->exec(
                "ALTER TABLE physical_counts
                 ADD COLUMN hu_active VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN is_deleted = 0 THEN hu ELSE NULL END) VIRTUAL"
            );
        }
        $hasIdx = $pdo->query(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'physical_counts' AND index_name = 'uniq_active_addr_hu'"
        )->fetch();
        if ((int)$hasIdx['c'] === 0) {
            $pdo->exec('ALTER TABLE physical_counts ADD UNIQUE KEY uniq_active_addr_hu (address_id, hu_active)');
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[migrate] warning: could not add physical_counts uniqueness guard: ' . $e->getMessage() . PHP_EOL);
    }

    echo "Migration complete. Schema is ready for $database.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
