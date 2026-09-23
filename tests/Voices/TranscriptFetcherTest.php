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

    /** @param float|list<float> $cooldownHours */
    private function fetcher(string $script, ?callable $runner = null, float|array $cooldownHours = 6.0, ?int $now = null): TranscriptFetcher
    {
        return new TranscriptFetcher('/bin/sh', $script, $this->dir . '/cache', 10, $runner, 5.0, 3, function (float $s): void {
            $this->slept[] = $s;
        }, $cooldownHours, $now === null ? null : static fn (): int => $now);
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
        $refused = $this->fetcher($this->fakeScript('{"status":"blocked","error":"RequestBlocked"}'));
        $blocked = $refused->fetch('O8eWFVZxgcI');
        self::assertSame('blocked', $blocked['status']);
        self::assertFileDoesNotExist($this->dir . '/cache/O8eWFVZxgcI.json', 'that is about the network, not the video');
        // A refusal now stands until the cooldown expires, which is the subject
        // of its own test; the rest of this one is about broken runs.
        $refused->clearBlock();

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

    public function testEveryErrorIsRecordedAndTheRunGoesOn(): void
    {
        $answers = ['{"status":"error","error":"HTTPError: 500"}', '{"status":"ok","text":"dopo"}', 'not json'];
        $runner = static function () use (&$answers): array {
            return ['code' => 0, 'out' => (string)array_shift($answers)];
        };
        $fetcher = $this->fetcher($this->fakeScript('unused'), $runner);

        self::assertSame('ok', $fetcher->fetch('aaaaaaaaaa1')['status']);
        self::assertFalse($fetcher->isBlocked());
        self::assertSame([['id' => 'aaaaaaaaaa1', 'try' => 1, 'error' => 'HTTPError: 500']], $fetcher->errors());
        self::assertNull($fetcher->tripped(), 'an error is not a refusal: no pause');
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

    /**
     * A refusal is about the address, not the video: after the first one every
     * remaining video must knock zero times, not three.
     */
    public function testTheFirstBlockStopsTheRunInsteadOfKnockingAgain(): void
    {
        $asked = 0;
        $runner = static function () use (&$asked): array {
            $asked++;

            return ['code' => 0, 'out' => '{"status":"blocked","error":"RequestBlocked"}'];
        };
        $fetcher = $this->fetcher($this->fakeScript('unused'), $runner);

        self::assertSame('blocked', $fetcher->fetch('O8eWFVZxgcI')['status']);
        self::assertSame('blocked', $fetcher->fetch('9s7sZkuW_Jg')['status']);
        self::assertSame('blocked', $fetcher->fetch('lHul7HACuLo')['status']);

        self::assertSame(1, $asked, 'one refusal is the address answering for every video');
        self::assertSame('RequestBlocked', $fetcher->lastError(), 'the script\'s own name for the wall is kept');
        self::assertSame('RequestBlocked', $fetcher->tripped()['error'] ?? null);
        self::assertTrue($fetcher->isBlocked());
        self::assertSame([], array_filter($this->slept, static fn (float $s): bool => $s >= 5.0), 'and no backoff to wait through');
    }

    public function testTheBlockOutlivesTheProcessForTheCooldown(): void
    {
        $asked = 0;
        $runner = static function () use (&$asked): array {
            $asked++;

            return ['code' => 0, 'out' => '{"status":"blocked","error":"RequestBlocked"}'];
        };
        $this->fetcher($this->fakeScript('unused'), $runner, 6.0, 1_000_000)->fetch('O8eWFVZxgcI');
        self::assertFileExists($this->dir . '/cache/' . TranscriptFetcher::BLOCK_MARKER);

        // A new process, an hour later: the job of the next morning must not
        // spend its run walking into the same wall.
        $next = $this->fetcher($this->fakeScript('unused'), $runner, 6.0, 1_003_600);
        self::assertTrue($next->isBlocked());
        self::assertSame('blocked', $next->fetch('9s7sZkuW_Jg')['status']);
        self::assertSame(1, $asked, 'the second process asked nothing at all');
        $reason = (string)$next->unavailableReason();
        self::assertStringContainsString('refused caption requests', $reason);
        // The deadline is ours, and the sentence has to say so: YouTube never
        // tells us when a refusal ends, and a log line that implies it would be
        // read as a promise at eight in the morning.
        self::assertStringContainsString('our own cooldown', $reason);

        // The marker is not a transcript, and must not be counted as one.
        self::assertSame([], glob($this->dir . '/cache/*.json') ?: []);
    }

    public function testTheBlockExpiresOnItsOwnAndCanBeForgotten(): void
    {
        $runner = static fn (): array => ['code' => 0, 'out' => '{"status":"blocked","error":"RequestBlocked"}'];
        $this->fetcher($this->fakeScript('unused'), $runner, 6.0, 1_000_000)->fetch('O8eWFVZxgcI');

        // Thirteen hours later the cooldown is long over and the fetcher asks again.
        $later = $this->fetcher($this->fakeScript('{"status":"ok","text":"el juego da miedo"}'), null, 6.0, 1_046_800);
        self::assertFalse($later->isBlocked());
        self::assertSame('ok', $later->fetch('9s7sZkuW_Jg')['status']);

        // And within the cooldown, --clear-block is the way to insist.
        $this->fetcher($this->fakeScript('unused'), $runner, 6.0, 2_000_000)->fetch('_1OZU-1N3VE');
        $insisting = $this->fetcher($this->fakeScript('{"status":"missing"}'), null, 6.0, 2_003_600);
        self::assertTrue($insisting->isBlocked());
        $insisting->clearBlock();
        self::assertFalse($insisting->isBlocked());
        self::assertNull($insisting->blockedUntil());
        self::assertSame('missing', $insisting->fetch('BNuGG3cOKBY')['status']);
    }

    public function testThePauseGrowsWithEachRefusalInARowAndStopsAtTheLastStep(): void
    {
        $blocked = static fn (): array => ['code' => 0, 'out' => '{"status":"blocked","error":"IpBlocked"}'];
        $ladder = [6.0, 12.0, 24.0];
        $t = 1_000_000;
        $seen = [];
        foreach (['aaaaaaaaaa1', 'aaaaaaaaaa2', 'aaaaaaaaaa3', 'aaaaaaaaaa4'] as $id) {
            $fetcher = $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, $t);
            self::assertFalse($fetcher->isBlocked(), 'each run starts after the previous pause has ended');
            $fetcher->fetch($id);
            $tripped = $fetcher->tripped();
            self::assertNotNull($tripped);
            $seen[] = [$tripped['streak'], $tripped['hours'], $tripped['until'] - $t];
            $t = $tripped['until'] + 1;
        }
        self::assertSame([[1, 6.0, 21_600], [2, 12.0, 43_200], [3, 24.0, 86_400], [4, 24.0, 86_400]], $seen);
    }

    public function testTheFirstAnswerThatGetsThroughResetsTheLadder(): void
    {
        $blocked = static fn (): array => ['code' => 0, 'out' => '{"status":"blocked","error":"IpBlocked"}'];
        $ladder = [6.0, 12.0, 24.0];
        $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_000_000)->fetch('aaaaaaaaaa1');
        $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_021_601)->fetch('aaaaaaaaaa2');

        $through = $this->fetcher($this->fakeScript('{"status":"ok","text":"funziona"}'), null, $ladder, 1_064_802);
        self::assertSame('ok', $through->fetch('aaaaaaaaaa3')['status']);
        self::assertNull($through->tripped());
        self::assertFileDoesNotExist($this->dir . '/cache/' . TranscriptFetcher::BLOCK_MARKER);

        $again = $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_100_000);
        $again->fetch('aaaaaaaaaa4');
        self::assertSame(1, $again->tripped()['streak'] ?? null, 'a new wall starts again from six hours');
        self::assertSame(6.0, $again->tripped()['hours'] ?? null);
    }

    public function testAPauseWithNothingToAskKeepsTheCount(): void
    {
        $blocked = static fn (): array => ['code' => 0, 'out' => '{"status":"blocked","error":"IpBlocked"}'];
        $ladder = [6.0, 12.0, 24.0];
        $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_000_000)->fetch('aaaaaaaaaa1');

        // A run after the pause that finds every video in the cache proves
        // nothing about the wall, so the next refusal is still the second.
        file_put_contents($this->dir . '/cache/aaaaaaaaaa2.json', '{"status":"ok","text":"in cache"}');
        $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_030_000)->fetch('aaaaaaaaaa2');
        $next = $this->fetcher($this->fakeScript('unused'), $blocked, $ladder, 1_030_000);
        $next->fetch('aaaaaaaaaa3');
        self::assertSame(2, $next->tripped()['streak'] ?? null);
        self::assertSame(12.0, $next->tripped()['hours'] ?? null);
    }

    public function testAnEmptyTranscriptCountsAsMissing(): void
    {
        $transcript = $this->fetcher($this->fakeScript('{"status":"ok","text":"   "}'))->fetch('O8eWFVZxgcI');
        self::assertSame('missing', $transcript['status']);
    }
}
