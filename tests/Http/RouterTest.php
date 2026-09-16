<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Http;

use ManorLedger\Http\HttpException;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/login', static fn (Request $r): Response => Response::text('form'));
        $this->router->post('/login', static fn (Request $r): Response => Response::text('submitted'));
        $this->router->get('/healthz', static fn (Request $r): Response => Response::text('ok'));
    }

    public function testExactMatchDispatch(): void
    {
        self::assertSame('form', $this->router->dispatch(new Request('GET', '/login'))->body());
        self::assertSame('submitted', $this->router->dispatch(new Request('POST', '/login'))->body());
        self::assertSame('form', $this->router->dispatch(new Request('HEAD', '/login'))->body(), 'HEAD maps to GET');
    }

    /** @return iterable<string, array{string}> */
    public static function unknownPaths(): iterable
    {
        yield 'trailing slash' => ['/login/'];
        yield 'case' => ['/Login'];
        yield 'prefix' => ['/login/extra'];
        yield 'encoded' => ['/%6Cogin'];
        yield 'dotdot' => ['/../login'];
        yield 'root' => ['/'];
    }

    #[DataProvider('unknownPaths')]
    public function testUnknownPathIs404(string $path): void
    {
        try {
            $this->router->dispatch(new Request('GET', $path));
            self::fail('expected 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testMethodMismatchIs405WithAllow(): void
    {
        try {
            $this->router->dispatch(new Request('POST', '/healthz'));
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame(['Allow' => 'GET'], $e->headers());
        }
        try {
            $this->router->dispatch(new Request('DELETE', '/login'));
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame('GET, POST', $e->headers()['Allow']);
        }
    }
}
