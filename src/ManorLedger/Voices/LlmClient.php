<?php
/**
 * One adapter for every model the feature can use, chosen by named profile.
 *
 * Four drivers (`gemini`, `ollama`, `openai`, `anthropic`) share one paced
 * retry-and-fallback path. Measured on 2026-09-16 (PLAN-voices §6): model ids
 * drift, so the id comes from configuration and a 404 naming a successor is
 * surfaced whole; and the free tier throttles a burst with 429/503, so pacing
 * and backoff are what keep the work on the better model. What counts as a
 * usable answer is the caller's business: `json()` takes a validator and
 * retries once with its complaint appended before moving down the chain.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use RuntimeException;

final class LlmClient
{
    private const RETRYABLE = [408, 409, 425, 429, 500, 502, 503, 504];
    /** Attempts per profile: one bad answer is not a verdict on the model. */
    private const ATTEMPTS = 3;
    /** 429 and 503 are the free tier saying "slow down", not "you are broken". */
    private const THROTTLED = [429, 503];
    private const THROTTLE_BACKOFF = 20.0;


    private Closure $transport;
    private Closure $sleep;
    private Closure $log;
    /** @var array<string, string> */
    private array $keys = [];
    /** @var array<string, float> profile => when its last request left */
    private array $lastCallAt = [];
    private int $calls = 0;

    /** @param array<string, array<string, mixed>> $profiles name => {driver, baseUrl, model, keyFile, timeout, fallback} */
    public function __construct(
        private readonly array $profiles,
        private readonly float $temperature = 0.2,
        private readonly int $maxRetries = 3,
        ?callable $transport = null,
        ?callable $sleep = null,
        ?callable $log = null,
        private readonly float $minInterval = 0.0,
    ) {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : self::defaultTransport();
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static fn (float $s) => usleep((int)($s * 1e6));
        $this->log = $log !== null ? Closure::fromCallable($log) : static fn (string $line): null => null;
    }

    /** Model calls actually sent, so `bin/voices` can prove a cached run makes none. */
    public function calls(): int
    {
        return $this->calls;
    }
    /**
     * @param  ?callable $validate fn(array $data): ?string — null when acceptable, else the correction to send back
     * @return array{data: array<string, mixed>, model: string, profile: string, fellBack: bool}
     */
    public function json(string $profile, string $system, string $user, ?callable $validate = null): array
    {
        $failures = [];
        foreach ($this->chain($profile) as $step => $name) {
            $prompt = $user;
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                try {
                    $answer = $this->call($name, $system, $prompt);
                } catch (RuntimeException $e) {
                    $failures[] = $name . ': ' . $e->getMessage();
                    ($this->log)('llm: profile ' . $name . ' unusable: ' . $e->getMessage());
                    continue 2;
                }
                $data = self::decodeObject($answer['text']);
                $problem = $data === null
                    ? 'La tua risposta precedente non era un oggetto JSON valido. Rispondi SOLO con l\'oggetto JSON richiesto.'
                    : ($validate !== null ? $validate($data) : null);
                if ($problem === null) {
                    return ['data' => $data, 'model' => $answer['model'], 'profile' => $name, 'fellBack' => $step > 0];
                }
                $failures[] = $name . ' attempt ' . $attempt . ': ' . $problem;
                ($this->log)('llm: ' . $name . ' output rejected (' . $problem . ')');
                $prompt = $user . "\n\nCORREZIONE OBBLIGATORIA: " . $problem;
            }
        }
        throw new RuntimeException('No usable answer for profile "' . $profile . '": ' . implode(' | ', $failures));
    }

    /** One call, retried on the transient statuses. @return array{text: string, model: string} */
    public function call(string $profile, string $system, string $user): array
    {
        $config = $this->profiles[$profile] ?? throw new RuntimeException('Unknown LLM profile: ' . $profile);
        $model  = (string)$config['model'];
        $last   = '';
        for ($try = 1; $try <= max(1, $this->maxRetries); $try++) {
            $this->pace($profile);
            $this->calls++;
            $response = ($this->transport)([$this->request($profile, $config, $system, $user)])[0] ?? [];
            $status   = (int)($response['status'] ?? 0);
            $body     = (string)($response['body'] ?? '');
            if ($status === 200 && $body !== '') {
                $json = json_decode($body, true);
                $text = $this->extract($config, $json);
                if ($text !== '') {
                    return ['text' => $text, 'model' => $model];
                }
                // A thinking model can spend its budget thinking, and a filtered
                // prompt comes back with no candidate at all: both say why here.
                $last = 'empty completion, reason ' . (is_array($json) ? (string)($json['candidates'][0]['finishReason']
                    ?? $json['promptFeedback']['blockReason'] ?? 'no candidate') : 'unparsable');
            } elseif ($status === 404) {
                // The API names the successor in the message; that is the fix, so surface it.
                throw new RuntimeException('model "' . $model . '" rejected with 404. ' . $this->apiMessage($profile, $body));
            } else {
                $last = 'HTTP ' . $status . ' ' . $this->apiMessage($profile, $body !== '' ? $body : (string)($response['error'] ?? ''));
                if (!in_array($status, self::RETRYABLE, true) && $status !== 0) {
                    throw new RuntimeException($last);
                }
            }
            if ($try < $this->maxRetries) {
                // A throttled tier says when to come back, in a header or in the
                // body ("retryDelay": "47s"): obey it rather than guess.
                $wait = (float)(array_change_key_case($response['headers'] ?? [])['retry-after'] ?? 0);
                if ($wait <= 0 && preg_match('/retry(?:Delay"?:\s*"|\s+in\s+)([\d.]+)/i', $body, $hint) === 1) {
                    $wait = (float)$hint[1];
                }
                $pause = $wait > 0 ? min(120.0, $wait + 1.0)
                    : (in_array($status, self::THROTTLED, true) ? self::THROTTLE_BACKOFF * (2 ** ($try - 1)) : 2.0 * $try);
                ($this->log)('llm: ' . $profile . ' ' . $last . ', waiting ' . round($pause, 1)
                    . ' s then retry ' . $try . '/' . ($this->maxRetries - 1));
                ($this->sleep)($pause);
            }
        }
        throw new RuntimeException('gave up after ' . $this->maxRetries . ' attempts: ' . $last);
    }

    /** Spacing between calls to one profile, for the day the tier throttles bursts. */
    private function pace(string $profile): void
    {
        $due = ($this->lastCallAt[$profile] ?? 0.0) + $this->minInterval - microtime(true);
        if ($due > 0) {
            ($this->sleep)($due);
        }
        $this->lastCallAt[$profile] = microtime(true);
    }

    /** @return list<string> the profile then its fallback, deduplicated */
    private function chain(string $profile): array
    {
        $chain = [];
        for ($name = $profile; $name !== '' && !in_array($name, $chain, true); $name = (string)($this->profiles[$name]['fallback'] ?? '')) {
            $chain[] = $name;
        }
        return $chain;
    }

    /** @param array<string, mixed> $config */
    private function request(string $name, array $config, string $system, string $user): array
    {
        $driver = (string)($config['driver'] ?? 'openai');
        $model  = (string)$config['model'];
        $base   = rtrim((string)$config['baseUrl'], '/') . '/';
        $key    = $this->key($name, $config);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        [$url, $payload] = match ($driver) {
            'gemini' => [
                $base . 'models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key),
                ['systemInstruction' => ['parts' => [['text' => $system]]],
                 'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                 'generationConfig' => ['temperature' => $this->temperature, 'responseMimeType' => 'application/json',
                                        'maxOutputTokens' => (int)($config['maxOutputTokens'] ?? 8192)]],
            ],
            'ollama' => [
                $base . 'api/chat',
                ['model' => $model, 'stream' => false, 'format' => 'json', 'options' => ['temperature' => $this->temperature],
                 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]],
            ],
            'anthropic' => [
                $base . 'v1/messages',
                ['model' => $model, 'max_tokens' => 4096, 'temperature' => $this->temperature, 'system' => $system,
                 'messages' => [['role' => 'user', 'content' => $user]]],
            ],
            default => [
                $base . 'chat/completions',
                ['model' => $model, 'temperature' => $this->temperature, 'response_format' => ['type' => 'json_object'],
                 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]],
            ],
        };
        $headers += match (true) {
            $driver === 'anthropic' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            $driver !== 'gemini' && $key !== '' => ['Authorization' => 'Bearer ' . $key],
            default => [],
        };
        return ['method' => 'POST', 'url' => $url, 'headers' => $headers, 'connectTimeout' => 10,
                'timeout' => (int)($config['timeout'] ?? 120),
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /** @param array<string, mixed> $config */
    private function extract(array $config, mixed $json): string
    {
        if (!is_array($json)) {
            return '';
        }
        return trim(match ((string)($config['driver'] ?? 'openai')) {
            'gemini' => implode('', array_map(
                static fn (array $p): string => (string)($p['text'] ?? ''),
                $json['candidates'][0]['content']['parts'] ?? [],
            )),
            'ollama' => (string)($json['message']['content'] ?? ''),
            'anthropic' => (string)($json['content'][0]['text'] ?? ''),
            default => (string)($json['choices'][0]['message']['content'] ?? ''),
        });
    }

    /** Decodes a JSON object, tolerating the ```json fences some models emit. */
    public static function decodeObject(string $text): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text) ?? $text);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $config */
    private function key(string $name, array $config): string
    {
        $file = $config['keyFile'] ?? null;
        if ($file === null || $file === '') {
            return '';
        }
        if (!isset($this->keys[$name])) {
            // Never validated by prefix: the current Google key is not an AIza… one.
            $raw = @file_get_contents((string)$file);
            if ($raw === false || trim($raw) === '') {
                throw new RuntimeException('Cannot read the API key for profile "' . $name . '" (' . $file . ')');
            }
            $this->keys[$name] = trim($raw);
        }
        return $this->keys[$name];
    }

    /** Short, key-free rendering of whatever the provider said. */
    private function apiMessage(string $profile, string $body): string
    {
        $json = json_decode($body, true);
        $message = is_array($json)
            ? (string)($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? '')
            : $body;
        $message = trim(preg_replace('/\s+/', ' ', $message !== '' ? $message : $body) ?? '');
        return mb_substr(str_replace(array_values($this->keys), '[redacted]', $message), 0, 400, 'UTF-8');
    }

    /** Sequential curl. Plain HTTP only towards loopback: a key never travels unencrypted. */
    private static function defaultTransport(): Closure
    {
        return static function (array $requests): array {
            $responses = [];
            foreach ($requests as $index => $request) {
                $url = (string)$request['url'];
                $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
                if (str_starts_with($url, 'http://') && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    throw new RuntimeException('Refusing to send a prompt over plain HTTP to ' . $host);
                }
                $headers = [];
                foreach ($request['headers'] ?? [] as $name => $value) {
                    $headers[] = $name . ': ' . $value;
                }
                $handle = curl_init($url);
                $received = [];
                curl_setopt_array($handle, [
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                        return strlen($line);
                    },
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => (string)($request['body'] ?? ''), CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => (int)($request['timeout'] ?? 120),
                    CURLOPT_CONNECTTIMEOUT => (int)($request['connectTimeout'] ?? 10),
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($handle);
                $responses[$index] = ['status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'body' => $body === false ? '' : (string)$body, 'headers' => $received, 'error' => (string)curl_error($handle)];
                curl_close($handle);
            }
            return $responses;
        };
    }
}
