<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Core\Auth;
use App\Core\Config;

$user = Auth::user();
if ($user) {
    $target = ['admin' => '/admin.php', 'control' => '/control.php', 'entry' => '/entry.php'][$user['role']] ?? '/entry.php';
    header('Location: ' . $target);
    exit;
}
if (!file_exists(__DIR__ . '/../config/config.php') && !Config::hasRuntimeConfig()) {
    header('Location: /install/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Warehouse Inventory — Login</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="container" style="padding-top:15vh">
  <div class="card">
    <h1 class="mt-0 center">📦 Warehouse Inventory</h1>
    <div id="error" class="banner err" style="display:none"></div>
    <form id="loginForm">
      <label>Username</label>
      <input type="text" id="username" autocomplete="username" required autofocus>
      <label>Password</label>
      <input type="password" id="password" autocomplete="current-password" required>
      <button type="submit" class="btn-primary btn-block" id="submitBtn">Log in</button>
    </form>
  </div>
</div>
<script src="/assets/js/common.js"></script>
<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errBox = document.getElementById('error');
  const btn = document.getElementById('submitBtn');
  errBox.style.display = 'none';
  btn.disabled = true; btn.textContent = 'Logging in…';
  try {
    const data = await apiPost('/api/login.php', {
      username: document.getElementById('username').value,
      password: document.getElementById('password').value,
    });
    const target = { admin: '/admin.php', control: '/control.php', entry: '/entry.php' }[data.user.role] || '/entry.php';
    window.location.href = target;
  } catch (err) {
    errBox.textContent = err.message || 'Login failed.';
    errBox.style.display = 'block';
    btn.disabled = false; btn.textContent = 'Log in';
  }
});
</script>
</body>
</html>
