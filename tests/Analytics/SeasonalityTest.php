<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\Seasonality;
use ManorLedger\Analytics\Series;
use PHPUnit\Framework\TestCase;

final class SeasonalityTest extends TestCase
{
    /** 4 weeks ending Sunday 2026-08-30: weekdays 100, Saturday 200, Sunday 300. */
    private function weekly(int $weeks = 4, string $through = '2026-08-30'): array
    {
        $s = [];
        for ($k = 0; $k < $weeks * 7; $k++) {
            $d = Series::shift($through, -$k);
            $s[$d] = [6 => 200, 7 => 300][Series::weekday($d)] ?? 100;
        }

        return $s;
    }

    public function testIndexSumsToSevenAndRanksWeekend(): void
    {
        $idx = (new Seasonality())->index($this->weekly(), '2026-08-30', 4);
        self::assertCount(7, $idx);
        self::assertEqualsWithDelta(7.0, array_sum($idx), 1e-3);
        // overall mean = (5x100 + 200 + 300)/7 = 142.857
        self::assertEqualsWithDelta(100 / 142.857142857, $idx[0], 1e-4);
        self::assertEqualsWithDelta(300 / 142.857142857, $idx[6], 1e-4);
        self::assertGreaterThan($idx[5], $idx[6]);
    }

    public function testNeutralSeriesIsAllOnes(): void
    {
        $flat = array_fill_keys(array_keys($this->weekly()), 50);
        self::assertSame(array_fill(0, 7, 1.0), (new Seasonality())->index($flat, '2026-08-30', 4));
    }

    public function testBuildUsesOnlyCompleteWeeksAndContractShape(): void
    {
        $revenue = $this->weekly();
        $dau = $this->weekly(2);
        $out = (new Seasonality())->build($revenue, $dau, '2026-08-30');
        self::assertSame(Seasonality::WEEKDAYS, $out['weekdays']);
        self::assertSame(2, $out['weeks'], 'DAU only covers two whole weeks');
        self::assertEqualsWithDelta(7.0, array_sum($out['revenueIndex']), 1e-3);
        self::assertEqualsWithDelta(7.0, array_sum($out['dauIndex']), 1e-3);

        $short = (new Seasonality())->build(['2026-08-30' => 1], [], '2026-08-30');
        self::assertSame(0, $short['weeks']);
        self::assertSame(array_fill(0, 7, null), $short['revenueIndex']);
    }
}
