<?php

declare(strict_types=1);

namespace Firol\Http;

/**
 * Tiny regex-based router. Pattern syntax: `/api/foo/{id}` — `id` becomes
 * a string entry in the params array passed to the handler.
 *
 * The handler receives (Request $request, array $params) and is expected to
 * call into Response::json() / Response::noContent() / Response::error()
 * which exit the request.
 */
final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    public function get(string $pattern, callable $handler): void    { $this->add('GET',    $pattern, $handler); }
    public function post(string $pattern, callable $handler): void   { $this->add('POST',   $pattern, $handler); }
    public function patch(string $pattern, callable $handler): void  { $this->add('PATCH',  $pattern, $handler); }
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = rtrim($request->path(), '/') ?: '/';

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter(
                    $matches,
                    static fn ($k) => is_string($k),
                    ARRAY_FILTER_USE_KEY,
                );
                $handler($request, $params);
                return;
            }
        }

        Response::error('Not Found', 404, ['path' => $path]);
    }
}
