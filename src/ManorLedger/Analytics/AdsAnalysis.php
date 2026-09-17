<?php
/**
 * What the advertising did, day by day: who it brought, what it cost and what
 * came back.
 *
 * Two sources that do not meet anywhere else have to be joined here:
 *
 * - the **Analytics API**, which knows every day how many players arrived from
 *   each AcquisitionSource (SponsoredAds and SearchAds are the paid ones) but
 *   knows nothing about money spent;
 * - the **Ads Manager export** (data/ads.json), which knows the money to the
 *   cent but only as a total per campaign over the whole export window.
 *
 * So a daily cost has to be derived, and the derivation is stated on the chart
 * rather than hidden: a campaign's spend follows the paid impressions it is
 * estimated to have served each day (see spendByCampaign()). A campaign that
 * ran hot on Saturday and idle on Monday gets a Saturday-shaped cost curve
 * instead of a flat average. When no impression data covers the campaign
 * (older days, before the pairs were fetched) the spend falls back to an even
 * spread, and `spendEstimated` marks the days where that happened.
 *
 * Revenue is attributed the same way the manual analysis does it: paid players
 * are worth what every other player is worth, so the share of DAU that came
 * from ads takes the same share of the day's revenue. That is only legitimate
 * because the retention of paid traffic was measured to match the organic one
 * (D1 0.97x, D7 1.00x) — the view carries the caveat, and the D1/D7-by-source
 * chart lets the reader check that it still holds.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class AdsAnalysis
{
    /** The AcquisitionSource labels that cost money. Everything else is organic. */
    public const PAID_LABELS = ['SponsoredAds', 'SearchAds'];
    /** IPF converges fast on a table this small; the cap is only there so a degenerate one cannot spin. */
    private const FIT_PASSES = 40;
    /**
     * Half a cent. A campaign left open in Ads Manager keeps a nominal window
     * long after it stopped delivering, and the fit answers with fractions of
     * a cent on those days. Below this they are not spend: they would print as
     * $ 0,00 bars and, worse, divide a real revenue by almost zero.
     */
    private const SPEND_FLOOR = 0.005;

    public function __construct(private readonly Economics $economics)
    {
    }

    /**
     * @param array|null $ledger  Decoded data/ads.json, or null when no export was ever imported.
     * @param array<string, array<string, array<string, int|float|null>>> $bySource
     *        Keyed 'impressions'|'clicks'|'plays'|'dau'|'d1'|'d7', each label => (date => value).
     * @param array<string, int|float|null> $revenue Daily revenue in Robux (consolidated days).
     * @param array<string, int|float|null> $dau     Daily active users, all sources.
     */
    public function build(?array $ledger, array $bySource, array $revenue, array $dau): array
    {
        $campaigns = $this->campaigns($ledger);
        $paidImpressions = $this->fold($bySource['impressions'] ?? [], self::PAID_LABELS);
        $paidDau = $this->fold($bySource['dau'] ?? [], self::PAID_LABELS);
        $paidPlays = $this->fold($bySource['plays'] ?? [], self::PAID_LABELS);
        if ($campaigns === [] && $paidDau === []) {
            return [];
        }

        $estimated = [];
        $perCampaign = array_map($this->denoise(...), $this->spendByCampaign($campaigns, $paidImpressions, $estimated));
        $spend = $perCampaign === [] ? [] : $this->denoise(Series::sum(...array_values($perCampaign)));
        $spendByCampaign = $this->labelled($campaigns, $perCampaign);

        $organicDau = $this->organic($dau, $paidDau);
        $paidShare = Series::ratio($paidDau, $dau);
        $attributedRobux = $this->multiply($revenue, $paidShare);
        $attributedUsd = $this->economics->netUsdSeries($attributedRobux);
        $roi = Series::ratio($attributedUsd, $spend);

        $totals = $this->totals($campaigns, $spend, $paidDau, $paidPlays, $attributedUsd);

        return [
            'ledger'     => $ledger === null ? null : [
                'importedAt' => $ledger['importedAt'] ?? null,
                'source'     => $ledger['source'] ?? null,
                'window'     => $ledger['window'] ?? ['from' => null, 'to' => null],
                'currency'   => $ledger['currency'] ?? 'USD',
            ],
            'paidLabels' => self::PAID_LABELS,
            'campaigns'  => $this->campaignRows($campaigns, $perCampaign, $spend, $attributedUsd),
            'windows'    => $this->windows($spend),
            'totals'     => $totals,
            'spend'              => Series::block('usd', ['' => $spend], 2),
            'spendByCampaign'    => Series::block('usd', $spendByCampaign, 2),
            'spendEstimated'     => array_values(array_unique(array_keys($estimated))),
            'buyers'             => Series::block('int', ['' => $paidDau]),
            'paidPlays'          => Series::block('int', ['' => $paidPlays]),
            'dauSplit'           => Series::block('int', ['paid' => $paidDau, 'organic' => $organicDau]),
            'paidShare'          => Series::block('pct', ['' => $paidShare], 5),
            'costPerDauDay'      => Series::block('usd', ['' => Series::ratio($spend, $paidDau)], 6),
            'costPerPlay'        => Series::block('usd', ['' => Series::ratio($spend, $paidPlays)], 5),
            'revenueUsdNet'      => Series::block('usd', ['' => $attributedUsd], 2),
            'roi'                => Series::block('pct', ['' => $roi], 4),
            'cumulative'         => Series::block('usd', [
                'spend'   => Series::cumulative($spend),
                'revenue' => Series::cumulative($attributedUsd),
            ], 2),
            'retentionBySource'  => $this->retention($bySource),
        ];
    }

    /** @return list<array<string, mixed>> Campaigns that spent something and have a start date. */
    private function campaigns(?array $ledger): array
    {
        $out = [];
        foreach ($ledger['campaigns'] ?? [] as $campaign) {
            if (!is_array($campaign) || ($campaign['from'] ?? null) === null) {
                continue;
            }
            if ((float)($campaign['spent'] ?? 0) <= 0) {
                continue;
            }
            $out[] = $campaign;
        }

        return $out;
    }

    /**
     * Every campaign's spend spread over its own days.
     *
     * The export gives two facts per campaign — total spend and total
     * impressions — and the API gives a third, the daily shape of paid
     * impressions. With campaigns overlapping (three ran together in
     * September) a single shared shape would put the same fraction of every
     * budget on every day, which is wrong whenever one campaign started
     * mid-window. So the daily impressions are fitted to both margins at once
     * (iterative proportional fitting): the columns keep the shape the API
     * measured, the rows keep each campaign's own impression total, and the
     * spend follows the impressions each campaign is estimated to have served.
     * Campaigns the export gives no impressions for stay out of the fit and
     * fall back to the plain shape.
     *
     * @param list<array<string, mixed>> $campaigns
     * @param array<string, int|float|null> $paidImpressions
     * @param array<string, true> $estimated Days that fell back to an even spread.
     * @return array<int, array<string, float>> campaign index => date => spend
     */
    private function spendByCampaign(array $campaigns, array $paidImpressions, array &$estimated): array
    {
        $fitted = $this->fit($campaigns, $paidImpressions);
        $out = [];
        foreach ($campaigns as $i => $campaign) {
            $shares = $fitted[$i] ?? null;
            $daily = $shares === null
                ? $this->allocate($campaign, $paidImpressions, $estimated)
                : array_map(static fn (float $share): float => $share * (float)$campaign['spent'], $shares);
            if ($daily !== []) {
                $out[$i] = $daily;
            }
        }

        return $out;
    }

    /**
     * The per-campaign maps under their chart labels. Two flights of the same
     * campaign name would collide, so the label carries the start date and
     * same-day duplicates are summed instead of overwriting each other.
     *
     * @param list<array<string, mixed>> $campaigns
     * @param array<int, array<string, float>> $perCampaign
     * @return array<string, array<string, float>>
     */
    private function labelled(array $campaigns, array $perCampaign): array
    {
        $out = [];
        foreach ($perCampaign as $i => $daily) {
            $label = $this->campaignLabel($campaigns[$i]);
            $out[$label] = isset($out[$label]) ? Series::sum($out[$label], $daily) : $daily;
        }

        return $out;
    }

    /**
     * Iterative proportional fitting of the days x campaigns table, on shares:
     * both margins are normalised to 1, so the impression *counts* never have
     * to agree (the API counts unique users, the export counts impressions —
     * only their shapes are comparable). Rows are scaled last, which makes
     * each campaign's share of its own spend exact even if the fit is stopped
     * early.
     *
     * @param list<array<string, mixed>> $campaigns
     * @param array<string, int|float|null> $paidImpressions
     * @return array<int, array<string, float>> campaign index => date => share of that campaign's spend
     */
    private function fit(array $campaigns, array $paidImpressions): array
    {
        $rows = [];
        foreach ($campaigns as $i => $campaign) {
            $impressions = (float)($campaign['impressions'] ?? 0);
            $days = Series::calendar((string)$campaign['from'], (string)($campaign['to'] ?? $campaign['from']));
            $cells = [];
            foreach ($days as $day) {
                $weight = (float)($paidImpressions[$day] ?? 0);
                if ($weight > 0) {
                    $cells[$day] = $weight;
                }
            }
            if ($impressions > 0 && $cells !== []) {
                $rows[$i] = ['target' => $impressions, 'cells' => $cells];
            }
        }
        if (count($rows) < 2) {
            return [];   // one campaign: the plain shape already satisfies both margins
        }
        // Column targets: the measured daily shape, restricted to days a campaign covers.
        $columns = [];
        foreach ($rows as $row) {
            foreach ($row['cells'] as $day => $weight) {
                $columns[$day] = $weight;
            }
        }
        $matrix = array_map(static fn (array $row): array => $row['cells'], $rows);
        for ($pass = 0; $pass < self::FIT_PASSES; $pass++) {
            $this->scale($matrix, $columns, false);
            $this->scale($matrix, array_map(static fn (array $row): float => $row['target'], $rows), true);
        }
        $out = [];
        foreach ($matrix as $i => $cells) {
            $total = array_sum($cells);
            $out[$i] = $total > 0 ? array_map(static fn (float $v): float => $v / $total, $cells) : [];
        }

        return $out;
    }

    /**
     * One half-step of the fit: rescale every row (or column) so its sum
     * matches its target, both sides read as shares of their own total.
     *
     * @param array<int, array<string, float>> $matrix
     * @param array<int|string, float> $targets
     */
    private function scale(array &$matrix, array $targets, bool $byRow): void
    {
        $grand = array_sum(array_map(static fn (array $cells): float => array_sum($cells), $matrix));
        $targetTotal = array_sum($targets);
        if ($grand <= 0 || $targetTotal <= 0) {
            return;
        }
        $sums = [];
        foreach ($matrix as $i => $cells) {
            foreach ($cells as $day => $value) {
                $key = $byRow ? $i : $day;
                $sums[$key] = ($sums[$key] ?? 0.0) + $value;
            }
        }
        foreach ($matrix as $i => $cells) {
            foreach ($cells as $day => $value) {
                $key = $byRow ? $i : $day;
                $want = ($targets[$key] ?? 0.0) / $targetTotal * $grand;
                $have = $sums[$key] ?? 0.0;
                $matrix[$i][$day] = $have > 0 ? $value * ($want / $have) : 0.0;
            }
        }
    }

    /**
     * A campaign's spend spread over its days, weighted by that day's paid
     * impressions. `$estimated` collects the days that had to fall back to an
     * even spread, so the chart can say which ones are not impression-shaped.
     *
     * @param array<string, int|float|null> $paidImpressions
     * @param array<string, true> $estimated
     * @return array<string, float>
     */
    private function allocate(array $campaign, array $paidImpressions, array &$estimated): array
    {
        $from = (string)$campaign['from'];
        $to = (string)($campaign['to'] ?? $campaign['from']);
        $days = Series::calendar($from, $to);
        if ($days === []) {
            return [];
        }
        $spent = (float)$campaign['spent'];
        $weights = [];
        $total = 0.0;
        foreach ($days as $day) {
            $w = $paidImpressions[$day] ?? null;
            $w = $w === null ? 0.0 : max(0.0, (float)$w);
            $weights[$day] = $w;
            $total += $w;
        }
        $out = [];
        if ($total <= 0) {
            $even = $spent / count($days);
            foreach ($days as $day) {
                $out[$day] = $even;
                $estimated[$day] = true;
            }

            return $out;
        }
        foreach ($days as $day) {
            $out[$day] = $spent * ($weights[$day] / $total);
        }

        return $out;
    }

    /** Campaign names repeat across runs; the start date keeps the series apart. */
    private function campaignLabel(array $campaign): string
    {
        $name = trim((string)($campaign['name'] ?? '')) ?: 'campagna';

        return $name . ' · ' . $this->shortDate((string)$campaign['from']);
    }

    private function shortDate(string $iso): string
    {
        return substr($iso, 8, 2) . '/' . substr($iso, 5, 2);
    }

    /**
     * One row per campaign, with the revenue its own days earned.
     *
     * A day's attributed revenue is split between the campaigns running that
     * day in proportion to what each spent on it, so three overlapping flights
     * never each claim the whole day. The dates shown are the ones the money
     * actually landed on: a campaign left open in Ads Manager would otherwise
     * claim a window running to the end of the export.
     *
     * @param list<array<string, mixed>> $campaigns
     * @param array<int, array<string, float>> $perCampaign
     * @param array<string, int|float|null> $spend         Daily total, every campaign together.
     * @param array<string, int|float|null> $attributedUsd
     * @return list<array<string, mixed>>
     */
    private function campaignRows(array $campaigns, array $perCampaign, array $spend, array $attributedUsd): array
    {
        $rows = [];
        foreach ($campaigns as $i => $campaign) {
            $daily = $perCampaign[$i] ?? [];
            $days = array_keys(Series::sorted($daily));
            $allocated = array_sum($daily);
            $revenue = null;
            foreach ($days as $day) {
                $dayTotal = (float)($spend[$day] ?? 0);
                $dayRevenue = $attributedUsd[$day] ?? null;
                if ($dayTotal <= 0 || $dayRevenue === null) {
                    continue;
                }
                $revenue = ($revenue ?? 0.0) + (float)$dayRevenue * ($daily[$day] / $dayTotal);
            }
            $spent = (float)$campaign['spent'];
            $impressions = $campaign['impressions'] ?? null;
            $clicks = $campaign['clicks'] ?? null;
            $plays = $campaign['plays'] ?? null;
            $rows[] = [
                'id'          => $campaign['id'] ?? null,
                'name'        => $campaign['name'] ?? null,
                'objective'   => $campaign['objective'] ?? null,
                'from'        => $days === [] ? (string)$campaign['from'] : $days[0],
                'to'          => $days === [] ? (string)($campaign['to'] ?? $campaign['from']) : end($days),
                'declaredTo'  => (string)($campaign['to'] ?? $campaign['from']),
                'days'        => count($days),
                'running'     => (bool)($campaign['running'] ?? false),
                'budget'      => $campaign['budget'] ?? null,
                'spent'       => round($spent, 2),
                'allocated'   => round($allocated, 2),
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'plays'       => $plays,
                'ctr'         => $this->rate($clicks, $impressions),
                'playRate'    => $this->rate($plays, $clicks),
                'cpm'         => $impressions ? round($spent / $impressions * 1000, 4) : null,
                'cpc'         => $clicks ? round($spent / $clicks, 4) : null,
                'cpp'         => $plays ? round($spent / $plays, 4) : null,
                'revenueUsdNet' => $revenue === null ? null : round($revenue, 2),
                'roi'         => ($revenue === null || $allocated <= 0) ? null : round($revenue / $allocated, 4),
            ];
        }
        usort($rows, static fn (array $a, array $b) => [$a['from'], $a['name']] <=> [$b['from'], $b['name']]);

        return $rows;
    }

    /**
     * The runs of days money was actually spent, as bands for the charts.
     * Declared campaign dates would not do: a campaign nobody closed in Ads
     * Manager stays open for ever and would shade the ten-day pause between
     * two flights as if the ads had never stopped.
     *
     * @param array<string, float> $spend
     * @return list<array{from: string, to: string, label: string}>
     */
    private function windows(array $spend): array
    {
        $days = array_keys(Series::sorted(array_filter($spend, static fn ($v): bool => $v !== null && $v > 0)));
        $out = [];
        foreach ($days as $day) {
            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && $day <= Series::shift($out[$last]['to'], 1)) {
                $out[$last]['to'] = $day;
                continue;
            }
            $out[] = ['from' => $day, 'to' => $day, 'label' => 'ads attive'];
        }

        return $out;
    }

    /**
     * Spend below half a cent is not spend: dropping it keeps $ 0,00 bars off
     * the chart and keeps the cost and ROI ratios from dividing by a crumb.
     *
     * @param array<string, float> $spend
     * @return array<string, float>
     */
    private function denoise(array $spend): array
    {
        return array_filter($spend, static fn ($v): bool => $v !== null && $v >= self::SPEND_FLOOR);
    }

    /**
     * @param list<array<string, mixed>> $campaigns
     * @param array<string, int|float|null> $spend
     */
    private function totals(array $campaigns, array $spend, array $paidDau, array $paidPlays, array $attributedUsd): array
    {
        $spent = 0.0;
        $impressions = 0;
        $clicks = 0;
        $plays = 0;
        foreach ($campaigns as $campaign) {
            $spent += (float)$campaign['spent'];
            $impressions += (int)($campaign['impressions'] ?? 0);
            $clicks += (int)($campaign['clicks'] ?? 0);
            $plays += (int)($campaign['plays'] ?? 0);
        }
        // Only the days the money actually covers: paid DAU keeps accruing long
        // after a campaign ends (Roblox credits returning players to their
        // original source), and counting those days would flatter the cost.
        $spendDays = array_keys(array_filter($spend, static fn ($v) => $v !== null && $v > 0));
        $buyers = $this->sumOver($paidDau, $spendDays) ?? 0.0;
        $playsOnSpendDays = $this->sumOver($paidPlays, $spendDays) ?? 0.0;
        $revenue = $this->sumOver($attributedUsd, $spendDays);
        $revenueAll = array_sum(array_map(static fn ($v) => (float)($v ?? 0), $attributedUsd));

        return [
            'spentUsd'        => round($spent, 2),
            'impressions'     => $impressions ?: null,
            'clicks'          => $clicks ?: null,
            'plays'           => $plays ?: null,
            'campaigns'       => count($campaigns),
            'spendDays'       => count($spendDays),
            'buyersDauDays'   => $buyers > 0 ? (int)round($buyers) : null,
            'paidPlays'       => $playsOnSpendDays > 0 ? (int)round($playsOnSpendDays) : null,
            'costPerDauDay'   => $buyers > 0 ? round($spent / $buyers, 6) : null,
            'costPerPlay'     => $playsOnSpendDays > 0 ? round($spent / $playsOnSpendDays, 5) : null,
            'revenueUsdNet'   => $revenue === null ? null : round($revenue, 2),
            'revenueUsdNetAll'=> round($revenueAll, 2),
            'roi'             => ($revenue === null || $spent <= 0) ? null : round($revenue / $spent, 4),
        ];
    }

    /** D1 and D7 for the paid sources against the organic ones: is bought traffic as good? */
    private function retention(array $bySource): array
    {
        $out = [];
        foreach (['d1' => 'D1', 'd7' => 'D7'] as $key => $name) {
            $maps = $bySource[$key] ?? [];
            if ($maps === []) {
                continue;
            }
            $paid = $this->mean($maps, self::PAID_LABELS, true);
            $organic = $this->mean($maps, self::PAID_LABELS, false);
            if ($paid === [] && $organic === []) {
                continue;
            }
            $out[$key] = Series::block('pct', ['paid' => $paid, 'organic' => $organic], 5) + ['name' => $name];
        }

        return $out;
    }

    /**
     * Unweighted mean over the labels on the wanted side of the paid/organic
     * split. A rate cannot be summed, and per-source DAU weights would need a
     * second pair fetched on the same days; the D1/D7 sources sit close enough
     * together that the plain mean tells the same story.
     *
     * An exact zero is dropped, not averaged: retention is measured on the
     * cohort that arrived that day, and on a day nobody new arrived from a
     * paid source Roblox answers 0. Kept, it would draw ten days of "the
     * bought players never come back" over a stretch with no bought players.
     *
     * @param array<string, array<string, int|float|null>> $maps
     * @param list<string> $labels
     */
    private function mean(array $maps, array $labels, bool $keep): array
    {
        $selected = array_filter(
            $maps,
            static fn (string $label) => in_array($label, $labels, true) === $keep && $label !== '',
            ARRAY_FILTER_USE_KEY,
        );
        $selected = array_map(
            static fn (array $map): array => array_filter($map, static fn ($v): bool => $v !== null && (float)$v > 0),
            $selected,
        );

        return $selected === [] ? [] : Series::aggregateLabels($selected, false);
    }

    /**
     * @param array<string, array<string, int|float|null>> $maps label => date => value
     * @param list<string> $labels
     * @return array<string, int|float> The labels summed day by day.
     */
    private function fold(array $maps, array $labels): array
    {
        $selected = array_filter($maps, static fn (string $label) => in_array($label, $labels, true), ARRAY_FILTER_USE_KEY);

        return $selected === [] ? [] : Series::aggregateLabels($selected, true);
    }

    /** Total minus paid, floored at zero: the two series are measured separately. */
    private function organic(array $dau, array $paidDau): array
    {
        $out = [];
        foreach ($dau as $date => $value) {
            if ($value === null) {
                continue;
            }
            // Both sides are head counts: keep the difference one too.
            $out[(string)$date] = (int)round(max(0, $value - (float)($paidDau[$date] ?? 0)));
        }

        return $out;
    }

    /** @return array<string, float> Elementwise product, only where both sides have a value. */
    private function multiply(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $date => $value) {
            $other = $b[$date] ?? null;
            if ($value === null || $other === null) {
                continue;
            }
            $out[(string)$date] = (float)$value * (float)$other;
        }

        return $out;
    }

    /** @param list<string> $dates */
    private function sumOver(array $map, array $dates): ?float
    {
        $sum = null;
        foreach ($dates as $date) {
            $value = $map[$date] ?? null;
            if ($value !== null) {
                $sum = ($sum ?? 0.0) + (float)$value;
            }
        }

        return $sum;
    }

    private function rate(?int $numerator, ?int $denominator): ?float
    {
        return ($numerator === null || !$denominator) ? null : round($numerator / $denominator, 5);
    }
}
