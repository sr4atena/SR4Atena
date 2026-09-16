<?php
declare(strict_types=1);

namespace ManorLedger\Auth;

/** In-memory driver for tests and CLI contexts. Records what a real driver would have done. */
final class ArraySessionDriver implements SessionDriver
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $started = false;
    private int $generation = 0;
    public int $regenerations = 0;
    public int $destroys = 0;
    public ?string $startedName = null;
    /** @var array{path: string, secure: bool, httponly: bool, samesite: string}|null */
    public ?array $startedCookie = null;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function start(string $name, string $savePath, int $gcMaxLifetime, array $cookie): void
    {
        $this->started = true;
        $this->startedName = $name;
        $this->startedCookie = $cookie;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        return 'test-session-' . $this->generation;
    }

    public function regenerate(): void
    {
        $this->generation++;
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
        $this->destroys++;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
