<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Analytics;

use ManorLedger\Analytics\Anomalies;
use ManorLedger\Analytics\Series;
use PHPUnit\Framework\TestCase;

final class AnomaliesTest extends TestCase
{
    /** 6 weeks ending $through (a Saturday): weekdays ~100, weekend ~200, deterministic jitter. */
    private function weekendPattern(string $through = '2026-08-29'): array
    {
        $s = [];
        for ($k = 41; $k >= 0; $k--) {
            $d = Series::shift($through, -$k);
            $base = Series::weekday($d) >= 6 ? 200 : 100;
            $s[$d] = $base + ($k * 7) % 5 - 2;
        }

        return $s;
    }

    public function testNormalWeekendBumpIsNotAnAnomaly(): void
    {
        $metrics = ['DailyActiveUsers' => ['unit' => 'int', 'series' => ['' => $this->weekendPattern()]]];
        self::assertSame([], (new Anomalies())->detect($metrics));
    }

    public function testPlantedSpikeIsFlaggedHighWithItalianMessage(): void
    {
        $s = $this->weekendPattern();
        $s['2026-08-29'] = 520; // Saturday, baseline ~200
        $metrics = ['ClientCrashRate15m' => ['unit' => 'pct', 'series' => ['' => $s]]];
        $found = (new Anomalies())->detect($metrics);

        self::assertCount(1, $found);
        $a = $found[0];
        self::assertSame('ClientCrashRate15m', $a['metric']);
        self::assertSame('2026-08-29', $a['date']);
        self::assertSame(520, $a['value']);
        self::assertEqualsWithDelta(200, $a['expected'], 3);
        self::assertSame('up', $a['direction']);
        self::assertSame('high', $a['severity']);
        self::assertTrue($a['bad']);
        self::assertGreaterThanOrEqual(4.5, $a['zScore']);
        self::assertSame('weekday', $a['method']);
        self::assertMatchesRegularExpression('/^Crash rate client 2,[56]x sopra la baseline dello stesso giorno della settimana$/', $a['message']);
    }

    public function testDropIsGoodNewsForACrashMetricAndBadForFps(): void
    {
        $s = $this->weekendPattern();
        $s['2026-08-29'] = 20;
        $metrics = [
            'ClientCrashCount' => ['unit' => 'int', 'series' => ['' => $s]],
            'ClientFpsAvg'     => ['unit' => 'fps', 'series' => ['' => $s]],
        ];
        $found = (new Anomalies())->detect($metrics);
        self::assertCount(2, $found);
        $byMetric = array_column($found, null, 'metric');
        self::assertFalse($byMetric['ClientCrashCount']['bad']);
        self::assertTrue($byMetric['ClientFpsAvg']['bad']);
        self::assertSame('down', $byMetric['ClientFpsAvg']['direction']);
        self::assertStringContainsString('FPS medi client al 10% della baseline', $byMetric['ClientFpsAvg']['message']);
    }

    public function testProvisionalDayIsExcludedAndShortHistorySkipped(): void
    {
        $s = $this->weekendPattern();
        $s['2026-08-30'] = 9999; // the day Roblox will still revise
        $metrics = ['DailyActiveUsers' => ['unit' => 'int', 'series' => ['' => $s]]];
        self::assertSame([], (new Anomalies())->detect($metrics, '2026-08-30'));

        $short = array_slice($s, -9, null, true);
        self::assertSame([], (new Anomalies())->detect(['DailyActiveUsers' => ['unit' => 'int', 'series' => ['' => $short]]]));
    }

    public function testPerLabelMetricSkipsOkAndFlagsTheFailingStatus(): void
    {
        $ok = $this->weekendPattern();
        $ok['2026-08-29'] = 5000;
        $throttled = $this->weekendPattern();
        $throttled['2026-08-29'] = 700;
        $metrics = ['DataStoreRequestsByStatus' => ['unit' => 'int', 'series' => ['Ok' => $ok, 'KeyThrottled' => $throttled]]];
        $found = (new Anomalies())->detect($metrics);
        self::assertCount(1, $found);
        self::assertSame('KeyThrottled', $found[0]['label']);
        self::assertSame('Richieste DataStore KeyThrottled', $found[0]['name']);
    }

    public function testMedianFallbackWhenHistoryIsShort(): void
    {
        $s = [];
        for ($k = 15; $k >= 0; $k--) {
            $s[Series::shift('2026-08-29', -$k)] = 100 + $k % 3;
        }
        $s['2026-08-29'] = 400;
        $hit = (new Anomalies())->analyse($s, 'Test', 'up');
        self::assertNotNull($hit);
        self::assertSame('median14', $hit['method']);
        self::assertStringContainsString('mediana dei 14 giorni precedenti', $hit['message']);
    }
}
