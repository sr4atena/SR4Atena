<?php
/**
 * RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30 s) plus RFC 4648 base32, without
 * dependencies. Only SHA-1 is implemented because that is what authenticator
 * apps universally support.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use InvalidArgumentException;
use ManorLedger\Support\Clock;

final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly Clock $clock)
    {
    }

    /** 160-bit secret as unpadded base32, the format authenticator apps expect. */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20), false);
    }

    public function currentStep(): int
    {
        return intdiv($this->clock->now(), self::PERIOD);
    }

    public function codeForStep(string $base32Secret, int $step): string
    {
        return self::hotp(self::base32Decode($base32Secret), $step, self::DIGITS);
    }

    /**
     * Returns the matched time step when $code is valid within ±WINDOW steps
     * and newer than $lastUsedStep, otherwise null. Callers persist the returned
     * step so the same code cannot be replayed within its validity window.
     */
    public function verify(string $base32Secret, string $code, ?int $lastUsedStep = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($base32Secret);
        $now = $this->currentStep();
        $matched = null;
        // Always evaluate every candidate so timing is independent of which one matches.
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $now + $offset;
            if (hash_equals(self::hotp($key, $step, self::DIGITS), $code) && $matched === null) {
                $matched = $step;
            }
        }
        if ($matched === null || ($lastUsedStep !== null && $matched <= $lastUsedStep)) {
            return null;
        }
        return $matched;
    }

    public function otpauthUri(string $issuer, string $account, string $base32Secret): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/{$label}?{$query}";
    }

    /** RFC 4226 HOTP over a raw binary key. */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS): string
    {
        $mac = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($mac[19]) & 0x0f;
        $binary = ((ord($mac[$offset]) & 0x7f) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);
        return str_pad((string)($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $binary, bool $pad = true): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        if ($pad && $out !== '' && strlen($out) % 8 !== 0) {
            $out .= str_repeat('=', 8 - strlen($out) % 8);
        }
        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(rtrim($encoded, '='));
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^[A-Z2-7]+$/', $clean) !== 1) {
            throw new InvalidArgumentException('Invalid base32 input');
        }
        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
