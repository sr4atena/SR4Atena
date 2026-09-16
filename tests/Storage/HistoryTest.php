<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Storage;

use ManorLedger\Storage\History;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    private string $path;
    private array $old;
    private array $new;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/manor-history-' . bin2hex(random_bytes(4)) . '/history.json';
        $this->old = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/cache-old.json'), true);
        $this->new = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/cache-new.json'), true);
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    private function history(): History
    {
        return new History($this->path, static fn (string $id): ?string => ['ItemMonetizationRevenue' => 'robux', 'DailyActiveUsers' => 'int'][$id] ?? null);
    }

    public function testMergeReturnsNumberOfPointsWritten(): void
    {
        $h = $this->history();
        // 3 revenue days + 2 platform points + 1 country point; hourly and error results carry nothing.
        self::assertSame(6, $h->merge($this->old));
    }

    public function testNewerFetchOverwritesAnExistingDay(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        $h->merge($this->new);
        self::assertSame(315, $h->metric('ItemMonetizationRevenue')['series']['']['2026-08-03']);
    }

    public function testDaysAbsentFromTheNewFetchAreKept(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        $h->merge($this->new);
        $days = $h->metric('ItemMonetizationRevenue')['series'][''];
        self::assertSame(['2026-08-01' => 100, '2026-08-02' => 200, '2026-08-03' => 315, '2026-08-04' => 400], $days);
    }

    public function testNonOkResultsAreIgnored(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        self::assertNull($h->metric('ForwardD7Retention'), 'error status carries no points');
        $h->merge($this->new);
        self::assertSame(['Phone' => ['2026-08-01' => 10], 'Computer' => ['2026-08-01' => 5]], $h->metric('DailyActiveUsers')['series'], 'ratelimited result must not wipe existing days');
        self::assertSame(0.03, $h->metric('ForwardD7Retention')['series']['']['2026-08-01']);
    }

    public function testHourlyGranularityIsSkipped(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        self::assertNull($h->metric('HourlyThing'));
        self::assertNotContains('HourlyThing', $h->ids());
    }

    public function testDimensionPairsAreKeyedMetricPipeDimensionWithTheMetricUnit(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        $pair = $h->metric('DailyActiveUsers|Country');
        self::assertSame('int', $pair['unit']);
        self::assertSame(['US' => ['2026-08-01' => 5]], $pair['series']);
    }

    public function testUnitComesFromTheResolverAndDefaultsToDec(): void
    {
        $h = $this->history();
        $h->merge($this->old);
        self::assertSame('robux', $h->metric('ItemMonetizationRevenue')['unit']);
        $bare = new History($this->path);
        $bare->merge($this->old);
        self::assertSame('dec', $bare->metric('ItemMonetizationRevenue')['unit']);
    }

    public function testCatalogObjectPassedToMergeWins(): void
    {
        $catalog = new class {
            public function unitOf(string $id): ?string
            {
                return 'usd';
            }
        };
        $h = $this->history();
        $h->merge($this->old, $catalog);
        self::assertSame('usd', $h->metric('ItemMonetizationRevenue')['unit']);
    }

    public function testBareResultsMapIsAcceptedToo(): void
    {
        $h = $this->history();
        self::assertSame(6, $h->merge($this->old['results']));
        self::assertNull($h->fetchedAt());
    }

    public function testSaveAndReloadPreserveEverythingSortedWithFetchedAt(): void
    {
        $h = $this->history();
        $h->merge($this->new);
        $h->merge($this->old);
        $h->save();

        $again = $this->history();
        self::assertTrue($again->exists());
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'], $again->dates());
        self::assertSame(['DailyActiveUsers', 'DailyActiveUsers|Country', 'ForwardD7Retention', 'ItemMonetizationRevenue'], $again->ids());
        self::assertSame(2000, $again->fetchedAt());
        self::assertNotNull($again->updatedAt());
        $raw = json_decode((string)file_get_contents($this->path), true);
        self::assertSame(History::VERSION, $raw['version']);
        // Old fetch merged after the new one still cannot resurrect the stale 300: it was written last.
        self::assertSame(300, $again->metric('ItemMonetizationRevenue')['series']['']['2026-08-03']);
    }

    public function testMissingFileLoadsAsEmptyHistory(): void
    {
        $h = $this->history();
        self::assertFalse($h->exists());
        self::assertSame([], $h->ids());
        self::assertSame([], $h->dates());
        self::assertNull($h->metric('Anything'));
    }
}
