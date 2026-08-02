let _shopProducts = [];

RENDER.shop = async () => {
  _shopProducts = await api('shop/products');
  const cards = _shopProducts.map(p => {
    const out = p.shelf_stock <= 0;
    return `<div class="card prodcard"><div class="thumb">${p.emoji}</div>
      <div class="nm">${esc(p.name)}</div><div class="desc">${esc(p.description || '')}</div>
      <div>${out ? '<span class="badge b-bad">Sold out today</span>' : p.shelf_stock < 10 ? `<span class="badge b-warn">Only ${p.shelf_stock} left</span>` : '<span class="badge b-good">Freshly stocked</span>'}</div>
      <div class="prow"><span class="pr">${money(p.price).replace('Rs. ', 'Rs.')}</span>
        ${out ? '<button class="btn btn-ghost btn-sm" disabled>Unavailable</button>' :
          `<div class="qty-ctl"><button onclick="cartAdd('${p.id}',-1)">−</button><span id="c-${p.id}">${cart[p.id] || 0}</span><button onclick="cartAdd('${p.id}',1)">＋</button></div>`}
      </div></div>`;
  }).join('');
  return `<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">${cards}</div>`;
};

function cartAdd(pid, delta) {
  const p = _shopProducts.find(x => x.id === pid);
  const max = p ? p.shelf_stock : 999;
  cart[pid] = Math.min(max, Math.max(0, (cart[pid] || 0) + delta));
  if (!cart[pid]) delete cart[pid];
  const e = document.getElementById('c-' + pid);
  if (e) e.textContent = cart[pid] || 0;
  updateCartFab();
}
function cartItems() { return Object.entries(cart); }
function cartTotal() { return cartItems().reduce((s, [pid, q]) => s + (_shopProducts.find(x => x.id === pid)?.price || 0) * q, 0); }
function updateCartFab() {
  const n = cartItems().reduce((s, [, q]) => s + q, 0);
  document.getElementById('cartFab').classList.toggle('show', n > 0 && SESSION && SESSION.role === 'customer' && CURRENT === 'shop');
  document.getElementById('cartCount').textContent = n;
}
function openCheckout() {
  if (!cartItems().length) { toast('Your basket is empty.', 'warn'); return; }
  const rows = cartItems().map(([pid, q]) => { const p = _shopProducts.find(x => x.id === pid); return `<div class="kv"><span>${p.emoji} ${esc(p.name)} ×${q}</span><b>${money(p.price * q).replace('Rs. ', '')}</b></div>`; }).join('');
  const delFee = cartTotal() >= 2000 ? 0 : 250;
  modal(`<div class="modal-h"><h3>🧺 Checkout</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">${rows}
    <div class="kv"><span>Delivery fee ${delFee === 0 ? '(free over ' + fmt(2000) + ')' : ''}</span><b>${delFee ? money(delFee).replace('Rs. ', '') : 'FREE'}</b></div>
    <div class="kv"><span><b style="color:var(--ink)">Total</b></span><b style="color:var(--brass)">${money(cartTotal() + delFee).replace('Rs. ', '')}</b></div>
    <div class="row" style="margin-top:14px"><div class="field"><label>Order mode</label><select id="coMode" onchange="document.getElementById('coAddrWrap').style.display=this.value==='Delivery'?'block':'none'"><option>Delivery</option><option>Pickup</option></select></div>
    <div class="field"><label>Payment method</label><select id="coPay">
      <option ${SESSION.paymentMethod === 'Cash on delivery' ? 'selected' : ''}>Cash on delivery</option>
      <option ${SESSION.paymentMethod === 'Card on delivery' ? 'selected' : ''}>Card on delivery</option>
      <option ${SESSION.paymentMethod === 'Online card payment' ? 'selected' : ''}>Online card payment</option></select></div></div>
    <div id="coAddrWrap"><div class="field"><label>Deliver to (your saved address — editable)</label><textarea id="coAddr" rows="2">${esc(SESSION.address || '')}</textarea></div></div>
    <div class="field"><label>Note for the bakery (optional)</label><input id="coNote" placeholder="e.g. add birthday candles"></div>
    <label style="display:flex;gap:8px;font-size:12.5px;color:var(--slate);align-items:center;font-weight:600"><input type="checkbox" id="coSaveAddr" checked style="width:auto"> Save this address to my account</label>
  </div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Keep shopping</button>
    <button class="btn btn-primary" onclick="placeOrder(${delFee})">Place order · ${money(cartTotal() + delFee).replace('Rs. ', 'Rs.')}</button></div>`, true);
}
async function placeOrder(fee) {
  const mode = v('coMode') === 'Delivery' ? 'Delivery' : 'Pickup';
  const address = mode === 'Delivery' ? v('coAddr').trim() : 'Pickup at store';
  if (mode === 'Delivery' && !address) { toast('Please enter a delivery address.', 'bad'); return; }
  const items = cartItems().map(([productId, qty]) => ({ productId, qty }));
  try {
    const order = await api('orders/checkout', {
      method: 'POST',
      body: { items, mode, address, paymentMethod: v('coPay'), note: v('coNote'), saveAddress: mode === 'Delivery' && document.getElementById('coSaveAddr').checked },
    });
    cart = {};
    closeModal();
    updateCartFab();
    toast(`Order ${order.id} placed! Track it in My orders. 🥐`, 'good');
    go('myorders');
  } catch (e) { /* toasted */ }
}

RENDER.myorders = async () => {
  const mine = await api('orders/mine');
  setNavPill('myorders', mine.filter(o => !['Delivered', 'Cancelled'].includes(o.status)).length);
  if (!mine.length) return `<div class="card panel"><div class="empty"><div class="ic">🛒</div>No orders yet.<br><br><button class="btn btn-primary" onclick="go('shop')">Browse bakery items</button></div></div>`;
  const cards = mine.map(o => {
    const cls = o.status === 'Delivered' ? 'b-good' : o.status === 'Cancelled' ? 'b-bad' : o.status === 'Pending' ? 'b-warn' : 'b-info';
    const rider = (o.driver_name && o.status === 'Out for delivery') ? `<div class="alert a-info" style="margin:10px 0 0"><span>🛵</span><div><b>${esc(o.driver_name)} is on the way</b>${esc(o.vehicle_type)} ${esc(o.vehicle_no)} · ☎ ${esc(o.driver_phone)} · ETA ${esc(o.eta)}</div></div>` : '';
    return `<div class="card"><div class="card-h"><h3>${o.id}</h3><span class="grow"></span><span class="badge ${cls}">${o.status}</span></div>
    <div class="card-b"><div style="margin-bottom:8px;font-size:13px">${o.items_summary || ''}</div>
      <div class="kv"><span>${fmtWhen(o.created_at)} · ${o.mode}</span><b style="color:var(--brass)">${money(o.total).replace('Rs. ', '')}</b></div>${rider}
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="orderDetail('${o.id}',false)">View details</button>
        ${o.status === 'Pending' ? `<button class="btn btn-danger btn-sm" onclick="custCancel('${o.id}')">Cancel</button>` : ''}
        <button class="btn btn-ghost btn-sm" onclick="reorderItems('${o.id}')">↻ Reorder</button></div></div></div>`;
  }).join('');
  return `<div class="grid g-auto">${cards}</div>`;
};
async function custCancel(id) {
  try {
    await api(`orders/${id}/customer-cancel`, { method: 'POST' });
    closeModal();
    toast('Order cancelled — nothing was charged.', 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}
async function reorderItems(id) {
  const o = await api(`orders/${id}`);
  if (!_shopProducts.length) _shopProducts = await api('shop/products');
  cart = {};
  o.lines.forEach(l => {
    const p = _shopProducts.find(x => x.id === l.product_id);
    if (p && p.shelf_stock > 0) cart[l.product_id] = Math.min(p.shelf_stock, l.qty);
  });
  closeModal();
  go('shop');
  toast('Items added back to your basket — checkout when ready.', 'good');
}
