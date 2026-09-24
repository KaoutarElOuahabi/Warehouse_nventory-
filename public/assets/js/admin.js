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
  document.getElementById('addressSearch').addEventListener('input', renderAddresses);
  document.getElementById('exportResultsBtn').addEventListener('click', exportResultsXlsx);
  document.getElementById('liveRefreshBtn').addEventListener('click', loadLive);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && liveVisible()) loadLive(); });
  setInterval(() => { if (!document.hidden && liveVisible()) loadLive(); }, LIVE_REFRESH_MS);

  await loadLive();
})();

function switchTab(tab) {
  document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('tab-' + tab).style.display = 'block';
  if (tab === 'live') loadLive();
  if (tab === 'dashboard') loadDashboard();
  if (tab === 'addresses') loadAddresses();
  if (tab === 'users') loadUsers();
  if (tab === 'audit') loadAudit();
}

// ---------- Live follow-up ----------
const LIVE_REFRESH_MS = 30000;
let liveLoading = false;

function liveVisible() {
  return document.getElementById('tab-live').style.display !== 'none';
}

function minutesText(m) {
  if (m === null || m === undefined) return '—';
  if (m < 60) return `${m} min`;
  return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;
}

function progressBar(percent) {
  const cls = percent >= 100 ? 'done' : percent >= 50 ? 'mid' : 'low';
  return `<div class="bar"><div class="bar-fill ${cls}" style="width:${Math.min(100, percent)}%"></div></div>`;
}

async function loadLive() {
  if (liveLoading) return;
  liveLoading = true;
  try {
    const d = await apiGet('/api/admin/live.php');
    renderLiveOverall(d);
    renderLiveCounters(d);
    renderLiveStuck(d);
    renderLiveRacks(d);
    renderLiveControl(d);
    document.getElementById('liveUpdated').textContent =
      `Updated ${d.generated_at.slice(11, 16)} · refreshes every ${LIVE_REFRESH_MS / 1000} s`;
  } catch (e) {
    document.getElementById('liveUpdated').innerHTML = `<span class="hint-err">Could not refresh: ${escapeHtml(e.message)}</span>`;
  } finally {
    liveLoading = false;
  }
}

function renderLiveOverall(d) {
  const o = d.overall;
  const eta = o.remaining === 0 ? '✅ All counted'
    : o.eta_at ? `${o.eta_at} <span class="text-muted">(in ${minutesText(o.eta_minutes)})</span>`
    : '<span class="text-muted">— no address completed in the last hour</span>';
  const tiles = [
    [`${o.counted} / ${o.total}`, 'Addresses counted'],
    [o.remaining, 'Left to count'],
    [o.pace_per_hour, 'Addresses / hour (last hour)'],
    [o.counters_active, 'Counters active now'],
    [d.control_queue.waiting + d.control_queue.in_progress, 'At Control'],
    [o.sent_to_control_percent === null ? '—' : o.sent_to_control_percent + ' %', 'Sent to Control'],
  ];
  document.getElementById('liveOverall').innerHTML = `
    <div class="live-progress">
      <div class="live-percent">${o.percent} %</div>
      <div style="flex:1">${progressBar(o.percent)}
        <div class="hint">Estimated finish: <b>${eta}</b> · Final (OK or controlled): ${o.final}</div>
      </div>
    </div>
    <div class="stat-grid live-stats">${tiles.map(([n, l]) => `<div class="stat"><div class="num">${n}</div><div class="lbl">${l}</div></div>`).join('')}</div>`;
}

function renderLiveCounters(d) {
  const box = document.getElementById('liveCounters');
  if (!d.counters.length) { box.innerHTML = '<p class="text-muted">No counters yet.</p>'; return; }
  const medal = ['🥇', '🥈', '🥉'];
  const state = (c) => c.state === 'active' ? '<span class="badge badge-match">Active</span>'
    : c.state === 'idle' ? `<span class="badge badge-diff">Idle ${minutesText(c.idle_minutes)}</span>`
    : '<span class="badge badge-muted">Not started</span>';
  box.innerHTML = `<table>
    <thead><tr><th>#</th><th>Counter</th><th class="num-col">Addresses</th><th class="num-col">Last hour</th><th class="num-col">Lines</th>
      <th class="num-col">First-time OK</th><th>Now at</th><th>Status</th></tr></thead>
    <tbody>${d.counters.map((c, i) => `<tr class="${c.state === 'idle' ? 'row-warn' : ''}">
      <td class="rank">${c.addresses > 0 && i < 3 ? medal[i] : i + 1}</td>
      <td><b>${escapeHtml(c.name)}</b></td>
      <td class="num-col"><b>${c.addresses}</b></td>
      <td class="num-col">${c.last_hour}</td>
      <td class="num-col">${c.lines}</td>
      <td class="num-col">${c.ok_percent === null ? '—' : c.ok_percent + ' %'}</td>
      <td>${c.current_address ? escapeHtml(c.current_address) : '<span class="text-muted">—</span>'}</td>
      <td>${state(c)}</td></tr>`).join('')}</tbody></table>`;
}

function renderLiveStuck(d) {
  const box = document.getElementById('liveStuck');
  if (!d.stuck.length) {
    box.innerHTML = `<p class="hint-ok">No stuck address (none in progress without activity for ${d.settings.stuck_minutes} min).</p>`;
    return;
  }
  box.innerHTML = `<p class="hint">Started but no new entry for more than ${d.settings.stuck_minutes} min. Go and check whether the counter needs help.</p>` +
    d.stuck.map(s => `<div class="count-row"><div class="count-main"><div class="code">${escapeHtml(s.code)}</div>
      <div class="meta">Rack ${escapeHtml(s.rack)}${s.last_by ? ' · last entry by ' + escapeHtml(s.last_by) : ''}</div></div>
      <div class="count-side"><span class="badge badge-diff">No activity for ${minutesText(s.idle_minutes)}</span></div></div>`).join('');
}

function renderLiveRacks(d) {
  const box = document.getElementById('liveRacks');
  if (!d.racks.length) { box.innerHTML = '<p class="text-muted">No addresses imported yet.</p>'; return; }
  box.innerHTML = `<table>
    <thead><tr><th>Rack</th><th style="min-width:140px">Progress</th><th class="num-col">Counted</th><th class="num-col">Not started</th>
      <th class="num-col">In progress</th><th class="num-col">At Control</th><th class="num-col">Stuck</th><th class="num-col">Counters</th></tr></thead>
    <tbody>${d.racks.map(r => `<tr>
      <td><b>${escapeHtml(r.rack)}</b></td>
      <td>${progressBar(r.percent)}<div class="hint" style="margin-top:2px">${r.percent} %</div></td>
      <td class="num-col">${r.counted} / ${r.total}</td>
      <td class="num-col">${r.not_started}</td>
      <td class="num-col">${r.in_progress}</td>
      <td class="num-col">${r.waiting_control}</td>
      <td class="num-col">${r.stuck ? `<span class="badge badge-diff">${r.stuck}</span>` : 0}</td>
      <td class="num-col">${r.counters}</td></tr>`).join('')}</tbody></table>
    <p class="hint">Least advanced racks first. Rack = the part of the address before the first dash (A -01- 1 → A, R09-A-3 → R09).</p>`;
}

function renderLiveControl(d) {
  const q = d.control_queue;
  const items = q.items.slice(0, 15).map(i => `<div class="count-row"><div class="count-main"><div class="code">${escapeHtml(i.code)}</div>
    <div class="meta">${i.status === 'CONTROL_IN_PROGRESS' ? 'Control in progress' : 'Waiting for Control'}${i.counted_by ? ' · counted by ' + escapeHtml(i.counted_by) : ''}</div></div>
    <div class="count-side"><div class="meta">waiting</div><b>${minutesText(i.waiting_minutes)}</b></div></div>`).join('');
  const more = q.items.length > 15 ? `<p class="hint">+ ${q.items.length - 15} more</p>` : '';
  const ctrl = d.controllers.length
    ? `<p class="hint">Controlled so far: ${d.controllers.map(c => `${escapeHtml(c.name)} <b>${c.controlled}</b>`).join(' · ')}</p>` : '';
  document.getElementById('liveControl').innerHTML = `
    <div class="stat-grid live-stats">
      <div class="stat"><div class="num">${q.waiting}</div><div class="lbl">Waiting</div></div>
      <div class="stat"><div class="num">${q.in_progress}</div><div class="lbl">In progress</div></div>
      <div class="stat"><div class="num">${minutesText(q.oldest_minutes)}</div><div class="lbl">Oldest waiting</div></div>
    </div>${ctrl}${items || '<p class="hint-ok">Nothing waiting for Control.</p>'}${more}`;
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
  const isCsv = /\.(csv|txt)$/i.test(file.name);
  const reader = new FileReader();
  reader.onload = async (e) => {
    try {
      parsedRows = isCsv ? rowsFromCsv(e.target.result) : rowsFromWorkbook(new Uint8Array(e.target.result));
      const data = await apiPost('/api/admin/import.php', { rows: parsedRows, filename: parsedFilename, mode: 'preview' });
      renderPreview(data);
    } catch (err) {
      document.getElementById('importPreview').innerHTML = `<div class="banner err">${escapeHtml(err.message || String(err))}</div>`;
    }
  };
  if (isCsv) reader.readAsText(file); else reader.readAsArrayBuffer(file);
}

function rowsFromWorkbook(bytes) {
  const wb = XLSX.read(bytes, { type: 'array' });
  const sheet = wb.Sheets[wb.SheetNames[0]];
  // Codes come from the displayed text (keeps leading zeros like "000123"),
  // quantities from the real cell value (a "2,000"-formatted cell must stay
  // 2000, not become the text "2,000").
  const textRows = XLSX.utils.sheet_to_json(sheet, { defval: '', raw: false });
  const rawRows = XLSX.utils.sheet_to_json(sheet, { defval: '', raw: true });
  return textRows.map((r, i) => {
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
}

// CSV is read as plain text so codes keep their leading zeros (PN 002150030000,
// HU 0300747731). SAP exports with "," as separator write decimals as "1,2",
// which splits the quantity into two cells: when Quantity is the last column and
// a row has exactly one extra numeric cell, the two are joined back into 1.2.
function rowsFromCsv(text) {
  const lines = text.replace(/^\uFEFF/, '').split(/\r?\n/).filter(l => l.trim() !== '');
  if (!lines.length) return [];
  const sep = (lines[0].split(';').length > lines[0].split(',').length) ? ';' : ',';
  const split = (line) => {
    const out = []; let cur = ''; let q = false;
    for (let i = 0; i < line.length; i++) {
      const c = line[i];
      if (c === '"') { if (q && line[i + 1] === '"') { cur += '"'; i++; } else q = !q; }
      else if (c === sep && !q) { out.push(cur); cur = ''; }
      else cur += c;
    }
    out.push(cur);
    return out.map(v => v.trim());
  };
  const header = split(lines[0]);
  const qtyIdx = header.findIndex(h => ['quantity', 'qty', 'qte'].includes(h.toLowerCase().replace(/[^a-z0-9]/g, '')));
  return lines.slice(1).map(line => {
    let cells = split(line);
    let merged = null;
    if (sep === ',' && qtyIdx === header.length - 1 && cells.length === header.length + 1
        && /^\d+$/.test(cells[qtyIdx]) && /^\d+$/.test(cells[qtyIdx + 1])) {
      merged = parseFloat(cells[qtyIdx] + '.' + cells[qtyIdx + 1]);
      cells = cells.slice(0, qtyIdx + 1);
    }
    const obj = {};
    header.forEach((h, i) => { obj[h] = cells[i] ?? ''; });
    const r = normalizeRow(obj);
    return {
      address: String(r.address).trim(),
      hu: String(r.hu).trim(),
      part_number: String(r.part_number).trim(),
      unit: String(r.unit).trim(),
      quantity: merged !== null ? merged : String(r.quantity).trim(),
    };
  });
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
let addressRows = [];
const ADDRESS_STATUS_TEXT = {
  NOT_STARTED: 'Not started', IN_PROGRESS: 'In progress', COMPLETED_OK: 'Completed - OK',
  COMPLETED_CONTROL_REQUIRED: 'Control required', CONTROL_IN_PROGRESS: 'Control in progress', CONTROLLED: 'Controlled',
};

async function loadAddresses() {
  const status = document.getElementById('statusFilter').value;
  const data = await apiGet('/api/admin/addresses.php' + (status ? '?status=' + status : ''));
  addressRows = data.addresses;
  renderAddresses();
}

// Each row shows what the bin holds in SAP (active import) and what was counted,
// so wrong or old addresses stand out without opening every one.
function renderAddresses() {
  const q = document.getElementById('addressSearch').value.trim().toLowerCase();
  const rows = q ? addressRows.filter(a => a.code.toLowerCase().includes(q)) : addressRows;
  const notInFile = addressRows.filter(a => !a.in_stock_file).length;
  document.getElementById('addressSummary').innerHTML =
    `${rows.length} of ${addressRows.length} address(es)` +
    (notInFile ? ` · <span class="hint-err">${notInFile} not in the current stock file</span>` : '');
  const box = document.getElementById('addressList');
  if (!rows.length) { box.innerHTML = '<div class="card text-muted center">No addresses.</div>'; return; }
  box.innerHTML = rows.map(a => {
    const sap = a.sap_hus ? `SAP: ${a.sap_hus} HU · ${a.sap_pns} PN` : (a.in_stock_file ? 'SAP: empty bin' : '');
    const counted = a.counted_lines ? `Counted: ${a.counted_lines} line(s)` : 'Not counted yet';
    const warn = a.in_stock_file ? '' : ' <span class="badge badge-missing">Not in stock file</span>';
    return `
    <div class="list-item" onclick="viewAddress('${escapeHtml(a.code)}')">
      <span><span class="code">${escapeHtml(a.code)}</span>${warn}<br>
        <span class="meta">${escapeHtml([sap, counted].filter(Boolean).join(' · '))}</span></span>
      <span class="meta">${escapeHtml(ADDRESS_STATUS_TEXT[a.status] || a.status)}</span>
    </div>`;
  }).join('');
}

async function viewAddress(code) {
  const data = await apiGet('/api/admin/address_detail.php?code=' + encodeURIComponent(code));
  const lines = data.lines.map(l => `<tr>
      <td>${escapeHtml(l.hu || '(no HU)')}</td>
      <td>${l.expected_quantity !== null ? escapeHtml(l.expected_part_number) + ' — ' + escapeHtml(formatQty(l.expected_quantity)) + ' ' + escapeHtml(l.expected_unit) : '—'}</td>
      <td>${l.physical_quantity !== null ? escapeHtml(l.physical_part_number) + ' — ' + escapeHtml(formatQty(l.physical_quantity)) + ' ' + escapeHtml(l.physical_unit) : '—'}</td>
      <td>${escapeHtml(STATUS_TEXT[l.status] || l.status)}${l.action ? `<div class="hint">${escapeHtml(l.action)}</div>` : ''}</td>
    </tr>`).join('');
  const modal = document.createElement('div');
  modal.className = 'modal-backdrop';
  modal.innerHTML = `<div class="modal" style="max-width:700px">
    <h3 class="mt-0">${escapeHtml(code)} — ${escapeHtml(ADDRESS_STATUS_TEXT[data.address.status] || data.address.status)}</h3>
    ${data.lines.length ? '' : '<p class="text-muted">No SAP stock and nothing counted at this address.</p>'}
    <table><thead><tr><th>HU</th><th>SAP (PN — qty)</th><th>Counted (PN — qty)</th><th>Status</th></tr></thead><tbody>${lines}</tbody></table>
    <button class="btn-secondary btn-block" style="margin-top:14px">Close</button>
  </div>`;
  modal.querySelector('button').addEventListener('click', () => modal.remove());
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);
}

// ---------- Users ----------
const ROLE_TEXT = { entry: 'Data Entry', control: 'Control', admin: 'Admin' };
let userRows = [];

async function loadUsers() {
  const data = await apiGet('/api/admin/users.php');
  userRows = data.users;
  const box = document.getElementById('userList');
  box.innerHTML = userRows.map(u => `
    <div class="list-item" style="cursor:default;${u.active ? '' : 'opacity:.6'}">
      <span><span class="code">${escapeHtml(u.username)}</span>${u.active ? '' : ' <span class="badge badge-muted">Inactive</span>'}<br>
        <span class="meta">${escapeHtml(u.full_name)} · ${escapeHtml(ROLE_TEXT[u.role] || u.role)}</span></span>
      <span style="display:flex;gap:6px">
        <button class="btn-primary btn-sm" onclick="editUser(${u.id})">Edit</button>
        <button class="btn-secondary btn-sm" onclick="toggleUser(${u.id}, ${u.active ? 0 : 1})">${u.active ? 'Deactivate' : 'Activate'}</button>
      </span>
    </div>`).join('');
}

// Easy to read aloud / type on a phone: no 0/O or 1/l/I.
function generatePassword() {
  const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
  const bytes = new Uint32Array(8);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, b => chars[b % chars.length]).join('');
}

function editUser(id) {
  const u = userRows.find(x => x.id === id);
  if (!u) return;
  const modal = document.createElement('div');
  modal.className = 'modal-backdrop';
  modal.innerHTML = `<div class="modal">
    <h3 class="mt-0">Edit user: ${escapeHtml(u.username)}</h3>
    <label class="hint">Full name</label>
    <input type="text" id="euName" value="${escapeHtml(u.full_name)}">
    <label class="hint" style="display:block;margin-top:10px">Role</label>
    <select id="euRole">
      ${Object.entries(ROLE_TEXT).map(([v, t]) => `<option value="${v}" ${u.role === v ? 'selected' : ''}>${t}</option>`).join('')}
    </select>
    <label class="hint" style="display:block;margin-top:10px">New password (leave empty to keep the current one)</label>
    <div style="display:flex;gap:6px">
      <input type="text" id="euPass" autocomplete="off" placeholder="min. 6 characters" style="flex:1">
      <button class="btn-secondary btn-sm" id="euGen" type="button">Generate</button>
    </div>
    <div id="euMsg" class="hint hint-err"></div>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn-secondary" style="flex:1" id="euCancel">Cancel</button>
      <button class="btn-primary" style="flex:1" id="euSave">Save</button>
    </div>
  </div>`;
  const close = () => modal.remove();
  modal.querySelector('#euCancel').addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  modal.querySelector('#euGen').addEventListener('click', () => { modal.querySelector('#euPass').value = generatePassword(); });
  modal.querySelector('#euSave').addEventListener('click', async (ev) => {
    const full_name = modal.querySelector('#euName').value.trim();
    const role = modal.querySelector('#euRole').value;
    const password = modal.querySelector('#euPass').value.trim();
    const msg = modal.querySelector('#euMsg');
    if (!full_name) { msg.textContent = 'Full name is required.'; return; }
    if (password && password.length < 6) { msg.textContent = 'Password must be at least 6 characters.'; return; }
    const payload = { action: 'update', id, full_name, role };
    if (password) payload.password = password;
    ev.currentTarget.disabled = true;
    try {
      await apiPost('/api/admin/users.php', payload);
      close();
      if (password) alert(`Password changed.\n\nGive this to ${u.full_name} (${u.username}):\n\n${password}`);
      loadUsers();
    } catch (e) {
      msg.textContent = e.message;
      ev.currentTarget.disabled = false;
    }
  });
  document.body.appendChild(modal);
  modal.querySelector('#euName').focus();
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
  const u = userRows.find(x => x.id === id);
  if (!active && !confirm(`Deactivate ${u ? u.username : 'this user'}? They can no longer log in.`)) return;
  try {
    await apiPost('/api/admin/users.php', { action: 'update', id, active });
  } catch (e) { alert(e.message); }
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
