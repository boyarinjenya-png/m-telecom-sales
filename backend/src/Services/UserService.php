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
        $rows = $this->db->fetchAll('SELECT * FROM users ORDER BY role, full_name');
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
        $data = ['updated_at' => $this->db->now()];
        if (isset($input['full_name'])) {
            $data['full_name'] = trim((string) $input['full_name']);
        }
        if (array_key_exists('phone', $input)) {
            $data['phone'] = $input['phone'];
        }
        if (!empty($input['password'])) {
            if (strlen((string) $input['password']) < 8) {
                throw new HttpException('Пароль должен быть не короче 8 символов');
            }
            $data['password_hash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        $this->db->update('users', $data, ['id' => $id]);
        $this->audit->log((int) $actor['id'], 'user.update', 'user', $id, $request);
        $row = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $this->auth->publicUser($row ?? []);
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
