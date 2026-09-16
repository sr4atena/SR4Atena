<?php
/**
 * Daily gzip snapshots of the raw cache, one per fetch day.
 *
 * They cost nothing to keep and are the only way to answer "what did Roblox
 * say about day X on day Y" (how we measured the provisional-day revision).
 * The shape inside is the raw cache document, so History::merge can replay
 * them oldest-first to rebuild history.json from scratch.
 */
declare(strict_types=1);

namespace ManorLedger\Storage;

use RuntimeException;

final class Snapshots
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(private readonly string $dir)
    {
    }

    public function write(string $date, array $cache): void
    {
        $this->assertDate($date);
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode snapshot ' . $date . ': ' . json_last_error_msg());
        }
        $gz = gzencode($json, 6);
        if ($gz === false) {
            throw new RuntimeException('Cannot gzip snapshot ' . $date);
        }
        (new JsonStore($this->pathFor($date)))->writeRaw($gz);
    }

    /** @return list<string> Snapshot dates, ascending. */
    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $dates = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.json\.gz$/', $name, $m)) {
                $dates[] = $m[1];
            }
        }
        sort($dates, SORT_STRING);

        return $dates;
    }

    public function read(string $date): ?array
    {
        $this->assertDate($date);
        $path = $this->pathFor($date);
        if (!is_file($path)) {
            return null;
        }
        $gz = file_get_contents($path);
        if ($gz === false) {
            return null;
        }
        $json = gzdecode($gz);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Deletes snapshots older than $keepDays before $today (default: now,
     * UTC) and returns how many were removed.
     */
    public function prune(int $keepDays, ?string $today = null): int
    {
        $today ??= gmdate('Y-m-d');
        $this->assertDate($today);
        $cutoff = gmdate('Y-m-d', strtotime($today . ' UTC') - max(0, $keepDays) * 86400);
        $removed = 0;
        foreach ($this->list() as $date) {
            if (strcmp($date, $cutoff) < 0 && unlink($this->pathFor($date))) {
                $removed++;
            }
        }

        return $removed;
    }

    public function pathFor(string $date): string
    {
        return $this->dir . '/' . $date . '.json.gz';
    }

    private function assertDate(string $date): void
    {
        if (!preg_match(self::DATE, $date)) {
            throw new RuntimeException('Snapshot date must be YYYY-MM-DD, got ' . $date);
        }
    }
}
