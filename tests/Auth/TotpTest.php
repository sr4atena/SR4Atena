<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Auth;

use ManorLedger\Auth\Totp;
use ManorLedger\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC6238_SECRET = '12345678901234567890';

    /** RFC 6238 Appendix B, SHA-1 rows. @return iterable<string, array{int, string}> */
    public static function rfc6238Vectors(): iterable
    {
        yield 'T=59' => [59, '94287082'];
        yield 'T=1111111109' => [1111111109, '07081804'];
        yield 'T=1111111111' => [1111111111, '14050471'];
        yield 'T=1234567890' => [1234567890, '89005924'];
        yield 'T=2000000000' => [2000000000, '69279037'];
        yield 'T=20000000000' => [20000000000, '65353130'];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testRfc6238Sha1Vectors(int $time, string $expected8): void
    {
        $step = intdiv($time, Totp::PERIOD);
        self::assertSame($expected8, Totp::hotp(self::RFC6238_SECRET, $step, 8));
        $totp = new Totp(new FrozenClock($time));
        $secret = Totp::base32Encode(self::RFC6238_SECRET, false);
        self::assertSame(substr($expected8, -6), $totp->codeForStep($secret, $step));
        self::assertSame($step, $totp->verify($secret, substr($expected8, -6)));
    }

    /** RFC 4648 section 10. @return iterable<string, array{string, string}> */
    public static function base32Vectors(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'f' => ['f', 'MY======'];
        yield 'fo' => ['fo', 'MZXQ===='];
        yield 'foo' => ['foo', 'MZXW6==='];
        yield 'foob' => ['foob', 'MZXW6YQ='];
        yield 'fooba' => ['fooba', 'MZXW6YTB'];
        yield 'foobar' => ['foobar', 'MZXW6YTBOI======'];
    }

    #[DataProvider('base32Vectors')]
    public function testBase32Vectors(string $plain, string $encoded): void
    {
        self::assertSame($encoded, Totp::base32Encode($plain));
        self::assertSame($plain, Totp::base32Decode($encoded));
        self::assertSame($plain, Totp::base32Decode(rtrim(strtolower($encoded), '=')), 'unpadded lower-case input');
    }

    public function testWindowAndReplayRejection(): void
    {
        $clock = new FrozenClock(1_700_000_000);
        $totp = new Totp($clock);
        $secret = $totp->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret, '20 bytes -> 32 base32 chars, no padding');
        $step = $totp->currentStep();

        self::assertSame($step - 1, $totp->verify($secret, $totp->codeForStep($secret, $step - 1)));
        self::assertSame($step + 1, $totp->verify($secret, $totp->codeForStep($secret, $step + 1)));
        self::assertNull($totp->verify($secret, $totp->codeForStep($secret, $step - 2)));
        self::assertNull($totp->verify($secret, $totp->codeForStep($secret, $step + 2)));

        $code = $totp->codeForStep($secret, $step);
        self::assertSame($step, $totp->verify($secret, $code, $step - 1));
        self::assertNull($totp->verify($secret, $code, $step), 'same step already used');
        self::assertNull($totp->verify($secret, $totp->codeForStep($secret, $step - 1), $step), 'older step after newer');

        self::assertNull($totp->verify($secret, '12345'));
        self::assertNull($totp->verify($secret, '12345a'));
        self::assertNull($totp->verify($secret, ''));
    }

    public function testOtpauthUri(): void
    {
        $uri = (new Totp(new FrozenClock(0)))->otpauthUri('Manor Ledger', 'alice', 'JBSWY3DPEHPK3PXP');
        self::assertSame(
            'otpauth://totp/Manor%20Ledger:alice?secret=JBSWY3DPEHPK3PXP&issuer=Manor%20Ledger&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }
}
