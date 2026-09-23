<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Core\Auth;
$user = Auth::requireRole('control', 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Control — Warehouse Inventory</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="topbar">
  <div>
    <div class="title">🔎 Control</div>
    <div class="user"><?= htmlspecialchars($user['full_name']) ?> · Control</div>
  </div>
  <button class="logout" onclick="doLogout()">Log out</button>
</div>

<div class="container-wide">

  <div id="queueView">
    <div class="card">
      <div class="scan-row">
        <input type="text" id="queueScanInput" placeholder="Scan or type an Address to open it directly">
        <button class="btn-primary" id="queueScanBtn">Open</button>
      </div>
    </div>
    <h2 id="queueTitle">CONTROL REQUIRED</h2>
    <div id="queueList"></div>
  </div>

  <div id="addressView" style="display:none">
    <button class="btn-secondary btn-sm" onclick="backToQueue()">← Back to queue</button>
    <div class="card">
      <h2 class="mt-0" id="addrTitle"></h2>
      <div id="addrSummary" class="hint"></div>
    </div>
    <div class="card" style="overflow-x:auto">
      <table id="linesTable">
        <thead>
          <tr><th>HU</th><th>Expected</th><th>Physical</th><th>Status</th><th></th></tr>
        </thead>
        <tbody id="linesBody"></tbody>
      </table>
    </div>
    <div class="card">
      <label class="mt-0">Add missing physical stock</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="text" id="addHu" placeholder="HU (optional)" style="flex:1;min-width:100px">
        <input type="text" id="addPn" placeholder="Part Number" style="flex:1;min-width:120px">
        <input type="text" id="addQty" placeholder="Qty" inputmode="decimal" autocomplete="off" style="width:110px">
      </div>
      <button class="btn-secondary btn-block" style="margin-top:10px" onclick="addPhysical()">+ Add</button>
    </div>
    <div class="card">
      <label class="mt-0">Observations</label>
      <textarea id="observations" rows="3" placeholder="Notes about this control (optional)"></textarea>
      <button class="btn-success btn-block" style="margin-top:14px" onclick="validateAddress()">VALIDATE ADDRESS</button>
    </div>
  </div>

</div>
<script src="/assets/js/common.js"></script>
<script src="/assets/js/control.js"></script>
</body>
</html>
