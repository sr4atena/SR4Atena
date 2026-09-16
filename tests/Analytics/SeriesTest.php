<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\Series;
use PHPUnit\Framework\TestCase;

final class SeriesTest extends TestCase
{
    public function testRollingMeanIsNullUntilTheCalendarWindowIsComplete(): void
    {
        $s = ['2026-08-01' => 10, '2026-08-02' => 20, '2026-08-03' => 30, '2026-08-04' => 40];
        $m = Series::rollingMean($s, 3);
        self::assertSame([null, null, 20.0, 30.0], array_values($m));
        self::assertSame(array_keys($s), array_keys($m));
    }

    public function testRollingMeanTreatsCalendarGapsAndNullsAsIncomplete(): void
    {
        // 08-03 is missing: 08-04's trailing 3-day window spans it and must be null.
        $gap = ['2026-08-01' => 10, '2026-08-02' => 20, '2026-08-04' => 40, '2026-08-05' => 50, '2026-08-06' => 60];
        self::assertSame([null, null, null, null, 50.0], array_values(Series::rollingMean($gap, 3)));
        $nulls = ['2026-08-01' => 10, '2026-08-02' => null, '2026-08-03' => 30];
        self::assertSame([null, null, null], array_values(Series::rollingMean($nulls, 3)));
    }

    public function testSumUnionsDatesAndKeepsAllNullDaysNull(): void
    {
        $a = ['2026-08-01' => 1, '2026-08-02' => null];
        $b = ['2026-08-02' => null, '2026-08-03' => 3];
        self::assertSame(['2026-08-01' => 1, '2026-08-02' => null, '2026-08-03' => 3], Series::sum($a, $b));
    }

    public function testRatioNullsOnZeroOrMissingDenominator(): void
    {
        $num = ['2026-08-01' => 10, '2026-08-02' => 10, '2026-08-03' => 10, '2026-08-04' => null];
        $den = ['2026-08-01' => 4, '2026-08-02' => 0, '2026-08-04' => 2];
        self::assertSame(['2026-08-01' => 2.5, '2026-08-02' => null, '2026-08-03' => null, '2026-08-04' => null], Series::ratio($num, $den));
    }

    public function testCumulativeSkipsNullsWithoutResetting(): void
    {
        $s = ['2026-08-02' => 2, '2026-08-01' => 1, '2026-08-03' => null, '2026-08-04' => 4];
        self::assertSame(['2026-08-01' => 1.0, '2026-08-02' => 3.0, '2026-08-03' => null, '2026-08-04' => 7.0], Series::cumulative($s));
    }

    public function testSameWeekdayDelta(): void
    {
        $s = ['2026-08-01' => 100, '2026-08-08' => 120, '2026-08-15' => 0, '2026-08-22' => 50];
        self::assertEqualsWithDelta(0.2, Series::sameWeekdayDelta($s, '2026-08-08'), 1e-9);
        self::assertNull(Series::sameWeekdayDelta($s, '2026-08-01'), 'no previous week');
        self::assertNull(Series::sameWeekdayDelta($s, '2026-08-22'), 'zero base');
    }

    public function testLastNAlignDatesValuesAndCalendar(): void
    {
        $a = ['2026-08-03' => 3, '2026-08-01' => 1];
        $b = ['2026-08-02' => 2];
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], Series::alignDates([$a, $b]));
        self::assertSame(['2026-08-03' => 3], Series::lastN($a, 1));
        self::assertSame([1, null, 3], Series::values($a, ['2026-08-01', '2026-08-02', '2026-08-03']));
        self::assertSame(['2026-08-30', '2026-08-31', '2026-09-01'], Series::calendar('2026-08-30', '2026-09-01'));
        self::assertSame(7, Series::weekday('2026-09-06'), '6 Sept 2026 is a Sunday');
    }

    public function testRobustStatistics(): void
    {
        self::assertSame(2.5, Series::median([1, 2, 3, 4]));
        self::assertSame(3.0, Series::median([5, 1, 3, null]));
        self::assertSame(1.0, Series::mad([1, 2, 3, 4, 100]));
        self::assertNull(Series::mean([null, null]));
    }

    public function testAggregateLabelsSumsAdditiveAndWeightsRates(): void
    {
        $labels = ['Phone' => ['2026-08-01' => 0.1, '2026-08-02' => 0.1], 'PC' => ['2026-08-01' => 0.3]];
        self::assertSame(['2026-08-01' => 0.4, '2026-08-02' => 0.1], Series::aggregateLabels($labels, true));
        $weights = ['Phone' => ['2026-08-01' => 300], 'PC' => ['2026-08-01' => 100]];
        $agg = Series::aggregateLabels($labels, false, $weights);
        self::assertEqualsWithDelta(0.15, $agg['2026-08-01'], 1e-9, 'DAU-weighted mean');
        self::assertEqualsWithDelta(0.1, $agg['2026-08-02'], 1e-9, 'unweighted fallback when no weight that day');
    }
}
