<?php
/**
 * Defensive headers stamped on every response, including errors and
 * redirects. Values are fixed by ARCHITECTURE.md; the CSP allows no inline
 * script or style at all, so templates must not use either.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

final class SecurityHeaders
{
    public const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";

    public function __construct(private readonly bool $https)
    {
    }

    public function apply(Response $response): Response
    {
        $hsts = 'max-age=31536000' . ($this->https ? '; includeSubDomains' : '');
        $fixed = [
            'Content-Security-Policy'   => self::CSP,
            'Strict-Transport-Security' => $hsts,
            'X-Content-Type-Options'    => 'nosniff',
            'X-Frame-Options'           => 'DENY',
            'Referrer-Policy'           => 'no-referrer',
            'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy'   => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        foreach ($fixed as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if (!$response->hasHeader('Cache-Control') && $this->isSensitive($response)) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }
        return $response;
    }

    /** HTML, JSON and redirects carry session-dependent content; static assets are nginx's job. */
    private function isSensitive(Response $response): bool
    {
        $type = strtolower($response->header('Content-Type') ?? '');
        return $type === '' || str_starts_with($type, 'text/html') || str_starts_with($type, 'application/json')
            || $response->hasHeader('Location');
    }
}
