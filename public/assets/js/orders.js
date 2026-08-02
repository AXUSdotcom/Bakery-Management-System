const ORD_STEPS = ['Pending', 'Preparing', 'Ready', 'Out for delivery', 'Delivered'];
let _ordersCache = [];

RENDER.orders = async () => {
  _ordersCache = await api(`orders?status=${ordFilter}`);
  setNavPill('orders', _ordersCache.filter(o => o.status === 'Pending').length);
  const rows = _ordersCache.map(o => {
    const cls = o.status === 'Delivered' ? 'b-good' : o.status === 'Pending' ? 'b-warn' : o.status === 'Cancelled' ? 'b-bad' : 'b-info';
    return `<tr class="clickable" onclick="orderDetail('${o.id}',true)"><td class="num"><b>${o.id}</b><br><span class="subtx">${fmtWhen(o.created_at)} · ${o.order_type} · ${o.mode}</span></td>
      <td><b>${esc(o.customer_name)}</b><br><span class="subtx">${esc(o.phone || '')}</span></td><td class="mini">${o.items_summary || ''}</td><td class="num">${money(o.total).replace('Rs. ', '')}</td>
      <td><span class="badge ${cls}">${o.status}</span></td>
      <td class="r" onclick="event.stopPropagation()" style="white-space:nowrap">${staffOrderActions(o)}</td></tr>`;
  }).join('');
  return `<div class="toolbar"><div class="seg">${['all', ...ORD_STEPS, 'Cancelled'].map(f => `<button class="${ordFilter === f ? 'active' : ''}" onclick="ordFilter='${f}';refresh()">${f === 'all' ? 'All' : f}</button>`).join('')}</div></div>
  <div class="card panel" style="padding-top:14px"><table><thead><tr><th>Order</th><th>Customer</th><th>Items</th><th class="r">Total</th><th>Status</th><th class="r">Action</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="6"><div class="empty"><div class="ic">☰</div>No orders in this view.</div></td></tr>`}</tbody></table></div>`;
};

function staffOrderActions(o) {
  if (o.status === 'Pending') return `<button class="btn btn-primary btn-sm" onclick="ordAdvance('${o.id}','Preparing')">Confirm</button> <button class="btn btn-ghost btn-sm" onclick="staffCancel('${o.id}')">Cancel</button>`;
  if (o.status === 'Preparing') return `<button class="btn btn-good btn-sm" onclick="ordAdvance('${o.id}','Ready')">Mark ready</button>`;
  if (o.status === 'Ready') return o.mode === 'Delivery' ? `<button class="btn btn-info btn-sm" onclick="mDispatch('${o.id}')">🛵 Dispatch</button>` : `<button class="btn btn-good btn-sm" onclick="ordAdvance('${o.id}','Delivered')">Handover</button>`;
  if (o.status === 'Out for delivery') return `<button class="btn btn-good btn-sm" onclick="ordAdvance('${o.id}','Delivered')">✓ Delivered</button>`;
  return '<span class="subtx">—</span>';
}
async function ordAdvance(id, toStatus) {
  try {
    await api(`orders/${id}/advance`, { method: 'POST', body: { toStatus } });
    closeModal();
    toast(`${id} → ${toStatus}`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function staffCancel(id) {
  modal(`<div class="modal-h"><h3>Cancel ${id}?</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">This order will be cancelled and its shelf stock returned.</div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Keep order</button>
    <button class="btn btn-danger" onclick="doStaffCancel('${id}')">Yes, cancel order</button></div>`);
}
async function doStaffCancel(id) {
  try {
    await api(`orders/${id}/staff-cancel`, { method: 'POST' });
    closeModal();
    toast(`${id} cancelled.`, 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}
function mDispatch(id) {
  const o = _ordersCache.find(x => x.id === id);
  modal(`<div class="modal-h"><h3>🛵 Dispatch ${id}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">
    <div class="kv"><span>Deliver to</span><b>${esc(o.customer_name)}</b></div>
    <div class="kv"><span>Address</span><b style="max-width:300px">${esc(o.address || '')}</b></div>
    <div class="row" style="margin-top:12px"><div class="field"><label>Delivery person *</label><input id="dName" placeholder="e.g. Ruwan J."></div>
    <div class="field"><label>Rider phone *</label><input id="dPhone" placeholder="07X-XXXXXXX"></div></div>
    <div class="row"><div class="field"><label>Vehicle type</label><select id="dVType"><option>Motorbike</option><option>Three-wheeler</option><option>Van</option><option>Bicycle</option></select></div>
    <div class="field"><label>Vehicle number *</label><input id="dVNo" placeholder="e.g. WP BAQ-4521"></div></div>
    <div class="field"><label>Expected delivery time</label><input id="dEta" value="Within 45 min"></div>
    <div class="alert a-info" style="margin:4px 0 0"><span>👁</span><div>These details become visible to the customer in <b style="display:inline">My orders</b> the moment you dispatch.</div></div>
  </div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Back</button>
    <button class="btn btn-info" onclick="dispatchOrder('${id}')">Dispatch rider →</button></div>`);
}
async function dispatchOrder(id) {
  const name = v('dName').trim(), phone = v('dPhone').trim(), vehicleNo = v('dVNo').trim();
  if (!name || !phone || !vehicleNo) { toast('Rider name, phone and vehicle number are required.', 'bad'); return; }
  try {
    await api(`orders/${id}/advance`, { method: 'POST', body: { toStatus: 'Out for delivery', driverName: name, driverPhone: phone, vehicleType: v('dVType'), vehicleNo, eta: v('dEta') } });
    closeModal();
    toast(`${id} dispatched with ${name}. Customer can now see rider details.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}

async function orderDetail(id, staff) {
  const o = await api(`orders/${id}`);
  const idx = ORD_STEPS.indexOf(o.status);
  const steps = ORD_STEPS.filter(s => !(o.mode === 'Pickup' && s === 'Out for delivery'));
  const track = o.status === 'Cancelled' ? '<span class="badge b-bad">Cancelled</span>' :
    `<div class="ostep">${steps.map((s, i, arr) => { const on = ORD_STEPS.indexOf(s) <= idx; return `<div class="s ${on ? 'on' : ''}"><div class="d">${on ? '✓' : i + 1}</div>${s}</div>${i < arr.length - 1 ? '<div class="ln"></div>' : ''}`; }).join('')}</div>`;
  const items = o.lines.map(l => `<div class="kv"><span>${l.emoji} ${esc(l.name)} ×${l.qty}</span><b>${money(l.qty * l.unit_price).replace('Rs. ', '')}</b></div>`).join('');
  const dlv = o.driver_name ? `<div class="sec-lbl">🛵 Delivery tracking</div>
    <div class="kv"><span>Delivery person</span><b>${esc(o.driver_name)}</b></div>
    <div class="kv"><span>Rider phone</span><b>${esc(o.driver_phone || '')}</b></div>
    <div class="kv"><span>Vehicle</span><b>${esc(o.vehicle_type)} · ${esc(o.vehicle_no)}</b></div>
    <div class="kv"><span>Expected</span><b>${esc(o.eta || '')}</b></div>
    ${o.delivered_at ? `<div class="kv"><span>Received at</span><b style="color:var(--fresh)">${fmtWhen(o.delivered_at)}</b></div>` : ''}` : '';
  const tl = `<div class="sec-lbl">Timeline</div><ul class="timeline">${o.timeline.map(t => `<li><b>${fmtWhen(t.happened_at)}</b> — ${esc(t.event)}</li>`).join('')}</ul>`;
  const custActions = !staff && o.status === 'Pending' ? `<button class="btn btn-danger" onclick="custCancel('${o.id}')">Cancel order</button>` : '';
  const reorder = !staff ? `<button class="btn btn-ghost" onclick="reorderItems('${o.id}')">↻ Reorder these items</button>` : '';
  modal(`<div class="modal-h"><h3>${o.id}</h3><span class="grow"></span><span class="badge ${o.status === 'Delivered' ? 'b-good' : o.status === 'Cancelled' ? 'b-bad' : 'b-info'}">${o.status}</span><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">${track}<div style="height:14px"></div>
    <div class="kv"><span>Customer</span><b>${esc(o.customer_name)} · ${esc(o.phone || '')}</b></div>
    <div class="kv"><span>Placed</span><b>${fmtWhen(o.created_at)} (${o.order_type})</b></div>
    <div class="kv"><span>Mode</span><b>${o.mode}</b></div>
    <div class="kv"><span>${o.mode === 'Delivery' ? 'Delivery address' : 'Pickup'}</span><b style="max-width:300px">${esc(o.address || '')}</b></div>
    <div class="kv"><span>Payment</span><b>${esc(o.payment_method || '')}</b></div>
    ${o.note ? `<div class="kv"><span>Note</span><b style="max-width:300px">${esc(o.note)}</b></div>` : ''}
    <div class="sec-lbl">Items</div>${items}<div class="kv"><span><b style="color:var(--ink)">Total</b></span><b style="color:var(--brass)">${money(o.total).replace('Rs. ', '')}</b></div>
    ${dlv}${tl}
  </div>
  <div class="modal-f">${reorder}${custActions}<button class="btn btn-ghost" onclick="closeModal()">Close</button>
    ${staff ? `<span class="grow"></span>${staffOrderActions(o)}` : ''}</div>`, true);
}
