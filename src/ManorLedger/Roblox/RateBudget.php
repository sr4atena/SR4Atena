<?php
/**
 * Shared rate-limit window for the Roblox Analytics API.
 *
 * Roblox allows 30 requests per calendar minute per *owner* (shared by every
 * API key of the account). Every PHP process is separate, so the counter lives
 * in a small JSON file guarded by an exclusive flock: two refreshes started
 * together must draw from the same window instead of each spending a full one.
 *
 * The quota declared by Roblox in the x-ratelimit-* headers is authoritative;
 * the fixed window (windowLimit per windowSecs) is only a safety net used
 * until the first header has been seen.
 */
declare(strict_types=1);

namespace ManorLedger\Roblox;

use Closure;

final class RateBudget
{
    /** Longest single sleep before re-checking the shared state. */
    private const MAX_SLEEP_SECS = 65.0;

    private Closure $now;
    private Closure $sleep;

    /**
     * @param string   $statePath  JSON file holding the shared counter (a sibling .lock is created).
     * @param bool     $allowSleep CLI mode: wait for the window to reset instead of refusing tokens.
     * @param int      $windowLimit Requests per estimated window until a header is observed.
     * @param int      $windowSecs  Length of the real window.
     * @param int      $reserve     Tokens never spent, left for the owner's other tools.
     * @param ?callable $now   fn(): float  (unix time with fraction) — injectable for tests.
     * @param ?callable $sleep fn(float $seconds): void — injectable for tests.
     */
    public function __construct(
        private readonly string $statePath,
        private readonly bool $allowSleep,
        private readonly int $windowLimit = 18,
        private readonly int $windowSecs = 60,
        private readonly int $reserve = 6,
        ?callable $now = null,
        ?callable $sleep = null,
    ) {
        $this->now   = $now !== null ? Closure::fromCallable($now) : static fn (): float => microtime(true);
        $this->sleep = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (float $secs): void { usleep((int)($secs * 1e6)); };
    }

    /**
     * Consume one token. Returns false only when the quota is exhausted and
     * sleeping is not allowed (browser mode); in CLI mode it waits instead.
     */
    public function take(): bool
    {
        while (true) {
            $wait = $this->withState(function (array &$st, callable $save): float {
                $now = ($this->now)();

                // 1. A pause imposed by a 429 (retry-after) or by the reset header.
                if (($st['blockedUntil'] ?? 0) > $now) {
                    return $st['blockedUntil'] - $now;
                }

                // 2. The real quota read from headers, if we have seen one.
                $rem = $st['remaining'] ?? null;
                $rst = $st['resetAt'] ?? null;
                if ($rem !== null && $rst !== null) {
                    if ($rst <= $now) {
                        // Window expired: forget the header and start over.
                        $st['remaining'] = null;
                        $st['resetAt']   = null;
                    } elseif ($rem <= $this->reserve) {
                        // Below the reserve: wait for the declared reset.
                        $save($st);
                        return max(0.05, $rst - $now);
                    } else {
                        // Optimistic decrement until the next response corrects it.
                        $st['remaining'] = $rem - 1;
                        $st['used']      = ($st['used'] ?? 0) + 1;
                        $save($st);
                        return 0.0;
                    }
                }

                // 3. Fallback: estimated window, until a header is observed.
                if ($now - $st['winStart'] >= $this->windowSecs) {
                    $st['winStart'] = $now;
                    $st['used']     = 0;
                }
                if ($st['used'] < $this->windowLimit) {
                    $st['used']++;
                    $save($st);
                    return 0.0;
                }
                return max(0.05, $this->windowSecs - ($now - $st['winStart']));
            });
            if ($wait <= 0.0) {
                return true;
            }
            if (!$this->allowSleep) {
                return false;
            }
            // The lock is already released: other processes are not held up.
            ($this->sleep)(min($wait, self::MAX_SLEEP_SECS) + 0.25);
        }
    }

    /**
     * Record the quota Roblox declared on a response. Responses arrive out of
     * order, so the most pessimistic value wins: underestimating the remaining
     * tokens is the safe mistake.
     */
    public function observe(?int $remaining, ?int $resetSecs): void
    {
        if ($remaining === null && $resetSecs === null) {
            return;
        }
        $this->withState(function (array &$st, callable $save) use ($remaining, $resetSecs): float {
            $now = ($this->now)();
            if ($resetSecs !== null) {
                $cand = $now + max(0, $resetSecs);
                $st['resetAt'] = isset($st['resetAt']) && $st['resetAt'] > $now
                    ? max($st['resetAt'], $cand) : $cand;
            }
            if ($remaining !== null) {
                $st['remaining'] = isset($st['remaining'])
                    ? min($st['remaining'], $remaining) : $remaining;
            }
            $save($st);
            return 0.0;
        });
    }

    /** Explicit pause, e.g. from retry-after on a 429. */
    public function blockFor(float $secs): void
    {
        $this->withState(function (array &$st, callable $save) use ($secs): float {
            $st['blockedUntil'] = ($this->now)() + max(1.0, $secs);
            $st['remaining']    = 0;
            $save($st);
            return 0.0;
        });
    }

    /**
     * Roblox answered 429: the window really is spent. Writing it to the shared
     * state makes every other process slow down instead of insisting.
     */
    public function exhausted(?float $secs = null): void
    {
        $this->blockFor($secs ?? (float)$this->windowSecs);
    }

    public function allowsSleep(): bool
    {
        return $this->allowSleep;
    }

    /**
     * Run $fn on the shared state under an exclusive lock. Without a usable
     * lock file we degrade to a per-process counter: better to proceed with a
     * local estimate than to stop everything.
     *
     * @param callable(array &$state, callable $save): float $fn
     */
    private function withState(callable $fn): float
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $lockPath = $this->statePath . '.lock';
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            $st = $this->freshState();
            return $fn($st, static function (array $s): void {});
        }
        chmod($lockPath, 0600);
        flock($fh, LOCK_EX);
        try {
            $raw = is_file($this->statePath) ? file_get_contents($this->statePath) : false;
            $st  = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($st) || !isset($st['winStart'], $st['used'])) {
                $st = $this->freshState();
            }
            $save = function (array $s): void {
                // Temp file + rename: the state can never be read half-written.
                $tmp = $this->statePath . '.tmp' . getmypid();
                if (file_put_contents($tmp, json_encode($s)) !== false) {
                    chmod($tmp, 0600);
                    rename($tmp, $this->statePath);
                }
            };
            return $fn($st, $save);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array{winStart: float, used: int} */
    private function freshState(): array
    {
        return ['winStart' => ($this->now)(), 'used' => 0];
    }
}
