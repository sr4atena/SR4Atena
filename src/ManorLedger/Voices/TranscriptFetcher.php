<?php
/**
 * Captions for one video, obtained by running `tools/transcript.py`.
 *
 * The Python side never fails: it prints one JSON object and exits 0, so this
 * class only has to parse and time out. Five of the ten videos measured on
 * 2026-09-16 have captions disabled at the source — `missing` is the normal
 * answer for half the list, not an error to report.
 *
 * YouTube also throttles rapid repeated caption requests from one IP: the
 * richest video of the 2026-09-16 set came back `error` inside a burst of ten
 * and `ok` when asked on its own. Requests are therefore spaced out and
 * transient failures retried; `missing` is never retried, because captions
 * disabled by the creator is a permanent property of the video.
 *
 * Results are cached on disk because a published video's captions do not
 * change, and because it lets the transcripts be fetched from a host that
 * YouTube serves (the workstation) even when PHP runs elsewhere.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use ManorLedger\Storage\JsonStore;

final class TranscriptFetcher
{
    public const STATUSES = ['ok', 'missing', 'blocked', 'error'];
    /** Statuses that describe the video rather than the moment, and can be cached. */
    public const PERMANENT = ['ok', 'missing'];
    private const MAX_CHARS = 20000;
    private const BACKOFF = 5.0;

    private Closure $runner;
    private Closure $sleep;
    private float $lastRunAt = 0.0;

    /** @param ?callable $runner fn(string $python, string $script, string $id, int $timeout): array{out: string, code: int} */
    public function __construct(
        private readonly string $python,
        private readonly string $script,
        private readonly string $cacheDir,
        private readonly int $timeout = 60,
        ?callable $runner = null,
        private readonly float $minInterval = 0.0,
        private readonly int $maxTries = 3,
        ?callable $sleep = null,
    ) {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : Closure::fromCallable(self::run(...));
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static fn (float $s) => usleep((int)($s * 1e6));
    }

    /**
     * Preflight: null when the tool can run, otherwise why it cannot. A venv
     * without the dependency answers `error` for every video, which looks like
     * ten broken videos and is in fact one broken environment.
     */
    public function unavailableReason(): ?string
    {
        if (!is_file($this->script)) {
            return 'transcript script not found: ' . $this->script;
        }
        if (!is_executable($this->python)) {
            return 'python interpreter not executable: ' . $this->python . ' (make voices-venv)';
        }
        $result = ($this->runner)($this->python, $this->script, '--check', min(30, $this->timeout));
        $decoded = json_decode(trim($result['out']), true);
        if (($decoded['status'] ?? '') === 'ok') {
            return null;
        }

        return (string)($decoded['error'] ?? 'unusable transcript tool (exit ' . $result['code'] . ')');
    }

    /**
     * @return array{status: string, language: ?string, generated: bool, chars: int, text: string}
     */
    public function fetch(string $videoId, bool $useCache = true): array
    {
        $store = new JsonStore($this->cacheDir . '/' . $videoId . '.json');
        if ($useCache) {
            $cached = $store->read();
            if ($cached !== null && in_array($cached['status'] ?? '', self::STATUSES, true)) {
                return self::normalise($cached);
            }
        }
        if (!is_file($this->script) || !is_executable($this->python)) {
            return self::normalise(['status' => 'error',
                'error' => 'cannot run ' . $this->python . ' ' . $this->script . ' (make voices-venv)']);
        }
        $transcript = ['status' => 'error', 'language' => null, 'generated' => false, 'chars' => 0, 'text' => ''];
        for ($try = 1; $try <= max(1, $this->maxTries); $try++) {
            $this->pace();
            $result = ($this->runner)($this->python, $this->script, $videoId, $this->timeout);
            $decoded = json_decode(trim($result['out']), true);
            $transcript = self::normalise(is_array($decoded)
                ? $decoded
                : ['status' => 'error', 'error' => 'unparsable output (exit ' . $result['code'] . ')']);
            // Only a permanent answer is cached: a throttled minute must not
            // poison the cache, and a disabled caption track will not change.
            if (in_array($transcript['status'], self::PERMANENT, true)) {
                $store->write($transcript);

                return $transcript;
            }
            if ($try < $this->maxTries) {
                ($this->sleep)(self::BACKOFF * (2 ** ($try - 1)));
            }
        }

        return $transcript;
    }

    /** YouTube throttles a burst of caption requests from one IP: do not send one. */
    private function pace(): void
    {
        $due = $this->lastRunAt + $this->minInterval - microtime(true);
        if ($due > 0) {
            ($this->sleep)($due);
        }
        $this->lastRunAt = microtime(true);
    }

    /** @param array<string, mixed> $raw */
    private static function normalise(array $raw): array
    {
        $status = in_array($raw['status'] ?? '', self::STATUSES, true) ? (string)$raw['status'] : 'error';
        $text = $status === 'ok' ? trim((string)($raw['text'] ?? '')) : '';
        if ($text === '') {
            $status = $status === 'ok' ? 'missing' : $status;
        }
        $text = mb_substr($text, 0, self::MAX_CHARS, 'UTF-8');

        return [
            'status'    => $status,
            'language'  => isset($raw['language']) && $raw['language'] !== '' ? (string)$raw['language'] : null,
            'generated' => (bool)($raw['generated'] ?? false),
            'chars'     => mb_strlen($text, 'UTF-8'),
            'text'      => $text,
        ];
    }

    /**
     * proc_open rather than exec: the arguments are passed as an argv list, so
     * a video id can never be interpreted by a shell.
     *
     * @return array{out: string, code: int}
     */
    private static function run(string $python, string $script, string $videoId, int $timeout): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$python, $script, $videoId], $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['out' => '', 'code' => -1];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $out .= (string)stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['out' => $out, 'code' => (int)$status['exitcode']];
            }
            usleep(50000);
        }
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['out' => $out, 'code' => -1];
    }
}
