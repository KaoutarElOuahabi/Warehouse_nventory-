<?php
/**
 * One-time setup wizard. Creates config/config.php, builds the database
 * schema, and creates the first admin user. Delete this /install/ folder
 * (or it will refuse to run) once setup is complete.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$configPath = __DIR__ . '/../../config/config.php';
$alreadyInstalled = file_exists($configPath);

// Railway (and any other managed host) injects DB connection info via env vars
// at runtime. If those are present, this is a live deployment and the wizard
// must never be allowed to run, regardless of whether config.php exists yet —
// otherwise anyone who finds /install/ could repoint the app at their own DB.
$runtimeDbHost = getenv('MYSQLHOST') ?: getenv('DB_HOST');
$runtimeDbName = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: getenv('DB_DATABASE');
$runtimeDbConfigured = ($runtimeDbHost && $runtimeDbName) || getenv('DATABASE_URL') || getenv('MYSQL_URL');

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && !$runtimeDbConfigured) {
    $driver = $_POST['driver'] ?? 'mysql';
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int)($_POST['port'] ?? 3306);
    $dbname = trim($_POST['database'] ?? '');
    $dbuser = trim($_POST['username'] ?? '');
    $dbpass = $_POST['password'] ?? '';
    $adminUser = trim($_POST['admin_username'] ?? '');
    $adminName = trim($_POST['admin_full_name'] ?? '');
    $adminPass = $_POST['admin_password'] ?? '';

    if ($driver === 'mysql' && ($dbname === '' || $dbuser === '')) {
        $errors[] = 'Database name and username are required for MySQL.';
    }
    if ($adminUser === '' || $adminName === '' || strlen($adminPass) < 6) {
        $errors[] = 'Admin username, full name, and a password of at least 6 characters are required.';
    }

    if (!$errors) {
        try {
            if ($driver === 'sqlite') {
                $sqlitePath = __DIR__ . '/../../storage/database.sqlite';
                @mkdir(dirname($sqlitePath), 0755, true);
                $pdo = new PDO('sqlite:' . $sqlitePath);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $schemaFile = __DIR__ . '/../../database/schema.sqlite.sql';
            } else {
                $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbuser, $dbpass);
                $schemaFile = __DIR__ . '/../../database/schema.mysql.sql';
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = file_get_contents($schemaFile);
            $sqlLines = array_filter(explode("\n", $sql), function ($l) {
                return strpos(trim($l), '--') !== 0;
            });
            $sql = implode("\n", $sqlLines);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt);
            }

            // Create first admin user
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, role, active, created_at, updated_at)
                 VALUES (:u, :p, :fn, :r, 1, :now, :now)'
            );
            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                ':u' => $adminUser, ':p' => password_hash($adminPass, PASSWORD_DEFAULT),
                ':fn' => $adminName, ':r' => 'admin', ':now' => $now,
            ]);

            $appKey = bin2hex(random_bytes(32));
            $configContents = "<?php\nreturn [\n" .
                "    'db' => [\n" .
                "        'driver'   => " . var_export($driver, true) . ",\n" .
                "        'host'     => " . var_export($host, true) . ",\n" .
                "        'port'     => " . var_export($port, true) . ",\n" .
                "        'database' => " . var_export($dbname, true) . ",\n" .
                "        'username' => " . var_export($dbuser, true) . ",\n" .
                "        'password' => " . var_export($dbpass, true) . ",\n" .
                "        'sqlite_path' => __DIR__ . '/../storage/database.sqlite',\n" .
                "    ],\n" .
                "    'app_key' => " . var_export($appKey, true) . ",\n" .
                "    'app_name' => 'Warehouse Inventory',\n" .
                "    'session_lifetime_minutes' => 480,\n" .
                "];\n";
            file_put_contents($configPath, $configContents);

            $success = true;
        } catch (\Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Warehouse Inventory — Setup</title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px}
  .card{max-width:520px;margin:24px auto;background:#1e293b;border-radius:12px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.4)}
  h1{font-size:1.4rem;margin-top:0}
  label{display:block;margin:14px 0 4px;font-size:.85rem;color:#94a3b8}
  input,select{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:1rem}
  button{margin-top:20px;width:100%;padding:12px;border:none;border-radius:8px;background:#2563eb;color:#fff;font-size:1rem;font-weight:600;cursor:pointer}
  button:hover{background:#1d4ed8}
  .err{background:#7f1d1d;color:#fecaca;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:.9rem}
  .ok{background:#14532d;color:#bbf7d0;padding:16px;border-radius:8px}
  fieldset{border:1px solid #334155;border-radius:8px;margin-top:18px;padding:12px}
  legend{padding:0 6px;color:#94a3b8;font-size:.85rem}
  a{color:#93c5fd}
  .hint{font-size:.78rem;color:#64748b;margin-top:4px}
</style>
</head>
<body>
<div class="card">
<h1>Warehouse Inventory — Setup</h1>

<?php if ($runtimeDbConfigured && !$success): ?>
  <div class="err">This environment has a database configured via runtime environment variables (production). The setup wizard is disabled here for security. If you need to (re)build the schema, run <code>migrate.php</code> from a deploy shell instead, then delete this <code>/install</code> folder.</div>
<?php elseif ($alreadyInstalled && !$success): ?>
  <div class="err">This app is already installed. Delete <code>config/config.php</code> if you need to re-run setup, or go to <a href="../">the login page</a>.</div>
<?php elseif ($success): ?>
  <div class="ok">
    <strong>Setup complete.</strong><br><br>
    For security, please delete the <code>/install</code> folder now.<br><br>
    <a href="../">Go to login →</a>
  </div>
<?php else: ?>
  <?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <form method="post">
    <fieldset>
      <legend>Database</legend>
      <label>Driver</label>
      <select name="driver" id="driver" onchange="document.getElementById('mysqlFields').style.display=this.value==='mysql'?'block':'none'">
        <option value="mysql" selected>MySQL (production / Hostinger)</option>
        <option value="sqlite">SQLite (quick local testing)</option>
      </select>
      <div id="mysqlFields">
        <label>Host</label><input name="host" value="127.0.0.1">
        <label>Port</label><input name="port" value="3306">
        <label>Database name</label><input name="database">
        <label>Database username</label><input name="username">
        <label>Database password</label><input name="password" type="password">
      </div>
    </fieldset>
    <fieldset>
      <legend>First admin account</legend>
      <label>Username</label><input name="admin_username" required>
      <label>Full name</label><input name="admin_full_name" required>
      <label>Password</label><input name="admin_password" type="password" required minlength="6">
      <div class="hint">At least 6 characters. You can create more users (Control, Data Entry) after logging in.</div>
    </fieldset>
    <button type="submit">Install</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
