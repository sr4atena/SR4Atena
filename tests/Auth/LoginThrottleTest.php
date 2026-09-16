<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\LoginThrottle;
use ManorLedger\Support\FrozenClock;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    use TempDirTrait;

    private FrozenClock $clock;
    private LoginThrottle $throttle;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir() . '/throttle';
        $this->clock = new FrozenClock(1_000_000);
        $this->throttle = new LoginThrottle($this->dir, $this->clock, 5, 900);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function failTimes(int $n, string $ip = '203.0.113.7', string $user = 'alice'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->throttle->fail($ip, $user);
        }
    }

    public function testLocksAfterMaxFailuresAndEscalates(): void
    {
        $this->failTimes(4);
        self::assertNull($this->throttle->check('203.0.113.7', 'alice'));
        $this->failTimes(1);
        self::assertSame(900, $this->throttle->check('203.0.113.7', 'alice'));

        $this->clock->advance(899);
        self::assertSame(1, $this->throttle->check('203.0.113.7', 'alice'));
        $this->clock->advance(1);
        self::assertNull($this->throttle->check('203.0.113.7', 'alice'));

        $this->failTimes(5);
        self::assertSame(1800, $this->throttle->check('203.0.113.7', 'alice'), 'second lockout doubles');
        $this->clock->advance(1800);
        $this->failTimes(5);
        self::assertSame(3600, $this->throttle->check('203.0.113.7', 'alice'));
    }

    public function testLockoutIsCappedAt24Hours(): void
    {
        for ($round = 0; $round < 10; $round++) {
            $this->failTimes(5);
            $this->clock->advance($this->throttle->check('203.0.113.7', 'alice') ?? 0);
        }
        $this->failTimes(5);
        self::assertSame(LoginThrottle::MAX_LOCKOUT_SECONDS, $this->throttle->check('203.0.113.7', 'alice'));
    }

    public function testCountersAreIndependentPerIpAndPerUser(): void
    {
        $this->failTimes(5, '203.0.113.7', 'alice');
        self::assertSame(900, $this->throttle->check('203.0.113.7', 'bob'), 'same IP, other user: IP counter locks');
        self::assertSame(900, $this->throttle->check('198.51.100.9', 'alice'), 'other IP, same user: user counter locks');
        self::assertNull($this->throttle->check('198.51.100.9', 'bob'));
        self::assertSame(900, $this->throttle->check('198.51.100.9', 'ALICE'), 'username counter is case-insensitive');
    }

    public function testResetClearsBothCountersAndFiles(): void
    {
        $this->failTimes(5);
        $this->throttle->reset('203.0.113.7', 'alice');
        self::assertNull($this->throttle->check('203.0.113.7', 'alice'));
        self::assertSame([], glob($this->dir . '/*.json') ?: []);
        $this->failTimes(4);
        self::assertNull($this->throttle->check('203.0.113.7', 'alice'), 'escalation counter also reset');
    }

    public function testGcRemovesStaleRecordsOnly(): void
    {
        $this->failTimes(1, '203.0.113.7', 'alice');
        $this->clock->advance(3600);
        $this->failTimes(1, '198.51.100.9', 'bob');
        $this->clock->advance(LoginThrottle::MAX_LOCKOUT_SECONDS - 1800);
        self::assertSame(2, $this->throttle->gc(), 'ip:alice-ip and user:alice records are stale');
        self::assertCount(2, glob($this->dir . '/*.json') ?: []);
        self::assertSame('0600', substr(sprintf('%o', fileperms((glob($this->dir . '/*.json') ?: [])[0])), -4));
    }
}
