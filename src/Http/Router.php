<?php

declare(strict_types=1);

namespace Tbank\Invest\Http;

use Tbank\Invest\Exception\TInvestException;

final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): self
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)}/', static function (array $m) use (&$params) {
            $params[] = $m[1];

            return '([^/]+)';
        }, rtrim($path, '/') ?: '/') ?? $path;
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $path = rtrim($request->path, '/') ?: '/';
        $allow = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $allow[] = $route['method'];
            if ($route['method'] !== $request->method) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = urldecode((string) ($matches[$i] ?? ''));
            }
            $result = ($route['handler'])($request->withParams($params));
            if ($result instanceof Response) {
                return $result;
            }

            return Response::json($result);
        }

        if ($allow !== []) {
            throw new TInvestException('Method not allowed', 405, ['allow' => array_values(array_unique($allow))], null, 'method_not_allowed');
        }

        throw new TInvestException('Not found', 404, null, null, 'not_found');
    }
}
