<?php
/**
 * One JSON document on disk, written atomically.
 *
 * Every runtime file (history, dashboard, users) goes through this class so
 * the durability rules live in one place: temp file + rename under an
 * exclusive lock, 0600 on the file, 0700 on a freshly created directory.
 * A reader never sees a half-written document, and two writers cannot
 * interleave.
 */
declare(strict_types=1);

namespace ManorLedger\Storage;

use RuntimeException;

final class JsonStore
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Null when the file is missing, unreadable or not a JSON object/array. */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function mtime(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }
        $mtime = filemtime($this->path);

        return $mtime === false ? null : $mtime;
    }

    public function write(array $data): void
    {
        $json = json_encode($data, self::JSON_FLAGS);
        if ($json === false) {
            throw new RuntimeException('Cannot encode JSON for ' . $this->path . ': ' . json_last_error_msg());
        }
        $this->writeRaw($json);
    }

    /**
     * Shared with Snapshots, which stores gzip bytes with the same guarantees.
     * The lock file sits next to the target so it survives the rename.
     */
    public function writeRaw(string $bytes): void
    {
        $dir = dirname($this->path);
        self::ensureDir($dir);

        $lockPath = $this->path . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open lock file ' . $lockPath);
        }
        chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock ' . $lockPath);
            }
            $tmp = tempnam($dir, '.' . basename($this->path) . '.');
            if ($tmp === false) {
                throw new RuntimeException('Cannot create temp file in ' . $dir);
            }
            try {
                if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new RuntimeException('Short write to ' . $tmp);
                }
                chmod($tmp, 0600);
                if (!rename($tmp, $this->path)) {
                    throw new RuntimeException('Cannot rename ' . $tmp . ' to ' . $this->path);
                }
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory ' . $dir);
        }
    }
}
