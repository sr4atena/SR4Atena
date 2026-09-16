<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Http;

use ManorLedger\Auth\ArraySessionDriver;
use ManorLedger\Http\Kernel;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Http\SecurityHeaders;
use ManorLedger\Support\Config;
use ManorLedger\Support\FrozenClock;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** End-to-end through Kernel::handle() with an in-memory session: headers, routes, auth gates. */
final class KernelTest extends TestCase
{
    use TempDirTrait;

    private const ROOT = __DIR__ . '/../..';
    private string $dir;
    private ArraySessionDriver $driver;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->driver = new ArraySessionDriver();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function kernel(bool $debug = false, bool $broken = false): Kernel
    {
        $config = new Config([
            'app'   => ['name' => 'Manor Ledger', 'game' => "The Locust's Manor", 'host' => 'ledger.example', 'debug' => $debug],
            'paths' => ['users' => $this->dir . '/users.json', 'throttle' => $this->dir . '/throttle', 'sessions' => $this->dir . '/sessions',
                        'authLog' => $this->dir . '/auth.log', 'dashboard' => $broken ? 12345 : $this->dir . '/dashboard.json',
                        'voices' => $this->dir . '/voices.json', 'voicesMedia' => $this->dir . '/media/yt'],
            'auth'  => ['idleTimeout' => 1800, 'absoluteTimeout' => 43200, 'maxFailures' => 5, 'lockoutSeconds' => 900, 'cookieName' => '__Host-manor_session'],
        ]);
        return new Kernel($config, self::ROOT, new FrozenClock(1_700_000_000), $this->driver);
    }

    /** @param array<string, string> $post @param array<string, string> $headers */
    private function request(string $method, string $path, array $post = [], array $headers = []): Request
    {
        $headers += ['Host' => 'ledger.example', 'User-Agent' => 'UA'];
        // Once the in-memory session exists the browser would send its cookie back.
        $cookies = $this->driver->isStarted() ? ['__Host-manor_session' => $this->driver->id()] : [];
        return new Request($method, $path, $headers, [], $post, $cookies, ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
    }

    private function login(Kernel $kernel): void
    {
        file_put_contents($this->dir . '/users.json', json_encode(['demo' => [
            'username' => 'demo', 'hash' => password_hash('demo-password-123', PASSWORD_BCRYPT, ['cost' => 4]), 'role' => 'owner',
            'totpSecret' => null, 'createdAt' => 'x', 'lastLoginAt' => null,
        ]], JSON_THROW_ON_ERROR));
        $kernel->handle($this->request('GET', '/login'));
        $token = $this->driver->get('csrf');
        $response = $kernel->handle($this->request('POST', '/login', ['_csrf' => $token, 'username' => 'demo', 'password' => 'demo-password-123'], ['Origin' => 'https://ledger.example']));
        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function routes(): iterable
    {
        yield 'root unauthenticated' => ['GET', '/', 302];
        yield 'login form' => ['GET', '/login', 200];
        yield 'login post without csrf' => ['POST', '/login', 403];
        yield 'logout without csrf' => ['POST', '/logout', 403];
        yield 'api unauthenticated' => ['GET', '/api/dashboard', 401];
        yield 'voices unauthenticated' => ['GET', '/api/voices', 401];
        yield 'thumbnail unauthenticated' => ['GET', '/media/yt/O8eWFVZxgcI.jpg', 401];
        // The id is the only variable path segment in the application: anything
        // that is not exactly eleven id characters is not a route at all.
        yield 'thumbnail with a short id' => ['GET', '/media/yt/short.jpg', 404];
        yield 'thumbnail with a traversal' => ['GET', '/media/yt/../../users.json', 404];
        yield 'thumbnail without an extension' => ['GET', '/media/yt/O8eWFVZxgcI', 404];
        yield 'media prefix alone' => ['GET', '/media/yt', 404];
        yield 'healthz' => ['GET', '/healthz', 200];
        yield 'unknown' => ['GET', '/nope', 404];
        yield 'method mismatch' => ['DELETE', '/login', 405];
        yield 'method mismatch api' => ['POST', '/api/dashboard', 405];
    }

    #[DataProvider('routes')]
    public function testEveryResponseCarriesSecurityHeaders(string $method, string $path, int $status): void
    {
        $response = $this->kernel()->handle($this->request($method, $path));
        self::assertSame($status, $response->status());
        self::assertSame(SecurityHeaders::CSP, $response->header('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'self'", SecurityHeaders::CSP);
        self::assertSame('max-age=31536000; includeSubDomains', $response->header('Strict-Transport-Security'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        // same-origin, not no-referrer: stripping the Referer on same-origin
        // form posts leaves the CSRF origin check blind on browsers that omit
        // the Origin header, which made the login form unusable.
        self::assertSame('same-origin', $response->header('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->header('Permissions-Policy'));
        self::assertSame('same-origin', $response->header('Cross-Origin-Opener-Policy'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertStringNotContainsString('<script', $response->body(), 'no inline scripts anywhere');
        self::assertStringNotContainsString('style=', $response->body(), 'no inline styles anywhere');
        if ($status === 405) {
            self::assertNotNull($response->header('Allow'));
        }
    }

    public function testAnonymousScansCreateNoSession(): void
    {
        $kernel = $this->kernel();
        self::assertSame(302, $kernel->handle($this->request('GET', '/'))->status());
        self::assertSame(401, $kernel->handle($this->request('GET', '/api/dashboard'))->status());
        self::assertSame(404, $kernel->handle($this->request('GET', '/wp-login.php'))->status());
        self::assertFalse($this->driver->isStarted(), 'no cookie presented: nothing to resume, nothing created');
        $kernel->handle($this->request('GET', '/login'));
        self::assertTrue($this->driver->isStarted(), 'the login form needs a session for its CSRF token');
    }

    public function testHstsWithoutSubdomainsOnPlainHttp(): void
    {
        $request = new Request('GET', '/healthz', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $this->kernel()->handle($request);
        self::assertSame('max-age=31536000', $response->header('Strict-Transport-Security'));
        self::assertSame('ok', $response->body());
    }

    public function testLoginFormAndGenericError(): void
    {
        $kernel = $this->kernel();
        $form = $kernel->handle($this->request('GET', '/login'));
        self::assertStringContainsString('Analisi economica di The Locust&apos;s Manor', $form->body());
        self::assertStringContainsString('autocomplete="current-password"', $form->body());
        self::assertStringContainsString('name="_csrf" value="' . $this->driver->get('csrf') . '"', $form->body());
        self::assertStringNotContainsString('autofocus', $form->body());

        $token = $this->driver->get('csrf');
        $badOrigin = $kernel->handle($this->request('POST', '/login', ['_csrf' => $token, 'username' => 'x', 'password' => 'y'], ['Origin' => 'https://evil.example']));
        self::assertSame(403, $badOrigin->status());

        $wrong = $kernel->handle($this->request('POST', '/login', ['_csrf' => $token, 'username' => 'ghost', 'password' => 'y'], ['Origin' => 'https://ledger.example']));
        self::assertSame(200, $wrong->status());
        self::assertStringContainsString('Credenziali non valide', $wrong->body());
    }

    public function testAuthenticatedFlow(): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);

        $home = $kernel->handle($this->request('GET', '/'));
        self::assertSame(200, $home->status());
        self::assertStringContainsString('text/html', (string)$home->header('Content-Type'));

        $missing = $kernel->handle($this->request('GET', '/api/dashboard'));
        self::assertSame(503, $missing->status());
        self::assertSame('{"error":"dashboard not built yet"}', $missing->body());

        file_put_contents($this->dir . '/dashboard.json', '{"generatedAt":"x"}');
        $api = $kernel->handle($this->request('GET', '/api/dashboard'));
        self::assertSame(200, $api->status());
        self::assertSame($this->dir . '/dashboard.json', $api->filePath());
        self::assertNotNull($api->header('ETag'));
        $cached = $kernel->handle($this->request('GET', '/api/dashboard', [], ['If-None-Match' => (string)$api->header('ETag')]));
        self::assertSame(304, $cached->status());

        $logout = $kernel->handle($this->request('POST', '/logout', ['_csrf' => $this->driver->get('csrf')], ['Origin' => 'https://ledger.example']));
        self::assertSame(302, $logout->status());
        self::assertSame('/login', $logout->header('Location'));
        self::assertSame(1, $this->driver->destroys);
    }

    public function testVoicesAndThumbnailsAreServedOnlyToASession(): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);

        $missing = $kernel->handle($this->request('GET', '/api/voices'));
        self::assertSame(503, $missing->status());
        self::assertSame('{"error":"voices not built yet"}', $missing->body());

        file_put_contents($this->dir . '/voices.json', '{"generatedAt":"2026-09-17T06:03:11Z"}');
        $api = $kernel->handle($this->request('GET', '/api/voices'));
        self::assertSame(200, $api->status());
        self::assertSame($this->dir . '/voices.json', $api->filePath());
        self::assertSame('no-store', $api->header('Cache-Control'));
        self::assertSame(304, $kernel->handle($this->request('GET', '/api/voices', [], ['If-None-Match' => (string)$api->header('ETag')]))->status());

        // A well-formed id that has no file is a 404, not a 500 and not a path.
        self::assertSame(404, $kernel->handle($this->request('GET', '/media/yt/O8eWFVZxgcI.jpg'))->status());

        mkdir($this->dir . '/media/yt', 0755, true);
        file_put_contents($this->dir . '/media/yt/O8eWFVZxgcI.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('x', 64));
        $image = $kernel->handle($this->request('GET', '/media/yt/O8eWFVZxgcI.jpg'));
        self::assertSame(200, $image->status());
        self::assertSame('image/jpeg', $image->header('Content-Type'));
        // Cloudflare sits in front of this host: an authenticated image must never be cached publicly.
        self::assertSame('private, max-age=86400', $image->header('Cache-Control'));
    }

    public function testCrashIsGenericUnlessDebug(): void
    {
        // A mistyped config value throws while the object graph is built, i.e. inside handle()'s guard.
        $previousLog = ini_set('error_log', $this->dir . '/php-error.log');
        try {
            $this->assertCrashHandling();
        } finally {
            ini_set('error_log', (string)$previousLog);
        }
        self::assertStringContainsString('InvalidArgumentException: Config key paths.dashboard', (string)file_get_contents($this->dir . '/php-error.log'));
    }

    private function assertCrashHandling(): void
    {
        $response = $this->kernel(false, true)->handle($this->request('GET', '/login'));
        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('InvalidArgumentException', $response->body());
        self::assertStringContainsString('Si è verificato un errore', $response->body());
        self::assertSame(SecurityHeaders::CSP, $response->header('Content-Security-Policy'));

        $debug = $this->kernel(true, true)->handle($this->request('GET', '/login'));
        self::assertSame(500, $debug->status());
        self::assertStringContainsString('InvalidArgumentException', $debug->body());

        $api = $this->kernel(false, true)->handle($this->request('GET', '/api/dashboard'));
        self::assertSame(500, $api->status());
        self::assertSame('{"error":"errore interno"}', $api->body());
    }
}
