<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;

final class ClientService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private DuplicateService $duplicates,
        private AuditService $audit
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function list(array $user, array $filters, int $page = 1, int $perPage = 50): array
    {
        $where = [$this->access->managerFilterSql($user, 'c')];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(c.full_name LIKE :q OR c.company_name LIKE :q2 OR c.phone_primary LIKE :q3 OR c.bin LIKE :q4 OR c.iin LIKE :q5)';
            $like = '%' . $filters['q'] . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
            $params['q5'] = $like;
        }
        if (!empty($filters['type'])) {
            $where[] = 'c.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['manager_id']) && $this->access->isAdmin($user)) {
            $where[] = 'c.manager_id = :mid';
            $params['mid'] = (int) $filters['manager_id'];
        }
        if (!empty($filters['application_event'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM interactions ai
                WHERE ai.client_id = c.id
                  AND (ai.result = 'application_received' OR ai.payload_json LIKE '%\\"kind\\":\\"application\\"%')
            )";
        }
        if (!empty($filters['status_event']) && !empty($filters['from']) && !empty($filters['to'])) {
            $where[] = 'EXISTS (
                SELECT 1 FROM status_history sh
                WHERE sh.client_id = c.id AND sh.to_status = :stev
                  AND sh.created_at >= :efrom AND sh.created_at <= :eto
            )';
            $params['stev'] = $filters['status_event'];
            $params['efrom'] = $filters['from'];
            $params['eto'] = $filters['to'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM clients c WHERE {$sqlWhere}", $params);
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $items = $this->db->fetchAll(
            "SELECT c.*, u.full_name AS manager_name
             FROM clients c
             LEFT JOIN users u ON u.id = c.manager_id
             WHERE {$sqlWhere}
             ORDER BY c.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['items' => array_map([$this, 'decorate'], $items), 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function get(int $id, array $user): array
    {
        $client = $this->access->clientOrFail($id, $user);
        $client['manager_name'] = null;
        if ($client['manager_id']) {
            $m = $this->db->fetch('SELECT full_name FROM users WHERE id = :id', ['id' => $client['manager_id']]);
            $client['manager_name'] = $m['full_name'] ?? null;
        }
        $client['phones'] = $this->db->fetchAll('SELECT * FROM client_phones WHERE client_id = :id ORDER BY id', ['id' => $id]);
        $client['contacts'] = $this->db->fetchAll('SELECT * FROM client_contacts WHERE client_id = :id ORDER BY id', ['id' => $id]);
        $client['objects'] = $this->db->fetchAll('SELECT * FROM client_objects WHERE client_id = :id ORDER BY id', ['id' => $id]);
        $client['timeline'] = $this->timeline($id);
        $this->attachCardSections($client, $id);
        return $this->decorate($client);
    }

    /** @return list<array<string,mixed>> */
    public function timeline(int $clientId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.*, u.full_name AS user_name
             FROM interactions i
             JOIN users u ON u.id = i.user_id
             WHERE i.client_id = :id
             ORDER BY i.created_at DESC, i.id DESC',
            ['id' => $clientId]
        );
        foreach ($rows as &$row) {
            $row['type_label'] = Catalog::INTERACTION_TYPES[$row['type']] ?? $row['type'];
            $row['result_label'] = $row['result'] ? (Catalog::RESULTS[$row['result']] ?? $row['result']) : null;
            $row['interest_label'] = $row['interest'] ? (Catalog::INTEREST[$row['interest']] ?? $row['interest']) : null;
            $ds = Catalog::normalizeDataSource(
                isset($row['data_source']) ? (string) $row['data_source'] : null,
                isset($row['source']) ? (string) $row['source'] : null
            );
            $row['data_source'] = $ds;
            $row['data_source_label'] = Catalog::dataSourceLabel($ds);
            $row['source_label'] = $row['data_source_label'];
            if ($row['payload_json']) {
                $row['payload'] = json_decode((string) $row['payload_json'], true);
            }
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input, array $user, Request $request, bool $force = false): array
    {
        $type = $input['type'] ?? Catalog::TYPE_PERSON;
        if (!in_array($type, [Catalog::TYPE_PERSON, Catalog::TYPE_COMPANY], true)) {
            throw new HttpException('Некорректный тип клиента');
        }
        $this->validate($input, $type);

        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (is_string($idem) && $idem !== '') {
            $existing = $this->db->fetch('SELECT id FROM clients WHERE idempotency_key = :k OR uuid = :u', ['k' => $idem, 'u' => $idem]);
            if ($existing) {
                return $this->get((int) $existing['id'], $user);
            }
        }

        if (!$force) {
            $dups = $this->duplicates->find($input);
            if ($dups) {
                throw HttpException::conflict('Найдены возможные дубликаты', [
                    'duplicates' => $dups,
                    'require_confirmation' => true,
                ]);
            }
        }

        $now = $this->db->now();
        $uuid = $input['uuid'] ?? $this->uuid();
        $id = $this->db->transaction(function (Database $db) use ($input, $user, $type, $now, $uuid, $idem) {
            $id = $db->insert('clients', [
                'uuid' => $uuid,
                'type' => $type,
                'status' => $input['status'] ?? 'new',
                'full_name' => $input['full_name'] ?? null,
                'iin' => $type === Catalog::TYPE_PERSON ? (preg_replace('/\\D+/', '', (string) ($input['iin'] ?? '')) ?: null) : null,
                'phone_primary' => $input['phone_primary'] ?? null,
                'phone_primary_norm' => Catalog::normalizePhone($input['phone_primary'] ?? null),
                'phone_secondary' => $input['phone_secondary'] ?? null,
                'phone_secondary_norm' => Catalog::normalizePhone($input['phone_secondary'] ?? null),
                'address' => $input['address'] ?? null,
                'lat' => $input['lat'] ?? null,
                'lng' => $input['lng'] ?? null,
                'current_provider' => $input['current_provider'] ?? null,
                'need' => $input['need'] ?? null,
                'comment' => $input['comment'] ?? null,
                'company_name' => $input['company_name'] ?? null,
                'bin' => Catalog::normalizeBin($input['bin'] ?? null),
                'legal_address' => $input['legal_address'] ?? null,
                'actual_address' => $input['actual_address'] ?? null,
                'potential_connections' => $input['potential_connections'] ?? null,
                'company_needs' => $input['company_needs'] ?? null,
                'manager_id' => $this->access->isAdmin($user) ? ($input['manager_id'] ?? $user['id']) : $user['id'],
                'created_by' => $user['id'],
                'next_contact_at' => $input['next_contact_at'] ?? null,
                'idempotency_key' => $idem,
                'created_at' => $now,
                'updated_at' => $now,
                'updated_by' => $user['id'],
            ]);
            $this->syncRelated($id, $input, $now);
            $db->insert('status_history', [
                'client_id' => $id,
                'user_id' => $user['id'],
                'from_status' => null,
                'to_status' => $input['status'] ?? 'new',
                'created_at' => $now,
            ]);
            return $id;
        });

        $this->audit->log((int) $user['id'], 'client.create', 'client', $id, $request, [
            'type' => $type,
            'force' => $force,
        ]);
        return $this->get($id, $user);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $input, array $user, Request $request): array
    {
        $client = $this->access->clientOrFail($id, $user);
        $type = $client['type'];
        $this->validate(array_merge($client, $input), $type, true);

        $serverUpdated = (string) $client['updated_at'];
        $clientUpdated = $input['base_updated_at'] ?? null;
        if (is_string($clientUpdated) && $clientUpdated !== '' && $clientUpdated < $serverUpdated) {
            $this->db->insert('sync_conflicts', [
                'user_id' => $user['id'],
                'entity_type' => 'client',
                'entity_uuid' => $client['uuid'],
                'local_payload' => json_encode($input, JSON_UNESCAPED_UNICODE),
                'server_payload' => json_encode($client, JSON_UNESCAPED_UNICODE),
                'resolution' => 'pending',
                'created_at' => $this->db->now(),
            ]);
            throw HttpException::conflict('Конфликт изменений карточки клиента', [
                'server' => $this->get($id, $user),
                'strategy' => 'server_wins_until_resolved',
            ]);
        }

        $now = $this->db->now();
        $fields = [
            'full_name', 'iin', 'phone_primary', 'phone_secondary', 'address', 'lat', 'lng',
            'current_provider', 'need', 'comment', 'company_name', 'legal_address',
            'actual_address', 'potential_connections', 'company_needs', 'next_contact_at',
        ];
        $data = ['updated_at' => $now, 'updated_by' => $user['id']];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $data[$f] = $input[$f];
            }
        }
        if (array_key_exists('phone_primary', $input)) {
            $data['phone_primary_norm'] = Catalog::normalizePhone($input['phone_primary']);
        }
        if (array_key_exists('phone_secondary', $input)) {
            $data['phone_secondary_norm'] = Catalog::normalizePhone($input['phone_secondary']);
        }
        if (array_key_exists('bin', $input)) {
            $data['bin'] = Catalog::normalizeBin($input['bin']);
        }
        if (array_key_exists('manager_id', $input) && $this->access->isAdmin($user)) {
            $data['manager_id'] = $input['manager_id'];
        }
        $this->db->update('clients', $data, ['id' => $id]);
        if (isset($input['phones']) || isset($input['contacts']) || isset($input['objects'])) {
            $this->syncRelated($id, $input, $now, false);
        }
        $this->audit->log((int) $user['id'], 'client.update', 'client', $id, $request);
        return $this->get($id, $user);
    }

    public function changeStatus(int $id, string $status, array $user, Request $request): array
    {
        if (!isset(Catalog::STATUSES[$status])) {
            throw new HttpException('Неизвестный статус');
        }
        $client = $this->access->clientOrFail($id, $user);
        $from = (string) $client['status'];
        if (!$this->access->isAdmin($user)) {
            if (in_array($status, Catalog::ADMIN_ONLY_STATUSES, true)) {
                throw HttpException::forbidden('Статус «Подключён» подтверждает руководитель');
            }
            if (!Catalog::canManagerSetStatus($from, $status)) {
                throw HttpException::forbidden('Недопустимый переход статуса');
            }
        }
        $now = $this->db->now();
        $this->db->update('clients', ['status' => $status, 'updated_at' => $now, 'updated_by' => $user['id']], ['id' => $id]);
        $this->db->insert('status_history', [
            'client_id' => $id,
            'user_id' => $user['id'],
            'from_status' => $from,
            'to_status' => $status,
            'created_at' => $now,
        ]);
        $this->db->insert('interactions', [
            'uuid' => $this->uuid(),
            'client_id' => $id,
            'object_id' => null,
            'user_id' => $user['id'],
            'type' => 'status',
            'direction' => null,
            'result' => null,
            'interest' => null,
            'topic' => null,
            'offered' => null,
            'next_action' => null,
            'next_contact_at' => null,
            'comment' => (Catalog::STATUSES[$from] ?? $from) . ' → ' . Catalog::STATUSES[$status],
            'source' => Catalog::SOURCE_SYSTEM,
            'payload_json' => json_encode(['from' => $from, 'to' => $status], JSON_UNESCAPED_UNICODE),
            'idempotency_key' => null,
            'amended_from_id' => null,
            'created_at' => $now,
        ]);
        $this->audit->log((int) $user['id'], 'client.status', 'client', $id, $request, ['from' => $from, 'to' => $status]);
        return $this->get($id, $user);
    }

    /**
     * Atomically records an application (optional note + status → application_received).
     * Idempotent via idempotency_key so offline outbox retries never duplicate.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createApplication(int $id, array $input, array $user, Request $request): array
    {
        $this->access->clientOrFail($id, $user);
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (!is_string($idem) || trim($idem) === '') {
            throw new HttpException('idempotency_key обязателен для заявки');
        }
        $idem = trim($idem);
        $existing = $this->db->fetch(
            'SELECT * FROM interactions WHERE idempotency_key = :k OR uuid = :u',
            ['k' => $idem, 'u' => $idem]
        );
        if ($existing) {
            return $this->get($id, $user);
        }

        $comment = trim((string) ($input['comment'] ?? ''));
        $now = $this->db->now();
        $this->db->insert('interactions', [
            'uuid' => $idem,
            'client_id' => $id,
            'object_id' => null,
            'user_id' => $user['id'],
            'type' => 'note',
            'direction' => null,
            'result' => 'application_received',
            'interest' => null,
            'topic' => null,
            'offered' => null,
            'next_action' => null,
            'next_contact_at' => null,
            'comment' => $comment !== '' ? $comment : 'Создана заявка',
            'source' => Catalog::SOURCE_MANAGER,
            'data_source' => Catalog::DATA_SOURCE_MANUAL,
            'payload_json' => json_encode(['kind' => 'application'], JSON_UNESCAPED_UNICODE),
            'idempotency_key' => $idem,
            'amended_from_id' => null,
            'created_at' => $now,
        ]);

        $client = $this->access->clientOrFail($id, $user);
        if ((string) $client['status'] !== 'application_received') {
            $this->changeStatus($id, 'application_received', $user, $request);
        } else {
            $this->db->update('clients', [
                'updated_at' => $now,
                'updated_by' => $user['id'],
            ], ['id' => $id]);
        }

        $this->audit->log((int) $user['id'], 'client.application', 'client', $id, $request, [
            'idempotency_key' => $idem,
        ]);
        return $this->get($id, $user);
    }

    public function assign(int $id, int $managerId, array $user, Request $request): array
    {
        $this->access->requireAdmin($user);
        $this->access->clientOrFail($id, $user);
        $manager = $this->db->fetch('SELECT * FROM users WHERE id = :id AND role = :r', [
            'id' => $managerId,
            'r' => Catalog::ROLE_MANAGER,
        ]);
        if (!$manager) {
            throw HttpException::notFound('Менеджер не найден');
        }
        $this->db->update('clients', [
            'manager_id' => $managerId,
            'updated_at' => $this->db->now(),
            'updated_by' => $user['id'],
        ], ['id' => $id]);
        $this->audit->log((int) $user['id'], 'client.assign', 'client', $id, $request, ['manager_id' => $managerId]);
        return $this->get($id, $user);
    }

    /** @return list<array<string,mixed>> */
    public function mapMarkers(array $user, array $filters): array
    {
        $where = [$this->access->managerFilterSql($user, 'c')];
        $params = [];
        if (!empty($filters['type'])) {
            $where[] = 'c.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['manager_id']) && $this->access->isAdmin($user)) {
            $where[] = 'c.manager_id = :mid';
            $params['mid'] = (int) $filters['manager_id'];
        }
        $sqlWhere = implode(' AND ', $where);
        $clients = $this->db->fetchAll(
            "SELECT c.id, c.uuid, c.type, c.status, c.full_name, c.company_name, c.address, c.lat, c.lng, u.full_name AS manager_name
             FROM clients c
             LEFT JOIN users u ON u.id = c.manager_id
             WHERE {$sqlWhere}",
            $params
        );
        $markers = [];
        foreach ($clients as $c) {
            if ($c['lat'] !== null && $c['lng'] !== null) {
                $markers[] = [
                    'kind' => 'client',
                    'client_id' => (int) $c['id'],
                    'title' => $c['company_name'] ?: $c['full_name'],
                    'type' => $c['type'],
                    'status' => $c['status'],
                    'status_label' => Catalog::STATUSES[$c['status']] ?? $c['status'],
                    'address' => $c['address'],
                    'manager_name' => $c['manager_name'],
                    'lat' => (float) $c['lat'],
                    'lng' => (float) $c['lng'],
                ];
            }
            $objects = $this->db->fetchAll(
                'SELECT * FROM client_objects WHERE client_id = :id AND lat IS NOT NULL AND lng IS NOT NULL',
                ['id' => $c['id']]
            );
            foreach ($objects as $o) {
                $markers[] = [
                    'kind' => 'object',
                    'client_id' => (int) $c['id'],
                    'object_id' => (int) $o['id'],
                    'title' => ($c['company_name'] ?: $c['full_name']) . ' — ' . $o['name'],
                    'type' => $c['type'],
                    'status' => $c['status'],
                    'status_label' => Catalog::STATUSES[$c['status']] ?? $c['status'],
                    'address' => $o['address'],
                    'manager_name' => $c['manager_name'],
                    'lat' => (float) $o['lat'],
                    'lng' => (float) $o['lng'],
                ];
            }
        }
        return $markers;
    }

    /** @param array<string,mixed> $input */
    private function validate(array $input, string $type, bool $partial = false): void
    {
        if ($type === Catalog::TYPE_PERSON) {
            if (!$partial && trim((string) ($input['full_name'] ?? '')) === '') {
                throw new HttpException('Укажите ФИО');
            }
            if (!$partial && trim((string) ($input['phone_primary'] ?? '')) === '') {
                throw new HttpException('Укажите основной телефон');
            }
        } else {
            if (!$partial && trim((string) ($input['company_name'] ?? '')) === '') {
                throw new HttpException('Укажите название компании');
            }
        }
        if ($type === Catalog::TYPE_PERSON && !empty($input['iin'])) {
            $iin = preg_replace('/\\D+/', '', (string) $input['iin']);
            if ($iin === null || strlen($iin) !== 12) {
                throw new HttpException('ИИН должен содержать 12 цифр');
            }
        }
        if (!empty($input['bin'])) {
            $bin = Catalog::normalizeBin($input['bin']);
            if ($bin && strlen($bin) !== 12) {
                throw new HttpException('БИН должен содержать 12 цифр');
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function syncRelated(int $clientId, array $input, string $now, bool $replacePhonesOnCreate = true): void
    {
        if (!empty($input['phone_primary']) && $replacePhonesOnCreate) {
            $this->db->insert('client_phones', [
                'client_id' => $clientId,
                'phone' => $input['phone_primary'],
                'phone_norm' => Catalog::normalizePhone($input['phone_primary']),
                'label' => 'Основной',
                'is_primary' => 1,
                'created_at' => $now,
            ]);
        }
        if (!empty($input['phone_secondary']) && $replacePhonesOnCreate) {
            $this->db->insert('client_phones', [
                'client_id' => $clientId,
                'phone' => $input['phone_secondary'],
                'phone_norm' => Catalog::normalizePhone($input['phone_secondary']),
                'label' => 'Дополнительный',
                'is_primary' => 0,
                'created_at' => $now,
            ]);
        }
        if (!empty($input['phones']) && is_array($input['phones'])) {
            foreach ($input['phones'] as $p) {
                $phone = is_array($p) ? ($p['phone'] ?? '') : (string) $p;
                if ($phone === '') {
                    continue;
                }
                $this->db->insert('client_phones', [
                    'client_id' => $clientId,
                    'phone' => $phone,
                    'phone_norm' => Catalog::normalizePhone($phone),
                    'label' => is_array($p) ? ($p['label'] ?? null) : null,
                    'is_primary' => is_array($p) && !empty($p['is_primary']) ? 1 : 0,
                    'created_at' => $now,
                ]);
            }
        }
        if (!empty($input['contacts']) && is_array($input['contacts'])) {
            foreach ($input['contacts'] as $c) {
                if (empty($c['full_name'])) {
                    continue;
                }
                $this->db->insert('client_contacts', [
                    'client_id' => $clientId,
                    'full_name' => $c['full_name'],
                    'position' => $c['position'] ?? null,
                    'phone' => $c['phone'] ?? null,
                    'phone_norm' => Catalog::normalizePhone($c['phone'] ?? null),
                    'email' => $c['email'] ?? null,
                    'is_primary' => !empty($c['is_primary']) ? 1 : 0,
                    'created_at' => $now,
                ]);
            }
        }
        if (!empty($input['objects']) && is_array($input['objects'])) {
            foreach ($input['objects'] as $o) {
                if (empty($o['name'])) {
                    continue;
                }
                $this->db->insert('client_objects', [
                    'uuid' => $o['uuid'] ?? $this->uuid(),
                    'client_id' => $clientId,
                    'name' => $o['name'],
                    'address' => $o['address'] ?? null,
                    'lat' => $o['lat'] ?? null,
                    'lng' => $o['lng'] ?? null,
                    'comment' => $o['comment'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $client */
    private function attachCardSections(array &$client, int $id): void
    {
        $p = ['id' => $id];
        $calls = $this->db->fetchAll(
            'SELECT cl.*, u.full_name AS manager_name, i.comment, i.topic, i.offered, i.interest
             FROM calls cl
             JOIN users u ON u.id = cl.user_id
             LEFT JOIN interactions i ON i.id = cl.interaction_id
             WHERE cl.client_id = :id
             ORDER BY cl.started_at DESC, cl.id DESC',
            $p
        );
        foreach ($calls as &$call) {
            $call = Catalog::decorateCall($call);
        }
        unset($call);
        $client['calls'] = $calls;

        $wa = $this->db->fetchAll(
            'SELECT w.*, u.full_name AS manager_name
             FROM whatsapp_events w
             JOIN users u ON u.id = w.user_id
             WHERE w.client_id = :id
             ORDER BY w.created_at DESC, w.id DESC',
            $p
        );
        foreach ($wa as &$row) {
            $row = Catalog::decorateWhatsapp($row);
        }
        unset($row);
        $client['whatsapp'] = $wa;

        $notes = [];
        $meetings = [];
        $offers = [];
        foreach ($client['timeline'] as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type === 'note') {
                $notes[] = $item;
            } elseif ($type === 'meeting') {
                $meetings[] = $item;
            } elseif ($type === 'offer') {
                $offers[] = $item;
            }
        }
        foreach ($wa as $row) {
            if (!empty($row['offer_sent']) || ($row['kind'] ?? '') === 'offer') {
                $offers[] = [
                    'id' => 'wa-' . $row['id'],
                    'type' => 'offer',
                    'type_label' => 'Коммерческое предложение',
                    'created_at' => $row['created_at'],
                    'user_name' => $row['manager_name'],
                    'offered' => $row['comment'] ?? '',
                    'comment' => $row['comment'] ?? '',
                    'source_label' => 'WhatsApp',
                    'channel' => 'whatsapp',
                ];
            }
        }
        $client['notes'] = $notes;
        $client['meetings'] = $meetings;
        $client['offers'] = $offers;

        $tasks = $this->db->fetchAll(
            'SELECT t.*, u.full_name AS assignee_name
             FROM tasks t
             JOIN users u ON u.id = t.assigned_to
             WHERE t.client_id = :id
             ORDER BY CASE WHEN t.status = \'open\' THEN 0 ELSE 1 END, t.due_at IS NULL, t.due_at ASC',
            $p
        );
        foreach ($tasks as &$task) {
            $task['overdue'] = $task['status'] === Catalog::TASK_OPEN && $task['due_at'] && $task['due_at'] < $this->db->now();
        }
        unset($task);
        $client['tasks'] = $tasks;

        $history = $this->db->fetchAll(
            'SELECT sh.*, u.full_name AS user_name
             FROM status_history sh
             JOIN users u ON u.id = sh.user_id
             WHERE sh.client_id = :id
             ORDER BY sh.created_at DESC, sh.id DESC',
            $p
        );
        $connections = [];
        foreach ($history as &$ev) {
            $ev['from_label'] = $ev['from_status'] ? (Catalog::STATUSES[$ev['from_status']] ?? $ev['from_status']) : null;
            $ev['to_label'] = Catalog::STATUSES[$ev['to_status']] ?? $ev['to_status'];
            if (in_array($ev['to_status'] ?? '', ['connecting', 'connected'], true)) {
                $connections[] = $ev;
            }
        }
        unset($ev);
        $client['status_history'] = $history;
        $client['applications'] = array_values(array_filter(
            $client['timeline'] ?? [],
            static function (array $item): bool {
                $payload = $item['payload'] ?? [];
                return ($item['result'] ?? '') === 'application_received'
                    || (($payload['kind'] ?? '') === 'application');
            }
        ));
        $client['connections'] = $connections;
    }

    /** @param array<string,mixed> $row */
    private function decorate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['status_label'] = Catalog::STATUSES[$row['status']] ?? $row['status'];
        $row['type_label'] = $row['type'] === Catalog::TYPE_COMPANY ? 'Юридическое лицо' : 'Физическое лицо';
        $row['display_name'] = $row['company_name'] ?: $row['full_name'];
        return $row;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
