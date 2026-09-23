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
 * `blocked` is not retried either, and for the opposite reason: it describes
 * the *address*, not the video. If the address is refused, the next video will
 * be refused for the same reason, so retrying per video multiplies one refusal
 * by the videos left and by the tries allowed — useless work, and the surest
 * way to keep the refusal in place, since these limiters measure the last
 * request rather than the first. The first `blocked` answer therefore trips a
 * breaker: the rest of the run asks nothing at all, and a marker in the cache
 * directory keeps any run from asking again before the pause is over.
 *
 * The pause grows with each refusal in a row — 6 hours, then 12, then 24 and
 * 24 from there on — because a wall still standing after six hours is not a
 * six-hour wall. The count lives in the marker and survives the expiry of the
 * pause; the first answer that gets through (`ok` or `missing`) clears it.
 * Everything else degrades as before — the summaries of videos already seen
 * come from their own cache, so a refused night costs the transcripts of new
 * videos and nothing more.
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
    /**
     * The one file in the cache directory that is not a transcript: it says
     * until when this address is to stay away. A dot file, so the `*.json`
     * globs that count cached transcripts never see it.
     */
    public const BLOCK_MARKER = '.blocked.json';

    private Closure $runner;
    private Closure $sleep;
    private Closure $now;
    private float $lastRunAt = 0.0;
    private bool $blocked = false;
    private ?int $blockedUntil = null;
    /** Refusals in a row, as remembered by the marker; 0 when the last answer got through. */
    private int $streak = 0;
    /** @var ?array{streak: int, hours: float, until: int} set when this run tripped the breaker */
    private ?array $tripped = null;
    /** @var list<float> */
    private array $cooldownLadder;
    /** @var list<array{id: string, try: int, error: string}> every `error` answer of this run */
    private array $errors = [];
    /** What the script said about its last non-permanent answer, exception name first. */
    private ?string $lastError = null;

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
        /**
         * How long to stay away after a block: one number, or a ladder indexed
         * by the refusals in a row (the last step repeats). With the breaker
         * above, trying again costs exactly one request, so the pause is about
         * not being the address that keeps knocking.
         *
         * @var float|list<float>
         */
        float|array $blockCooldownHours = [6.0, 12.0, 24.0],
        ?callable $now = null,
    ) {
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : Closure::fromCallable(self::run(...));
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static fn (float $s) => usleep((int)($s * 1e6));
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
        $ladder = array_values(array_map(static fn ($h): float => max(0.0, (float)$h), (array)$blockCooldownHours));
        $this->cooldownLadder = $ladder === [] ? [6.0] : $ladder;
        $this->readMarker();
    }

    /** True when this address is serving nothing until the cooldown expires. */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /** Unix time the block was set to expire, or null when there is none. */
    public function blockedUntil(): ?int
    {
        return $this->blockedUntil;
    }

    /**
     * What this run's refusal set, or null when this run met no refusal: the
     * refusals in a row, the pause chosen for it and when it ends.
     *
     * @return ?array{streak: int, hours: float, until: int, error: string}
     */
    public function tripped(): ?array
    {
        return $this->tripped;
    }

    /**
     * Every `error` answer of this run, one per attempt, including those a
     * later attempt recovered from. Not a reason to stop — errors are retried —
     * but worth telling someone about, because a new kind of refusal that the
     * script does not recognise yet would show up here first.
     *
     * @return list<array{id: string, try: int, error: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The script's own words for the last answer that was not `ok`/`missing`,
     * e.g. "IpBlocked: …" or "AgeRestricted: …". `blocked` covers both the
     * address and a few video-level walls, and only this tells them apart.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Forget the block and ask again. Only worth doing once something outside
     * this code has established that YouTube is answering again.
     */
    public function clearBlock(): void
    {
        $this->blocked = false;
        $this->blockedUntil = null;
        $this->streak = 0;
        @unlink($this->cacheDir . '/' . self::BLOCK_MARKER);
    }

    /**
     * Preflight: null when the tool can run, otherwise why it cannot. A venv
     * without the dependency answers `error` for every video, which looks like
     * ten broken videos and is in fact one broken environment.
     */
    public function unavailableReason(): ?string
    {
        if ($this->blocked) {
            // The hour in this sentence is ours, not YouTube's. YouTube refuses
            // without a word and never says for how long, so the deadline is our
            // own cooldown and must not read like a promise from them.
            return 'YouTube refused caption requests from this address and does not say for how long; '
                . 'our own cooldown holds until ' . gmdate('Y-m-d H:i', (int)$this->blockedUntil)
                . ' UTC (bin/voices --clear-block to ask before that)';
        }
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
        // The breaker. Nothing is going to get through, and every request that
        // does not get through is a reason for the block to last longer.
        if ($this->blocked) {
            return self::normalise(['status' => 'blocked',
                'error' => 'not asked: our cooldown after a refusal holds until '
                    . gmdate('Y-m-d H:i', (int)$this->blockedUntil) . ' UTC']);
        }
        if (!is_file($this->script) || !is_executable($this->python)) {
            $why = 'cannot run ' . $this->python . ' ' . $this->script . ' (make voices-venv)';
            $this->errors[] = ['id' => $videoId, 'try' => 0, 'error' => $why];

            return self::normalise(['status' => 'error', 'error' => $why]);
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
            if (!in_array($transcript['status'], self::PERMANENT, true)) {
                $this->lastError = is_array($decoded)
                    ? (string)($decoded['error'] ?? $transcript['status'])
                    : 'unparsable output (exit ' . $result['code'] . ')';
            }
            if (in_array($transcript['status'], self::PERMANENT, true)) {
                $store->write($transcript);
                // YouTube answered: whatever the count of refusals was, it ends here.
                if ($this->streak > 0) {
                    $this->streak = 0;
                    @unlink($this->cacheDir . '/' . self::BLOCK_MARKER);
                }

                return $transcript;
            }
            // A refusal is about the address: the next video would be refused
            // for the same reason, so stop here rather than ask again.
            if ($transcript['status'] === 'blocked') {
                $this->trip();

                return $transcript;
            }
            $this->errors[] = ['id' => $videoId, 'try' => $try, 'error' => (string)$this->lastError];
            if ($try < $this->maxTries) {
                ($this->sleep)(self::BACKOFF * (2 ** ($try - 1)));
            }
        }

        return $transcript;
    }

    /**
     * Raise the breaker and write it down, so that a job running twice in one
     * day does not spend its second run walking into the same wall.
     */
    private function trip(): void
    {
        $this->streak++;
        $hours = $this->cooldownLadder[min($this->streak, count($this->cooldownLadder)) - 1];
        $this->blocked = true;
        $this->blockedUntil = ($this->now)() + (int)round($hours * 3600);
        $this->tripped = ['streak' => $this->streak, 'hours' => $hours, 'until' => $this->blockedUntil,
                          'error' => (string)$this->lastError];
        JsonStore::ensureDir($this->cacheDir);
        (new JsonStore($this->cacheDir . '/' . self::BLOCK_MARKER))->write([
            'at'     => gmdate('Y-m-d\TH:i:s\Z', ($this->now)()),
            'until'  => $this->blockedUntil,
            'streak' => $this->streak,
            'hours'  => $hours,
            'error'  => (string)$this->lastError,
        ]);
    }

    /**
     * A block left behind by an earlier run. The pause holds while it lasts;
     * the count of refusals holds after it, until an answer gets through.
     */
    private function readMarker(): void
    {
        $marker = (new JsonStore($this->cacheDir . '/' . self::BLOCK_MARKER))->read();
        if ($marker === null) {
            return;
        }
        $this->streak = max(1, (int)($marker['streak'] ?? 1));
        $until  = (int)($marker['until'] ?? 0);
        if ($until > ($this->now)()) {
            $this->blocked = true;
            $this->blockedUntil = $until;
        }
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
