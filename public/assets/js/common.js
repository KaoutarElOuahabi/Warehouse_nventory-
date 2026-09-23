async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

async function apiGet(url) {
  const res = await fetch(url);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

async function requireSession(allowedRoles) {
  try {
    const data = await apiGet('/api/session.php');
    if (!data.authenticated) {
      window.location.href = '/';
      return null;
    }
    if (allowedRoles && !allowedRoles.includes(data.user.role)) {
      window.location.href = '/';
      return null;
    }
    return data.user;
  } catch (e) {
    window.location.href = '/';
    return null;
  }
}

async function doLogout() {
  try { await apiPost('/api/logout.php'); } catch (e) {}
  window.location.href = '/';
}

function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

// Mirrors UnitService::parseQuantityText() on the server — keep both in sync.
function parseQuantityText(text) {
  const v = String(text ?? '').trim();
  if (v === '') return { value: null, error: 'Quantity is required.' };
  if (v[0] === '-') return { value: null, error: 'Quantity cannot be negative.' };
  if (/^\d+$/.test(v)) return { value: Number(v), error: null };
  if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(v) && (v.includes(',') || v.split('.').length > 2)) {
    return { value: Number(v.replace(/\./g, '').replace(',', '.')), error: null };
  }
  if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v) && (v.includes('.') || v.split(',').length > 2)) {
    return { value: Number(v.replace(/,/g, '')), error: null };
  }
  const m = v.match(/^(\d+)[.,](\d+)$/);
  if (m) {
    if (m[2].length === 3 && /^[1-9]\d{0,2}$/.test(m[1])) {
      return { value: null, error: `"${v}" is ambiguous (thousands or decimals?). Type it without a thousands separator, e.g. 1500 or 1,5.` };
    }
    return { value: Number(m[1] + '.' + m[2]), error: null };
  }
  return { value: null, error: `"${v}" is not a valid quantity. Use digits only, with one decimal separator if needed (e.g. 12 or 2,5).` };
}

// Shows 2 as "2" (never "2.0000") and 2.2 in the device's own decimal style.
function formatQty(value) {
  if (value === null || value === undefined || value === '') return '';
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  return n.toLocaleString(undefined, { useGrouping: false, maximumFractionDigits: 4 });
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[m]));
}
