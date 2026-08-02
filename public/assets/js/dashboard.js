let _dashboardData = null;

function kpiCard(icon, ibg, ifg, label, val, foot, footCls) {
  return `<div class="card kpi"><div class="lbl"><span class="ic" style="background:${ibg};color:${ifg}">${icon}</span>${label}</div>
    <div class="val">${val}</div><div class="foot ${footCls || ''}">${foot}</div></div>`;
}

RENDER.dashboard = async () => {
  const d = _dashboardData = await api('dashboard');
  const chg = d.wasteChangePct;
  const alerts = d.lowItems.map(i => `<div class="alert a-bad"><span>⚑</span><div><b>${esc(i.name)} below reorder</b>${i.stock_on_hand} ${i.uom} left</div><button class="btn btn-danger btn-sm act" onclick="autoPO('${i.id}')">Raise PO</button></div>`).join('')
    + (d.pendingOrders ? `<div class="alert a-info"><span>☰</span><div><b>${d.pendingOrders} order(s) awaiting confirmation</b>Confirm to start preparing.</div><button class="btn btn-ghost btn-sm act" onclick="go('orders')">Queue</button></div>` : '')
    + `<div class="alert a-warn"><span>♺</span><div><b>${fmt(d.wasteCost30d)} wastage this month</b>See the wastage log for a reason breakdown.</div><button class="btn btn-ghost btn-sm act" onclick="go('wastage')">Log</button></div>`;

  const top = d.topSellers;
  const tm = Math.max(1, ...(top.length ? top.map(t => Number(t.revenue)) : [1]));
  const tops = top.length
    ? top.map(t => `<div class="hrow"><div class="nm">${t.emoji || ''} ${esc(t.name)}</div><div class="hbar"><i style="width:${Math.round(Number(t.revenue) / tm * 100)}%"></i></div><div class="v">${(Number(t.revenue) / 1000).toFixed(1)}k</div></div>`).join('')
    : `<div class="empty" style="padding:16px"><div class="ic">☰</div>No sales yet this week.</div>`;

  const expSoon = d.expiringSoon;
  const h = d.stockHealth;

  return `
  <div class="grid kpis" style="margin-bottom:18px">
    ${kpiCard('♺', 'var(--danger-tint)', 'var(--danger)', 'Waste cost · 30d', money(d.wasteCost30d).replace('Rs. ', 'Rs.'), `${chg < 0 ? '▼' : '▲'} ${Math.abs(chg).toFixed(0)}% vs prev 30d`, chg < 0 ? 'up' : 'down')}
    ${kpiCard('⛁', 'var(--brass-tint)', 'var(--brass)', 'Inventory value', money(d.inventoryValue).replace('Rs. ', 'Rs.'), `<b>${d.activeBatches}</b> active batches`)}
    ${kpiCard('⚑', 'var(--warn-tint)', 'var(--warn)', 'Low-stock items', d.lowStockCount, `${d.lowStockCount ? 'POs suggested' : 'all healthy'}`)}
    ${kpiCard('☰', 'var(--fresh-tint)', 'var(--fresh)', 'Sales today', money(d.salesToday).replace('Rs. ', 'Rs.'), `<b>${d.openOrders}</b> open orders`)}
  </div>
  <div class="grid g2" style="margin-bottom:18px">
    <div class="card panel"><div class="panel-h"><h3>Sales — last 7 days</h3><span class="tag">Σ ${fmt(d.sales7d.reduce((s, x) => s + Number(x.total), 0))}</span></div><canvas id="cSales" height="150"></canvas></div>
    <div class="card"><div class="card-h"><h3>⚡ Action centre</h3><span class="grow"></span><span class="badge b-bad">${d.lowItems.length + 1} alerts</span></div><div class="card-b" style="max-height:240px;overflow-y:auto">${alerts}</div></div>
  </div>
  <div class="grid g2" style="margin-bottom:18px">
    <div class="card panel"><div class="panel-h"><h3>Waste cost trend</h3><span class="tag">last 6 weeks · Rs.</span></div><canvas id="cWaste" height="150"></canvas></div>
    <div class="card panel"><div class="panel-h"><h3>Waste by reason</h3><span class="tag">30 days</span></div><canvas id="cReason" height="150"></canvas></div>
  </div>
  <div class="grid three-col">
    <div class="card"><div class="card-h"><h3>Top sellers (7d)</h3></div><div class="card-b">${tops}</div></div>
    <div class="card panel"><div class="panel-h"><h3>Stock health</h3></div><div class="donut-wrap"><canvas id="cHealth" width="140" height="140" style="max-width:140px"></canvas>
      <div class="legend">
        <div><i style="background:var(--fresh)"></i>Healthy — ${h.healthy}</div>
        <div><i style="background:var(--warn)"></i>Getting low — ${h.warn}</div>
        <div><i style="background:var(--danger)"></i>Below reorder — ${h.bad}</div>
      </div></div></div>
    <div class="card panel">
      <div class="panel-h"><h3>Expiring next 7 days</h3><span class="sp"></span><button class="btn btn-ghost btn-sm" onclick="go('inventory')">Inventory</button></div>
      ${expSoon.length ? `<div class="bnk">${expSoon.map(b => `<div class="bar"><span class="n">${esc(b.ingredient_name)}</span><div class="track"></div><span class="v">${b.qty_on_hand} ${b.uom} · ${expiryPill(b.expiry_date)}</span></div>`).join('')}</div>` : `<div class="empty"><div class="ic">✓</div>Nothing expiring soon.</div>`}
    </div>
  </div>`;
};

AFTER.dashboard = () => {
  const d = _dashboardData;
  const co = { plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#EAECE4' }, ticks: { font: { family: 'IBM Plex Mono', size: 11 }, callback: x => (x / 1000) + 'k' } }, x: { grid: { display: false }, ticks: { font: { family: 'IBM Plex Mono', size: 11 } } } } };
  const salesLabels = d.sales7d.map(s => new Date(s.sale_date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short' }));
  new Chart(document.getElementById('cSales'), { type: 'bar', data: { labels: salesLabels, datasets: [{ data: d.sales7d.map(s => Number(s.total)), backgroundColor: '#A9791A', borderRadius: 5, maxBarThickness: 34 }] }, options: co });
  new Chart(document.getElementById('cWaste'), { type: 'line', data: { labels: d.wasteTrend6w.map(w => w.label), datasets: [{ data: d.wasteTrend6w.map(w => w.cost), borderColor: '#A9791A', backgroundColor: 'rgba(169,121,26,.08)', fill: true, tension: .35, borderWidth: 2.5, pointRadius: 3, pointBackgroundColor: '#A9791A' }] }, options: co });
  new Chart(document.getElementById('cReason'), { type: 'doughnut', data: { labels: d.wasteByReason.map(r => r.reason), datasets: [{ data: d.wasteByReason.map(r => Number(r.cost)), backgroundColor: ['#A3392A', '#B07714', '#2E6A4E', '#1C3A33', '#C4901F'], borderWidth: 2, borderColor: '#fff' }] }, options: { plugins: { legend: { position: 'right', labels: { font: { family: 'IBM Plex Sans', size: 12 }, boxWidth: 12, padding: 10 } } }, cutout: '62%' } });
  const h = d.stockHealth;
  new Chart(document.getElementById('cHealth'), { type: 'doughnut', data: { labels: ['Healthy', 'Getting low', 'Below reorder'], datasets: [{ data: [h.healthy, h.warn, h.bad], backgroundColor: ['#2E6A4E', '#B07714', '#A3392A'], borderWidth: 2, borderColor: '#fff' }] }, options: { plugins: { legend: { display: false } }, cutout: '66%' } });
  setNavPill('inventory', d.lowStockCount);
  setNavPill('orders', d.pendingOrders);
};
