/**
 * KPI stat tiles: label, value, optional second line, delta vs previous week
 * (coloured by direction × whether up is good) and a one-line reading guide.
 */
import { el, deltaChip } from './cards.js';
import { fmtDelta, fmtUnit } from './format.js';

/** tile = { label, value, unit, sub, delta, deltaLabel, pp, invert, how, accent } */
export function kpiTile(tile) {
  const root = el('article', 'kpi' + (tile.accent ? ' kpi-accent' : ''));
  root.appendChild(el('h3', 'kpi-label', tile.label));
  root.appendChild(el('p', 'kpi-value', typeof tile.value === 'string' ? tile.value : fmtUnit(tile.value, tile.unit)));
  if (tile.sub) root.appendChild(el('p', 'kpi-sub', tile.sub));
  if (typeof tile.delta === 'number') {
    const meta = el('div', 'kpi-meta');
    meta.appendChild(deltaChip(tile.delta, fmtDelta(tile.delta, tile.pp), tile.invert));
    meta.appendChild(el('span', 'kpi-delta-label', tile.deltaLabel ?? 'vs 7 giorni prima'));
    root.appendChild(meta);
  }
  if (tile.how) root.appendChild(el('p', 'kpi-how', tile.how));
  return root;
}
export function kpiRow(tiles) {
  const row = el('div', 'kpi-row');
  tiles.forEach((t) => row.appendChild(kpiTile(t)));
  return row;
}
