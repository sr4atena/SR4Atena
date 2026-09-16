<?php
/**
 * One full refresh cycle: read the API key, fetch every daily metric and every
 * metric × dimension pair, merge the rows into the raw caches, fold them into
 * the long-lived history and archive a gzip snapshot of the day.
 *
 * The caches are *merged*, not replaced (as the legacy proxy did): a partial
 * run with --only, or a metric that failed today, never wipes yesterday's rows.
 */
declare(strict_types=1);

namespace ManorLedger\Roblox;

use Closure;
use ManorLedger\Storage\History;
use ManorLedger\Storage\JsonStore;
use ManorLedger\Storage\Snapshots;
use RuntimeException;

final class Refresher
{
    private Closure $clientFactory;
    private Closure $log;
    private Closure $now;

    /**
     * @param array<string, mixed> $config        the array returned by config/app.php
     * @param callable             $clientFactory fn(string $apiKey): AnalyticsClient
     * @param ?callable            $logger        fn(string $line): void (default: STDERR)
     * @param ?callable            $now           fn(): int unix time, injectable for tests
     */
    public function __construct(
        private readonly array $config,
        private readonly MetricCatalog $catalog,
        callable $clientFactory,
        private readonly JsonStore $metricsStore,
        private readonly JsonStore $dimensionsStore,
        private readonly History $history,
        private readonly Snapshots $snapshots,
        ?callable $logger = null,
        ?callable $now = null,
    ) {
        $this->clientFactory = Closure::fromCallable($clientFactory);
        $this->log = $logger !== null
            ? Closure::fromCallable($logger)
            : static function (string $line): void { fwrite(STDERR, $line . "\n"); };
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    /**
     * @param ?float       $ifOlderThanHours skip when the metrics cache is younger than this
     * @param list<string> $only             metric ids and/or "Metric|Dimension" keys to fetch
     * @return array{skipped: bool, reason: ?string, metrics: array<string,int>, dimensions: array<string,int>,
     *               requests: int, pointsMerged: int, snapshot: ?string, failed: int, seconds: float}
     */
    public function run(?float $ifOlderThanHours = null, array $only = [], bool $dryRun = false): array
    {
        $startedAt = ($this->now)();
        $summary = [
            'skipped' => false, 'reason' => null, 'metrics' => [], 'dimensions' => [],
            'requests' => 0, 'pointsMerged' => 0, 'snapshot' => null, 'failed' => 0, 'seconds' => 0.0,
        ];

        if ($ifOlderThanHours !== null) {
            $fetchedAt = (int)(($this->metricsStore->read() ?? [])['fetchedAt'] ?? 0);
            $ageHours  = $fetchedAt > 0 ? ($startedAt - $fetchedAt) / 3600 : INF;
            if ($ageHours < $ifOlderThanHours) {
                $summary['skipped'] = true;
                $summary['reason']  = sprintf('cache is %.1f h old (< %.1f h requested)', $ageHours, $ifOlderThanHours);
                $this->info('Skip: ' . $summary['reason']);
                return $summary;
            }
        }

        $metrics = $this->selectMetrics($only);
        $pairs   = $this->selectPairs($only);
        $this->info(sprintf('Plan: %d daily metrics + %d dimension pairs (%d requests minimum)',
            count($metrics), count($pairs), count($metrics) + count($pairs)));
        if ($metrics === [] && $pairs === []) {
            throw new RuntimeException('Nothing to fetch: --only matched no metric or dimension pair');
        }
        if ($dryRun) {
            $summary['reason'] = 'dry run';
            $this->info('Dry run: no request sent, nothing written');
            return $summary;
        }

        $client = ($this->clientFactory)($this->readApiKey());

        $this->info('Fetching metrics (rate limit makes this take a few minutes)...');
        $metricRows = $metrics === [] ? [] : $client->fetch($metrics);
        $metricsCache = $this->mergeCache($this->metricsStore, $metricRows);
        $summary['metrics'] = self::countStatuses($metricRows);
        $this->info('Metrics: ' . self::describe($summary['metrics']));

        $this->info('Fetching dimension pairs...');
        $pairRows = $pairs === [] ? [] : $client->fetch(array_values($pairs));
        $this->mergeCache($this->dimensionsStore, $pairRows);
        $summary['dimensions'] = self::countStatuses($pairRows);
        $this->info('Dimensions: ' . self::describe($summary['dimensions']));

        // Only this run's rows are folded in (wrapped so History records fetchedAt).
        $fetchedAt = $metricsCache['fetchedAt'];
        $summary['pointsMerged'] = $this->history->merge(['fetchedAt' => $fetchedAt, 'results' => $metricRows])
            + $this->history->merge(['fetchedAt' => $fetchedAt, 'results' => $pairRows]);
        $this->history->save();
        $this->info(sprintf('History: %d points merged', $summary['pointsMerged']));

        $summary['failed'] = self::failures($summary['metrics']) + self::failures($summary['dimensions']);
        if ($summary['failed'] === 0) {
            // Same rule as the legacy cron: a day is archived only when every
            // row came back, so the archive never holds a half-fetched day.
            $date = gmdate('Y-m-d', ($this->now)());
            $this->snapshots->write($date, $metricsCache);
            $summary['snapshot'] = $date;
            $this->info('Snapshot archived for ' . $date);
        } else {
            $this->info(sprintf('Snapshot skipped: %d row(s) failed', $summary['failed']));
        }

        $summary['requests'] = $client->requestsSent();
        $summary['seconds']  = (float)(($this->now)() - $startedAt);
        $this->info(sprintf('Done in %.0fs, %d requests, %d failed row(s)',
            $summary['seconds'], $summary['requests'], $summary['failed']));
        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function selectMetrics(array $only): array
    {
        $metrics = $this->catalog->dailyMetrics();
        if ($only === []) {
            return $metrics;
        }
        return array_values(array_filter(
            $metrics,
            static fn (array $m): bool => in_array((string)$m['id'], $only, true),
        ));
    }

    /** @return array<string, array<string, mixed>> */
    private function selectPairs(array $only): array
    {
        $pairs = $this->catalog->dimensionPairs();
        if ($only === []) {
            return $pairs;
        }
        return array_filter($pairs, static fn (string $key): bool => in_array($key, $only, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The key grants read access to the game's analytics: a file readable by
     * anyone on the host is treated as already leaked and refused outright.
     */
    private function readApiKey(): string
    {
        $path = (string)($this->config['paths']['apiKey'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('API key file not found: ' . $path);
        }
        $perms = fileperms($path);
        if ($perms === false || ($perms & 0o006) !== 0) {
            throw new RuntimeException(sprintf(
                'API key file %s is world-accessible (mode %o); run chmod 600 on it', $path, $perms & 0o777));
        }
        $key = trim((string)file_get_contents($path));
        if ($key === '') {
            throw new RuntimeException('API key file is empty: ' . $path);
        }
        return $key;
    }

    /**
     * Read-merge-write of a raw cache: new rows overwrite the same keys,
     * everything else survives. Returns the merged document.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return array{fetchedAt: int, results: array<string, array<string, mixed>>}
     */
    private function mergeCache(JsonStore $store, array $rows): array
    {
        $cache = $store->read();
        $results = is_array($cache['results'] ?? null) ? $cache['results'] : [];
        foreach ($rows as $key => $row) {
            $results[$key] = $row;
        }
        $merged = ['fetchedAt' => ($this->now)(), 'results' => $results];
        $store->write($merged);
        return $merged;
    }

    /** @return array<string, int> status => count, sorted by status */
    private static function countStatuses(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '?');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private static function failures(array $counts): int
    {
        return ($counts['error'] ?? 0) + ($counts['ratelimited'] ?? 0);
    }

    private static function describe(array $counts): string
    {
        if ($counts === []) {
            return 'none requested';
        }
        $parts = [];
        foreach ($counts as $status => $n) {
            $parts[] = "$status=$n";
        }
        return implode(' ', $parts);
    }

    private function info(string $message): void
    {
        ($this->log)('[' . gmdate('Y-m-d H:i:s', ($this->now)()) . 'Z] ' . $message);
    }
}
