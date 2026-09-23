/**
 * One card per video: thumbnail, title, provenance, the model's one-line
 * reading, the praise/requests as chips and the verbatim player quotes.
 * The thumbnail placeholder is bound in JS (no inline onerror: the CSP
 * forbids inline handlers) and every quote stays a plain text node.
 */
import { el } from '../cards.js';
import { fmtDate, fmtInt, fmtCompact } from '../format.js';
import { CAP, cap, toneTag, pill, extLink, watchUrl, thumbUrl, arraySafe } from './common.js';

const TRANSCRIPT_BADGE = {
  missing: ['trascrizione non disponibile', 'Il creatore ha disattivato i sottotitoli: la sintesi viene dai soli commenti.'],
  blocked: ['trascrizione bloccata', 'YouTube ha rifiutato la richiesta dei sottotitoli per questo video.'],
  error: ['trascrizione non recuperata', 'Il recupero dei sottotitoli è fallito: la sintesi viene dai soli commenti.'],
};
const SOURCE_LABEL = { transcript: 'trascrizione', comments: 'commenti' };

export function videoCard(v) {
  const s = v.summary ?? {};
  const failed = s.status !== 'ok';
  const root = el('article', 'voci-video' + (failed ? ' is-failed' : ''));
  const title = cap(v.title, CAP.title) || 'Video senza titolo';
  const href = watchUrl(v.id);

  root.appendChild(thumbnail(v, title, href));

  const body = el('div', 'voci-video-body');
  const h = el('h3', 'voci-video-title');
  h.appendChild(href ? extLink(href, 'voci-video-link', title) : el('span', null, title));
  body.appendChild(h);

  const meta = el('p', 'voci-video-meta');
  const parts = [cap(v.channel, CAP.name), v.publishedAt ? fmtDate(v.publishedAt, 'short') : null,
    typeof v.views === 'number' ? `${fmtCompact(v.views)} visualizzazioni` : null].filter(Boolean);
  parts.forEach((text, i) => {
    if (i) meta.appendChild(el('span', 'voci-sep', '·'));
    meta.appendChild(el('span', null, text));
  });
  body.appendChild(meta);

  const flags = el('div', 'voci-video-flags');
  if (!failed) flags.appendChild(toneTag(s.tone));
  const badge = TRANSCRIPT_BADGE[v.transcript?.status];
  if (badge) flags.appendChild(pill('voci-pill-warn', badge[0], badge[1]));
  const sources = arraySafe(s.basedOn).map((b) => SOURCE_LABEL[b]).filter(Boolean);
  if (sources.length) flags.appendChild(pill('voci-pill-soft', 'da ' + sources.join(' + ')));
  if (v.mixed === true) {
    flags.appendChild(pill('voci-pill-warn', 'più giochi nel video',
      'Il creatore gioca anche ad altri titoli nello stesso video: la scheda resta, ma non entra nella sintesi generale.'));
  }
  if (flags.childNodes.length) body.appendChild(flags);

  if (failed) body.appendChild(failureNote(v));
  else {
    const line = cap(s.oneLine, CAP.line);
    if (line) body.appendChild(el('p', 'voci-oneline', line));
    const points = el('div', 'voci-video-points');
    chipList(points, 'Piace', s.likes, 'is-like', '+');
    chipList(points, 'Da migliorare', s.improvements, 'is-improve', '→');
    if (points.childNodes.length) body.appendChild(points);
    arraySafe(s.quotes).slice(0, 3).forEach((q) => {
      const fig = quote(q);
      if (fig) body.appendChild(fig);
    });
  }
  root.appendChild(body);
  return root;
}

function thumbnail(v, title, href) {
  const src = thumbUrl(v.id);
  const frame = href ? extLink(href, 'voci-thumb', null) : el('div', 'voci-thumb');
  if (href) frame.setAttribute('aria-label', `Guarda su YouTube: ${title}`);
  const ph = el('span', 'voci-thumb-ph');
  ph.appendChild(el('span', 'voci-thumb-ph-mark', '▶'));
  ph.appendChild(el('span', 'voci-thumb-ph-text', 'anteprima non disponibile'));
  ph.setAttribute('aria-hidden', 'true');
  if (!src) { frame.classList.add('is-empty'); frame.appendChild(ph); return frame; }
  const img = el('img', 'voci-thumb-img');
  img.src = src;
  img.alt = `Anteprima del video: ${title}`;
  img.width = 320;
  img.height = 180;
  img.loading = 'lazy';
  img.decoding = 'async';
  // The thumbnail may not have been published yet: degrade to a placeholder.
  img.addEventListener('error', () => {
    img.remove();
    frame.classList.add('is-empty');
    if (!frame.contains(ph)) frame.appendChild(ph);
  }, { once: true });
  frame.appendChild(img);
  return frame;
}

function chipList(target, label, items, cls, glyph) {
  const list = arraySafe(items).map((t) => cap(t, CAP.point)).filter(Boolean).slice(0, 6);
  if (!list.length) return;
  const group = el('div', 'voci-chipgroup');
  group.appendChild(el('h4', 'voci-chipgroup-title', label));
  const ul = el('ul', `voci-chips ${cls}`);
  list.forEach((text) => {
    const li = el('li', 'voci-chip');
    const g = el('span', 'voci-chip-glyph', glyph);
    g.setAttribute('aria-hidden', 'true');
    li.append(g, document.createTextNode(text));
    ul.appendChild(li);
  });
  group.appendChild(ul);
  target.appendChild(group);
}

function quote(q) {
  const text = cap(q?.text, CAP.quote);
  if (!text) return null;
  const fig = el('figure', 'voci-quote');
  const bq = el('blockquote', 'voci-quote-text', text);
  fig.appendChild(bq);
  const bits = [cap(q.topic, CAP.topic), typeof q.likes === 'number' && q.likes > 0 ? `${fmtInt(q.likes)} mi piace` : null].filter(Boolean);
  if (bits.length) fig.appendChild(el('figcaption', 'voci-quote-meta', bits.join(' · ')));
  return fig;
}

function failureNote(v) {
  const box = el('div', 'voci-failed-note');
  box.appendChild(el('p', 'voci-failed-title', 'Sintesi non disponibile'));
  const noTranscript = v.transcript?.status !== 'ok';
  const kept = Number(v.comments?.kept) || 0;
  const why = noTranscript && kept === 0
    ? 'Niente sottotitoli e nessun commento che parli del gioco: non c’era materiale su cui lavorare.'
    : 'Il modello non ha restituito un riepilogo valido per questo video. Il prossimo ciclo riprova.';
  box.appendChild(el('p', 'voci-failed-text', why));
  return box;
}
