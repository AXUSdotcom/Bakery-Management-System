const WASTE_EDIT_ROLES = ['admin', 'manager', 'store'];

RENDER.wastage = async () => {
  const canEdit = WASTE_EDIT_ROLES.includes(SESSION.role);
  const data = await api('wastage');
  const rows = data.log.map(w => `<tr><td class="mini">${fmtWhen(w.logged_at)}</td><td><b>${esc(w.ingredient_name)}</b></td><td class="num">${w.batch_id || '—'}</td>
    <td class="num">${w.qty} ${w.uom}</td><td><span class="pill ${w.reason === 'Expired' || w.reason === 'Damaged/Spoiled' ? 'p-danger' : 'p-warn'}"><span class="d"></span>${w.reason}</span></td>
    <td class="num" style="color:var(--danger)">−${money(w.cost).replace('Rs. ', '')}</td>
    <td><span class="pill ${w.is_auto ? 'p-slate' : 'p-brass'}"><span class="d"></span>${w.is_auto ? 'auto' : 'manual'}</span></td></tr>`).join('');
  return `<div class="grid g3" style="margin-bottom:18px">
    ${kpiCard('♺', 'var(--danger-tint)', 'var(--danger)', 'Waste · 7 days', money(data.totals.total7d).replace('Rs. ', 'Rs.'), `${data.totals.events7d} events`)}
    ${kpiCard('♺', 'var(--warn-tint)', 'var(--warn)', 'Waste · 30 days', money(data.totals.total30d).replace('Rs. ', 'Rs.'), 'auto + manual')}
    ${kpiCard('⏳', 'var(--fresh-tint)', 'var(--fresh)', 'Auto-logged', data.totals.autoLogged, 'by expiry job')}
  </div>
  <div class="card panel">
    <div class="panel-h"><h3>Wastage log</h3><span class="tag">costed at batch price</span><span class="sp"></span>
      ${canEdit ? `<button class="btn btn-slate btn-sm" onclick="runExpiryJob()"><span class="ic">♺</span>Run expiry job</button>` : ''}</div>
    <table><thead><tr><th>Date</th><th>Ingredient</th><th>Batch</th><th class="r">Qty</th><th>Reason</th><th class="r">Cost</th><th>Source</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="7"><div class="empty">No wastage recorded.</div></td></tr>`}</tbody></table>
  </div>`;
};
