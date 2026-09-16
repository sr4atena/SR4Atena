<?php
/**
 * The long-lived daily series, folded from every fetch.
 *
 * Roblox keeps only ~28 days per metric, so the dashboard's memory is this
 * file. The merge is deliberately dumb: the newest fetch wins for a day it
 * carries, and days it does not carry are left untouched. That is enough for
 * Roblox's revision pattern (the freshest day is revised upward the next day)
 * and guarantees nothing is ever deleted.
 *
 * The catalog is reached through a plain callable (metricId -> unit) so this
 * class has no dependency on the Roblox namespace and tests need no catalog.
 */
declare(strict_types=1);

namespace ManorLedger\Storage;

final class History
{
    public const VERSION = 1;
    /** Only this granularity is a daily series; hourly/weekly/bucket results are skipped. */
    private const DAILY = 'OneDay';
    private const DEFAULT_UNIT = 'dec';

    private readonly JsonStore $store;
    /** @var callable(string): string */
    private $unitOf;
    private ?array $data = null;

    /**
     * @param null|callable(string): ?string $unitOf Resolver metricId -> unit ("robux",
     *        "int", ...), e.g. MetricCatalog::unitOf(...). Null or absent falls back to "dec".
     */
    public function __construct(string $path, ?callable $unitOf = null)
    {
        $this->store  = new JsonStore($path);
        $this->unitOf = $unitOf ?? static fn (string $id): ?string => null;
    }

    public function load(): void
    {
        if ($this->data !== null) {
            return;
        }
        $read = $this->store->read();
        $valid = is_array($read) && isset($read['metrics']) && is_array($read['metrics']);
        $this->data = $valid ? $read : ['version' => self::VERSION, 'updatedAt' => null, 'metrics' => []];
        $this->data['version'] = self::VERSION;
    }

    public function exists(): bool
    {
        return $this->store->read() !== null;
    }

    /**
     * Folds one cache document into the history and returns the number of
     * (series, day) points written. Accepts either the whole cache
     * {fetchedAt, results} or a bare results map. Results keyed
     * "Metric|Dimension" land under that same key.
     *
     * @param object|null $catalog Anything exposing unitOf(string): ?string (the
     *        MetricCatalog); when given it takes precedence over the constructor resolver.
     */
    public function merge(array $cacheResults, ?object $catalog = null): int
    {
        $this->load();
        $unitOf = $this->unitOf;
        if ($catalog !== null && method_exists($catalog, 'unitOf')) {
            $unitOf = static fn (string $id): ?string => $catalog->unitOf($id);
        }
        $results = $cacheResults;
        if (isset($cacheResults['results']) && is_array($cacheResults['results'])) {
            $results = $cacheResults['results'];
            $fetchedAt = $cacheResults['fetchedAt'] ?? null;
            if (is_int($fetchedAt) && $fetchedAt > (int)($this->data['fetchedAt'] ?? 0)) {
                $this->data['fetchedAt'] = $fetchedAt;
            }
        }

        $written = 0;
        foreach ($results as $key => $result) {
            if (!is_string($key) || !is_array($result)) {
                continue;
            }
            if (($result['status'] ?? null) !== 'ok' || ($result['granularity'] ?? null) !== self::DAILY) {
                continue;
            }
            $written += $this->mergeResult($key, $result['series'] ?? [], $unitOf);
        }

        return $written;
    }

    private function mergeResult(string $key, array $seriesList, callable $unitOf): int
    {
        $metricId = explode('|', $key, 2)[0];
        $written  = 0;
        foreach ($seriesList as $series) {
            if (!is_array($series)) {
                continue;
            }
            $label = (string)($series['label'] ?? '');
            foreach ($series['points'] ?? [] as $point) {
                if (!is_array($point) || !isset($point['t'], $point['v']) || !is_numeric($point['v'])) {
                    continue;
                }
                $date = substr((string)$point['t'], 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                if (!isset($this->data['metrics'][$key])) {
                    $this->data['metrics'][$key] = ['unit' => self::DEFAULT_UNIT, 'series' => []];
                }
                // Re-resolved on every merge so a history first written without a catalog heals itself.
                $this->data['metrics'][$key]['unit'] = (string)($unitOf($metricId) ?? self::DEFAULT_UNIT);
                $this->data['metrics'][$key]['series'][$label][$date] = $point['v'] + 0;
                $written++;
            }
        }

        return $written;
    }

    public function save(): void
    {
        $this->load();
        foreach ($this->data['metrics'] as &$metric) {
            foreach ($metric['series'] as &$days) {
                ksort($days, SORT_STRING);
            }
            unset($days);
        }
        unset($metric);
        $this->data['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->store->write($this->data);
    }

    /** @return null|array{unit: string, series: array<string, array<string, int|float>>} */
    public function metric(string $id): ?array
    {
        $this->load();
        $metric = $this->data['metrics'][$id] ?? null;
        if ($metric === null) {
            return null;
        }
        foreach ($metric['series'] as &$days) {
            ksort($days, SORT_STRING);
        }
        unset($days);

        return $metric;
    }

    /** @return list<string> Metric keys, dimension pairs included, sorted. */
    public function ids(): array
    {
        $this->load();
        $ids = array_keys($this->data['metrics']);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return list<string> Every ISO day present in any series, ascending. */
    public function dates(): array
    {
        $this->load();
        $dates = [];
        foreach ($this->data['metrics'] as $metric) {
            foreach ($metric['series'] as $days) {
                foreach ($days as $date => $_) {
                    $dates[$date] = true;
                }
            }
        }
        $list = array_keys($dates);
        sort($list, SORT_STRING);

        return array_map('strval', $list);
    }

    /** Unix time of the most recent fetch folded in, when the cache carried one. */
    public function fetchedAt(): ?int
    {
        $this->load();
        $value = $this->data['fetchedAt'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function updatedAt(): ?string
    {
        $this->load();
        $value = $this->data['updatedAt'] ?? null;

        return is_string($value) ? $value : null;
    }
}
