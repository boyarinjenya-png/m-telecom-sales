<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\Request;

final class FollowUpService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function apply(int $clientId, ?string $result, array $input, array $user, Request $request): array
    {
        $result = $result ?: '';
        $plan = Catalog::followUpPlan($result);
        if (!$plan) {
            return ['mode' => 'none', 'task' => null, 'created' => false, 'duplicate' => false];
        }

        $dueAt = $this->dueAt($plan, $input);
        $out = [
            'mode' => $plan['mode'],
            'kind' => $plan['kind'] ?? null,
            'title' => $plan['title'] ?? null,
            'due_at' => $dueAt,
            'ask_datetime' => !empty($plan['ask_datetime']),
            'ask_reason' => !empty($plan['ask_reason']),
            'propose_application' => !empty($plan['propose_application']),
            'task' => null,
            'created' => false,
            'duplicate' => false,
            'message' => null,
        ];

        if ($dueAt) {
            $this->db->update('clients', [
                'next_contact_at' => $dueAt,
                'updated_at' => $this->db->now(),
                'updated_by' => $user['id'],
            ], ['id' => $clientId]);
            $out['due_at'] = $dueAt;
        }

        if (($plan['mode'] ?? '') !== 'auto' || empty($plan['title'])) {
            if (!empty($out['propose_application'])) {
                $out['message'] = 'Можно создать заявку из дополнительных действий.';
            }
            return $out;
        }

        $existing = $this->db->fetch(
            "SELECT * FROM tasks
             WHERE client_id = :c AND status = :st AND title = :t
             ORDER BY id DESC",
            ['c' => $clientId, 'st' => Catalog::TASK_OPEN, 't' => $plan['title']]
        );
        if ($existing) {
            if ($dueAt && ($existing['due_at'] ?? '') !== $dueAt) {
                $this->db->update('tasks', [
                    'due_at' => $dueAt,
                    'updated_at' => $this->db->now(),
                ], ['id' => $existing['id']]);
                $existing['due_at'] = $dueAt;
            }
            $out['task'] = $existing;
            $out['duplicate'] = true;
            $out['message'] = 'Открытая задача уже есть, дубль не создан.';
            return $out;
        }

        $now = $this->db->now();
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        $id = $this->db->insert('tasks', [
            'uuid' => $this->uuid(),
            'client_id' => $clientId,
            'created_by' => $user['id'],
            'assigned_to' => $user['id'],
            'title' => $plan['title'],
            'details' => $input['comment'] ?? $input['next_action'] ?? null,
            'due_at' => $dueAt,
            'status' => Catalog::TASK_OPEN,
            'completed_at' => null,
            'idempotency_key' => is_string($idem) && $idem !== '' ? $idem . ':followup' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $out['task'] = $this->db->fetch('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
        $out['created'] = true;
        $out['message'] = 'Создана задача: ' . $plan['title'];
        return $out;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $input
     */
    private function dueAt(array $plan, array $input): ?string
    {
        $custom = trim((string) ($input['next_contact_at'] ?? ''));
        if ($custom !== '') {
            if (strlen($custom) === 16) {
                $custom .= ':00';
            }
            return str_replace('T', ' ', $custom);
        }
        if (($plan['due'] ?? '') === 'next_workday') {
            return Catalog::nextWorkday();
        }
        return null;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
