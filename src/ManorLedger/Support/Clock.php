<?php
declare(strict_types=1);

namespace ManorLedger\Support;

/**
 * Time source injected everywhere time matters (throttle, sessions, TOTP)
 * so tests can freeze and advance it deterministically.
 */
interface Clock
{
    /** Unix timestamp in seconds. */
    public function now(): int;
}
