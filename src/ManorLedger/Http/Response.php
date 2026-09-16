<?php
/**
 * Value object for the outgoing response. Bodies are strings, except for
 * files, which are streamed at send() time so dashboard.json never has to be
 * loaded into memory twice.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

use RuntimeException;

final class Response
{
    /** @var array<string, string> canonical-case header names */
    private array $headers = [];
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(private int $status = 200, array $headers = [], private string $body = '')
    {
        foreach ($headers as $name => $value) {
            $this->headers[self::canonical($name)] = $value;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($status, ['Content-Type' => 'application/json; charset=UTF-8'], $body);
    }

    /** Only same-site paths are ever used as targets, so an absolute URL is a bug. */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new RuntimeException('Redirects must target an absolute path on this host');
        }
        return new self($status, ['Location' => $path, 'Content-Type' => 'text/html; charset=UTF-8'], '');
    }

    /** Streams a file with ETag / Last-Modified and answers 304 to a matching conditional request. */
    public static function file(string $path, string $contentType, ?Request $request = null): self
    {
        clearstatcache(true, $path); // bin/build may have replaced the file during this process's lifetime
        if (!is_file($path) || ($stat = stat($path)) === false) {
            throw new RuntimeException("File not found: {$path}");
        }
        $etag = '"' . hash('sha256', $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino']) . '"';
        $lastModified = gmdate('D, d M Y H:i:s \G\M\T', (int)$stat['mtime']);
        $headers = ['ETag' => $etag, 'Last-Modified' => $lastModified, 'Content-Type' => $contentType];
        if ($request !== null && self::isNotModified($request, $etag, (int)$stat['mtime'])) {
            return new self(304, $headers, '');
        }
        $response = new self(200, $headers + ['Content-Length' => (string)$stat['size']]);
        $response->filePath = $path;
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[self::canonical($name)] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[self::canonical($name)]);
    }

    public function header(string $name): ?string
    {
        return $this->headers[self::canonical($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function send(): void
    {
        header_remove('X-Powered-By'); // expose_php cannot be changed at runtime; do not advertise the PHP version
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }

    private static function isNotModified(Request $request, string $etag, int $mtime): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null) {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                    return true;
                }
            }
            return false;
        }
        $ifModifiedSince = $request->header('If-Modified-Since');
        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);
            return $since !== false && $since >= $mtime;
        }
        return false;
    }

    private static function canonical(string $name): string
    {
        $lower = strtolower($name);
        return $lower === 'etag' ? 'ETag' : implode('-', array_map('ucfirst', explode('-', $lower)));
    }
}
