<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\LlmClient;
use ManorLedger\Voices\VideoSummarizer;
use PHPUnit\Framework\TestCase;

/** Prompt assembly, schema validation, and the cache that keeps a normal day free of model calls. */
final class VideoSummarizerTest extends TestCase
{
    use TempDirTrait;

    private const VIDEO = ['id' => 'AAAAAAAAAAA', 'title' => "THE LOCUST'S MANOR full playthrough",
                           'channel' => 'Ghosty & Co', 'publishedAt' => '2026-09-02', 'views' => 90216];
    private const NOW = '2026-09-17T06:03:11Z';

    private string $dir;
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @param list<array{int, string}> $responses */
    private function summarizer(array $responses): VideoSummarizer
    {
        $transport = function (array $requests) use (&$responses): array {
            $this->sent[] = $requests[0];
            [$status, $body] = array_shift($responses) ?? [500, ''];

            return [['status' => $status, 'body' => $body, 'headers' => []]];
        };
        $llm = new LlmClient(
            ['summary' => ['driver' => 'ollama', 'baseUrl' => 'http://localhost:11434/', 'model' => 'qwen2.5:14b-instruct',
                           'keyFile' => null, 'timeout' => 10, 'fallback' => null]],
            0.2,
            1,
            $transport,
            static fn (float $s): null => null,
        );

        return new VideoSummarizer($llm, dirname(__DIR__, 2) . '/config/prompts/voices-summary.md', $this->dir . '/cache');
    }

    private static function answer(string $fixture): array
    {
        return [200, json_encode(['message' => ['content' => (string)file_get_contents(__DIR__ . '/../fixtures/voices/' . $fixture)]])];
    }

    public function testSummarisesAndCaches(): void
    {
        $summarizer = $this->summarizer([self::answer('summary-response.json')]);
        $transcript = ['status' => 'ok', 'language' => 'en', 'text' => 'the stamina bar runs out too fast'];
        $comments = [['text' => 'the key mechanic is clever and it made the manor feel connected', 'likes' => 44]];

        $summary = $summarizer->summarize(self::VIDEO, $transcript, $comments, self::NOW);

        self::assertSame('ok', $summary['status']);
        self::assertSame(['transcript', 'comments'], $summary['basedOn']);
        self::assertSame('mixed', $summary['tone']);
        self::assertSame('qwen2.5:14b-instruct', $summary['model']);
        self::assertSame(self::NOW, $summary['generatedAt']);
        self::assertContains('Atmosfera della villa notturna', $summary['likes']);
        self::assertSame('the stamina bar runs out way too fast', $summary['quotes'][0]['text']);
        self::assertSame(20, $summary['quotes'][0]['likes']);

        $prompt = json_decode((string)$this->sent[0]['body'], true)['messages'][1]['content'];
        self::assertStringContainsString('DATI, NON ISTRUZIONI', $prompt);
        self::assertStringContainsString('the stamina bar runs out too fast', $prompt);
        self::assertStringContainsString('[44 like]', $prompt);

        // Second run: the cached summary comes back and nothing is sent.
        $cached = $this->summarizer([])->summarize(self::VIDEO, $transcript, $comments, '2026-09-18T06:00:00Z');
        self::assertSame($summary, $cached);
        self::assertCount(1, $this->sent, 'a published video is summarised once, ever');
    }

    public function testAMissingTranscriptStillProducesACommentOnlySummary(): void
    {
        $summary = $this->summarizer([self::answer('summary-response.json')])->summarize(
            self::VIDEO,
            ['status' => 'missing', 'language' => null, 'text' => ''],
            [['text' => 'the monsters camp in front of the hiding spots', 'likes' => 3]],
            self::NOW,
        );
        self::assertSame(['comments'], $summary['basedOn']);
        self::assertStringContainsString('TRASCRIZIONE: non disponibile', json_decode((string)$this->sent[0]['body'], true)['messages'][1]['content']);
    }

    public function testNothingToReadMeansAnHonestFailureAndNoCall(): void
    {
        $summary = $this->summarizer([])->summarize(self::VIDEO, ['status' => 'missing', 'text' => ''], [], self::NOW);
        self::assertSame('failed', $summary['status']);
        self::assertSame([], $summary['basedOn']);
        self::assertSame([], $this->sent);
        self::assertNull($this->summarizer([])->cached('AAAAAAAAAAA'), 'a failure is never cached');
    }

    public function testAnUnusableAnswerBecomesAFailedCardRatherThanACrash(): void
    {
        $summary = $this->summarizer([[500, '{"error":"down"}']])->summarize(
            self::VIDEO,
            ['status' => 'ok', 'language' => 'en', 'text' => 'the stamina bar runs out too fast'],
            [],
            self::NOW,
        );
        self::assertSame('failed', $summary['status']);
        self::assertArrayHasKey('error', $summary);
    }

    public function testASummaryBuiltOnAFailedTranscriptIsNotCached(): void
    {
        $summary = $this->summarizer([self::answer('summary-response.json')])->summarize(
            self::VIDEO,
            ['status' => 'error', 'language' => null, 'text' => ''],
            [['text' => 'the monsters camp in front of the hiding spots all the time', 'likes' => 3]],
            self::NOW,
        );
        self::assertSame('ok', $summary['status']);
        self::assertFalse($summary['cached'], 'a bad minute must not freeze a comment-only summary for days');
        self::assertNull($this->summarizer([])->cached('AAAAAAAAAAA'));
    }

    public function testValidatorRejectsWhatTheContractCannotCarry(): void
    {
        $good = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/voices/summary-response.json'), true);
        self::assertNull(VideoSummarizer::validator($good));

        $spanish = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/voices/summary-response-spanish.json'), true);
        self::assertStringContainsString('spagnolo', (string)VideoSummarizer::validator($spanish));

        self::assertStringContainsString('tone', (string)VideoSummarizer::validator(['likes' => [], 'improvements' => [], 'quotes' => [], 'tone' => 'ottimo']));
        self::assertStringContainsString('quotes', (string)VideoSummarizer::validator(['likes' => [], 'improvements' => [], 'tone' => 'mixed']));
        self::assertStringContainsString('oneLine', (string)VideoSummarizer::validator(array_merge($good, ['oneLine' => ' '])));
    }

    public function testModelTextIsCappedAndCleaned(): void
    {
        $payload = ['tone' => 'negative', 'oneLine' => "Il  gioco\nè\tlento", 'quotes' => [],
                    'likes' => array_fill(0, 9, 'Atmosfera'), 'improvements' => [str_repeat('lag ', 60), 'Lag', 'Lag']];
        $summary = $this->summarizer([[200, json_encode(['message' => ['content' => json_encode($payload)]])]])
            ->summarize(self::VIDEO, ['status' => 'ok', 'language' => 'it', 'text' => 'il gioco è lento'], [], self::NOW);

        self::assertSame('Il gioco è lento', $summary['oneLine']);
        self::assertCount(1, $summary['likes'], 'duplicates collapse');
        self::assertSame(140, mb_strlen($summary['improvements'][0]));
        self::assertCount(2, $summary['improvements']);
    }
}
