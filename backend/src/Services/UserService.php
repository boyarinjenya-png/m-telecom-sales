<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Auth\AuthService;
use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;

final class UserService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private AuditService $audit,
        private AuthService $auth
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(array $actor): array
    {
        $this->access->requireAdmin($actor);
        // Soft-deleted accounts are retained for historical references but hidden from active user pickers.\n        $rows = $this->db->fetchAll(\"SELECT * FROM users WHERE status <> 'deleted' ORDER BY role, full_name\");
        return array_map([$this->auth, 'publicUser'], $rows);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input, array $actor, Request $request): array
    {
        $this->access->requireAdmin($actor);
        $role = $input['role'] ?? Catalog::ROLE_MANAGER;
        if ($role === Catalog::ROLE_ADMIN) {
            throw HttpException::forbidden('Создание администратора через API запрещено');
        }
        if ($role !== Catalog::ROLE_MANAGER) {
            throw new HttpException('Некорректная роль');
        }
        $login = trim((string) ($input['login'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $fullName = trim((string) ($input['full_name'] ?? ''));
        if ($login === '' || $fullName === '' || strlen($password) < 8) {
            throw new HttpException('Логин, ФИО и пароль от 8 символов обязательны');
        }
        if ($this->db->fetch('SELECT id FROM users WHERE login = :l', ['l' => $login])) {
            throw new HttpException('Логин уже занят');
        }
        $now = $this->db->now();
        $id = $this->withManagerSlot(function () use ($login, $password, $fullName, $input, $actor, $now): int {
            $this->assertManagerSlotAvailable();
            return $this->db->insert('users', [
                'uuid' => $this->uuid(),
                'login' => $login,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'phone' => $input['phone'] ?? null,
                'role' => Catalog::ROLE_MANAGER,
                'status' => Catalog::USER_ACTIVE,
                'created_by' => $actor['id'],
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        $this->audit->log((int) $actor['id'], 'user.create', 'user', $id, $request);
        $row = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $this->auth->publicUser($row ?? []);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $id, array $input, array $actor, Request $request): array
    {
        $this->access->requireAdmin($actor);
        $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw HttpException::notFound('Пользователь не найден');
        }
        if (($user['role'] ?? '') !== Catalog::ROLE_MANAGER || ($user['status'] ?? '') === 'deleted') {
            throw HttpException::forbidden('Можно изменять только активные учётные записи менеджеров');
        }
        $data = ['updated_at' => $this->db->now()];
        if (array_key_exists('login', $input)) {
            $login = trim((string) $input['login']);
            if ($login === '' || strlen($login) > 100) {
                throw new HttpException('Логин обязателен и должен быть не длиннее 100 символов');
            }
            $duplicate = $this->db->fetch(
                'SELECT id FROM users WHERE login = :login AND id <> :id',
                ['login' => $login, 'id' => $id]
            );
            if ($duplicate) {
                throw new HttpException('Логин уже занят', 409);
            }
            $data['login'] = $login;
        }
        if (array_key_exists('full_name', $input)) {
            $fullName = trim((string) $input['full_name']);
            if ($fullName === '') {
                throw new HttpException('ФИО не может быть пустым');
            }
            $data['full_name'] = $fullName;
        }
        if (array_key_exists('phone', $input)) {
            $data['phone'] = trim((string) $input['phone']) ?: null;
        }
        $passwordChanged = !empty($input['password']);
        if ($passwordChanged) {
            if (strlen((string) $input['password']) < 8) {
                throw new HttpException('Пароль должен быть не короче 8 символов');
            }
            $data['password_hash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        $this->db->update('users', $data, ['id' => $id]);
        if ($passwordChanged) {
            $this->auth->revokeAllSessions($id, $request, (int) $actor['id']);
        }
        $this->audit->log((int) $actor['id'], 'user.update', 'user', $id, $request);
        $row = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $this->auth->publicUser($row ?? []);
    }

    /**
     * Generate a one-time temporary password. The plaintext is returned only in this response.
     */
    public function resetManagerPassword(int $id, array $actor, Request $request): array
    {
        $this->access->requireAdmin($actor);
        $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw HttpException::notFound('Менеджер не найден');
        }
        if (($user['role'] ?? '') !== Catalog::ROLE_MANAGER || ($user['status'] ?? '') === 'deleted') {
            throw HttpException::forbidden('Сбросить пароль можно только действующему менеджеру');
        }
        $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $this->db->transaction(function () use ($id, $temporaryPassword, $actor, $request): void {
            $this->db->update('users', [
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'updated_at' => $this->db->now(),
            ], ['id' => $id]);
            $this->auth->revokeAllSessions($id, $request, (int) $actor['id']);
            $this->audit->log((int) $actor['id'], 'user.password_reset', 'user', $id, $request, [
                'sessions_revoked' => true,
            ]);
        });
        return ['temporary_password' => $temporaryPassword, 'sessions_revoked' => true];
    }

    public function setBlocked(int $id, bool $blocked, array $actor, Request $request): array
    {
        $this->access->requireAdmin($actor);
        $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw HttpException::notFound('Пользователь не найден');
        }
        if ($user['role'] === Catalog::ROLE_ADMIN) {
            throw HttpException::forbidden('Нельзя заблокировать руководителя этим действием');
        }
        $status = $blocked ? Catalog::USER_BLOCKED : Catalog::USER_ACTIVE;
        $this->withManagerSlot(function () use ($blocked, $user, $id, $status): void {
            if (!$blocked && $user['status'] !== Catalog::USER_ACTIVE) {
                $this->assertManagerSlotAvailable();
            }
            $this->db->update('users', ['status' => $status, 'updated_at' => $this->db->now()], ['id' => $id]);
        });
        if ($blocked) {
            $this->auth->revokeAllSessions($id, $request, (int) $actor['id']);
        }
        $this->audit->log((int) $actor['id'], $blocked ? 'user.block' : 'user.unblock', 'user', $id, $request);
        $row = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $this->auth->publicUser($row ?? []);
    }

    /**
     * Soft-delete a manager without destroying historical client, task, call or audit references.
     * The account is immediately disabled and all active sessions are revoked.
     */
    public function deleteManager(int $id, array $actor, Request $request): array
    {
        $this->access->requireAdmin($actor);
        $user = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$user) {
            throw HttpException::notFound('Менеджер не найден');
        }
        if ($user['role'] !== Catalog::ROLE_MANAGER) {
            throw HttpException::forbidden('Удалять можно только учётные записи менеджеров');
        }
        if ((int) $user['id'] === (int) $actor['id']) {
            throw HttpException::forbidden('Нельзя удалить собственную учётную запись');
        }
        if (($user['status'] ?? '') !== 'deleted') {
            $this->db->transaction(function () use ($id, $actor, $request): void {
                $this->db->update('users', [
                    'status' => 'deleted',
                    'updated_at' => $this->db->now(),
                ], ['id' => $id]);
                $this->auth->revokeAllSessions($id, $request, (int) $actor['id']);
                $this->audit->log((int) $actor['id'], 'user.delete', 'user', $id, $request, [
                    'mode' => 'soft_delete',
                ]);
            });
        }
        return ['deleted' => true, 'id' => $id];
    }

    public function createAdmin(string $login, string $password, string $fullName): int
    {
        $now = $this->db->now();
        return $this->db->insert('users', [
            'uuid' => $this->uuid(),
            'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'phone' => null,
            'role' => Catalog::ROLE_ADMIN,
            'status' => Catalog::USER_ACTIVE,
            'created_by' => null,
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function withManagerSlot(callable $fn): mixed
    {
        $mysql = !$this->db->isSqlite();
        if ($mysql) {
            $this->db->query("SELECT GET_LOCK('mts_manager_limit', 10)");
        }
        try {
            return $this->db->transaction($fn);
        } finally {
            if ($mysql) {
                $this->db->query("SELECT RELEASE_LOCK('mts_manager_limit')");
            }
        }
    }

    private function assertManagerSlotAvailable(): void
    {
        $count = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE role = :r AND status = :s',
            ['r' => Catalog::ROLE_MANAGER, 's' => Catalog::USER_ACTIVE]
        );
        if ($count >= Catalog::MAX_ACTIVE_MANAGERS) {
            throw new HttpException('Достигнут лимит 5 активных менеджеров', 409);
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
