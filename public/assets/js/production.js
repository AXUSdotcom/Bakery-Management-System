const PROD_CONFIRM_ROLES = ['admin', 'manager', 'baker'];
const PROD_PO_ROLES = ['admin', 'manager', 'store'];
let _prodProducts = [];

RENDER.production = async () => {
  const data = await api('production');
  _prodProducts = data.products;
  const inputs = _prodProducts.map(p => `<tr><td><span style="font-size:17px">${p.emoji}</span> <b>${esc(p.name)}</b></td>
    <td class="num">${p.shelf_stock}</td><td><span class="maxchip">max ${p.maxBakeable}</span></td>
    <td><div class="qty-ctl"><button onclick="bump('${p.id}',-5)">−</button><span id="plan-${p.id}">${plan[p.id] || 0}</span><button onclick="bump('${p.id}',5)">＋</button></div></td></tr>`).join('');
  const hist = data.history.map(h => `<tr><td class="mini">${fmtWhen(h.run_at)}</td><td>${esc(h.run_by_name || '—')}</td><td class="mini">${esc(h.lines)}</td><td><span class="badge b-good">${h.status}</span></td></tr>`).join('');
  return `<div class="steps" style="margin-bottom:16px"><b>1</b> Enter quantities (or auto-suggest) → <b>2</b> Live ingredient check → <b>3</b> Confirm to deduct stock (FEFO) &amp; add finished goods</div>
  <div class="grid two-col">
    <div class="card"><div class="card-h"><h3>Today's bake plan</h3><span class="grow"></span>
      <button class="btn btn-info btn-sm" onclick="suggestPlan()">✨ Auto-suggest</button>
      <button class="btn btn-ghost btn-sm" onclick="plan={};refresh()">Clear</button></div>
      <div class="tbl-wrap card-b" style="padding-top:6px"><table><thead><tr><th>Product</th><th class="r">On shelf</th><th>Bakeable</th><th>Plan today</th></tr></thead><tbody>${inputs}</tbody></table></div></div>
    <div class="card"><div class="card-h"><h3>🧮 Ingredient feasibility</h3></div><div class="card-b" id="feasBox"></div></div>
  </div>
  <div class="card panel" style="margin-top:18px"><div class="panel-h"><h3>Production history</h3></div>
    <table><thead><tr><th>When</th><th>Baker</th><th>Items</th><th>Status</th></tr></thead><tbody>${hist || `<tr><td colspan="4"><div class="empty">No production recorded yet.</div></td></tr>`}</tbody></table></div>`;
};
AFTER.production = () => renderFeas();

async function suggestPlan() {
  const r = await api('production/suggest', { method: 'POST' });
  plan = r.plan;
  toast("Plan suggested from 7-day average sales minus shelf stock.", 'good');
  refresh();
}
function bump(pid, delta) {
  plan[pid] = Math.max(0, (plan[pid] || 0) + delta);
  document.getElementById('plan-' + pid).textContent = plan[pid];
  renderFeas();
}
async function renderFeas() {
  const box = document.getElementById('feasBox');
  if (!box) return;
  const activePlan = Object.fromEntries(Object.entries(plan).filter(([, q]) => q > 0));
  const canConfirm = PROD_CONFIRM_ROLES.includes(SESSION.role);
  if (!Object.keys(activePlan).length) {
    box.innerHTML = `<div class="empty"><div class="ic">⚖</div>Add quantities (or auto-suggest) —<br>availability is checked as you type.</div>`;
    return;
  }
  const feas = await api('production/feasibility', { method: 'POST', body: { plan: activePlan } });
  const rows = feas.rows.map(r => {
    const pct = Math.min(100, Math.round(r.have / r.need * 100));
    const col = r.shortage > 0 ? 'var(--danger)' : pct < 130 ? 'var(--warn)' : 'var(--fresh)';
    return `<div class="feas-row"><div class="nm">${esc(r.name)}</div>
      <div class="lvl"><i style="width:${pct}%;background:${col}"></i></div>
      <div class="need">need <b class="mono">${r.need}</b> / have <b class="mono">${r.have}</b> ${r.uom} ${r.shortage > 0 ? `<span class="badge b-bad">short ${r.shortage}</span>` : ''}</div></div>`;
  }).join('');
  let result;
  if (feas.ok) {
    result = `<div class="bigresult ok" style="margin-top:14px"><span style="font-size:26px">✓</span><div><div class="n" style="color:var(--fresh)">Plan is fully bakeable</div><div class="mini">Confirming deducts ingredients (FEFO) and adds finished goods.</div></div></div>
      ${canConfirm ? `<button class="btn btn-good btn-block" style="margin-top:12px" onclick="confirmBake()">Confirm production &amp; update inventory</button>` : `<div class="mini" style="margin-top:12px;text-align:center">Only bakers &amp; managers can confirm production.</div>`}`;
  } else {
    const adj = Object.entries(activePlan).map(([pid, q]) => {
      const p = _prodProducts.find(x => x.id === pid);
      return `<div class="hrow"><div class="nm">${p.emoji} ${esc(p.name)}</div><div class="grow mini">planned ${q}</div><span class="maxchip">max alone: ${p.maxBakeable}</span></div>`;
    }).join('');
    result = `<div class="bigresult no" style="margin-top:14px"><span style="font-size:26px">✕</span><div><div class="n" style="color:var(--danger)">Not enough ingredients</div><div class="mini">Shortages above. Each product alone could yield at most:</div></div></div>
      <div style="margin-top:12px">${adj}</div>
      <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
        ${PROD_PO_ROLES.includes(SESSION.role) ? `<button class="btn btn-danger btn-sm" onclick="poForShortages()">⚡ Raise POs for shortages</button>` : ''}
        <button class="btn btn-ghost btn-sm" onclick="fitPlan()">Auto-fit plan to stock</button></div>`;
  }
  box.innerHTML = rows + result;
}
async function fitPlan() {
  const activePlan = Object.fromEntries(Object.entries(plan).filter(([, q]) => q > 0));
  const r = await api('production/fit', { method: 'POST', body: { plan: activePlan } });
  plan = r.plan;
  toast('Plan auto-fitted to available ingredients.', 'good');
  refresh();
}
async function poForShortages() {
  const activePlan = Object.fromEntries(Object.entries(plan).filter(([, q]) => q > 0));
  try {
    await api('production/po-for-shortages', { method: 'POST', body: { plan: activePlan } });
    toast('Draft POs raised for production shortages — review & send.', 'good');
    go('purchase');
  } catch (e) { /* toasted */ }
}
async function confirmBake() {
  const activePlan = Object.fromEntries(Object.entries(plan).filter(([, q]) => q > 0));
  try {
    const r = await api('production/confirm', { method: 'POST', body: { plan: activePlan } });
    plan = {};
    toast('Production confirmed — ingredients deducted (FEFO), shelf updated.', 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
