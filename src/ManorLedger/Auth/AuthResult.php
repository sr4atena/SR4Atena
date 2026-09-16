<?php
declare(strict_types=1);

namespace ManorLedger\Auth;

/** Outcome of a login step. Deliberately coarse: the UI must not learn more than this. */
final readonly class AuthResult
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';
    public const NEEDS_TOTP = 'needsTotp';

    /** @param array{username: string, role: string}|null $user */
    private function __construct(
        public string $status,
        public ?array $user = null,
        public int $retryAfter = 0,
    ) {
    }

    /** @param array<string, mixed> $user */
    public static function ok(array $user): self
    {
        return new self(self::OK, ['username' => (string)$user['username'], 'role' => (string)$user['role']]);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(self::LOCKED, null, max(1, $retryAfter));
    }

    public static function needsTotp(): self
    {
        return new self(self::NEEDS_TOTP);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function retryAfterMinutes(): int
    {
        return (int)max(1, ceil($this->retryAfter / 60));
    }
}
