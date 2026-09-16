/**
 * Chart lifecycle: mount an ECharts instance inside a card body from a spec
 * builder, keep a registry per view so charts can be refreshed in place
 * when the period changes (no flash, no layout jump), share the hover date
 * across the view (echarts.connect) and dispose everything on navigation.
 * Every chart also carries a table twin, toggled from the card footer.
 */
import { toOption } from './chart-option.js';
import { fmtDate, fmtUnit } from './format.js';
import { getState } from './state.js';
import { el, note } from './cards.js';

const echarts = globalThis.echarts;
const registry = [];
let group = 'view';
let resizeObserver = null;

function observer() {
  if (!resizeObserver) {
    resizeObserver = new ResizeObserver((entries) => entries.forEach((e) => {
      const entry = registry.find((r) => r.node === e.target);
      if (entry?.inst) entry.inst.resize();
    }));
  }
  return resizeObserver;
}

/**
 * card: the {body, foot} returned by cards.card(); build(ctx) returns a spec
 * or null when the data is missing ("dato non disponibile").
 */
export function chart(card, build, { title = '', tall = false } = {}) {
  const node = el('div', tall ? 'chart chart-tall' : 'chart');
  node.setAttribute('role', 'img');
  node.setAttribute('aria-label', title);
  card.body.appendChild(node);
  const entry = { node, build, inst: null, spec: null, table: null, card };
  registry.push(entry);
  render(entry);
  return entry;
}

function render(entry) {
  const ctx = getState();
  let spec = null;
  try { spec = entry.build(ctx); } catch (err) { console.error('chart build failed', err); }
  entry.spec = spec;
  if (!spec || !spec.series.some((s) => s.values.some((v) => typeof v === 'number'))) {
    entry.inst?.dispose();
    entry.inst = null;
    entry.node.classList.add('is-hidden');
    if (!entry.card.body.querySelector('.note')) note(entry.card.body, 'dato non disponibile');
    return;
  }
  entry.node.classList.remove('is-hidden');
  entry.card.body.querySelector('.note')?.remove();
  if (!entry.inst) {
    entry.inst = echarts.init(entry.node, null, { renderer: 'canvas' });
    entry.inst.group = group;
    observer().observe(entry.node);
    addTableToggle(entry);
  }
  entry.inst.setOption(toOption(spec), { notMerge: true });
  setFootnote(entry, spec.footnote);
  if (entry.table && !entry.table.classList.contains('is-hidden')) fillTable(entry);
}

/** Re-run every builder with the current state (period change). */
export function refreshCharts() {
  registry.forEach(render);
  echarts.connect(group);
}
export function connectView(name) {
  group = name;
  registry.forEach((r) => { if (r.inst) r.inst.group = name; });
  echarts.connect(name);
}
export function disposeCharts() {
  registry.forEach((r) => { r.inst?.dispose(); resizeObserver?.unobserve(r.node); });
  registry.length = 0;
}

function setFootnote(entry, text) {
  let n = entry.card.foot.querySelector('.footnote');
  if (!text) { n?.remove(); return; }
  if (!n) { n = el('span', 'footnote'); entry.card.foot.prepend(n); }
  n.textContent = text;
}

/* ---- table twin ---- */
function addTableToggle(entry) {
  const btn = el('button', 'btn-ghost btn-xs', 'Tabella');
  btn.type = 'button';
  btn.setAttribute('aria-expanded', 'false');
  entry.card.foot.appendChild(btn);
  entry.table = el('div', 'chart-table is-hidden');
  entry.card.body.appendChild(entry.table);
  btn.addEventListener('click', () => {
    const open = entry.table.classList.toggle('is-hidden');
    btn.setAttribute('aria-expanded', String(!open));
    btn.textContent = open ? 'Tabella' : 'Nascondi tabella';
    if (!open) fillTable(entry);
  });
}
function fillTable(entry) {
  const spec = entry.spec;
  const xs = spec.dates ?? spec.categories;
  const cols = spec.series.filter((s) => !s.hidden);
  const table = el('table', 'data-table data-table-compact');
  const thead = el('thead');
  const hr = el('tr');
  hr.appendChild(el('th', null, spec.dates ? 'Data' : 'Voce'));
  cols.forEach((s) => { const th = el('th', 'num', s.name); hr.appendChild(th); });
  thead.appendChild(hr);
  const tbody = el('tbody');
  xs.forEach((x, i) => {
    const tr = el('tr');
    tr.appendChild(el('td', null, spec.dates ? fmtDate(x) : String(x)));
    cols.forEach((s) => tr.appendChild(el('td', 'num', fmtUnit(s.values[i], s.unit ?? spec.unit))));
    tbody.appendChild(tr);
  });
  table.append(thead, tbody);
  entry.table.replaceChildren(table);
}
