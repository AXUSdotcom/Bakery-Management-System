/* Boot, session bootstrap, nav, view router — the client-side counterpart of
   the prototype's go()/buildNav()/login()/logout(). RBAC here is UI-only
   convenience; every action is re-checked server-side. */

const ROLE_META = {
  admin: { label: 'Administrator' }, manager: { label: 'Manager' }, baker: { label: 'Baker' },
  store: { label: 'Storekeeper' }, customer: { label: 'Customer' },
};
const NAV = {
  dashboard: { ic: '▤', label: 'Dashboard', grp: 'Overview' },
  inventory: { ic: '▦', label: 'Inventory', grp: 'Operations' },
  production: { ic: '⚖', label: 'Production plan', grp: 'Operations' },
  orders: { ic: '☰', label: 'Customer orders', grp: 'Operations' },
  purchase: { ic: '⛿', label: 'Purchase orders', grp: 'Supply chain' },
  suppliers: { ic: '🚚', label: 'Suppliers', grp: 'Supply chain' },
  products: { ic: '❏', label: 'Products & recipes', grp: 'Catalogue' },
  wastage: { ic: '♺', label: 'Wastage log', grp: 'Administration' },
  users: { ic: '🛡', label: 'Users & roles', grp: 'Administration' },
  notifications: { ic: '🔔', label: 'Notifications', grp: 'Administration' },
  audit: { ic: '⧉', label: 'Audit log', grp: 'Administration' },
  shop: { ic: '🥐', label: 'Order bakery', grp: 'Shop' },
  myorders: { ic: '☰', label: 'My orders', grp: 'Shop' },
  account: { ic: '👤', label: 'My account', grp: 'Shop' },
};
const TITLES = {
  dashboard: 'Live business health', inventory: 'Self-watching stock with automatic alerts & reordering',
  production: "Enter today's bake — ingredients are checked live",
  orders: 'Click any order for full details, dispatch & delivery tracking',
  purchase: 'Review → approve → track → receive · auto-raised on low stock',
  suppliers: 'Vendors, lead times and contacts', products: 'Create, edit and remove items and their ingredient recipes',
  wastage: 'Track and reduce ingredient losses', users: 'Who can see and do what',
  notifications: 'Everything the system flagged for your role', audit: 'Read-only record of every action',
  shop: 'Baked daily · delivered to your saved address', myorders: 'Track deliveries and reorder your favourites',
  account: 'Delivery address, payment & loyalty',
};

const allowed = view => SESSION && (SESSION.permissions || []).includes(view);

function setNavPill(view, n) {
  const e = document.getElementById('navpill-' + view);
  if (!e) return;
  if (n) { e.style.display = 'inline-block'; e.textContent = n; } else { e.style.display = 'none'; }
}

function buildNav() {
  const views = SESSION.permissions;
  const groups = {};
  views.forEach(v => { const g = NAV[v].grp; (groups[g] = groups[g] || []).push(v); });
  let h = '';
  for (const g in groups) {
    h += `<div class="grp">${g}</div>`;
    groups[g].forEach(v => {
      const n = NAV[v];
      h += `<a data-v="${v}" onclick="go('${v}')"><span class="ic">${n.ic}</span>${n.label}<span class="badge-n" id="navpill-${v}" style="display:none"></span></a>`;
    });
  }
  document.getElementById('nav').innerHTML = h;
}

async function go(view) {
  if (!allowed(view)) { renderDeny(); return; }
  CURRENT = view;
  searchTxt = '';
  document.querySelectorAll('.side a').forEach(a => a.classList.toggle('active', a.dataset.v === view));
  document.getElementById('title').textContent = NAV[view].label;
  document.getElementById('crumb').textContent = NAV[view].grp;
  document.getElementById('view').innerHTML = `<div class="empty">Loading…</div>`;
  try {
    document.getElementById('view').innerHTML = await RENDER[view]();
    if (AFTER[view]) AFTER[view]();
  } catch (e) { /* api() already toasted the error */ }
  updateCartFab();
  document.querySelector('.main').scrollTop = 0;
}
function refresh() { go(CURRENT); }
function renderDeny() {
  document.getElementById('view').innerHTML =
    `<div class="deny">⛔ Your role (${ROLE_META[SESSION.role].label}) does not have permission to open this section.</div>`;
}

function showRegister() { document.getElementById('loginBox').style.display = 'none'; document.getElementById('registerBox').style.display = 'block'; }
function showLogin() { document.getElementById('registerBox').style.display = 'none'; document.getElementById('loginBox').style.display = 'block'; }

async function doLogin() {
  const email = v('loginEmail').trim(), pass = v('loginPass');
  if (!email || !pass) { toast('Enter your email and password.', 'bad'); return; }
  try {
    const user = await api('auth/login', { method: 'POST', body: { email, password: pass } });
    afterLogin(user);
  } catch (e) { /* toasted */ }
}

async function doRegister() {
  const body = { name: v('rName').trim(), phone: v('rPhone').trim(), email: v('rEmail').trim(), address: v('rAddr').trim(), password: v('rPass') };
  try {
    const user = await api('auth/register', { method: 'POST', body });
    toast('Account created — your address is saved for checkout.', 'good');
    afterLogin(user);
  } catch (e) { /* toasted */ }
}

async function afterLogin(user) {
  SESSION = user;
  document.getElementById('login').style.display = 'none';
  document.getElementById('app').classList.add('on');
  document.getElementById('whoName').textContent = user.name;
  document.getElementById('whoRole').textContent = ROLE_META[user.role].label;
  document.getElementById('avatar').textContent = user.name[0];
  document.getElementById('dateChip').textContent = '📅 ' + new Date().toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  buildNav();
  await loadNotifications();
  go(user.home);
  toast(`Welcome, ${user.name.split(' ')[0]} — ${ROLE_META[user.role].label} portal.`, 'good');
  startPolling();
}

async function logout() {
  try { await api('auth/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
  stopPolling();
  SESSION = null; cart = {}; plan = {}; CURRENT = '';
  toggleNotif(false);
  document.getElementById('app').classList.remove('on');
  document.getElementById('login').style.display = 'flex';
  showLogin();
}

function showLoggedOut() {
  if (!SESSION) return;
  stopPolling();
  SESSION = null;
  document.getElementById('app').classList.remove('on');
  document.getElementById('login').style.display = 'flex';
  showLogin();
  toast('Your session ended — please sign in again.', 'warn');
}

let pollTimer = null;
function startPolling() {
  stopPolling();
  pollTimer = setInterval(() => { loadNotifications().catch(() => {}); }, 30000);
}
function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

(async function boot() {
  try {
    const user = await api('auth/me');
    afterLogin(user);
  } catch (e) {
    showLogin();
  }
})();
