<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Tests\TempDirTrait;
use ManorLedger\Voices\LlmClient;
use ManorLedger\Voices\VideoSummarizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Drivers, retries, the fallback chain and the language guard, all offline. */
final class LlmClientTest extends TestCase
{
    use TempDirTrait;

    private string $dir;
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        file_put_contents($this->dir . '/key', "not-an-AIza-key-53-characters-long\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @param list<array{int, string}> $responses */
    private function client(array $responses, array $overrides = []): LlmClient
    {
        $profiles = [
            'summary' => ['driver' => 'gemini', 'baseUrl' => 'https://example.test/v1beta/', 'model' => 'gemini-3.6-flash',
                          'keyFile' => $this->dir . '/key', 'timeout' => 60, 'fallback' => 'local'],
            'local' => ['driver' => 'ollama', 'baseUrl' => 'http://localhost:11434/', 'model' => 'qwen2.5:14b-instruct',
                        'keyFile' => null, 'timeout' => 60, 'fallback' => null],
        ];
        foreach ($overrides as $name => $profile) {
            $profiles[$name] = $profile;
        }
        $transport = function (array $requests) use (&$responses): array {
            $this->sent[] = $requests[0];
            [$status, $body] = array_shift($responses) ?? [500, ''];

            return [['status' => $status, 'body' => $body, 'headers' => [], 'error' => '']];
        };

        return new LlmClient($profiles, 0.2, 3, $transport, static fn (float $s): null => null, function (string $l): void {
            $this->log[] = $l;
        });
    }

    private static function gemini(array $payload): array
    {
        return [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]]])];
    }

    private static function ollama(array $payload): array
    {
        return [200, json_encode(['message' => ['content' => json_encode($payload)]])];
    }

    public function testGeminiRequestShapeAndKeyFromFile(): void
    {
        $client = $this->client([self::gemini(['ok' => true, 'testo' => 'il gioco è molto bello'])]);
        $answer = $client->json('summary', 'sistema', 'utente');

        self::assertSame('gemini-3.6-flash', $answer['model']);
        self::assertFalse($answer['fellBack']);
        self::assertTrue($answer['data']['ok']);

        $request = $this->sent[0];
        self::assertSame('https://example.test/v1beta/models/gemini-3.6-flash:generateContent?key=not-an-AIza-key-53-characters-long', $request['url']);
        $body = json_decode((string)$request['body'], true);
        self::assertSame('sistema', $body['systemInstruction']['parts'][0]['text']);
        self::assertSame('utente', $body['contents'][0]['parts'][0]['text']);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame(0.2, $body['generationConfig']['temperature']);
    }

    public function testRetriesOnHighDemandThenSucceeds(): void
    {
        $client = $this->client([
            [503, '{"error":{"message":"The model is overloaded, experiencing high demand"}}'],
            self::gemini(['verdict' => 'il gioco è migliorato molto nel tempo']),
        ]);
        $answer = $client->json('summary', 's', 'u');
        self::assertSame('gemini-3.6-flash', $answer['model']);
        self::assertCount(2, $this->sent);
        self::assertStringContainsString('high demand', implode("\n", $this->log));
    }

    public function testTheDelayTheApiAsksForIsObeyed(): void
    {
        $waits = [];
        $profiles = ['summary' => ['driver' => 'gemini', 'baseUrl' => 'https://example.test/v1beta/', 'model' => 'm',
                                   'keyFile' => $this->dir . '/key', 'timeout' => 10, 'fallback' => null]];
        $responses = [
            [429, '{"error":{"message":"Quota exceeded. Please retry in 47.056792","details":[{"retryDelay":"47s"}]}}'],
            self::gemini(['verdict' => 'il gioco è migliorato molto nel tempo']),
        ];
        $client = new LlmClient($profiles, 0.2, 3, function (array $r) use (&$responses): array {
            [$status, $body] = array_shift($responses);

            return [['status' => $status, 'body' => $body, 'headers' => [], 'error' => '']];
        }, static function (float $s) use (&$waits): void { $waits[] = $s; });

        $client->json('summary', 's', 'u');
        self::assertEqualsWithDelta(48.06, $waits[0], 0.01, 'the API stated 47 s: waiting 20 s would only burn another attempt');
    }

    public function testFallsBackToTheLocalModelWhenTheRemoteKeepsFailing(): void
    {
        $client = $this->client([
            [503, '{"error":{"message":"busy"}}'], [503, '{"error":{"message":"busy"}}'], [503, '{"error":{"message":"busy"}}'],
            self::ollama(['verdict' => 'il gioco è migliorato molto nel tempo']),
        ]);
        $answer = $client->json('summary', 's', 'u');

        self::assertSame('qwen2.5:14b-instruct', $answer['model']);
        self::assertTrue($answer['fellBack'], 'the page must be able to say the fallback ran');
        self::assertSame('http://localhost:11434/api/chat', $this->sent[3]['url']);
        $body = json_decode((string)$this->sent[3]['body'], true);
        self::assertSame('json', $body['format']);
        self::assertFalse($body['stream']);
    }

    public function testAModelIdThatNoLongerExistsIsReportedWithItsSuccessor(): void
    {
        $client = $this->client([
            [404, '{"error":{"message":"gemini-2.5-flash is no longer available to new users; use gemini-3.6-flash instead"}}'],
            self::ollama(['verdict' => 'il gioco è migliorato molto nel tempo']),
        ]);
        $client->json('summary', 's', 'u');
        $log = implode("\n", $this->log);
        self::assertStringContainsString('404', $log);
        self::assertStringContainsString('use gemini-3.6-flash instead', $log, 'the suggested id is the fix: never swallow it');
        self::assertCount(2, $this->sent, 'a 404 is not retried, it is a configuration problem');
    }

    public function testTheKeyNeverAppearsInAnError(): void
    {
        $client = $this->client([[400, '{"error":{"message":"API key not valid: not-an-AIza-key-53-characters-long"}}']], [
            'summary' => ['driver' => 'gemini', 'baseUrl' => 'https://example.test/v1beta/', 'model' => 'm',
                          'keyFile' => $this->dir . '/key', 'timeout' => 60, 'fallback' => null],
        ]);
        try {
            $client->json('summary', 's', 'u');
            self::fail('an invalid key must stop the profile');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('API key not valid', $e->getMessage());
            self::assertStringNotContainsString('not-an-AIza-key', $e->getMessage());
        }
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function languages(): iterable
    {
        yield 'italian' => [['Atmosfera della villa notturna', 'La stamina è troppo breve e non permette di correre'], 'it'];
        yield 'spanish' => [['La atmósfera de la mansión por la noche', 'La barra de resistencia es muy corta para el juego'], 'es'];
        yield 'portuguese' => [['A atmosfera da mansão à noite', 'A barra de resistência é muito curta para o jogo'], 'pt'];
        yield 'english' => [['The atmosphere of the manor at night', 'The stamina bar is very short for the game'], 'en'];
        yield 'nothing to judge' => [['', 'Lag'], 'it'];
    }

    #[DataProvider('languages')]
    public function testLanguageDetection(array $texts, string $expected): void
    {
        self::assertSame($expected, VideoSummarizer::dominantLanguage($texts));
    }

    public function testSpanishOutputIsRetriedNamingTheLanguageThenFallsBack(): void
    {
        $spanish = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/voices/summary-response-spanish.json'), true);
        $italian = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/voices/summary-response.json'), true);
        $client = $this->client([self::gemini($spanish), self::gemini($spanish), self::gemini($spanish), self::ollama($italian)]);

        $validate = static fn (array $data): ?string => VideoSummarizer::italianProblem(
            array_merge($data['likes'], $data['improvements'], [$data['oneLine']]),
        );
        $answer = $client->json('summary', 's', 'u', $validate);

        self::assertSame('qwen2.5:14b-instruct', $answer['model']);
        self::assertTrue($answer['fellBack']);
        self::assertCount(4, $this->sent, 'the primary is given three attempts before the chain moves on');
        self::assertStringContainsString('spagnolo', (string)$this->sent[1]['body'], 'the retry names the language it detected');
        self::assertStringContainsString('CORREZIONE OBBLIGATORIA', (string)$this->sent[1]['body']);
    }

    public function testFencedJsonIsAccepted(): void
    {
        self::assertSame(['a' => 1], LlmClient::decodeObject("```json\n{\"a\": 1}\n```"));
        self::assertSame(['a' => 1], LlmClient::decodeObject("Ecco il risultato:\n{\"a\": 1}"));
        self::assertNull(LlmClient::decodeObject('non è JSON'));
    }
}
