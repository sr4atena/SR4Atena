/**
 * Glossary drawer: lists every acronym from dashboard.json `glossary`
 * (array of {term, meaning}; {term, name, description} and objects keyed by
 * term are accepted too) and can be
 * opened at a given term from the acronym chips on each card.
 */
const FALLBACK = [
  { term: 'DAU', name: 'Daily Active Users', description: 'Utenti unici che hanno giocato nel giorno.' },
  { term: 'MAU', name: 'Monthly Active Users', description: 'Utenti unici negli ultimi 30 giorni.' },
  { term: 'ARPDAU', name: 'Average Revenue Per Daily Active User', description: 'Robux medi per utente attivo al giorno.' },
  { term: 'ARPPU', name: 'Average Revenue Per Paying User', description: 'Robux medi per utente pagante.' },
  { term: 'CVR', name: 'Conversion Rate', description: 'Quota di utenti che compie il passo successivo.' },
  { term: 'R$', name: 'Robux', description: 'Valuta di Roblox, convertita in dollari al tasso DevEx.' },
  { term: 'DevEx', name: 'Developer Exchange', description: 'Conversione dei Robux in dollari.' },
];
let entries = [];
let drawer = null;
let lastFocus = null;

function normalise(raw) {
  if (Array.isArray(raw)) {
    return raw.filter((e) => e && e.term).map((e) => ({ term: e.term, name: e.name ?? '', description: e.meaning ?? e.description ?? '' }));
  }
  if (raw && typeof raw === 'object') {
    return Object.entries(raw).map(([term, v]) => (typeof v === 'string'
      ? { term, name: '', description: v }
      : { term, name: v.name ?? v.title ?? '', description: v.description ?? v.desc ?? '' }));
  }
  return [];
}
export function termId(term) {
  return 'gl-' + term.toLowerCase().replace(/[^a-z0-9]+/g, '-');
}
export function initGlossary(raw, openButton) {
  entries = normalise(raw);
  if (!entries.length) entries = FALLBACK;
  entries.sort((a, b) => a.term.localeCompare(b.term, 'it'));
  drawer = document.getElementById('glossary');
  const list = drawer.querySelector('[data-glossary-list]');
  list.replaceChildren();
  entries.forEach((e) => {
    const dt = document.createElement('dt');
    dt.id = termId(e.term);
    dt.textContent = e.term;
    if (e.name) { const small = document.createElement('small'); small.textContent = ' ' + e.name; dt.appendChild(small); }
    const dd = document.createElement('dd');
    dd.textContent = e.description;
    list.append(dt, dd);
  });
  openButton.addEventListener('click', () => openGlossary());
  drawer.querySelector('[data-glossary-close]').addEventListener('click', closeGlossary);
  document.getElementById('glossary-backdrop').addEventListener('click', closeGlossary);
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && drawer.classList.contains('is-open')) closeGlossary(); });
}
export function openGlossary(term = null) {
  if (!drawer) return;
  lastFocus = document.activeElement;
  drawer.classList.add('is-open');
  drawer.setAttribute('aria-hidden', 'false');
  document.getElementById('glossary-backdrop').classList.add('is-open');
  document.body.classList.add('drawer-open');
  drawer.querySelectorAll('.is-current').forEach((n) => n.classList.remove('is-current'));
  const match = term ? entries.find((e) => e.term === term) ?? entries.find((e) => e.term.split(/\s*\/\s*/).includes(term)) : null;
  const target = match ? document.getElementById(termId(match.term)) : null;
  if (target) {
    target.classList.add('is-current');
    target.nextElementSibling?.classList.add('is-current');
    target.scrollIntoView({ block: 'center' });
  }
  drawer.querySelector('[data-glossary-close]').focus();
}
export function closeGlossary() {
  drawer.classList.remove('is-open');
  drawer.setAttribute('aria-hidden', 'true');
  document.getElementById('glossary-backdrop').classList.remove('is-open');
  document.body.classList.remove('drawer-open');
  if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
}
/** Row of acronym chips for a card; each opens the glossary at its term. */
export function termChips(terms) {
  const row = document.createElement('div');
  row.className = 'chips';
  row.setAttribute('aria-label', 'Acronimi usati');
  terms.forEach((term) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip';
    b.textContent = term;
    b.title = 'Apri il glossario: ' + term;
    b.addEventListener('click', () => openGlossary(term));
    row.appendChild(b);
  });
  return row;
}
