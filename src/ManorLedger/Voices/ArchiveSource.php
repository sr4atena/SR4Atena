<?php
/**
 * Extra candidates for the top list, read from an archive of videos kept by
 * another process.
 *
 * A relevance search is a sample, not a census: YouTube ranks the results by
 * its own idea of relevance, and a video can be the most watched of the lot
 * and still not come back — a Spanish-language one with a hundred times the
 * views of our #15 did exactly that. An archive built by walking a date-ordered
 * search day by day has no such blind spot, so its most watched videos join
 * the search results before the local sort by views picks the top.
 *
 * The archive is optional and never trusted blindly: a missing, stale or
 * malformed file adds nothing, and the search alone carries the run as before.
 * Expected shape: {"videos": {"<11-char id>": {"views": <int>, …}, …}}.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;

final class ArchiveSource
{
    private Closure $now;

    /** @param ?callable $now fn(): int */
    public function __construct(
        private readonly string $path,
        private readonly float $maxAgeHours = 72.0,
        private readonly int $take = 150,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    /**
     * The most watched ids in the archive, and a line saying what happened.
     *
     * @return array{ids: list<string>, note: string}
     */
    public function ids(): array
    {
        $loaded = $this->load();
        if (is_string($loaded)) {
            return ['ids' => [], 'note' => $loaded];
        }
        [$videos, $age] = $loaded;
        $views = array_map(static fn (array $v): int => (int)($v['views'] ?? 0), $videos);
        arsort($views);
        $ids = array_slice(array_map('strval', array_keys($views)), 0, max(0, $this->take));

        return ['ids' => $ids, 'note' => sprintf('%d candidates from the archive (%d in it, %.0f h old)', count($ids), count($views), $age)];
    }

    /**
     * The newest videos of creators with an audience: at least
     * `$minSubscribers` on the channel and `$minSeconds` long. Without the
     * audience rule the newest are uploads from channels with a handful of
     * subscribers, a dozen a day, and the video of a creator with a quarter of
     * a million comes nineteenth.
     *
     * @return array{ids: list<string>, note: string}
     */
    public function recent(int $take, int $minSubscribers, int $minSeconds = 240): array
    {
        $loaded = $this->load();
        if (is_string($loaded)) {
            return ['ids' => [], 'note' => $loaded];
        }
        [$videos, $age] = $loaded;
        $eligible = array_filter($videos, static fn (array $v): bool => (int)($v['subscribers'] ?? 0) >= $minSubscribers
            && (int)($v['seconds'] ?? PHP_INT_MAX) >= $minSeconds);
        uasort($eligible, static fn (array $a, array $b): int => strcmp(
            (string)($b['publishedTime'] ?? $b['publishedAt'] ?? ''), (string)($a['publishedTime'] ?? $a['publishedAt'] ?? '')));
        $ids = array_slice(array_map('strval', array_keys($eligible)), 0, max(0, $take));

        return ['ids' => $ids, 'note' => sprintf('%d recent candidates from channels with at least %s subscribers (%d eligible, %.0f h old)',
            count($ids), number_format($minSubscribers), count($eligible), $age)];
    }

    /** @return string|array{0: array<string, array<string, mixed>>, 1: float} the reason for nothing, or the videos and their age */
    private function load(): string|array
    {
        if (!is_file($this->path)) {
            return 'no archive at ' . basename($this->path) . ', search only';
        }
        $age = (($this->now)() - (int)filemtime($this->path)) / 3600;
        if ($age > $this->maxAgeHours) {
            return sprintf('archive is %.0f h old (limit %.0f h), search only', $age, $this->maxAgeHours);
        }
        $decoded = json_decode((string)@file_get_contents($this->path), true);
        if (!is_array($decoded) || !is_array($decoded['videos'] ?? null)) {
            return 'archive unreadable, search only';
        }
        $videos = [];
        foreach ($decoded['videos'] as $id => $video) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 && is_array($video)) {
                $videos[$id] = $video;
            }
        }

        return [$videos, $age];
    }
}
