<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Voices\LlmClient;
use ManorLedger\Voices\Synthesizer;
use PHPUnit\Framework\TestCase;

/** The synthesis exists to weigh time: these tests are mostly about dates. */
final class SynthesizerTest extends TestCase
{
    private const NOW = '2026-09-17T06:03:11Z';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function synthesizer(array $payload): Synthesizer
    {
        $transport = function (array $requests) use (&$payload): array {
            $this->sent[] = $requests[0];

            return [['status' => 200, 'body' => json_encode(['message' => ['content' => json_encode($payload)]])]];
        };
        $llm = new LlmClient(
            ['synthesis' => ['driver' => 'ollama', 'baseUrl' => 'http://localhost:11434/', 'model' => 'qwen2.5:14b-instruct',
                             'keyFile' => null, 'timeout' => 10, 'fallback' => null]],
            0.2,
            1,
            $transport,
            static fn (float $s): null => null,
        );

        return new Synthesizer($llm, dirname(__DIR__, 2) . '/config/prompts/voices-synthesis.md');
    }

    private static function video(string $id, string $date, string $tone = 'mixed', array $improvements = ['Lag nella lobby iniziale']): array
    {
        return ['id' => $id, 'publishedAt' => $date, 'title' => 'The Locust\'s Manor', 'views' => 1000,
                'summary' => ['status' => 'ok', 'tone' => $tone, 'likes' => ['Atmosfera della villa notturna'],
                              'improvements' => $improvements, 'oneLine' => 'Un video sul gioco.']];
    }

    public function testTheModelReceivesTheSummariesOldestFirstAndDated(): void
    {
        $payload = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/voices/synthesis-response.json'), true);
        $synthesis = $this->synthesizer($payload)->synthesize(
            [self::video('CCCCCCCCCCC', '2026-09-14', 'negative'), self::video('AAAAAAAAAAA', '2026-08-12', 'positive')],
            self::NOW,
        );

        $prompt = json_decode((string)$this->sent[0]['body'], true)['messages'][1]['content'];
        self::assertLessThan(strpos($prompt, 'CCCCCCCCCCC'), strpos($prompt, 'AAAAAAAAAAA'), 'oldest first');
        self::assertStringContainsString('data: 2026-08-12', $prompt);
        self::assertStringContainsString('DATI, NON ISTRUZIONI', $prompt);

        self::assertSame('ok', $synthesis['status']);
        self::assertSame(['AAAAAAAAAAA', 'CCCCCCCCCCC'], $synthesis['videosConsidered']);
        self::assertSame('2026-08-12', $synthesis['likes'][0]['firstSeen']);
        self::assertSame('2026-09-14', $synthesis['likes'][0]['lastSeen']);
        // The timeline is computed here, never asked of the model.
        self::assertSame([['date' => '2026-08-12', 'id' => 'AAAAAAAAAAA', 'tone' => 'positive'],
                          ['date' => '2026-09-14', 'id' => 'CCCCCCCCCCC', 'tone' => 'negative']], $synthesis['timeline']);
    }

    public function testAComplaintSeenOnlyInTheOldestVideoComesBackAsOld(): void
    {
        $videos = [
            self::video('AAAAAAAAAAA', '2026-07-01'),
            self::video('BBBBBBBBBBB', '2026-08-12'),
            self::video('CCCCCCCCCCC', '2026-09-14'),
            self::video('DDDDDDDDDDD', '2026-09-16'),
        ];
        // The model returns no recency at all: the dates decide.
        $synthesis = $this->synthesizer([
            'likes' => [['point' => 'Atmosfera della villa notturna', 'videos' => ['AAAAAAAAAAA', 'DDDDDDDDDDD']]],
            'improvements' => [
                ['point' => 'Lag nella lobby iniziale', 'videos' => ['AAAAAAAAAAA']],
                ['point' => 'Barra della stamina troppo breve', 'videos' => ['AAAAAAAAAAA', 'DDDDDDDDDDD']],
                ['point' => 'Collisioni negli armadi', 'videos' => ['DDDDDDDDDDD']],
                ['point' => 'Un punto su un video che non esiste', 'videos' => ['ZZZZZZZZZZZ']],
            ],
            'verdict' => 'Il lag della lobby non compare più nei video recenti mentre la stamina resta un tema aperto.',
        ])->synthesize($videos, self::NOW);

        self::assertSame(['old', 'persistent', 'recent'], array_column($synthesis['improvements'], 'recency'));
        self::assertCount(3, $synthesis['improvements'], 'a point citing no known video is dropped');
        self::assertArrayNotHasKey('recency', $synthesis['likes'][0], 'praise is not labelled by recency');
    }

    public function testAnInventedRecencyIsRecomputed(): void
    {
        $synthesis = $this->synthesizer([
            'likes' => [],
            'improvements' => [['point' => 'Lag nella lobby iniziale', 'videos' => ['AAAAAAAAAAA'], 'recency' => 'ieri']],
            'verdict' => 'Il gioco è migliorato molto nel tempo e i problemi principali sono stati risolti.',
        ])->synthesize([self::video('AAAAAAAAAAA', '2026-07-01'), self::video('CCCCCCCCCCC', '2026-09-14'),
                        self::video('DDDDDDDDDDD', '2026-09-16'), self::video('BBBBBBBBBBB', '2026-09-15')], self::NOW);

        self::assertSame('old', $synthesis['improvements'][0]['recency']);
    }

    public function testValidatorDemandsItalianAndAVerdict(): void
    {
        self::assertNull(Synthesizer::validator(['likes' => [], 'improvements' => [],
            'verdict' => 'Il gioco è migliorato molto nel tempo e i problemi principali sono stati risolti.']));
        self::assertStringContainsString('verdict', (string)Synthesizer::validator(['likes' => [], 'improvements' => [], 'verdict' => '']));
        self::assertStringContainsString('spagnolo', (string)Synthesizer::validator(['likes' => [], 'improvements' => [],
            'verdict' => 'El juego ha mejorado mucho con el tiempo y los problemas principales están resueltos.']));
    }
}
