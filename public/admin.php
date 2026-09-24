<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Core\Auth;
$user = Auth::requireRole('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Admin — Warehouse Inventory</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>
<div class="topbar">
  <div>
    <div class="title">⚙️ Admin</div>
    <div class="user"><?= htmlspecialchars($user['full_name']) ?> · Admin</div>
  </div>
  <button class="logout" onclick="doLogout()">Log out</button>
</div>

<div class="container-wide">
  <div class="tabs">
    <button class="active" data-tab="dashboard">Dashboard</button>
    <button data-tab="import">Import Stock</button>
    <button data-tab="addresses">Addresses</button>
    <button data-tab="users">Users</button>
    <button data-tab="audit">Audit Trail</button>
  </div>

  <div id="tab-dashboard" class="tab-panel">
    <div class="stat-grid" id="statGrid"></div>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span>Active import batch</span>
        <span id="activeBatch" class="text-muted"></span>
      </div>
    </div>
    <div class="card">
      <button class="btn-primary" style="width:auto" id="exportResultsBtn">⬇ Export results for SAP correction (Excel)</button>
      <a class="btn btn-secondary" style="margin-left:8px" href="/api/admin/export_audit.php">⬇ Export audit trail (CSV)</a>
      <p class="hint">Sheets: <b>SAP corrections</b> (differences of finished addresses only — Controlled or Completed OK — with the action to post), <b>Not finished</b> (not counted / waiting for Control), <b>Addresses</b>, <b>All lines</b>.</p>
    </div>
    <div class="card">
      <h3 class="mt-0">Start a new counting cycle</h3>
      <p class="hint">This clears all physical counts and resets every address to NOT STARTED. Imported stock is kept. This cannot be undone.</p>
      <input type="text" id="resetConfirm" placeholder='Type RESET to confirm'>
      <button class="btn-danger btn-block" style="margin-top:10px" onclick="resetCycle()">Reset counting cycle</button>
    </div>
  </div>

  <div id="tab-import" class="tab-panel" style="display:none">
    <div class="card">
      <h3 class="mt-0">Upload stock file</h3>
      <p class="hint">Required columns (any order, header row required): <b>Address, HU, Part Number, Unit, Quantity</b>. Accepts .xlsx, .xls, .csv.
        A row with only an Address (HU/PN/Unit/Qty empty) registers an <b>empty bin</b>, so it can be counted too.
        <a href="/templates/stock_import_template.csv" download>Download template</a></p>
      <p class="hint">A new upload <b>replaces</b> the previous stock completely: HUs, part numbers and addresses that are not in the new file are removed (addresses that already have counts are kept).</p>
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv">
      <button class="btn-secondary btn-block" style="margin-top:10px" id="previewBtn">Preview</button>
      <div id="importPreview"></div>
      <button class="btn-primary btn-block" style="margin-top:10px;display:none" id="commitBtn">Confirm Import</button>
    </div>
  </div>

  <div id="tab-addresses" class="tab-panel" style="display:none">
    <div class="card">
      <select id="statusFilter">
        <option value="">All statuses</option>
        <option value="NOT_STARTED">Not started</option>
        <option value="IN_PROGRESS">In progress</option>
        <option value="COMPLETED_OK">Completed - OK</option>
        <option value="COMPLETED_CONTROL_REQUIRED">Completed - Control required</option>
        <option value="CONTROL_IN_PROGRESS">Control in progress</option>
        <option value="CONTROLLED">Controlled</option>
      </select>
      <input type="text" id="addressSearch" placeholder="Search address" autocomplete="off" style="margin-top:10px">
      <div class="hint" id="addressSummary"></div>
    </div>
    <div id="addressList"></div>
  </div>

  <div id="tab-users" class="tab-panel" style="display:none">
    <div class="card">
      <h3 class="mt-0">New user</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="text" id="newUsername" placeholder="Username" style="flex:1;min-width:120px">
        <input type="text" id="newFullName" placeholder="Full name" style="flex:1;min-width:120px">
        <input type="password" id="newPassword" placeholder="Password" style="flex:1;min-width:120px">
        <select id="newRole"><option value="entry">Data Entry</option><option value="control">Control</option><option value="admin">Admin</option></select>
      </div>
      <button class="btn-primary btn-block" style="margin-top:10px" onclick="createUser()">Create user</button>
    </div>
    <div id="userList"></div>
  </div>

  <div id="tab-audit" class="tab-panel" style="display:none">
    <div id="auditList"></div>
  </div>
</div>

<script src="/assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="/assets/js/common.js?v=<?= filemtime(__DIR__ . '/assets/js/common.js') ?>"></script>
<script src="/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/assets/js/admin.js') ?>"></script>
</body>
</html>
