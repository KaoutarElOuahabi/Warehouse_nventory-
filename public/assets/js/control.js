let currentAddress = null;

(async function init() {
  const user = await requireSession(['control', 'admin']);
  if (!user) return;
  document.getElementById('queueScanBtn').addEventListener('click', () => openAddress(document.getElementById('queueScanInput').value.trim()));
  document.getElementById('queueScanInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') openAddress(e.target.value.trim()); });
  await loadQueue();
})();

async function loadQueue() {
  const data = await apiGet('/api/control/queue.php');
  document.getElementById('queueTitle').textContent = `CONTROL REQUIRED — ${data.count} ADDRESS${data.count === 1 ? '' : 'ES'}`;
  const list = document.getElementById('queueList');
  if (!data.count) {
    list.innerHTML = '<div class="card center text-muted">No addresses currently require control. 🎉</div>';
    return;
  }
  list.innerHTML = data.addresses.map(a => `
    <div class="list-item" onclick="openAddress('${escapeHtml(a.code)}')">
      <span><span class="code">${escapeHtml(a.code)}</span><br><span class="meta">${escapeHtml(a.issue_label)}</span></span>
      <span class="meta">${a.issues} issue${a.issues === 1 ? '' : 's'}</span>
    </div>`).join('');
}

async function openAddress(code) {
  if (!code) return;
  try {
    const data = await apiGet('/api/control/address.php?code=' + encodeURIComponent(code));
    currentAddress = data.address.code;
    document.getElementById('queueView').style.display = 'none';
    document.getElementById('addressView').style.display = 'block';
    document.getElementById('addrTitle').textContent = 'ADDRESS: ' + data.address.code;
    document.getElementById('observations').value = data.address.control_observations || '';
    renderLines(data.lines, data.summary);
  } catch (e) {
    alert(e.message);
  }
}

function backToQueue() {
  document.getElementById('addressView').style.display = 'none';
  document.getElementById('queueView').style.display = 'block';
  document.getElementById('queueScanInput').value = '';
  currentAddress = null;
  loadQueue();
}

function badgeFor(status) {
  const map = { MATCH: 'badge-match', QUANTITY_DIFFERENCE: 'badge-diff', MISSING: 'badge-missing', UNEXPECTED: 'badge-unexpected' };
  const label = { MATCH: 'MATCH', QUANTITY_DIFFERENCE: 'QTY DIFF', MISSING: 'MISSING', UNEXPECTED: 'UNEXPECTED' };
  return `<span class="badge ${map[status]}">${label[status]}</span>`;
}

function renderLines(lines, summary) {
  document.getElementById('addrSummary').textContent =
    `${summary.match} matching · ${summary.issues} issue(s) · ${summary.total} line(s) total`;
  const body = document.getElementById('linesBody');
  body.innerHTML = lines.map(l => {
    const expected = l.expected_quantity !== null ? `${l.expected_part_number} — ${l.expected_quantity} ${l.expected_unit}` : '<span class="text-muted">—</span>';
    const physical = l.physical_quantity !== null ? `${l.physical_part_number} — ${l.physical_quantity} ${l.physical_unit}` : '<span class="text-muted">—</span>';
    const rowClass = l.status === 'MATCH' ? 'match' : '';
    const huLabel = l.hu ? escapeHtml(l.hu) : (l.hu_not_available ? '(HU not available)' : '—');
    let actions = '';
    if (l.status !== 'MATCH') {
      if (l.physical_id) {
        actions += `<button class="btn-secondary btn-sm" onclick='openCorrect(${JSON.stringify(l)})'>Edit</button> `;
        actions += `<button class="btn-danger btn-sm" onclick="removeLine(${l.physical_id})">Remove</button> `;
      }
      actions += `<button class="btn-secondary btn-sm" onclick="confirmDifference(${JSON.stringify(huLabel)})">Confirm real</button>`;
    }
    return `<tr class="${rowClass}">
      <td>${huLabel}</td><td>${expected}</td><td>${physical}</td><td>${badgeFor(l.status)}</td>
      <td style="white-space:nowrap">${actions}</td>
    </tr>`;
  }).join('');
}

async function refreshCurrentLines() {
  const data = await apiGet('/api/control/address.php?code=' + encodeURIComponent(currentAddress));
  renderLines(data.lines, data.summary);
}

function openCorrect(line) {
  const hu = prompt('Handling Unit:', line.hu || '');
  if (hu === null) return;
  const pn = prompt('Part Number:', line.physical_part_number || '');
  if (pn === null) return;
  const unit = prompt('Unit:', line.physical_unit || '');
  if (unit === null) return;
  const qty = prompt('Quantity:', line.physical_quantity !== null ? line.physical_quantity : '');
  if (qty === null) return;
  updateLine('correct', { physical_id: line.physical_id, hu, part_number: pn, unit, quantity: qty });
}

async function removeLine(physicalId) {
  if (!confirm('Remove this physical count line? The original record stays in the audit trail.')) return;
  const note = prompt('Reason (optional):', '') || '';
  await updateLine('remove', { physical_id: physicalId, note });
}

async function confirmDifference(hu) {
  const note = prompt('Observation (optional):', 'Confirmed physical difference is real.') || '';
  await updateLine('confirm_difference', { hu, note });
}

async function addPhysical() {
  const hu = document.getElementById('addHu').value.trim();
  const pn = document.getElementById('addPn').value.trim();
  const unit = document.getElementById('addUnit').value.trim() || 'PCS';
  const qty = document.getElementById('addQty').value;
  if (!pn || !qty) { alert('Part Number and Quantity are required.'); return; }
  await updateLine('add', { hu, part_number: pn, unit, quantity: qty });
  document.getElementById('addHu').value = '';
  document.getElementById('addPn').value = '';
  document.getElementById('addUnit').value = '';
  document.getElementById('addQty').value = '';
}

async function updateLine(action, payload) {
  try {
    const data = await apiPost('/api/control/update_line.php', Object.assign({ address_code: currentAddress, action }, payload));
    renderLines(data.lines, data.summary);
  } catch (e) {
    alert(e.message);
  }
}

async function validateAddress() {
  const observations = document.getElementById('observations').value.trim();
  try {
    const res = await apiPost('/api/control/validate.php', { address_code: currentAddress, observations });
    alert(res.remaining_issues > 0
      ? `Address validated with ${res.remaining_issues} confirmed/unresolved difference(s) on record.`
      : 'Address validated. All differences resolved.');
    backToQueue();
  } catch (e) {
    alert(e.message);
  }
}
