/**
 * Shared vocabulary for the "Voci" view: the length caps applied to model
 * output and third-party comments, the tone and recency scales with their
 * Italian labels, and the URL guards. Every string that comes from
 * voices.json is inserted with textContent by the callers; nothing here
 * builds markup from data.
 */
import { el } from '../cards.js';

/** Caps from PLAN-voices §8: points 140, quotes 280, verdict 1200. */
export const CAP = { point: 140, quote: 280, verdict: 1200, line: 240, title: 160, name: 60, topic: 80 };

/** Collapse whitespace, trim, hard-cap. Anything that is not a string is dropped. */
export function cap(text, max) {
  if (typeof text !== 'string') return '';
  const s = text.replace(/\s+/g, ' ').trim();
  return s.length <= max ? s : s.slice(0, max - 1).trimEnd() + '…';
}

const ID_RE = /^[A-Za-z0-9_-]{11}$/;
export const isVideoId = (id) => typeof id === 'string' && ID_RE.test(id);
/** Never take an href from the JSON: rebuild it from the validated id. */
export const watchUrl = (id) => (isVideoId(id) ? `https://www.youtube.com/watch?v=${id}` : null);
export const thumbUrl = (id) => (isVideoId(id) ? `/media/yt/${id}.jpg` : null);

export const TONE = {
  positive: { label: 'positivo', cls: 'tone-positive' },
  mixed: { label: 'misto', cls: 'tone-mixed' },
  negative: { label: 'negativo', cls: 'tone-negative' },
};
export const TONE_UNKNOWN = { label: 'non valutato', cls: 'tone-unknown' };
export const toneOf = (t) => TONE[t] ?? TONE_UNKNOWN;

/**
 * Improvements are ordered by urgency, not by date: what spans the whole
 * month first, what has just appeared next, what only older videos mention
 * last — and that one keeps a question mark, because "fixed" is a guess.
 */
export const RECENCY = {
  persistent: { label: 'persistente', cls: 'rec-persistent', rank: 0, hint: 'presente sia nei video vecchi sia nei recenti: non è stato risolto.' },
  recent: { label: 'recente', cls: 'rec-recent', rank: 1, hint: 'compare solo nei video più recenti: è una critica nuova.' },
  old: { label: 'risolto?', cls: 'rec-old', rank: 2, hint: 'compare solo nei video più vecchi: potrebbe essere già stato risolto.' },
};
export const RECENCY_UNKNOWN = { label: 'non datato', cls: 'rec-unknown', rank: 3, hint: 'senza date sufficienti per collocarlo nel tempo.' };
export const recencyOf = (r) => RECENCY[r] ?? RECENCY_UNKNOWN;

/** Tone as a coloured dot plus its word: never colour alone. */
export function toneTag(tone) {
  const meta = toneOf(tone);
  const tag = el('span', `voci-tone ${meta.cls}`);
  const dot = el('span', 'voci-tone-dot');
  dot.setAttribute('aria-hidden', 'true');
  tag.append(dot, document.createTextNode(meta.label));
  return tag;
}

/** A small labelled pill (recency, transcript state, counts). */
export function pill(cls, text, title = null) {
  const p = el('span', `voci-pill ${cls}`, text);
  if (title) p.title = title;
  return p;
}

/** An external link that always carries the safe rel/target pair. */
export function extLink(href, className, text) {
  const a = el('a', className, text);
  a.href = href;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  return a;
}

export const arraySafe = (v) => (Array.isArray(v) ? v : []);
