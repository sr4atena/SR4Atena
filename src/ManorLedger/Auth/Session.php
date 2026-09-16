<?php
/**
 * Authenticated session on top of a SessionDriver. Timeouts are enforced
 * from timestamps stored inside the session, not from the cookie: a cookie
 * lifetime is client-controlled, the session record is not.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use ManorLedger\Http\Request;
use ManorLedger\Support\Clock;

final class Session
{
    private const KEY_USER = 'user';
    private const KEY_LOGIN_AT = 'loginAt';
    private const KEY_LAST_SEEN = 'lastSeen';
    private const KEY_UA = 'uaHash';
    private const KEY_CSRF = 'csrf';

    private ?Request $request = null;

    public function __construct(
        private readonly SessionDriver $driver,
        private readonly Clock $clock,
        private readonly string $savePath,
        private readonly int $idleTimeout,
        private readonly int $absoluteTimeout,
        private readonly string $cookieName = '__Host-manor_session',
    ) {
    }

    /** Idempotent. Starts the session and drops it when expired or bound to another client. */
    public function start(Request $request): void
    {
        if ($this->driver->isStarted()) {
            return;
        }
        $this->request = $request;
        $this->driver->start($this->cookieNameFor($request), $this->savePath, $this->absoluteTimeout, [
            'path'     => '/',
            'secure'   => $request->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if ($this->driver->get(self::KEY_USER) === null) {
            return;
        }
        if (!$this->isStillValid($request)) {
            // Invalidate without destroying: the visitor keeps a fresh anonymous session for the login form.
            $this->driver->clear();
            $this->driver->regenerate();
            return;
        }
        $this->driver->set(self::KEY_LAST_SEEN, $this->clock->now());
    }

    /**
     * Starts the session only when the client presents our cookie. Pages that
     * merely gate on "logged in?" use this so anonymous scans create no files.
     */
    public function resume(Request $request): bool
    {
        if ($this->driver->isStarted()) {
            return true;
        }
        if ($request->cookie($this->cookieNameFor($request)) === null) {
            return false;
        }
        $this->start($request);
        return true;
    }

    /**
     * The `__Host-` prefix is only honoured by browsers over HTTPS with the
     * Secure flag; plain-HTTP local development gets the bare name.
     */
    public function cookieNameFor(Request $request): string
    {
        if ($request->isHttps()) {
            return $this->cookieName;
        }
        return str_starts_with($this->cookieName, '__Host-') ? substr($this->cookieName, 7) : $this->cookieName;
    }

    /** @param array<string, mixed> $user record from UserStore (the hash is never copied) */
    public function login(array $user): void
    {
        $this->assertStarted();
        $now = $this->clock->now();
        $this->driver->clear();
        $this->driver->regenerate();
        $this->driver->set(self::KEY_USER, [
            'username' => (string)$user['username'],
            'role'     => (string)($user['role'] ?? 'viewer'),
        ]);
        $this->driver->set(self::KEY_LOGIN_AT, $now);
        $this->driver->set(self::KEY_LAST_SEEN, $now);
        $this->driver->set(self::KEY_UA, self::userAgentHash($this->request));
        $this->driver->set(self::KEY_CSRF, Csrf::generateToken());
    }

    public function logout(): void
    {
        if ($this->driver->isStarted()) {
            $this->driver->clear();
            $this->driver->destroy();
        }
    }

    /** @return array{username: string, role: string}|null */
    public function user(): ?array
    {
        $user = $this->driver->isStarted() ? $this->driver->get(self::KEY_USER) : null;
        return is_array($user) && isset($user['username'], $user['role']) ? $user : null;
    }

    public function csrfToken(): string
    {
        $this->assertStarted();
        $token = $this->driver->get(self::KEY_CSRF);
        if (!is_string($token) || $token === '') {
            $token = Csrf::generateToken();
            $this->driver->set(self::KEY_CSRF, $token);
        }
        return $token;
    }

    public function get(string $key): mixed
    {
        return $this->driver->isStarted() ? $this->driver->get($key) : null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertStarted();
        $this->driver->set($key, $value);
    }

    public function remove(string $key): void
    {
        if ($this->driver->isStarted()) {
            $this->driver->remove($key);
        }
    }

    /** Pure policy: idle timeout, absolute lifetime and user-agent binding. */
    public function isStillValid(Request $request): bool
    {
        $now = $this->clock->now();
        $loginAt = $this->driver->get(self::KEY_LOGIN_AT);
        $lastSeen = $this->driver->get(self::KEY_LAST_SEEN);
        $uaHash = $this->driver->get(self::KEY_UA);
        if (!is_int($loginAt) || !is_int($lastSeen) || !is_string($uaHash)) {
            return false;
        }
        if ($now - $loginAt >= $this->absoluteTimeout || $now - $lastSeen >= $this->idleTimeout) {
            return false;
        }
        return hash_equals($uaHash, self::userAgentHash($request));
    }

    public static function userAgentHash(?Request $request): string
    {
        return hash('sha256', $request?->header('User-Agent') ?? '');
    }

    private function assertStarted(): void
    {
        if (!$this->driver->isStarted()) {
            throw new \LogicException('Session::start() must be called first');
        }
    }
}
