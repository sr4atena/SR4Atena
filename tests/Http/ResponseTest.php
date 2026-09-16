<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Http;

use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testFileStreamingWithEtagAndConditionalRequests(): void
    {
        $path = $this->makeTempDir() . '/dashboard.json';
        file_put_contents($path, '{"a":1}');
        touch($path, 1_700_000_000);

        $full = Response::file($path, 'application/json; charset=UTF-8', new Request('GET', '/api/dashboard'));
        self::assertSame(200, $full->status());
        self::assertSame($path, $full->filePath());
        self::assertSame('7', $full->header('Content-Length'));
        self::assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $full->header('Last-Modified'));
        $etag = $full->header('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);

        $cached = Response::file($path, 'application/json', new Request('GET', '/api/dashboard', ['If-None-Match' => $etag]));
        self::assertSame(304, $cached->status());
        self::assertSame('', $cached->body());
        self::assertNull($cached->filePath());
        self::assertSame($etag, $cached->header('ETag'));

        $weak = Response::file($path, 'application/json', new Request('GET', '/api/dashboard', ['If-None-Match' => 'W/' . $etag . ', "other"']));
        self::assertSame(304, $weak->status());
        $stale = Response::file($path, 'application/json', new Request('GET', '/api/dashboard', ['If-None-Match' => '"other"']));
        self::assertSame(200, $stale->status());
        $since = Response::file($path, 'application/json', new Request('GET', '/api/dashboard', ['If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT']));
        self::assertSame(304, $since->status());

        file_put_contents($path, '{"a":12}');
        touch($path, 1_700_000_001);
        self::assertNotSame($etag, Response::file($path, 'application/json')->header('ETag'), 'ETag changes with the file');
    }

    public function testRedirectRefusesOffSiteTargets(): void
    {
        self::assertSame('/login', Response::redirect('/login')->header('Location'));
        self::assertSame(302, Response::redirect('/login')->status());
        $this->expectException(\RuntimeException::class);
        Response::redirect('//evil.example/');
    }

    public function testHeaderNamesAreCanonicalised(): void
    {
        $response = Response::json(['ok' => true])->withHeader('cache-control', 'no-store');
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertArrayHasKey('ETag', $response->withHeader('etag', '"x"')->headers());
        self::assertTrue($response->hasHeader('CACHE-CONTROL'));
        self::assertSame('{"ok":true}', $response->body());
        self::assertSame('application/json; charset=UTF-8', $response->header('content-type'));
    }
}
