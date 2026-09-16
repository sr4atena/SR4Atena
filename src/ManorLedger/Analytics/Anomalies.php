<?php
/**
 * Robust anomaly detection on the last complete day of a few watched metrics.
 *
 * The baseline for a day is the mean of the same weekday over the previous
 * four weeks (weekend doubling is normal here, not an anomaly); when history
 * is too short it falls back to the median of the previous 14 days. The
 * residual of the last day is scored against the spread of the residuals of
 * the previous days, using MAD x 1.4826 (the robust sigma), so a single past
 * spike does not inflate the threshold and hide the next one.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class Anomalies
{
    /** metricId => [Italian short name, direction that is bad news, per-label mode]. */
    public const CANDIDATES = [
        'ClientCrashRate15m'          => ['name' => 'Crash rate client', 'bad' => 'up'],
        'ClientCrashCount'            => ['name' => 'Crash client', 'bad' => 'up'],
        'ServerCrashCount'            => ['name' => 'Crash server', 'bad' => 'up'],
        'OomUnexpectedExits'          => ['name' => 'Uscite per memoria esaurita (OOM)', 'bad' => 'up'],
        'ClientFpsAvg'                => ['name' => 'FPS medi client', 'bad' => 'down'],
        'ServerFrameRateAvg'          => ['name' => 'Frame rate server', 'bad' => 'down'],
        'DataStoreRequestsByStatus'   => ['name' => 'Richieste DataStore', 'bad' => 'up', 'perLabel' => true, 'skipLabels' => ['Ok']],
        'AverageSessionLengthMinutes' => ['name' => 'Durata media sessione', 'bad' => 'down'],
        'ForwardD1Retention'          => ['name' => 'Retention D1', 'bad' => 'down'],
        'PayingUsersCVR'              => ['name' => 'Conversione a pagante', 'bad' => 'down'],
        'DailyActiveUsers'            => ['name' => 'Utenti attivi giornalieri', 'bad' => 'down'],
        'ItemMonetizationRevenue'     => ['name' => 'Ricavi da vendite', 'bad' => 'down'],
    ];

    private const ROBUST_SIGMA = 1.4826;
    private const LOOKBACK_DAYS = 28;
    private const WEEKS_BASELINE = 4;
    private const MIN_RESIDUALS = 5;

    public function __construct(
        private readonly array $candidates = self::CANDIDATES,
        private readonly int $minDays = 10,
        private readonly float $mediumZ = 3.0,
        private readonly float $highZ = 4.5,
    ) {
    }

    /**
     * @param array<string, array{unit: string, series: array<string, array<string, int|float|null>>}> $metrics
     *        History entries keyed by metric id.
     * @param string|null $excludeDate Day still provisional in the source: dropped from every series.
     * @return list<array<string, mixed>> Flagged anomalies, most severe first.
     */
    public function detect(array $metrics, ?string $excludeDate = null): array
    {
        $found = [];
        foreach ($this->candidates as $id => $spec) {
            $metric = $metrics[$id] ?? null;
            if ($metric === null) {
                continue;
            }
            foreach ($this->seriesFor($metric, $spec) as $label => $series) {
                if ($excludeDate !== null) {
                    unset($series[$excludeDate]);
                }
                $name = $spec['name'] . ($label === '' ? '' : ' ' . $label);
                $hit = $this->analyse(Series::compact($series), $name, $spec['bad']);
                if ($hit !== null) {
                    $found[] = ['metric' => $id, 'label' => $label] + $hit;
                }
            }
        }
        usort($found, static fn (array $a, array $b) => [$b['severity'] === 'high', abs($b['zScore'])] <=> [$a['severity'] === 'high', abs($a['zScore'])]);

        return $found;
    }

    /** Scores the last day of a compact series; null when nothing is flagged or history is too short. */
    public function analyse(array $s, string $name, string $badDirection): ?array
    {
        if (count($s) < $this->minDays) {
            return null;
        }
        $last = array_key_last($s);
        $expected = $this->expected($s, $last);
        if ($expected === null) {
            return null;
        }
        $residuals = [];
        foreach ($s as $date => $v) {
            if (strcmp($date, $last) >= 0 || strcmp($date, Series::shift($last, -self::LOOKBACK_DAYS)) < 0) {
                continue;
            }
            $e = $this->expected($s, $date);
            if ($e !== null) {
                $residuals[] = $v - $e['value'];
            }
        }
        if (count($residuals) < self::MIN_RESIDUALS) {
            return null;
        }
        $sigma = self::ROBUST_SIGMA * (Series::mad($residuals) ?? 0.0);
        if ($sigma == 0.0) {
            // Flat residuals (e.g. a counter stuck at zero): fall back to the mean absolute deviation.
            $center = Series::median($residuals);
            $sigma = 1.2533 * (Series::mean(array_map(static fn ($r) => abs($r - $center), $residuals)) ?? 0.0);
        }
        if ($sigma == 0.0) {
            return null;
        }
        $residual = $s[$last] - $expected['value'];
        $z = ($residual - Series::median($residuals)) / $sigma;
        if (abs($z) < $this->mediumZ) {
            return null;
        }
        $direction = $residual >= 0 ? 'up' : 'down';

        return [
            'name'      => $name,
            'date'      => $last,
            'value'     => self::tidy($s[$last]),
            'expected'  => self::tidy($expected['value']),
            'zScore'    => round($z, 2),
            'direction' => $direction,
            'severity'  => abs($z) >= $this->highZ ? 'high' : 'medium',
            'bad'       => $direction === $badDirection,
            'method'    => $expected['method'],
            'message'   => $this->message($name, (float)$s[$last], $expected['value'], $direction, $expected['method']),
        ];
    }

    /** @return null|array{value: float, method: string} */
    private function expected(array $s, string $date): ?array
    {
        $sameWeekday = [];
        for ($w = 1; $w <= self::WEEKS_BASELINE; $w++) {
            $v = $s[Series::shift($date, -7 * $w)] ?? null;
            if ($v !== null) {
                $sameWeekday[] = $v;
            }
        }
        if (count($sameWeekday) >= 3) {
            return ['value' => (float)Series::mean($sameWeekday), 'method' => 'weekday'];
        }
        $recent = [];
        for ($k = 1; $k <= 14; $k++) {
            $v = $s[Series::shift($date, -$k)] ?? null;
            if ($v !== null) {
                $recent[] = $v;
            }
        }
        if (count($recent) >= self::MIN_RESIDUALS) {
            return ['value' => (float)Series::median($recent), 'method' => 'median14'];
        }

        return null;
    }

    /**
     * The "" aggregate, or every non-skipped label in per-label mode. A metric
     * that only carries a breakdown must be aggregated by the caller first
     * (DashboardBuilder does, weighting rates by platform DAU).
     *
     * @return array<string, array<string, int|float|null>> label => series to analyse.
     */
    private function seriesFor(array $metric, array $spec): array
    {
        $series = $metric['series'] ?? [];
        if ($spec['perLabel'] ?? false) {
            $skip = $spec['skipLabels'] ?? [];

            return array_filter($series, static fn ($label) => $label !== '' && !in_array($label, $skip, true), ARRAY_FILTER_USE_KEY);
        }

        return isset($series['']) ? ['' => $series['']] : [];
    }

    private function message(string $name, float $value, float $expected, string $direction, string $method): string
    {
        $baseline = $method === 'weekday'
            ? 'la baseline dello stesso giorno della settimana'
            : 'la mediana dei 14 giorni precedenti';
        if ($expected == 0.0) {
            return sprintf('%s a %s contro una baseline di zero', $name, self::it($value, 2));
        }
        if ($direction === 'up') {
            return sprintf('%s %sx sopra %s', $name, self::it($value / $expected, 1), $baseline);
        }

        return sprintf('%s al %s%% del%s', $name, self::it($value / $expected * 100, 0), $baseline);
    }

    private static function it(float $v, int $decimals): string
    {
        return number_format($v, $decimals, ',', '.');
    }

    private static function tidy(int|float $v): int|float
    {
        return is_int($v) ? $v : round($v, 6);
    }
}
