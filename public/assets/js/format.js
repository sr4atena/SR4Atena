/**
 * Formatting helpers: Italian locale, Europe/Rome dates. Pure functions only,
 * so they can be unit-tested without a DOM. Units follow the dashboard.json
 * contract: robux, usd, int, dec, pct (0-1 fraction), min, sec, ms, hours,
 * fps, bytes, mb.
 */
const LOCALE = 'it-IT';
const TZ = 'Europe/Rome';
const MINUS = '−';
const cache = new Map();

function nf(options) {
  const key = JSON.stringify(options);
  let f = cache.get(key);
  if (!f) { f = new Intl.NumberFormat(LOCALE, options); cache.set(key, f); }
  return f;
}
function df(options) {
  const key = 'd' + JSON.stringify(options);
  let f = cache.get(key);
  if (!f) { f = new Intl.DateTimeFormat(LOCALE, { timeZone: TZ, ...options }); cache.set(key, f); }
  return f;
}
const isNum = (v) => typeof v === 'number' && Number.isFinite(v);

export function fmtInt(v) {
  return isNum(v) ? nf({ maximumFractionDigits: 0 }).format(v) : '—';
}
export function fmtDec(v, digits = 2) {
  return isNum(v) ? nf({ minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v) : '—';
}
/** Adaptive decimals: 1,69 / 12,3 / 1.234 */
export function fmtAuto(v) {
  if (!isNum(v)) return '—';
  const a = Math.abs(v);
  if (a >= 1000) return fmtInt(v);
  if (a >= 100) return fmtDec(v, 0);
  if (a >= 10) return fmtDec(v, 1);
  return fmtDec(v, 2);
}
export function fmtPct(v, digits = 1) {
  return isNum(v) ? fmtDec(v * 100, digits) + ' %' : '—';
}
export function fmtUsd(v) {
  if (!isNum(v)) return '—';
  return '$ ' + (Math.abs(v) >= 10000 ? fmtInt(v) : fmtDec(v, 2));
}
export function fmtRobux(v) {
  return isNum(v) ? fmtAuto(v) + ' R$' : '—';
}
export function fmtBytes(v) {
  if (!isNum(v)) return '—';
  const units = ['B', 'kB', 'MB', 'GB', 'TB'];
  let i = 0; let x = v;
  while (Math.abs(x) >= 1000 && i < units.length - 1) { x /= 1000; i++; }
  return fmtDec(x, i === 0 ? 0 : 2) + ' ' + units[i];
}
/** Full value with its unit suffix, for tooltips, tables and KPI tiles. */
export function fmtUnit(v, unit) {
  if (!isNum(v)) return '—';
  switch (unit) {
    case 'robux': return fmtRobux(v);
    case 'usd': return fmtUsd(v);
    case 'pct': return fmtPct(v, Math.abs(v) < 0.01 ? 2 : 1);
    case 'int': return fmtInt(v);
    case 'dec': return fmtAuto(v);
    case 'min': return fmtAuto(v) + ' min';
    case 'sec': return fmtAuto(v) + ' s';
    case 'ms': return fmtAuto(v) + ' ms';
    case 'hours': return fmtAuto(v) + ' h';
    case 'fps': return fmtAuto(v) + ' fps';
    case 'bytes': return fmtBytes(v);
    case 'mb': return fmtAuto(v) + ' MB';
    default: return fmtAuto(v);
  }
}
/** Short axis ticks: 120k, 1,2M, 9 %, $ 4k */
export function fmtTick(v, unit) {
  if (!isNum(v)) return '';
  if (unit === 'pct') return fmtDec(v * 100, v * 100 < 1 && v !== 0 ? 1 : 0) + '%';
  if (unit === 'bytes') return fmtBytes(v);
  const prefix = unit === 'usd' ? '$' : '';
  return prefix + fmtCompact(v);
}
export function fmtCompact(v) {
  if (!isNum(v)) return '—';
  const a = Math.abs(v);
  if (a >= 1e6) return fmtDec(v / 1e6, a >= 1e7 ? 0 : 1) + 'M';
  if (a >= 1e3) return fmtDec(v / 1e3, a >= 1e4 ? 0 : 1) + 'k';
  return fmtAuto(v);
}
/** Signed relative delta, e.g. "+1,2 %" or "−3,1 %". `pp` = percentage points. */
export function fmtDelta(d, pp = false) {
  if (!isNum(d)) return '—';
  const sign = d > 0 ? '+' : d < 0 ? MINUS : '±';
  const body = pp ? fmtDec(Math.abs(d) * 100, 2) + ' pp' : fmtDec(Math.abs(d) * 100, 1) + ' %';
  return sign + body;
}
export function fmtMultiple(x) {
  return isNum(x) ? fmtAuto(x) + 'x' : '—';
}

/* ---- dates ---- */
function parseDay(iso) {
  // ISO calendar date, interpreted at noon UTC so the Rome day never shifts.
  return new Date(iso.slice(0, 10) + 'T12:00:00Z');
}
/** "lun 15 set" (default), "15 set" (axis), "15 settembre 2026" (long) */
export function fmtDate(iso, style = 'short') {
  if (!iso) return '';
  const d = parseDay(iso);
  if (style === 'axis') return df({ day: 'numeric', month: 'short' }).format(d).replace('.', '');
  if (style === 'long') return df({ day: 'numeric', month: 'long', year: 'numeric' }).format(d);
  return df({ weekday: 'short', day: 'numeric', month: 'short' }).format(d).replace(/\./g, '');
}
/** "07:02" in Europe/Rome from an ISO datetime */
export function fmtTime(isoDateTime) {
  if (!isoDateTime) return '';
  return df({ hour: '2-digit', minute: '2-digit' }).format(new Date(isoDateTime));
}
export function weekdayIndex(iso) {
  // 0 = Monday ... 6 = Sunday
  return (parseDay(iso).getUTCDay() + 6) % 7;
}
export function isWeekend(iso) {
  return weekdayIndex(iso) >= 5;
}
