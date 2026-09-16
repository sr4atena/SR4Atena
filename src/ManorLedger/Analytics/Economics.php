<?php
/**
 * From Robux to dollars, and from dollars to a price tag.
 *
 * Two deliberate choices, both verified on the legacy snapshots:
 * - the 7-day window uses the MEAN, not the median. Seven consecutive days
 *   hold exactly one of each weekday, so the mean is already neutral to
 *   seasonality; on a game that doubles at the weekend the median always
 *   lands on a weekday and understates by a third.
 * - the window is made of consolidated days only: the caller passes
 *   `$through`, the last non-provisional day, and everything after it is
 *   ignored here.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class Economics
{
    private const WINDOW = 7;
    private const DAYS_PER_MONTH = 30;

    /**
     * @param array{conservative: int|float, base: int|float} $multiples
     * @param list<float> $plateauShares Fractions of peak DAU for the plateau scale.
     */
    public function __construct(
        private readonly float $devexUsdPerRobux,
        private readonly float $royaltyShare,
        private readonly array $multiples,
        private readonly array $plateauShares,
    ) {
    }

    public function grossUsd(float $robux): float
    {
        return $robux * $this->devexUsdPerRobux;
    }

    public function netUsd(float $robux): float
    {
        return $robux * $this->devexUsdPerRobux * (1 - $this->royaltyShare);
    }

    /** Net dollars per month implied by a daily Robux run rate. */
    public function monthlyNetUsd(float $dailyRobux): float
    {
        return $this->netUsd($dailyRobux) * self::DAYS_PER_MONTH;
    }

    public function grossUsdSeries(array $robux): array
    {
        return array_map(fn ($v) => $v === null ? null : $this->grossUsd((float)$v), $robux);
    }

    public function netUsdSeries(array $robux): array
    {
        return array_map(fn ($v) => $v === null ? null : $this->netUsd((float)$v), $robux);
    }

    /**
     * Daily valuation for each multiple: mean7(revenue) x 30 x devex x keep x multiple.
     *
     * @return array<string, array<string, float|null>> keyed by multiple name.
     */
    public function valuationSeries(array $revenue): array
    {
        $mean7 = Series::rollingMean($revenue, self::WINDOW);
        $out = [];
        foreach ($this->multiples as $name => $multiple) {
            $out[$name] = array_map(
                fn ($m) => $m === null ? null : $this->monthlyNetUsd((float)$m) * $multiple,
                $mean7,
            );
        }

        return $out;
    }

    /**
     * The scale a buyer reads: DAU settles at a fraction of its historical
     * peak, monetised at today's ARPDAU, priced at the conservative multiple.
     *
     * @return list<array{share: float, dau: float, monthlyUsdNet: float, valuationUsd: float}>
     */
    public function plateauScale(float $peakDau, float $arpdau7): array
    {
        $rows = [];
        foreach ($this->plateauShares as $share) {
            $dau = $peakDau * $share;
            $monthly = $this->monthlyNetUsd($dau * $arpdau7);
            $rows[] = [
                'share'         => $share,
                'dau'           => (int)round($dau),
                'monthlyUsdNet' => round($monthly, 2),
                'valuationUsd'  => round($monthly * $this->multiples['conservative'], 2),
            ];
        }

        return $rows;
    }

    /**
     * Headline numbers: current 7-day window ending at $through versus the
     * seven days before it. Every input is a date -> value map; missing
     * inputs may be empty arrays.
     *
     * @param array<string, array<string, int|float|null>> $s Keys: revenue, dau,
     *        mau, payingUsers, cvr, d1, d7, stickiness.
     */
    public function kpis(array $s, string $through): array
    {
        $revenue = Series::compact($s['revenue'] ?? []);
        $dau     = Series::compact($s['dau'] ?? []);
        $arpdau  = Series::ratio($revenue, $dau);

        $rev7 = $this->windowMean($revenue, $through, 0);
        $rev7Prev = $this->windowMean($revenue, $through, 1);
        $monthly = $rev7 === null ? null : $this->monthlyNetUsd($rev7);

        $cumulative = 0.0;
        foreach ($revenue as $date => $v) {
            if (strcmp($date, $through) <= 0) {
                $cumulative += $v;
            }
        }

        $stickiness = Series::compact($s['stickiness'] ?? []);
        if ($stickiness === [] && isset($s['mau'])) {
            $stickiness = Series::compact(Series::ratio($dau, $s['mau']));
        }

        return [
            'revenue7dRobux'           => $this->kpi($rev7, $rev7Prev, 2, 'vs 7 giorni prima'),
            'revenue7dUsdNet'          => $this->kpi($rev7 === null ? null : $this->netUsd($rev7), $rev7Prev === null ? null : $this->netUsd($rev7Prev), 2, 'vs 7 giorni prima'),
            'monthlyRunRateUsdNet'     => ['value' => $monthly === null ? null : round($monthly, 2)],
            'valuationBaseUsd'         => ['value' => $monthly === null ? null : round($monthly * $this->multiples['base'], 2)],
            'valuationConservativeUsd' => ['value' => $monthly === null ? null : round($monthly * $this->multiples['conservative'], 2)],
            'cumulativeUsdNet'         => ['value' => round($this->netUsd($cumulative), 2)],
            'dau7'                     => $this->windowKpi($dau, $through, 0),
            'arpdau7Robux'             => $this->windowKpi($arpdau, $through, 4),
            'payingCvr7'               => $this->windowKpi(Series::compact($s['cvr'] ?? []), $through, 4),
            'd1Retention'              => $this->latestKpi(Series::compact($s['d1'] ?? []), $through),
            'd7Retention'              => $this->latestKpi(Series::compact($s['d7'] ?? []), $through),
            'stickiness7'              => $this->windowKpi($stickiness, $through, 4),
        ];
    }

    /** Mean over the 7 calendar days ending $windowsBack windows before $through; null when empty. */
    public function windowMean(array $s, string $through, int $windowsBack): ?float
    {
        $end = Series::shift($through, -self::WINDOW * $windowsBack);
        $values = [];
        for ($k = 0; $k < self::WINDOW; $k++) {
            $v = $s[Series::shift($end, -$k)] ?? null;
            if ($v !== null) {
                $values[] = $v;
            }
        }

        return Series::mean($values);
    }

    private function windowKpi(array $s, string $through, int $decimals): array
    {
        return $this->kpi($this->windowMean($s, $through, 0), $this->windowMean($s, $through, 1), $decimals, 'vs 7 giorni prima');
    }

    /** Retention cohorts lag by a week: report the freshest value and its date, not a window. */
    private function latestKpi(array $s, string $through): array
    {
        $latest = null;
        $date = null;
        foreach ($s as $d => $v) {
            if (strcmp($d, $through) <= 0) {
                $latest = $v;
                $date = $d;
            }
        }

        return ['value' => $latest === null ? null : round((float)$latest, 4), 'date' => $date];
    }

    private function kpi(?float $current, ?float $previous, int $decimals, string $label): array
    {
        $delta = ($current === null || $previous === null || $previous == 0.0) ? null : round($current / $previous - 1, 4);

        return [
            'value'      => $current === null ? null : ($decimals === 0 ? (int)round($current) : round($current, $decimals)),
            'delta'      => $delta,
            'deltaLabel' => $label,
        ];
    }
}
