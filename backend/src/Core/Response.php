<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

final class Response
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    public string $body = '';
    public bool $isFile = false;
    public ?string $filePath = null;

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['Cache-Control'] = 'no-store';
        $r->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return $r;
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    public static function ok(mixed $data = [], int $status = 200): self
    {
        $payload = is_array($data) ? $data : ['data' => $data];
        if (!array_key_exists('ok', $payload)) {
            $payload = array_merge(['ok' => true], $payload);
        }
        return self::json($payload, $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['X-Frame-Options'] = 'SAMEORIGIN';
        $r->headers['Referrer-Policy'] = 'same-origin';
        $r->body = $html;
        return $r;
    }

    public static function download(string $content, string $filename, string $mime): self
    {
        $r = new self();
        $r->headers['Content-Type'] = $mime;
        $r->headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->body = $content;
        return $r;
    }

    public static function file(string $path, string $mime): self
    {
        $r = new self();
        $r->isFile = true;
        $r->filePath = $path;
        $r->headers['Content-Type'] = $mime;
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        return $r;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Location'] = $url;
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->isFile && $this->filePath && is_file($this->filePath)) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
