<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;

final class TaskService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private AuditService $audit
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $user, array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$this->access->isAdmin($user)) {
            $where[] = 't.assigned_to = :me';
            $params['me'] = $user['id'];
        } elseif (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :asg';
            $params['asg'] = (int) $filters['assigned_to'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 't.client_id = :cid';
            $params['cid'] = (int) $filters['client_id'];
        }
        if (!empty($filters['bucket'])) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
            if ($filters['bucket'] === 'today') {
                $where[] = "t.status = 'open' AND t.due_at >= :d1 AND t.due_at < :d2";
                $params['d1'] = $today . ' 00:00:00';
                $params['d2'] = $tomorrow . ' 00:00:00';
            } elseif ($filters['bucket'] === 'overdue') {
                $where[] = "t.status = 'open' AND t.due_at < :now";
                $params['now'] = $this->db->now();
            } elseif ($filters['bucket'] === 'upcoming') {
                $where[] = "t.status = 'open' AND t.due_at >= :d2";
                $params['d2'] = $tomorrow . ' 00:00:00';
            } elseif ($filters['bucket'] === 'done') {
                $where[] = "t.status = 'done'";
            }
        }
        $sql = 'SELECT t.*, u.full_name AS assignee_name, c.full_name AS client_name, c.company_name
                FROM tasks t
                JOIN users u ON u.id = t.assigned_to
                LEFT JOIN clients c ON c.id = t.client_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY CASE WHEN t.status = \'open\' THEN 0 ELSE 1 END, t.due_at IS NULL, t.due_at ASC';
        $rows = $this->db->fetchAll($sql, $params);
        foreach ($rows as &$r) {
            $r['client_display'] = $r['company_name'] ?: $r['client_name'];
            $r['overdue'] = $r['status'] === Catalog::TASK_OPEN && $r['due_at'] && $r['due_at'] < $this->db->now();
        }
        return $rows;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input, array $user, Request $request): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new HttpException('Укажите название задачи');
        }
        $assigned = (int) ($input['assigned_to'] ?? $user['id']);
        if (!$this->access->isAdmin($user) && $assigned !== (int) $user['id']) {
            throw HttpException::forbidden('Можно назначать задачи только себе');
        }
        if (!empty($input['client_id'])) {
            $this->access->clientOrFail((int) $input['client_id'], $user);
        }
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (is_string($idem) && $idem !== '') {
            $existing = $this->db->fetch('SELECT * FROM tasks WHERE idempotency_key = :k OR uuid = :u', ['k' => $idem, 'u' => $idem]);
            if ($existing) {
                return $existing;
            }
        }
        $now = $this->db->now();
        $id = $this->db->insert('tasks', [
            'uuid' => $input['uuid'] ?? $this->uuid(),
            'client_id' => $input['client_id'] ?? null,
            'created_by' => $user['id'],
            'assigned_to' => $assigned,
            'title' => $title,
            'details' => $input['details'] ?? null,
            'due_at' => $input['due_at'] ?? null,
            'status' => Catalog::TASK_OPEN,
            'completed_at' => null,
            'idempotency_key' => $idem,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->log((int) $user['id'], 'task.create', 'task', $id, $request);
        return $this->db->fetch('SELECT * FROM tasks WHERE id = :id', ['id' => $id]) ?? [];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $id, array $input, array $user, Request $request): array
    {
        $task = $this->db->fetch('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
        if (!$task) {
            throw HttpException::notFound('Задача не найдена');
        }
        if (!$this->access->isAdmin($user) && (int) $task['assigned_to'] !== (int) $user['id']) {
            throw HttpException::forbidden();
        }
        $data = ['updated_at' => $this->db->now()];
        foreach (['title', 'details', 'due_at'] as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $input[$f];
            }
        }
        if (isset($input['status'])) {
            if (!in_array($input['status'], [Catalog::TASK_OPEN, Catalog::TASK_DONE, Catalog::TASK_CANCELLED], true)) {
                throw new HttpException('Некорректный статус задачи');
            }
            $data['status'] = $input['status'];
            $data['completed_at'] = $input['status'] === Catalog::TASK_DONE ? $this->db->now() : null;
        }
        $this->db->update('tasks', $data, ['id' => $id]);
        $this->audit->log((int) $user['id'], 'task.update', 'task', $id, $request);
        return $this->db->fetch('SELECT * FROM tasks WHERE id = :id', ['id' => $id]) ?? [];
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
