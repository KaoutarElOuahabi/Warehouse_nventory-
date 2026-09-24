let currentAddressCode = null;
let huFoundLocked = false; // true when PN was auto-filled from a known HU (PN becomes non-editable)
let lastHuLookup = null;
let addressSearchTimer = null;
let pnSearchTimer = null;
let decimalUnits = ['KG', 'G', 'M', 'L', 'LM'];
let countRows = new Map();

let html5QrCode = null;

async function loadUnits() {
  try {
    const data = await apiGet('/api/entry/units.php');
    if (data.decimal_units && data.decimal_units.length) {
      decimalUnits = data.decimal_units.map(u => String(u).toUpperCase());
    }
  } catch (e) { /* keep defaults */ }
}

function allowsDecimal(unit) {
  return decimalUnits.includes(String(unit || '').toUpperCase());
}

// Handling Units: 300 + 6 digits or 1000 + 6 digits. Labels/SAP may add "H" or a
// leading 0 (H300660525, 0300660525) — same HU. Mirrors normalize_hu() in bootstrap.php.
const HU_PATTERN = /^(300\d{6}|1000\d{6})$/;

function normalizeHu(value) {
  let v = String(value || '').replace(/\s+/g, '').toUpperCase();
  if (v.startsWith('H')) v = v.slice(1);
  if (/^0300\d{6}$/.test(v)) v = v.slice(1);
  return v;
}

function isHuCode(value) {
  return HU_PATTERN.test(normalizeHu(value));
}

// Error message while typing/submitting an HU, or null if it's fine so far.
function huFormatError(value, complete = true) {
  let v = String(value || '').replace(/\s+/g, '').toUpperCase();
  if (!v) return null;
  if (v.startsWith('H')) v = v.slice(1);
  if (!/^\d*$/.test(v)) return 'An HU has digits only (e.g. 300660525, H300660525 or 0300660525). This looks like an address or another code.';
  if (!v) return null;
  const fits = ['300', '0300', '1000'].some(p => p.startsWith(v.slice(0, p.length)) || v.startsWith(p));
  if (!fits) return 'An HU starts with 300, 0300 or 1000.';
  if (v.length > 10) return `An HU has 9 or 10 digits — you typed ${v.length}.`;
  if (complete && !isHuCode(v)) return `Incomplete HU: 300 + 6 digits (e.g. 300660525) or 0300/1000 + 6 digits.`;
  return null;
}

async function isKnownAddressMatch(code) {
  const normalized = String(code || '').trim();
  if (!normalized) return false;
  try {
    const data = await apiGet('/api/entry/search_address.php?q=' + encodeURIComponent(normalized));
    const matches = data.results || [];
    return matches.some((row) => String(row.code).toUpperCase() === normalized.toUpperCase());
  } catch (e) {
    return false;
  }
}

(async function init() {
  const user = await requireSession(['entry', 'control', 'admin']);
  if (!user) return;

  await loadUnits();

  document.getElementById('scanAddressBtn').addEventListener('click', () => openCamera('addressInput', 'Scan Address barcode'));
  document.getElementById('setAddressBtn').addEventListener('click', setAddress);
  document.getElementById('addressInput').addEventListener('input', onAddressInput);
  document.getElementById('addressInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') setAddress(); });
  document.getElementById('addressSuggestions').addEventListener('click', (e) => {
    const item = e.target.closest('[data-code]');
    if (item) selectAddress(item.dataset.code);
  });
  document.getElementById('myAddressesList').addEventListener('click', (e) => {
    const item = e.target.closest('[data-code]');
    if (item && !item.classList.contains('locked')) selectAddress(item.dataset.code);
  });
  document.getElementById('myAddressesFilter').addEventListener('input', renderMyAddresses);
  loadMyAddresses();

  document.getElementById('scanHuBtn').addEventListener('click', () => openCamera('huInput', 'Scan HU barcode', lookupHu));
  document.getElementById('huInput').addEventListener('input', onHuInput);
  document.getElementById('huInput').addEventListener('change', () => lookupHu(document.getElementById('huInput').value.trim()));
  document.getElementById('huInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookupHu(e.target.value.trim()); } });

  document.getElementById('huNotAvailable').addEventListener('change', onHuNotAvailableToggle);
  document.getElementById('pnInput').addEventListener('input', onPnInput);
  document.getElementById('pnInput').addEventListener('change', resolveTypedPn);
  document.getElementById('pnInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); resolveTypedPn(); } });
  document.getElementById('pnSuggestions').addEventListener('click', (e) => {
    const item = e.target.closest('[data-pn]');
    if (item) selectPn(item.dataset.pn, item.dataset.unit);
  });

  document.getElementById('quantityInput').addEventListener('input', onQuantityInput);
  document.getElementById('quantityInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); onConfirmClick(); } });
  document.getElementById('confirmBtn').addEventListener('click', onConfirmClick);
  document.getElementById('completeBtn').addEventListener('click', onCompleteAddress);
  document.getElementById('recordedList').addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.dataset.edit) openEditLine(Number(btn.dataset.edit));
    if (btn.dataset.del) deleteLine(Number(btn.dataset.del));
  });
})();

function openCamera(targetInputId, title, onDecodedExtra) {
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

function showBanner(kind, message) {
  document.getElementById('entryBanner').innerHTML = message ? `<div class="banner ${kind}">${escapeHtml(message)}</div>` : '';
}

// ---------- Address ----------
function onAddressInput(e) {
  const q = e.target.value.trim();
  const msg = document.getElementById('addressMsg');
  clearTimeout(addressSearchTimer);

  if (!q) {
    document.getElementById('addressSuggestions').innerHTML = '';
    if (msg) msg.textContent = '';
    return;
  }
  msg.textContent = isHuCode(q) ? `${q} is a Handling Unit, not an address. Scan it in the HU field after choosing the address.` : '';

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
    box.innerHTML = '<div class="hint hint-err">No matching address in master data.</div>';
    return;
  }
  box.innerHTML = '<div class="card" style="padding:8px;margin-top:6px">' +
    results.map(r => `<div class="list-item" style="margin-bottom:4px" data-code="${escapeHtml(r.code)}">
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

  const exactMatch = await isKnownAddressMatch(code);
  if (!exactMatch) {
    msg.textContent = isHuCode(code)
      ? `${code} is a Handling Unit, not an address. Select the address from the list.`
      : 'Address not found in master data. Select a valid address from the list.';
    return;
  }

  msg.textContent = 'Loading…';
  try {
    const data = await apiPost('/api/entry/address.php', { code });
    currentAddressCode = data.address.code;
    document.getElementById('currentAddress').textContent = currentAddressCode;
    document.getElementById('addressCard').style.display = 'none';
    document.getElementById('myAddressesCard').style.display = 'none';
    document.getElementById('entryCard').style.display = 'block';
    document.getElementById('addressSuggestions').innerHTML = '';
    resetHuForm();
    await refreshCounts();
    msg.textContent = '';
  } catch (e) {
    msg.textContent = e.message;
  }
}

function changeAddress() {
  document.getElementById('entryCard').style.display = 'none';
  document.getElementById('addressCard').style.display = 'block';
  document.getElementById('addressInput').value = '';
  document.getElementById('addressInput').focus();
  currentAddressCode = null;
  countRows = new Map();
  loadMyAddresses();
}

// ---------- My addresses today (quick re-check / reopen) ----------
let myAddresses = [];
const MY_STATUS = {
  IN_PROGRESS: ['badge-diff', 'NOT COMPLETED'],
  COMPLETED_OK: ['badge-match', 'COMPLETED'],
  COMPLETED_CONTROL_REQUIRED: ['badge-unexpected', 'SENT TO CONTROL'],
  CONTROL_IN_PROGRESS: ['badge-muted', 'WITH CONTROL'],
  CONTROLLED: ['badge-muted', 'CONTROLLED'],
};

async function loadMyAddresses() {
  try {
    const data = await apiGet('/api/entry/my_addresses.php');
    myAddresses = data.addresses || [];
  } catch (e) {
    myAddresses = [];
  }
  renderMyAddresses();
}

function renderMyAddresses() {
  const card = document.getElementById('myAddressesCard');
  const onAddressScreen = document.getElementById('addressCard').style.display !== 'none';
  card.style.display = onAddressScreen && myAddresses.length ? 'block' : 'none';
  if (!myAddresses.length) return;

  const lines = myAddresses.reduce((sum, a) => sum + a.my_lines, 0);
  const open = myAddresses.filter(a => a.status === 'IN_PROGRESS').length;
  document.getElementById('myAddressesSummary').textContent =
    `${myAddresses.length} address(es) · ${lines} line(s)` + (open ? ` · ${open} not completed` : '');

  const filterInput = document.getElementById('myAddressesFilter');
  filterInput.style.display = myAddresses.length > 8 ? 'block' : 'none';
  const f = filterInput.value.trim().toUpperCase();
  const shown = f ? myAddresses.filter(a => String(a.code).toUpperCase().includes(f)) : myAddresses;

  document.getElementById('myAddressesList').innerHTML = shown.map(a => {
    const [cls, label] = MY_STATUS[a.status] || ['badge-muted', a.status];
    const locked = a.status === 'CONTROL_IN_PROGRESS' || a.status === 'CONTROLLED';
    return `<div class="list-item${locked ? ' locked' : ''}" data-code="${escapeHtml(a.code)}">
      <span>
        <span class="code">${escapeHtml(a.code)}</span><br>
        <span class="meta">${a.my_lines} line(s) · ${escapeHtml(String(a.last_activity || '').slice(11, 16))}</span>
      </span>
      <span class="badge ${cls}">${label}</span>
    </div>`;
  }).join('') || '<div class="hint">No match.</div>';
}

function renderAddressStatus(status, locked) {
  const note = document.getElementById('addressStatusNote');
  document.getElementById('confirmBtn').disabled = locked;
  document.getElementById('completeBtn').disabled = locked;
  if (locked) {
    note.innerHTML = '<div class="banner warn">Control is handling this address — lines can no longer be changed here.</div>';
  } else if (status === 'COMPLETED_OK' || status === 'COMPLETED_CONTROL_REQUIRED') {
    note.innerHTML = '<div class="banner ok" style="font-weight:600;font-size:.9rem">This address is already completed. You can still correct it — any change reopens it and you must press COMPLETE ADDRESS again.</div>';
  } else {
    note.innerHTML = '';
  }
}

// ---------- HU / PN / Unit / Quantity form ----------
function resetHuForm() {
  document.getElementById('huInput').value = '';
  document.getElementById('huInput').disabled = false;
  setHuMsg('', '');
  document.getElementById('huNotAvailable').checked = false;
  document.getElementById('pnInput').value = '';
  document.getElementById('pnMsg').textContent = '';
  document.getElementById('quantityInput').value = '';
  document.getElementById('pnSuggestions').innerHTML = '';
  showBanner('', '');
  huFoundLocked = false;
  lastHuLookup = null;
  showAutoPnField('—', true);
  setUnitField('—', true);
  togglePnMode(false);
  onQuantityInput();
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

// Unit is never chosen by the user: it always comes from SAP (HU) or part master (PN).
function setUnitField(text, empty) {
  const f = document.getElementById('unitField');
  f.textContent = text;
  f.className = 'readonly-field' + (empty ? ' empty' : '');
  onQuantityInput();
}

function getUnitValue() {
  const f = document.getElementById('unitField');
  return f.classList.contains('empty') ? '' : f.textContent.trim();
}

function onHuInput(e) {
  lastHuLookup = null;
  setHuMsg(huFormatError(e.target.value, false) || '', 'err');
  if (huFoundLocked) {
    // HU text changed after a successful lookup: drop the PN/unit of the old HU.
    huFoundLocked = false;
    showAutoPnField('—', true);
    setUnitField('—', true);
  }
  // A full HU needs no Enter/Tab: show its PN from master data right away.
  const v = e.target.value.trim();
  if (isHuCode(v)) lookupHu(v);
}

function onHuNotAvailableToggle() {
  const checked = document.getElementById('huNotAvailable').checked;
  document.getElementById('huInput').disabled = checked;
  setHuMsg('', '');
  huFoundLocked = false;
  document.getElementById('pnInput').value = '';
  document.getElementById('pnMsg').textContent = '';
  setUnitField('—', true);
  if (checked) {
    document.getElementById('huInput').value = '';
    togglePnMode(true);
    document.getElementById('pnInput').focus();
  } else {
    togglePnMode(false);
    showAutoPnField('—', true);
  }
}

function setHuMsg(text, kind) {
  const msg = document.getElementById('huMsg');
  msg.textContent = text;
  msg.className = 'hint' + (text ? (kind === 'ok' ? ' hint-ok' : ' hint-err') : '');
}

async function lookupHu(rawHu) {
  const hu = normalizeHu(rawHu);
  // Enter + blur both fire for the same value; look each value up only once.
  if (!hu || document.getElementById('huNotAvailable').checked || hu === lastHuLookup) return;
  lastHuLookup = hu;
  showBanner('', '');
  const formatError = huFormatError(rawHu);
  if (formatError) {
    setHuMsg(formatError, 'err');
    return;
  }
  setHuMsg('', '');
  try {
    const data = await apiPost('/api/entry/scan_hu.php', { hu });
    // The HU was changed while this lookup was running: ignore the old answer.
    if (normalizeHu(document.getElementById('huInput').value) !== hu) return;
    if (data.found) {
      setHuMsg(`✓ HU ${hu} found in stock — Part Number and unit filled in. Enter the quantity.`, 'ok');
      huFoundLocked = true;
      togglePnMode(false);
      showAutoPnField(data.part_number, false);
      setUnitField(data.unit, false);
      document.getElementById('quantityInput').focus();
    } else {
      huFoundLocked = false;
      togglePnMode(true);
      document.getElementById('pnInput').value = '';
      document.getElementById('pnMsg').textContent = '';
      setUnitField('—', true);
      document.getElementById('pnInput').focus();
      setHuMsg(`HU ${hu} is NOT in the imported stock. Check the number on the label — if it is correct, choose the Part Number below.`, 'err');
    }
  } catch (e) {
    lastHuLookup = null; // allow a retry of the same HU
    showBanner('err', e.message);
  }
}

function onPnInput(e) {
  const q = e.target.value.trim();
  document.getElementById('pnMsg').textContent = '';
  setUnitField('—', true);
  clearTimeout(pnSearchTimer);
  if (!q) { document.getElementById('pnSuggestions').innerHTML = ''; return; }
  pnSearchTimer = setTimeout(async () => {
    try {
      const data = await apiGet('/api/entry/search_pn.php?q=' + encodeURIComponent(q));
      renderPnSuggestions(data.results || []);
    } catch (e) {}
  }, 120);
}

function renderPnSuggestions(results) {
  const box = document.getElementById('pnSuggestions');
  if (!results.length) {
    box.innerHTML = '<div class="hint hint-err">No matching Part Number in master data.</div>';
    return;
  }
  box.innerHTML = '<div class="card" style="padding:8px;margin-top:6px">' +
    results.map(r => `<div class="list-item" style="margin-bottom:4px" data-pn="${escapeHtml(r.part_number)}" data-unit="${escapeHtml(r.unit)}">
      <span class="code">${escapeHtml(r.part_number)}</span><span class="meta">${escapeHtml(r.unit)}</span></div>`).join('') +
    '</div>';
}

function selectPn(pn, unit) {
  document.getElementById('pnInput').value = pn;
  document.getElementById('pnSuggestions').innerHTML = '';
  document.getElementById('pnMsg').textContent = '';
  setUnitField(unit, false);
  document.getElementById('quantityInput').focus();
}

// Typed (not picked) PN: resolve it against master data right away.
async function resolveTypedPn() {
  if (huFoundLocked) return;
  const pn = document.getElementById('pnInput').value.trim();
  const msg = document.getElementById('pnMsg');
  if (!pn || getUnitValue()) return;
  try {
    const data = await apiPost('/api/entry/lookup_pn.php', { part_number: pn });
    if (document.getElementById('pnInput').value.trim() !== pn) return;
    if (data.unit) {
      msg.textContent = '';
      setUnitField(data.unit, false);
    } else {
      msg.textContent = `Part Number "${pn}" is not in master data. Pick one from the list.`;
    }
  } catch (e) {
    msg.textContent = e.message;
  }
}

function checkQuantity() {
  const p = parseQuantityText(document.getElementById('quantityInput').value);
  if (p.error) return p;
  const unit = getUnitValue();
  if (unit && !allowsDecimal(unit) && !Number.isInteger(p.value)) {
    return { value: null, error: `${unit} is counted in whole numbers — no decimals.` };
  }
  return p;
}

function onQuantityInput() {
  const el = document.getElementById('qtyPreview');
  if (!document.getElementById('quantityInput').value.trim()) {
    el.textContent = '';
    el.className = 'hint';
    return;
  }
  const q = checkQuantity();
  if (q.error) {
    el.textContent = q.error;
    el.className = 'hint hint-err';
    return;
  }
  el.textContent = `Will record: ${formatQty(q.value)} ${getUnitValue()}`.trim();
  el.className = 'hint hint-ok';
}

function currentFormData() {
  const huNotAvailable = document.getElementById('huNotAvailable').checked;
  const hu = huNotAvailable ? '' : normalizeHu(document.getElementById('huInput').value);
  const partNumber = huFoundLocked
    ? document.getElementById('pnAutoField').textContent.trim()
    : document.getElementById('pnInput').value.trim();
  return {
    address_code: currentAddressCode,
    hu,
    hu_not_available: huNotAvailable,
    part_number: partNumber,
    unit: getUnitValue(),
    quantity: document.getElementById('quantityInput').value.trim(),
  };
}

async function onConfirmClick() {
  const btn = document.getElementById('confirmBtn');
  if (btn.disabled) return;
  let form = currentFormData();
  showBanner('', '');

  // HU typed but not looked up yet: fill its PN from master data before saving.
  if (!form.hu_not_available && isHuCode(form.hu) && lastHuLookup !== form.hu) {
    await lookupHu(form.hu);
    if (!huFoundLocked) return; // unknown HU: the counter picks the PN first
    form = currentFormData();
  }

  if (!form.hu_not_available && !form.hu) return showBanner('err', 'Scan or enter a Handling Unit, or check "HU NOT AVAILABLE".');
  if (!form.hu_not_available) {
    const huErr = huFormatError(form.hu);
    if (huErr) return showBanner('err', huErr);
  }
  if (!form.part_number) return showBanner('err', 'Part Number is required.');
  if (!form.unit) return showBanner('err', 'Select the Part Number from the list so its unit is known.');
  const q = checkQuantity();
  if (q.error) return showBanner('err', q.error);
  if (q.value >= 10000 && !confirm(`Large quantity: ${formatQty(q.value)} ${form.unit}.\n\nIs this correct?`)) return;

  btn.disabled = true;
  try {
    const pre = await apiPost('/api/entry/precheck.php', form);
    const newQty = `${formatQty(pre.quantity)} ${pre.unit}`;
    if (pre.already_here && !confirm(
      `HU ${form.hu} is already recorded at this address (${formatQty(pre.already_here.quantity)} ${pre.already_here.unit}).\n\nReplace it with ${newQty}?`
    )) return;
    if (pre.counted_elsewhere && pre.counted_elsewhere.length && !confirm(
      `HU ${form.hu} was already counted at ${pre.counted_elsewhere.join(', ')}.\nAn HU can only be in one place.\n\nRecord it here as well? Control will check both addresses.`
    )) return;

    if (pre.signal === 'DIFFERENCE') {
      document.getElementById('entryBanner').innerHTML = `
        <div class="banner warn">⚠ DIFFERENCE DETECTED<br><span style="font-weight:400;font-size:.85rem">Please physically double-check ${escapeHtml(newQty)} before confirming.</span></div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <button class="btn-secondary" style="flex:1" id="recheckBtn">RECHECK / EDIT</button>
          <button class="btn-danger" style="flex:1" id="forceConfirmBtn">CONFIRM PHYSICAL COUNT</button>
        </div>`;
      document.getElementById('recheckBtn').addEventListener('click', () => { showBanner('', ''); document.getElementById('quantityInput').focus(); });
      document.getElementById('forceConfirmBtn').addEventListener('click', (ev) => {
        ev.currentTarget.disabled = true;
        document.getElementById('recheckBtn').disabled = true;
        saveCount(form);
      });
    } else {
      await saveCount(form);
    }
  } catch (e) {
    showBanner('err', e.message);
  } finally {
    btn.disabled = false;
  }
}

async function saveCount(form) {
  try {
    const res = await apiPost('/api/entry/confirm.php', form);
    const recorded = `${form.hu || 'NO HU'} · ${formatQty(parseQuantityText(form.quantity).value)} ${res.unit}`;
    resetHuForm();
    showBanner(res.signal === 'DIFFERENCE' ? 'warn' : 'ok',
      res.signal === 'DIFFERENCE' ? `⚠ Recorded (${recorded}) — difference noted for Control.` : `✓ COUNT RECORDED (${recorded})`);
    await refreshCounts();
  } catch (e) {
    showBanner('err', e.message);
    // The save may have reached the server before the connection dropped:
    // show the real list so the counter can see whether to retry.
    await refreshCounts(true);
  }
}

// ---------- Recorded lines (edit / delete own lines) ----------
async function refreshCounts(keepBanner = false) {
  if (!currentAddressCode) return;
  try {
    const data = await apiGet('/api/entry/my_counts.php?address_code=' + encodeURIComponent(currentAddressCode));
    countRows = new Map(data.rows.map(r => [r.id, r]));
    document.getElementById('huCount').textContent = data.rows.length;
    renderAddressStatus(data.address_status, data.locked);
    document.getElementById('recordedList').innerHTML = data.rows.map(r => `
      <div class="count-row">
        <div class="count-main">
          <div class="code">${escapeHtml(r.hu || 'NO HU LABEL')}</div>
          <div class="meta">PN ${escapeHtml(r.part_number)}</div>
          <div class="meta">${escapeHtml(r.entered_by_name || '')} · ${escapeHtml(String(r.entered_at || '').slice(11, 16))}</div>
        </div>
        <div class="count-side">
          <div class="count-qty">${escapeHtml(formatQty(r.quantity))} <span class="meta">${escapeHtml(r.unit)}</span></div>
          ${r.can_edit ? `<div class="count-actions">
            <button class="btn-secondary btn-sm" data-edit="${r.id}">Edit</button>
            <button class="btn-danger btn-sm" data-del="${r.id}">Delete</button>
          </div>` : ''}
        </div>
      </div>`).join('');
  } catch (e) {
    if (!keepBanner) showBanner('err', 'Could not load the recorded lines: ' + e.message);
  }
}

function openEditLine(id) {
  const r = countRows.get(id);
  if (!r) return;
  const modal = document.createElement('div');
  modal.className = 'modal-backdrop';
  modal.innerHTML = `<div class="modal">
    <h3 class="mt-0">Correct line</h3>
    <div class="hint mt-0">HU: <b>${escapeHtml(r.hu || 'NO HU LABEL')}</b></div>
    <label>PART NUMBER</label>
    <input type="text" id="editPn" autocomplete="off" value="${escapeHtml(r.part_number)}" ${r.pn_editable ? '' : 'disabled'}>
    ${r.pn_editable ? '' : '<div class="hint">Part Number comes from SAP for this HU. Wrong HU? Delete this line and scan the right one.</div>'}
    <label>QUANTITY (${escapeHtml(r.unit)})</label>
    <input type="text" id="editQty" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatQty(r.quantity))}">
    <div id="editMsg" class="hint hint-err"></div>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn-secondary" style="flex:1" data-act="cancel">Cancel</button>
      <button class="btn-primary" style="flex:1" data-act="save">Save</button>
    </div>
  </div>`;
  document.body.appendChild(modal);
  const qtyInput = modal.querySelector('#editQty');
  qtyInput.focus();
  qtyInput.select();

  modal.addEventListener('click', async (e) => {
    if (e.target === modal || e.target.dataset.act === 'cancel') { modal.remove(); return; }
    if (e.target.dataset.act !== 'save') return;
    const msg = modal.querySelector('#editMsg');
    const pn = modal.querySelector('#editPn').value.trim();
    const p = parseQuantityText(qtyInput.value);
    if (p.error) { msg.textContent = p.error; return; }
    if (pn === r.part_number && !allowsDecimal(r.unit) && !Number.isInteger(p.value)) {
      msg.textContent = `${r.unit} is counted in whole numbers — no decimals.`;
      return;
    }
    e.target.disabled = true;
    try {
      const res = await apiPost('/api/entry/update_count.php', { id: r.id, action: 'edit', part_number: pn, quantity: qtyInput.value.trim() });
      modal.remove();
      showBanner('ok', res.message);
      await refreshCounts();
    } catch (err) {
      msg.textContent = err.message;
      e.target.disabled = false;
    }
  });
}

async function deleteLine(id) {
  const r = countRows.get(id);
  if (!r) return;
  if (!confirm(`Delete this line?\n\nHU: ${r.hu || 'NO HU LABEL'}\nPN: ${r.part_number}\nQty: ${formatQty(r.quantity)} ${r.unit}\n\nOnly this line is removed — the address and its other lines stay.`)) return;
  try {
    const res = await apiPost('/api/entry/update_count.php', { id: r.id, action: 'delete' });
    showBanner('ok', res.message);
    await refreshCounts();
  } catch (e) {
    showBanner('err', e.message);
  }
}

async function onCompleteAddress() {
  if (!currentAddressCode) return;
  const lines = countRows.size;
  const confirmEmpty = lines === 0;
  const question = confirmEmpty
    ? `No HU recorded at ${currentAddressCode}.\n\nConfirm this address is physically EMPTY?`
    : `Complete address ${currentAddressCode} with ${lines} line(s)?`;
  if (!confirm(question)) return;
  try {
    const res = await apiPost('/api/entry/complete_address.php', { address_code: currentAddressCode, confirm_empty: confirmEmpty });
    alert(res.message);
    changeAddress();
  } catch (e) {
    alert(e.message);
  }
}
