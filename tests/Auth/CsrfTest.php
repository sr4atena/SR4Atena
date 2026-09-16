<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\ArraySessionDriver;
use ManorLedger\Auth\Csrf;
use ManorLedger\Auth\Session;
use ManorLedger\Http\Request;
use ManorLedger\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new ArraySessionDriver(), new FrozenClock(0), '/tmp/unused', 1800, 43200);
        $this->session->start(new Request('POST', '/login', ['Host' => 'ledger.example'], [], [], [], ['REMOTE_ADDR' => '1.2.3.4']));
    }

    public function testTokenFormatAndValidation(): void
    {
        $csrf = new Csrf($this->session, 'ledger.example');
        $token = $csrf->token();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token, 'base64url of 32 bytes');
        self::assertSame($token, $csrf->token(), 'stable within the session');
        self::assertTrue($csrf->validate($token));
        self::assertFalse($csrf->validate($token . 'x'));
        self::assertFalse($csrf->validate(''));
        self::assertFalse($csrf->validate(null));
        self::assertNotSame($token, Csrf::generateToken());
    }

    /** @return iterable<string, array{string, array<string, string>, bool}> */
    public static function origins(): iterable
    {
        yield 'origin matches' => ['ledger.example', ['Origin' => 'https://ledger.example'], true];
        yield 'origin case-insensitive' => ['ledger.example', ['Origin' => 'https://Ledger.Example'], true];
        yield 'origin other host' => ['ledger.example', ['Origin' => 'https://evil.example'], false];
        yield 'origin subdomain' => ['ledger.example', ['Origin' => 'https://ledger.example.evil.example'], false];
        yield 'origin null' => ['ledger.example', ['Origin' => 'null'], false];
        yield 'origin wins over referer' => ['ledger.example', ['Origin' => 'https://evil.example', 'Referer' => 'https://ledger.example/login'], false];
        yield 'referer fallback' => ['ledger.example', ['Referer' => 'https://ledger.example/login'], true];
        yield 'referer other host' => ['ledger.example', ['Referer' => 'https://evil.example/ledger.example'], false];
        yield 'no headers' => ['ledger.example', [], false];
        yield 'localhost accepts request host' => ['localhost', ['Origin' => 'http://127.0.0.1:8099', 'Host' => '127.0.0.1:8099'], true];
        yield 'localhost accepts localhost' => ['localhost', ['Origin' => 'http://localhost:8099', 'Host' => '127.0.0.1:8099'], true];
        yield 'localhost rejects foreign origin' => ['localhost', ['Origin' => 'http://evil.example', 'Host' => '127.0.0.1:8099'], false];
        yield 'prod ignores request host' => ['ledger.example', ['Origin' => 'http://evil.example', 'Host' => 'evil.example'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('origins')]
    public function testSameOrigin(string $appHost, array $headers, bool $expected): void
    {
        $csrf = new Csrf($this->session, $appHost);
        $request = new Request('POST', '/login', $headers, [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);
        self::assertSame($expected, $csrf->sameOrigin($request));
    }
}
