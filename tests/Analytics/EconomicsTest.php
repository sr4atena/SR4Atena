<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\Economics;
use PHPUnit\Framework\TestCase;

final class EconomicsTest extends TestCase
{
    private Economics $eco;

    protected function setUp(): void
    {
        $this->eco = new Economics(0.0038, 0.17, ['conservative' => 18, 'base' => 30], [0.06, 0.10, 0.15]);
    }

    public function testRobuxToUsd(): void
    {
        self::assertEqualsWithDelta(3.8, $this->eco->grossUsd(1000), 1e-9);
        self::assertEqualsWithDelta(3.154, $this->eco->netUsd(1000), 1e-9);
        self::assertEqualsWithDelta(94.62, $this->eco->monthlyNetUsd(1000), 1e-9);
        self::assertSame([null, 3.8], array_values($this->eco->grossUsdSeries(['2026-08-01' => null, '2026-08-02' => 1000])));
    }

    public function testValuationSeriesAgainstHandComputedNumbers(): void
    {
        $revenue = [];
        for ($i = 1; $i <= 8; $i++) {
            $revenue[sprintf('2026-08-%02d', $i)] = 1000;
        }
        $revenue['2026-08-08'] = 8000; // mean of 08-02..08-08 = 2000
        $v = $this->eco->valuationSeries($revenue);
        self::assertSame(['conservative', 'base'], array_keys($v));
        self::assertSame(array_fill(0, 6, null), array_slice(array_values($v['base']), 0, 6));
        // 1000 R$/day x 30 x 0.0038 x 0.83 = 94.62 $/month; x30 = 2838.6; x18 = 1703.16
        self::assertEqualsWithDelta(2838.6, $v['base']['2026-08-07'], 1e-6);
        self::assertEqualsWithDelta(1703.16, $v['conservative']['2026-08-07'], 1e-6);
        self::assertEqualsWithDelta(5677.2, $v['base']['2026-08-08'], 1e-6);
    }

    public function testPlateauScaleUsesConservativeMultiple(): void
    {
        $rows = $this->eco->plateauScale(10000, 0.5);
        self::assertCount(3, $rows);
        // 10% of 10 000 DAU x 0.5 R$ x 30 x 0.0038 x 0.83 = 47.31 $/month; x18 = 851.58
        self::assertSame(['share' => 0.10, 'dau' => 1000, 'monthlyUsdNet' => 47.31, 'valuationUsd' => 851.58], $rows[1]);
    }

    public function testKpisCompareCurrentWindowWithThePreviousOne(): void
    {
        $revenue = [];
        $dau = [];
        for ($i = 0; $i < 14; $i++) {
            $d = sprintf('2026-08-%02d', 10 + $i);
            $revenue[$d] = $i < 7 ? 1000 : 1100;   // previous week 1000/day, current 1100/day
            $dau[$d] = $i < 7 ? 500 : 550;
        }
        $revenue['2026-08-24'] = 99999; // after $through: must be ignored
        $k = $this->eco->kpis([
            'revenue' => $revenue, 'dau' => $dau, 'mau' => array_fill_keys(array_keys($dau), 5000),
            'payingUsers' => [], 'cvr' => array_fill_keys(array_keys($dau), 0.01),
            'd1' => ['2026-08-20' => 0.09, '2026-08-21' => 0.08], 'd7' => [], 'stickiness' => [],
        ], '2026-08-23');

        self::assertSame(1100.0, $k['revenue7dRobux']['value']);
        self::assertEqualsWithDelta(0.1, $k['revenue7dRobux']['delta'], 1e-9);
        self::assertSame('vs 7 giorni prima', $k['revenue7dRobux']['deltaLabel']);
        self::assertEqualsWithDelta(1100 * 0.0038 * 0.83, $k['revenue7dUsdNet']['value'], 0.005);
        self::assertEqualsWithDelta(1100 * 30 * 0.0038 * 0.83, $k['monthlyRunRateUsdNet']['value'], 0.005);
        self::assertEqualsWithDelta(1100 * 30 * 0.0038 * 0.83 * 30, $k['valuationBaseUsd']['value'], 0.01);
        self::assertEqualsWithDelta(1100 * 30 * 0.0038 * 0.83 * 18, $k['valuationConservativeUsd']['value'], 0.01);
        self::assertEqualsWithDelta(14700 * 0.0038 * 0.83, $k['cumulativeUsdNet']['value'], 0.005, 'the day after $through is excluded');
        self::assertSame(550, $k['dau7']['value']);
        self::assertEqualsWithDelta(0.1, $k['dau7']['delta'], 1e-9);
        self::assertSame(2.0, $k['arpdau7Robux']['value']);
        self::assertSame(0.0, $k['arpdau7Robux']['delta']);
        self::assertSame(0.01, $k['payingCvr7']['value']);
        self::assertSame(['value' => 0.08, 'date' => '2026-08-21'], $k['d1Retention']);
        self::assertSame(['value' => null, 'date' => null], $k['d7Retention']);
        self::assertEqualsWithDelta(0.11, $k['stickiness7']['value'], 1e-9, 'DAU/MAU when no stickiness metric');
    }

    public function testKpisWithNoDataAreNullNotZero(): void
    {
        $k = $this->eco->kpis(['revenue' => [], 'dau' => []], '2026-08-23');
        self::assertNull($k['revenue7dRobux']['value']);
        self::assertNull($k['revenue7dRobux']['delta']);
        self::assertNull($k['valuationBaseUsd']['value']);
        self::assertSame(0.0, $k['cumulativeUsdNet']['value']);
    }
}
