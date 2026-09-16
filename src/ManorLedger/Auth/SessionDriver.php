<?php
declare(strict_types=1);

namespace ManorLedger\Auth;

/**
 * The only place that touches PHP's session_* functions, so Session's
 * timeout and binding logic can be unit-tested against an in-memory driver.
 */
interface SessionDriver
{
    /** @param array{path: string, secure: bool, httponly: bool, samesite: string} $cookie */
    public function start(string $name, string $savePath, int $gcMaxLifetime, array $cookie): void;

    public function isStarted(): bool;

    public function id(): string;

    /** Replaces the id and drops the old server-side record; data is kept. */
    public function regenerate(): void;

    /** Drops the server-side record and expires the cookie. */
    public function destroy(): void;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function clear(): void;
}
