/**
 * View "Voci": what YouTube says about the game. Unlike the other four
 * views this one does not read dashboard.json: it loads /api/voices on
 * first entry and keeps it for the session. Top to bottom: the verdict,
 * four facts, the tone strip over the month, the two-column table of
 * praise and requests, then the cards: the most watched videos and the most
 * recent of creators with an audience. A video in both lists is analysed
 * once and shown in both sections.
 *
 * Everything below the fetch is model output or third-party text: capped
 * and inserted with textContent, never innerHTML (see voci/common.js).
 */
import { el } from '../cards.js';
import { getState } from '../state.js';
import { heroSection, statsRow, timelineSection, pointsSection } from '../voci/synthesis.js';
import { videoCard } from '../voci/video-card.js';
import { cap, CAP, arraySafe } from '../voci/common.js';
import { fmtInt } from '../format.js';

export const title = 'AI Sentiment';

/** The daily file changes once a day: one fetch per session is enough. */
let cached = null;
let renderId = 0;

export function render(main) {
  const mine = ++renderId;
  main.replaceChildren(statusBox('Caricamento delle voci…', null));
  if (cached) { paint(main, cached); return; }
  load().then((result) => {
    if (mine !== renderId || getState().view !== 'ai-sentiment' || !result) return;
    if (result.data) { cached = result.data; paint(main, result.data); }
    else main.replaceChildren(statusBox(result.title, result.text, result.retry));
  });
}

/* ---- loading, exactly as app.js loads /api/dashboard ---------------- */
async function load() {
  let res;
  try {
    res = await fetch('/api/voices', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch {
    return { title: 'Connessione assente', text: 'Impossibile raggiungere il server. Controlla la rete e riprova.', retry: true };
  }
  if (res.status === 401 || res.status === 403 || (res.redirected && new URL(res.url).pathname.startsWith('/login'))) {
    location.href = '/login';
    return null;
  }
  if (res.status === 503) {
    return { title: 'Analisi non ancora generata',
      text: 'Le voci dei video sono calcolate una volta al giorno alle 08:00 sulla postazione di lavoro e poi pubblicate qui. Il primo file non è ancora arrivato.',
      retry: true };
  }
  if (!res.ok) return { title: 'Qualcosa non va', text: `Il server ha risposto ${res.status}. Riprova tra qualche minuto.`, retry: true };
  try {
    const data = await res.json();
    if (!data || typeof data !== 'object') throw new Error('shape');
    return { data };
  } catch {
    return { title: 'Dati non leggibili', text: 'La risposta del server non è un JSON valido.', retry: true };
  }
}

function statusBox(title, text, retry = false) {
  const box = el('div', 'status');
  box.setAttribute('role', 'status');
  box.appendChild(el('h2', 'status-title', title));
  if (text) box.appendChild(el('p', 'status-text', text));
  if (retry) {
    const btn = el('button', 'btn', 'Riprova');
    btn.type = 'button';
    btn.addEventListener('click', () => { cached = null; render(document.getElementById('main')); });
    box.appendChild(btn);
  }
  return box;
}

/* ---- the page ------------------------------------------------------- */
function paint(main, data) {
  const videos = arraySafe(data.videos);
  const nodes = [heroSection(data), statsRow(data), timelineSection(data), pointsSection(data)];
  const byId = new Map(videos.map((v) => [v.id, v]));
  const pick = (ids) => arraySafe(ids).map((id) => byId.get(id)).filter(Boolean);
  // Files written before the two lists existed carry no `lists`: all videos
  // are the most watched, as they always were.
  const top = data.lists ? pick(data.lists.top) : videos;
  const recent = data.lists ? pick(data.lists.recent) : [];
  if (top.length) {
    nodes.push(videosSection(top, 'voci-videos-title', `I ${top.length} video più visti`,
      'Ogni scheda riassume quello che il creatore dice giocando e quello che i suoi spettatori scrivono nei commenti. Le frasi fra virgolette sono citazioni testuali, mai tradotte.'));
  }
  if (recent.length) {
    const min = Number(data.listRules?.recentMinSubscribers) || 0;
    const both = recent.filter((v) => arraySafe(v.lists).includes('top')).length;
    nodes.push(videosSection(recent, 'voci-recent-title', `I ${recent.length} video più recenti`,
      `I più nuovi fra i video di almeno quattro minuti${min ? ` di canali con almeno ${fmtInt(min)} iscritti` : ''}, dal più recente.`
      + (both ? ` ${both === 1 ? 'Uno compare' : `${fmtInt(both)} compaiono`} anche fra i più visti: nell'analisi ${both === 1 ? 'conta' : 'contano'} una volta sola.` : '')));
  }
  const note = sourceNote(data);
  if (note) nodes.push(note);
  main.replaceChildren(...nodes);
}

/* The list lengths are configuration (voices.topN, voices.recentN), so the
 * titles count what arrived instead of spelling a number out. */
function videosSection(videos, id, title, says) {
  const section = el('section', 'voci-videos-section');
  section.setAttribute('aria-labelledby', id);
  const head = el('div', 'voci-section-head');
  const h = el('h2', 'voci-section-title', title);
  h.id = id;
  head.appendChild(h);
  head.appendChild(el('p', 'voci-section-says', says));
  section.appendChild(head);
  const g = el('div', 'voci-videos');
  videos.forEach((v) => g.appendChild(videoCard(v)));
  section.appendChild(g);
  return section;
}

function sourceNote(data) {
  const bits = [];
  const queries = arraySafe(data.queries).map((q) => cap(q, CAP.name)).filter(Boolean);
  if (queries.length) bits.push(`Ricerche YouTube: ${queries.map((q) => `«${q}»`).join(' e ')}.`);
  const excluded = Number(data.stats?.excludedNonRoblox) || 0;
  if (excluded) bits.push(`${excluded} video ${excluded === 1 ? 'escluso' : 'esclusi'} perché ${excluded === 1 ? 'parla' : 'parlano'} di un'altra piattaforma.`);
  const summaryModel = cap(data.models?.summary, CAP.name);
  if (summaryModel) bits.push(`Riepiloghi per video con ${summaryModel}.`);
  bits.push('I riepiloghi sono generati da un modello linguistico e possono sbagliare: le citazioni, no.');
  return bits.length ? el('p', 'voci-source-note', bits.join(' ')) : null;
}
