<?php
/**
 * Brute-force protection with two independent counters: per client IP (a
 * single attacker spraying usernames) and per username (a distributed attack
 * on one account). Each consecutive lockout doubles the previous one, capped
 * at 24 h; a successful login resets both counters.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use ManorLedger\Support\Clock;
use ManorLedger\Support\LockedJsonFile;

final class LoginThrottle
{
    public const MAX_LOCKOUT_SECONDS = 86400;

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $maxFailures,
        private readonly int $lockoutSeconds,
    ) {
    }

    /** Seconds until the IP or the username is allowed to try again, or null when not locked. */
    public function check(string $ip, string $username): ?int
    {
        $remaining = 0;
        foreach ($this->keys($ip, $username) as $key) {
            $record = $this->file($key)->read();
            $lockedUntil = (int)($record['lockedUntil'] ?? 0);
            $remaining = max($remaining, $lockedUntil - $this->clock->now());
        }
        return $remaining > 0 ? $remaining : null;
    }

    public function fail(string $ip, string $username): void
    {
        $now = $this->clock->now();
        foreach ($this->keys($ip, $username) as $key) {
            $this->file($key)->update(function (array $record) use ($now): array {
                $failures = (int)($record['failures'] ?? 0) + 1;
                $lockouts = (int)($record['lockouts'] ?? 0);
                $lockedUntil = (int)($record['lockedUntil'] ?? 0);
                if ($failures >= $this->maxFailures) {
                    $duration = min($this->lockoutSeconds * (2 ** $lockouts), self::MAX_LOCKOUT_SECONDS);
                    $lockedUntil = $now + $duration;
                    $lockouts++;
                    $failures = 0;
                }
                return [
                    'failures'    => $failures,
                    'lockouts'    => $lockouts,
                    'lockedUntil' => $lockedUntil,
                    'updatedAt'   => $now,
                ];
            });
        }
    }

    public function reset(string $ip, string $username): void
    {
        foreach ($this->keys($ip, $username) as $key) {
            $this->file($key)->delete();
        }
    }

    /** Removes records idle for longer than the maximum lockout. Returns how many were deleted. */
    public function gc(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $deleted = 0;
        $cutoff = $this->clock->now() - self::MAX_LOCKOUT_SECONDS;
        foreach (glob($this->dir . '/*.json') ?: [] as $path) {
            $file = new LockedJsonFile($path);
            $record = $file->read();
            $updatedAt = (int)($record['updatedAt'] ?? 0);
            $lockedUntil = (int)($record['lockedUntil'] ?? 0);
            if ($updatedAt < $cutoff && $lockedUntil < $this->clock->now()) {
                $file->delete();
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @return list<string> */
    private function keys(string $ip, string $username): array
    {
        return ['ip:' . $ip, 'user:' . strtolower($username)];
    }

    private function file(string $key): LockedJsonFile
    {
        return new LockedJsonFile($this->dir . '/' . hash('sha256', $key) . '.json');
    }
}
