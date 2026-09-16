<?php
/**
 * File-backed user directory (data/users.json, 0600). Tiny by design: a
 * single-tenant dashboard has a handful of accounts managed from the CLI.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use InvalidArgumentException;
use ManorLedger\Support\Clock;
use ManorLedger\Support\LockedJsonFile;
use RuntimeException;

final class UserStore
{
    public const ROLES = ['owner', 'viewer'];
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    private readonly LockedJsonFile $file;

    public function __construct(string $path, private readonly Clock $clock)
    {
        $this->file = new LockedJsonFile($path);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** @return array<string, mixed>|null */
    public function find(string $username): ?array
    {
        if (!self::isValidUsername($username)) {
            return null;
        }
        return $this->read()[$username] ?? null;
    }

    /** @return list<array<string, mixed>> sorted by username */
    public function all(): array
    {
        $users = array_values($this->read());
        usort($users, static fn (array $a, array $b): int => strcmp($a['username'], $b['username']));
        return $users;
    }

    /** @return array<string, mixed> the stored record */
    public function add(string $username, string $hash, string $role = 'viewer'): array
    {
        $this->assertUsername($username);
        $this->assertHash($hash);
        if (!self::isValidRole($role)) {
            throw new InvalidArgumentException('Role must be one of: ' . implode(', ', self::ROLES));
        }
        $record = [
            'username'    => $username,
            'hash'        => $hash,
            'role'        => $role,
            'totpSecret'  => null,
            'totpLastStep' => null,
            'createdAt'   => $this->timestamp(),
            'lastLoginAt' => null,
        ];
        $this->file->update(function (array $users) use ($username, $record): array {
            if (isset($users[$username])) {
                throw new RuntimeException("User already exists: {$username}");
            }
            $users[$username] = $record;
            return $users;
        });
        return $record;
    }

    public function updatePassword(string $username, string $hash): void
    {
        $this->assertHash($hash);
        $this->patch($username, ['hash' => $hash]);
    }

    /** @param string|null $secret base32 secret, or null to disable TOTP */
    public function setTotp(string $username, ?string $secret): void
    {
        if ($secret !== null && preg_match('/^[A-Z2-7]{16,}$/', $secret) !== 1) {
            throw new InvalidArgumentException('TOTP secret must be base32 without padding');
        }
        $this->patch($username, ['totpSecret' => $secret, 'totpLastStep' => null]);
    }

    /** @param int|null $totpStep last accepted TOTP step, kept so a code cannot be replayed across sessions */
    public function recordLogin(string $username, ?int $totpStep = null): void
    {
        $changes = ['lastLoginAt' => $this->timestamp()];
        if ($totpStep !== null) {
            $changes['totpLastStep'] = $totpStep;
        }
        $this->patch($username, $changes);
    }

    public function remove(string $username): void
    {
        $this->assertUsername($username);
        $this->file->update(function (array $users) use ($username): array {
            if (!isset($users[$username])) {
                throw new RuntimeException("Unknown user: {$username}");
            }
            unset($users[$username]);
            return $users;
        });
    }

    /** @param array<string, mixed> $changes */
    private function patch(string $username, array $changes): void
    {
        $this->assertUsername($username);
        $this->file->update(function (array $users) use ($username, $changes): array {
            if (!isset($users[$username])) {
                throw new RuntimeException("Unknown user: {$username}");
            }
            $users[$username] = array_merge($users[$username], $changes);
            return $users;
        });
    }

    /** @return array<string, array<string, mixed>> keyed by username; malformed entries are dropped */
    private function read(): array
    {
        $users = [];
        foreach ($this->file->read() as $key => $record) {
            if (is_array($record) && is_string($key) && self::isValidUsername($key)
                && isset($record['username'], $record['hash']) && $record['username'] === $key) {
                $users[$key] = $record;
            }
        }
        return $users;
    }

    private function assertUsername(string $username): void
    {
        if (!self::isValidUsername($username)) {
            throw new InvalidArgumentException('Username must be 3-32 chars of [a-z0-9._-]');
        }
    }

    private function assertHash(string $hash): void
    {
        if (password_get_info($hash)['algo'] === null) {
            throw new InvalidArgumentException('Value is not a password hash');
        }
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->clock->now());
    }
}
