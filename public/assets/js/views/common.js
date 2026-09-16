/**
 * Shared card builders used by more than one view.
 */
import { card } from '../cards.js';
import { chart } from '../charts.js';
import { theme } from '../chart-option.js';
import { forPeriod, firstValues, aggregateSeries, breakdownSeries, aggregationNote, alignValues } from '../series.js';

/**
 * One-metric card (line by default). The builder returns null when the
 * metric is absent from dashboard.json, which renders "dato non disponibile".
 */
export function metricCard(g, { id, source = 'metrics', title, says, terms = [], span = 6, kind = 'line', color, yScale = false, markLines, ariaTitle, tall = false }) {
  const c = card({ title, says, terms, span });
  g.appendChild(c.root);
  chart(c, (ctx) => {
    const block = forPeriod(ctx.data[source]?.[id], ctx.period);
    if (!block) return null;
    const t = theme();
    // No aggregate (a rate broken down by something other than Platform): draw every breakdown series instead.
    const series = aggregateSeries(block)
      ? [{ name: title, values: firstValues(block), kind, color: color ?? t.palette[0], endLabel: kind !== 'bar' }]
      : breakdownSeries(block).slice(0, 8).map((b, i) => ({ name: b.label, values: b.values, kind, color: t.palette[i] }));
    return { dates: block.dates, unit: block.unit, yScale, markLines, provisionalDate: ctx.data.provisionalDate, footnote: aggregationNote(block), series };
  }, { title: ariaTitle ?? title, tall });
  return c;
}
/** Several metrics (same unit) as lines on one axis. Absent ones are skipped. */
export function multiMetricCard(g, { ids, title, says, terms = [], span = 6, unit, colors, tall = false }) {
  const c = card({ title, says, terms, span });
  g.appendChild(c.root);
  chart(c, (ctx) => {
    const t = theme();
    const blocks = ids.map((m, i) => ({ ...m, block: forPeriod(ctx.data[m.source ?? 'metrics']?.[m.id], ctx.period), color: colors?.[i] ?? t.palette[i] }))
      .filter((m) => m.block);
    if (!blocks.length) return null;
    // Coverage differs per metric: share the longest x axis and re-index the others on it.
    const base = blocks.reduce((a, b) => (b.block.dates.length > a.block.dates.length ? b : a));
    const dates = base.block.dates;
    return { dates, unit: unit ?? base.block.unit, provisionalDate: ctx.data.provisionalDate,
      footnote: blocks.map((m) => aggregationNote(m.block)).find(Boolean) ?? null,
      series: blocks.map((m) => ({ name: m.name, values: alignValues(dates, m.block), kind: m.kind ?? 'line', color: m.color, endLabel: blocks.length <= 3 })) };
  }, { title, tall });
  return c;
}
