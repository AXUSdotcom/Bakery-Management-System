const INV_EDIT_ROLES = ['admin', 'manager', 'store'];

RENDER.inventory = async () => {
  const canEdit = INV_EDIT_ROLES.includes(SESSION.role);
  const [list, batches] = await Promise.all([
    api(`inventory?search=${encodeURIComponent(searchTxt)}&filter=${invFilter}`),
    api('inventory/batches'),
  ]);
  setNavPill('inventory', list.filter(i => i.statusClass !== 'b-good').length);

  const low = list.filter(i => i.stockOnHand < i.reorderLevel);
  const rows = list.map(i => {
    const pct = Math.min(100, Math.round(i.stockOnHand / (i.reorderLevel * 2) * 100));
    const col = i.statusClass === 'b-good' ? 'var(--fresh)' : i.statusClass === 'b-warn' ? 'var(--warn)' : 'var(--danger)';
    return `<tr><td><b>${esc(i.name)}</b><br><span class="subtx">${esc(i.supplierName || '—')}</span></td>
      <td class="num">${i.stockOnHand} ${i.uom}</td>
      <td><div class="lvl"><i style="width:${pct}%;background:${col}"></i></div></td>
      <td class="num">${i.reorderLevel} ${i.uom}</td>
      <td class="num">${i.daysCover > 30 ? '30+' : i.daysCover.toFixed(1)} d</td>
      <td class="num">${money(i.value).replace('Rs. ', '')}</td>
      <td><span class="badge ${i.statusClass}">${i.statusLabel}</span></td>
      ${canEdit ? `<td class="r" style="white-space:nowrap"><button class="btn btn-ghost btn-sm" onclick="mAddStock('${i.id}')">＋ Stock</button>
        <button class="btn btn-ghost btn-sm" onclick="mWaste('${i.id}')">Waste</button>
        ${i.stockOnHand < i.reorderLevel ? `<button class="btn btn-danger btn-sm" onclick="autoPO('${i.id}')">Raise PO</button>` : ''}</td>` : ''}</tr>`;
  }).join('');

  const invNotifs = NOTIFS.filter(n => n.category === 'inventory').slice(0, 6);
  const activeBatches = batches;

  return `
  <div class="grid" style="grid-template-columns:1fr 330px;margin-bottom:18px">
    <div>
      ${low.length ? `<div class="alert a-bad"><span>🤖</span><div><b>Auto-replenishment ready</b>${low.length} ingredient(s) below reorder: ${low.map(i => esc(i.name)).join(', ')}.</div>${canEdit ? `<button class="btn btn-danger btn-sm act" onclick="autoPOAll()">Raise all</button>` : ''}</div>` : ''}
      <div class="toolbar">
        <div class="search"><span class="ic">⌕</span><input placeholder="Search ingredients…" value="${esc(searchTxt)}" oninput="searchTxt=this.value.toLowerCase();refresh()"></div>
        <div class="seg"><button class="${invFilter === 'all' ? 'active' : ''}" onclick="invFilter='all';refresh()">All</button>
          <button class="${invFilter === 'low' ? 'active' : ''}" onclick="invFilter='low';refresh()">Below reorder</button>
          <button class="${invFilter === 'ok' ? 'active' : ''}" onclick="invFilter='ok';refresh()">Healthy</button></div>
        <div class="grow"></div>${canEdit ? `<button class="btn btn-primary btn-sm" onclick="mNewIngredient()"><span class="ic">＋</span>New ingredient</button>` : ''}
      </div>
      <div class="card tbl-wrap panel" style="padding-top:14px">
        <table><thead><tr><th>Ingredient</th><th class="r">In stock</th><th>Level</th><th class="r">Reorder</th><th class="r">Cover</th><th class="r">Value</th><th>Status</th>${canEdit ? '<th></th>' : ''}</tr></thead>
        <tbody>${rows || `<tr><td colspan="8"><div class="empty"><div class="ic">▦</div>No ingredients match.</div></td></tr>`}</tbody></table>
      </div>
    </div>
    <div class="card" style="align-self:start"><div class="card-h"><h3>🔔 Inventory alerts</h3><span class="grow"></span><button class="btn btn-ghost btn-sm" onclick="toggleNotif(true)">All</button></div>
      <div class="card-b" style="max-height:520px;overflow-y:auto">${invNotifs.length ? invNotifs.map(notifCard).join('') : `<div class="empty"><div class="ic">✓</div>No inventory alerts — stock is watching itself.</div>`}</div></div>
  </div>
  <div class="card panel">
    <div class="panel-h"><h3>Inventory batches</h3><span class="tag">first-expire-first-out</span><span class="sp"></span>
      <button class="btn btn-slate btn-sm" onclick="runExpiryJob()"><span class="ic">♺</span>Run expiry job</button></div>
    <table><thead><tr><th>Batch</th><th>Ingredient</th><th>Supplier</th><th class="r">On hand</th><th class="r">Unit cost</th><th>Expiry</th>${canEdit ? '<th></th>' : ''}</tr></thead>
    <tbody>${activeBatches.length ? activeBatches.map(b => `<tr><td class="num">${b.id}</td><td><b>${esc(b.ingredient_name)}</b></td><td>${esc(b.supplier_name || '—')}</td>
      <td class="num">${b.qty_on_hand} ${b.uom}</td><td class="num">${money(b.unit_cost).replace('Rs. ', '')}</td><td>${expiryPill(b.expiry_date)}</td>
      ${canEdit ? `<td class="r"><button class="btn btn-danger btn-sm" onclick="mWasteBatch('${b.id}')">Log waste</button></td>` : ''}</tr>`).join('') : `<tr><td colspan="7"><div class="empty">No active batches.</div></td></tr>`}</tbody></table>
  </div>`;
};

let _invSuppliers = null;
async function ensureSuppliersLoaded() {
  if (!_invSuppliers) _invSuppliers = await api('suppliers');
  return _invSuppliers;
}

async function mAddStock(id) {
  const list = await api(`inventory?filter=all`);
  const i = list.find(x => x.id === id);
  modal(`<div class="modal-h"><h3>Receive stock — ${esc(i.name)}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">
    <div class="row"><div class="field"><label>Quantity received (${i.uom})</label><input id="mQty" type="number" min="0" value="50"></div>
    <div class="field"><label>Days to expiry</label><input id="mExp" type="number" min="1" value="15"></div></div>
    <div class="field"><label>Note (batch / source)</label><input id="mNote" placeholder="e.g. delivery from ${esc(i.supplierName || 'supplier')}"></div>
  </div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-good" onclick="saveAddStock('${id}')">Add batch to stock</button></div>`);
}
async function saveAddStock(id) {
  const qty = +v('mQty') || 0;
  const days = +v('mExp') || 15;
  if (qty <= 0) { toast('Enter a quantity above zero.', 'bad'); return; }
  try {
    await api('inventory/receive', { method: 'POST', body: { ingredientId: id, qty, expiryDays: days } });
    closeModal();
    toast('Stock received.', 'good');
    refresh();
  } catch (e) { /* toasted */ }
}

async function mWaste(id) {
  const list = await api(`inventory?filter=all`);
  const i = list.find(x => x.id === id);
  modal(`<div class="modal-h"><h3>Record wastage — ${esc(i.name)}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="row"><div class="field"><label>Quantity wasted (${i.uom})</label><input id="mQty" type="number" step="0.1" min="0" value="1"></div>
    <div class="field"><label>Reason</label><select id="mReason"><option>Expired</option><option>Damaged/Spoiled</option><option>Over-Production</option><option>Prep-Loss/Spillage</option><option>Customer-Return</option></select></div></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-danger" onclick="saveWasteIng('${id}')">Record wastage</button></div>`);
}
async function saveWasteIng(id) {
  const qty = +v('mQty') || 0;
  const reason = v('mReason');
  if (qty <= 0) { toast('Enter a quantity above zero.', 'bad'); return; }
  try {
    await api('inventory/waste', { method: 'POST', body: { ingredientId: id, qty, reason } });
    closeModal();
    toast('Wastage recorded.', 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}

async function mWasteBatch(bid) {
  const batches = await api('inventory/batches');
  const b = batches.find(x => x.id === bid);
  modal(`<div class="modal-h"><h3>Log waste — ${b.id}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="kv"><span>Ingredient</span><b>${esc(b.ingredient_name)}</b></div><div class="kv"><span>On hand</span><b>${b.qty_on_hand} ${b.uom}</b></div>
    <div class="row" style="margin-top:12px"><div class="field"><label>Quantity</label><input id="mQty" type="number" step="0.1" min="0" value="${b.qty_on_hand}"></div>
    <div class="field"><label>Reason</label><select id="mReason"><option>Expired</option><option>Damaged/Spoiled</option><option>Over-Production</option><option>Prep-Loss/Spillage</option></select></div></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-danger" onclick="saveWasteBatch('${bid}')">Log waste</button></div>`);
}
async function saveWasteBatch(bid) {
  const qty = +v('mQty') || 0;
  const reason = v('mReason');
  try {
    await api('inventory/waste-batch', { method: 'POST', body: { batchId: bid, qty, reason } });
    closeModal();
    toast('Waste logged and costed.', 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}

async function runExpiryJob() {
  try {
    const r = await api('inventory/run-expiry-job', { method: 'POST' });
    toast(r.expiredCount ? `${r.expiredCount} expired batch(es) auto-logged as waste.` : 'No expired batches with stock — nothing to log.', r.expiredCount ? 'warn' : 'good');
    refresh();
  } catch (e) { /* toasted */ }
}

async function mNewIngredient() {
  const suppliers = await ensureSuppliersLoaded();
  const opts = suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  modal(`<div class="modal-h"><h3>New ingredient</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b"><div class="field"><label>Name *</label><input id="nName" placeholder="e.g. Cinnamon powder"></div>
    <div class="row"><div class="field"><label>Unit</label><select id="nUnit"><option>kg</option><option>L</option><option>pc</option></select></div>
    <div class="field"><label>Cost per unit (Rs)</label><input id="nCost" type="number" value="500"></div></div>
    <div class="row"><div class="field"><label>Opening stock</label><input id="nStock" type="number" value="0"></div>
    <div class="field"><label>Reorder level</label><input id="nReorder" type="number" value="5"></div></div>
    <div class="field"><label>Supplier</label><select id="nSup">${opts}</select></div></div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="saveNewIngredient()">Save ingredient</button></div>`);
}
async function saveNewIngredient() {
  const name = v('nName').trim();
  if (!name) { toast('Name is required.', 'bad'); return; }
  try {
    await api('inventory/ingredients', {
      method: 'POST',
      body: { name, uom: v('nUnit'), unitCost: +v('nCost') || 0, reorderLevel: +v('nReorder') || 0, supplierId: v('nSup'), openingStock: +v('nStock') || 0 },
    });
    closeModal();
    toast(`${name} added to inventory.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
