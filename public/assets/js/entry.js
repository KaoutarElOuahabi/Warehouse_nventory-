let currentAddressCode = null;
let huFoundLocked = false; // true when PN was auto-filled from a known HU (PN becomes non-editable)
let manualUnitMode = false;
let addressSearchTimer = null;
let pnSearchTimer = null;

let html5QrCode = null;
let cameraTargetInput = null;

(async function init() {
  const user = await requireSession(['entry', 'control', 'admin']);
  if (!user) return;

  document.getElementById('scanAddressBtn').addEventListener('click', () => openCamera('addressInput', 'Scan Address barcode'));
  document.getElementById('setAddressBtn').addEventListener('click', setAddress);
  document.getElementById('addressInput').addEventListener('input', onAddressInput);
  document.getElementById('addressInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') setAddress(); });

  document.getElementById('scanHuBtn').addEventListener('click', () => openCamera('huInput', 'Scan HU barcode', onHuScanned));
  document.getElementById('huInput').addEventListener('change', () => lookupHu(document.getElementById('huInput').value.trim()));
  document.getElementById('huInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookupHu(e.target.value.trim()); } });

  document.getElementById('huNotAvailable').addEventListener('change', onHuNotAvailableToggle);
  document.getElementById('pnInput').addEventListener('input', onPnInput);
  document.getElementById('confirmBtn').addEventListener('click', onConfirmClick);
  document.getElementById('completeBtn').addEventListener('click', onCompleteAddress);
})();

function openCamera(targetInputId, title, onDecodedExtra) {
  cameraTargetInput = targetInputId;
  document.getElementById('cameraTitle').textContent = title;
  document.getElementById('cameraModal').style.display = 'flex';
  html5QrCode = new Html5Qrcode('qr-reader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 250, height: 150 } },
    (decodedText) => {
      document.getElementById(targetInputId).value = decodedText.trim();
      closeCamera();
      if (onDecodedExtra) onDecodedExtra(decodedText.trim());
    },
    () => {}
  ).catch((err) => {
    closeCamera();
    alert('Could not access the camera: ' + err + '\nYou can type the value instead.');
  });
}

function closeCamera() {
  document.getElementById('cameraModal').style.display = 'none';
  if (html5QrCode) {
    html5QrCode.stop().catch(() => {}).finally(() => { html5QrCode = null; });
  }
}

function onHuScanned(value) {
  lookupHu(value);
}

function onAddressInput(e) {
  const q = e.target.value.trim();
  clearTimeout(addressSearchTimer);
  if (!q) {
    document.getElementById('addressSuggestions').innerHTML = '';
    return;
  }
  addressSearchTimer = setTimeout(async () => {
    try {
      const data = await apiGet('/api/entry/search_address.php?q=' + encodeURIComponent(q));
      renderAddressSuggestions(data.results || []);
    } catch (e) {
      document.getElementById('addressSuggestions').innerHTML = '';
    }
  }, 120);
}

function renderAddressSuggestions(results) {
  const box = document.getElementById('addressSuggestions');
  if (!results.length) {
    box.innerHTML = '';
    return;
  }
  box.innerHTML = '<div class="card" style="padding:8px;margin-top:6px">' +
    results.map(r => `<div class="list-item" style="margin-bottom:4px" onclick="selectAddress('${escapeHtml(r.code)}')">
      <span class="code">${escapeHtml(r.code)}</span>
    </div>`).join('') +
    '</div>';
}

function selectAddress(code) {
  document.getElementById('addressInput').value = code;
  document.getElementById('addressSuggestions').innerHTML = '';
  setAddress();
}

async function setAddress() {
  const code = document.getElementById('addressInput').value.trim();
  const msg = document.getElementById('addressMsg');
  if (!code) { msg.textContent = 'Enter or scan an address.'; return; }
  msg.textContent = 'Loading…';
  try {
    const data = await apiPost('/api/entry/address.php', { code });
    currentAddressCode = data.address.code;
    document.getElementById('currentAddress').textContent = currentAddressCode;
    document.getElementById('addressCard').style.display = 'none';
    document.getElementById('entryCard').style.display = 'block';
    resetHuForm();
    await refreshCounts();
    msg.textContent = '';
  } catch (e) {
    msg.textContent = e.message;
  }
}

function openEntryForAddress(addressCode) {
  currentAddressCode = addressCode;
  document.getElementById('currentAddress').textContent = currentAddressCode;
  document.getElementById('addressCard').style.display = 'none';
  document.getElementById('entryCard').style.display = 'block';
  resetHuForm();
  refreshCounts();
}

function changeAddress() {
  document.getElementById('entryCard').style.display = 'none';
  document.getElementById('addressCard').style.display = 'block';
  document.getElementById('addressInput').value = '';
  document.getElementById('addressInput').focus();
  currentAddressCode = null;
}

function resetHuForm() {
  document.getElementById('huInput').value = '';
  document.getElementById('huInput').disabled = false;
  document.getElementById('huNotAvailable').checked = false;
  document.getElementById('pnInput').value = '';
  document.getElementById('quantityInput').value = '';
  document.getElementById('entryBanner').innerHTML = '';
  document.getElementById('pnSuggestions').innerHTML = '';
  huFoundLocked = false;
  manualUnitMode = false;
  showAutoPnField('—', true);
  setUnitField('—', true);
  togglePnMode(false);
  document.getElementById('huInput').focus();
}

function togglePnMode(manual) {
  document.getElementById('pnAutoField').style.display = manual ? 'none' : 'block';
  document.getElementById('pnManualField').style.display = manual ? 'flex' : 'none';
}

function showAutoPnField(text, empty) {
  const f = document.getElementById('pnAutoField');
  f.textContent = text;
  f.className = 'readonly-field' + (empty ? ' empty' : '');
}

function setUnitField(text, empty) {
  const wrap = document.getElementById('unitField');
  if (manualUnitMode) {
    wrap.outerHTML = `<select id="unitField" class="readonly-field">
      <option value="PCS">PCS</option><option value="KG">KG</option>
      <option value="M">M (Meter)</option><option value="L">L (Liter)</option>
      <option value="ROLLS">Rolls</option></select>`;
  } else {
    if (wrap.tagName === 'SELECT') {
      wrap.outerHTML = `<div class="readonly-field" id="unitField"></div>`;
    }
    document.getElementById('unitField').textContent = text;
    document.getElementById('unitField').className = 'readonly-field' + (empty ? ' empty' : '');
  }
}

function getUnitValue() {
  const f = document.getElementById('unitField');
  return f.tagName === 'SELECT' ? f.value : f.textContent.trim();
}

function onHuNotAvailableToggle() {
  const checked = document.getElementById('huNotAvailable').checked;
  document.getElementById('huInput').disabled = checked;
  if (checked) {
    document.getElementById('huInput').value = '';
    huFoundLocked = false;
    manualUnitMode = true;
    togglePnMode(true);
    setUnitField('', true);
    document.getElementById('pnInput').focus();
  } else {
    togglePnMode(false);
    showAutoPnField('—', true);
    manualUnitMode = false;
    setUnitField('—', true);
  }
}

async function lookupHu(hu) {
  if (!hu || document.getElementById('huNotAvailable').checked) return;
  document.getElementById('entryBanner').innerHTML = '';
  try {
    const data = await apiPost('/api/entry/scan_hu.php', { hu });
    if (data.found) {
      if (!currentAddressCode && data.address_code) {
        openEntryForAddress(data.address_code);
      }
      huFoundLocked = true;
      manualUnitMode = false;
      togglePnMode(false);
      showAutoPnField(data.part_number, false);
      setUnitField(data.unit, false);
      document.getElementById('quantityInput').focus();
    } else {
      huFoundLocked = false;
      togglePnMode(true);
      document.getElementById('pnInput').value = '';
      document.getElementById('pnInput').focus();
      manualUnitMode = false;
      setUnitField('—', true);
      document.getElementById('entryBanner').innerHTML =
        '<div class="banner warn">HU not found in imported stock. Enter the Part Number and Quantity manually.</div>';
    }
  } catch (e) {
    document.getElementById('entryBanner').innerHTML = `<div class="banner err">${escapeHtml(e.message)}</div>`;
  }
}

function onPnInput(e) {
  const q = e.target.value.trim();
  clearTimeout(pnSearchTimer);
  if (!q) { document.getElementById('pnSuggestions').innerHTML = ''; return; }
  pnSearchTimer = setTimeout(async () => {
    try {
      const data = await apiGet('/api/entry/search_pn.php?q=' + encodeURIComponent(q));
      renderPnSuggestions(data.results);
    } catch (e) {}
  }, 120);
}

function renderPnSuggestions(results) {
  const box = document.getElementById('pnSuggestions');
  if (!results.length) { box.innerHTML = ''; return; }
  box.innerHTML = '<div class="card" style="padding:8px;margin-top:6px">' +
    results.map(r => `<div class="list-item" style="margin-bottom:4px" onclick="selectPn('${escapeHtml(r.part_number)}','${escapeHtml(r.unit)}')">
      <span class="code">${escapeHtml(r.part_number)}</span><span class="meta">${escapeHtml(r.unit)}</span></div>`).join('') +
    '</div>';
}

async function selectPn(pn, unit) {
  document.getElementById('pnInput').value = pn;
  document.getElementById('pnSuggestions').innerHTML = '';
  manualUnitMode = false;
  setUnitField(unit, false);
  document.getElementById('quantityInput').focus();
}

function currentFormData() {
  const huNotAvailable = document.getElementById('huNotAvailable').checked;
  const hu = huNotAvailable ? '' : document.getElementById('huInput').value.trim();
  const partNumber = huFoundLocked
    ? document.getElementById('pnAutoField').textContent.trim()
    : document.getElementById('pnInput').value.trim();
  const unit = getUnitValue();
  const quantity = document.getElementById('quantityInput').value;
  return { address_code: currentAddressCode, hu, hu_not_available: huNotAvailable, part_number: partNumber, unit, quantity };
}

async function onConfirmClick() {
  const form = currentFormData();
  const banner = document.getElementById('entryBanner');
  banner.innerHTML = '';

  if (!form.hu_not_available && !form.hu) { banner.innerHTML = '<div class="banner err">Scan or enter a Handling Unit, or check "HU NOT AVAILABLE".</div>'; return; }
  if (!form.part_number) { banner.innerHTML = '<div class="banner err">Part Number is required.</div>'; return; }
  if (form.quantity === '' || form.quantity === null || form.quantity === undefined) { banner.innerHTML = '<div class="banner err">Quantity is required.</div>'; return; }
  if (Number(form.quantity) < 0) { banner.innerHTML = '<div class="banner err">Quantity cannot be negative.</div>'; return; }

  const btn = document.getElementById('confirmBtn');
  btn.disabled = true;
  try {
    const pre = await apiPost('/api/entry/precheck.php', form);
    if (pre.signal === 'DIFFERENCE') {
      banner.innerHTML = `
        <div class="banner warn">⚠ DIFFERENCE DETECTED<br><span style="font-weight:400;font-size:.85rem">Please physically double-check before confirming.</span></div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <button class="btn-secondary" style="flex:1" id="recheckBtn">RECHECK / EDIT</button>
          <button class="btn-danger" style="flex:1" id="forceConfirmBtn">CONFIRM PHYSICAL COUNT</button>
        </div>`;
      document.getElementById('recheckBtn').addEventListener('click', () => { banner.innerHTML = ''; document.getElementById('quantityInput').focus(); });
      document.getElementById('forceConfirmBtn').addEventListener('click', () => saveCount(form));
    } else {
      await saveCount(form);
    }
  } catch (e) {
    banner.innerHTML = `<div class="banner err">${escapeHtml(e.message)}</div>`;
  } finally {
    btn.disabled = false;
  }
}

async function saveCount(form) {
  const banner = document.getElementById('entryBanner');
  try {
    const res = await apiPost('/api/entry/confirm.php', form);
    banner.innerHTML = res.signal === 'DIFFERENCE'
      ? '<div class="banner warn">⚠ Recorded — difference noted for Control.</div>'
      : '<div class="banner ok">✓ COUNT RECORDED</div>';
    document.getElementById('huCount').textContent = res.hus_recorded;
    await refreshCounts();
    setTimeout(() => { if (currentAddressCode) resetHuForm(); }, 900);
  } catch (e) {
    banner.innerHTML = `<div class="banner err">${escapeHtml(e.message)}</div>`;
  }
}

async function refreshCounts() {
  if (!currentAddressCode) return;
  try {
    const data = await apiGet('/api/entry/my_counts.php?address_code=' + encodeURIComponent(currentAddressCode));
    document.getElementById('huCount').textContent = data.rows.length;
    const list = document.getElementById('recordedList');
    if (!data.rows.length) { list.innerHTML = ''; return; }
    list.innerHTML = data.rows.map(r => `
      <div class="list-item" style="cursor:default">
        <span>
          <span class="code">${escapeHtml(r.hu || '(no HU)')}</span><br>
          <span class="meta">${escapeHtml(r.part_number)} · ${escapeHtml(r.unit)}</span>
        </span>
        <strong>${r.quantity}</strong>
      </div>`).join('');
  } catch (e) {}
}

async function onCompleteAddress() {
  if (!currentAddressCode) return;
  if (!confirm(`Complete address ${currentAddressCode}? You can still scan it again later if needed.`)) return;
  try {
    const res = await apiPost('/api/entry/complete_address.php', { address_code: currentAddressCode });
    alert(res.message);
    changeAddress();
  } catch (e) {
    alert(e.message);
  }
}
