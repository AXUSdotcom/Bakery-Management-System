let NOTIFS = [];
const CAT_ICON = { inventory: '♺', orders: '☰', purchasing: '⛿', production: '⚖', catalogue: '❏' };

async function loadNotifications() {
  NOTIFS = await api('notifications');
  updateBell();
}

function unreadN() {
  return NOTIFS.filter(n => !n.is_read).length;
}

function updateBell() {
  const b = document.getElementById('belldot');
  const n = unreadN();
  b.style.display = n ? 'grid' : 'none';
  b.textContent = n;
  setNavPill('notifications', n);
  renderNotifList();
  if (CURRENT === 'notifications') refresh();
}

function notifCard(n) {
  const cls = { bad: 'p-danger', good: 'p-fresh', warn: 'p-warn', info: 'p-slate' }[n.type] || 'p-slate';
  return `<div class="notif ${n.is_read ? '' : 'unread'}"><div class="ic">${n.icon || CAT_ICON[n.category] || '●'}</div>
    <div style="flex:1"><b>${esc(n.title)}</b><p>${esc(n.message)}</p><div class="tm">${fmtWhen(n.created_at)} · ${n.category}</div></div>
    ${n.is_read ? '' : `<button class="btn btn-ghost btn-sm" onclick="markRead(${n.id})">✓</button>`}</div>`;
}

function renderNotifList() {
  const e = document.getElementById('notifList');
  if (!e) return;
  e.innerHTML = NOTIFS.length ? NOTIFS.map(notifCard).join('') : `<div class="empty"><div class="ic">✓</div>All quiet.</div>`;
}

async function markRead(id) {
  await api(`notifications/${id}/read`, { method: 'POST' });
  await loadNotifications();
}

async function markAllRead() {
  await api('notifications/read-all', { method: 'POST' });
  await loadNotifications();
}

function toggleNotif(open) {
  const dr = document.getElementById('notifDrawer');
  dr.classList.toggle('open', open === undefined ? !dr.classList.contains('open') : open);
  renderNotifList();
}

RENDER.notifications = async () => {
  const rows = NOTIFS;
  return `<div class="card panel">
    <div class="panel-h"><h3>Notifications</h3><span class="tag">${unreadN()} unread</span><span class="sp"></span>
      <button class="btn btn-ghost btn-sm" onclick="toggleNotif(true)">Open drawer</button>
      <button class="btn btn-ghost btn-sm" onclick="markAllRead()">Mark all read</button></div>
    ${rows.length ? rows.map(n => `<div style="display:flex;align-items:center;gap:14px;padding:14px 4px;border-bottom:1px solid var(--line-2)">
      <div style="width:36px;height:36px;border-radius:9px;background:${n.is_read ? 'var(--line-2)' : 'var(--brass-tint)'};color:${n.is_read ? 'var(--muted)' : 'var(--brass)'};display:grid;place-items:center;font-size:16px">${n.icon || CAT_ICON[n.category] || '●'}</div>
      <div style="flex:1"><div style="font-size:14px;${n.is_read ? 'color:var(--muted)' : 'font-weight:500'}">${esc(n.title)}</div><div class="mini">${esc(n.message)} · ${fmtWhen(n.created_at)} · ${n.category}</div></div>
      ${!n.is_read ? `<button class="btn btn-ghost btn-sm" onclick="markRead(${n.id})">Clear</button>` : '<span class="pill p-fresh"><span class="d"></span>read</span>'}</div>`).join('') : `<div class="empty"><div class="ic">✓</div>No notifications.</div>`}
  </div>`;
};
