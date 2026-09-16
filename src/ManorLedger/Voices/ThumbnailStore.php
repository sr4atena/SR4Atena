<?php
/**
 * Thumbnails downloaded once and served from our own host.
 *
 * The browser must never contact YouTube: that is what keeps the CSP at
 * `img-src 'self' data:` and stops a third party from seeing who is reading
 * the dashboard. Files are 0644 because the web process runs as a different
 * user than this job.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use ManorLedger\Roblox\CurlTransport;
use ManorLedger\Storage\JsonStore;

final class ThumbnailStore
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';
    private const MAX_BYTES = 2_000_000;

    private Closure $transport;

    /** @param ?callable $transport fn(list<array> $requests): list<array> */
    public function __construct(private readonly string $dir, ?callable $transport = null)
    {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : Closure::fromCallable(new CurlTransport());
    }

    public function path(string $videoId): string
    {
        return $this->dir . '/' . $videoId . '.jpg';
    }

    public function has(string $videoId): bool
    {
        return preg_match(self::ID_PATTERN, $videoId) === 1 && is_file($this->path($videoId));
    }

    /**
     * Downloads the missing thumbnails of a set of videos in one wave.
     *
     * @param  list<array{id: string, thumbnailUrl?: string}> $videos
     * @return array<string, bool> id => available locally afterwards
     */
    public function store(array $videos): array
    {
        JsonStore::ensureDir($this->dir);
        @chmod($this->dir, 0755);
        $result = [];
        $wanted = [];
        foreach ($videos as $video) {
            $id = (string)($video['id'] ?? '');
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }
            if ($this->has($id)) {
                $result[$id] = true;
                continue;
            }
            $url = (string)($video['thumbnailUrl'] ?? '');
            $result[$id] = false;
            if (str_starts_with($url, 'https://')) {
                $wanted[$id] = ['method' => 'GET', 'url' => $url, 'headers' => [], 'body' => null,
                                'timeout' => 30, 'connectTimeout' => 10];
            }
        }
        if ($wanted === []) {
            return $result;
        }
        $responses = ($this->transport)(array_values($wanted));
        foreach (array_keys($wanted) as $index => $id) {
            $response = $responses[$index] ?? [];
            $body = (string)($response['body'] ?? '');
            if ((int)($response['status'] ?? 0) === 200 && self::isJpeg($body)) {
                $result[$id] = $this->writeJpeg($id, $body);
            }
        }

        return $result;
    }

    /** Removes thumbnails no longer in the list and older than the retention window. */
    public function prune(array $keepIds, int $days = 30): int
    {
        $cutoff = time() - $days * 86400;
        $removed = 0;
        foreach (glob($this->dir . '/*.jpg') ?: [] as $file) {
            $id = basename($file, '.jpg');
            if (in_array($id, $keepIds, true) || (int)filemtime($file) > $cutoff) {
                continue;
            }
            if (unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** JPEG magic bytes: whatever the remote host returns, only a real JPEG is written. */
    public static function isJpeg(string $bytes): bool
    {
        return strlen($bytes) > 1024 && strlen($bytes) <= self::MAX_BYTES && str_starts_with($bytes, "\xFF\xD8\xFF");
    }

    private function writeJpeg(string $videoId, string $bytes): bool
    {
        $path = $this->path($videoId);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);

            return false;
        }
        chmod($tmp, 0644);

        return rename($tmp, $path);
    }
}
