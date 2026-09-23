<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            if (!class_exists($handler[0])) {
                throw new \LogicException('Route controller does not exist: ' . $handler[0]);
            }
            if (!isset($handler[1]) || !is_string($handler[1]) || !method_exists($handler[0], $handler[1])) {
                throw new \LogicException(
                    'Route controller method does not exist: ' . $handler[0] . '::' . (string) ($handler[1] ?? '')
                );
            }
        }

        $pattern = $this->normalizePath($pattern);
        $segments = explode('/', trim($pattern, '/'));
        $parts = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match)) {
                $parts[] = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        $regex = $pattern === '/' ? '#^/$#' : '#^/' . implode('/', $parts) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(Request $request, Application $app): Response
    {
        $path = $this->normalizePath($request->path());

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[] = rawurldecode((string) $value);
                }
            }

            $handler = $route['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $controller = new $handler[0]($app);
                $handler = [$controller, $handler[1]];
            }

            $response = $handler($request, ...$params);
            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handlers must return a Response instance.');
            }

            return $response;
        }

        return $request->expectsJson()
            ? Response::json(['error' => ['code' => 'not_found', 'message' => 'Resource not found.']], 404)
            : Response::html($app->view()->render('errors/404', ['title' => 'Page not found']), 404);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
