<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;

final class AccessControl
{
    public function __construct(private Database $db)
    {
    }

    public function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === Catalog::ROLE_ADMIN;
    }

    public function requireAdmin(array $user): void
    {
        if (!$this->isAdmin($user)) {
            throw HttpException::forbidden();
        }
    }

    public function requireRole(array $user, array $roles): void
    {
        if ($roles && !in_array($user['role'] ?? '', $roles, true)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string,mixed> */
    public function clientOrFail(int $id, array $user): array
    {
        $client = $this->db->fetch('SELECT * FROM clients WHERE id = :id', ['id' => $id]);
        if (!$client) {
            throw HttpException::notFound('Клиент не найден');
        }
        $this->assertCanViewClient($client, $user);
        return $client;
    }

    public function assertCanViewClient(array $client, array $user): void
    {
        if ($this->isAdmin($user)) {
            return;
        }
        if ((int) ($client['manager_id'] ?? 0) === (int) $user['id']) {
            return;
        }
        throw HttpException::forbidden('Нет доступа к этому клиенту');
    }

    public function assertCanEditClient(array $client, array $user): void
    {
        $this->assertCanViewClient($client, $user);
    }

    public function assertCanViewUpload(array $upload, array $user): void
    {
        if ($this->isAdmin($user)) {
            return;
        }
        $clientId = (int) ($upload['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw HttpException::forbidden('Нет доступа к этому файлу');
        }
        $this->clientOrFail($clientId, $user);
    }

    public function managerFilterSql(array $user, string $alias = 'c'): string
    {
        if ($this->isAdmin($user)) {
            return '1=1';
        }
        return $alias . '.manager_id = ' . (int) $user['id'];
    }
}
