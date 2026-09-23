/**
 * The synthesis half of "Voci": the verdict hero, the four facts under it,
 * the month-long tone strip and the two-column table of what players like
 * and what they want improved. All text comes from voices.json and is
 * therefore untrusted: it is capped and written with textContent only.
 */
import { el, card, grid } from '../cards.js';
import { kpiRow } from '../kpi.js';
import { fmtDate, fmtInt, fmtTime, fmtCompact } from '../format.js';
import { CAP, cap, toneOf, toneTag, recencyOf, RECENCY, pill, watchUrl, arraySafe } from './common.js';

const day = (iso) => (typeof iso === 'string' && iso.length >= 10 ? Date.parse(iso.slice(0, 10) + 'T12:00:00Z') : NaN);
const plural = (n, one, many) => `${fmtInt(n)} ${n === 1 ? one : many}`;

/* ---- 1. the verdict ------------------------------------------------- */
export function heroSection(data) {
  const s = data.synthesis ?? {};
  const root = el('section', 'voci-hero');
  root.setAttribute('aria-labelledby', 'voci-hero-title');

  const head = el('div', 'voci-hero-head');
  const eyebrow = el('h2', 'voci-eyebrow', 'Il verdetto');
  eyebrow.id = 'voci-hero-title';
  head.appendChild(eyebrow);
  if (s.status === 'stale') {
    const when = s.generatedAt ? fmtDate(s.generatedAt, 'long') : null;
    head.appendChild(pill('rec-old voci-stale', when ? `sintesi del ${when}` : 'sintesi precedente',
      'Oggi il modello non ha risposto: resta l’ultima sintesi riuscita, con la sua data.'));
  }
  root.appendChild(head);

  const verdict = cap(s.verdict, CAP.verdict);
  root.appendChild(el('p', 'voci-verdict', verdict || 'La sintesi di questo ciclo non è disponibile.'));

  const bits = [];
  if (s.generatedAt) bits.push(`Generata il ${fmtDate(s.generatedAt, 'long')} alle ${fmtTime(s.generatedAt)}`);
  const model = cap(s.model ?? data.models?.synthesis, CAP.name);
  if (model) bits.push(`modello ${model}`);
  if (data.models?.fellBackTo) bits.push(`di riserva: ${cap(data.models.fellBackTo, CAP.name)}`);
  const considered = arraySafe(s.videosConsidered).length;
  const total = arraySafe(data.videos).length;
  if (considered) bits.push(`${considered} video su ${total} con una sintesi utilizzabile`);
  const mixed = arraySafe(data.videos).filter((v) => v.mixed === true).length;
  if (mixed) bits.push(`${mixed} ${mixed === 1 ? 'escluso' : 'esclusi'} perché ${mixed === 1 ? 'mostra' : 'mostrano'} più giochi`);
  root.appendChild(el('p', 'voci-hero-meta', bits.join(' · ')));
  return root;
}

/* ---- 2. the four facts ---------------------------------------------- */
export function statsRow(data) {
  const videos = arraySafe(data.videos);
  const views = videos.reduce((a, v) => a + (Number(v.views) || 0), 0);
  const withTranscript = videos.filter((v) => v.transcript?.status === 'ok').length;
  const kept = videos.reduce((a, v) => a + (Number(v.comments?.kept) || 0), 0);
  const fetched = videos.reduce((a, v) => a + (Number(v.comments?.fetched) || 0), 0);
  const dates = videos.map((v) => v.publishedAt).filter(Boolean).sort();
  const span = dates.length ? `dal ${fmtDate(dates[0], 'axis')} al ${fmtDate(dates[dates.length - 1], 'axis')}` : null;
  const candidates = data.stats?.candidates;
  // "Disabled by the creator" and "we could not get it" are different facts:
  // only the first is something the page is entitled to state.
  const disabled = videos.filter((v) => v.transcript?.status === 'missing').length;
  const unusable = videos.filter((v) => v.transcript?.status === 'error' || v.transcript?.status === 'blocked').length;
  const why = [];
  if (disabled) why.push(`${fmtInt(disabled)} con i sottotitoli disabilitati`);
  if (unusable) why.push(`${fmtInt(unusable)} non recuperabili`);
  // The list lengths are configuration (voices.topN, voices.recentN), so never spell them out.
  const n = fmtInt(videos.length);
  const top = arraySafe(data.lists?.top).length;
  const recent = arraySafe(data.lists?.recent).length;
  const analysed = recent
    ? { label: 'Video analizzati', value: fmtInt(videos.length), sub: 'i più visti e i più recenti, senza doppioni',
        how: `I ${fmtInt(top)} più visti e i ${fmtInt(recent)} più recenti di canali con un pubblico: ${n} in tutto, perché un video presente in entrambe le liste si analizza una volta sola.` }
    : { label: 'Video analizzati', value: fmtInt(videos.length), sub: candidates ? `i più visti fra ${fmtInt(candidates)} candidati` : null,
        how: `I ${n} video più visti che nominano il gioco nel titolo.` };
  return kpiRow([
    analysed,
    { label: 'Visualizzazioni raccolte', value: fmtCompact(views), sub: span, accent: true,
      how: `Somma delle visualizzazioni dei ${n} video considerati.` },
    { label: 'Trascrizioni disponibili', value: `${fmtInt(withTranscript)} su ${fmtInt(videos.length)}`,
      sub: why.length ? why.join(', ') : null,
      how: 'Il parlato del creatore è la fonte principale; senza, si usano solo i commenti.' },
    { label: 'Commenti tenuti', value: fmtInt(kept), sub: fetched ? `su ${fmtInt(fetched)} letti` : null,
      how: 'Scartati i commenti rivolti al canale: restano quelli che parlano del gioco.' },
  ]);
}

/* ---- 3. the tone strip ---------------------------------------------- */
export function timelineSection(data) {
  const videos = arraySafe(data.videos).filter((v) => day(v.publishedAt) && !Number.isNaN(day(v.publishedAt)));
  const root = el('section', 'voci-strip');
  root.setAttribute('aria-labelledby', 'voci-strip-title');
  const head = el('div', 'voci-strip-head');
  const h = el('h2', 'voci-strip-title', 'Come si è mosso il giudizio');
  h.id = 'voci-strip-title';
  head.appendChild(h);
  const legend = el('div', 'voci-legend');
  ['positive', 'mixed', 'negative'].forEach((t) => legend.appendChild(toneTag(t)));
  head.append(legend);
  root.appendChild(head);
  if (!videos.length) return root;

  const toneById = new Map(arraySafe(data.synthesis?.timeline).map((e) => [e.id, e.tone]));
  const times = videos.map((v) => day(v.publishedAt));
  const min = Math.min(...times);
  const max = Math.max(...times);
  const axis = el('div', 'voci-axis');
  axis.appendChild(el('div', 'voci-axis-line'));
  videos.forEach((v) => {
    const pct = max > min ? ((day(v.publishedAt) - min) / (max - min)) * 100 : 50;
    axis.appendChild(dot(v, toneById.get(v.id) ?? v.summary?.tone, pct));
  });
  root.appendChild(axis);
  const ends = el('div', 'voci-axis-ends');
  ends.append(el('span', null, fmtDate(new Date(min).toISOString(), 'axis')),
    el('span', null, fmtDate(new Date(max).toISOString(), 'axis')));
  root.appendChild(ends);
  return root;
}

function dot(v, tone, pct) {
  const meta = toneOf(tone);
  const title = cap(v.title, CAP.title);
  const when = fmtDate(v.publishedAt, 'short');
  const href = watchUrl(v.id);
  const node = href ? el('a', `voci-dot ${meta.cls}`) : el('span', `voci-dot ${meta.cls}`);
  if (href) { node.href = href; node.target = '_blank'; node.rel = 'noopener noreferrer'; }
  node.style.left = pct.toFixed(2) + '%';
  node.setAttribute('aria-label', `${when}: ${title} — giudizio ${meta.label}`);
  // Keep the tooltip inside the strip: half its width is a quarter of the axis on a phone.
  const tip = el('span', 'voci-dot-tip' + (pct < 25 ? ' is-start' : pct > 75 ? ' is-end' : ''));
  tip.appendChild(el('span', 'voci-dot-date', `${when} · giudizio ${meta.label}`));
  tip.appendChild(el('span', 'voci-dot-title', title));
  tip.setAttribute('aria-hidden', 'true');
  node.appendChild(tip);
  return node;
}

/* ---- 4. likes / improvements ---------------------------------------- */
export function pointsSection(data) {
  const s = data.synthesis ?? {};
  const g = grid();
  const likes = arraySafe(s.likes).slice().sort((a, b) => arraySafe(b.videos).length - arraySafe(a.videos).length);
  const improvements = arraySafe(s.improvements).slice().sort((a, b) =>
    recencyOf(a.recency).rank - recencyOf(b.recency).rank || arraySafe(b.videos).length - arraySafe(a.videos).length);

  const cLikes = card({ title: 'Cosa piace', span: 6, terms: ['Sintesi', 'Tono'],
    says: `I punti di forza ricorrenti nei ${fmtInt(arraySafe(data.videos).length)} video, dal più citato al meno citato.` });
  cLikes.body.appendChild(pointList(likes, false));
  g.appendChild(cLikes.root);

  const cImp = card({ title: 'Cosa vorrebbero migliorato', span: 6, terms: ['Persistente', 'Risolto?'],
    says: 'Le richieste ricorrenti, ordinate per urgenza: prima quelle che attraversano tutto il mese, poi le nuove, infine quelle sparite dai video recenti.' });
  cImp.body.appendChild(pointList(improvements, true));
  cImp.body.appendChild(recencyLegend());
  g.appendChild(cImp.root);
  return g;
}

function pointList(points, withRecency) {
  if (!points.length) return el('p', 'note', 'Nessun punto ricorrente in questo ciclo.');
  const max = Math.max(...points.map((p) => arraySafe(p.videos).length), 1);
  const list = el('ul', 'voci-points');
  points.forEach((p) => {
    const text = cap(p.point, CAP.point);
    if (!text) return;
    const n = arraySafe(p.videos).length;
    const meta = withRecency ? recencyOf(p.recency) : null;
    const li = el('li', 'voci-point' + (meta ? ' ' + meta.cls : ''));
    const main = el('div', 'voci-point-main');
    main.appendChild(el('span', 'voci-point-text', text));
    if (meta) main.appendChild(pill(meta.cls, meta.label, meta.hint));
    li.appendChild(main);

    const bar = el('div', 'voci-point-bar');
    const fill = el('i', 'voci-point-fill');
    fill.style.width = Math.max(6, Math.round((n / max) * 100)) + '%';
    bar.appendChild(fill);
    li.appendChild(bar);

    const foot = el('div', 'voci-point-foot');
    foot.appendChild(el('span', 'voci-point-count', plural(n, 'video', 'video')));
    const range = spanLabel(p.firstSeen, p.lastSeen);
    if (range) foot.appendChild(el('span', 'voci-point-range', range));
    li.appendChild(foot);
    list.appendChild(li);
  });
  return list;
}

function spanLabel(first, last) {
  if (!first && !last) return null;
  if (first && last && first.slice(0, 10) !== last.slice(0, 10)) return `${fmtDate(first, 'axis')} → ${fmtDate(last, 'axis')}`;
  return fmtDate(last || first, 'axis');
}

function recencyLegend() {
  const box = el('div', 'voci-recency-legend');
  box.appendChild(el('h3', 'voci-legend-title', 'Come leggere le etichette'));
  const dl = el('dl', 'voci-legend-list');
  Object.values(RECENCY).forEach((r) => {
    const dt = el('dt');
    dt.appendChild(pill(r.cls, r.label));
    dl.append(dt, el('dd', null, r.hint));
  });
  box.appendChild(dl);
  return box;
}
