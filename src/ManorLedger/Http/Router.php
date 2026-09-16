<?php
/**
 * Exact-match routing table. No patterns, no parameters: the application has
 * six URLs and anything else is a 404 before it reaches any code.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> path => method => handler */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$path][strtoupper($method)] = $handler;
    }

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @throws HttpException 404 for unknown paths, 405 (with Allow) for known paths and wrong methods */
    public function dispatch(Request $request): Response
    {
        $methods = $this->routes[$request->path()] ?? null;
        if ($methods === null) {
            throw new HttpException(404, 'Not Found');
        }
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $handler = $methods[$method] ?? null;
        if ($handler === null) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', array_keys($methods))]);
        }
        return $handler($request);
    }
}
