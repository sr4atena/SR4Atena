<?php
/**
 * Weekday index: how much a given weekday runs above or below the weekly
 * mean. 1.0 is neutral; 1.4 on Saturday means Saturdays earn 40% more than
 * an average day. It is measured on whole weeks only so every weekday is
 * represented the same number of times.
 */
declare(strict_types=1);

namespace ManorLedger\Analytics;

final class Seasonality
{
    public const WEEKDAYS = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];

    public function __construct(private readonly int $weeks = 4)
    {
    }

    /**
     * @return array{weekdays: list<string>, revenueIndex: list<float|null>, dauIndex: list<float|null>, weeks: int, through: string}
     */
    public function build(array $revenue, array $dau, string $through): array
    {
        $weeks = min($this->weeks, $this->completeWeeks($revenue, $through), $this->completeWeeks($dau, $through));

        return [
            'weekdays'     => self::WEEKDAYS,
            'revenueIndex' => $this->index($revenue, $through, $weeks),
            'dauIndex'     => $this->index($dau, $through, $weeks),
            'weeks'        => $weeks,
            'through'      => $through,
        ];
    }

    /**
     * Mean per weekday over the $weeks x 7 days ending at $through, divided by
     * the overall mean of that window. Null entries where a weekday never
     * had a value.
     *
     * @return list<float|null> Monday first.
     */
    public function index(array $s, string $through, int $weeks): array
    {
        if ($weeks < 1) {
            return array_fill(0, 7, null);
        }
        $byWeekday = array_fill(1, 7, []);
        $all = [];
        for ($k = 0; $k < $weeks * 7; $k++) {
            $date = Series::shift($through, -$k);
            $v = $s[$date] ?? null;
            if ($v === null) {
                continue;
            }
            $byWeekday[Series::weekday($date)][] = $v;
            $all[] = $v;
        }
        $overall = Series::mean($all);
        $out = [];
        for ($wd = 1; $wd <= 7; $wd++) {
            $mean = Series::mean($byWeekday[$wd]);
            $out[] = ($mean === null || $overall === null || $overall == 0.0) ? null : round($mean / $overall, 4);
        }

        return $out;
    }

    /** How many whole 7-day blocks ending at $through are fully populated. */
    private function completeWeeks(array $s, string $through): int
    {
        $weeks = 0;
        while ($weeks < $this->weeks) {
            for ($k = 0; $k < 7; $k++) {
                if (($s[Series::shift($through, -($weeks * 7 + $k))] ?? null) === null) {
                    return $weeks;
                }
            }
            $weeks++;
        }

        return $weeks;
    }
}
