RENDER.audit = async () => {
  const rows = await api('audit');
  return `<div class="card panel"><div class="panel-h"><h3>Audit log</h3><span class="tag">admin only · read-only</span></div>
    <table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th></tr></thead>
    <tbody>${rows.length ? rows.map(l => `<tr><td class="mini">${fmtWhen(l.happened_at)}</td><td><b>${esc(l.user_name || 'System')}</b></td><td><span class="pill p-slate"><span class="d"></span>${l.action}</span></td><td class="mini">${esc(l.detail || '')}</td></tr>`).join('') : `<tr><td colspan="4"><div class="empty">No activity recorded yet — perform actions to populate the log.</div></td></tr>`}</tbody></table></div>`;
};
