const PERMS = {
  admin: 'Full access — all modules, users & roles',
  manager: 'Dashboard, inventory, production, POs, orders, suppliers, reports',
  baker: 'Production, recipes, inventory (view), order queue',
  store: 'Inventory, POs, suppliers, wastage',
  customer: 'Shop, own orders & account only',
};
let _usersCache = [];

RENDER.users = async () => {
  _usersCache = await api('users');
  const rows = _usersCache.map(u => `<tr><td><div style="display:flex;gap:10px;align-items:center"><div class="av" style="width:30px;height:30px;font-size:12px;background:var(--brass);color:#fff;border-radius:50%;display:grid;place-items:center;font-weight:600">${u.name[0]}</div><div><b>${esc(u.name)}</b><br><span class="subtx">${esc(u.email)}</span></div></div></td>
    <td><span class="badge ${u.role === 'admin' ? 'b-bad' : u.role === 'customer' ? 'b-mut' : 'b-info'}">${ROLE_META[u.role].label}</span></td>
    <td class="mini">${PERMS[u.role]}</td>
    <td><span class="badge ${u.active ? 'b-good' : 'b-mut'}">${u.active ? 'Active' : 'Disabled'}</span></td>
    <td class="r" style="white-space:nowrap"><button class="btn btn-ghost btn-sm" onclick="uToggle(${u.id})">${u.active ? 'Disable' : 'Enable'}</button>
      <button class="btn btn-ghost btn-sm" onclick="uRole(${u.id})">Role</button></td></tr>`).join('');
  return `<div class="toolbar"><div class="grow"></div><button class="btn btn-primary btn-sm" onclick="mNewUser()"><span class="ic">＋</span>New user</button></div>
  <div class="card panel" style="padding-top:14px"><table><thead><tr><th>User</th><th>Role</th><th>Access scope</th><th>Status</th><th class="r"></th></tr></thead><tbody>${rows}</tbody></table></div>`;
};
async function uToggle(id) {
  try {
    const r = await api(`users/${id}/toggle`, { method: 'POST' });
    toast(r.active ? 'User enabled.' : 'User disabled.', 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function uRole(id) {
  const u = _usersCache.find(x => x.id === id);
  const opts = Object.keys(ROLE_META).map(r => `<option value="${r}" ${u.role === r ? 'selected' : ''}>${ROLE_META[r].label}</option>`).join('');
  modal(`<div class="modal-h"><h3>Change role — ${esc(u.name)}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="field"><label>Role</label><select id="mRole">${opts}</select></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="saveRole(${id})">Save</button></div>`);
}
async function saveRole(id) {
  try {
    await api(`users/${id}/role`, { method: 'POST', body: { role: v('mRole') } });
    closeModal();
    toast('Role updated.', 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function mNewUser() {
  const opts = Object.keys(ROLE_META).map(r => `<option value="${r}">${ROLE_META[r].label}</option>`).join('');
  modal(`<div class="modal-h"><h3>New user</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="field"><label>Full name *</label><input id="nuName"></div>
    <div class="field"><label>Email *</label><input id="nuEmail" type="email"></div>
    <div class="field"><label>Starting password *</label><input id="nuPass" type="password" placeholder="Share this with them separately"></div>
    <div class="field"><label>Role</label><select id="nuRole">${opts}</select></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="saveNewUser()">Create user</button></div>`);
}
async function saveNewUser() {
  const name = v('nuName').trim();
  if (!name) { toast('Name is required.', 'bad'); return; }
  try {
    await api('users', { method: 'POST', body: { name, email: v('nuEmail').trim(), password: v('nuPass'), role: v('nuRole') } });
    closeModal();
    toast(`${name} added.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
