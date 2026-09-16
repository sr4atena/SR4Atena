<?php
/**
 * Small JSON document with atomic read-modify-write.
 *
 * The lock lives in a sidecar file rather than on the document itself: the
 * document is replaced by rename(), so a waiter that opened the old inode
 * would otherwise acquire a lock on an orphan and lose the update.
 */
declare(strict_types=1);

namespace ManorLedger\Support;

use RuntimeException;

final class LockedJsonFile
{
    public function __construct(
        private readonly string $path,
        private readonly int $fileMode = 0600,
        private readonly int $dirMode = 0700,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<mixed> */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Applies $mutator to the current document under an exclusive lock and
     * persists its return value. Returning null leaves the file untouched.
     *
     * @param callable(array<mixed>): (array<mixed>|null) $mutator
     * @return array<mixed> the document after mutation
     */
    public function update(callable $mutator): array
    {
        $this->ensureDirectory();
        $lockPath = $this->path . '.lock';
        $isNew = !is_file($lockPath);
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException("Cannot open lock for {$this->path}");
        }
        if ($isNew) {
            chmod($lockPath, $this->fileMode);
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Cannot lock {$this->path}");
            }
            $current = $this->read();
            $next = $mutator($current);
            if ($next === null) {
                return $current;
            }
            $this->writeAtomically($next);
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(): void
    {
        foreach ([$this->path, $this->path . '.lock'] as $file) {
            if (is_file($file) && !@unlink($file) && is_file($file)) {
                throw new RuntimeException("Cannot delete {$file}");
            }
        }
    }

    /** @param array<mixed> $document */
    private function writeAtomically(array $document): void
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$tmp}");
        }
        chmod($tmp, $this->fileMode);
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace {$this->path}");
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, $this->dirMode, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }
}
