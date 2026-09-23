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

const STATUS_BADGES = {
  MATCH: ['badge-match', 'MATCH'],
  QUANTITY_DIFFERENCE: ['badge-diff', 'QTY DIFF'],
  PN_DIFFERENCE: ['badge-diff', 'PN DIFF'],
  MISSING: ['badge-missing', 'MISSING'],
  UNEXPECTED: ['badge-unexpected', 'UNEXPECTED'],
  WRONG_LOCATION: ['badge-unexpected', 'WRONG LOCATION'],
  HU_LABEL_MISSING: ['badge-diff', 'NO HU LABEL'],
};

function badgeFor(status) {
  const [cls, label] = STATUS_BADGES[status] || ['badge-diff', status];
  return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
}

function qtyCell(pn, qty, unit) {
  if (qty === null || qty === undefined) return '<span class="text-muted">—</span>';
  return `${escapeHtml(pn)} — <b>${escapeHtml(formatQty(qty))}</b> ${escapeHtml(unit)}`;
}

function renderLines(lines, summary) {
  document.getElementById('addrSummary').textContent =
    `${summary.match} matching · ${summary.issues} issue(s) · ${summary.total} line(s) total`;
  currentLines = [];
  const body = document.getElementById('linesBody');
  body.innerHTML = lines.map(l => {
    const expected = qtyCell(l.expected_part_number, l.expected_quantity, l.expected_unit)
      + (l.sap_address ? `<div class="hint">SAP address: ${escapeHtml(l.sap_address)}</div>` : '');
    const physical = qtyCell(l.physical_part_number, l.physical_quantity, l.physical_unit)
      + (l.found_at ? `<div class="hint">Counted at: ${escapeHtml(l.found_at)}</div>` : '');
    const rowClass = l.status === 'MATCH' ? 'match' : '';
    const huLabel = escapeHtml(l.hu || '') + (l.hu_not_available ? `${l.hu ? '<br>' : ''}<span class="hint">(no HU label)</span>` : '') || '—';
    let actions = '';
    if (l.status !== 'MATCH') {
      const i = currentLines.push(l) - 1;
      if (l.physical_id) {
        actions += `<button class="btn-secondary btn-sm" data-act="edit" data-i="${i}">Edit</button> `;
        actions += `<button class="btn-danger btn-sm" data-act="remove" data-i="${i}">Remove</button> `;
      }
      actions += `<button class="btn-secondary btn-sm" data-act="confirm" data-i="${i}">Confirm real</button>`;
    }
    return `<tr class="${rowClass}">
      <td>${huLabel}</td><td>${expected}</td><td>${physical}</td>
      <td>${badgeFor(l.status)}${l.action ? `<div class="hint">${escapeHtml(l.action)}</div>` : ''}</td>
      <td style="white-space:nowrap">${actions}</td>
    </tr>`;
  }).join('');
}

let currentLines = [];
document.getElementById('linesBody').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const l = currentLines[Number(btn.dataset.i)];
  if (!l) return;
  if (btn.dataset.act === 'edit') openCorrect(l);
  if (btn.dataset.act === 'remove') removeLine(l.physical_id);
  if (btn.dataset.act === 'confirm') confirmDifference(l.hu || '(no HU label)');
});

async function refreshCurrentLines() {
  const data = await apiGet('/api/control/address.php?code=' + encodeURIComponent(currentAddress));
  renderLines(data.lines, data.summary);
}

function openCorrect(line) {
  const hu = prompt('Handling Unit:', line.hu || '');
  if (hu === null) return;
  const pn = prompt('Part Number (unit is taken from master data):', line.physical_part_number || '');
  if (pn === null) return;
  const qty = prompt(`Quantity (${line.physical_unit}):`, formatQty(line.physical_quantity));
  if (qty === null) return;
  const parsed = parseQuantityText(qty);
  if (parsed.error) { alert(parsed.error); return; }
  updateLine('correct', { physical_id: line.physical_id, hu: hu.trim(), part_number: pn.trim(), quantity: qty.trim() });
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
  const qty = document.getElementById('addQty').value.trim();
  if (!pn || !qty) { alert('Part Number and Quantity are required.'); return; }
  const parsed = parseQuantityText(qty);
  if (parsed.error) { alert(parsed.error); return; }
  if (await updateLine('add', { hu, part_number: pn, quantity: qty })) {
    document.getElementById('addHu').value = '';
    document.getElementById('addPn').value = '';
    document.getElementById('addQty').value = '';
  }
}

async function updateLine(action, payload) {
  try {
    const data = await apiPost('/api/control/update_line.php', Object.assign({ address_code: currentAddress, action }, payload));
    renderLines(data.lines, data.summary);
    return true;
  } catch (e) {
    alert(e.message);
    return false;
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
