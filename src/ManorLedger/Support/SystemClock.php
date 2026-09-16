<?php
declare(strict_types=1);

namespace ManorLedger\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
