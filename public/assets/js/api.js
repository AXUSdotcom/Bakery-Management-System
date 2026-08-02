/* fetch() wrapper + tiny formatting helpers shared by every view module. */

/* Shared mutable UI state + the RENDER/AFTER registries every view module writes into.
   Declared here because api.js is the first script loaded. */
let SESSION = null, CURRENT = '', cart = {}, plan = {}, editRecipe = [];
let invFilter = 'all', poFilter = 'all', ordFilter = 'all', searchTxt = '';
const RENDER = {}, AFTER = {};

async function api(path, { method = 'GET', body } = {}) {
  const opts = { method, headers: {}, credentials: 'same-origin' };
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const res = await fetch('/api/' + path, opts);
  let json;
  try {
    json = await res.json();
  } catch (e) {
    json = { ok: false, error: 'Unexpected server response.' };
  }
  if (!json.ok) {
    const msg = json.error || 'Something went wrong.';
    if (res.status !== 401) toast(msg, 'bad');
    if (res.status === 401 && CURRENT !== '') { showLoggedOut(); }
    throw new Error(msg);
  }
  return json.data;
}

const money = n => 'Rs. ' + Number(n || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmt = n => 'Rs. ' + Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
const nowT = () => new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

/** Escapes free-text values before they're interpolated into innerHTML template strings. */
function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtWhen(iso) {
  if (!iso) return '—';
  const dt = new Date(String(iso).replace(' ', 'T'));
  if (isNaN(dt)) return iso;
  return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' ' +
    dt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function expiryPill(expDate) {
  const days = Math.round((new Date(expDate + 'T00:00:00') - new Date(new Date().toDateString())) / 864e5);
  if (days < 0) return `<span class="pill p-danger"><span class="d"></span>expired</span>`;
  if (days <= 2) return `<span class="pill p-danger"><span class="d"></span>${days}d left</span>`;
  if (days <= 7) return `<span class="pill p-warn"><span class="d"></span>${days}d left</span>`;
  return `<span class="pill p-fresh"><span class="d"></span>${days}d left</span>`;
}
