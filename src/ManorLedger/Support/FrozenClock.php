<?php
declare(strict_types=1);

namespace ManorLedger\Support;

/** Deterministic clock for tests: stays put until advanced explicitly. */
final class FrozenClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $timestamp): void
    {
        $this->now = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
