/**
 * Translates a small declarative chart spec into an ECharts option, applying
 * the shared dark theme (tokens read from CSS custom properties) and the
 * mark rules: thin bars with rounded data-ends, 2px lines, hairline grid,
 * one tooltip listing every series, hovered date echoed on the x axis.
 *
 * spec = {
 *   dates: [iso] | categories: [string],
 *   unit, series: [{ name, values, unit, kind: 'line'|'area'|'bar'|'band',
 *                    color, stack, hidden, endLabel, thin, dashed, extra(v, i) }],
 *   secondary: { unit, factor },    // right axis = left axis × factor
 *   percentStack, horizontal, provisionalDate, yScale, yMax,
 *   markLines: [{ value, label }], bands: [{ from, to, label }] (category names or indices),
 *   categoryTicks: [string],        // the only x labels to draw (others stay on the axis, unlabelled)
 *   footnote: string (rendered by charts.js under the card)
 * }
 * Tooltips are built as DOM nodes (never HTML strings) so they stay CSP-safe.
 */
import { fmtDate, fmtTick, fmtUnit } from './format.js';

let tokens = null;
export function theme() {
  if (tokens) return tokens;
  const css = getComputedStyle(document.documentElement);
  const v = (name) => css.getPropertyValue(name).trim();
  tokens = {
    surface: v('--surface'), text: v('--text'), muted: v('--text-muted'), soft: v('--text-soft'),
    grid: v('--chart-grid'), axis: v('--chart-axis'), accent: v('--accent'), accent2: v('--accent-2'),
    palette: [1, 2, 3, 4, 5, 6, 7, 8].map((i) => v(`--s${i}`)),
    status: { good: v('--good'), warning: v('--warning'), serious: v('--serious'), critical: v('--critical') },
    dim: v('--chart-dim'),
  };
  return tokens;
}
/** Colour follows the entity: platforms and statuses keep their hue on every chart. */
export function entityColor(label, index) {
  const t = theme();
  const fixed = {
    Phone: t.palette[0], Computer: t.palette[1], Tablet: t.palette[2], Console: t.palette[3], TV: t.palette[4], VR: t.palette[6],
    New: t.palette[0], Returning: t.palette[2],
  };
  if (fixed[label]) return fixed[label];
  // Request statuses wear status colours: the series *means* good/bad.
  if (/^(ok|success)$/i.test(label)) return t.dim;
  if (/throttl|limit|quota/i.test(label)) return t.status.warning;
  if (/error|conflict|fail|timeout/i.test(label)) return t.status.critical;
  if (/notfound|noitem|unknown/i.test(label)) return t.status.serious;
  return t.palette[index % t.palette.length];
}
const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

function niceCeil(x) {
  if (!(x > 0)) return 1;
  const mag = 10 ** Math.floor(Math.log10(x));
  const n = x / mag;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
  return step * mag;
}
function maxOf(series) {
  let m = 0;
  series.forEach((s) => s.values.forEach((v) => { if (typeof v === 'number' && v > m) m = v; }));
  return m;
}
/** Decal = hatched fill for the provisional (still revised) day. */
function provisionalStyle(color) {
  return { opacity: 0.55, decal: { symbol: 'rect', color: 'rgba(255,255,255,0.35)', dashArrayX: [1, 0], dashArrayY: [3, 4], rotation: Math.PI / 4 } };
}

function markLine(lines) {
  const t = theme();
  return { silent: true, symbol: 'none', lineStyle: { color: t.muted, width: 1, type: 'dashed', opacity: 0.7 },
    label: { color: t.muted, fontSize: 11, position: 'insideEndTop', formatter: (p) => p.name },
    data: lines.map((m) => ({ yAxis: m.value, name: m.label })) };
}

export function toOption(spec) {
  const t = theme();
  const xs = spec.dates ?? spec.categories;
  const isTime = Boolean(spec.dates);
  const visible = spec.series.filter((s) => !s.hidden);
  const hasBars = spec.series.some((s) => s.kind === 'bar');
  const hasLines = spec.series.some((s) => s.kind !== 'bar');
  const showLegend = visible.length > 1 && !spec.horizontal;
  const provIdx = spec.provisionalDate ? xs.indexOf(spec.provisionalDate) : -1;
  const bands = [...(spec.bands ?? [])];
  if (provIdx >= 0) bands.push({ from: provIdx, to: provIdx, label: 'provvisorio' });
  const markArea = bands.length ? { silent: true, itemStyle: { color: 'rgba(255,255,255,0.045)' },
    label: { show: true, color: t.muted, fontSize: 10, position: 'insideTop', formatter: (p) => p.name },
    data: bands.map((b) => [{ xAxis: b.from, name: b.label ?? '' }, { xAxis: b.to }]) } : null;
  let markAreaDone = false;
  const wantsEnd = spec.series.some((s) => s.endLabel);
  const primaryMax = spec.yMax ?? (spec.percentStack ? 1 : spec.secondary ? niceCeil(maxOf(spec.series.filter((s) => !s.secondary)) * 1.08) : null);
  let colorIdx = 0;

  const series = spec.series.map((s) => {
    const color = s.color ?? entityColor(s.name, colorIdx++);
    const unit = s.unit ?? spec.unit;
    const base = { name: s.name, color, yAxisIndex: s.secondary ? 1 : 0, stack: s.stack, emphasis: { focus: 'none' }, z: s.kind === 'bar' ? 2 : 3 };
    if (s.kind === 'bar') {
      const radius = spec.horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0];
      const data = s.values.map((v, i) => (i === provIdx ? { value: v, itemStyle: provisionalStyle(color) }
        : s.colors ? { value: v, itemStyle: { color: s.colors[i] } } : v));
      const bar = { ...base, type: 'bar', data, barMaxWidth: 24, barGap: '25%', barCategoryGap: '35%',
        itemStyle: { borderRadius: s.stack ? 0 : radius, borderColor: s.stack ? t.surface : undefined, borderWidth: s.stack ? 1 : 0 } };
      if (s.labels) bar.label = { show: true, position: spec.horizontal ? 'right' : 'top', color: t.soft, fontSize: 11, formatter: (p) => fmtUnit(p.value, unit) };
      if (spec.markLines && s === visible[0]) bar.markLine = markLine(spec.markLines);
      if (markArea && !markAreaDone && !spec.horizontal) { bar.markArea = markArea; markAreaDone = true; }
      return bar;
    }
    const line = { ...base, type: 'line', data: s.values, showSymbol: false, symbolSize: 8, smooth: false, connectNulls: false,
      lineStyle: { width: s.kind === 'band' ? 0 : s.thin ? 1 : 2, type: s.dashed ? 'dashed' : 'solid', opacity: s.kind === 'band' ? 0 : 1 },
      itemStyle: { borderColor: t.surface, borderWidth: 2 } };
    if (s.kind === 'area') line.areaStyle = { opacity: s.stack ? 0.55 : 0.12 };
    if (s.kind === 'band') line.areaStyle = { opacity: 0.16, color: s.color ?? t.accent };
    if (s.endLabel) {
      line.endLabel = { show: true, formatter: (p) => fmtUnit(p.value, unit), color: t.soft, fontSize: 11, offset: [6, 0] };
      line.labelLayout = { moveOverlap: 'shiftY' };
    }
    if (spec.markLines && s === visible[0]) line.markLine = markLine(spec.markLines);
    if (markArea && !markAreaDone && s.kind !== 'band') { line.markArea = markArea; markAreaDone = true; }
    return line;
  });

  const valueAxis = (unit, max, position) => ({
    type: 'value', position, min: 0, max: max ?? undefined, scale: Boolean(spec.yScale && !max),
    splitNumber: 5, axisLine: { show: false }, axisTick: { show: false },
    splitLine: { show: position !== 'right', lineStyle: { color: t.grid, width: 1, type: 'solid' } },
    axisLabel: { color: t.muted, fontSize: 11, formatter: (v) => fmtTick(v, unit) },
    axisPointer: { label: { formatter: (p) => fmtUnit(p.value, unit) } },
  });
  const categoryAxis = {
    type: 'category', data: xs, boundaryGap: hasBars,
    axisLine: { lineStyle: { color: t.axis } }, axisTick: { show: false },
    axisLabel: { color: t.muted, fontSize: 11, hideOverlap: true, formatter: (v) => (isTime ? fmtDate(v, 'axis') : v),
      interval: spec.categoryTicks ? (_i, v) => spec.categoryTicks.includes(v) : 'auto' },
    axisPointer: { label: { show: true, formatter: (p) => (isTime ? fmtDate(p.value) : p.value) } },
  };
  const yAxes = [valueAxis(spec.unit, primaryMax, 'left')];
  if (spec.secondary) yAxes.push(valueAxis(spec.secondary.unit, primaryMax * spec.secondary.factor, 'right'));

  return {
    animation: !reducedMotion(), animationDuration: 350, animationDurationUpdate: 250,
    color: t.palette, textStyle: { fontFamily: 'inherit' },
    grid: { left: 8, right: spec.secondary ? 8 : wantsEnd ? 68 : 16, top: showLegend ? 40 : 16, bottom: 6, containLabel: true },
    legend: showLegend ? { show: true, top: 0, right: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8, itemGap: 14,
      textStyle: { color: t.soft, fontSize: 11 }, data: visible.map((s) => s.name) } : { show: false },
    tooltip: {
      trigger: 'axis', appendToBody: true, borderWidth: 0, padding: 0, backgroundColor: 'transparent', extraCssText: 'box-shadow:none;',
      axisPointer: { type: hasLines ? 'cross' : 'shadow', lineStyle: { color: t.axis, width: 1 }, crossStyle: { color: t.axis },
        shadowStyle: { color: 'rgba(255,255,255,0.05)' },
        label: { backgroundColor: t.surface, borderColor: t.axis, borderWidth: 1, color: t.text, fontSize: 11, padding: [3, 6] } },
      formatter: (params) => tooltipNode(params, spec, xs, isTime),
    },
    xAxis: spec.horizontal ? yAxes[0] : categoryAxis,
    yAxis: spec.horizontal ? { ...categoryAxis, inverse: true } : yAxes,
    series,
  };
}

function tooltipNode(params, spec, xs, isTime) {
  const list = Array.isArray(params) ? params : [params];
  const root = document.createElement('div');
  root.className = 'tt';
  const head = document.createElement('div');
  head.className = 'tt-date';
  const x = list[0]?.axisValue ?? list[0]?.name;
  head.textContent = isTime ? fmtDate(x) : String(x);
  root.appendChild(head);
  list.forEach((p) => {
    const s = spec.series[p.seriesIndex];
    if (!s || s.hidden) return;
    const v = p.value && typeof p.value === 'object' ? p.value.value : p.value;
    const row = document.createElement('div');
    row.className = 'tt-row';
    const key = document.createElement('span');
    key.className = s.kind === 'bar' || s.kind === 'area' ? 'tt-key tt-key-rect' : 'tt-key';
    key.style.background = p.color;
    const val = document.createElement('strong');
    val.className = 'tt-val';
    val.textContent = fmtUnit(v, s.unit ?? spec.unit);
    const name = document.createElement('span');
    name.className = 'tt-name';
    name.textContent = s.name;
    row.append(key, val, name);
    if (s.extra && typeof v === 'number') {
      const text = s.extra(v, p.dataIndex);
      if (text) {
        const ex = document.createElement('span');
        ex.className = 'tt-extra';
        ex.textContent = text;
        row.appendChild(ex);
      }
    }
    root.appendChild(row);
  });
  if (spec.provisionalDate && x === spec.provisionalDate) {
    const note = document.createElement('div');
    note.className = 'tt-note';
    note.textContent = 'Giorno provvisorio: Roblox lo rivede ancora';
    root.appendChild(note);
  }
  return root;
}
