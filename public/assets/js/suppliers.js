const SUP_EDIT_ROLES = ['admin', 'manager', 'store'];
let _suppliersCache = [];

RENDER.suppliers = async () => {
  const canEdit = SUP_EDIT_ROLES.includes(SESSION.role);
  _suppliersCache = await api('suppliers');
  const rows = _suppliersCache.map(s => `<tr><td><b>${esc(s.name)}</b></td><td class="mini">${esc(s.supplies_summary || '')}</td><td class="num">${esc(s.contact || '')}</td><td class="mini">${esc(s.email || '')}</td>
    <td><span class="badge b-info">${s.lead_days} day(s)</span></td><td class="num">${s.po_count} POs</td>
    ${canEdit ? `<td class="r" style="white-space:nowrap"><button class="btn btn-ghost btn-sm" onclick="mEditSupplier('${s.id}')">Edit</button>
      <button class="btn btn-danger btn-sm" onclick="removeSupplier('${s.id}')">Remove</button></td>` : ''}</tr>`).join('');
  return `<div class="toolbar"><div class="grow"></div>${canEdit ? `<button class="btn btn-primary btn-sm" onclick="mEditSupplier('')"><span class="ic">＋</span>New supplier</button>` : ''}</div>
  <div class="card panel" style="padding-top:14px"><table><thead><tr><th>Supplier</th><th>Supplies</th><th>Phone</th><th>Email</th><th>Lead time</th><th class="r">History</th>${canEdit ? '<th></th>' : ''}</tr></thead><tbody>${rows}</tbody></table></div>`;
};

function mEditSupplier(id) {
  const s = id ? _suppliersCache.find(x => x.id === id) : { name: '', contact: '', email: '', lead_days: 2, supplies_summary: '' };
  modal(`<div class="modal-h"><h3>${id ? 'Edit supplier' : 'New supplier'}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="field"><label>Company name *</label><input id="sName" value="${esc(s.name)}" placeholder="e.g. Ceylon Spices Ltd"></div>
    <div class="row"><div class="field"><label>Phone</label><input id="sContact" value="${esc(s.contact)}"></div>
    <div class="field"><label>Lead time (days)</label><input id="sLead" type="number" min="1" value="${s.lead_days}"></div></div>
    <div class="field"><label>Email</label><input id="sEmail" value="${esc(s.email)}"></div>
    <div class="field"><label>What they supply</label><input id="sItems" value="${esc(s.supplies_summary)}" placeholder="e.g. Spices, Nuts"></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="saveSupplier('${id}')">Save supplier</button></div>`);
}
async function saveSupplier(id) {
  const name = v('sName').trim();
  if (!name) { toast('Company name is required.', 'bad'); return; }
  try {
    await api('suppliers', { method: 'POST', body: { id: id || null, name, contact: v('sContact'), email: v('sEmail'), leadDays: +v('sLead') || 1, suppliesSummary: v('sItems') } });
    closeModal();
    toast(id ? 'Supplier updated.' : `${name} added as a supplier.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function removeSupplier(id) {
  const s = _suppliersCache.find(x => x.id === id);
  modal(`<div class="modal-h"><h3>Remove ${esc(s.name)}?</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">PO history is kept, but no new POs can be raised to this supplier.</div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Keep</button>
    <button class="btn btn-danger" onclick="doRemoveSupplier('${id}')">Remove supplier</button></div>`);
}
async function doRemoveSupplier(id) {
  try {
    await api(`suppliers/${id}/remove`, { method: 'POST' });
    closeModal();
    toast('Supplier removed.', 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}
