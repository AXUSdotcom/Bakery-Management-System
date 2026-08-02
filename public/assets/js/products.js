const PROD_EDIT_ROLES = ['admin', 'manager'];
let _productsCache = [];
let _ingredientsCache = [];

RENDER.products = async () => {
  const canEdit = PROD_EDIT_ROLES.includes(SESSION.role);
  _productsCache = await api('products');
  const cards = _productsCache.map(p => {
    const mb = p.maxBakeable;
    const rec = p.recipe.map(r => `<div class="hrow" style="margin-bottom:6px"><div class="nm">${esc(r.ingredient_name)}</div><div class="grow mini">${r.qty_per_unit} ${r.uom} / unit</div></div>`).join('');
    return `<div class="card"><div class="card-h"><span style="font-size:22px">${p.emoji}</span><h3>${esc(p.name)}</h3><span class="grow"></span><span class="badge b-mut">${money(p.price).replace('Rs. ', 'Rs.')}</span></div>
    <div class="card-b"><div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
      <span class="badge b-info">Shelf: ${p.shelf_stock}</span>
      <span class="badge ${mb > 20 ? 'b-good' : mb > 0 ? 'b-warn' : 'b-bad'}">Max bakeable: ${mb}</span>
      <span class="badge b-mut">Margin ${p.margin.toFixed(0)}%</span></div>
      <div class="sec-lbl">Recipe · per unit</div>${rec}
      ${canEdit ? `<div style="display:flex;gap:8px;margin-top:14px"><button class="btn btn-ghost btn-sm" onclick="mEditProduct('${p.id}')">✏️ Edit</button>
        <button class="btn btn-danger btn-sm" onclick="removeProduct('${p.id}')">Remove</button></div>` : ''}</div></div>`;
  }).join('');
  return `<div class="toolbar"><div class="grow"></div>${canEdit ? `<button class="btn btn-primary btn-sm" onclick="mEditProduct('')"><span class="ic">＋</span>New product</button>` : ''}</div>
  <div class="grid g-auto">${cards}</div>`;
};

async function mEditProduct(id) {
  _ingredientsCache = await api('inventory?filter=all');
  const p = id ? _productsCache.find(x => x.id === id) : { name: '', emoji: '🥨', price: 200, shelf_stock: 0, description: '', recipe: [{ ingredient_id: _ingredientsCache[0].id, qty_per_unit: 0.1 }] };
  editRecipe = p.recipe.map(r => [r.ingredient_id || r.ingredientId, +r.qty_per_unit]);
  modal(`<div class="modal-h"><h3>${id ? 'Edit product' : 'New product'}</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">
    <div class="row"><div class="field"><label>Product name *</label><input id="pName" value="${esc(p.name)}" placeholder="e.g. Cinnamon roll"></div>
    <div class="field" style="max-width:110px"><label>Emoji</label><input id="pEmoji" value="${p.emoji}"></div></div>
    <div class="row"><div class="field"><label>Price (Rs) *</label><input id="pPrice" type="number" min="0" value="${p.price}"></div>
    <div class="field"><label>Shelf stock</label><input id="pStock" type="number" min="0" value="${p.shelf_stock || 0}"></div></div>
    <div class="field"><label>Description</label><input id="pDesc" value="${esc(p.description || '')}" placeholder="Shown to customers in the shop"></div>
    <div class="field"><label>Recipe — ingredients per 1 unit</label><div id="recipeLines"></div>
      <button class="btn btn-ghost btn-sm" onclick="editRecipe.push(['${_ingredientsCache[0].id}',0.1]);drawRecipe()"><span class="ic">＋</span>Add ingredient line</button></div>
  </div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="saveProduct('${id}')">Save product</button></div>`, true);
  drawRecipe();
}
function drawRecipe() {
  const opts = iId => _ingredientsCache.map(x => `<option value="${x.id}" ${x.id === iId ? 'selected' : ''}>${esc(x.name)} (${x.uom})</option>`).join('');
  document.getElementById('recipeLines').innerHTML = editRecipe.map((r, idx) => `<div class="recipe-line">
    <select onchange="editRecipe[${idx}][0]=this.value">${opts(r[0])}</select>
    <input type="number" step="0.001" min="0" value="${r[1]}" onchange="editRecipe[${idx}][1]=+this.value||0">
    <button class="btn btn-danger btn-sm" onclick="editRecipe.splice(${idx},1);drawRecipe()">✕</button></div>`).join('');
}
async function saveProduct(id) {
  const name = v('pName').trim();
  if (!name) { toast('Product name is required.', 'bad'); return; }
  const recipe = editRecipe.filter(r => r[1] > 0).map(r => ({ ingredientId: r[0], qtyPerUnit: r[1] }));
  if (!recipe.length) { toast('Add at least one recipe ingredient.', 'bad'); return; }
  try {
    await api('products', {
      method: 'POST',
      body: { id: id || null, name, emoji: v('pEmoji') || '🥨', price: +v('pPrice') || 0, shelfStock: +v('pStock') || 0, description: v('pDesc'), recipe },
    });
    closeModal();
    toast(id ? `${name} updated.` : `${name} added to the catalogue.`, 'good');
    refresh();
  } catch (e) { /* toasted */ }
}
function removeProduct(id) {
  const p = _productsCache.find(x => x.id === id);
  modal(`<div class="modal-h"><h3>Remove ${esc(p.name)}?</h3><button class="x" onclick="closeModal()">✕</button></div>
  <div class="modal-b">It will disappear from the customer shop. Past orders keep their history.</div>
  <div class="modal-f"><button class="btn btn-ghost" onclick="closeModal()">Keep</button>
    <button class="btn btn-danger" onclick="doRemoveProduct('${id}')">Remove product</button></div>`);
}
async function doRemoveProduct(id) {
  try {
    await api(`products/${id}/remove`, { method: 'POST' });
    closeModal();
    toast('Product removed.', 'warn');
    refresh();
  } catch (e) { /* toasted */ }
}
