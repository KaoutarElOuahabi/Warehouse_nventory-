<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Core\Auth;
$user = Auth::requireRole('entry', 'control', 'admin');
if (php_sapi_name() !== 'cli') { /* still render for control/admin who may want to test entry too */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Data Entry — Warehouse Inventory</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="topbar">
  <div>
    <div class="title">📦 Inventory Warehouse</div>
    <div class="user"><?= htmlspecialchars($user['full_name']) ?> · Data Entry</div>
  </div>
  <button class="logout" onclick="doLogout()">Log out</button>
</div>

<div class="container" id="app">

  <!-- STEP 1: Address -->
  <div class="card" id="addressCard">
    <label class="mt-0">ADDRESS</label>
    <div class="scan-row">
      <input type="text" id="addressInput" placeholder="Address format: A-01-01" autocomplete="off">
      <button class="btn-secondary" id="scanAddressBtn">📷 Scan</button>
    </div>
    <div id="addressSuggestions"></div>
    <button class="btn-primary btn-block" id="setAddressBtn">Start / Continue</button>
    <div id="addressMsg" class="hint"></div>
  </div>

  <!-- STEP 2: HU + PN + Qty (hidden until address set) -->
  <div id="entryCard" style="display:none">
    <div class="card">
      <div class="hint mt-0">ADDRESS</div>
      <div class="readonly-field" id="currentAddress" style="font-size:1.3rem"></div>
      <button class="btn-secondary btn-sm" style="margin-top:8px" onclick="changeAddress()">Change address</button>
    </div>

    <div class="card">
      <label class="mt-0">HANDLING UNIT</label>
      <div class="scan-row">
        <input type="text" id="huInput" placeholder="HU number, e.g. 300660525" autocomplete="off">
        <button class="btn-secondary" id="scanHuBtn">📷 Scan</button>
      </div>
      <div id="huMsg" class="hint"></div>

      <div class="checkbox-row">
        <input type="checkbox" id="huNotAvailable">
        <label for="huNotAvailable">HU NOT AVAILABLE</label>
      </div>

      <label id="pnLabel">PART NUMBER</label>
      <div id="pnAutoField" class="readonly-field empty">—</div>
      <div class="scan-row" id="pnManualField" style="display:none;position:relative">
        <input type="text" id="pnInput" placeholder="Scan or search Part Number" autocomplete="off">
      </div>
      <div id="pnSuggestions"></div>

      <label>UNIT</label>
      <div class="readonly-field empty" id="unitField">—</div>

      <label>QUANTITY *</label>
      <input type="number" id="quantityInput" step="any" placeholder="Enter quantity" inputmode="decimal">

      <div id="entryBanner"></div>

      <button class="btn-primary btn-block" id="confirmBtn" style="margin-top:18px">CONFIRM</button>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span>HUs recorded at this address:</span>
        <strong id="huCount" style="font-size:1.3rem">0</strong>
      </div>
      <button class="btn-success btn-block" id="completeBtn" style="margin-top:14px">COMPLETE ADDRESS</button>
      <div id="recordedList" style="margin-top:12px"></div>
    </div>
  </div>

</div>

<div id="cameraModal" class="modal-backdrop" style="display:none">
  <div class="modal">
    <h3 class="mt-0" id="cameraTitle">Scan</h3>
    <div id="camera-wrap"><div id="qr-reader" style="width:100%"></div></div>
    <button class="btn-secondary btn-block" style="margin-top:12px" onclick="closeCamera()">Cancel</button>
  </div>
</div>

<script src="/assets/vendor/html5-qrcode/html5-qrcode.min.js"></script>
<script src="/assets/js/common.js"></script>
<script src="/assets/js/entry.js"></script>
</body>
</html>
