<?php
/**
 * Pure functions over daily series: array<string ISO date, int|float|null>.
 *
 * Windows are calendar windows, not index windows: a day missing from the
 * map counts as missing, so a 7-day mean over a series with a hole is null
 * instead of silently spanning eight calendar days.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class Series
{
    /** Units whose per-label values add up to the aggregate (others are averaged). */
    public const ADDITIVE_UNITS = ['int', 'robux', 'usd', 'hours', 'bytes'];

    private const DAY = 86400;

    private function __construct()
    {
    }

    /** Sorted copy of the map, keys as strings. */
    public static function sorted(array $s): array
    {
        ksort($s, SORT_STRING);
        $out = [];
        foreach ($s as $date => $value) {
            $out[(string)$date] = $value;
        }

        return $out;
    }

    /** @return list<string> Every calendar day from $from to $to inclusive. */
    public static function calendar(string $from, string $to): array
    {
        $dates = [];
        for ($t = self::ts($from), $end = self::ts($to); $t <= $end; $t += self::DAY) {
            $dates[] = gmdate('Y-m-d', $t);
        }

        return $dates;
    }

    public static function shift(string $date, int $days): string
    {
        return gmdate('Y-m-d', self::ts($date) + $days * self::DAY);
    }

    /** ISO weekday 1 (Monday) .. 7 (Sunday). */
    public static function weekday(string $date): int
    {
        return (int)gmdate('N', self::ts($date));
    }

    /** Mean of the trailing $window calendar days; null unless all of them are present. */
    public static function rollingMean(array $s, int $window): array
    {
        $s = self::sorted($s);
        $out = [];
        foreach ($s as $date => $_) {
            $sum = 0.0;
            $complete = true;
            for ($k = 0; $k < $window; $k++) {
                $v = $s[self::shift($date, -$k)] ?? null;
                if ($v === null) {
                    $complete = false;
                    break;
                }
                $sum += $v;
            }
            $out[$date] = $complete ? $sum / $window : null;
        }

        return $out;
    }

    /** Union of dates; days null in every input stay null. */
    public static function sum(array ...$series): array
    {
        $out = [];
        foreach ($series as $s) {
            foreach ($s as $date => $v) {
                if ($v === null) {
                    $out[(string)$date] ??= null;
                    continue;
                }
                $out[(string)$date] = ($out[(string)$date] ?? 0) + $v;
            }
        }

        return self::sorted($out);
    }

    /** Element-wise num/den over the numerator's dates; null on a null or zero denominator. */
    public static function ratio(array $num, array $den): array
    {
        $out = [];
        foreach (self::sorted($num) as $date => $n) {
            $d = $den[$date] ?? null;
            $out[$date] = ($n === null || $d === null || (float)$d === 0.0) ? null : $n / $d;
        }

        return $out;
    }

    /** Running total; a null day is emitted as null but does not reset the total. */
    public static function cumulative(array $s): array
    {
        $out = [];
        $total = 0.0;
        foreach (self::sorted($s) as $date => $v) {
            if ($v === null) {
                $out[$date] = null;
                continue;
            }
            $total += $v;
            $out[$date] = $total;
        }

        return $out;
    }

    /** value(date)/value(date-7) - 1, null when either side is missing or the base is zero. */
    public static function sameWeekdayDelta(array $s, string $date): ?float
    {
        $now  = $s[$date] ?? null;
        $prev = $s[self::shift($date, -7)] ?? null;
        if ($now === null || $prev === null || (float)$prev === 0.0) {
            return null;
        }

        return $now / $prev - 1;
    }

    public static function lastN(array $s, int $n): array
    {
        $s = self::sorted($s);

        return $n <= 0 ? [] : array_slice($s, -$n, null, true);
    }

    /** @return list<string> Sorted union of the dates of every map passed. */
    public static function alignDates(array $maps): array
    {
        $dates = [];
        foreach ($maps as $s) {
            foreach ($s as $date => $_) {
                $dates[(string)$date] = true;
            }
        }
        $list = array_keys($dates);
        sort($list, SORT_STRING);

        return array_map('strval', $list);
    }

    /** @return list<int|float|null> The map read along $dates, null where absent. */
    public static function values(array $s, array $dates): array
    {
        $out = [];
        foreach ($dates as $date) {
            $out[] = $s[$date] ?? null;
        }

        return $out;
    }

    /** Non-null values only. */
    public static function compact(array $s): array
    {
        return array_filter(self::sorted($s), static fn ($v) => $v !== null);
    }

    public static function mean(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    public static function median(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float)$values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Median absolute deviation around the median. */
    public static function mad(array $values): ?float
    {
        $median = self::median($values);
        if ($median === null) {
            return null;
        }

        return self::median(array_map(static fn ($v) => abs($v - $median), array_filter($values, static fn ($v) => $v !== null)));
    }

    /**
     * Folds per-label maps into one aggregate map: a sum for additive units,
     * otherwise a mean weighted by $weights (label => date => weight, e.g.
     * DAU per platform) and unweighted on days without weights.
     *
     * @param array<string, array<string, int|float|null>> $labelMaps
     * @param array<string, array<string, int|float|null>> $weights
     */
    public static function aggregateLabels(array $labelMaps, bool $additive, array $weights = []): array
    {
        $sum = [];
        $count = [];
        $weighted = [];
        $weightSum = [];
        foreach ($labelMaps as $label => $map) {
            foreach ($map as $date => $v) {
                if ($v === null) {
                    continue;
                }
                $date = (string)$date;
                $sum[$date] = ($sum[$date] ?? 0) + $v;
                $count[$date] = ($count[$date] ?? 0) + 1;
                $w = $weights[$label][$date] ?? null;
                if ($w !== null && $w > 0) {
                    $weighted[$date] = ($weighted[$date] ?? 0) + $v * $w;
                    $weightSum[$date] = ($weightSum[$date] ?? 0) + $w;
                }
            }
        }
        $out = [];
        foreach ($sum as $date => $total) {
            if ($additive) {
                $out[$date] = $total;
            } elseif (isset($weightSum[$date])) {
                $out[$date] = $weighted[$date] / $weightSum[$date];
            } else {
                $out[$date] = $total / $count[$date];
            }
        }

        return self::sorted($out);
    }

    /**
     * The {unit, dates, series[]} shape dashboard.json is made of. Dates span
     * the full calendar between the first and the last day, so a gap shows up
     * as an explicit null and never as a skipped tick.
     *
     * @param array<string, array<string, int|float|null>> $labelMaps
     */
    public static function block(string $unit, array $labelMaps, ?int $decimals = null): array
    {
        $all = self::alignDates($labelMaps);
        $dates = $all === [] ? [] : self::calendar($all[0], end($all));
        $series = [];
        foreach ($labelMaps as $label => $map) {
            $values = self::values($map, $dates);
            if ($decimals !== null) {
                $values = array_map(static fn ($v) => $v === null ? null : round((float)$v, $decimals), $values);
            }
            $series[] = ['label' => (string)$label, 'values' => $values];
        }

        return ['unit' => $unit, 'dates' => $dates, 'series' => $series];
    }

    private static function ts(string $date): int
    {
        $t = strtotime($date . ' 00:00:00 UTC');
        if ($t === false) {
            throw new \InvalidArgumentException('Bad ISO date: ' . $date);
        }

        return $t;
    }
}
