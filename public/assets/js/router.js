/**
 * Hash router: #valore (default), #crescita, #monetizzazione, #salute.
 * Keeps the nav tabs' aria-selected in sync and calls the view renderer.
 */
import { setView } from './state.js';

export function initRouter(views, render) {
  const names = Object.keys(views);
  const tabs = [...document.querySelectorAll('[data-view-link]')];

  function current() {
    const name = location.hash.replace(/^#/, '');
    return names.includes(name) ? name : names[0];
  }
  function apply() {
    const name = current();
    setView(name);
    tabs.forEach((tab) => {
      const active = tab.dataset.viewLink === name;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    document.title = `${views[name].title} · Manor Ledger`;
    render(name, views[name]);
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
  window.addEventListener('hashchange', apply);
  // Arrow keys move between tabs (WAI-ARIA tabs pattern).
  tabs.forEach((tab, i) => tab.addEventListener('keydown', (ev) => {
    if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
    ev.preventDefault();
    const next = tabs[(i + (ev.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    next.focus();
    location.hash = next.dataset.viewLink;
  }));
  apply();
}
