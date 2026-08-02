function modal(html, wide) {
  document.getElementById('modal').className = 'modal' + (wide ? ' wide' : '');
  document.getElementById('modal').innerHTML = html;
  document.getElementById('modalBg').classList.add('on');
}
function closeModal() {
  document.getElementById('modalBg').classList.remove('on');
}
document.getElementById('modalBg').addEventListener('click', e => {
  if (e.target.id === 'modalBg') closeModal();
});

/** Small DOM helpers used throughout the view modules. */
const v = id => document.getElementById(id).value;
const V = () => document.getElementById('view');
