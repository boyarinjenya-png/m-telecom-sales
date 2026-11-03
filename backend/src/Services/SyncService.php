<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\Request;

final class SyncService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private ClientService $clients,
        private InteractionService $interactions,
        private TaskService $tasks
    ) {
    }

    /** @return array<string,mixed> */
    public function pull(array $user, ?string $since): array
    {
        $since = $since ?: '1970-01-01 00:00:00';
        $mf = $this->access->managerFilterSql($user, 'c');
        $clients = $this->db->fetchAll(
            "SELECT c.* FROM clients c WHERE {$mf} AND c.updated_at >= :s",
            ['s' => $since]
        );
        $clientIds = array_map(fn ($c) => (int) $c['id'], $clients);
        $tasks = $this->tasks->list($user, []);
        $conflicts = $this->db->fetchAll(
            'SELECT * FROM sync_conflicts WHERE user_id = :u AND resolution = :r ORDER BY id DESC',
            ['u' => $user['id'], 'r' => 'pending']
        );
        return [
            'server_time' => $this->db->now(),
            'clients' => $clients,
            'client_ids' => $clientIds,
            'tasks' => $tasks,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public function push(array $ops, array $user, Request $request): array
    {
        $results = [];
        foreach ($ops as $op) {
            $kind = $op['kind'] ?? '';
            $payload = $op['payload'] ?? [];
            if (!is_array($payload)) {
                $payload = [];
            }
            try {
                $data = match ($kind) {
                    'client.create' => $this->clients->create($payload, $user, $request, !empty($payload['force'])),
                    'client.update' => $this->clients->update((int) ($payload['id'] ?? 0), $payload, $user, $request),
                    'client.status' => $this->clients->changeStatus(
                        (int) ($payload['id'] ?? $payload['client_id'] ?? 0),
                        (string) ($payload['status'] ?? ''),
                        $user,
                        $request
                    ),
                    'client.application' => $this->clients->createApplication(
                        (int) ($payload['client_id'] ?? $payload['id'] ?? 0),
                        $payload,
                        $user,
                        $request
                    ),
                    'call' => $this->interactions->recordCall((int) ($payload['client_id'] ?? 0), $payload, $user, $request),
                    'call.complete' => $this->interactions->completeCall((int) ($payload['id'] ?? $payload['call_id'] ?? 0), $payload, $user, $request),
                    'whatsapp' => $this->interactions->recordWhatsapp((int) ($payload['client_id'] ?? 0), $payload, $user, $request),
                    'interaction' => $this->interactions->create((int) ($payload['client_id'] ?? 0), $payload, $user, $request),
                    'task.create' => $this->tasks->create($payload, $user, $request),
                    'task.update' => $this->tasks->update((int) ($payload['id'] ?? 0), $payload, $user, $request),
                    default => throw new \InvalidArgumentException('Неизвестная операция синхронизации'),
                };
                $results[] = [
                    'client_op_id' => $op['client_op_id'] ?? null,
                    'ok' => true,
                    'data' => $data,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_op_id' => $op['client_op_id'] ?? null,
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'status' => (int) ($e->getCode() ?: 400),
                ];
            }
        }
        return $results;
    }
}
