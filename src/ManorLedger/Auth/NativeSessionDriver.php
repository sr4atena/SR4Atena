<?php
declare(strict_types=1);

namespace ManorLedger\Auth;

use RuntimeException;

final class NativeSessionDriver implements SessionDriver
{
    /** @var array{path: string, secure: bool, httponly: bool, samesite: string} */
    private array $cookie = ['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'];
    private string $name = '';

    public function start(string $name, string $savePath, int $gcMaxLifetime, array $cookie): void
    {
        if ($this->isStarted()) {
            return;
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException("Cannot start session: headers already sent at {$file}:{$line}");
        }
        if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) {
            throw new RuntimeException("Cannot create session directory {$savePath}");
        }
        $this->cookie = $cookie;
        $this->name = $name;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.gc_maxlifetime', (string)$gcMaxLifetime);
        ini_set('session.save_handler', 'files');
        session_save_path($savePath);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $cookie['path'],
            'domain'   => '',
            'secure'   => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
        if (!session_start()) {
            throw new RuntimeException('session_start() failed');
        }
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function id(): string
    {
        return session_id();
    }

    public function regenerate(): void
    {
        $this->assertStarted();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->assertStarted();
        $_SESSION = [];
        session_destroy();
        // session_destroy() does not touch the client: expire the cookie explicitly.
        setcookie($this->name, '', [
            'expires'  => 1,
            'path'     => $this->cookie['path'],
            'domain'   => '',
            'secure'   => $this->cookie['secure'],
            'httponly' => $this->cookie['httponly'],
            'samesite' => $this->cookie['samesite'],
        ]);
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    private function assertStarted(): void
    {
        if (!$this->isStarted()) {
            throw new RuntimeException('Session is not started');
        }
    }
}
