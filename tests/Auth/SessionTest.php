<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\ArraySessionDriver;
use ManorLedger\Auth\Session;
use ManorLedger\Http\Request;
use ManorLedger\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private FrozenClock $clock;
    private ArraySessionDriver $driver;
    private Session $session;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1_000_000);
        $this->driver = new ArraySessionDriver();
        $this->session = new Session($this->driver, $this->clock, '/tmp/unused', 1800, 43200);
    }

    private function request(string $ua = 'Mozilla/5.0', bool $https = true): Request
    {
        return new Request('GET', '/', ['User-Agent' => $ua], [], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => $https ? 'on' : '']);
    }

    public function testLoginRegeneratesIdRotatesCsrfAndNeverStoresTheHash(): void
    {
        $this->session->start($this->request());
        $anonymousToken = $this->session->csrfToken();
        $this->session->login(['username' => 'alice', 'role' => 'owner', 'hash' => '$2y$secret', 'totpSecret' => 'X']);
        self::assertSame(1, $this->driver->regenerations);
        self::assertSame(['username' => 'alice', 'role' => 'owner'], $this->session->user());
        self::assertNotSame($anonymousToken, $this->session->csrfToken());
        self::assertStringNotContainsString('secret', json_encode($this->driver->all(), JSON_THROW_ON_ERROR));
        self::assertSame('__Host-manor_session', $this->driver->startedName);
        self::assertTrue($this->driver->startedCookie['secure']);
        self::assertSame('Strict', $this->driver->startedCookie['samesite']);
    }

    public function testPlainHttpDropsHostPrefixAndSecureFlag(): void
    {
        $this->session->start($this->request('UA', false));
        self::assertSame('manor_session', $this->driver->startedName);
        self::assertFalse($this->driver->startedCookie['secure']);
        self::assertTrue($this->driver->startedCookie['httponly']);
    }

    public function testIdleTimeoutExpiresSession(): void
    {
        $this->session->start($this->request());
        $this->session->login(['username' => 'alice', 'role' => 'viewer']);
        $this->clock->advance(1799);
        self::assertTrue($this->session->isStillValid($this->request()));
        $this->clock->advance(1);
        self::assertFalse($this->session->isStillValid($this->request()));
    }

    public function testActivityExtendsIdleButNotAbsoluteLifetime(): void
    {
        $this->session->start($this->request());
        $this->session->login(['username' => 'alice', 'role' => 'viewer']);
        for ($i = 0; $i < 28; $i++) {
            $this->clock->advance(1500);
            $this->driver = $this->restart();
        }
        self::assertNotNull($this->session->user(), 'kept alive by activity at 42000s');
        $this->clock->advance(1500);
        $this->restart();
        self::assertNull($this->session->user(), 'absolute lifetime of 43200s reached');
        self::assertSame(2, $this->driver->regenerations, 'invalidation regenerates the id instead of destroying');
        self::assertSame([], array_diff_key($this->driver->all(), ['csrf' => 1]), 'data cleared');
    }

    public function testUserAgentBinding(): void
    {
        $this->session->start($this->request('Firefox'));
        $this->session->login(['username' => 'alice', 'role' => 'viewer']);
        self::assertTrue($this->session->isStillValid($this->request('Firefox')));
        self::assertFalse($this->session->isStillValid($this->request('Chrome')));
        $this->restart('Chrome');
        self::assertNull($this->session->user());
    }

    public function testResumeOnlyWithCookie(): void
    {
        self::assertFalse($this->session->resume($this->request()));
        self::assertFalse($this->driver->isStarted());
        $withCookie = new Request('GET', '/', ['User-Agent' => 'UA'], [], [], ['__Host-manor_session' => 'abc'], ['REMOTE_ADDR' => '1.1.1.1', 'HTTPS' => 'on']);
        self::assertTrue($this->session->resume($withCookie));
        self::assertTrue($this->driver->isStarted());
    }

    public function testLogoutDestroys(): void
    {
        $this->session->start($this->request());
        $this->session->login(['username' => 'alice', 'role' => 'viewer']);
        $this->session->logout();
        self::assertSame(1, $this->driver->destroys);
        self::assertNull($this->session->user());
    }

    public function testStartBeforeUseIsEnforced(): void
    {
        $this->expectException(\LogicException::class);
        $this->session->csrfToken();
    }

    /** Simulates a new request re-opening the same session store. */
    private function restart(string $ua = 'Mozilla/5.0'): ArraySessionDriver
    {
        $reflection = new \ReflectionProperty(ArraySessionDriver::class, 'started');
        $reflection->setValue($this->driver, false);
        $this->session->start($this->request($ua));
        return $this->driver;
    }
}
