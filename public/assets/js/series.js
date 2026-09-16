/**
 * Small helpers over the {dates, series:[{label, values}]} blocks of the
 * dashboard contract. Pure functions: no DOM, no ECharts.
 */
const PERIOD_DAYS = { '7g': 7, '30g': 30, '90g': 90, tutto: Infinity };
export const PERIODS = Object.keys(PERIOD_DAYS);

export function periodDays(period) {
  return PERIOD_DAYS[period] ?? 30;
}
/** Keep only the last `n` days of a block (all series). Returns a new block. */
export function tail(block, n) {
  if (!block || !Array.isArray(block.dates)) return null;
  if (!Number.isFinite(n) || n >= block.dates.length) return block;
  const from = block.dates.length - n;
  return {
    ...block,
    dates: block.dates.slice(from),
    series: block.series.map((s) => ({ ...s, values: s.values.slice(from) })),
  };
}
/** Block sliced to the selected period (7g / 30g / 90g / tutto). */
export function forPeriod(block, period) {
  return tail(block, periodDays(period));
}
export function seriesByLabel(block, label) {
  return block?.series?.find((s) => s.label === label) ?? null;
}
export function firstValues(block) {
  return aggregateSeries(block)?.values ?? [];
}
/** Value on a given ISO date (or null). */
export function valueAt(block, iso, label = null) {
  if (!block) return null;
  const i = block.dates.indexOf(iso);
  if (i < 0) return null;
  const s = label === null ? aggregateSeries(block) : seriesByLabel(block, label);
  return s ? s.values[i] ?? null : null;
}
/** Mean of the last `n` non-null values. */
export function meanLast(values, n) {
  const w = values.slice(-n).filter((v) => typeof v === 'number');
  return w.length ? w.reduce((a, b) => a + b, 0) / w.length : null;
}
/** Trailing mean of window `n`; null until the window is full. */
export function rolling(values, n) {
  return values.map((_, i) => (i + 1 < n ? null : meanLast(values.slice(0, i + 1), n)));
}
export function sum(values) {
  return values.reduce((a, b) => a + (typeof b === 'number' ? b : 0), 0);
}
/** Elementwise a[i] - b[i] (null-safe). */
export function diff(a, b) {
  return a.map((v, i) => (typeof v === 'number' && typeof b[i] === 'number' ? v - b[i] : null));
}
/** Last non-null value and its date. */
export function lastPoint(block, label = null) {
  if (!block) return null;
  const s = label === null ? aggregateSeries(block) : seriesByLabel(block, label);
  if (!s) return null;
  for (let i = s.values.length - 1; i >= 0; i--) {
    if (typeof s.values[i] === 'number') return { date: block.dates[i], value: s.values[i] };
  }
  return null;
}
/** Sort series so the biggest total comes first (stable for ties). */
export function sortByTotal(series) {
  return [...series].sort((a, b) => sum(b.values) - sum(a.values));
}
/**
 * Values of `block` re-indexed on `dates` (null where the block has no such
 * day). Lets series with different coverage share one x axis.
 */
export function alignValues(dates, block, label = null) {
  if (!block) return dates.map(() => null);
  const s = label === null ? aggregateSeries(block) : seriesByLabel(block, label);
  if (!s) return dates.map(() => null);
  const index = new Map(block.dates.map((d, i) => [d, i]));
  return dates.map((d) => { const i = index.get(d); return i === undefined ? null : s.values[i] ?? null; });
}
/**
 * The aggregate series of a block: the one labelled "" (prepended by the
 * builder when Roblox only returned a breakdown), else the only series.
 * Returns null when there are several breakdown series and no aggregate.
 */
export function aggregateSeries(block) {
  if (!block?.series?.length) return null;
  const agg = block.series.find((s) => s.label === '');
  if (agg) return agg;
  return block.series.length === 1 ? block.series[0] : null;
}
/** Breakdown series only (drops the "" aggregate). */
export function breakdownSeries(block) {
  return (block?.series ?? []).filter((s) => s.label !== '');
}
/** Italian footnote when the aggregate was derived from a breakdown. */
export function aggregationNote(block) {
  if (!block?.aggregatedFromBreakdown) return null;
  const how = block.aggregation === 'weightedMean' ? 'media ponderata' : 'somma';
  const by = block.breakdown === 'Platform' ? 'dalle piattaforme' : `dalla scomposizione per ${block.breakdown ?? 'dimensione'}`;
  return `valore aggregato (${how}) ${by}`;
}
