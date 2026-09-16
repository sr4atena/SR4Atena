<?php
/**
 * Synchroniser token plus an Origin/Referer check. Both are required on every
 * POST: the token defends against forged forms, the origin check against a
 * token leaked by a compromised same-site page or a misconfigured proxy.
 */
declare(strict_types=1);

namespace ManorLedger\Auth;

use ManorLedger\Http\Request;

final class Csrf
{
    public function __construct(private readonly Session $session, private readonly string $appHost)
    {
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function token(): string
    {
        return $this->session->csrfToken();
    }

    public function validate(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals($this->session->csrfToken(), $submitted);
    }

    /**
     * Origin wins when present; Referer is the fallback for older clients.
     * A request carrying neither is rejected: browsers always send Origin on
     * same-origin form POSTs.
     */
    public function sameOrigin(Request $request): bool
    {
        $source = $request->header('Origin') ?? $request->header('Referer');
        if ($source === null || $source === '' || $source === 'null') {
            return false;
        }
        $host = parse_url($source, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        return in_array(strtolower($host), $this->allowedHosts($request), true);
    }

    /** @return list<string> */
    private function allowedHosts(Request $request): array
    {
        $allowed = [strtolower($this->appHost)];
        if ($this->appHost === 'localhost') {
            // Local development only: the dev server answers on 127.0.0.1 as well as localhost.
            $requestHost = $request->host();
            if ($requestHost !== null) {
                $allowed[] = strtolower($requestHost);
            }
        }
        return $allowed;
    }
}
