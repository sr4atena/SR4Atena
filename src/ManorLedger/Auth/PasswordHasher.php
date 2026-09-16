<?php
/**
 * Argon2id with the parameters fixed by ARCHITECTURE.md; bcrypt only when the
 * runtime was built without argon2 (the local LAMPP PHP). Verification for an
 * unknown user runs against a fixed dummy hash of the same algorithm so the
 * response time does not reveal whether the username exists.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use RuntimeException;

final class PasswordHasher
{
    private const ARGON2_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];
    private const BCRYPT_OPTIONS = ['cost' => 12];

    /** Hashes of a discarded random password; the plaintext no longer exists anywhere. */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$Qk5HS1VlMUNkR0pHTkhraA$n+7GzVuK2xNDgs4gefdmZrdQFaPNP4mN3ha9+sHybss';
    private const DUMMY_BCRYPT = '$2y$12$yGUXrnIknbVLhMj020NQEOIhUgm3k114MWBeDl8j6IRKE3mWEVlTK';

    private readonly string $algorithm;

    /** @param string|null $algorithm PASSWORD_ARGON2ID or PASSWORD_BCRYPT; null picks the strongest available. */
    public function __construct(?string $algorithm = null)
    {
        if ($algorithm === null) {
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        }
        if ($algorithm === 'argon2id' && !defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException('argon2id is not available in this PHP build');
        }
        if ($algorithm !== 'argon2id' && $algorithm !== PASSWORD_BCRYPT) {
            throw new RuntimeException("Unsupported password algorithm: {$algorithm}");
        }
        $this->algorithm = $algorithm;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function usesArgon2id(): bool
    {
        return $this->algorithm === 'argon2id';
    }

    public function hash(string $password): string
    {
        if ($password === '') {
            throw new RuntimeException('Refusing to hash an empty password');
        }
        return password_hash($password, $this->algorithm, $this->options());
    }

    /**
     * Constant-time-ish verification. A null hash (unknown user) still costs a
     * full verify against the dummy hash and always returns false.
     */
    public function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($password, $this->dummyHash());
            return false;
        }
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options());
    }

    public function dummyHash(): string
    {
        return $this->usesArgon2id() ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT;
    }

    /** @return array<string, int> */
    private function options(): array
    {
        return $this->usesArgon2id() ? self::ARGON2_OPTIONS : self::BCRYPT_OPTIONS;
    }
}
