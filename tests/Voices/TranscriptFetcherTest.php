<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\TranscriptFetcher;
use PHPUnit\Framework\TestCase;

/** The Python script is replaced by a fake: no network, no dependency, same contract. */
final class TranscriptFetcherTest extends TestCase
{
    use TempDirTrait;

    private string $dir;
    /** @var list<float> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function fakeScript(string $output, int $exit = 0): string
    {
        $path = $this->dir . '/fake-transcript.sh';
        file_put_contents($path, "#!/bin/sh\nprintf '%s' " . escapeshellarg($output) . "\nexit " . $exit . "\n");
        chmod($path, 0755);

        return $path;
    }

    private function fetcher(string $script, ?callable $runner = null): TranscriptFetcher
    {
        return new TranscriptFetcher('/bin/sh', $script, $this->dir . '/cache', 10, $runner, 5.0, 3, function (float $s): void {
            $this->slept[] = $s;
        });
    }

    public function testRunsTheScriptAndCachesTheResult(): void
    {
        $script = $this->fakeScript('{"status":"ok","language":"pt","generated":true,"text":"o jogo é assustador"}');
        $transcript = $this->fetcher($script)->fetch('lHul7HACuLo');

        self::assertSame('ok', $transcript['status']);
        self::assertSame('pt', $transcript['language']);
        self::assertTrue($transcript['generated']);
        self::assertSame(19, $transcript['chars']);
        self::assertFileExists($this->dir . '/cache/lHul7HACuLo.json');

        // The cached answer is used even when the script would now say something else.
        $again = $this->fetcher($this->fakeScript('{"status":"error"}'))->fetch('lHul7HACuLo');
        self::assertSame('ok', $again['status']);
        self::assertSame('o jogo é assustador', $again['text']);
    }

    public function testCaptionsDisabledIsANormalAnswer(): void
    {
        $transcript = $this->fetcher($this->fakeScript('{"status":"missing","error":"TranscriptsDisabled"}'))->fetch('eGxhf71MXyE');
        self::assertSame('missing', $transcript['status']);
        self::assertSame(0, $transcript['chars']);
        self::assertSame('', $transcript['text']);
        self::assertFileExists($this->dir . '/cache/eGxhf71MXyE.json', 'a disabled track will stay disabled');
    }

    public function testBlockedAndBrokenRunsAreNeverCached(): void
    {
        $blocked = $this->fetcher($this->fakeScript('{"status":"blocked","error":"RequestBlocked"}'))->fetch('O8eWFVZxgcI');
        self::assertSame('blocked', $blocked['status']);
        self::assertFileDoesNotExist($this->dir . '/cache/O8eWFVZxgcI.json', 'that is about the network, not the video');

        $garbage = $this->fetcher($this->fakeScript('Traceback (most recent call last)', 1))->fetch('O8eWFVZxgcI');
        self::assertSame('error', $garbage['status']);

        $missingScript = $this->fetcher($this->dir . '/nope.py')->fetch('O8eWFVZxgcI');
        self::assertSame('error', $missingScript['status']);
    }

    public function testATransientErrorIsRetriedWithBackoffAndThenSucceeds(): void
    {
        $answers = [
            ['code' => 0, 'out' => '{"status":"error","error":"RequestFailed"}'],
            ['code' => 0, 'out' => '{"status":"ok","language":"es","generated":true,"text":"el juego da miedo"}'],
        ];
        $tries = 0;
        $runner = static function () use (&$answers, &$tries): array {
            $tries++;

            return array_shift($answers);
        };
        $transcript = $this->fetcher($this->fakeScript('unused'), $runner)->fetch('J8e2KtEQwEY');

        self::assertSame('ok', $transcript['status'], 'the richest video failed only because it was asked in a burst');
        self::assertSame(2, $tries);
        $waits = array_values(array_filter($this->slept, static fn (float $s): bool => $s >= 1.0));
        self::assertSame(5.0, $waits[0], 'backoff before the retry');
        self::assertCount(2, $waits, 'and the interval that keeps the next request off YouTube\'s throttle');
    }

    public function testCaptionsDisabledIsNeverRetried(): void
    {
        $tries = 0;
        $runner = static function () use (&$tries): array {
            $tries++;

            return ['code' => 0, 'out' => '{"status":"missing","error":"TranscriptsDisabled"}'];
        };
        self::assertSame('missing', $this->fetcher($this->fakeScript('unused'), $runner)->fetch('_1OZU-1N3VE')['status']);
        self::assertSame(1, $tries, 'a disabled caption track will not appear on a retry');
    }

    public function testThePreflightNamesTheFaultInsteadOfDegradingEveryVideo(): void
    {
        $ok = $this->fetcher($this->fakeScript('{"status":"ok","check":true}'));
        self::assertNull($ok->unavailableReason());

        $broken = $this->fetcher($this->fakeScript('{"status":"error","error":"youtube-transcript-api is not installed"}'));
        self::assertStringContainsString('not installed', (string)$broken->unavailableReason());

        $missingScript = new TranscriptFetcher('/bin/sh', $this->dir . '/nope.py', $this->dir . '/cache');
        self::assertStringContainsString('not found', (string)$missingScript->unavailableReason());
    }

    public function testAnEmptyTranscriptCountsAsMissing(): void
    {
        $transcript = $this->fetcher($this->fakeScript('{"status":"ok","text":"   "}'))->fetch('O8eWFVZxgcI');
        self::assertSame('missing', $transcript['status']);
    }
}
