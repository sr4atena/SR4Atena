<?php
/**
 * In-session survival: how much of a day's sessions is still playing after N
 * seconds. Roblox publishes TotalSessionsEndedInBucket with the
 * SessionTimeBucket breakdown, whose labels are seconds ("0", "30", ...,
 * "1800") and whose value at bucket B counts the sessions still alive at B.
 * The series is monotonically decreasing, so it already is a survival curve:
 * survival(B) = value(B) / value("0").
 *
 * A window pools the days it covers (sum of bucket B over the window divided
 * by the sum of its 0-second bucket) rather than averaging the daily ratios.
 * That way the curve of a window is the curve of the sessions it actually
 * contains, and a quiet Tuesday does not weigh as much as a busy Sunday.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class SessionSurvival
{
    /** Thresholds followed over time: seconds => Italian label. */
    public const MILESTONES = [60 => '1 minuto', 300 => '5 minuti', 600 => '10 minuti', 1800 => '30 minuti'];

    private const BASE_BUCKET = '0';
    private const HALF = 0.5;

    /** @param int $minDays Usable days a window needs before it is published at all. */
    public function __construct(
        private readonly int $windowDays = 7,
        private readonly int $minDays = 3,
    ) {
    }

    /**
     * @param array|null  $metric      History entry {unit, series} of TotalSessionsEndedInBucket.
     * @param string|null $dataThrough Last complete day; later days are ignored.
     *
     * @return array|null Null when the metric is absent or carries no usable day.
     */
    public function build(?array $metric, ?string $dataThrough): ?array
    {
        $series  = $metric['series'] ?? [];
        $buckets = $this->buckets($series);
        if ($dataThrough === null || $buckets === []) {
            return null;
        }
        $days = $this->usableDays($series, $dataThrough);
        if ($days === []) {
            return null;
        }
        $currentDays  = $this->windowSlice($days, $dataThrough, 0);
        $previousDays = $this->windowSlice($days, $dataThrough, $this->windowDays);
        $current = $this->curve($series, $buckets, $currentDays);
        $base = $series[self::BASE_BUCKET];

        return [
            'bucketsSeconds' => $buckets,
            'current'        => $current,
            'previous'       => $this->curve($series, $buckets, $previousDays),
            'sessionsPerDay' => $current === null ? null : round(array_sum(array_map(static fn (string $d): float => (float)$base[$d], $currentDays)) / count($currentDays), 2),
            'milestones'     => $this->milestones($series, $days, $currentDays, $previousDays),
            'medianSeconds'  => $current === null ? null : $this->median($buckets, $current['values']),
        ];
    }

    /** @return list<int> Bucket labels that parse as seconds, ascending, empty unless the 0 bucket is there. */
    private function buckets(array $series): array
    {
        if (!isset($series[self::BASE_BUCKET])) {
            return [];
        }
        $seconds = [];
        foreach (array_keys($series) as $label) {
            if (preg_match('/^\d+$/', (string)$label) === 1) {
                $seconds[] = (int)$label;
            }
        }
        sort($seconds);

        return $seconds;
    }

    /**
     * Days up to $dataThrough whose 0-second bucket is present and non-zero:
     * anything else would divide by a missing or empty base.
     *
     * @return list<string> Ascending ISO dates.
     */
    private function usableDays(array $series, string $dataThrough): array
    {
        $days = [];
        foreach (Series::sorted($series[self::BASE_BUCKET]) as $date => $value) {
            if ($date <= $dataThrough && $value !== null && (float)$value > 0.0) {
                $days[] = (string)$date;
            }
        }

        return $days;
    }

    /**
     * The usable days of the window ending $offset days before $dataThrough.
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    private function windowSlice(array $days, string $dataThrough, int $offset): array
    {
        $to   = Series::shift($dataThrough, -$offset);
        $from = Series::shift($to, -($this->windowDays - 1));

        return array_values(array_filter($days, static fn (string $d): bool => $d >= $from && $d <= $to));
    }

    /** @return array{from: string, to: string, days: int, values: list<float|null>}|null */
    private function curve(array $series, array $buckets, array $days): ?array
    {
        if (count($days) < $this->minDays) {
            return null;
        }

        return [
            'from'   => $days[0],
            'to'     => $days[count($days) - 1],
            'days'   => count($days),
            'values' => array_map(fn (int $b): ?float => $this->pooled($series, $b, $days), $buckets),
        ];
    }

    /**
     * Survival at one bucket over several days, pooled. Days where the bucket
     * itself is missing drop out of both sides of the ratio.
     */
    private function pooled(array $series, int $bucket, array $days): ?float
    {
        $alive = 0.0;
        $started = 0.0;
        foreach ($days as $date) {
            $value = $series[(string)$bucket][$date] ?? null;
            if ($value === null) {
                continue;
            }
            $alive   += (float)$value;
            $started += (float)$series[self::BASE_BUCKET][$date];
        }

        return $started > 0.0 ? round($alive / $started, 4) : null;
    }

    /**
     * @param list<string> $days All usable days, for the per-threshold time series.
     *
     * @return list<array{seconds: int, label: string, current: float|null, previous: float|null, delta: float|null, dates: list<string>, values: list<float|null>}>
     */
    private function milestones(array $series, array $days, array $currentDays, array $previousDays): array
    {
        $out = [];
        foreach (self::MILESTONES as $seconds => $label) {
            if (!isset($series[(string)$seconds])) {
                continue;
            }
            $current  = count($currentDays) < $this->minDays ? null : $this->pooled($series, $seconds, $currentDays);
            $previous = count($previousDays) < $this->minDays ? null : $this->pooled($series, $seconds, $previousDays);
            $out[] = [
                'seconds'  => $seconds,
                'label'    => $label,
                'current'  => $current,
                'previous' => $previous,
                'delta'    => ($current === null || $previous === null) ? null : round($current - $previous, 4),
                'dates'    => $days,
                'values'   => array_map(fn (string $d): ?float => $this->pooled($series, $seconds, [$d]), $days),
            ];
        }

        return $out;
    }

    /**
     * Seconds at which the curve crosses one half, interpolated linearly
     * between the two buckets that straddle it. Null when it never does.
     *
     * @param list<int>        $buckets
     * @param list<float|null> $values
     */
    private function median(array $buckets, array $values): ?float
    {
        $previousBucket = null;
        $previousValue  = null;
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            if ($previousValue !== null && $previousValue >= self::HALF && $value < self::HALF) {
                $span = ($previousValue - self::HALF) / ($previousValue - $value);

                return round($previousBucket + $span * ($buckets[$i] - $previousBucket), 1);
            }
            $previousBucket = $buckets[$i];
            $previousValue  = $value;
        }

        return null;
    }
}
