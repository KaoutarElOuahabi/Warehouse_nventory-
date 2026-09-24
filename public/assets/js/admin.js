let parsedRows = null;
let parsedFilename = null;

(async function init() {
  const user = await requireSession(['admin']);
  if (!user) return;

  document.querySelectorAll('.tabs button').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });
  document.getElementById('previewBtn').addEventListener('click', onPreview);
  document.getElementById('commitBtn').addEventListener('click', onCommitImport);
  document.getElementById('statusFilter').addEventListener('change', loadAddresses);
  document.getElementById('exportResultsBtn').addEventListener('click', exportResultsXlsx);

  await loadDashboard();
})();

function switchTab(tab) {
  document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('tab-' + tab).style.display = 'block';
  if (tab === 'dashboard') loadDashboard();
  if (tab === 'addresses') loadAddresses();
  if (tab === 'users') loadUsers();
  if (tab === 'audit') loadAudit();
}

async function loadDashboard() {
  const data = await apiGet('/api/admin/dashboard.php');
  const c = data.address_counts;
  const grid = document.getElementById('statGrid');
  const items = [
    ['Not started', c.NOT_STARTED], ['In progress', c.IN_PROGRESS],
    ['Completed - OK', c.COMPLETED_OK], ['Control required', c.COMPLETED_CONTROL_REQUIRED],
    ['Control in progress', c.CONTROL_IN_PROGRESS], ['Controlled', c.CONTROLLED],
  ];
  grid.innerHTML = items.map(([label, n]) => `<div class="stat"><div class="num">${n}</div><div class="lbl">${label}</div></div>`).join('');
  document.getElementById('activeBatch').textContent = data.active_batch
    ? `${data.active_batch.filename} — ${data.active_batch.row_count} rows (${data.active_batch.imported_at})`
    : 'No stock imported yet';
}

async function resetCycle() {
  const confirmVal = document.getElementById('resetConfirm').value.trim();
  if (confirmVal !== 'RESET') { alert('Type RESET to confirm.'); return; }
  if (!confirm('This will clear ALL physical counts. Continue?')) return;
  try {
    const res = await apiPost('/api/admin/reset_cycle.php', { confirm: 'RESET' });
    alert(`Done. ${res.physical_counts_cleared} physical count(s) cleared.`);
    document.getElementById('resetConfirm').value = '';
    loadDashboard();
  } catch (e) { alert(e.message); }
}

// ---------- Results export (Excel) ----------
const STATUS_TEXT = {
  MATCH: 'Match', QUANTITY_DIFFERENCE: 'Quantity difference', PN_DIFFERENCE: 'Part number difference',
  MISSING: 'Missing (in SAP, not found)', UNEXPECTED: 'Unexpected (found, not in SAP)',
  WRONG_LOCATION: 'Wrong location', HU_LABEL_MISSING: 'HU label missing',
};

// Codes are written as text cells (leading zeros kept), quantities as number
// cells, so Excel in any language shows them correctly.
function sheetFrom(rows, widths) {
  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!cols'] = widths.map(w => ({ wch: w }));
  if (rows.length > 1) ws['!autofilter'] = { ref: ws['!ref'] };
  return ws;
}

async function exportResultsXlsx() {
  const btn = document.getElementById('exportResultsBtn');
  btn.disabled = true;
  const label = btn.textContent;
  btn.textContent = 'Building export…';
  try {
    const data = await apiGet('/api/admin/results.php');
    const s = (v) => (v === null || v === undefined) ? '' : String(v);
    const n = (v) => (v === null || v === undefined) ? '' : Number(v);

    const lineHeader = ['Address', 'HU', 'Status', 'SAP action', 'SAP Part Number', 'SAP Qty', 'Counted Part Number', 'Counted Qty',
      'Unit', 'Difference (counted - SAP)', 'SAP address of HU', 'HU also counted at', 'No HU label', 'Counted by', 'Address status'];
    const lineRow = (l) => [
      s(l.address), s(l.hu), STATUS_TEXT[l.status] || l.status, s(l.action),
      s(l.expected_part_number), n(l.expected_quantity), s(l.physical_part_number), n(l.physical_quantity),
      s(l.physical_unit || l.expected_unit), l.status === 'MATCH' ? 0 : n(l.difference),
      s(l.sap_address), s(l.found_at), l.hu_not_available ? 'yes' : '', s(l.counted_by), s(l.address_status),
    ];
    const lineWidths = [14, 16, 26, 60, 18, 10, 18, 10, 8, 12, 14, 16, 10, 18, 26];

    // Only final results may be posted in SAP: an address not counted or not
    // yet controlled must never produce a "post the loss" line.
    const FINAL = ['CONTROLLED', 'COMPLETED_OK'];
    const REASON = {
      NOT_STARTED: 'Not counted yet', IN_PROGRESS: 'Counting not completed',
      COMPLETED_CONTROL_REQUIRED: 'Waiting for Control', CONTROL_IN_PROGRESS: 'Control in progress',
    };
    const diffs = data.lines.filter(l => l.status !== 'MATCH' && FINAL.includes(l.address_status));
    const notFinished = data.addresses.filter(a => !FINAL.includes(a.status));

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, sheetFrom([lineHeader, ...diffs.map(lineRow)], lineWidths), 'SAP corrections');
    XLSX.utils.book_append_sheet(wb, sheetFrom(
      [['Address', 'Status', 'Why not final', 'SAP HUs', 'Lines counted', 'Differences so far'],
        ...notFinished.map(a => [s(a.code), s(a.status), REASON[a.status] || '', n(a.sap_hus), n(a.counted_lines), n(a.issues)])],
      [14, 26, 24, 10, 14, 16]), 'Not finished');
    XLSX.utils.book_append_sheet(wb, sheetFrom(
      [['Address', 'Status', 'SAP HUs', 'Lines counted', 'Differences', 'Completed by', 'Completed at', 'Controlled by', 'Controlled at', 'Control observations'],
        ...data.addresses.map(a => [s(a.code), s(a.status), n(a.sap_hus), n(a.counted_lines), n(a.issues), s(a.completed_by), s(a.completed_at),
          s(a.controlled_by), s(a.controlled_at), s(a.control_observations)])],
      [14, 26, 10, 14, 12, 18, 18, 18, 18, 40]), 'Addresses');
    XLSX.utils.book_append_sheet(wb, sheetFrom([lineHeader, ...data.lines.map(lineRow)], lineWidths), 'All lines');

    const stamp = String(data.generated_at || '').replace(/[^0-9]/g, '').slice(0, 12);
    XLSX.writeFile(wb, `inventory_results_${stamp}.xlsx`);
  } catch (e) {
    alert('Export failed: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.textContent = label;
  }
}

// ---------- Import ----------
function onPreview() {
  const fileInput = document.getElementById('importFile');
  if (!fileInput.files.length) { alert('Choose a file first.'); return; }
  const file = fileInput.files[0];
  parsedFilename = file.name;
  const reader = new FileReader();
  reader.onload = async (e) => {
    try {
      const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
      const sheet = wb.Sheets[wb.SheetNames[0]];
      // Codes come from the displayed text (keeps leading zeros like "000123"),
      // quantities from the real cell value (a "2,000"-formatted cell must stay
      // 2000, not become the text "2,000").
      const textRows = XLSX.utils.sheet_to_json(sheet, { defval: '', raw: false });
      const rawRows = XLSX.utils.sheet_to_json(sheet, { defval: '', raw: true });
      parsedRows = textRows.map((r, i) => {
        const t = normalizeRow(r);
        const raw = normalizeRow(rawRows[i] || {});
        return {
          address: codeValue(t.address, raw.address),
          hu: codeValue(t.hu, raw.hu),
          part_number: codeValue(t.part_number, raw.part_number),
          unit: String(t.unit ?? '').trim(),
          quantity: typeof raw.quantity === 'number' ? raw.quantity : String(raw.quantity ?? '').trim(),
        };
      });
      const data = await apiPost('/api/admin/import.php', { rows: parsedRows, filename: parsedFilename, mode: 'preview' });
      renderPreview(data);
    } catch (err) {
      document.getElementById('importPreview').innerHTML = `<div class="banner err">${escapeHtml(err.message || String(err))}</div>`;
    }
  };
  reader.readAsArrayBuffer(file);
}

function normalizeRow(r) {
  // tolerant header matching: Address / address / ADDRESS, "Part Number" / PN, etc.
  const keys = Object.keys(r);
  const find = (...names) => {
    for (const k of keys) {
      const norm = k.toLowerCase().replace(/[^a-z0-9]/g, '');
      if (names.includes(norm)) return r[k];
    }
    return '';
  };
  return {
    address: find('address', 'addresscode', 'addr'),
    hu: find('hu', 'handlingunit', 'handlingunitcode'),
    part_number: find('partnumber', 'pn', 'partno', 'material'),
    unit: find('unit', 'uom', 'unitofmeasure'),
    quantity: find('quantity', 'qty', 'qte'),
  };
}

// A code cell stored as a number: use its displayed text when that is pure
// digits (keeps zero-padding from the cell format), else the full integer
// (never "3.00661E+11").
function codeValue(text, raw) {
  if (typeof raw === 'number') {
    const t = String(text ?? '').trim();
    if (/^\d+$/.test(t)) return t;
    return Number.isInteger(raw) ? raw.toFixed(0) : String(raw);
  }
  return String(raw ?? '').trim();
}

function renderPreview(data) {
  const box = document.getElementById('importPreview');
  let html = `<div class="banner ${data.error_count ? 'warn' : 'ok'}">${data.valid_rows} valid stock row(s), ${data.empty_bins || 0} empty bin(s), ${data.error_count} rejected row(s)</div>`;
  if (data.errors.length) {
    html += '<div class="card" style="max-height:200px;overflow:auto"><b>Rejected rows (not imported)</b><ul>' + data.errors.map(e => `<li>${escapeHtml(e)}</li>`).join('') + '</ul></div>';
  }
  if (data.warnings && data.warnings.length) {
    html += '<div class="card" style="max-height:200px;overflow:auto"><b>Check before importing</b><ul>' + data.warnings.map(e => `<li>${escapeHtml(e)}</li>`).join('') + '</ul></div>';
  }
  if (data.sample && data.sample.length) {
    html += '<div class="card" style="overflow-x:auto"><b>First rows as they will be imported</b><table><thead><tr><th>Address</th><th>HU</th><th>Part Number</th><th>Qty</th><th>Unit</th></tr></thead><tbody>' +
      data.sample.map(s => `<tr><td>${escapeHtml(s.address)}</td><td>${escapeHtml(s.hu)}</td><td>${escapeHtml(s.part_number)}</td><td>${escapeHtml(formatQty(s.quantity))}</td><td>${escapeHtml(s.unit)}</td></tr>`).join('') +
      '</tbody></table></div>';
  }
  box.innerHTML = html;
  document.getElementById('commitBtn').style.display = (data.valid_rows > 0 || data.empty_bins > 0) ? 'block' : 'none';
}

async function onCommitImport() {
  if (!parsedRows) return;
  if (!confirm('This REPLACES all previous stock: old HUs, part numbers and addresses not in this file are removed. Continue?')) return;
  try {
    const res = await apiPost('/api/admin/import.php', { rows: parsedRows, filename: parsedFilename, mode: 'commit' });
    let msg = `Imported ${res.imported_rows} stock row(s) and ${res.empty_bins} empty bin(s). ${res.error_count} row(s) rejected.`
      + `\n${res.addresses_removed} old address(es) removed.`;
    if (res.addresses_kept_with_counts) {
      msg += `\n${res.addresses_kept_with_counts} old address(es) kept because they already have counts — use "Reset counting cycle" first to remove them too.`;
    }
    alert(msg);
    document.getElementById('commitBtn').style.display = 'none';
    document.getElementById('importPreview').innerHTML = '';
    document.getElementById('importFile').value = '';
    parsedRows = null;
    loadDashboard();
  } catch (e) {
    alert(e.message + (e.data && e.data.errors ? '\n' + e.data.errors.join('\n') : ''));
  }
}

// ---------- Addresses ----------
async function loadAddresses() {
  const status = document.getElementById('statusFilter').value;
  const data = await apiGet('/api/admin/addresses.php' + (status ? '?status=' + status : ''));
  const box = document.getElementById('addressList');
  if (!data.addresses.length) { box.innerHTML = '<div class="card text-muted center">No addresses.</div>'; return; }
  box.innerHTML = data.addresses.map(a => `
    <div class="list-item" onclick="viewAddress('${escapeHtml(a.code)}')">
      <span class="code">${escapeHtml(a.code)}</span>
      <span class="meta">${escapeHtml(a.status)}</span>
    </div>`).join('');
}

async function viewAddress(code) {
  const data = await apiGet('/api/admin/address_detail.php?code=' + encodeURIComponent(code));
  const lines = data.lines.map(l => `<tr>
      <td>${escapeHtml(l.hu || '(no HU)')}</td>
      <td>${l.expected_quantity !== null ? escapeHtml(l.expected_part_number) + ' — ' + escapeHtml(formatQty(l.expected_quantity)) + ' ' + escapeHtml(l.expected_unit) : '—'}</td>
      <td>${l.physical_quantity !== null ? escapeHtml(l.physical_part_number) + ' — ' + escapeHtml(formatQty(l.physical_quantity)) + ' ' + escapeHtml(l.physical_unit) : '—'}</td>
      <td>${escapeHtml(l.status)}${l.action ? `<div class="hint">${escapeHtml(l.action)}</div>` : ''}</td>
    </tr>`).join('');
  const modal = document.createElement('div');
  modal.className = 'modal-backdrop';
  modal.innerHTML = `<div class="modal" style="max-width:700px">
    <h3 class="mt-0">${escapeHtml(code)} — ${escapeHtml(data.address.status)}</h3>
    <table><thead><tr><th>HU</th><th>Expected</th><th>Physical</th><th>Status</th></tr></thead><tbody>${lines}</tbody></table>
    <button class="btn-secondary btn-block" style="margin-top:14px">Close</button>
  </div>`;
  modal.querySelector('button').addEventListener('click', () => modal.remove());
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);
}

// ---------- Users ----------
async function loadUsers() {
  const data = await apiGet('/api/admin/users.php');
  const box = document.getElementById('userList');
  box.innerHTML = data.users.map(u => `
    <div class="list-item" style="cursor:default">
      <span><span class="code">${escapeHtml(u.username)}</span><br><span class="meta">${escapeHtml(u.full_name)} · ${escapeHtml(u.role)}</span></span>
      <button class="btn-secondary btn-sm" onclick="toggleUser(${u.id}, ${u.active ? 0 : 1})">${u.active ? 'Deactivate' : 'Activate'}</button>
    </div>`).join('');
}

async function createUser() {
  const username = document.getElementById('newUsername').value.trim();
  const full_name = document.getElementById('newFullName').value.trim();
  const password = document.getElementById('newPassword').value;
  const role = document.getElementById('newRole').value;
  if (!username || !full_name || password.length < 6) { alert('Fill all fields (password min 6 chars).'); return; }
  try {
    await apiPost('/api/admin/users.php', { action: 'create', username, full_name, password, role });
    document.getElementById('newUsername').value = '';
    document.getElementById('newFullName').value = '';
    document.getElementById('newPassword').value = '';
    loadUsers();
  } catch (e) { alert(e.message); }
}

async function toggleUser(id, active) {
  await apiPost('/api/admin/users.php', { action: 'update', id, active });
  loadUsers();
}

// ---------- Audit ----------
async function loadAudit() {
  const data = await apiGet('/api/admin/audit.php?limit=200');
  const box = document.getElementById('auditList');
  box.innerHTML = `<div class="card" style="overflow-x:auto"><table>
    <thead><tr><th>When</th><th>Entity</th><th>Action</th><th>Actor</th><th>Notes</th></tr></thead>
    <tbody>${data.entries.map(e => `<tr>
      <td>${escapeHtml(e.created_at)}</td>
      <td>${escapeHtml(e.entity_type)}${e.entity_id ? ' #' + e.entity_id : ''}</td>
      <td>${escapeHtml(e.action)}</td>
      <td>${escapeHtml(e.actor_name || '')}</td>
      <td>${escapeHtml(e.notes || '')}</td>
    </tr>`).join('')}</tbody></table></div>`;
}
