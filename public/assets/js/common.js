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

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[m]));
}
