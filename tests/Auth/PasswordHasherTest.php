<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testPrefersArgon2idWhenAvailable(): void
    {
        $hasher = new PasswordHasher();
        if (!defined('PASSWORD_ARGON2ID')) {
            fwrite(STDERR, "\n[warning] argon2id unavailable in this PHP build; bcrypt fallback under test\n");
            self::assertSame(PASSWORD_BCRYPT, $hasher->algorithm());
            return;
        }
        self::assertTrue($hasher->usesArgon2id());
        $hash = $hasher->hash('correct horse battery staple');
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash);
    }

    public function testRoundTrip(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('S3cret-passphrase!');
        self::assertTrue($hasher->verify('S3cret-passphrase!', $hash));
        self::assertFalse($hasher->verify('S3cret-passphrase?', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testBcryptFallbackRoundTripAndRehashDetection(): void
    {
        $bcrypt = new PasswordHasher(PASSWORD_BCRYPT);
        $hash = $bcrypt->hash('S3cret-passphrase!');
        self::assertStringStartsWith('$2y$12$', $hash);
        self::assertTrue($bcrypt->verify('S3cret-passphrase!', $hash));
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertTrue((new PasswordHasher())->needsRehash($hash), 'bcrypt hash must be upgraded on login');
        }
    }

    public function testUnknownUserVerifyIsFalseButStillCostsAHash(): void
    {
        $hasher = new PasswordHasher();
        $dummy = $hasher->dummyHash();
        self::assertNotNull(password_get_info($dummy)['algo']);
        $start = hrtime(true);
        self::assertFalse($hasher->verify('anything', null));
        $unknown = hrtime(true) - $start;
        $start = hrtime(true);
        self::assertFalse($hasher->verify('anything', $hasher->hash('other-password-123')));
        $known = hrtime(true) - $start;
        // Both paths run one real verification; the unknown path must not be a short-circuit.
        self::assertGreaterThan($known / 4, $unknown);
    }

    public function testRefusesEmptyPassword(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PasswordHasher())->hash('');
    }
}
