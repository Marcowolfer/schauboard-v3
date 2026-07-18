<?php /* Eingebunden in admin/index.php innerhalb des <script>-Blocks. Reines JavaScript. */ ?>
/* ===== Welche Modal-Felder pro Blocktyp sichtbar sind ===== */
const TYPE_FIELDS = {
  text:      ['type','text','font_size','color','align','bold','advanced'],
  heading:   ['type','text','font_size','color','align','bold','advanced'],
  clock:     ['type','clock_format','show_date','font_size','color','advanced'],
  image:     ['type','src','upload','fit','advanced'],
  gallery:   ['type','gallery','fit','advanced'],
  shape:     ['type','shape_kind','color','opacity','radius','advanced'],
  weather:   ['type','city','forecast','font_size','color','advanced'],
  rss:       ['type','rss_url','rss_count','rss_time','rss_source','font_size','color','advanced'],
  ticker:    ['type','text','speed','bg','font_size','color','advanced'],
  table:     ['type','table','font_size','advanced'],
  webpage:   ['type','url','refresh_minutes','zoom','advanced'],
  qrcode:    ['type','data','qlabel','font_size','color','advanced'],
  countdown: ['type','target','clabel','font_size','color','advanced'],
  animation: ['type','html','advanced'],
  video:     ['type','src','upload','fit','advanced'],
};

/* Schutz vor Datenverlust: ungespeicherte Aenderungen markieren und beim
   Verlassen/Wechseln der Seite warnen. markDirty() bei jeder Mutation,
   clearDirty() nach erfolgreichem Speichern. */
let _dirty = false;
function markDirty() { _dirty = true; }
function clearDirty() { _dirty = false; }
window.addEventListener('beforeunload', function (e) {
  if (_dirty) { e.preventDefault(); e.returnValue = ''; return ''; }
});

/* In die Zwischenablage kopieren – mit Fallback fuer HTTP (kein navigator.clipboard
   ausserhalb von HTTPS/localhost, z. B. auf dem NAS unter http://<ip>/). */
async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) { /* fallback unten */ }
  }
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch (e) {
    return false;
  }
}

/* ===================== EDITOR ===================== */
/* Farbwaehler: natives color-Input neben jedem Hex-Feld, beidseitig synchron. */
const COLOR_PICKS = [
  ['mColorPick', 'mColor'], ['mBgPick', 'mBg'],
  ['mHeaderBgPick', 'mHeaderBg'], ['mHeaderColorPick', 'mHeaderColor'],
  ['mCellColorPick', 'mCellColor'], ['mBorderColorPick', 'mBorderColor'],
  ['slideBgColorPick', 'slideBgColor'],
];
const HEX6 = /^#[0-9a-fA-F]{6}$/;
function syncColorPicks() {
  COLOR_PICKS.forEach(([p, t]) => {
    const pe = document.getElementById(p), te = document.getElementById(t);
    if (pe && te && HEX6.test(te.value.trim())) pe.value = te.value.trim().toLowerCase();
  });
}
function initColorPicks() {
  COLOR_PICKS.forEach(([p, t]) => {
    const pe = document.getElementById(p), te = document.getElementById(t);
    if (!pe || !te) return;
    pe.addEventListener('input', () => {
      te.value = pe.value;
      // vorhandene Listener mitziehen (z. B. Folien-Hintergrund live + markDirty)
      te.dispatchEvent(new Event('input', {bubbles: true}));
    });
    te.addEventListener('input', () => {
      const v = te.value.trim();
      if (HEX6.test(v)) pe.value = v.toLowerCase();
    });
  });
}

/* Datums-Gueltigkeit einer Folie (Editor-Seite).
   "Heute" kommt vom Server in der Settings-Zeitzone (APP.today) - dieselbe Basis
   wie das Display; die Browser-Uhr ist nur Fallback. (Wert stammt vom Seiten-
   Load; eine ueber Mitternacht offene Admin-Seite zeigt bis zum Reload den
   alten Tag - bewusst in Kauf genommen.)
   Rueckgabe: '' = keine Einschraenkung, 'on' = aktuell sichtbar, 'off' = zurzeit inaktiv. */
function slideValidState(s) {
  const from = s.valid_from || '', until = s.valid_until || '';
  if (!from && !until) return '';
  let today = APP.today;
  if (!today) {
    const d = new Date();
    today = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  if (from && today < from) return 'off';
  if (until && today > until) return 'off';
  return 'on';
}
function fmtDate(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
  return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
}
function slideValidLabel(s) {
  const from = s.valid_from || '', until = s.valid_until || '';
  if (from && until) return fmtDate(from) + ' – ' + fmtDate(until);
  if (from) return 'ab ' + fmtDate(from);
  if (until) return 'bis ' + fmtDate(until);
  return '';
}

function getSlide() { return state.slides.find(s => s.id === state.selectedSlideId) || null; }
function getBlock() { const s = getSlide(); return s ? (s.blocks || []).find(b => b.id === state.selectedBlockId) || null : null; }

function ensureEditorSelection() {
  if (!state.slides.length) { state.selectedSlideId = null; state.selectedBlockId = null; return; }
  if (!getSlide()) state.selectedSlideId = state.slides[0].id;
  const s = getSlide();
  if (!s.blocks || !s.blocks.some(b => b.id === state.selectedBlockId)) {
    state.selectedBlockId = s.blocks && s.blocks[0] ? s.blocks[0].id : null;
  }
}

function newSlide() {
  return {id: uid('slide_'), name: 'Neue Folie', bg_color: '#1a1a2e', bg_image: '', duration: Number(APP.settings?.system?.default_slide_duration || 10), valid_from: '', valid_until: '', blocks: []};
}
function newBlock(type, pos) {
  const d = B.defaults(type);
  d.id = uid('block_');
  d.x = pos ? clamp(pos.x, 0, 1900) : 120;
  d.y = pos ? clamp(pos.y, 0, 1060) : 120;
  return d;
}

function canvasPoint(canvas, cx, cy) {
  const r = canvas.getBoundingClientRect();
  return {
    x: Math.round(clamp((cx - r.left) / r.width * 1920, 0, 1920)),
    y: Math.round(clamp((cy - r.top) / r.height * 1080, 0, 1080)),
  };
}

function addBlock(type, pos) {
  const s = getSlide();
  if (!s) { toast('Erst eine Folie anlegen.', 'err'); return; }
  const b = newBlock(type, pos);
  s.blocks = s.blocks || [];
  s.blocks.push(b);
  state.selectedBlockId = b.id;
  markDirty();
  renderEditor();
}

/* ----- Snap ----- */
function snapCandidates(slide, excludeId) {
  const x = [0, 960, 1920], y = [0, 540, 1080];
  (slide.blocks || []).forEach(b => {
    if (b.id === excludeId) return;
    const bx = Number(b.x || 0), by = Number(b.y || 0), bw = Number(b.w || 0), bh = Number(b.h || 0);
    x.push(bx, bx + bw / 2, bx + bw);
    y.push(by, by + bh / 2, by + bh);
  });
  return {x, y};
}
function applySnap(block, nx, ny, cand, thr) {
  thr = thr || 14;
  const w = Number(block.w || 0), h = Number(block.h || 0);
  const xp = [{v: nx, o: 0}, {v: nx + w / 2, o: w / 2}, {v: nx + w, o: w}];
  const yp = [{v: ny, o: 0}, {v: ny + h / 2, o: h / 2}, {v: ny + h, o: h}];
  let bx = {d: Infinity, s: nx, g: null}, by = {d: Infinity, s: ny, g: null};
  xp.forEach(p => cand.x.forEach(l => { const d = Math.abs(p.v - l); if (d < thr && d < bx.d) bx = {d, s: l - p.o, g: l}; }));
  yp.forEach(p => cand.y.forEach(l => { const d = Math.abs(p.v - l); if (d < thr && d < by.d) by = {d, s: l - p.o, g: l}; }));
  return {x: bx.g === null ? nx : bx.s, y: by.g === null ? ny : by.s, guides: {x: bx.g === null ? [] : [bx.g], y: by.g === null ? [] : [by.g]}};
}

/* ----- Render ----- */
// Folien per Drag&Drop umsortieren -> ordnet NUR die Editor-Liste (Organisation).
// Die Anzeige-Reihenfolge auf dem Display steckt in der Playlist (slide_ids) und
// wird dort per Drag&Drop gesetzt (siehe initPlaylists / movePlSlide).
function moveSlide(from, to) {
  if (from == null || from < 0) return;
  const arr = state.slides;
  if (to > from) to--; // nach dem Entfernen verschieben sich die Indizes
  if (to === from || to < 0 || to > arr.length) { if (to === from) return; }
  const [item] = arr.splice(from, 1);
  arr.splice(Math.max(0, Math.min(arr.length, to)), 0, item);
  markDirty();
  renderEditor();
}
function renderSlidesList() {
  const list = document.getElementById('slidesList');
  list.innerHTML = '';
  if (!state.slides.length) { list.innerHTML = '<div class="muted">Noch keine Folien.</div>'; return; }
  const clearDrop = () => list.querySelectorAll('.slide-item-btn').forEach(x => x.classList.remove('drop-before', 'drop-after'));
  state.slides.forEach((s, idx) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.draggable = true;
    btn.className = 'slide-item-btn' + (s.id === state.selectedSlideId ? ' active' : '');
    // Datums-Gueltigkeit als Badge (rot, wenn die Folie zurzeit nicht angezeigt wird).
    const vState = slideValidState(s);
    const vBadge = vState ? `<span class="si-valid${vState === 'off' ? ' off' : ''}" title="Gültigkeitszeitraum${vState === 'off' ? ' – zurzeit nicht auf dem Display' : ''}">📅 ${esc(slideValidLabel(s))}${vState === 'off' ? ' · inaktiv' : ''}</span>` : '';
    btn.innerHTML = `<span class="si-grip" title="Ziehen zum Umsortieren">⠿</span><span class="si-info"><strong>${esc(s.name || 'Folie')}</strong><small>${(s.blocks || []).length} Blöcke · ${Number(s.duration || 10)}s</small>${vBadge}</span>`;
    const del = document.createElement('span');
    del.className = 'si-del';
    del.textContent = '✕';
    del.title = 'Folie löschen';
    del.addEventListener('click', (e) => {
      e.stopPropagation();
      if (state.slides.length <= 1) { toast('Mindestens eine Folie nötig.', 'err'); return; }
      state.slides = state.slides.filter(x => x.id !== s.id);
      if (state.selectedSlideId === s.id) state.selectedSlideId = state.slides[0].id;
      markDirty();
      renderEditor();
    });
    btn.appendChild(del);
    btn.addEventListener('click', () => { state.selectedSlideId = s.id; state.selectedBlockId = (s.blocks && s.blocks[0]) ? s.blocks[0].id : null; renderEditor(); });
    // Drag&Drop
    btn.addEventListener('dragstart', (e) => {
      state.slideDrag = idx;
      btn.classList.add('dragging');
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(idx)); } catch (_) {} }
    });
    btn.addEventListener('dragend', () => { btn.classList.remove('dragging'); clearDrop(); state.slideDrag = null; });
    btn.addEventListener('dragover', (e) => {
      if (state.slideDrag == null) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      const r = btn.getBoundingClientRect();
      const after = (e.clientY - r.top) > r.height / 2;
      clearDrop();
      btn.classList.add(after ? 'drop-after' : 'drop-before');
    });
    btn.addEventListener('drop', (e) => {
      if (state.slideDrag == null) return;
      e.preventDefault();
      const r = btn.getBoundingClientRect();
      const after = (e.clientY - r.top) > r.height / 2;
      const to = idx + (after ? 1 : 0);
      const from = state.slideDrag;
      clearDrop();
      state.slideDrag = null;
      moveSlide(from, to);
    });
    list.appendChild(btn);
  });
}

function renderBlockList() {
  const list = document.getElementById('blockList');
  const s = getSlide();
  list.innerHTML = '';
  if (!s || !s.blocks || !s.blocks.length) { list.innerHTML = '<div class="muted">Keine Blöcke</div>'; return; }
  // Liste von oben (vorderste Ebene) nach unten (hinterste) anzeigen.
  s.blocks.slice().reverse().forEach((b) => {
    const idx = s.blocks.indexOf(b);
    const meta = B.TYPES[b.type] || B.TYPES.text;
    const row = document.createElement('div');
    row.className = 'block-pill' + (b.id === state.selectedBlockId ? ' active' : '');
    const info = document.createElement('button');
    info.type = 'button';
    info.className = 'block-pill-info';
    info.innerHTML = `<strong>${meta.icon} ${esc(meta.label)}</strong><small>${Math.round(b.w)}×${Math.round(b.h)}</small>`;
    info.addEventListener('click', () => { state.selectedBlockId = b.id; updateSelection(); });
    const ctrl = document.createElement('div');
    ctrl.className = 'layer-ctrl';
    const up = document.createElement('button');
    up.type = 'button'; up.textContent = '▲'; up.title = 'Nach vorne'; up.disabled = idx === s.blocks.length - 1;
    up.addEventListener('click', (e) => { e.stopPropagation(); moveBlock(b.id, 1); });
    const down = document.createElement('button');
    down.type = 'button'; down.textContent = '▼'; down.title = 'Nach hinten'; down.disabled = idx === 0;
    down.addEventListener('click', (e) => { e.stopPropagation(); moveBlock(b.id, -1); });
    ctrl.appendChild(up); ctrl.appendChild(down);
    row.appendChild(info); row.appendChild(ctrl);
    list.appendChild(row);
  });
}

function moveBlock(id, dir) {
  const s = getSlide();
  if (!s || !Array.isArray(s.blocks)) return;
  const i = s.blocks.findIndex(b => b.id === id);
  if (i < 0) return;
  const j = i + dir;
  if (j < 0 || j >= s.blocks.length) return;
  const tmp = s.blocks[i];
  s.blocks[i] = s.blocks[j];
  s.blocks[j] = tmp;
  state.selectedBlockId = id;
  markDirty();
  renderEditor();
}

function renderCanvas() {
  const canvas = document.getElementById('studioCanvas');
  const s = getSlide();
  canvas.innerHTML = '';
  if (!s) { canvas.style.background = '#1a1a2e'; canvas.innerHTML = '<div class="canvas-empty">Lege links eine Folie an und ziehe Module aus der Leiste hierher.</div>'; return; }
  canvas.style.background = s.bg_image ? `${s.bg_color || '#1a1a2e'} url(${s.bg_image}) center/cover no-repeat` : (s.bg_color || '#1a1a2e');

  // Schriften aus dem 1920-Koordinatensystem auf die Canvas-Breite herunterskalieren.
  const scale = (canvas.clientWidth || 1920) / 1920;
  const defaultCity = (APP.settings && APP.settings.weather && APP.settings.weather.location) || 'Zurich';
  (s.blocks || []).forEach(block => {
    const node = B.render(block, 'editor', {scale, defaultCity});
    node.dataset.blockId = block.id;
    attachBlockPointer(node, block);
    if (block.id === state.selectedBlockId) decorateSelected(node, block);
    canvas.appendChild(node);
  });

  drawSnapGuides(canvas);
  B.applyLive(canvas, {weatherEndpoint: WEATHER_ENDPOINT, rssEndpoint: '../api/rss.php'});
}

// Pointer-/Doppelklick-Handler. Wichtig: beim Klick NICHT das ganze Canvas neu
// aufbauen – sonst wird der angeklickte Block mitten in der Interaktion zerstoert
// und Doppelklick/Buttons funktionieren nie.
function attachBlockPointer(node, block) {
  const canvas = document.getElementById('studioCanvas');
  node.addEventListener('dblclick', (e) => { e.preventDefault(); openModal(block.id); });
  node.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    if (e.target.closest('.block-overlay-menu') || e.target.closest('.resize-handle')) return;
    const wasSelected = state.selectedBlockId === block.id;
    state.selectedBlockId = block.id;
    if (!wasSelected) updateSelection();
    const p = canvasPoint(canvas, e.clientX, e.clientY);
    state.drag = {id: block.id, node, start: {x: e.clientX, y: e.clientY}, offset: {x: p.x - Number(block.x || 0), y: p.y - Number(block.y || 0)}, moved: false};
  });
}

function decorateSelected(node, block) {
  const s = getSlide();
  if (!s) return;
  node.classList.add('active');
  const menu = document.createElement('div');
  menu.className = 'block-overlay-menu';
  const mk = (txt, title, cls, fn) => {
    const b2 = document.createElement('button'); b2.type = 'button'; b2.textContent = txt; b2.title = title; if (cls) b2.className = cls;
    b2.addEventListener('click', (ev) => { ev.stopPropagation(); ev.preventDefault(); fn(); });
    b2.addEventListener('pointerdown', (ev) => ev.stopPropagation());
    return b2;
  };
  menu.appendChild(mk('✎', 'Bearbeiten', '', () => openModal(block.id)));
  menu.appendChild(mk('⧉', 'Duplizieren', '', () => {
    const dup = JSON.parse(JSON.stringify(block));
    dup.id = uid('block_');
    dup.x = clamp(Number(block.x || 0) + 40, 0, 1900);
    dup.y = clamp(Number(block.y || 0) + 40, 0, 1060);
    s.blocks.push(dup); state.selectedBlockId = dup.id; markDirty(); renderEditor();
  }));
  menu.appendChild(mk('🗑', 'Löschen', 'danger', () => {
    s.blocks = s.blocks.filter(x => x.id !== block.id);
    state.selectedBlockId = s.blocks[0] ? s.blocks[0].id : null; markDirty(); renderEditor();
  }));
  node.appendChild(menu);

  const handle = document.createElement('span');
  handle.className = 'resize-handle';
  handle.addEventListener('pointerdown', (e) => {
    e.preventDefault(); e.stopPropagation();
    state.resize = {id: block.id, node, start: {x: e.clientX, y: e.clientY}, rect: {x: Number(block.x || 0), y: Number(block.y || 0), w: Number(block.w || 0), h: Number(block.h || 0)}};
  });
  node.appendChild(handle);
}

// Selektion wechseln, OHNE die Block-Nodes neu zu bauen (haelt Doppelklick stabil).
function updateSelection() {
  const canvas = document.getElementById('studioCanvas');
  const s = getSlide();
  if (!s) return;
  canvas.querySelectorAll('.sb-block').forEach(node => {
    node.querySelector('.block-overlay-menu')?.remove();
    node.querySelector('.resize-handle')?.remove();
    node.classList.remove('active');
    if (node.dataset.blockId === state.selectedBlockId) {
      const blk = s.blocks.find(b => b.id === node.dataset.blockId);
      if (blk) decorateSelected(node, blk);
    }
  });
  renderBlockList();
  renderSlideFields();
}

function drawSnapGuides(canvas) {
  canvas.querySelectorAll('.snap-line').forEach(n => n.remove());
  state.snap.x.forEach(l => { const g = document.createElement('div'); g.className = 'snap-line v'; g.style.left = (l / 1920 * 100) + '%'; canvas.appendChild(g); });
  state.snap.y.forEach(l => { const g = document.createElement('div'); g.className = 'snap-line h'; g.style.top = (l / 1080 * 100) + '%'; canvas.appendChild(g); });
}

function renderSlideFields() {
  const s = getSlide();
  const map = {slideName: 'name', slideId: 'id', slideDuration: 'duration', slideBgColor: 'bg_color', slideBgImage: 'bg_image', slideValidFrom: 'valid_from', slideValidUntil: 'valid_until'};
  Object.entries(map).forEach(([elId, field]) => {
    const el = document.getElementById(elId);
    if (!el) return;
    el.value = s ? (s[field] ?? '') : '';
  });
  const meta = document.getElementById('editorMeta');
  meta.textContent = s ? `${s.name || 'Folie'} · ${(s.blocks || []).length} Blöcke · ${Number(s.duration || 10)}s` : 'Noch keine Folie ausgewählt.';
  syncColorPicks();
}

function renderEditor() {
  ensureEditorSelection();
  renderSlidesList();
  renderBlockList();
  renderTemplatesList();
  renderCanvas();
  renderSlideFields();
}

/* ----- Block-Modal ----- */
function showFields(type) {
  const allow = TYPE_FIELDS[type] || TYPE_FIELDS.text;
  document.querySelectorAll('#blockModal [data-f]').forEach(el => {
    const key = el.getAttribute('data-f');
    el.classList.toggle('field-hidden', !allow.includes(key));
  });
}
function openModal(blockId) {
  const s = getSlide();
  if (!s) return;
  const b = (s.blocks || []).find(x => x.id === blockId);
  if (!b) return;
  state.modalBlockId = blockId;
  const meta = B.TYPES[b.type] || B.TYPES.text;
  document.getElementById('modalTitle').textContent = meta.label + ' bearbeiten';
  document.getElementById('modalSub').textContent = 'Doppelklick auf den Block öffnet diesen Dialog.';
  document.getElementById('mType').value = b.type;
  document.getElementById('mText').value = b.text || '';
  document.getElementById('mSrc').value = b.src || '';
  document.getElementById('mFit').value = b.fit || 'cover';
  document.getElementById('mCity').value = b.city || '';
  document.getElementById('mCity').placeholder = 'Standardort: ' + ((APP.settings && APP.settings.weather && APP.settings.weather.location) || 'Zurich');
  document.getElementById('mForecast').checked = !!b.show_forecast;
  document.getElementById('mRssUrl').value = b.url || '';
  document.getElementById('mRssCount').value = Number(b.count || 5);
  document.getElementById('mRssTime').checked = b.show_time !== false;
  document.getElementById('mRssSource').checked = !!b.show_source;
  document.getElementById('mShapeKind').value = b.kind || 'rect';
  document.getElementById('mOpacity').value = Number(b.opacity ?? 100);
  document.getElementById('mRadius').value = Number(b.radius ?? 24);
  document.getElementById('mGalleryList').value = Array.isArray(b.images) ? b.images.join('\n') : '';
  document.getElementById('mGalleryInterval').value = Number(b.interval || 6);
  document.getElementById('mClockFormat').value = b.clock_format || 'HH:MM';
  document.getElementById('mShowDate').checked = !!b.show_date;
  document.getElementById('mSpeed').value = Number(b.speed || 60);
  document.getElementById('mBg').value = b.bg || '#313244';
  document.getElementById('mUrl').value = b.url || '';
  document.getElementById('mRefresh').value = Number(b.refresh_minutes || 0);
  document.getElementById('mZoom').value = Number(b.zoom || 100);
  document.getElementById('mData').value = b.data || '';
  document.getElementById('mQLabel').value = b.label || '';
  document.getElementById('mTarget').value = b.target || '';
  document.getElementById('mCLabel').value = b.label || '';
  document.getElementById('mHtml').value = b.html || '';
  document.getElementById('mFont').value = Number(b.font_size || 42);
  document.getElementById('mColor').value = b.color || '#ffffff';
  document.getElementById('mAlign').value = b.align || 'left';
  document.getElementById('mBold').checked = !!b.bold;
  document.getElementById('mHeaderBg').value = b.header_bg || '#313244';
  document.getElementById('mHeaderColor').value = b.header_color || '#cba6f7';
  document.getElementById('mCellColor').value = b.cell_color || '#ffffff';
  document.getElementById('mBorderColor').value = b.border_color || '#45475a';
  document.getElementById('mX').value = Number(b.x || 0);
  document.getElementById('mY').value = Number(b.y || 0);
  document.getElementById('mW').value = Number(b.w || 0);
  document.getElementById('mH').value = Number(b.h || 0);
  state.tableDraft = Array.isArray(b.table_data) ? JSON.parse(JSON.stringify(b.table_data)) : [['Spalte 1', 'Spalte 2'], ['Wert', 'Wert']];
  renderTableGrid();
  showFields(b.type);
  syncColorPicks(); // Swatches an die frisch gesetzten Hex-Werte angleichen
  document.getElementById('blockModal').classList.add('open');
}
function closeModal() { state.modalBlockId = null; document.getElementById('blockModal').classList.remove('open'); }
function applyModal() {
  const s = getSlide();
  if (!s) return;
  const b = (s.blocks || []).find(x => x.id === state.modalBlockId);
  if (!b) return;
  const oldType = b.type;
  b.type = document.getElementById('mType').value;
  const t = b.type;
  if (t === 'text' || t === 'heading') { b.text = document.getElementById('mText').value; b.bold = document.getElementById('mBold').checked; }
  if (t === 'ticker') { b.text = document.getElementById('mText').value; b.speed = Number(document.getElementById('mSpeed').value || 60); b.bg = document.getElementById('mBg').value.trim() || '#313244'; }
  if (t === 'image') { b.src = document.getElementById('mSrc').value.trim(); b.fit = document.getElementById('mFit').value; }
  if (t === 'video') { b.src = document.getElementById('mSrc').value.trim(); b.fit = document.getElementById('mFit').value; }
  if (t === 'clock') { b.clock_format = document.getElementById('mClockFormat').value; b.show_date = document.getElementById('mShowDate').checked; }
  if (t === 'weather') { b.city = document.getElementById('mCity').value.trim(); b.show_forecast = document.getElementById('mForecast').checked; } // leer = globalen Standardort nutzen
  if (t === 'rss') {
    b.url = document.getElementById('mRssUrl').value.trim();
    b.count = clamp(Number(document.getElementById('mRssCount').value || 5), 1, 15);
    b.show_time = document.getElementById('mRssTime').checked;
    b.show_source = document.getElementById('mRssSource').checked;
  }
  if (t === 'shape') { b.kind = document.getElementById('mShapeKind').value; b.opacity = clamp(Number(document.getElementById('mOpacity').value || 100), 5, 100); b.radius = clamp(Number(document.getElementById('mRadius').value || 0), 0, 400); }
  if (t === 'gallery') {
    b.images = document.getElementById('mGalleryList').value.split('\n').map(s => s.trim()).filter(Boolean).slice(0, 30);
    b.interval = clamp(Number(document.getElementById('mGalleryInterval').value || 6), 2, 120);
    b.fit = document.getElementById('mFit').value;
  }
  if (t === 'webpage') { b.url = document.getElementById('mUrl').value.trim(); b.refresh_minutes = Number(document.getElementById('mRefresh').value || 0); b.zoom = Number(document.getElementById('mZoom').value || 100); }
  if (t === 'qrcode') { b.data = document.getElementById('mData').value.trim(); b.label = document.getElementById('mQLabel').value.trim(); }
  if (t === 'countdown') { b.target = document.getElementById('mTarget').value; b.label = document.getElementById('mCLabel').value.trim(); }
  if (t === 'animation') { b.html = document.getElementById('mHtml').value; }
  if (t === 'table') {
    b.table_data = state.tableDraft;
    b.header_bg = document.getElementById('mHeaderBg').value.trim() || '#313244';
    b.header_color = document.getElementById('mHeaderColor').value.trim() || '#cba6f7';
    b.cell_color = document.getElementById('mCellColor').value.trim() || '#ffffff';
    b.border_color = document.getElementById('mBorderColor').value.trim() || '#45475a';
  }
  // Gemeinsame Felder nur schreiben, wenn der Typ sie ueberhaupt nutzt
  // (keine Geisterwerte auf Bild/Webseite).
  const fields = TYPE_FIELDS[t] || [];
  if (fields.includes('font_size')) b.font_size = Number(document.getElementById('mFont').value || 42);
  if (fields.includes('color')) b.color = document.getElementById('mColor').value.trim() || '#ffffff';
  if (fields.includes('align')) b.align = document.getElementById('mAlign').value;
  // Groesse zuerst, dann Position so begrenzen, dass der Block in der Buehne bleibt.
  b.w = clamp(Number(document.getElementById('mW').value || 40), 40, 1920);
  b.h = clamp(Number(document.getElementById('mH').value || 40), 40, 1080);
  b.x = clamp(Number(document.getElementById('mX').value || 0), 0, 1920 - b.w);
  b.y = clamp(Number(document.getElementById('mY').value || 0), 0, 1080 - b.h);
  // base_w/base_h (Text-Skalierung) nur bei echtem Text<->Heading-Wechsel oder wenn fehlend zuruecksetzen.
  const scalable = (x) => x === 'text' || x === 'heading';
  if (!b.base_w || !b.base_h || (scalable(oldType) !== scalable(b.type))) { b.base_w = b.w; b.base_h = b.h; }
  state.selectedBlockId = b.id;
  markDirty();
  closeModal();
  renderEditor();
}

/* ----- Tabellen-Editor + Excel-Paste ----- */
function renderTableGrid() {
  const grid = document.getElementById('tblGrid');
  if (!grid) return;
  const data = state.tableDraft;
  let html = '<table>';
  data.forEach((row, ri) => {
    html += '<tr>';
    row.forEach((cell, ci) => {
      html += `<td><input type="text" value="${esc(cell)}" data-r="${ri}" data-c="${ci}"></td>`;
    });
    html += '</tr>';
  });
  html += '</table>';
  grid.innerHTML = html;
  grid.querySelectorAll('input').forEach(inp => {
    inp.addEventListener('input', () => {
      const r = Number(inp.dataset.r), c = Number(inp.dataset.c);
      if (state.tableDraft[r]) state.tableDraft[r][c] = inp.value;
      markDirty();
    });
  });
}
function tableNorm() {
  const cols = state.tableDraft.reduce((m, r) => Math.max(m, r.length), 1);
  state.tableDraft = state.tableDraft.map(r => { const c = r.slice(); while (c.length < cols) c.push(''); return c; });
}

/* ===================== VORLAGEN + EXPORT/IMPORT ===================== */
// Gemeinsames Datei-Format: ein Envelope mit Marker, damit Import gezielt pruefen kann.
function sbEnvelope(kind, data) {
  return {schauboard: true, kind: kind, format: 1, exported_at: new Date().toISOString(), data: data};
}
function sbSafeName(s) {
  return (String(s || 'export').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '')) || 'export';
}
function sbDownload(filename, obj) {
  const blob = new Blob([JSON.stringify(obj, null, 2)], {type: 'application/json'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
function sbReadJsonFile(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => { try { resolve(JSON.parse(r.result)); } catch (e) { reject(new Error('Keine gültige JSON-Datei.')); } };
    r.onerror = () => reject(new Error('Datei konnte nicht gelesen werden.'));
    r.readAsText(file);
  });
}
// Folie mit frischen IDs (Folie + alle Bloecke) -> kollidiert beim Import/Duplizieren nicht.
function freshSlide(slideLike) {
  const c = JSON.parse(JSON.stringify(slideLike || {}));
  c.id = uid('slide_');
  c.blocks = (c.blocks || []).map(b => { const nb = JSON.parse(JSON.stringify(b)); nb.id = uid('block_'); return nb; });
  return c;
}
function slideForTemplate(s) { const c = JSON.parse(JSON.stringify(s)); delete c.id; return c; }

/* ----- Vorlagen-Bibliothek ----- */
function renderTemplatesList() {
  const list = document.getElementById('templateList');
  if (!list) return;
  list.innerHTML = '';
  if (!state.templates.length) { list.innerHTML = '<div class="muted">Keine Vorlagen</div>'; return; }
  state.templates.forEach(t => {
    const blocks = (t.slide && t.slide.blocks) ? t.slide.blocks.length : 0;
    const row = document.createElement('div');
    row.className = 'block-pill';
    const info = document.createElement('button');
    info.type = 'button';
    info.className = 'block-pill-info';
    info.title = 'Neue Folie aus dieser Vorlage';
    info.innerHTML = `<strong>📄 ${esc(t.name || 'Vorlage')}</strong><small>${blocks} Blöcke</small>`;
    info.addEventListener('click', () => createSlideFromTemplate(t.id));
    const ctrl = document.createElement('div');
    ctrl.className = 'layer-ctrl';
    const ex = document.createElement('button');
    ex.type = 'button'; ex.textContent = '⬇'; ex.title = 'Vorlage exportieren';
    ex.addEventListener('click', (e) => { e.stopPropagation(); sbDownload('schauboard-vorlage-' + sbSafeName(t.name) + '.json', sbEnvelope('template', t)); });
    const del = document.createElement('button');
    del.type = 'button'; del.textContent = '🗑'; del.title = 'Vorlage löschen';
    del.addEventListener('click', (e) => { e.stopPropagation(); deleteTemplate(t.id); });
    ctrl.appendChild(ex); ctrl.appendChild(del);
    row.appendChild(info); row.appendChild(ctrl);
    list.appendChild(row);
  });
}
async function saveTemplates() {
  await postJson('../api/templates.php', {items: state.templates});
}
async function addTemplateFromCurrentSlide() {
  const s = getSlide();
  if (!s) { toast('Keine Folie ausgewählt.', 'err'); return; }
  const name = prompt('Name der Vorlage:', s.name || 'Vorlage');
  if (name === null) return;
  const t = {id: uid('tpl_'), name: (name.trim() || 'Vorlage'), slide: slideForTemplate(s)};
  state.templates.push(t);
  try { await saveTemplates(); renderTemplatesList(); toast('Als Vorlage gespeichert ✓'); }
  catch (e) { state.templates = state.templates.filter(x => x !== t); toast(e.message, 'err'); }
}
function createSlideFromTemplate(id) {
  const t = state.templates.find(x => x.id === id);
  if (!t || !t.slide) return;
  const ns = freshSlide(t.slide);
  ns.name = t.name || ns.name || 'Neue Folie';
  state.slides.push(ns);
  state.selectedSlideId = ns.id;
  markDirty();
  renderEditor();
  toast('Folie aus Vorlage erstellt ✓');
}
async function deleteTemplate(id) {
  const t = state.templates.find(x => x.id === id);
  if (!t) return;
  if (!confirm('Vorlage „' + (t.name || '') + '" löschen?')) return;
  const before = state.templates.slice();
  state.templates = state.templates.filter(x => x.id !== id);
  try { await saveTemplates(); renderTemplatesList(); toast('Vorlage gelöscht'); }
  catch (e) { state.templates = before; renderTemplatesList(); toast(e.message, 'err'); }
}

/* ----- Einzel-Folie Export/Import ----- */
function exportCurrentSlide() {
  const s = getSlide();
  if (!s) { toast('Keine Folie ausgewählt.', 'err'); return; }
  sbDownload('schauboard-folie-' + sbSafeName(s.name) + '.json', sbEnvelope('slide', slideForTemplate(s)));
}
async function importSlideOrTemplateFile(file) {
  let env;
  try { env = await sbReadJsonFile(file); } catch (e) { toast(e.message, 'err'); return; }
  if (!env || env.schauboard !== true || !env.data) { toast('Keine Schauboard-Datei.', 'err'); return; }
  if (env.kind === 'slide') {
    const ns = freshSlide(env.data);
    if (!ns.name) ns.name = 'Importierte Folie';
    state.slides.push(ns);
    state.selectedSlideId = ns.id;
    markDirty();
    renderEditor();
    toast('Folie importiert ✓ – zum Sichern „Speichern" klicken.');
  } else if (env.kind === 'template') {
    const t = env.data;
    t.id = uid('tpl_');
    if (!t.name) t.name = 'Importierte Vorlage';
    if (!t.slide && t.blocks) t.slide = t; // Toleranz fuer aeltere/abweichende Formate
    state.templates.push(t);
    try { await saveTemplates(); renderTemplatesList(); toast('Vorlage importiert ✓'); }
    catch (e) { state.templates = state.templates.filter(x => x !== t); toast(e.message, 'err'); }
  } else if (env.kind === 'backup') {
    toast('Das ist ein Komplett-Backup – bitte unter „Einstellungen" importieren.', 'err');
  } else {
    toast('Unbekannter Dateityp.', 'err');
  }
}

/* ----- Komplett-Backup (Sichern & Umzug) ----- */
async function exportBackup() {
  try {
    const res = await fetch('../api/backup.php', {headers: {'Accept': 'application/json'}});
    const env = await res.json();
    if (!env || env.schauboard !== true) throw new Error('Export fehlgeschlagen.');
    const stamp = (env.exported_at || new Date().toISOString()).slice(0, 10);
    sbDownload('schauboard-backup-' + stamp + '.json', env);
    toast('Backup exportiert ✓');
  } catch (e) { toast(e.message, 'err'); }
}
async function importBackupFile(file) {
  let env;
  try { env = await sbReadJsonFile(file); } catch (e) { toast(e.message, 'err'); return; }
  if (!env || env.schauboard !== true || env.kind !== 'backup' || !env.data) { toast('Kein Schauboard-Backup.', 'err'); return; }
  if (!confirm('ACHTUNG: Das Backup ERSETZT alle aktuellen Inhalte (Folien, Playlists, Displays, Zeitpläne, Einstellungen, Vorlagen).\n\nFortfahren?')) return;
  try {
    await postJson('../api/backup.php', {data: env.data});
    toast('Backup eingespielt ✓ – Seite lädt neu …');
    setTimeout(() => location.reload(), 1500);
  } catch (e) { toast(e.message, 'err'); }
}

/* ===================== PLAYLISTS ===================== */
function initPlaylists() {
  const container = document.getElementById('itemContainer');

  // Eine Folie innerhalb der Playlist umsortieren -> DAS ist die Anzeige-Reihenfolge
  // auf dem Display (display/index.php iteriert slide_ids in dieser Reihenfolge).
  function movePlSlide(pl, from, to) {
    const arr = pl.slide_ids;
    if (from == null || from < 0 || from >= arr.length) return;
    if (to > from) to--; // nach dem Entfernen verschieben sich die Indizes
    to = Math.max(0, Math.min(arr.length, to));
    if (to === from) return;
    const [it] = arr.splice(from, 1);
    arr.splice(to, 0, it);
    markDirty();
    render();
  }

  function render() {
    container.innerHTML = '';
    const hint = document.getElementById('emptyHint');
    if (!state.playlists.length) { hint.style.display = 'block'; hint.textContent = 'Noch keine Playlists – mit „+ Playlist" eine anlegen.'; }
    else hint.style.display = 'none';
    const slideName = id => { const s = state.slides.find(x => x.id === id); return s ? (s.name || s.id) : id; };
    // Datums-Gueltigkeit sichtbar machen: 📅 = Zeitraum gesetzt, "inaktiv" = zurzeit nicht auf dem Display.
    const validMark = id => {
      const s = state.slides.find(x => x.id === id);
      if (!s) return '';
      const v = slideValidState(s);
      if (!v) return '';
      return `<span class="pl-valid${v === 'off' ? ' off' : ''}" title="Gültig: ${esc(slideValidLabel(s))}">📅</span>${v === 'off' ? '<span class="pl-inactive" title="Ausserhalb des Gültigkeitszeitraums – wird übersprungen">inaktiv</span>' : ''}`;
    };
    state.playlists.forEach(pl => {
      // Nur noch existierende Folien behalten (geloeschte Folien aus der Reihenfolge werfen).
      pl.slide_ids = (Array.isArray(pl.slide_ids) ? pl.slide_ids : []).filter(id => state.slides.some(s => s.id === id));
      const card = document.createElement('article');
      card.className = 'item-card';
      const rows = pl.slide_ids.map((id, i) =>
        `<div class="pl-slide" draggable="true" data-idx="${i}"><span class="si-grip" title="Ziehen zum Sortieren">⠿</span><span class="pl-slide-name"><span class="pl-num">${i + 1}.</span> ${esc(slideName(id))}</span>${validMark(id)}<span class="pl-del" data-rm="${esc(id)}" title="Aus Playlist entfernen">✕</span></div>`
      ).join('') || '<div class="muted">Noch keine Folien – unten hinzufügen.</div>';
      const notIn = state.slides.filter(s => !pl.slide_ids.includes(s.id));
      let adder;
      if (!state.slides.length) adder = '<div class="muted" style="margin-top:10px;">Noch keine Folien vorhanden – erst im Editor anlegen.</div>';
      else if (!notIn.length) adder = '<div class="muted" style="margin-top:10px;">Alle Folien sind in dieser Playlist.</div>';
      else adder = `<div class="row" style="margin-top:10px;"><select data-addsel style="flex:1;"><option value="">+ Folie hinzufügen …</option>${notIn.map(s => `<option value="${esc(s.id)}">${esc(s.name || s.id)}</option>`).join('')}</select></div>`;
      card.innerHTML = `
        <div class="head"><strong>${esc(pl.name || 'Playlist')}</strong>
          <button type="button" class="btn small danger" data-del>Entfernen</button></div>
        <label class="field">Name<input type="text" data-name value="${esc(pl.name || '')}"></label>
        <div><div class="muted" style="margin-bottom:8px;">Folien in dieser Playlist – ziehen zum Sortieren (Reihenfolge = Anzeige-Reihenfolge auf dem Display):</div>
          <div class="pl-slides">${rows}</div>
          ${adder}</div>`;
      card.querySelector('[data-del]').addEventListener('click', () => { state.playlists = state.playlists.filter(x => x !== pl); markDirty(); render(); });
      card.querySelector('[data-name]').addEventListener('input', e => { pl.name = e.target.value; markDirty(); });
      const addSel = card.querySelector('[data-addsel]');
      if (addSel) addSel.addEventListener('change', () => { if (addSel.value) { pl.slide_ids.push(addSel.value); markDirty(); render(); } });
      card.querySelectorAll('[data-rm]').forEach(el => el.addEventListener('click', () => { const id = el.getAttribute('data-rm'); pl.slide_ids = pl.slide_ids.filter(x => x !== id); markDirty(); render(); }));
      // Drag&Drop-Sortierung innerhalb der Playlist
      const clearDrop = () => card.querySelectorAll('.pl-slide').forEach(x => x.classList.remove('drop-before', 'drop-after'));
      let dragFrom = null;
      card.querySelectorAll('.pl-slide').forEach(row => {
        row.addEventListener('dragstart', e => { dragFrom = Number(row.dataset.idx); row.classList.add('dragging'); if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', row.dataset.idx); } catch (_) {} } });
        row.addEventListener('dragend', () => { row.classList.remove('dragging'); clearDrop(); dragFrom = null; });
        row.addEventListener('dragover', e => { if (dragFrom == null) return; e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'; const r = row.getBoundingClientRect(); const after = (e.clientY - r.top) > r.height / 2; clearDrop(); row.classList.add(after ? 'drop-after' : 'drop-before'); });
        row.addEventListener('drop', e => { if (dragFrom == null) return; e.preventDefault(); const r = row.getBoundingClientRect(); const after = (e.clientY - r.top) > r.height / 2; const to = Number(row.dataset.idx) + (after ? 1 : 0); const from = dragFrom; clearDrop(); dragFrom = null; movePlSlide(pl, from, to); });
      });
      container.appendChild(card);
    });
  }
  render();
  document.getElementById('addPlaylist').addEventListener('click', () => { state.playlists.push({id: uid('playlist_'), name: 'Neue Playlist', slide_ids: []}); markDirty(); render(); });
  document.getElementById('savePlaylists').addEventListener('click', async () => {
    state.playlists.forEach(pl => { if (!pl.id) pl.id = slug(pl.name) || uid('playlist_'); });
    try { await postJson('../api/playlists.php', {items: state.playlists}); clearDirty(); toast('Playlists gespeichert ✓'); } catch (e) { toast(e.message, 'err'); }
  });
}

/* ===================== DISPLAYS (Multi-Monitor, einfach) ===================== */
function isOnline(id) {
  const ts = state.heartbeats[id];
  if (!ts) return false;
  const age = (Date.now() - new Date(ts).getTime()) / 60000;
  return age < (APP.offlineTimeoutMin || 5);
}
function initDisplays() {
  const container = document.getElementById('itemContainer');
  function playlistOptions(sel) {
    return state.playlists.map(pl => `<option value="${esc(pl.id)}" ${pl.id === sel ? 'selected' : ''}>${esc(pl.name || pl.id)}</option>`).join('');
  }
  function render() {
    container.innerHTML = '';
    state.displays.forEach(d => {
      const url = ROOT + '?display=' + encodeURIComponent(d.id);
      const online = isOnline(d.id);
      const card = document.createElement('article');
      card.className = 'item-card';
      card.innerHTML = `
        <div class="head">
          <div><strong>${esc(d.name || 'Display')}</strong>
            <div class="status-dot ${online ? 'online' : ''}"><span class="dot"></span>${online ? 'Online' : 'Offline / noch kein Heartbeat'}</div></div>
          <button type="button" class="btn small danger" data-del>Entfernen</button>
        </div>
        <div class="form-grid">
          <label class="field">Name<input type="text" data-name value="${esc(d.name || '')}"></label>
          <label class="field">Zeigt Playlist<select data-playlist>${playlistOptions(d.default_playlist_id)}</select></label>
        </div>
        <div class="url-row">
          <input class="url-pill" type="text" readonly value="${esc(url)}" title="Klicken markiert die URL – dann Strg+C" onclick="this.select();this.setSelectionRange(0,this.value.length);">
          <button type="button" class="btn small" data-copy>📋 URL kopieren</button>
          <a class="btn small" href="${esc(url)}" target="_blank" rel="noreferrer">↗ Öffnen</a>
          <img alt="QR" width="56" height="56" style="border-radius:8px;background:#fff;padding:3px;" src="${esc(B.qrSrc(url, 120))}">
        </div>
        <details><summary class="muted" style="cursor:pointer;">Erweitert</summary>
          <div class="form-grid" style="margin-top:10px;">
            <label class="field">ID (Teil der URL)<input type="text" data-id value="${esc(d.id || '')}"></label>
            <label class="field">Token (optional)<input type="text" data-token value="${esc(d.token || '')}"></label>
          </div>
        </details>`;
      card.querySelector('[data-del]').addEventListener('click', () => { state.displays = state.displays.filter(x => x !== d); markDirty(); render(); });
      card.querySelector('[data-name]').addEventListener('input', e => { d.name = e.target.value; markDirty(); });
      card.querySelector('[data-playlist]').addEventListener('change', e => { d.default_playlist_id = e.target.value; markDirty(); });
      card.querySelector('[data-id]').addEventListener('input', e => { d.id = slug(e.target.value); markDirty(); render(); });
      card.querySelector('[data-token]').addEventListener('input', e => { d.token = e.target.value; markDirty(); });
      card.querySelector('[data-copy]').addEventListener('click', async () => {
        const ok = await copyText(url);
        if (ok) { toast('URL kopiert ✓'); return; }
        // Fallback: URL-Feld markieren, damit der Nutzer mit Strg+C kopieren kann.
        const pill = card.querySelector('.url-pill');
        if (pill && pill.select) { pill.focus(); pill.select(); pill.setSelectionRange(0, pill.value.length); }
        toast('URL ist markiert – mit Strg+C kopieren.', 'err');
      });
      container.appendChild(card);
    });
  }
  render();
  document.getElementById('addDisplay').addEventListener('click', () => {
    const name = 'Display ' + (state.displays.length + 1);
    state.displays.push({id: slug(name) || uid('display_'), name, default_playlist_id: state.playlists[0]?.id || 'playlist_default', token: '', last_seen_at: null});
    markDirty();
    render();
  });
  document.getElementById('saveDisplays').addEventListener('click', async () => {
    const ids = new Set();
    for (const d of state.displays) {
      if (!d.id) d.id = slug(d.name) || uid('display_');
      if (ids.has(d.id)) { toast('Doppelte Display-ID: ' + d.id, 'err'); return; }
      ids.add(d.id);
    }
    try { await postJson('../api/displays.php', {items: state.displays}); clearDirty(); toast('Displays gespeichert ✓'); } catch (e) { toast(e.message, 'err'); }
  });
}

/* ===================== SCHEDULES ===================== */
const DAYS = [['mon', 'Mo'], ['tue', 'Di'], ['wed', 'Mi'], ['thu', 'Do'], ['fri', 'Fr'], ['sat', 'Sa'], ['sun', 'So']];
function initSchedules() {
  const container = document.getElementById('itemContainer');
  const hint = document.getElementById('emptyHint');
  function displayOptions(sel) { return state.displays.map(d => `<option value="${esc(d.id)}" ${d.id === sel ? 'selected' : ''}>${esc(d.name || d.id)}</option>`).join(''); }
  function playlistOptions(sel) { return state.playlists.map(p => `<option value="${esc(p.id)}" ${p.id === sel ? 'selected' : ''}>${esc(p.name || p.id)}</option>`).join(''); }
  function render() {
    container.innerHTML = '';
    if (!state.schedules.length) { hint.style.display = 'block'; hint.textContent = 'Noch keine Zeitpläne. Ohne Zeitplan zeigt jedes Display einfach seine zugewiesene Playlist.'; }
    else hint.style.display = 'none';
    state.schedules.forEach(sc => {
      const card = document.createElement('article');
      card.className = 'item-card';
      const days = sc.days || [];
      card.innerHTML = `
        <div class="head"><strong>${esc(sc.name || 'Zeitplan')}</strong><button type="button" class="btn small danger" data-del>Entfernen</button></div>
        <div class="form-grid">
          <label class="field">Name<input type="text" data-name value="${esc(sc.name || '')}"></label>
          <label class="field">Display<select data-display>${displayOptions(sc.display_id)}</select></label>
          <label class="field">Playlist<select data-playlist>${playlistOptions(sc.playlist_id)}</select></label>
          <div class="form-grid" style="grid-template-columns:1fr 1fr;">
            <label class="field">Von<input type="time" data-from value="${esc(sc.from || '08:00')}"></label>
            <label class="field">Bis<input type="time" data-to value="${esc(sc.to || '17:00')}"></label>
          </div>
        </div>
        <div><div class="muted" style="margin-bottom:6px;">Aktive Tage:</div>
          <div class="day-picker">${DAYS.map(([k, l]) => `<span class="day-chip ${days.includes(k) ? 'on' : ''}" data-day="${k}">${l}</span>`).join('')}</div></div>`;
      card.querySelector('[data-del]').addEventListener('click', () => { state.schedules = state.schedules.filter(x => x !== sc); markDirty(); render(); });
      card.querySelector('[data-name]').addEventListener('input', e => { sc.name = e.target.value; markDirty(); });
      card.querySelector('[data-display]').addEventListener('change', e => { sc.display_id = e.target.value; markDirty(); });
      card.querySelector('[data-playlist]').addEventListener('change', e => { sc.playlist_id = e.target.value; markDirty(); });
      card.querySelector('[data-from]').addEventListener('input', e => { sc.from = e.target.value; markDirty(); });
      card.querySelector('[data-to]').addEventListener('input', e => { sc.to = e.target.value; markDirty(); });
      card.querySelectorAll('[data-day]').forEach(chip => chip.addEventListener('click', () => {
        const k = chip.dataset.day;
        sc.days = sc.days || [];
        if (sc.days.includes(k)) sc.days = sc.days.filter(x => x !== k); else sc.days.push(k);
        chip.classList.toggle('on');
        markDirty();
      }));
      container.appendChild(card);
    });
  }
  render();
  document.getElementById('addSchedule').addEventListener('click', () => {
    state.schedules.push({id: uid('schedule_'), name: 'Neuer Zeitplan', display_id: state.displays[0]?.id || 'default', playlist_id: state.playlists[0]?.id || 'playlist_default', from: '08:00', to: '17:00', days: ['mon', 'tue', 'wed', 'thu', 'fri']});
    markDirty();
    render();
  });
  document.getElementById('saveSchedules').addEventListener('click', async () => {
    try { await postJson('../api/schedules.php', {items: state.schedules}); clearDirty(); toast('Zeitpläne gespeichert ✓'); } catch (e) { toast(e.message, 'err'); }
  });
}

/* ===================== SETTINGS ===================== */
function initSettings() {
  const form = document.getElementById('settingsForm');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
      branding: {name: form.branding_name.value.trim()},
      system: {
        timezone: form.timezone.value.trim(),
        default_slide_duration: Number(form.default_slide_duration.value || 10),
        default_transition: form.default_transition.value,
        offline_timeout_minutes: Number(form.offline_timeout_minutes.value || 5),
      },
      weather: {enabled: form.weather_enabled.checked, location: form.weather_location.value.trim()},
      maintenance: {enabled: form.maintenance_enabled.checked, message: form.maintenance_message.value.trim()},
    };
    try { await postJson('../api/settings.php', payload); clearDirty(); toast('Einstellungen gespeichert ✓'); } catch (err) { toast(err.message, 'err'); }
  });
  form.addEventListener('input', markDirty);

  // Speichern-Knopf oben in der Kopfzeile (wie in den anderen Bereichen) -> kein Scrollen noetig.
  const topSave = document.getElementById('saveSettingsTop');
  if (topSave) topSave.addEventListener('click', () => form.requestSubmit());

  // Sichern & Umzug (Komplett-Backup)
  document.getElementById('exportBackupBtn').addEventListener('click', exportBackup);
  const importBackupInput = document.getElementById('importBackupInput');
  document.getElementById('importBackupBtn').addEventListener('click', () => importBackupInput.click());
  importBackupInput.addEventListener('change', (e) => {
    const f = e.target.files[0];
    if (f) importBackupFile(f);
    e.target.value = '';
  });
}

/* ===================== EDITOR INIT ===================== */
function initEditor() {
  // Werkzeugleiste aus den Block-Typen aufbauen
  const palette = document.getElementById('toolPalette');
  Object.keys(B.TYPES).forEach(type => {
    const meta = B.TYPES[type];
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tool-btn';
    btn.draggable = true;
    btn.innerHTML = `<span class="ic">${meta.icon}</span>${esc(meta.label)}`;
    btn.addEventListener('click', () => addBlock(type));
    btn.addEventListener('dragstart', e => { state.dragTool = type; e.dataTransfer?.setData('text/plain', type); });
    btn.addEventListener('dragend', () => { state.dragTool = null; });
    palette.appendChild(btn);
  });

  initColorPicks();
  ensureEditorSelection();
  renderEditor();

  // Folien-Felder
  [['slideName', 'name'], ['slideId', 'id'], ['slideDuration', 'duration'], ['slideBgColor', 'bg_color'], ['slideBgImage', 'bg_image'], ['slideValidFrom', 'valid_from'], ['slideValidUntil', 'valid_until']].forEach(([elId, field]) => {
    const el = document.getElementById(elId);
    el.addEventListener('input', () => {
      const s = getSlide(); if (!s) return;
      s[field] = field === 'duration' ? Number(el.value || 10) : el.value;
      if (field === 'id') state.selectedSlideId = s.id;
      if (field === 'bg_color' || field === 'bg_image') renderCanvas();
      if (field === 'name' || field === 'valid_from' || field === 'valid_until') renderSlidesList();
      markDirty();
    });
  });

  document.getElementById('addSlide').addEventListener('click', () => { const s = newSlide(); state.slides.push(s); state.selectedSlideId = s.id; state.selectedBlockId = null; markDirty(); renderEditor(); });

  // Vorlagen + Einzel-Export/-Import
  document.getElementById('saveAsTemplate').addEventListener('click', addTemplateFromCurrentSlide);
  document.getElementById('exportSlideBtn').addEventListener('click', exportCurrentSlide);
  const importSlideInput = document.getElementById('importSlideInput');
  document.getElementById('importSlideBtn').addEventListener('click', () => importSlideInput.click());
  importSlideInput.addEventListener('change', (e) => {
    const f = e.target.files[0];
    if (f) importSlideOrTemplateFile(f);
    e.target.value = '';
  });

  // Canvas Drag/Resize
  const canvas = document.getElementById('studioCanvas');
  canvas.addEventListener('dragover', e => { if (state.dragTool) { e.preventDefault(); canvas.classList.add('drag-over'); } });
  canvas.addEventListener('dragleave', () => canvas.classList.remove('drag-over'));
  canvas.addEventListener('drop', e => { if (!state.dragTool) return; e.preventDefault(); canvas.classList.remove('drag-over'); addBlock(state.dragTool, canvasPoint(canvas, e.clientX, e.clientY)); state.dragTool = null; });
  canvas.addEventListener('pointerdown', e => { if (e.target === canvas) { state.selectedBlockId = null; renderEditor(); } });
  canvas.addEventListener('pointermove', e => {
    const s = getSlide(); if (!s) return;
    if (state.resize) {
      const b = s.blocks.find(x => x.id === state.resize.id); if (!b) return;
      const dx = (e.clientX - state.resize.start.x) * (1920 / canvas.clientWidth);
      const dy = (e.clientY - state.resize.start.y) * (1080 / canvas.clientHeight);
      b.w = Math.round(clamp(state.resize.rect.w + dx, 40, 1920 - state.resize.rect.x));
      b.h = Math.round(clamp(state.resize.rect.h + dy, 40, 1080 - state.resize.rect.y));
      const node = state.resize.node;
      if (node) { node.style.width = (b.w / 1920 * 100) + '%'; node.style.height = (b.h / 1080 * 100) + '%'; }
      return;
    }
    if (!state.drag) return;
    const dist = Math.abs(e.clientX - state.drag.start.x) + Math.abs(e.clientY - state.drag.start.y);
    if (!state.drag.moved && dist < 5) return;
    state.drag.moved = true;
    const b = s.blocks.find(x => x.id === state.drag.id); if (!b) return;
    const p = canvasPoint(canvas, e.clientX, e.clientY);
    const rawX = clamp(p.x - state.drag.offset.x, 0, 1920 - Number(b.w || 0));
    const rawY = clamp(p.y - state.drag.offset.y, 0, 1080 - Number(b.h || 0));
    const snapped = applySnap(b, rawX, rawY, snapCandidates(s, b.id));
    b.x = Math.round(clamp(snapped.x, 0, 1920 - Number(b.w || 0)));
    b.y = Math.round(clamp(snapped.y, 0, 1080 - Number(b.h || 0)));
    state.snap = snapped.guides;
    const node = state.drag.node;
    if (node) { node.style.left = (b.x / 1920 * 100) + '%'; node.style.top = (b.y / 1080 * 100) + '%'; }
    drawSnapGuides(canvas);
  });
  // Nur nach echtem Ziehen/Resizen neu aufbauen. Ein reiner Klick laesst die
  // Nodes stehen -> der folgende Doppelklick kommt zuverlaessig an.
  const stop = () => {
    const wasInteracting = (state.drag && state.drag.moved) || state.resize;
    state.drag = null; state.resize = null; state.snap = {x: [], y: []};
    if (wasInteracting) { markDirty(); renderCanvas(); renderBlockList(); }
    else { drawSnapGuides(canvas); }
  };
  canvas.addEventListener('pointerup', stop);
  canvas.addEventListener('pointerleave', () => { if (state.drag || state.resize) stop(); });

  // Schriften bei Fenstergroessen-Aenderung neu an die Canvas-Breite anpassen.
  let _resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(_resizeTimer);
    _resizeTimer = setTimeout(() => { if (getSlide()) renderCanvas(); }, 150);
  });

  // Modal
  document.getElementById('mType').innerHTML = Object.keys(B.TYPES).map(t => `<option value="${t}">${esc(B.TYPES[t].label)}</option>`).join('');
  document.getElementById('mType').addEventListener('change', e => showFields(e.target.value));
  document.getElementById('closeModal').addEventListener('click', closeModal);
  document.getElementById('applyBlock').addEventListener('click', applyModal);
  document.getElementById('deleteBlock').addEventListener('click', () => {
    const s = getSlide(); if (!s) return;
    s.blocks = (s.blocks || []).filter(b => b.id !== state.modalBlockId);
    state.selectedBlockId = s.blocks[0] ? s.blocks[0].id : null;
    markDirty(); closeModal(); renderEditor();
  });
  document.getElementById('blockModal').addEventListener('click', e => { if (e.target.id === 'blockModal') closeModal(); });

  // Tabelle
  document.getElementById('tblAddRow').addEventListener('click', () => { const cols = state.tableDraft[0]?.length || 2; state.tableDraft.push(Array(cols).fill('')); markDirty(); renderTableGrid(); });
  document.getElementById('tblAddCol').addEventListener('click', () => { state.tableDraft.forEach(r => r.push('')); markDirty(); renderTableGrid(); });
  document.getElementById('tblDelRow').addEventListener('click', () => { if (state.tableDraft.length > 1) { state.tableDraft.pop(); markDirty(); renderTableGrid(); } });
  document.getElementById('tblDelCol').addEventListener('click', () => { if ((state.tableDraft[0]?.length || 0) > 1) { state.tableDraft.forEach(r => r.pop()); markDirty(); renderTableGrid(); } });
  document.getElementById('tblPaste').addEventListener('paste', e => {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
    if (!text) return;
    const rows = text.replace(/\r/g, '').split('\n').filter(l => l.length).map(l => l.split('\t'));
    if (rows.length) { state.tableDraft = rows; tableNorm(); markDirty(); renderTableGrid(); toast('Tabelle aus Zwischenablage übernommen ✓'); }
    e.target.value = '';
  });

  // Bild-Upload
  document.getElementById('mUpload').addEventListener('change', async e => {
    const file = e.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    try {
      const res = await fetch('../api/upload.php', {method: 'POST', body: fd});
      let data = null;
      try { data = await res.json(); } catch (_) { /* keine JSON-Antwort (z. B. Proxy-413/PHP-Limit) */ }
      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.error) ? data.error : ('Upload fehlgeschlagen (HTTP ' + res.status + '). Datei evtl. zu gross.'));
      }
      document.getElementById('mSrc').value = data.url;
      markDirty();
      toast('Bild hochgeladen ✓');
    } catch (err) { toast(err.message, 'err'); }
    e.target.value = '';
  });

  // Diashow: mehrere Bilder hochladen -> URLs an die Liste anhaengen (eine pro Zeile).
  document.getElementById('mGalleryUpload').addEventListener('change', async e => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;
    const ta = document.getElementById('mGalleryList');
    let okCount = 0;
    for (const file of files) {
      const fd = new FormData(); fd.append('file', file);
      try {
        const res = await fetch('../api/upload.php', {method: 'POST', body: fd});
        let data = null;
        try { data = await res.json(); } catch (_) { /* keine JSON-Antwort */ }
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) ? data.error : ('Upload fehlgeschlagen (HTTP ' + res.status + '). Datei evtl. zu gross.'));
        }
        ta.value = (ta.value.trim() ? ta.value.replace(/\s+$/, '') + '\n' : '') + data.url;
        okCount++;
      } catch (err) { toast(err.message, 'err'); }
    }
    if (okCount) { markDirty(); toast(okCount + (okCount === 1 ? ' Bild' : ' Bilder') + ' hochgeladen ✓'); }
    e.target.value = '';
  });

  // Speichern
  document.getElementById('saveSlides').addEventListener('click', saveSlides);
  // Vorschau
  document.getElementById('previewSlide').addEventListener('click', openPreview);
  document.getElementById('closePreview').addEventListener('click', () => document.getElementById('previewOverlay').classList.remove('open'));

  // Tastatur
  document.addEventListener('keydown', e => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
    if (document.getElementById('blockModal').classList.contains('open')) {
      if (e.key === 'Escape') closeModal();
      return;
    }
    if (!typing && state.selectedBlockId && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      e.preventDefault();
      const s = getSlide(), b = getBlock();
      if (s && b) {
        const step = e.shiftKey ? 10 : 1;
        const w = Number(b.w || 0), h = Number(b.h || 0), bx = Number(b.x || 0), by = Number(b.y || 0);
        if (e.key === 'ArrowLeft') b.x = clamp(bx - step, 0, 1920 - w);
        if (e.key === 'ArrowRight') b.x = clamp(bx + step, 0, 1920 - w);
        if (e.key === 'ArrowUp') b.y = clamp(by - step, 0, 1080 - h);
        if (e.key === 'ArrowDown') b.y = clamp(by + step, 0, 1080 - h);
        markDirty();
        renderCanvas();
      }
    }
    if (!typing && e.key === 'Delete' && state.selectedBlockId) {
      const s = getSlide();
      if (s) { s.blocks = (s.blocks || []).filter(b => b.id !== state.selectedBlockId); state.selectedBlockId = s.blocks[0] ? s.blocks[0].id : null; markDirty(); renderEditor(); }
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveSlides(); }
  });
}

async function saveSlides() {
  const items = state.slides.map(s => ({
    id: s.id, name: s.name, duration: Number(s.duration || 10),
    bg_color: s.bg_color || '#1a1a2e', bg_image: s.bg_image || '',
    valid_from: s.valid_from || '', valid_until: s.valid_until || '',
    blocks: Array.isArray(s.blocks) ? s.blocks : [],
  }));
  try { await postJson('../api/slides.php', {items}); clearDirty(); toast('Folien gespeichert ✓'); } catch (e) { toast(e.message, 'err'); }
}

async function openPreview() {
  const s = getSlide();
  if (!s) { toast('Keine Folie ausgewählt.', 'err'); return; }
  try {
    await postJson('../api/preview.php', {slide: s});
    const frame = document.getElementById('previewFrame');
    frame.src = ROOT + '?preview=1&_=' + Date.now();
    document.getElementById('previewOverlay').classList.add('open');
  } catch (e) { toast(e.message, 'err'); }
}
