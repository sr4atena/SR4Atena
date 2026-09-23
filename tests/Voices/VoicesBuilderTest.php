<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Storage\JsonStore;
use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\ArchiveSource;
use ManorLedger\Voices\CommentFilter;
use ManorLedger\Voices\LlmClient;
use ManorLedger\Voices\Synthesizer;
use ManorLedger\Voices\ThumbnailStore;
use ManorLedger\Voices\TranscriptFetcher;
use ManorLedger\Voices\VideoSummarizer;
use ManorLedger\Voices\VoicesBuilder;
use ManorLedger\Voices\YouTubeClient;
use PHPUnit\Framework\TestCase;

/**
 * The whole pipeline on fixtures: every transport is injected, so this runs
 * offline and still exercises the real classes end to end.
 */
final class VoicesBuilderTest extends TestCase
{
    use TempDirTrait;

    private const NOW = 1789624991; // 2026-09-17T06:03:11Z

    private string $dir;
    private int $llmCalls = 0;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->log = [];
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../fixtures/voices/' . $name);
    }

    /**
     * @param list<string> $llmBodies one JSON payload per expected model call
     * @param ?string $recentVideos the videos.list body for the recent list, with an archive
     */
    /** @param int|list<string> $comments how many comment answers (all the fixture), or the bodies themselves */
    private function builder(array $llmBodies, ?ArchiveSource $archive = null, ?string $recentVideos = null, int|array $comments = 2): VoicesBuilder
    {
        $waves = [
            [self::fixture('search-page1.json'), self::fixture('search-page1.json')],
            [self::fixture('search-page2.json'), self::fixture('search-page2.json')],
            [self::fixture('videos.json')],
        ];
        // The two most watched are processed first, then the recent list is
        // asked for, then the comments of the recent ones.
        foreach (is_int($comments) ? array_fill(0, $comments, self::fixture('comments.json')) : $comments as $n => $body) {
            if ($n === 2 && $recentVideos !== null) {
                $waves[] = [$recentVideos];
            }
            $waves[] = [$body];
        }
        $youtube = static function (array $requests) use (&$waves): array {
            $bodies = array_shift($waves) ?? [];

            return array_map(static fn (string $b): array => ['status' => 200, 'body' => $b, 'headers' => []], $bodies);
        };
        $images = static fn (array $requests): array => array_map(
            static fn (): array => ['status' => 200, 'body' => "\xFF\xD8\xFF\xE0" . str_repeat('x', 2000), 'headers' => []],
            $requests,
        );
        $model = function (array $requests) use (&$llmBodies): array {
            $this->llmCalls++;
            $body = array_shift($llmBodies);
            self::assertNotNull($body, 'one model call more than this run is allowed to make');

            return [['status' => 200, 'body' => json_encode(['message' => ['content' => $body]]), 'headers' => []]];
        };
        // Only the Portuguese video has captions: five of ten is the measured norm.
        $runner = static fn (string $python, string $script, string $id): array => ['code' => 0, 'out' => $id === 'CCCCCCCCCCC'
            ? '{"status":"ok","language":"pt","generated":true,"text":"o jogo tem glitches de colisao nos armarios"}'
            : '{"status":"missing","error":"TranscriptsDisabled"}'];

        $llm = new LlmClient(
            ['summary' => ['driver' => 'ollama', 'baseUrl' => 'http://localhost:11434/', 'model' => 'qwen2.5:14b-instruct',
                           'keyFile' => null, 'timeout' => 10, 'fallback' => null],
             'synthesis' => ['driver' => 'ollama', 'baseUrl' => 'http://localhost:11434/', 'model' => 'qwen2.5:14b-instruct',
                             'keyFile' => null, 'timeout' => 10, 'fallback' => null]],
            0.2,
            1,
            $model,
            static fn (float $s): null => null,
        );
        $prompts = dirname(__DIR__, 2) . '/config/prompts';

        return new VoicesBuilder(
            new YouTubeClient('K', $youtube),
            new CommentFilter(),
            new TranscriptFetcher('/bin/sh', __FILE__, $this->dir . '/transcripts', 5, $runner, 0.0, 2, static fn (float $s): null => null),
            new ThumbnailStore($this->dir . '/media/yt', $images),
            new VideoSummarizer($llm, $prompts . '/voices-summary.md', $this->dir . '/voices-cache'),
            new Synthesizer($llm, $prompts . '/voices-synthesis.md'),
            new JsonStore($this->dir . '/voices.json', 0640),
            $this->dir . '/voices-cache',
            YouTubeClient::DEFAULT_QUERIES,
            ['summary' => 'gemini-3.6-flash', 'synthesis' => 'gemini-3.6-flash'],
            'workstation',
            function (string $line): void { $this->log[] = $line; },
            static fn (): int => self::NOW,
            $archive,
            1000,
            97090732168175,
        );
    }

    private function fullRun(): array
    {
        return $this->builder([self::fixture('summary-response.json'), self::fixture('summary-response.json'),
                               self::fixture('synthesis-response.json')])->run();
    }

    public function testTheDocumentMatchesTheContract(): void
    {
        $document = $this->fullRun();

        self::assertSame('2026-09-17T06:03:11Z', $document['generatedAt']);
        self::assertSame('workstation', $document['host']);
        self::assertSame(YouTubeClient::DEFAULT_QUERIES, $document['queries']);
        self::assertSame(['candidates' => 4, 'excludedNonRoblox' => 2, 'withTranscript' => 1], $document['stats']);
        self::assertSame(['summary' => 'qwen2.5:14b-instruct', 'synthesis' => 'qwen2.5:14b-instruct',
                          'fellBackTo' => 'qwen2.5:14b-instruct'], $document['models'], 'the page must be able to say which model spoke');

        $video = $document['videos'][0];
        self::assertSame(['id', 'title', 'channel', 'channelId', 'publishedAt', 'views', 'likes', 'commentCount',
                          'url', 'thumbnail', 'transcript', 'comments', 'summary', 'lists', 'mixed'], array_keys($video));
        self::assertFalse($video['mixed']);
        self::assertSame(['top'], $video['lists']);
        self::assertSame(['top' => ['CCCCCCCCCCC', 'AAAAAAAAAAA'], 'recent' => []], $document['lists'], 'no archive, no recent list');
        self::assertSame('CCCCCCCCCCC', $video['id'], 'sorted by views, not by search rank');
        self::assertSame('/media/yt/CCCCCCCCCCC.jpg', $video['thumbnail']);
        self::assertFileExists($this->dir . '/media/yt/CCCCCCCCCCC.jpg');
        self::assertSame(['status' => 'ok', 'language' => 'pt', 'generated' => true, 'chars' => 43], $video['transcript']);
        self::assertSame(['fetched' => 4, 'kept' => 2], $video['comments'], 'creator chatter and emoji are dropped');
        self::assertSame('missing', $document['videos'][1]['transcript']['status']);

        $summary = $video['summary'];
        self::assertSame('ok', $summary['status']);
        self::assertSame(['transcript', 'comments'], $summary['basedOn']);
        self::assertContains('Lag nella lobby iniziale', $summary['improvements']);
        self::assertSame('the stamina bar runs out way too fast', $summary['quotes'][0]['text']);

        $synthesis = $document['synthesis'];
        self::assertSame('ok', $synthesis['status']);
        // Two videos, 2026-09-02 and 2026-09-14: counted from the newest, the
        // first point is 12 days old (raised, then not raised again) and the
        // second is of the day. The label always comes from the dates, never
        // from the model.
        self::assertSame(['persistent', 'recent'], array_column($synthesis['improvements'], 'recency'));
        self::assertCount(2, $synthesis['timeline']);
        self::assertSame(3, $this->llmCalls, 'two summaries and one synthesis');
        self::assertSame(0640, fileperms($this->dir . '/voices.json') & 0777, 'the web process reads it, nothing more');
    }

    public function testTheMostRecentJoinTheMostWatchedAndAVideoInBothIsAnalysedOnce(): void
    {
        $archive = $this->dir . '/archive.json';
        file_put_contents($archive, json_encode(['videos' => [
            // In the top list too: must be analysed once, shown in both.
            'AAAAAAAAAAA' => ['views' => 1, 'subscribers' => 5000, 'seconds' => 900, 'publishedTime' => '2026-09-16T10:00:00Z'],
            'EEEEEEEEEEE' => ['views' => 1, 'subscribers' => 239000, 'seconds' => 2489, 'publishedTime' => '2026-09-16T21:15:43Z'],
            // Newer, but a channel without an audience: not in the list.
            'FFFFFFFFFFF' => ['views' => 1, 'subscribers' => 40, 'seconds' => 900, 'publishedTime' => '2026-09-16T23:00:00Z'],
        ]]));
        $videos = json_decode(self::fixture('videos.json'), true);
        $a = array_values(array_filter($videos['items'], static fn (array $i): bool => $i['id'] === 'AAAAAAAAAAA'))[0];
        $e = $a;
        $e['id'] = 'EEEEEEEEEEE';
        $e['snippet']['title'] = 'The NEW Locust Horror Game on Roblox GOT SCARIER... (The Locust Manor)';
        $e['snippet']['publishedAt'] = '2026-09-16T21:15:43Z';
        $a['snippet']['publishedAt'] = '2026-09-16T10:00:00Z';

        $document = $this->builder(
            [self::fixture('summary-response.json'), self::fixture('summary-response.json'), self::fixture('summary-response.json'),
             self::fixture('synthesis-response.json')],
            new ArchiveSource($archive),
            (string)json_encode(['items' => [$a, $e]]),
            3,
        )->run(['recentN' => 15]);

        self::assertSame(['top' => ['CCCCCCCCCCC', 'AAAAAAAAAAA'], 'recent' => ['EEEEEEEEEEE', 'AAAAAAAAAAA']], $document['lists']);
        self::assertSame(['CCCCCCCCCCC', 'AAAAAAAAAAA', 'EEEEEEEEEEE'], array_column($document['videos'], 'id'), 'the union, no doubles');
        self::assertSame(['top', 'recent'], $document['videos'][1]['lists']);
        self::assertSame(['recent'], $document['videos'][2]['lists']);
        self::assertSame(4, $this->llmCalls, 'three summaries, not four, and one synthesis');
        self::assertCount(3, $document['synthesis']['videosConsidered'] ?? [], 'the synthesis reads each video once');
    }

    public function testARecentVideoWithNothingToSayGivesItsPlaceToTheNext(): void
    {
        $archive = $this->dir . '/archive.json';
        file_put_contents($archive, json_encode(['videos' => [
            'GGGGGGGGGGG' => ['views' => 1, 'subscribers' => 5000, 'seconds' => 900, 'publishedTime' => '2026-09-16T23:30:00Z'],
            'EEEEEEEEEEE' => ['views' => 1, 'subscribers' => 239000, 'seconds' => 2489, 'publishedTime' => '2026-09-16T21:15:43Z'],
        ]]));
        $videos = json_decode(self::fixture('videos.json'), true);
        $a = array_values(array_filter($videos['items'], static fn (array $i): bool => $i['id'] === 'AAAAAAAAAAA'))[0];
        $e = $a;
        $e['id'] = 'EEEEEEEEEEE';
        $e['snippet']['publishedAt'] = '2026-09-16T21:15:43Z';
        $g = $a;
        $g['id'] = 'GGGGGGGGGGG';
        $g['snippet']['title'] = "The Locust's Manor (1 view, no captions, no comments)";
        $g['snippet']['publishedAt'] = '2026-09-16T23:30:00Z';
        $comments = self::fixture('comments.json');

        $document = $this->builder(
            [self::fixture('summary-response.json'), self::fixture('summary-response.json'), self::fixture('summary-response.json'),
             self::fixture('synthesis-response.json')],
            new ArchiveSource($archive),
            (string)json_encode(['items' => [$g, $e]]),
            [$comments, $comments, '{"items":[]}', $comments],
        )->run(['recentN' => 1]);

        self::assertSame(['EEEEEEEEEEE'], $document['lists']['recent'], 'the newest had nothing to summarise: the next one takes its place');
        self::assertNotContains('GGGGGGGGGGG', array_column($document['videos'], 'id'), 'and it is neither shown nor analysed');
        self::assertStringContainsString('GGGGGGGGGGG: nothing to summarise', implode("\n", $this->log));
    }

    public function testAMostWatchedVideoWithNothingToSayIsNotListed(): void
    {
        // AAAAAAAAAAA has no captions; with no comments either there is
        // nothing to summarise, so only CCCCCCCCCCC remains.
        $document = $this->builder([self::fixture('summary-response.json'), self::fixture('synthesis-response.json')],
            null, null, [self::fixture('comments.json'), '{"items":[]}'])->run();

        self::assertSame(['CCCCCCCCCCC'], $document['lists']['top']);
        self::assertSame(['CCCCCCCCCCC'], array_column($document['videos'], 'id'));
        self::assertStringContainsString('AAAAAAAAAAA: nothing to summarise', implode("\n", $this->log));
    }

    public function testAMixedVideoTitledAfterAnotherGameIsNotListedAtAll(): void
    {
        $archive = $this->dir . '/archive.json';
        file_put_contents($archive, json_encode(['videos' => [
            'HHHHHHHHHHH' => ['views' => 1, 'subscribers' => 90000, 'seconds' => 900, 'publishedTime' => '2026-09-16T22:00:00Z'],
            'EEEEEEEEEEE' => ['views' => 1, 'subscribers' => 16000, 'seconds' => 900, 'publishedTime' => '2026-09-16T21:15:43Z'],
        ]]));
        $videos = json_decode(self::fixture('videos.json'), true);
        $a = array_values(array_filter($videos['items'], static fn (array $i): bool => $i['id'] === 'AAAAAAAAAAA'))[0];
        $h = $a;
        $h['id'] = 'HHHHHHHHHHH';
        $h['snippet']['title'] = 'Kabur Dari Anomali Boiled One & Locust Doctor Nowhere | House of The Locust Indonesia';
        $h['snippet']['description'] = "Nama Game : Locust Manor https://www.roblox.com/games/97090732168175\n"
            . 'Nama Game : House of The Locust https://www.roblox.com/games/86247128610772';
        $h['snippet']['publishedAt'] = '2026-09-16T22:00:00Z';
        $e = $a;
        $e['id'] = 'EEEEEEEEEEE';
        $e['snippet']['publishedAt'] = '2026-09-16T21:15:43Z';

        $document = $this->builder(
            [self::fixture('summary-response.json'), self::fixture('summary-response.json'), self::fixture('summary-response.json'),
             self::fixture('synthesis-response.json')],
            new ArchiveSource($archive),
            (string)json_encode(['items' => [$h, $e]]),
            3,
        )->run(['recentN' => 1]);

        self::assertSame(['EEEEEEEEEEE'], $document['lists']['recent'], 'titled after the other game: its place goes to the next');
        self::assertNotContains('HHHHHHHHHHH', array_column($document['videos'], 'id'), 'neither shown nor analysed');
        self::assertStringContainsString('HHHHHHHHHHH: several games and the title names another or none', implode("\n", $this->log));
    }

    public function testAMixedVideoIsShownButLeftOutOfTheSynthesis(): void
    {
        $archive = $this->dir . '/archive.json';
        file_put_contents($archive, json_encode(['videos' => [
            'EEEEEEEEEEE' => ['views' => 1, 'subscribers' => 16000, 'seconds' => 900, 'publishedTime' => '2026-09-16T21:15:43Z'],
        ]]));
        $videos = json_decode(self::fixture('videos.json'), true);
        $e = array_values(array_filter($videos['items'], static fn (array $i): bool => $i['id'] === 'AAAAAAAAAAA'))[0];
        $e['id'] = 'EEEEEEEEEEE';
        // Titled after this game, but with another one in the same video.
        $e['snippet']['title'] = "The Locust's Manor and MONOCHROME back to back";
        $e['snippet']['description'] = "Game 1: https://www.roblox.com/games/134208374070897/MONOCHROME\n"
            . 'Game 2: https://www.roblox.com/games/97090732168175/The-Locusts-Manor';
        $e['snippet']['publishedAt'] = '2026-09-16T21:15:43Z';

        $document = $this->builder(
            [self::fixture('summary-response.json'), self::fixture('summary-response.json'), self::fixture('summary-response.json'),
             self::fixture('synthesis-response.json')],
            new ArchiveSource($archive),
            (string)json_encode(['items' => [$e]]),
            3,
        )->run(['recentN' => 15]);

        $byId = array_column($document['videos'], null, 'id');
        self::assertTrue($byId['EEEEEEEEEEE']['mixed'], 'it links another game as well');
        self::assertSame('ok', $byId['EEEEEEEEEEE']['summary']['status'], 'its card keeps its summary');
        self::assertNotContains('EEEEEEEEEEE', $document['synthesis']['videosConsidered'], 'but the synthesis leaves it out');
        self::assertStringContainsString('1 mixed video left out', implode("\n", $this->log));
    }

    public function testASecondRunTheSameDayMakesNoModelCallAtAll(): void
    {
        $this->fullRun();
        $this->llmCalls = 0;
        $this->log = [];

        $document = $this->builder([])->run();

        self::assertSame(0, $this->llmCalls, 'summaries are cached and the synthesis is still current');
        self::assertStringContainsString('summary cached', implode("\n", $this->log));
        self::assertStringContainsString('synthesis: still current', implode("\n", $this->log));
        self::assertSame('ok', $document['synthesis']['status']);
    }

    public function testResynthesizeCostsExactlyOneCall(): void
    {
        $this->fullRun();
        $this->llmCalls = 0;

        $document = $this->builder([self::fixture('synthesis-response.json')])->run(['resynthesize' => true]);

        self::assertSame(1, $this->llmCalls);
        self::assertSame('ok', $document['synthesis']['status']);
    }

    public function testAFailedSynthesisKeepsThePreviousOneAndSaysSo(): void
    {
        $first = $this->fullRun();
        $this->llmCalls = 0;

        // The model is down: the summaries are cached, the synthesis is not.
        $document = $this->builder([])->run(['resynthesize' => true]);

        self::assertSame('stale', $document['synthesis']['status']);
        self::assertSame($first['synthesis']['generatedAt'], $document['synthesis']['generatedAt'], 'the page shows its date');
        self::assertSame($first['synthesis']['verdict'], $document['synthesis']['verdict']);
        self::assertStringContainsString('synthesis failed', implode("\n", $this->log));
    }

    public function testRemodelRedoesOnlyWhatTheFallbackWrote(): void
    {
        // The first run answers with the local model: every summary is "wrong".
        $this->fullRun();
        $this->llmCalls = 0;
        $this->log = [];

        $document = $this->builder([self::fixture('summary-response.json'), self::fixture('summary-response.json'),
                                    self::fixture('synthesis-response.json')])->run(['remodel' => true]);

        self::assertSame(3, $this->llmCalls, 'both cached summaries came from the fallback model');
        self::assertStringContainsString('asking the primary model again', implode("\n", $this->log));
        self::assertSame('ok', $document['synthesis']['status']);
    }

    public function testDryRunSendsNothing(): void
    {
        $document = $this->builder([])->run(['dryRun' => true, 'topN' => 15]);

        self::assertSame([], $document);
        self::assertFileDoesNotExist($this->dir . '/voices.json');
        self::assertStringContainsString('dry run', implode("\n", $this->log));
        self::assertStringContainsString('416 units', implode("\n", $this->log), 'the plan follows voices.topN');
    }
}
