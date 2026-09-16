<?php
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Authenticator;
use ManorLedger\Auth\AuthResult;
use ManorLedger\Auth\Csrf;
use ManorLedger\Auth\Session;
use ManorLedger\Http\HttpException;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Http\View;

final class LoginController
{
    private const ERROR_INVALID = 'Credenziali non valide.';

    public function __construct(
        private readonly Authenticator $auth,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly View $view,
        private readonly string $appName,
        private readonly string $gameName,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->session->start($request);
        if ($this->session->user() !== null) {
            return Response::redirect('/');
        }
        return $this->form($this->auth->hasPendingTotp());
    }

    public function submit(Request $request): Response
    {
        $this->session->start($request);
        if ($this->session->user() !== null) {
            return Response::redirect('/');
        }
        $this->assertCsrf($request);

        $totpCode = $request->post('totp');
        $totpStep = $totpCode !== null && $this->auth->hasPendingTotp();
        $result = $totpStep
            ? $this->auth->completeTotp($totpCode, $request->clientIp())
            : $this->auth->attempt($request->post('username') ?? '', $request->post('password') ?? '', $request->clientIp());

        return match ($result->status) {
            AuthResult::OK         => Response::redirect('/'),
            AuthResult::NEEDS_TOTP => $this->form(true),
            AuthResult::LOCKED     => $this->form(false, sprintf(
                'Troppi tentativi. Riprova tra %d %s.',
                $result->retryAfterMinutes(),
                $result->retryAfterMinutes() === 1 ? 'minuto' : 'minuti',
            )),
            default                => $this->form($totpStep && $this->auth->hasPendingTotp(), self::ERROR_INVALID),
        };
    }

    public function logout(Request $request): Response
    {
        $this->session->start($request);
        $this->assertCsrf($request);
        $this->auth->logout($request->clientIp());
        return Response::redirect('/login');
    }

    /** Both checks must pass; the same 403 for either keeps the failure mode opaque. */
    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->sameOrigin($request) || !$this->csrf->validate($request->post('_csrf'))) {
            throw new HttpException(403, 'Richiesta non valida: ricarica la pagina e riprova.');
        }
    }

    private function form(bool $needsTotp, ?string $error = null): Response
    {
        $html = $this->view->page('login', [
            'appName'   => $this->appName,
            'gameName'  => $this->gameName,
            'csrfToken' => $this->csrf->token(),
            'needsTotp' => $needsTotp,
            'error'     => $error,
        ], $this->appName . ' · Accesso', ['/assets/css/login.css']);
        return Response::html($html);
    }
}
