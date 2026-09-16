<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\ArraySessionDriver;
use ManorLedger\Auth\AuditLog;
use ManorLedger\Auth\Authenticator;
use ManorLedger\Auth\AuthResult;
use ManorLedger\Auth\LoginThrottle;
use ManorLedger\Auth\PasswordHasher;
use ManorLedger\Auth\Session;
use ManorLedger\Auth\Totp;
use ManorLedger\Auth\UserStore;
use ManorLedger\Http\Request;
use ManorLedger\Support\FrozenClock;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    use TempDirTrait;

    private const IP = '203.0.113.7';
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private FrozenClock $clock;
    private ArraySessionDriver $driver;
    private Session $session;
    private UserStore $users;
    private Totp $totp;
    private Authenticator $auth;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->clock = new FrozenClock(1_700_000_000);
        $this->driver = new ArraySessionDriver();
        $this->session = new Session($this->driver, $this->clock, $this->dir . '/sessions', 1800, 43200);
        $this->session->start(new Request('POST', '/login', ['User-Agent' => 'UA'], [], [], [], ['REMOTE_ADDR' => self::IP]));
        $this->users = new UserStore($this->dir . '/users.json', $this->clock);
        $this->totp = new Totp($this->clock);
        // bcrypt cost 4 keeps the suite fast; the algorithm choice is covered by PasswordHasherTest.
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->users->add('alice', password_hash('correct-password-1', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->users->add('bob', password_hash('correct-password-2', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->users->setTotp('bob', self::SECRET);
        $this->auth = new Authenticator(
            $this->users,
            $hasher,
            new LoginThrottle($this->dir . '/throttle', $this->clock, 5, 600),
            $this->session,
            new AuditLog($this->dir . '/auth.log', $this->clock),
            $this->totp,
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function auditLog(): string
    {
        return (string)file_get_contents($this->dir . '/auth.log');
    }

    public function testSuccessfulLogin(): void
    {
        $result = $this->auth->attempt('Alice ', 'correct-password-1', self::IP);
        self::assertSame(AuthResult::OK, $result->status);
        self::assertSame('alice', $this->session->user()['username']);
        self::assertSame(1, $this->driver->regenerations);
        self::assertNotNull($this->users->find('alice')['lastLoginAt']);
        self::assertStringContainsString('event=login.ok user=alice ip=203.0.113.7 role=viewer', $this->auditLog());
        self::assertStringNotContainsString('correct-password', $this->auditLog());
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/auth.log')), -4));
    }

    public function testWrongPasswordAndUnknownUserAreIndistinguishable(): void
    {
        $wrong = $this->auth->attempt('alice', 'nope', self::IP);
        $unknown = $this->auth->attempt('mallory', 'nope', self::IP);
        $malformed = $this->auth->attempt('../etc/passwd', 'nope', self::IP);
        self::assertSame(AuthResult::INVALID, $wrong->status);
        self::assertSame(AuthResult::INVALID, $unknown->status);
        self::assertSame(AuthResult::INVALID, $malformed->status);
        self::assertNull($this->session->user());
        self::assertSame(3, substr_count($this->auditLog(), 'event=login.fail'));
        self::assertStringContainsString('user=../etc/passwd', $this->auditLog(), 'sanitised but recorded');
    }

    public function testLockoutAfterRepeatedFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(AuthResult::INVALID, $this->auth->attempt('alice', 'nope', self::IP)->status);
        }
        $fifth = $this->auth->attempt('alice', 'nope', self::IP);
        self::assertSame(AuthResult::LOCKED, $fifth->status, 'the failure that reaches the limit reports the lockout at once');
        self::assertSame(600, $fifth->retryAfter);
        self::assertSame(10, $fifth->retryAfterMinutes());
        self::assertStringContainsString('event=login.lockout user=alice ip=203.0.113.7 seconds=600', $this->auditLog());

        $locked = $this->auth->attempt('alice', 'correct-password-1', self::IP);
        self::assertSame(AuthResult::LOCKED, $locked->status, 'even the right password is refused while locked');
        self::assertNull($this->session->user());
        self::assertStringContainsString('event=login.locked', $this->auditLog());

        $this->clock->advance(600);
        self::assertTrue($this->auth->attempt('alice', 'correct-password-1', self::IP)->isOk());
        self::assertSame(AuthResult::INVALID, $this->fresh()->attempt('alice', 'nope', self::IP)->status, 'counters reset by success');
    }

    public function testTotpFlow(): void
    {
        $result = $this->auth->attempt('bob', 'correct-password-2', self::IP);
        self::assertSame(AuthResult::NEEDS_TOTP, $result->status);
        self::assertNull($this->session->user(), 'not logged in until the code is verified');
        self::assertTrue($this->auth->hasPendingTotp());

        $wrong = $this->auth->completeTotp('000000', self::IP);
        self::assertSame(AuthResult::INVALID, $wrong->status);
        self::assertTrue($this->auth->hasPendingTotp(), 'still allowed to retry');

        $code = $this->totp->codeForStep(self::SECRET, $this->totp->currentStep());
        self::assertTrue($this->auth->completeTotp($code, self::IP)->isOk());
        self::assertSame('bob', $this->session->user()['username']);
        self::assertStringContainsString('event=login.fail user=bob ip=203.0.113.7 reason=totp', $this->auditLog());
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        $code = $this->totp->codeForStep(self::SECRET, $this->totp->currentStep());
        $this->auth->attempt('bob', 'correct-password-2', self::IP);
        self::assertTrue($this->auth->completeTotp($code, self::IP)->isOk());
        $this->auth->logout(self::IP);
        self::assertSame($this->totp->currentStep(), $this->users->find('bob')['totpLastStep']);
        // A brand-new session (post-logout) must still refuse the code: the marker lives on the user record too.
        $auth = $this->fresh();
        self::assertSame(AuthResult::NEEDS_TOTP, $auth->attempt('bob', 'correct-password-2', self::IP)->status);
        self::assertSame(AuthResult::INVALID, $auth->completeTotp($code, self::IP)->status);
        $this->clock->advance(30);
        $next = $this->totp->codeForStep(self::SECRET, $this->totp->currentStep());
        self::assertTrue($auth->completeTotp($next, self::IP)->isOk(), 'the following step is accepted');
    }

    public function testTotpWithoutPendingOrExpiredPendingIsInvalid(): void
    {
        self::assertSame(AuthResult::INVALID, $this->auth->completeTotp('123456', self::IP)->status);
        $this->auth->attempt('bob', 'correct-password-2', self::IP);
        $this->clock->advance(301);
        self::assertFalse($this->auth->hasPendingTotp());
        self::assertSame(AuthResult::INVALID, $this->auth->completeTotp('123456', self::IP)->status);
    }

    public function testLogoutWritesAudit(): void
    {
        $this->auth->attempt('alice', 'correct-password-1', self::IP);
        $this->auth->logout(self::IP);
        self::assertNull($this->session->user());
        self::assertStringContainsString('event=logout user=alice', $this->auditLog());
    }

    /** New session on the same stores, as the next HTTP request would see it. */
    private function fresh(): Authenticator
    {
        $this->driver = new ArraySessionDriver();
        $this->session = new Session($this->driver, $this->clock, $this->dir . '/sessions', 1800, 43200);
        $this->session->start(new Request('POST', '/login', ['User-Agent' => 'UA'], [], [], [], ['REMOTE_ADDR' => self::IP]));
        return new Authenticator(
            $this->users,
            new PasswordHasher(PASSWORD_BCRYPT),
            new LoginThrottle($this->dir . '/throttle', $this->clock, 5, 600),
            $this->session,
            new AuditLog($this->dir . '/auth.log', $this->clock),
            $this->totp,
            $this->clock,
        );
    }
}
