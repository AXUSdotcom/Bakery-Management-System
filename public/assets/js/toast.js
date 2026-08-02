function toast(m, type = '') {
  const wrap = document.getElementById('toasts');
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = m;
  wrap.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}
