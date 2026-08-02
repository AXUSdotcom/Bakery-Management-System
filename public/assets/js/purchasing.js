const PO_ACT_ROLES = ['admin', 'manager', 'store'];

RENDER.purchase = async () => {
  const canAct = PO_ACT_ROLES.includes(SESSION.role);
  const [pos, invList] = await Promise.all([api(`purchase?status=${poFilter}`), api('inventory?filter=low')]);
  const low = invList;
  setNavPill('purchase', pos.filter(p => p.status === 'Draft' || p.status === 'Sent').length);

  const rows = pos.map(p => {
    const cls = p.status === 'Draft' ? 'b-mut' : p.status === 'Sent' ? 'b-info' : p.status === 'Cancelled' ? 'b-bad' : 'b-good';
    return `<tr><td class="num"><b>${p.id}</b><br><span class="subtx">${fmtWhen(p.created_at)}</span></td>
      <td><b>${esc(p.supplier_name)}</b><br><span class="subtx">lead ${p.lead_days} day(s)</span></td>
      <td class="mini" style="max-width:260px">${esc(p.items_summary || '')}</td><td class="num">${money(p.total).replace('Rs. ', '')}</td>
      <td class="mini">${p.status === 'Sent' ? '+' + p.eta_days + ' days' : p.status === 'Received' ? 'received' : '—'}</td>
      <td><span class="badge ${cls}">${p.status}</span></td>
      ${canAct ? `<td class="r" style="white-space:nowrap">
        ${p.status === 'Draft' ? `<button class="btn btn-primary btn-sm" onclick="previewPO('${p.id}')">Review &amp; send</button> <button class="btn btn-ghost btn-sm" onclick="cancelPO('${p.id}')">Cancel</button>` : ''}
        ${p.status === 'Sent' ? `<button class="btn btn-good btn-sm" onclick="doReceivePO('${p.id}')">Mark received</button> <button class="btn btn-ghost btn-sm" onclick="cancelPO('${p.id}')">Cancel</button>` : ''}
        ${p.status === 'Received' ? '<span class="subtx">stock updated ✓</span>' : ''}
        ${p.status === 'Cancelled' ? '<span class="subtx">cancelled</span>' : ''}</td>` : ''}</tr>`;
  }).join('');

  return `
  ${low.length ? `<div class="card panel" style="margin-bottom:18px;border-left:3px solid var(--danger)">
    <div class="panel-h"><h3>Reorder needed</h3><span class="tag">below reorder point</span><span class="sp"></span>
      ${canAct ? `<button class="btn btn-primary btn-sm" onclick="autoPOAll()"><span class="ic">⚙</span>Generate draft POs</button>` : ''}</div>
    <table><thead><tr><th>Ingredient</th><th class="r">On hand</th><th class="r">Reorder pt</th><th>Supplier</th></tr></thead><tbody>
    ${low.map(i => `<tr><td><b>${esc(i.name)}</b></td><td class="num">${i.stockOnHand} ${i.uom}</td><td class="num">${i.reorderLevel}</td><td>${esc(i.supplierName || '—')}</td></tr>`).join('')}
    </tbody></table></div>` : ''}
  <div class="alert a-info"><span>🤖</span><div><b>Automation</b>Low stock raises a draft PO sized to restore 2× the reorder level. You always review before it goes out, and can cancel even after sending — until goods are received.</div></div>
  <div class="toolbar"><div class="seg">${['all', 'Draft', 'Sent', 'Received', 'Cancelled'].map(f => `<button class="${poFilter === f ? 'active' : ''}" onclick="poFilter='${f}';refresh()">${f === 'all' ? 'All' : f}</button>`).join('')}</div>
    <div class="grow"></div>${canAct && low.length ? `<button class="btn btn-danger btn-sm" onclick="autoPOAll()">⚡ Raise POs · ${low.length} low item(s)</button>` : ''}</div>
  <div class="card panel" style="padding-top:14px"><table><thead><tr><th>PO</th><th>Supplier</th><th>Items</th><th class="r">Value</th><th>ETA</th><th>Status</th>${canAct ? '<th></th>' : ''}</tr></thead>
    <tbody>${rows || `<tr><td colspan="7"><div class="empty"><div class="ic">⛿</div>No purchase orders in this view.</div></td></tr>`}</tbody></table></div>`;
};

async function autoPO(ingredientId) {
  try {
    await api('purchase/auto', { method: 'POST', body: { ingredientId } });
    toast('Draft PO created — review before sending.', 'good');
    if (allowed('purchase')) go('purchase'); else refresh();
  } catch (e) { /* toasted */ }
}
async function autoPOAll() {
  try {
    const r = await api('purchase/auto-all', { method: 'POST' });
    if (!r.poIds.length) { toast('Nothing below reorder point.', 'good'); return; }
    toast('Draft POs raised, grouped by supplier. Review before sending.', 'good');
    go('purchase');
  } catch (e) { /* toasted */ }
}

async function previewPO(id) {
  const p = await api(`purchase/${id}/preview`);
  const lines = p.lines.map(l => `<tr><td>${esc(l.ingredient_name)}</td><td class="num">${l.qty} ${l.uom}</td><td class="num">${money(l.unit_cost).replace('Rs. ', '')}</td><td class="num">${money(l.qty * l.unit_cost).replace('Rs. ', '')}</td></tr>`).join('');
  modal(`<div class="modal-h"><h3>Purchase order · ${p.id}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">
    <div class="kv"><span>Supplier</span><b>${esc(p.supplier_name)}</b></div>
    <div class="kv"><span>Send to</span><b>${esc(p.supplier_email)}</b></div>
    <div class="kv"><span>Expected delivery</span><b>${p.lead_days} day(s) after sending</b></div>
    <div class="card tbl-wrap" style="margin-top:14px"><table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Unit cost</th><th class="r">Line total</th></tr></thead>
    <tbody>${lines}<tr><td colspan="3" class="r"><b>Total</b></td><td class="num"><b style="color:var(--brass)">${money(p.total).replace('Rs. ', '')}</b></td></tr></tbody></table></div>
  </div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Back</button>
    <button class="btn btn-danger" onclick="closeModal();cancelPO('${p.id}')">Cancel PO</button>
    <button class="btn btn-primary" onclick="sendPO('${p.id}')"><span class="ic">✓</span>Approve &amp; send</button></div>`, true);
}
async function sendPO(id) {
  try {
    await api(`purchase/${id}/send`, { method: 'POST' });
    closeModal();
    toast(`${id} sent to supplier.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function cancelPO(id) {
  modal(`<div class="modal-h"><h3>Cancel ${id}?</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">If this PO was already sent, the supplier will be notified of the cancellation.</div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Keep PO</button>
    <button class="btn btn-danger" onclick="doCancelPO('${id}')">Yes, cancel PO</button></div>`);
}
async function doCancelPO(id) {
  try {
    await api(`purchase/${id}/cancel`, { method: 'POST' });
    closeModal();
    toast(`${id} cancelled.`, 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}
async function doReceivePO(id) {
  try {
    await api(`purchase/${id}/receive`, { method: 'POST' });
    toast(`${id} received — inventory updated automatically.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
