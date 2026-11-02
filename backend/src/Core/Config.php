<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromEnv(string $root): self
    {
        $basePath = Env::get('APP_BASE_PATH', '') ?? '';
        $basePath = rtrim($basePath, '/');

        return new self([
            'root' => $root,
            'name' => Env::get('APP_NAME', 'M-TELECOM SALES'),
            'env' => Env::get('APP_ENV', 'production'),
            'debug' => Env::bool('APP_DEBUG', false),
            'url' => rtrim(Env::get('APP_URL', 'https://sales.mtelecom.kz') ?? '', '/'),
            'base_path' => $basePath,
            'timezone' => Env::get('APP_TIMEZONE', 'Asia/Almaty'),
            'app_key' => Env::get('APP_KEY', 'change-me'),
            'db' => [
                'driver' => Env::get('DB_DRIVER', 'mysql'),
                'host' => Env::get('DB_HOST', 'localhost'),
                'port' => Env::get('DB_PORT', '3306'),
                'name' => Env::get('DB_NAME', 'mtelecom_sales'),
                'user' => Env::get('DB_USER', 'mtelecom_sales'),
                'pass' => Env::get('DB_PASS', ''),
                'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
                'sqlite_path' => Env::get('DB_SQLITE_PATH', $root . '/backend/storage/app.sqlite'),
            ],
            'token_ttl_hours' => (int) (Env::get('TOKEN_TTL_HOURS', '720') ?? '720'),
            'login_max_attempts' => (int) (Env::get('LOGIN_MAX_ATTEMPTS', '5') ?? '5'),
            'login_window_minutes' => (int) (Env::get('LOGIN_WINDOW_MINUTES', '15') ?? '15'),
            'upload_max_mb' => (int) (Env::get('UPLOAD_MAX_MB', '5') ?? '5'),
            'android_version_code' => (int) (Env::get('ANDROID_VERSION_CODE', '1') ?? '1'),
            'android_version_name' => Env::get('ANDROID_VERSION_NAME', '1.0.0'),
            'android_apk_url' => Env::get('ANDROID_APK_URL', ''),
            'map_tile_url' => Env::get('MAP_TILE_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
            'map_attribution' => Env::get('MAP_ATTRIBUTION', '&copy; OpenStreetMap contributors'),
            'cors_origins' => Env::get('APP_CORS_ORIGINS', ''),
            'storage' => $root . '/backend/storage',
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $v = $this->data;
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) {
                return $default;
            }
            $v = $v[$p];
        }
        return $v;
    }

    public function isSqlite(): bool
    {
        return ($this->get('db.driver') === 'sqlite');
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('debug');
    }

    public function isTest(): bool
    {
        return $this->get('env') === 'test';
    }

    public function isProduction(): bool
    {
        return $this->get('env') === 'production';
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function basePath(): string
    {
        return rtrim((string) $this->get('base_path', ''), '/');
    }

    public function cookiePath(): string
    {
        $base = $this->basePath();
        return $base === '' ? '/' : $base;
    }

    public function isHttps(): bool
    {
        $url = (string) $this->get('url', '');
        if (str_starts_with($url, 'https://')) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function allowedOrigin(): ?string
    {
        $origins = $this->allowedOrigins();
        return $origins[0] ?? null;
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $out = [];
        $url = (string) $this->get('url', '');
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                $out[] = $origin;
            }
        }
        $extra = (string) $this->get('cors_origins', '');
        foreach (explode(',', $extra) as $item) {
            $item = trim($item);
            if ($item === '' || $item === '*') {
                continue;
            }
            $out[] = rtrim($item, '/');
        }
        return array_values(array_unique($out));
    }
}
