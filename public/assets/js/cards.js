/**
 * DOM helpers for the dashboard: cards, tables, notes, sparkbars. Everything
 * is created with createElement/textContent (no innerHTML) so JSON labels are
 * never interpreted as markup and the CSP needs no 'unsafe-inline'.
 */
import { termChips } from './glossary.js';

export function el(tag, className = null, text = null) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== null && text !== undefined) node.textContent = text;
  return node;
}
export function grid() {
  return el('div', 'grid');
}
/**
 * A chart/table card: header (title, "Cosa dice", acronym chips), body and
 * footer. `span` is the number of grid columns on desktop (12 = full width).
 */
export function card({ title, says = '', terms = [], span = 6, kind = null }) {
  const root = el('section', `card span-${span}${kind ? ' card-' + kind : ''}`);
  root.setAttribute('aria-label', title);
  const head = el('header', 'card-head');
  const heading = el('h2', 'card-title', title);
  head.appendChild(heading);
  if (says) head.appendChild(el('p', 'card-says', says));
  if (terms.length) head.appendChild(termChips(terms));
  const body = el('div', 'card-body');
  const foot = el('footer', 'card-foot');
  root.append(head, body, foot);
  return { root, body, foot, head };
}
export function note(target, text) {
  const n = el('p', 'note', text);
  target.appendChild(n);
  return n;
}
/**
 * columns: [{ key, label, num, fmt(row) -> string|Node, cls(row) -> string }]
 */
export function table(columns, rows, { caption = null } = {}) {
  const t = el('table', 'data-table');
  if (caption) t.appendChild(el('caption', 'sr-only', caption));
  const thead = el('thead');
  const hr = el('tr');
  columns.forEach((c) => hr.appendChild(el('th', c.num ? 'num' : null, c.label)));
  thead.appendChild(hr);
  const tbody = el('tbody');
  rows.forEach((row) => {
    const tr = el('tr');
    if (row.provisional) tr.classList.add('is-provisional');
    columns.forEach((c) => {
      const td = el('td', c.num ? 'num' : null);
      const v = c.fmt ? c.fmt(row) : row[c.key];
      if (v instanceof Node) td.appendChild(v); else td.textContent = v ?? '—';
      if (c.cls) { const extra = c.cls(row); if (extra) td.classList.add(extra); }
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  t.append(thead, tbody);
  return t;
}
/** Two thin horizontal bars: current vs previous, widths relative to `max`. */
export function sparkPair(current, previous, max) {
  const wrap = el('span', 'spark');
  const a = el('span', 'spark-bar spark-now');
  const b = el('span', 'spark-bar spark-prev');
  const pct = (v) => (max > 0 && typeof v === 'number' ? Math.max(2, Math.round((v / max) * 100)) : 0);
  a.style.width = pct(current) + '%';
  b.style.width = pct(previous) + '%';
  wrap.append(a, b);
  return wrap;
}
/** Signed delta chip; `invert` when a decrease is the good direction. */
export function deltaChip(delta, text, invert = false) {
  const chip = el('span', 'delta');
  if (typeof delta !== 'number') { chip.classList.add('delta-flat'); chip.textContent = text; return chip; }
  const good = invert ? delta < 0 : delta > 0;
  const flat = Math.abs(delta) < 0.0005;
  chip.classList.add(flat ? 'delta-flat' : good ? 'delta-good' : 'delta-bad');
  const arrow = el('span', 'delta-arrow', flat ? '·' : delta > 0 ? '▲' : '▼');
  arrow.setAttribute('aria-hidden', 'true');
  chip.append(arrow, document.createTextNode(text));
  chip.setAttribute('aria-label', (flat ? 'stabile' : good ? 'in miglioramento' : 'in peggioramento') + ', ' + text);
  return chip;
}
export function severityBadge(severity) {
  const label = severity === 'high' ? 'alta' : severity === 'medium' ? 'media' : 'bassa';
  const b = el('span', `badge badge-${severity}`, label);
  return b;
}
