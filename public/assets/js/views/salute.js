/**
 * View "Salute": technical health. Anomaly signals first, then client and
 * server performance, crashes, memory, Data Store / Memory Store request
 * status and abuse reports. Absent metrics render a muted note.
 */
import { card, grid, el, severityBadge } from '../cards.js';
import { chart } from '../charts.js';
import { theme, entityColor } from '../chart-option.js';
import { forPeriod, sortByTotal, breakdownSeries } from '../series.js';
import { fmtDate, fmtUnit, fmtDec } from '../format.js';
import { metricCard } from './common.js';

export const title = 'Salute';

function anomalyCard(a, metrics) {
  const meta = metrics?.[a.metric];
  const unit = meta?.unit ?? 'dec';
  // Colour follows `bad`, not `direction`: a crash-rate drop is reported but is good news.
  const good = a.bad === false;
  const root = el('article', `signal ${good ? 'signal-good' : 'signal-' + (a.severity ?? 'medium')}`);
  const head = el('div', 'signal-head');
  const name = (a.name || meta?.name || a.metric) + (a.label ? ` \u00b7 ${a.label}` : '');
  head.append(good ? el('span', 'badge badge-good', 'migliora') : severityBadge(a.severity ?? 'medium'), el('span', 'signal-metric', name), el('time', 'signal-date', fmtDate(a.date)));
  root.appendChild(head);
  root.appendChild(el('p', 'signal-msg', a.message ?? ''));
  const facts = el('p', 'signal-facts');
  facts.append(el('strong', null, fmtUnit(a.value, unit)), document.createTextNode(` rilevato · atteso ${fmtUnit(a.expected, unit)}`));
  if (typeof a.zScore === 'number') facts.appendChild(document.createTextNode(` · z ${fmtDec(a.zScore, 1)}`));
  root.appendChild(facts);
  return root;
}

function statusCard(g, { id, title, says, terms }) {
  const c = card({ title, says, terms, span: 6 });
  g.appendChild(c.root);
  chart(c, (ctx) => {
    const block = forPeriod(ctx.data.metrics?.[id], ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: block.unit ?? 'int', provisionalDate: ctx.data.provisionalDate,
      series: sortByTotal(breakdownSeries(block)).map((s, i) => ({ name: s.label, values: s.values, kind: 'bar', stack: 'status', color: entityColor(s.label, i) })) };
  }, { title });
}

export function render(main, state) {
  const t = theme();
  const d = state.data;
  const g = grid();
  main.appendChild(g);

  const cSig = card({ title: 'Segnali', span: 12, terms: ['z-score', 'Baseline', 'MAD'], kind: 'signals',
    says: 'Scostamenti dalla baseline dello stesso giorno della settimana, calcolati con uno z-score robusto: qui compare solo ciò che merita uno sguardo.' });
  g.appendChild(cSig.root);
  const anomalies = d.anomalies ?? [];
  if (anomalies.length) {
    const list = el('div', 'signals');
    anomalies.forEach((a) => list.appendChild(anomalyCard(a, d.metrics)));
    cSig.body.appendChild(list);
  } else {
    const ok = el('p', 'signal-none');
    ok.append(el('span', 'badge badge-good', 'ok'), document.createTextNode(' Nessuna anomalia rilevata negli ultimi giorni.'));
    cSig.body.appendChild(ok);
  }

  metricCard(g, { id: 'ClientFpsAvg', title: 'FPS medi client', span: 6, terms: ['FPS'], yScale: true, color: t.palette[0],
    markLines: [{ value: 30, label: 'soglia percepita' }],
    says: 'Fluidità sui dispositivi dei giocatori: sotto 30 il gioco appare scattoso e la retention ne risente.' });
  metricCard(g, { id: 'ServerFrameRateAvg', title: 'FPS medi server', span: 6, terms: ['FPS'], yScale: true, color: t.palette[2],
    markLines: [{ value: 30, label: 'lag visibile' }],
    says: 'Heartbeat dei server (ideale 60): sotto 30 si vedono lag, teletrasporti e colpi non registrati.' });

  metricCard(g, { id: 'ClientCrashRate15m', title: 'Tasso di crash client (15 min)', span: 4, color: t.palette[1],
    says: 'Quota di sessioni che crasha nei primi 15 minuti: normalizzata sul traffico, quindi confrontabile fra giorni diversi.' });
  metricCard(g, { id: 'ClientCrashCount', title: 'Crash client', span: 4, kind: 'bar', color: t.palette[1],
    says: 'Crash in valore assoluto: ogni crash è una sessione interrotta e, spesso, un giocatore perso.' });
  metricCard(g, { id: 'ServerCrashCount', title: 'Crash server', span: 4, kind: 'bar', color: t.palette[7],
    says: 'Ogni crash del server butta fuori tutti i giocatori di quell’istanza insieme.' });

  metricCard(g, { id: 'OomUnexpectedExits', title: 'Uscite per esaurimento memoria', span: 6, terms: ['OOM'], kind: 'bar', color: t.palette[1],
    says: 'Chiusure improvvise per memoria finita: puntano a perdite di memoria o asset troppo pesanti per i telefoni.' });
  metricCard(g, { id: 'ClientMemoryUsageAvg', title: 'Memoria client', span: 6, yScale: true, color: t.palette[0],
    says: 'RAM media occupata sul dispositivo del giocatore: se cresce senza un update che lo spieghi, è una perdita.' });

  statusCard(g, { id: 'DataStoreRequestsByStatus', title: 'Richieste Data Store per stato', terms: ['DataStore', 'Rate limit'],
    says: 'Le richieste che non sono «Ok» anticipano progressi persi. Clicca «Ok» nella legenda per isolare gli errori.' });
  statusCard(g, { id: 'MemoryStoreRequestsByStatus', title: 'Richieste Memory Store per stato', terms: ['Rate limit'],
    says: 'Errori qui rompono code e sistemi in tempo reale. Clicca «Ok» nella legenda per isolare gli errori.' });

  metricCard(g, { id: 'TotalAbuseReports', title: 'Segnalazioni di abuso', span: 12, kind: 'bar', color: t.palette[6],
    says: 'Segnalazioni inviate dai giocatori: un picco indica un exploit in corso o un problema di moderazione.' });
}
