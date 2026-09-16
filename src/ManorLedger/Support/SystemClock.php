<?php
/**
 * The production Clock: the one place allowed to call time(). Everything else
 * receives a Clock, so no throttle window, session timeout or TOTP step can
 * read the wall clock behind a test's back.
 */
declare(strict_types=1);

namespace ManorLedger\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
