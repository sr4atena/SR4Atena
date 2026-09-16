/**
 * View "Monetizzazione": how the audience turns into revenue. ARPDAU and
 * ARPPU, paying users and conversion, revenue mix by platform, the
 * acquisition funnel for the last complete day, conversion rates, RFY
 * play-through and (when present) advertising.
 */
import { card, grid } from '../cards.js';
import { chart } from '../charts.js';
import { theme, entityColor } from '../chart-option.js';
import { forPeriod, firstValues, valueAt, sortByTotal } from '../series.js';
import { fmtPct } from '../format.js';
import { metricCard, multiMetricCard } from './common.js';

export const title = 'Monetizzazione';

export function render(main, state) {
  const t = theme();
  const d = state.data;
  const g = grid();
  main.appendChild(g);

  metricCard(g, { id: 'arpdauRobux', source: 'derived', title: 'ARPDAU', span: 6, terms: ['ARPDAU', 'R$'], color: t.accent,
    says: 'Robux per utente attivo al giorno: sale nel weekend perché chi gioca di sabato spende di più, non perché ci sono più utenti.' });
  metricCard(g, { id: 'arppuRobux', source: 'derived', title: 'ARPPU', span: 6, terms: ['ARPPU', 'R$'], color: t.accent2, yScale: true,
    says: 'Robux spesi da chi compra davvero: misura il valore del carrello, cioè l’efficacia dei prezzi degli oggetti.' });
  metricCard(g, { id: 'PayingUsers', title: 'Utenti paganti', span: 6, kind: 'bar', color: t.palette[0],
    says: 'Quanti utenti unici hanno speso almeno un Robux nel giorno: l’ampiezza della base pagante, non il suo valore.' });
  metricCard(g, { id: 'PayingUsersCVR', title: 'Conversione a pagante', span: 6, terms: ['CVR'], color: t.palette[2],
    says: 'Quota degli attivi che spende: se è bassa il collo di bottiglia è convincere a comprare, non far spendere di più.' });

  const cShare = card({ title: 'Quota ricavi per piattaforma', span: 6, terms: ['R$'],
    says: 'Da quale dispositivo arrivano i Robux, in percentuale del giorno: un mix stabile è normale, uno scostamento va spiegato.' });
  g.appendChild(cShare.root);
  chart(cShare, (ctx) => {
    const block = forPeriod(ctx.data.derived?.revenuePlatformShare, ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'pct', percentStack: true, provisionalDate: ctx.data.provisionalDate,
      series: sortByTotal(block.series).map((s, i) => ({ name: s.label, values: s.values, kind: 'bar', stack: 'share', color: entityColor(s.label, i) })) };
  }, { title: 'Quota dei ricavi per piattaforma' });

  const cFunnel = card({ title: 'Funnel di acquisizione', span: 6, terms: ['CTR', 'CVR'],
    says: 'Ultimo giorno completo: quanti hanno visto il gioco, quanti hanno aperto la pagina e quanti hanno giocato. Tra parentesi la conversione dal passo precedente.' });
  g.appendChild(cFunnel.root);
  chart(cFunnel, (ctx) => {
    const day = ctx.data.dataThrough;
    const m = ctx.data.metrics ?? {};
    const stages = [
      { label: 'Impression', v: valueAt(m.UniqueUsersWithImpressions, day) },
      { label: 'Pagina del gioco', v: valueAt(m.UniqueUsersWithClicks, day) },
      { label: 'Partita', v: valueAt(m.UniqueUsersWithPlaySessions, day) },
    ].filter((s) => typeof s.v === 'number');
    if (stages.length < 2) return null;
    const cats = stages.map((s, i) => (i === 0 ? s.label : `${s.label} (${fmtPct(s.v / stages[i - 1].v)})`));
    const ramp = ['#3987e5', '#256abf', '#1c5cab'];
    return { categories: cats, unit: 'int', horizontal: true,
      series: [{ name: 'Utenti', values: stages.map((s) => s.v), kind: 'bar', colors: ramp, labels: true }] };
  }, { title: 'Funnel di acquisizione dell’ultimo giorno completo' });

  multiMetricCard(g, { title: 'Tassi di conversione del funnel', span: 8, terms: ['CTR', 'CVR'], unit: 'pct',
    ids: [{ id: 'ImpressionCVR', name: 'Impression → pagina' }, { id: 'ClickCVR', name: 'Pagina → partita' }, { id: 'EndToEndCVR', name: 'End-to-end' }],
    says: 'La thumbnail (impression → pagina), la pagina (pagina → partita) e il totale: quale dei due passaggi perde di più.' });
  metricCard(g, { id: 'RFYPlayThroughRate', title: 'Play-through da Recommended For You', span: 4, terms: ['RFY', 'PTR'], color: t.palette[6],
    says: 'Quanto converte la home personalizzata di Roblox: è il segnale che l’algoritmo usa per decidere se continuare a mostrarti.' });

  const hasAds = d.metrics?.AdsPublisherReportingTotalRevenueRobux || d.metrics?.AdsPublisherReportingTotalImpressions;
  if (hasAds) {
    metricCard(g, { id: 'AdsPublisherReportingTotalRevenueRobux', title: 'Ricavi pubblicitari', span: 6, kind: 'bar', terms: ['R$'], color: t.accent2,
      says: 'Robux dagli annunci mostrati dentro il gioco: il canale alternativo agli acquisti, di solito marginale.' });
    metricCard(g, { id: 'AdsPublisherReportingTotalImpressions', title: 'Impression pubblicitarie', span: 6, color: t.palette[0],
      says: 'Quante volte gli annunci sono stati mostrati: il volume su cui si basano i ricavi pubblicitari.' });
  }
}
