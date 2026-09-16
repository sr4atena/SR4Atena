<?php
/**
 * Login orchestration: throttle → lookup → password → (TOTP) → session → audit.
 *
 * The TOTP step is a separate request. After a correct password the username
 * is parked in the session for a few minutes, so the second form posts only
 * the code and the password never has to be echoed back into HTML.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use ManorLedger\Support\Clock;

final class Authenticator
{
    private const PENDING_KEY = 'totpPending';
    private const LAST_STEP_KEY = 'totpLastStep';
    private const PENDING_TTL = 300;

    public function __construct(
        private readonly UserStore $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly AuditLog $audit,
        private readonly Totp $totp,
        private readonly Clock $clock,
    ) {
    }

    public function attempt(string $username, string $password, string $ip): AuthResult
    {
        $username = strtolower(trim($username));
        $this->session->remove(self::PENDING_KEY);

        $remaining = $this->throttle->check($ip, $username);
        if ($remaining !== null) {
            $this->audit->log('login.locked', ['user' => $username, 'ip' => $ip, 'retryAfter' => $remaining]);
            return AuthResult::locked($remaining);
        }

        // find() returns null for malformed names too; verify() still burns a full hash in that case.
        $user = $this->users->find($username);
        $valid = $this->hasher->verify($password, $user['hash'] ?? null) && $user !== null;
        if (!$valid) {
            return $this->failure($ip, $username, 'password');
        }

        if ($this->hasher->needsRehash((string)$user['hash'])) {
            $this->users->updatePassword($username, $this->hasher->hash($password));
        }

        if (is_string($user['totpSecret'] ?? null) && $user['totpSecret'] !== '') {
            $this->session->set(self::PENDING_KEY, [
                'username' => $username,
                'expires'  => $this->clock->now() + self::PENDING_TTL,
            ]);
            $this->audit->log('login.totp_required', ['user' => $username, 'ip' => $ip]);
            return AuthResult::needsTotp();
        }

        return $this->complete($user, $ip);
    }

    public function completeTotp(string $code, string $ip): AuthResult
    {
        $pending = $this->session->get(self::PENDING_KEY);
        $username = is_array($pending) ? (string)($pending['username'] ?? '') : '';
        $expires = is_array($pending) ? (int)($pending['expires'] ?? 0) : 0;
        if ($username === '' || $expires <= $this->clock->now()) {
            $this->session->remove(self::PENDING_KEY);
            return AuthResult::invalid();
        }

        $remaining = $this->throttle->check($ip, $username);
        if ($remaining !== null) {
            return AuthResult::locked($remaining);
        }

        $user = $this->users->find($username);
        $secret = is_array($user) ? ($user['totpSecret'] ?? null) : null;
        if (!is_string($secret) || $secret === '') {
            $this->session->remove(self::PENDING_KEY);
            return AuthResult::invalid();
        }

        // The newest accepted step is tracked both in the session and on the user record,
        // so a code observed once cannot be replayed after a logout either.
        $sessionStep = $this->session->get(self::LAST_STEP_KEY);
        $storedStep = $user['totpLastStep'] ?? null;
        $lastStep = max(is_int($sessionStep) ? $sessionStep : -1, is_int($storedStep) ? $storedStep : -1);
        $step = $this->totp->verify($secret, trim($code), $lastStep >= 0 ? $lastStep : null);
        if ($step === null) {
            return $this->failure($ip, $username, 'totp');
        }

        $result = $this->complete($user, $ip, $step);
        // Written after login() so it survives the session reset.
        $this->session->set(self::LAST_STEP_KEY, $step);
        return $result;
    }

    public function hasPendingTotp(): bool
    {
        $pending = $this->session->get(self::PENDING_KEY);
        return is_array($pending) && (int)($pending['expires'] ?? 0) > $this->clock->now();
    }

    public function logout(string $ip): void
    {
        $user = $this->session->user();
        $this->session->logout();
        if ($user !== null) {
            $this->audit->log('logout', ['user' => $user['username'], 'ip' => $ip]);
        }
    }

    /** @param array<string, mixed> $user */
    private function complete(array $user, string $ip, ?int $totpStep = null): AuthResult
    {
        $username = (string)$user['username'];
        $this->session->login($user);
        $this->throttle->reset($ip, $username);
        $this->users->recordLogin($username, $totpStep);
        $this->audit->log('login.ok', ['user' => $username, 'ip' => $ip, 'role' => (string)$user['role']]);
        return AuthResult::ok($user);
    }

    private function failure(string $ip, string $username, string $reason): AuthResult
    {
        $this->throttle->fail($ip, $username);
        $this->audit->log('login.fail', ['user' => $username, 'ip' => $ip, 'reason' => $reason]);
        $remaining = $this->throttle->check($ip, $username);
        if ($remaining !== null) {
            $this->audit->log('login.lockout', ['user' => $username, 'ip' => $ip, 'seconds' => $remaining]);
            return AuthResult::locked($remaining);
        }
        return AuthResult::invalid();
    }
}
