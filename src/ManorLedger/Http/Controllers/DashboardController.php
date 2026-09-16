<?php
/**
 * The one HTML page behind the session: a shell that carries no figures.
 *
 * Everything the view shows arrives later from /api/dashboard, so the markup
 * holds no data to leak into a cache or a referrer and the page is identical
 * for every user. It falls back to a placeholder template so a deploy without
 * a built frontend still answers.
 */
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Session;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Http\View;

final class DashboardController
{
    public function __construct(
        private readonly Session $session,
        private readonly View $view,
        private readonly string $appName,
        private readonly string $gameName,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->session->resume($request) ? $this->session->user() : null;
        if ($user === null) {
            return Response::redirect('/login');
        }
        $vars = [
            'user'      => $user,
            'csrfToken' => $this->session->csrfToken(),
            'appName'   => $this->appName,
            'gameName'  => $this->gameName,
        ];
        if (!$this->view->exists('dashboard')) {
            $html = $this->view->page('placeholder', $vars, $this->appName);
            return Response::html($html);
        }
        $html = $this->view->render('dashboard', $vars);
        // The dashboard template may be a full document or a fragment; wrap only the latter.
        if (stripos(ltrim($html), '<!doctype') !== 0 && stripos(ltrim($html), '<html') !== 0) {
            $html = $this->view->render('layout', ['title' => $this->appName, 'content' => $html, 'styles' => []]);
        }
        return Response::html($html);
    }
}
