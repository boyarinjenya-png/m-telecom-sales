<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

final class Request
{
    public string $method;
    public string $path;
    public string $rawBody;
    /** @var array<string,mixed> */
    public array $query;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,string> */
    public array $headers;
    /** @var array<string,mixed> */
    public array $cookies;
    /** @var array<string,mixed> */
    public array $files;
    public string $ip;
    public string $userAgent;
    /** @var array<string,string> */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $files
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        string $rawBody = '',
        string $ip = '127.0.0.1',
        string $userAgent = 'cli'
    ) {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim($path, '/');
        if ($this->path !== '/') {
            $this->path = rtrim($this->path, '/');
        }
        $this->query = $query;
        $this->body = $body;
        $this->headers = [];
        foreach ($headers as $k => $v) {
            $this->headers[strtolower((string) $k)] = (string) $v;
        }
        $this->cookies = $cookies;
        $this->files = $files;
        $this->rawBody = $rawBody;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = $_POST;
        $ct = strtolower($headers['content-type'] ?? '');
        if ($body === [] && str_contains($ct, 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return new self($method, $path, $_GET, $body, $headers, $_COOKIE, $_FILES, $raw, $ip, $ua);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) {
            return trim($m[1]);
        }
        return $this->query['access_token'] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, ?string $default = null): ?string
    {
        $v = $this->input($key, $default);
        if ($v === null) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->input($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }
}
