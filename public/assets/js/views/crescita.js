/**
 * View "Crescita": audience size and quality. DAU with 7-day mean,
 * stickiness, MAU, retention, weekday seasonality, platform mix, new vs
 * returning, visits, session length and peak concurrency.
 */
import { card, grid } from '../cards.js';
import { chart } from '../charts.js';
import { theme, entityColor } from '../chart-option.js';
import { forPeriod, firstValues, rolling, sortByTotal, alignValues, aggregationNote } from '../series.js';
import { kpiRow } from '../kpi.js';
import { fmtDate } from '../format.js';
import { metricCard } from './common.js';

export const title = 'Crescita';
const IT_LABEL = { New: 'Nuovi', Returning: 'Di ritorno' };

export function render(main, state) {
  const t = theme();
  const k = state.data.kpis ?? {};
  const cohort = (kpi) => (kpi?.date ? `coorte del ${fmtDate(kpi.date)}` : 'ultima coorte disponibile');
  main.appendChild(kpiRow([
    { label: 'Retention D1', value: k.d1Retention?.value, unit: 'pct', sub: cohort(k.d1Retention), how: 'Quota della coorte che è rientrata il giorno dopo.' },
    { label: 'Retention D7', value: k.d7Retention?.value, unit: 'pct', sub: cohort(k.d7Retention), how: 'Quota della coorte ancora attiva dopo una settimana.' },
    { label: 'Stickiness 7 giorni', value: k.stickiness7?.value, unit: 'pct', delta: k.stickiness7?.delta, deltaLabel: k.stickiness7?.deltaLabel, how: 'DAU diviso MAU, media dell\u2019ultima settimana.' },
    { label: 'Conversione a pagante 7 giorni', value: k.payingCvr7?.value, unit: 'pct', delta: k.payingCvr7?.delta, deltaLabel: k.payingCvr7?.deltaLabel, how: 'Quota degli attivi che ha speso, ultima settimana.' },
  ]));
  const g = grid();
  main.appendChild(g);

  const cDau = card({ title: 'Utenti attivi giornalieri', span: 8, terms: ['DAU'],
    says: 'Il pubblico di ogni giorno con la sua media mobile a 7 giorni: la linea sottile dice la direzione, le oscillazioni sono il weekend.' });
  g.appendChild(cDau.root);
  chart(cDau, (ctx) => {
    const full = ctx.data.metrics?.DailyActiveUsers;
    if (!full) return null;
    const values = firstValues(full);
    const block = forPeriod({ dates: full.dates, series: [{ label: 'dau', values }, { label: 'avg', values: rolling(values, 7) }] }, ctx.period);
    return { dates: block.dates, unit: 'int', provisionalDate: ctx.data.provisionalDate, footnote: aggregationNote(full), series: [
      { name: 'DAU', values: block.series[0].values, kind: 'area', color: t.palette[0], endLabel: true },
      { name: 'Media 7 giorni', values: block.series[1].values, kind: 'line', thin: true, color: t.soft },
    ] };
  }, { title: 'Utenti attivi giornalieri con media a 7 giorni', tall: true });

  metricCard(g, { id: 'dauMauStickiness', source: 'derived', title: 'Stickiness', span: 4, terms: ['Stickiness'], color: t.palette[2],
    says: 'Quota del bacino mensile che gioca ogni giorno: 0,2 vuol dire circa 6 giorni al mese per utente.' });
  metricCard(g, { id: 'MonthlyActiveUsers', title: 'Utenti attivi mensili', span: 4, terms: ['MAU'], yScale: true, color: t.palette[0],
    says: 'Il bacino degli ultimi 30 giorni: cambia lentamente, perciò è la base più stabile per parlare di crescita.' });

  const cRet = card({ title: 'Retention D1 e D7', span: 8, terms: ['D1 / D7 / D30', 'Coorte'],
    says: 'Quanti nuovi giocatori tornano il giorno dopo e dopo una settimana: la prima impressione e la tenuta oltre la curiosità.' });
  g.appendChild(cRet.root);
  chart(cRet, (ctx) => {
    const d1 = ctx.data.metrics?.ForwardD1Retention;
    const d7 = ctx.data.metrics?.ForwardD7Retention;
    const base = forPeriod((d1?.dates.length ?? 0) >= (d7?.dates.length ?? 0) ? d1 : d7, ctx.period);
    if (!base) return null;
    const series = [];
    if (d1) series.push({ name: 'D1', values: alignValues(base.dates, d1), kind: 'line', color: t.palette[0], endLabel: true });
    if (d7) series.push({ name: 'D7', values: alignValues(base.dates, d7), kind: 'line', color: t.palette[2], endLabel: true });
    return { dates: base.dates, unit: 'pct', series, provisionalDate: ctx.data.provisionalDate, footnote: aggregationNote(d1) ?? aggregationNote(d7) };
  }, { title: 'Retention a 1 e 7 giorni' });

  const seas = state.data.seasonality ?? {};
  const cSeas = card({ title: 'Stagionalità per giorno della settimana', span: 6, terms: ['Indice stagionale', 'DAU', 'R$'],
    says: `Indice rispetto alla media settimanale (1,0)${seas.weeks ? `, su ${seas.weeks} settimane` : ''}: il weekend pesa più sui ricavi che sugli utenti, quindi nel weekend si spende di più per persona.` });
  g.appendChild(cSeas.root);
  chart(cSeas, (ctx) => {
    const s = ctx.data.seasonality;
    if (!s?.weekdays) return null;
    return { categories: s.weekdays, unit: 'dec', markLines: [{ value: 1, label: 'media' }], bands: [{ from: 'sab', to: 'dom', label: 'weekend' }], series: [
      { name: 'Ricavi', values: s.revenueIndex ?? [], kind: 'bar', color: t.accent },
      { name: 'DAU', values: s.dauIndex ?? [], kind: 'bar', color: t.palette[0] },
    ] };
  }, { title: 'Indice di stagionalità per giorno della settimana' });

  const cPlat = card({ title: 'DAU per piattaforma', span: 6, terms: ['DAU'],
    says: 'Da dove entrano i giocatori: il telefono domina, e i crash da memoria della vista Salute vanno letti alla luce di questo.' });
  g.appendChild(cPlat.root);
  chart(cPlat, (ctx) => {
    const block = forPeriod(ctx.data.dimensions?.['DailyActiveUsers|Platform'], ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'int', provisionalDate: ctx.data.provisionalDate,
      series: sortByTotal(block.series).map((s, i) => ({ name: s.label, values: s.values, kind: 'area', stack: 'dau', color: entityColor(s.label, i) })) };
  }, { title: 'Utenti attivi giornalieri per piattaforma' });

  const cNew = card({ title: 'Nuovi vs di ritorno', span: 6, terms: ['DAU'],
    says: 'Quanta parte del pubblico è appena arrivata: se i nuovi calano senza che i ritorni crescano, la distribuzione organica sta rallentando.' });
  g.appendChild(cNew.root);
  chart(cNew, (ctx) => {
    const block = forPeriod(ctx.data.dimensions?.['DailyActiveUsers|IsNewUser'], ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'int', provisionalDate: ctx.data.provisionalDate,
      series: sortByTotal(block.series).map((s, i) => ({ name: IT_LABEL[s.label] ?? s.label, values: s.values, kind: 'area', stack: 'new', color: entityColor(s.label, i) })) };
  }, { title: 'Utenti nuovi e di ritorno' });

  metricCard(g, { id: 'Visits', title: 'Sessioni (visite)', span: 6, color: t.palette[0],
    says: 'Le partite avviate: divise per il DAU dicono quante volte al giorno si rientra.' });
  metricCard(g, { id: 'AverageSessionLengthMinutes', title: 'Durata media sessione', span: 6, yScale: true, color: t.palette[2],
    says: 'Minuti per sessione: se cala subito dopo un aggiornamento, il nuovo contenuto fa uscire prima.' });
  metricCard(g, { id: 'PeakConcurrentPlayers', title: 'Giocatori contemporanei di picco', span: 6, terms: ['PCCU / CCU'], color: t.palette[0],
    says: 'Il massimo di giocatori insieme nel giorno: popolarità istantanea e carico massimo sui server.' });
}
