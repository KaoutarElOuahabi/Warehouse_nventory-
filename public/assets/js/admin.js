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
      const json = XLSX.utils.sheet_to_json(sheet, { defval: '' });
      parsedRows = json.map(r => normalizeRow(r));
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

function renderPreview(data) {
  const box = document.getElementById('importPreview');
  let html = `<div class="banner ${data.error_count ? 'warn' : 'ok'}">${data.valid_rows} valid row(s), ${data.error_count} issue(s)</div>`;
  if (data.errors.length) {
    html += '<div class="card" style="max-height:200px;overflow:auto"><ul>' + data.errors.map(e => `<li>${escapeHtml(e)}</li>`).join('') + '</ul></div>';
  }
  box.innerHTML = html;
  document.getElementById('commitBtn').style.display = data.valid_rows > 0 ? 'block' : 'none';
}

async function onCommitImport() {
  if (!parsedRows) return;
  if (!confirm('This replaces the currently active stock snapshot. Continue?')) return;
  try {
    const res = await apiPost('/api/admin/import.php', { rows: parsedRows, filename: parsedFilename, mode: 'commit' });
    alert(`Imported ${res.imported_rows} row(s). ${res.error_count} issue(s) skipped.`);
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
      <td>${l.expected_quantity !== null ? escapeHtml(l.expected_part_number) + ' — ' + l.expected_quantity + ' ' + l.expected_unit : '—'}</td>
      <td>${l.physical_quantity !== null ? escapeHtml(l.physical_part_number) + ' — ' + l.physical_quantity + ' ' + l.physical_unit : '—'}</td>
      <td>${escapeHtml(l.status)}</td>
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
