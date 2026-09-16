<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\SessionSurvival;
use ManorLedger\Analytics\Series;
use PHPUnit\Framework\TestCase;

final class SessionSurvivalTest extends TestCase
{
    private const BUCKETS = [0, 30, 60, 300, 600, 1800];
    /** Survival 1 / .9 / .8 / .6 / .4 / .1 on a base of 1000 sessions. */
    private const SHAPE_A = [0 => 1000, 30 => 900, 60 => 800, 300 => 600, 600 => 400, 1800 => 100];
    /** Survival 1 / .8 / .7 / .5 / .3 / .05 on a base of 3000 sessions. */
    private const SHAPE_B = [0 => 3000, 30 => 2400, 60 => 2100, 300 => 1500, 600 => 900, 1800 => 150];

    /**
     * @param array<string, array<int, int>> $shapeByDate
     *
     * @return array{unit: string, series: array<string, array<string, int>>}
     */
    private function metric(array $shapeByDate): array
    {
        $series = [];
        foreach ($shapeByDate as $date => $shape) {
            foreach ($shape as $bucket => $value) {
                $series[(string)$bucket][(string)$date] = $value;
            }
        }

        return ['unit' => 'int', 'series' => $series];
    }

    /** $shapes maps an offset in days before $through to the shape of that day. */
    private function days(string $through, int $count, array $shapes = []): array
    {
        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $out[Series::shift($through, -$k)] = $shapes[$k] ?? self::SHAPE_A;
        }

        return $out;
    }

    public function testContractOnTwoFlatWindows(): void
    {
        // Current week is shape A, the week before is shape B: both curves are exact.
        $shapes = [];
        for ($k = 7; $k < 14; $k++) {
            $shapes[$k] = self::SHAPE_B;
        }
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-14', 14, $shapes)), '2026-09-14');

        self::assertSame(self::BUCKETS, $out['bucketsSeconds']);
        self::assertSame(['from' => '2026-09-08', 'to' => '2026-09-14', 'days' => 7, 'values' => [1.0, 0.9, 0.8, 0.6, 0.4, 0.1]], $out['current']);
        self::assertSame(['from' => '2026-09-01', 'to' => '2026-09-07', 'days' => 7, 'values' => [1.0, 0.8, 0.7, 0.5, 0.3, 0.05]], $out['previous']);
        self::assertSame(1000.0, $out['sessionsPerDay']);

        $byThreshold = array_column($out['milestones'], null, 'seconds');
        self::assertSame([60, 300, 600, 1800], array_keys($byThreshold));
        self::assertSame('1 minuto', $byThreshold[60]['label']);
        self::assertSame(0.8, $byThreshold[60]['current']);
        self::assertSame(0.7, $byThreshold[60]['previous']);
        self::assertSame(0.1, $byThreshold[60]['delta']);
        self::assertSame(0.05, $byThreshold[1800]['delta']);
        // The time series covers every usable day, oldest first.
        self::assertCount(14, $byThreshold[300]['dates']);
        self::assertSame('2026-09-01', $byThreshold[300]['dates'][0]);
        self::assertSame('2026-09-14', $byThreshold[300]['dates'][13]);
        self::assertSame(0.5, $byThreshold[300]['values'][0]);
        self::assertSame(0.6, $byThreshold[300]['values'][13]);
    }

    public function testMedianIsInterpolatedBetweenTheBucketsThatStraddleAHalf(): void
    {
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-14', 7)), '2026-09-14');
        // 0.6 at 300 s, 0.4 at 600 s: 300 + (0.1/0.2) x 300.
        self::assertSame(450.0, $out['medianSeconds']);
    }

    public function testMedianIsNullWhenTheCurveNeverFallsBelowAHalf(): void
    {
        $sticky = [0 => 1000, 30 => 990, 60 => 980, 300 => 900, 600 => 800, 1800 => 700];
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-14', 7, array_fill(0, 7, $sticky))), '2026-09-14');
        self::assertNull($out['medianSeconds']);
    }

    public function testWindowsArePooledSoBusyDaysWeighMore(): void
    {
        // Three usable days: two of shape A (1000 sessions) and one of shape B (3000).
        $days = $this->days('2026-09-14', 3, [0 => self::SHAPE_B]);
        $out = (new SessionSurvival())->build($this->metric($days), '2026-09-14');
        // (800 + 800 + 2100) / (1000 + 1000 + 3000) = 0.74, not the 0.7667 of an unweighted mean.
        self::assertSame(0.74, $out['current']['values'][2]);
        self::assertSame(0.74, $out['milestones'][0]['current']);
        self::assertSame(1666.67, $out['sessionsPerDay']);
    }

    public function testDaysWithoutAUsableBaseAreSkipped(): void
    {
        $days = $this->days('2026-09-14', 7);
        $days['2026-09-13'][0] = 0;                 // zero base: nothing started, nothing to divide by
        unset($days['2026-09-12'][0]);              // base missing altogether
        $out = (new SessionSurvival())->build($this->metric($days), '2026-09-14');

        self::assertSame(5, $out['current']['days']);
        self::assertSame(['2026-09-08', '2026-09-14'], [$out['current']['from'], $out['current']['to']]);
        self::assertSame(1000.0, $out['sessionsPerDay']);
        self::assertNotContains('2026-09-13', $out['milestones'][0]['dates']);
    }

    public function testDaysAfterDataThroughAreIgnored(): void
    {
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-16', 16)), '2026-09-14');
        self::assertSame('2026-09-14', $out['current']['to']);
        self::assertSame('2026-09-14', end($out['milestones'][0]['dates']));
    }

    public function testPreviousIsNullWithoutEnoughHistory(): void
    {
        // Nine days of history: the previous window only reaches two of them.
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-14', 9)), '2026-09-14');
        self::assertSame(7, $out['current']['days']);
        self::assertNull($out['previous']);
        self::assertNull($out['milestones'][0]['previous']);
        self::assertNull($out['milestones'][0]['delta']);
        self::assertSame(0.8, $out['milestones'][0]['current']);
    }

    public function testCurrentIsNullBelowThreeUsableDays(): void
    {
        $out = (new SessionSurvival())->build($this->metric($this->days('2026-09-14', 2)), '2026-09-14');
        self::assertNull($out['current']);
        self::assertNull($out['previous']);
        self::assertNull($out['sessionsPerDay']);
        self::assertNull($out['medianSeconds']);
        self::assertNull($out['milestones'][0]['current']);
        self::assertCount(2, $out['milestones'][0]['dates']);
    }

    public function testNullWhenTheMetricOrItsBaseBucketIsMissing(): void
    {
        $survival = new SessionSurvival();
        self::assertNull($survival->build(null, '2026-09-14'));
        self::assertNull($survival->build($this->metric($this->days('2026-09-14', 7)), null));
        self::assertNull($survival->build(['unit' => 'int', 'series' => ['60' => ['2026-09-14' => 10]]], '2026-09-14'));
        self::assertNull($survival->build($this->metric($this->days('2026-08-14', 7)), '2026-07-01'), 'no day at or before dataThrough');
    }
}
