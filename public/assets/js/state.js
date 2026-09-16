/**
 * Minimal store: the loaded dashboard, the selected period and the active
 * view. Views subscribe to period changes to refresh their charts in place.
 */
const listeners = new Set();
const state = {
  data: null,
  period: '30g',
  view: 'valore',
};
const STORAGE_KEY = 'manor-ledger.period';

export function getState() {
  return state;
}
export function setData(data) {
  state.data = data;
}
export function setView(view) {
  state.view = view;
}
export function setPeriod(period) {
  if (state.period === period) return;
  state.period = period;
  try { localStorage.setItem(STORAGE_KEY, period); } catch { /* private mode: ignore */ }
  listeners.forEach((fn) => fn(state));
}
export function restorePeriod(valid) {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved && valid.includes(saved)) state.period = saved;
  } catch { /* ignore */ }
  return state.period;
}
export function onPeriodChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
