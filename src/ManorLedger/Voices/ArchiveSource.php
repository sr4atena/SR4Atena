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
        if (!is_file($this->path)) {
            return ['ids' => [], 'note' => 'no archive at ' . basename($this->path) . ', search only'];
        }
        $age = (($this->now)() - (int)filemtime($this->path)) / 3600;
        if ($age > $this->maxAgeHours) {
            return ['ids' => [], 'note' => sprintf('archive is %.0f h old (limit %.0f h), search only', $age, $this->maxAgeHours)];
        }
        $decoded = json_decode((string)@file_get_contents($this->path), true);
        if (!is_array($decoded) || !is_array($decoded['videos'] ?? null)) {
            return ['ids' => [], 'note' => 'archive unreadable, search only'];
        }
        $views = [];
        foreach ($decoded['videos'] as $id => $video) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 && is_array($video)) {
                $views[$id] = (int)($video['views'] ?? 0);
            }
        }
        arsort($views);
        $ids = array_slice(array_keys($views), 0, max(0, $this->take));

        return ['ids' => $ids, 'note' => sprintf('%d candidates from the archive (%d in it, %.0f h old)', count($ids), count($views), $age)];
    }
}
