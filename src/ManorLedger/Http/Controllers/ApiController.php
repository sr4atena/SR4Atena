<?php
/**
 * The dashboard document, and nothing else.
 *
 * The browser never queries Roblox and this endpoint never computes: it
 * streams the file bin/build produced, so a page load costs one authorised
 * read and a slow or rate-limited API can only ever make the data old, not the
 * site unavailable. A missing file is a 503 with Retry-After, not an empty
 * dashboard.
 */
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Session;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;

final class ApiController
{
    public function __construct(private readonly Session $session, private readonly string $dashboardFile)
    {
    }

    public function dashboard(Request $request): Response
    {
        if (!$this->session->resume($request) || $this->session->user() === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if (!is_file($this->dashboardFile)) {
            return Response::json(['error' => 'dashboard not built yet'], 503)
                ->withHeader('Retry-After', '300');
        }
        return Response::file($this->dashboardFile, 'application/json; charset=UTF-8', $request)
            ->withHeader('Cache-Control', 'no-store');
    }
}
