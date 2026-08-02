RENDER.account = async () => {
  const a = await api('account');
  return `<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));max-width:780px">
    <div class="card"><div class="card-h"><h3>👤 Profile & delivery</h3></div><div class="card-b">
      <div class="field"><label>Full name</label><input id="acName" value="${esc(a.name)}"></div>
      <div class="field"><label>Phone</label><input id="acPhone" value="${esc(a.phone || '')}"></div>
      <div class="field"><label>Email</label><input id="acEmail" value="${esc(a.email)}" disabled></div>
      <div class="field"><label>Default delivery address (auto-filled at checkout)</label><textarea id="acAddr" rows="3">${esc(a.address || '')}</textarea></div>
      <div class="field"><label>Preferred payment</label><select id="acPay">${['Cash on delivery', 'Card on delivery', 'Online card payment'].map(p => `<option ${a.paymentMethod === p ? 'selected' : ''}>${p}</option>`).join('')}</select></div>
      <button class="btn btn-primary" onclick="saveAccount()">Save changes</button>
    </div></div>
    <div class="card"><div class="card-h"><h3>📈 My summary</h3></div><div class="card-b">
      <div class="kv"><span>Total orders</span><b>${a.totalOrders}</b></div>
      <div class="kv"><span>Total spent</span><b>${money(a.totalSpent).replace('Rs. ', 'Rs.')}</b></div>
      <div class="kv"><span>Loyalty points (1 pt / Rs 100)</span><b style="color:var(--brass)">⭐ ${a.loyaltyPoints}</b></div>
      <div class="alert a-good" style="margin-top:14px"><span>🎁</span><div><b>Loyalty perk</b>Free delivery on orders over ${fmt(2000)} — points redemption coming soon.</div></div>
    </div></div></div>`;
};
async function saveAccount() {
  try {
    await api('account', { method: 'POST', body: { name: v('acName').trim(), phone: v('acPhone'), address: v('acAddr').trim(), paymentMethod: v('acPay') } });
    SESSION.name = v('acName').trim() || SESSION.name;
    SESSION.address = v('acAddr').trim();
    SESSION.paymentMethod = v('acPay');
    document.getElementById('whoName').textContent = SESSION.name;
    document.getElementById('avatar').textContent = SESSION.name[0];
    toast('Account updated — your new address will be used at checkout.', 'good');
  } catch (e) { /* toasted */ }
}
