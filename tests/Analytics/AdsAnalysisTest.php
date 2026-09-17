<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\AdsAnalysis;
use ManorLedger\Analytics\Economics;
use PHPUnit\Framework\TestCase;

final class AdsAnalysisTest extends TestCase
{
    private const DEVEX = 0.0038;
    private const ROYALTY = 0.17;

    private function analysis(): AdsAnalysis
    {
        return new AdsAnalysis(new Economics(self::DEVEX, self::ROYALTY, ['conservative' => 18, 'base' => 30], [0.1]));
    }

    /** @param list<array<string, mixed>> $campaigns */
    private function ledger(array $campaigns): array
    {
        return ['importedAt' => '2026-09-17T06:00:00Z', 'source' => 'export.zip',
            'window' => ['from' => '2026-09-01', 'to' => '2026-09-05'], 'currency' => 'USD', 'campaigns' => $campaigns];
    }

    private function campaign(array $overrides = []): array
    {
        return $overrides + ['id' => 'c1', 'name' => 'Campagna', 'from' => '2026-09-01', 'to' => '2026-09-03',
            'running' => false, 'spent' => 90.0, 'impressions' => 300, 'clicks' => 30, 'plays' => 9, 'budget' => 30.0];
    }

    /** @return array<string, array<string, int|float|null>> label => date => value */
    private function bySource(array $paidImpressions, array $paidDau = [], array $organicDau = []): array
    {
        return [
            'impressions' => ['SponsoredAds' => $paidImpressions, 'Search' => []],
            'dau'         => ['SponsoredAds' => $paidDau, 'Search' => $organicDau],
            'plays'       => ['SponsoredAds' => $paidDau],
        ];
    }

    private function values(array $block, string $label = ''): array
    {
        foreach ($block['series'] as $series) {
            if ($series['label'] === $label) {
                return array_combine($block['dates'], $series['values']);
            }
        }
        self::fail("no series labelled '{$label}'");
    }

    public function testSpendFollowsTheDailyImpressionShapeAndKeepsItsTotal(): void
    {
        // Half the impressions on day 2, a quarter on each of the others.
        $impressions = ['2026-09-01' => 250, '2026-09-02' => 500, '2026-09-03' => 250];
        $ads = $this->analysis()->build($this->ledger([$this->campaign()]), $this->bySource($impressions), [], []);

        $spend = $this->values($ads['spend']);
        self::assertSame([22.5, 45.0, 22.5], array_values($spend));
        self::assertSame(90.0, round(array_sum($spend), 2), 'the export total must survive the spread');
    }

    public function testDaysWithoutImpressionsFallBackToAnEvenSpreadAndSaySo(): void
    {
        $ads = $this->analysis()->build($this->ledger([$this->campaign()]), $this->bySource([]), [], []);

        self::assertSame([30.0, 30.0, 30.0], array_values($this->values($ads['spend'])));
        self::assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $ads['spendEstimated']);
    }

    public function testOverlappingCampaignsKeepTheirOwnImpressionTotals(): void
    {
        // Two flights over the same three days: one delivered 900 impressions,
        // the other 100, and the second only ran on the last day.
        $impressions = ['2026-09-01' => 400, '2026-09-02' => 400, '2026-09-03' => 200];
        $ledger = $this->ledger([
            $this->campaign(['id' => 'big', 'name' => 'Grande', 'spent' => 90.0, 'impressions' => 900]),
            $this->campaign(['id' => 'small', 'name' => 'Piccola', 'from' => '2026-09-03', 'to' => '2026-09-03',
                'spent' => 10.0, 'impressions' => 100]),
        ]);
        $ads = $this->analysis()->build($ledger, $this->bySource($impressions), [], []);

        $byCampaign = $ads['spendByCampaign'];
        $big = $this->values($byCampaign, 'Grande · 01/09');
        $small = $this->values($byCampaign, 'Piccola · 03/09');
        self::assertSame(90.0, round(array_sum($big), 2));
        self::assertSame(10.0, round(array_sum($small), 2));
        // The small flight exists only on the third day, so the big one keeps
        // the whole of the first two and only shares the last.
        self::assertNull($small['2026-09-01']);
        self::assertGreaterThan(0.0, $small['2026-09-03']);
        self::assertGreaterThan($big['2026-09-03'], $big['2026-09-02']);
    }

    public function testRevenueIsAttributedByTheShareOfPlayersTheAdsBrought(): void
    {
        $impressions = ['2026-09-01' => 100, '2026-09-02' => 100, '2026-09-03' => 100];
        $paidDau = ['2026-09-01' => 200, '2026-09-02' => 200, '2026-09-03' => 200];
        $organicDau = ['2026-09-01' => 800, '2026-09-02' => 800, '2026-09-03' => 800];
        $revenue = ['2026-09-01' => 10000, '2026-09-02' => 10000, '2026-09-03' => 10000];
        $dau = ['2026-09-01' => 1000, '2026-09-02' => 1000, '2026-09-03' => 1000];

        $ads = $this->analysis()->build(
            $this->ledger([$this->campaign()]),
            $this->bySource($impressions, $paidDau, $organicDau),
            $revenue,
            $dau,
        );

        // A fifth of the players came from ads, so a fifth of the day's Robux,
        // net of DevEx and royalty.
        $expected = round(10000 * 0.2 * self::DEVEX * (1 - self::ROYALTY), 2);
        self::assertSame($expected, $this->values($ads['revenueUsdNet'])['2026-09-02']);
        self::assertSame(0.2, $this->values($ads['paidShare'])['2026-09-02']);
        self::assertSame(round($expected * 3 / 90, 4), $ads['totals']['roi']);
        self::assertSame(600, $ads['totals']['buyersDauDays']);
        // Organic is what is left of the total, not a second measurement.
        self::assertSame(800, $this->values($ads['dauSplit'], 'organic')['2026-09-02']);
    }

    public function testBandsFollowTheDaysMoneyWasSpentNotTheDeclaredWindow(): void
    {
        // A campaign nobody closed in Ads Manager: declared to the end of the
        // window, but impressions only on the first and the last day.
        $impressions = ['2026-09-01' => 500, '2026-09-02' => 0, '2026-09-03' => 0, '2026-09-04' => 0, '2026-09-05' => 500];
        $ledger = $this->ledger([$this->campaign(['to' => '2026-09-05', 'running' => true])]);
        $ads = $this->analysis()->build($ledger, $this->bySource($impressions), [], []);

        self::assertSame([
            ['from' => '2026-09-01', 'to' => '2026-09-01', 'label' => 'ads attive'],
            ['from' => '2026-09-05', 'to' => '2026-09-05', 'label' => 'ads attive'],
        ], $ads['windows']);
        $row = $ads['campaigns'][0];
        self::assertSame('2026-09-05', $row['to'], 'the row shows the days the money landed on');
        self::assertSame('2026-09-05', $row['declaredTo']);
    }

    public function testCrumbsOfSpendNeitherPrintNorDivide(): void
    {
        // One day carries the whole delivery; the other two get fractions of a
        // cent, which must not become $ 0,00 bars or a ROI of 40 000 %.
        $impressions = ['2026-09-01' => 1, '2026-09-02' => 1000000, '2026-09-03' => 1];
        $revenue = ['2026-09-01' => 10000, '2026-09-02' => 10000, '2026-09-03' => 10000];
        $dau = ['2026-09-01' => 1000, '2026-09-02' => 1000, '2026-09-03' => 1000];
        $paidDau = ['2026-09-01' => 200, '2026-09-02' => 200, '2026-09-03' => 200];

        $ads = $this->analysis()->build(
            $this->ledger([$this->campaign()]),
            $this->bySource($impressions, $paidDau, []),
            $revenue,
            $dau,
        );

        $spend = $this->values($ads['spend']);
        self::assertSame(['2026-09-02'], array_keys($spend), 'the crumb days leave the series entirely');
        // The ROI keeps the day on the axis with no value on it (a gap, not a
        // spike); the cost per player has no day at all, having no spend to divide.
        self::assertNull($this->values($ads['roi'])['2026-09-01']);
        self::assertArrayNotHasKey('2026-09-01', $this->values($ads['costPerDauDay']));
        self::assertEqualsWithDelta(90.0, $spend['2026-09-02'], 0.01);
    }

    public function testNothingToShowWithoutALedgerOrPaidTraffic(): void
    {
        self::assertSame([], $this->analysis()->build(null, [], [], []));
    }

    public function testSourcesWithoutALedgerStillGetTheirSplit(): void
    {
        $paidDau = ['2026-09-01' => 100];
        $ads = $this->analysis()->build(null, $this->bySource([], $paidDau, ['2026-09-01' => 900]), [], ['2026-09-01' => 1000]);

        self::assertNull($ads['ledger']);
        self::assertSame([], $ads['campaigns']);
        self::assertSame(100, $this->values($ads['dauSplit'], 'paid')['2026-09-01']);
        self::assertSame(0.1, $this->values($ads['paidShare'])['2026-09-01']);
    }
}
