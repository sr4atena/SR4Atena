<?php
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
