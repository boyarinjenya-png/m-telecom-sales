<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable,auth:bool,roles:list<string>}> */
    private array $routes = [];

    /** @param list<string> $roles */
    public function add(string $method, string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'auth' => $auth,
            'roles' => $roles,
        ];
    }

    public function get(string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->add('GET', $pattern, $handler, $auth, $roles);
    }

    public function post(string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->add('POST', $pattern, $handler, $auth, $roles);
    }

    public function put(string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->add('PUT', $pattern, $handler, $auth, $roles);
    }

    public function patch(string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->add('PATCH', $pattern, $handler, $auth, $roles);
    }

    public function delete(string $pattern, callable $handler, bool $auth = true, array $roles = []): void
    {
        $this->add('DELETE', $pattern, $handler, $auth, $roles);
    }

    /** @return array{handler:callable,auth:bool,roles:list<string>,params:array<string,string>}|null */
    public function match(Request $request): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method && $route['method'] !== 'ANY') {
                continue;
            }
            $params = $this->matchPattern($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }
            return [
                'handler' => $route['handler'],
                'auth' => $route['auth'],
                'roles' => $route['roles'],
                'params' => $params,
            ];
        }
        return null;
    }

    /** @return array<string,string>|null */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($pattern !== '/') {
            $pattern = rtrim($pattern, '/');
        }
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        if (!preg_match('#^' . $regex . '$#', $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = urldecode((string) $v);
            }
        }
        return $params;
    }
}
