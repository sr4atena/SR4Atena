<?php
/**
 * Append-only authentication log: one line per event, `key=value` pairs,
 * values sanitised so a crafted username cannot forge extra fields or lines.
 * Passwords, hashes, session ids and tokens must never be passed here.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use ManorLedger\Support\Clock;
use RuntimeException;

final class AuditLog
{
    private const MAX_VALUE_LENGTH = 96;

    public function __construct(private readonly string $path, private readonly Clock $clock)
    {
    }

    /** @param array<string, string|int|null> $fields */
    public function log(string $event, array $fields = []): void
    {
        $line = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()) . ' event=' . self::sanitize($event);
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $line .= ' ' . self::sanitizeKey((string)$key) . '=' . self::sanitize((string)$value);
        }
        $this->append($line . "\n");
    }

    public static function sanitize(string $value): string
    {
        $clean = preg_replace('/[^\x21-\x7e]+/', '_', $value) ?? '';
        $clean = str_replace(['=', '"', '\\'], '_', $clean);
        if (strlen($clean) > self::MAX_VALUE_LENGTH) {
            $clean = substr($clean, 0, self::MAX_VALUE_LENGTH) . '...';
        }
        return $clean === '' ? '-' : $clean;
    }

    private static function sanitizeKey(string $key): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_.]/', '', $key) ?? '';
        return $clean === '' ? 'field' : $clean;
    }

    private function append(string $line): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $isNew = !is_file($this->path);
        $handle = fopen($this->path, 'a');
        if ($handle === false) {
            throw new RuntimeException("Cannot open audit log {$this->path}");
        }
        try {
            if ($isNew) {
                chmod($this->path, 0600);
            }
            flock($handle, LOCK_EX);
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
