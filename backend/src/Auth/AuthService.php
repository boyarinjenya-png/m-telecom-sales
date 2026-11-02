<?php
declare(strict_types=1);

namespace MTelecom\Sales\Auth;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Config;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;
use MTelecom\Sales\Services\AuditService;

final class AuthService
{
    public function __construct(
        private Database $db,
        private Config $config,
        private AuditService $audit
    ) {
    }

    /** @return array{user:array<string,mixed>,token:string,expires_at:string} */
    public function login(string $login, string $password, Request $request, ?string $device = null, ?string $platform = null): array
    {
        $login = trim($login);
        $this->assertNotRateLimited($login, $request->ip);

        $user = $this->db->fetch('SELECT * FROM users WHERE login = :l', ['l' => $login]);
        $ok = $user && password_verify($password, (string) $user['password_hash']);
        $this->db->insert('login_attempts', [
            'login' => $login,
            'ip' => $request->ip,
            'success' => $ok ? 1 : 0,
            'created_at' => $this->db->now(),
        ]);

        if (!$ok) {
            throw HttpException::unauthorized('Неверный логин или пароль');
        }
        if (($user['status'] ?? '') !== Catalog::USER_ACTIVE) {
            throw HttpException::forbidden('Учётная запись заблокирована');
        }

        $ttlHours = (int) $this->config->get('token_ttl_hours', 720);
        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttlHours . ' hours')->format('Y-m-d H:i:s');
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->db->insert('auth_tokens', [
            'user_id' => $user['id'],
            'token_hash' => $hash,
            'device_name' => $device,
            'platform' => $platform,
            'expires_at' => $expires,
            'revoked_at' => null,
            'last_used_at' => $this->db->now(),
            'created_at' => $this->db->now(),
        ]);
        $this->db->update('users', ['last_login_at' => $this->db->now(), 'updated_at' => $this->db->now()], ['id' => $user['id']]);
        $this->audit->log((int) $user['id'], 'auth.login', 'user', (int) $user['id'], $request, [
            'platform' => $platform,
            'device' => $device,
        ]);

        return [
            'user' => $this->publicUser($user),
            'token' => $raw,
            'expires_at' => $expires,
        ];
    }

    /** @return array<string,mixed> */
    public function userFromRequest(Request $request): array
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            return $this->userFromToken($bearer, $request);
        }
        $sid = $request->cookies['mts_session'] ?? null;
        if (is_string($sid) && $sid !== '') {
            return $this->userFromSession($sid, $request);
        }
        throw HttpException::unauthorized();
    }

    /** @return array<string,mixed> */
    public function userFromToken(string $raw, Request $request): array
    {
        $hash = hash('sha256', $raw);
        $row = $this->db->fetch(
            'SELECT t.id AS token_id, t.expires_at, t.revoked_at, u.*
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :h',
            ['h' => $hash]
        );
        if (!$row) {
            throw HttpException::unauthorized('Сессия недействительна');
        }
        if ($row['revoked_at']) {
            throw HttpException::unauthorized('Сессия отозвана');
        }
        if ($row['expires_at'] < $this->db->now()) {
            throw HttpException::unauthorized('Сессия истекла');
        }
        if ($row['status'] !== Catalog::USER_ACTIVE) {
            throw HttpException::forbidden('Учётная запись заблокирована');
        }
        $this->db->update('auth_tokens', ['last_used_at' => $this->db->now()], ['id' => $row['token_id']]);
        unset($row['token_id'], $row['expires_at'], $row['revoked_at']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function userFromSession(string $sid, Request $request): array
    {
        $row = $this->db->fetch(
            'SELECT s.csrf_token, s.expires_at, u.*
             FROM web_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = :id',
            ['id' => $sid]
        );
        if (!$row || $row['expires_at'] < $this->db->now()) {
            throw HttpException::unauthorized('Сессия истекла');
        }
        if ($row['status'] !== Catalog::USER_ACTIVE) {
            throw HttpException::forbidden('Учётная запись заблокирована');
        }
        return $row;
    }

    public function logout(Request $request, array $user): void
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $this->db->update('auth_tokens', ['revoked_at' => $this->db->now()], [
                'token_hash' => hash('sha256', $bearer),
            ]);
        }
        $sid = $request->cookies['mts_session'] ?? null;
        if (is_string($sid) && $sid !== '') {
            $this->db->query('DELETE FROM web_sessions WHERE id = :id', ['id' => $sid]);
        }
        $this->audit->log((int) $user['id'], 'auth.logout', 'user', (int) $user['id'], $request);
    }

    /** @return array{session_id:string,csrf:string,user:array<string,mixed>} */
    public function createWebSession(array $user, Request $request): array
    {
        $sid = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('now'))->modify('+12 hours')->format('Y-m-d H:i:s');
        $this->db->insert('web_sessions', [
            'id' => $sid,
            'user_id' => $user['id'],
            'csrf_token' => $csrf,
            'ip' => $request->ip,
            'user_agent' => substr($request->userAgent, 0, 255),
            'expires_at' => $expires,
            'created_at' => $this->db->now(),
        ]);
        return ['session_id' => $sid, 'csrf' => $csrf, 'user' => $this->publicUser($user)];
    }

    public function revokeAllSessions(int $userId, Request $request, int $actorId): int
    {
        $now = $this->db->now();
        $this->db->query(
            'UPDATE auth_tokens SET revoked_at = :n WHERE user_id = :u AND revoked_at IS NULL',
            ['n' => $now, 'u' => $userId]
        );
        $this->db->query('DELETE FROM web_sessions WHERE user_id = :u', ['u' => $userId]);
        $this->audit->log($actorId, 'auth.revoke_sessions', 'user', $userId, $request);
        return 1;
    }

    public function assertCsrf(Request $request, array $user): void
    {
        if ($request->bearerToken()) {
            return;
        }
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $token = $request->header('x-csrf-token') ?? $request->str('csrf_token');
        $expected = $user['csrf_token'] ?? null;
        if (!$token || !$expected || !hash_equals((string) $expected, $token)) {
            throw new HttpException('Недействительный CSRF-токен', 419);
        }
    }

    /** @return array<string,mixed> */
    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'uuid' => $user['uuid'],
            'login' => $user['login'],
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'status' => $user['status'],
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }

    private function assertNotRateLimited(string $login, string $ip): void
    {
        $max = (int) $this->config->get('login_max_attempts', 5);
        $window = (int) $this->config->get('login_window_minutes', 15);
        $since = (new \DateTimeImmutable('now'))->modify('-' . $window . ' minutes')->format('Y-m-d H:i:s');
        $count = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM login_attempts
             WHERE login = :l AND ip = :ip AND success = 0 AND created_at >= :s',
            ['l' => $login, 'ip' => $ip, 's' => $since]
        );
        if ($count >= $max) {
            throw new HttpException('Слишком много попыток входа. Повторите позже.', 429);
        }
    }
}
