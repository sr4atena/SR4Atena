<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Http;

use ManorLedger\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(string $remote, array $headers = [], array $server = []): Request
    {
        return new Request('GET', '/', $headers, [], [], [], ['REMOTE_ADDR' => $remote] + $server);
    }

    public function testClientIpTrustsCloudflareHeaderOnlyFromLoopback(): void
    {
        self::assertSame('198.51.100.9', $this->request('127.0.0.1', ['CF-Connecting-IP' => '198.51.100.9'])->clientIp());
        self::assertSame('2001:db8::9', $this->request('::1', ['CF-Connecting-IP' => '2001:db8::9'])->clientIp());
        self::assertSame('198.51.100.9', $this->request('127.0.0.1', ['X-Real-IP' => '198.51.100.9'])->clientIp());
        self::assertSame('203.0.113.7', $this->request('203.0.113.7', ['CF-Connecting-IP' => '198.51.100.9'])->clientIp(), 'spoofed header from the internet is ignored');
        self::assertSame('10.0.0.5', $this->request('10.0.0.5', ['CF-Connecting-IP' => '198.51.100.9'])->clientIp(), 'private but not loopback');
        self::assertSame('127.0.0.1', $this->request('127.0.0.1')->clientIp());
        self::assertSame('127.0.0.1', $this->request('127.0.0.1', ['CF-Connecting-IP' => 'not-an-ip'])->clientIp());
        self::assertSame('127.0.0.1', $this->request('127.0.0.1', ['CF-Connecting-IP' => '1.2.3.4, 5.6.7.8'])->clientIp(), 'lists are not IPs');
        self::assertSame('203.0.113.7', $this->request('127.0.0.1', ['CF-Connecting-IP' => '', 'X-Real-IP' => '203.0.113.7'])->clientIp());
    }

    public function testIsHttps(): void
    {
        self::assertTrue($this->request('203.0.113.7', [], ['HTTPS' => 'on'])->isHttps());
        self::assertFalse($this->request('203.0.113.7', [], ['HTTPS' => 'off'])->isHttps());
        self::assertTrue($this->request('203.0.113.7', [], ['SERVER_PORT' => '443'])->isHttps());
        self::assertTrue($this->request('127.0.0.1', ['X-Forwarded-Proto' => 'https'])->isHttps());
        self::assertTrue($this->request('127.0.0.1', ['X-Forwarded-Proto' => 'https, http'])->isHttps());
        self::assertFalse($this->request('127.0.0.1', ['X-Forwarded-Proto' => 'http'])->isHttps());
        self::assertFalse($this->request('203.0.113.7', ['X-Forwarded-Proto' => 'https'])->isHttps(), 'forwarded proto from the internet is ignored');
        self::assertFalse($this->request('127.0.0.1')->isHttps());
    }

    public function testHeadersAreCaseInsensitiveAndPostRejectsArrays(): void
    {
        $request = new Request('POST', '/login', ['Content-Type' => 'x'], [], ['username' => ['a'], 'password' => 'p'], ['c' => '1'], []);
        self::assertSame('x', $request->header('content-type'));
        self::assertSame('x', $request->header('CONTENT-TYPE'));
        self::assertNull($request->post('username'));
        self::assertSame('p', $request->post('password'));
        self::assertSame('1', $request->cookie('c'));
        self::assertNull($request->post('missing'));
    }

    public function testHostStripsPort(): void
    {
        self::assertSame('localhost', $this->request('127.0.0.1', ['Host' => 'LOCALHOST:8099'])->host());
        self::assertSame('ledger.example', $this->request('127.0.0.1', ['Host' => 'ledger.example'])->host());
        self::assertNull($this->request('127.0.0.1')->host());
    }

    public function testFromGlobals(): void
    {
        $backup = [$_SERVER, $_GET, $_POST, $_COOKIE];
        $_SERVER = ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/login?x=1', 'HTTP_USER_AGENT' => 'UA', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        $_GET = ['x' => '1'];
        $_POST = ['u' => 'v'];
        $_COOKIE = [];
        try {
            $request = Request::fromGlobals();
            self::assertSame('POST', $request->method());
            self::assertSame('/login', $request->path());
            self::assertSame('UA', $request->header('user-agent'));
            self::assertSame('9.9.9.9', $request->clientIp());
            self::assertSame('1', $request->query('x'));
            self::assertSame('v', $request->post('u'));
            self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE] = $backup;
        }
    }
}
