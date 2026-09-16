<?php
/**
 * Immutable snapshot of the incoming request. Proxy headers are trusted only
 * when the TCP peer is loopback, i.e. the request arrived through cloudflared
 * or nginx on this host; anything else could have set them itself.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

final class Request
{
    /** @var array<string, string> lower-case header names */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $headers = [],
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string)$name)] = (string)$value;
        }
        $this->headers = $normalised;
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string)$key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string)$key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return new self(
            strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            $headers,
            $_GET,
            $_POST,
            $_COOKIE,
            $server,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Form field as a string, or null when absent or not a scalar (arrays are never accepted). */
    public function post(string $name): ?string
    {
        $value = $this->post[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function server(string $key): ?string
    {
        $value = $this->server[$key] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }

    public function remoteAddr(): string
    {
        return $this->server('REMOTE_ADDR') ?? '0.0.0.0';
    }

    public function isFromLoopback(): bool
    {
        return self::isLoopback($this->remoteAddr());
    }

    /**
     * CF-Connecting-IP (then X-Real-IP, set by nginx) is honoured only when the
     * peer is loopback. Anywhere else REMOTE_ADDR is the truth.
     */
    public function clientIp(): string
    {
        $remote = $this->remoteAddr();
        if (!self::isLoopback($remote)) {
            return $remote;
        }
        foreach (['cf-connecting-ip', 'x-real-ip'] as $name) {
            $candidate = trim($this->headers[$name] ?? '');
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return $remote;
    }

    public function isHttps(): bool
    {
        $https = strtolower($this->server('HTTPS') ?? '');
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ($this->server('SERVER_PORT') === '443') {
            return true;
        }
        if ($this->isFromLoopback()) {
            $proto = strtolower(trim(explode(',', $this->header('X-Forwarded-Proto') ?? '')[0]));
            return $proto === 'https';
        }
        return false;
    }

    /** Host header without port, lower-case, or null when missing/invalid. */
    public function host(): ?string
    {
        $raw = $this->header('Host') ?? '';
        $host = parse_url('http://' . $raw, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public static function isLoopback(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.');
    }
}
