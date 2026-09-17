/**
 * View "Analisi Ads": where the players came from, what the advertising cost
 * and what came back.
 *
 * Three questions, in this order, because that is the order they get argued
 * in: which sources bring the players (and how much of the whole they are),
 * what each flight of ads cost per day, and whether the money came back. The
 * cost series is derived (dashboard.json `ads`, see Analytics\AdsAnalysis) and
 * every card that uses it says so: the export gives a total per campaign, the
 * API gives the daily shape, the join is an estimate and is labelled as one.
 */
import { card, grid, el, table, note } from '../cards.js';
import { chart } from '../charts.js';
import { kpiRow } from '../kpi.js';
import { theme } from '../chart-option.js';
import { forPeriod, firstValues, sortByTotal, alignValues, seriesByLabel, sum } from '../series.js';
import { fmtUsd, fmtPct, fmtInt, fmtDate } from '../format.js';

export const title = 'Analisi Ads';

/** Roblox's source names as a player would say them. */
const SOURCE_IT = {
  SponsoredAds: 'Ads sponsorizzate',
  SearchAds: 'Ads nella ricerca',
  HomeRecommendation: 'Consigliati in home',
  Search: 'Ricerca',
  Charts: 'Classifiche',
  Friends: 'Amici',
  ContinueToPlay: 'Riprendi a giocare',
  Moments: 'Momenti',
  CrossGame: 'Altri giochi',
  Curation: 'Selezione Roblox',
  Others: 'Altre fonti',
};
const label = (source) => SOURCE_IT[source] ?? source;

/** Colour follows the source on every chart of the view; the paid ones wear the accent. */
function sourceColor(t) {
  return {
    SponsoredAds: t.accent,
    SearchAds: t.accent2,
    HomeRecommendation: t.palette[0],
    Search: t.palette[2],
    Charts: t.palette[3],
    Friends: t.palette[4],
    ContinueToPlay: t.palette[6],
    Moments: t.palette[5],
    CrossGame: t.palette[7],
    Curation: t.palette[1],
    Others: t.dim,
  };
}

/** Campaign windows clipped to the days on screen; outside them ECharts draws nothing. */
function bandsFor(dates, windows) {
  if (!dates?.length || !windows?.length) return null;
  const first = dates[0];
  const last = dates[dates.length - 1];
  const out = [];
  windows.forEach((w) => {
    const from = w.from < first ? first : w.from;
    const to = w.to > last ? last : w.to;
    if (from <= to && from <= last && to >= first) out.push({ from, to, label: w.label });
  });
  return out.length ? out : null;
}

/**
 * Ads Manager names a campaign after the minute it was created, and the label
 * already carries the start date: "The Locusts Manor_09112026_12:19PM · 11/09"
 * becomes "11/09 · The Locusts Manor · 12:19", short enough for a legend. The
 * clock time stays on, because two flights can start on the same day.
 */
function shortCampaign(label) {
  const [name, day] = String(label).split(' \u00b7 ');
  const stamp = name.match(/_(\d{1,2}:\d{2})\s*[AP]M$/i);
  const base = name.replace(/_\d{6,8}_\d{1,2}:\d{2}\s*[AP]M$/i, '').trim();
  const short = base.length > 26 ? base.slice(0, 25) + '\u2026' : base;
  return [day, short, stamp?.[1]].filter(Boolean).join(' \u00b7 ');
}

/** Day-by-day share of each series over their own total (the percent-stack input). */
function shares(series) {
  const totals = series[0].values.map((_, i) => series.reduce((a, s) => a + (typeof s.values[i] === 'number' ? s.values[i] : 0), 0));
  return series.map((s) => ({ ...s, values: s.values.map((v, i) => (totals[i] > 0 && typeof v === 'number' ? v / totals[i] : null)) }));
}

export function render(main, state) {
  const t = theme();
  const d = state.data;
  const ads = d.ads ?? {};
  const totals = ads.totals ?? {};
  const colors = sourceColor(t);

  main.appendChild(kpiRow([
    { label: 'Speso in pubblicità', value: totals.spentUsd, unit: 'usd', accent: true,
      sub: totals.campaigns ? `${totals.campaigns} campagne, ${totals.spendDays ?? 0} giorni di spesa` : 'nessun export importato',
      how: 'Somma dell’export Ads Manager: la spesa reale, non una stima.' },
    { label: 'Costo per giocatore al giorno', value: totals.costPerDauDay, unit: 'usd',
      sub: totals.buyersDauDays ? `${fmtInt(totals.buyersDauDays)} DAU-giorno comprati` : null,
      how: 'Spesa divisa per i giocatori-giorno arrivati dalle ads: quanto costa tenere in gioco una persona per un giorno.' },
    { label: 'Ricavo attribuito', value: totals.revenueUsdNet, unit: 'usd',
      sub: totals.revenueUsdNetAll ? `${fmtUsd(totals.revenueUsdNetAll)} netti contando anche i ritorni dopo` : '$ netti',
      how: 'Quota di ricavi dei giorni di spesa pari alla quota di giocatori arrivati dalle ads.' },
    { label: 'Ritorno diretto', value: totals.roi, unit: 'pct',
      sub: typeof totals.roi === 'number' ? (totals.roi >= 1 ? 'la pubblicità si ripaga' : 'sotto il pareggio') : null,
      how: 'Ricavo attribuito diviso spesa. Sopra il 100% ogni dollaro speso rientra; sotto, no.' },
  ]));

  if (ads.ledger) {
    main.appendChild(el('p', 'model-note', methodNote(ads)));
  } else {
    main.appendChild(el('p', 'model-note',
      'Nessun export Ads Manager importato: i grafici delle fonti funzionano lo stesso, quelli di costo e ritorno restano vuoti. '
      + 'Per popolarli scarica l’export delle campagne e importalo con bin/ads-import.'));
  }

  const g = grid();
  main.appendChild(g);
  const windows = ads.windows ?? [];

  /* ---- 1. le fonti ------------------------------------------------- */
  const cSources = card({ title: 'Da dove arrivano i giocatori', span: 12, terms: ['Fonte di acquisizione', 'DAU'],
    says: 'Le sessioni avviate ogni giorno, divise per come il giocatore ha trovato il gioco. Le fasce ombreggiate sono i giorni con ads attive: '
      + 'è lì che si vede se la spesa ha spostato qualcosa anche sulle fonti gratuite.' });
  g.appendChild(cSources.root);
  chart(cSources, (ctx) => {
    const block = forPeriod(ctx.data.dimensions?.['UniqueUsersWithPlaySessions|AcquisitionSource'], ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'int', provisionalDate: ctx.data.provisionalDate, bands: bandsFor(block.dates, windows),
      series: sortByTotal(block.series).map((s) => ({ name: label(s.label), values: s.values, kind: 'area', stack: 'src', color: colors[s.label] ?? t.dim })) };
  }, { title: 'Giocatori con almeno una partita per fonte di acquisizione', tall: true });

  const cMix = card({ title: 'Quanto pesa ogni fonte', span: 6, terms: ['Fonte di acquisizione'],
    says: 'Le stesse fonti in percentuale: toglie di mezzo la stagionalità e lascia solo il mix. Se le ads sponsorizzate sono una fetta sottile anche quando la spesa è alta, '
      + 'il gioco vive di traffico organico.' });
  g.appendChild(cMix.root);
  chart(cMix, (ctx) => {
    const block = forPeriod(ctx.data.dimensions?.['UniqueUsersWithPlaySessions|AcquisitionSource'], ctx.period);
    if (!block) return null;
    const series = sortByTotal(block.series).map((s) => ({ name: label(s.label), values: s.values, kind: 'area', stack: 'mix', color: colors[s.label] ?? t.dim }));
    return { dates: block.dates, unit: 'pct', percentStack: true, bands: bandsFor(block.dates, windows), series: shares(series) };
  }, { title: 'Quota di ogni fonte di acquisizione sul totale' });

  const cSplit = card({ title: 'Comprati e organici', span: 6, terms: ['DAU', 'Ads sponsorizzate'],
    says: 'Il pubblico del giorno diviso in due: chi è arrivato da una fonte a pagamento e tutti gli altri. Roblox continua ad attribuire alla sorgente originale anche chi torna, '
      + 'quindi la parte comprata resta visibile per giorni dopo la fine della campagna.' });
  g.appendChild(cSplit.root);
  chart(cSplit, (ctx) => {
    const block = forPeriod(ctx.data.ads?.dauSplit, ctx.period);
    if (!block) return null;
    const paid = seriesByLabel(block, 'paid');
    const organic = seriesByLabel(block, 'organic');
    return { dates: block.dates, unit: 'int', provisionalDate: ctx.data.provisionalDate, bands: bandsFor(block.dates, windows), series: [
      { name: 'Da ads', values: paid?.values ?? [], kind: 'area', stack: 'dau', color: t.accent },
      { name: 'Organici', values: organic?.values ?? [], kind: 'area', stack: 'dau', color: t.palette[0] },
    ] };
  }, { title: 'Utenti attivi da fonti a pagamento e organiche' });

  /* ---- 2. i costi -------------------------------------------------- */
  const cSpend = card({ title: 'Spesa giornaliera per campagna', span: 6, terms: ['Ad Credit'],
    says: 'Quanto è costata ogni giornata, campagna per campagna. La spesa è quella reale dell’export, distribuita sui giorni seguendo le impression che Roblox ha misurato: '
      + 'il totale è esatto, la forma dentro la campagna è stimata.' });
  g.appendChild(cSpend.root);
  chart(cSpend, (ctx) => {
    const block = forPeriod(ctx.data.ads?.spendByCampaign, ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'usd',
      series: sortByTotal(block.series).map((s, i) => ({ name: shortCampaign(s.label), values: s.values, kind: 'bar', stack: 'spend', color: t.palette[i % t.palette.length] })) };
  }, { title: 'Spesa pubblicitaria giornaliera per campagna' });

  const cCost = card({ title: 'Quanto costa un giocatore', span: 6, terms: ['CPP', 'DAU'],
    says: 'Spesa del giorno divisa per i giocatori che le ads hanno portato. È il prezzo dell’asta: se sale a parità di campagna, comprare pubblico sta diventando più caro.' });
  g.appendChild(cCost.root);
  chart(cCost, (ctx) => {
    const perDau = forPeriod(ctx.data.ads?.costPerDauDay, ctx.period);
    const perPlay = forPeriod(ctx.data.ads?.costPerPlay, ctx.period);
    if (!perDau && !perPlay) return null;
    const base = perDau ?? perPlay;
    const series = [];
    if (perDau) series.push({ name: 'per giocatore-giorno', values: alignValues(base.dates, perDau), kind: 'line', color: t.accent, endLabel: true });
    if (perPlay) series.push({ name: 'per partita avviata', values: alignValues(base.dates, perPlay), kind: 'line', color: t.palette[1], endLabel: true });
    return { dates: base.dates, unit: 'usd', yScale: true, series };
  }, { title: 'Costo per giocatore-giorno e per partita avviata' });

  /* ---- 3. i ritorni ------------------------------------------------ */
  const cReturn = card({ title: 'Speso e rientrato, giorno per giorno', span: 6, terms: ['Netto', 'R$', 'DevEx'],
    says: 'Le due barre da confrontare: quanto è uscito e quanto di quel giorno è attribuibile ai giocatori arrivati dalle ads, già al netto di DevEx e royalty. '
      + 'La barra verde più bassa di quella arancione vuol dire che quel giorno la pubblicità non si è ripagata.' });
  g.appendChild(cReturn.root);
  chart(cReturn, (ctx) => {
    const spend = forPeriod(ctx.data.ads?.spend, ctx.period);
    const revenue = forPeriod(ctx.data.ads?.revenueUsdNet, ctx.period);
    if (!spend && !revenue) return null;
    const base = (spend?.dates.length ?? 0) >= (revenue?.dates.length ?? 0) ? spend : revenue;
    return { dates: base.dates, unit: 'usd', provisionalDate: ctx.data.provisionalDate, series: [
      { name: 'Speso', values: alignValues(base.dates, spend), kind: 'bar', color: t.palette[1] },
      { name: 'Ricavo attribuito', values: alignValues(base.dates, revenue), kind: 'bar', color: t.palette[2] },
    ] };
  }, { title: 'Spesa pubblicitaria e ricavo attribuito per giorno' });

  const cRoi = card({ title: 'Ritorno per dollaro speso', span: 6, terms: ['ROI'],
    says: 'Il rapporto fra le due barre precedenti. La linea al 100% è il pareggio: sopra, la giornata di pubblicità si è ripagata da sola; sotto, ha bisogno del passaparola per valere la spesa.' });
  g.appendChild(cRoi.root);
  chart(cRoi, (ctx) => {
    const block = forPeriod(ctx.data.ads?.roi, ctx.period);
    if (!block) return null;
    const values = firstValues(block);
    const peak = values.reduce((m, x) => (typeof x === 'number' && x > m ? x : m), 0);
    // The break-even line is the point of this chart: keep it inside the axis
    // even in a week where nothing came close to it, and on a round tick so it
    // does not land between two labels.
    const top = [1.25, 2.5, 5, 10].find((s) => peak * 1.05 <= s) ?? Math.ceil(peak * 1.05);
    return { dates: block.dates, unit: 'pct', yMax: top, markLines: [{ value: 1, label: 'pareggio' }],
      series: [{ name: 'Ritorno diretto', values, kind: 'bar', color: t.accent }] };
  }, { title: 'Ricavo attribuito diviso spesa, per giorno' });

  const cCum = card({ title: 'Il conto cumulato', span: 12, terms: ['Netto'],
    says: 'La stessa storia sommata da inizio storico: la distanza fra le due linee è quanto la pubblicità è costata al netto di ciò che ha riportato. '
      + 'Si chiude solo se le due curve convergono.' });
  g.appendChild(cCum.root);
  chart(cCum, (ctx) => {
    const block = forPeriod(ctx.data.ads?.cumulative, ctx.period);
    if (!block) return null;
    const spend = seriesByLabel(block, 'spend');
    const revenue = seriesByLabel(block, 'revenue');
    return { dates: block.dates, unit: 'usd', bands: bandsFor(block.dates, windows), series: [
      { name: 'Speso', values: spend?.values ?? [], kind: 'line', color: t.palette[1], endLabel: true },
      { name: 'Rientrato (netto)', values: revenue?.values ?? [], kind: 'area', color: t.palette[2], endLabel: true },
    ] };
  }, { title: 'Spesa cumulata contro ricavo attribuito cumulato' });

  /* ---- 4. la qualità di ciò che si compra -------------------------- */
  const cRet = card({ title: 'Il giocatore comprato vale quanto l’altro?', span: 6, terms: ['D1 / D7 / D30', 'Coorte'],
    says: 'Retention del giorno dopo, confrontando chi è arrivato da una fonte a pagamento con chi è arrivato gratis. '
      + 'È il controllo che tiene in piedi tutta l’attribuzione dei ricavi: se le due linee si separano, il ritorno stimato sopra va corretto.' });
  g.appendChild(cRet.root);
  chart(cRet, (ctx) => {
    const block = forPeriod(ctx.data.ads?.retentionBySource?.d1, ctx.period);
    if (!block) return null;
    const paid = seriesByLabel(block, 'paid');
    const organic = seriesByLabel(block, 'organic');
    return { dates: block.dates, unit: 'pct', bands: bandsFor(block.dates, windows), series: [
      { name: 'Da ads', values: paid?.values ?? [], kind: 'line', color: t.accent, endLabel: true },
      { name: 'Organici', values: organic?.values ?? [], kind: 'line', color: t.palette[0], endLabel: true },
    ] };
  }, { title: 'Retention D1 del traffico a pagamento e di quello organico' });

  const cCvr = card({ title: 'Quale fonte converte meglio', span: 6, terms: ['CVR', 'PTR'],
    says: 'Quota di chi, visto il gioco da quella fonte, finisce davvero per giocare. Dice se una fonte porta volume o porta intenzione: '
      + 'le ads comprano impression, le classifiche e la ricerca portano gente che stava già cercando qualcosa del genere.' });
  g.appendChild(cCvr.root);
  chart(cCvr, (ctx) => {
    const block = forPeriod(ctx.data.dimensions?.['EndToEndCVR|AcquisitionSource'], ctx.period);
    if (!block) return null;
    return { dates: block.dates, unit: 'pct',
      series: sortByTotal(block.series).slice(0, 6).map((s) => ({ name: label(s.label), values: s.values, kind: 'line', color: colors[s.label] ?? t.dim })) };
  }, { title: 'Conversione da impression a partita per fonte' });

  /* ---- 5. le campagne, una per riga -------------------------------- */
  const rows = ads.campaigns ?? [];
  if (rows.length) g.appendChild(campaignsCard(rows, ads));
}

function campaignsCard(rows, ads) {
  const c = card({ title: 'Le campagne, una per una', span: 12, terms: ['CPM', 'CPC', 'CPP', 'ROI'],
    says: 'Ogni riga è una campagna dell’export. Le date sono i giorni su cui la spesa è stata effettivamente distribuita, '
      + 'non quelle dichiarate in Ads Manager: una campagna lasciata aperta continuerebbe a dire di essere in corso.' });
  const columns = [
    { key: 'name', label: 'Campagna', fmt: (r) => r.name ?? '—' },
    { key: 'from', label: 'Periodo', fmt: (r) => `${fmtDate(r.from, 'axis')} → ${fmtDate(r.to, 'axis')}${r.running ? ' (aperta)' : ''}` },
    { key: 'spent', label: 'Speso', num: true, fmt: (r) => fmtUsd(r.spent) },
    { key: 'impressions', label: 'Impression', num: true, fmt: (r) => fmtInt(r.impressions) },
    { key: 'clicks', label: 'Click', num: true, fmt: (r) => fmtInt(r.clicks) },
    { key: 'ctr', label: 'CTR', num: true, fmt: (r) => fmtPct(r.ctr) },
    { key: 'plays', label: 'Partite', num: true, fmt: (r) => fmtInt(r.plays) },
    { key: 'cpp', label: 'Costo/partita', num: true, fmt: (r) => fmtUsd(r.cpp) },
    { key: 'revenueUsdNet', label: 'Ricavo attribuito', num: true, fmt: (r) => fmtUsd(r.revenueUsdNet) },
    { key: 'roi', label: 'Ritorno', num: true, fmt: (r) => fmtPct(r.roi),
      cls: (r) => (typeof r.roi !== 'number' ? null : r.roi >= 1 ? 'num-good' : 'num-bad') },
  ];
  c.body.appendChild(table(columns, rows, { caption: 'Campagne pubblicitarie con spesa, volumi e ritorno attribuito' }));
  const spent = sum(rows.map((r) => r.spent));
  const returned = sum(rows.map((r) => r.revenueUsdNet));
  note(c.body, `In totale ${fmtUsd(spent)} spesi e ${fmtUsd(returned)} netti attribuiti nei giorni di spesa`
    + (ads.totals?.revenueUsdNetAll ? `, ${fmtUsd(ads.totals.revenueUsdNetAll)} contando anche i giorni successivi.` : '.'));
  return c.root;
}

/** One paragraph, said once, on where the numbers come from and what is estimated. */
function methodNote(ads) {
  const l = ads.ledger ?? {};
  const window = l.window?.from && l.window?.to ? `${fmtDate(l.window.from, 'long')} – ${fmtDate(l.window.to, 'long')}` : null;
  const estimated = (ads.spendEstimated ?? []).length;
  return 'Spesa: export Ads Manager'
    + (window ? ` (finestra ${window})` : '')
    + (l.importedAt ? `, importato il ${fmtDate(l.importedAt, 'long')}` : '')
    + '. L’export dà un totale per campagna, non un giorno per giorno: la spesa è distribuita sui giorni della campagna seguendo le impression a pagamento misurate dall’API, '
    + 'così che i totali restino esatti e la forma sia quella reale della consegna'
    + (estimated ? `; ${estimated} ${estimated === 1 ? 'giorno è ripartito' : 'giorni sono ripartiti'} in parti uguali per mancanza di impression.` : '.')
    + ' Ricavo attribuito: la quota di giocatori arrivata dalle ads prende la stessa quota dei ricavi del giorno, al netto di DevEx e royalty — '
    + 'lecito finché la retention del traffico comprato resta pari a quella organica (grafico in fondo).';
}
