<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;
use MTelecom\Sales\Core\Request;

final class InteractionService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private AuditService $audit,
        private FollowUpService $followUp
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(int $clientId, array $input, array $user, Request $request): array
    {
        $this->access->clientOrFail($clientId, $user);
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (is_string($idem) && $idem !== '') {
            $existing = $this->db->fetch('SELECT * FROM interactions WHERE idempotency_key = :k OR uuid = :u', ['k' => $idem, 'u' => $idem]);
            if ($existing) {
                return $existing;
            }
        }
        $type = $input['type'] ?? 'note';
        if (!isset(Catalog::INTERACTION_TYPES[$type])) {
            throw new HttpException('Неизвестный тип взаимодействия');
        }
        $result = $input['result'] ?? null;
        if ($result && !isset(Catalog::RESULTS[$result])) {
            throw new HttpException('Неизвестный результат контакта');
        }
        $dataSource = Catalog::normalizeDataSource(
            isset($input['data_source']) ? (string) $input['data_source'] : null,
            isset($input['source']) ? (string) $input['source'] : Catalog::SOURCE_MANAGER
        );
        if ($type !== 'call' && $dataSource === Catalog::DATA_SOURCE_SYSTEM) {
            $dataSource = Catalog::DATA_SOURCE_MANUAL;
        }
        $now = $this->db->now();
        $id = $this->db->insert('interactions', [
            'uuid' => $input['uuid'] ?? $this->uuid(),
            'client_id' => $clientId,
            'object_id' => $input['object_id'] ?? null,
            'user_id' => $user['id'],
            'type' => $type,
            'direction' => $input['direction'] ?? 'outgoing',
            'result' => $result,
            'interest' => $input['interest'] ?? null,
            'topic' => $input['topic'] ?? null,
            'offered' => $input['offered'] ?? null,
            'next_action' => $input['next_action'] ?? null,
            'next_contact_at' => $input['next_contact_at'] ?? null,
            'comment' => $input['comment'] ?? null,
            'source' => Catalog::legacySource($dataSource),
            'data_source' => $dataSource,
            'payload_json' => isset($input['payload']) ? json_encode($input['payload'], JSON_UNESCAPED_UNICODE) : null,
            'idempotency_key' => $idem,
            'amended_from_id' => null,
            'created_at' => $now,
        ]);
        if (!empty($input['next_contact_at'])) {
            $this->db->update('clients', [
                'next_contact_at' => $input['next_contact_at'],
                'updated_at' => $now,
                'updated_by' => $user['id'],
            ], ['id' => $clientId]);
            if ($type !== 'call' && $type !== 'whatsapp') {
                $this->db->insert('tasks', [
                    'uuid' => $this->uuid(),
                    'client_id' => $clientId,
                    'created_by' => $user['id'],
                    'assigned_to' => $user['id'],
                    'title' => 'Повторный контакт',
                    'details' => $input['next_action'] ?? $input['comment'] ?? null,
                    'due_at' => $input['next_contact_at'],
                    'status' => Catalog::TASK_OPEN,
                    'completed_at' => null,
                    'idempotency_key' => $idem ? $idem . ':task' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        $this->maybeFollowStatus($clientId, $result, $user, $request);
        $this->audit->log((int) $user['id'], 'interaction.create', 'interaction', $id, $request, ['type' => $type]);
        return $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $id]) ?? [];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function recordCall(int $clientId, array $input, array $user, Request $request): array
    {
        $this->access->clientOrFail($clientId, $user);
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (is_string($idem) && $idem !== '') {
            $existing = $this->db->fetch('SELECT * FROM calls WHERE idempotency_key = :k OR uuid = :u', ['k' => $idem, 'u' => $idem]);
            if ($existing) {
                $existing = Catalog::decorateCall($existing);
                $existing['interaction'] = !empty($existing['interaction_id'])
                    ? $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $existing['interaction_id']])
                    : null;
                return $existing;
            }
        }
        $direction = (string) ($input['direction'] ?? 'outgoing');
        if (!isset(Catalog::CALL_DIRECTIONS[$direction])) {
            $direction = 'outgoing';
        }
        $channel = (string) ($input['channel'] ?? 'sim');
        if (!isset(Catalog::CALL_CHANNELS[$channel])) {
            $channel = 'sim';
        }
        $claimed = Catalog::normalizeDataSource(
            isset($input['data_source']) ? (string) $input['data_source'] : null,
            isset($input['source']) ? (string) $input['source'] : null
        );
        if ($channel === 'sim') {
            if (Catalog::isSystemVerified($claimed)) {
                $dataSource = Catalog::DATA_SOURCE_SYSTEM;
                $talk = max(0, (int) ($input['talk_duration_sec'] ?? $input['duration_sec'] ?? 0));
                $connected = !empty($input['connected']) || $talk > 0;
                if ($talk <= 0) {
                    $connected = false;
                }
            } else {
                $dataSource = Catalog::DATA_SOURCE_UNVERIFIED;
                $talk = 0;
                $connected = false;
            }
        } else {
            $dataSource = Catalog::DATA_SOURCE_MANUAL;
            $talk = max(0, (int) ($input['talk_duration_sec'] ?? $input['duration_sec'] ?? 0));
            $connected = !empty($input['connected']) || $talk > 0;
            if ($talk <= 0) {
                $connected = false;
            }
        }
        $source = Catalog::legacySource($dataSource);
        $category = Catalog::callCategory($talk, $connected);
        $now = $this->db->now();
        $started = $input['started_at'] ?? $now;
        $interaction = $this->create($clientId, [
            'uuid' => $input['interaction_uuid'] ?? $this->uuid(),
            'idempotency_key' => $idem ? $idem . ':i' : null,
            'type' => 'call',
            'direction' => $direction,
            'result' => $input['result'] ?? ($connected ? 'answered' : 'no_answer'),
            'interest' => $input['interest'] ?? null,
            'topic' => $input['topic'] ?? null,
            'offered' => $input['offered'] ?? null,
            'next_action' => $input['next_action'] ?? null,
            'next_contact_at' => $input['next_contact_at'] ?? null,
            'comment' => $input['comment'] ?? null,
            'source' => $source,
            'data_source' => $dataSource,
            'payload' => [
                'duration_sec' => $talk,
                'talk_duration_sec' => $talk,
                'connected' => $connected,
                'category' => $category,
                'data_source' => $dataSource,
            ],
        ], $user, $request);

        $id = $this->db->insert('calls', [
            'uuid' => $input['uuid'] ?? $this->uuid(),
            'client_id' => $clientId,
            'user_id' => $user['id'],
            'interaction_id' => $interaction['id'] ?? null,
            'phone' => $input['phone'] ?? '',
            'direction' => $direction,
            'channel' => $channel,
            'started_at' => $started,
            'connected' => $connected ? 1 : 0,
            'duration_sec' => $talk,
            'talk_duration_sec' => $talk,
            'category' => $category,
            'result' => $input['result'] ?? ($connected ? 'answered' : 'no_answer'),
            'source' => $source,
            'data_source' => $dataSource,
            'idempotency_key' => $idem,
            'created_at' => $now,
        ]);
        $row = Catalog::decorateCall($this->db->fetch('SELECT * FROM calls WHERE id = :id', ['id' => $id]) ?? []);
        $row['interaction'] = $interaction;
        if (!$this->isAttemptOnly($input)) {
            $statusResult = $this->statusResult($input, $row['result'] ?? null);
            $row['follow_up'] = $this->followUp->apply($clientId, is_string($statusResult) ? $statusResult : ($row['result'] ?? null), $input, $user, $request);
        }
        return $row;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function completeCall(int $callId, array $input, array $user, Request $request): array
    {
        $row = $this->db->fetch('SELECT * FROM calls WHERE id = :id', ['id' => $callId]);
        if (!$row) {
            throw HttpException::notFound('Звонок не найден');
        }
        $this->access->clientOrFail((int) $row['client_id'], $user);

        $currentDs = Catalog::normalizeDataSource(
            isset($row['data_source']) ? (string) $row['data_source'] : null,
            isset($row['source']) ? (string) $row['source'] : null
        );
        $talk = (int) $row['talk_duration_sec'];
        $connected = ((int) $row['connected']) === 1;
        $dataSource = $currentDs;

        if ($currentDs !== Catalog::DATA_SOURCE_SYSTEM) {
            $claimed = Catalog::normalizeDataSource(
                isset($input['data_source']) ? (string) $input['data_source'] : null,
                isset($input['source']) ? (string) $input['source'] : null
            );
            if (Catalog::isSystemVerified($claimed)) {
                $talk = max(0, (int) ($input['talk_duration_sec'] ?? $input['duration_sec'] ?? 0));
                $connected = !empty($input['connected']) || $talk > 0;
                if ($talk <= 0) {
                    $connected = false;
                }
                $dataSource = Catalog::DATA_SOURCE_SYSTEM;
            }
        }

        $category = Catalog::callCategory($talk, $connected);
        $result = $input['result'] ?? $row['result'];
        if ($result && !isset(Catalog::RESULTS[(string) $result])) {
            $result = $row['result'];
        }

        $now = $this->db->now();
        $this->db->update('calls', [
            'connected' => $connected ? 1 : 0,
            'duration_sec' => $talk,
            'talk_duration_sec' => $talk,
            'category' => $category,
            'result' => $result,
            'source' => Catalog::legacySource($dataSource),
            'data_source' => $dataSource,
        ], ['id' => $callId]);

        if (!empty($row['interaction_id'])) {
            $inter = $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $row['interaction_id']]);
            $payload = [];
            if ($inter && !empty($inter['payload_json'])) {
                $decoded = json_decode((string) $inter['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $payload['duration_sec'] = $talk;
            $payload['talk_duration_sec'] = $talk;
            $payload['connected'] = $connected;
            $payload['category'] = $category;
            $payload['data_source'] = $dataSource;
            $interUpdate = [
                'result' => $result,
                'source' => Catalog::legacySource($dataSource),
                'data_source' => $dataSource,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ];
            foreach (['interest', 'topic', 'offered', 'next_action', 'next_contact_at', 'comment'] as $field) {
                if (array_key_exists($field, $input) && $input[$field] !== null) {
                    $interUpdate[$field] = $input[$field] === '' ? null : $input[$field];
                }
            }
            $this->db->update('interactions', $interUpdate, ['id' => $row['interaction_id']]);
            if (!empty($input['next_contact_at'])) {
                $this->db->update('clients', [
                    'next_contact_at' => $input['next_contact_at'],
                    'updated_at' => $now,
                    'updated_by' => $user['id'],
                ], ['id' => $row['client_id']]);
            }
            $this->maybeFollowStatus((int) $row['client_id'], is_string($result) ? $result : null, $user, $request);
        }

        $this->audit->log((int) $user['id'], 'call.complete', 'call', $callId, $request, [
            'data_source' => $dataSource,
            'talk_duration_sec' => $talk,
        ]);

        $fresh = Catalog::decorateCall($this->db->fetch('SELECT * FROM calls WHERE id = :id', ['id' => $callId]) ?? []);
        $statusResult = $this->statusResult($input, is_string($result) ? $result : null);
        if ($statusResult && $statusResult !== $result) {
            $this->maybeFollowStatus((int) $row['client_id'], $statusResult, $user, $request);
        }
        $fresh['follow_up'] = $this->followUp->apply((int) $row['client_id'], is_string($result) ? $result : null, $input, $user, $request);
        return $fresh;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function recordWhatsapp(int $clientId, array $input, array $user, Request $request): array
    {
        $this->access->clientOrFail($clientId, $user);
        $kind = $input['kind'] ?? 'chat';
        if (!isset(Catalog::WHATSAPP_KINDS[$kind])) {
            throw new HttpException('Неизвестный тип WhatsApp-события');
        }
        $idem = $input['idempotency_key'] ?? $input['uuid'] ?? null;
        if (is_string($idem) && $idem !== '') {
            $existing = $this->db->fetch('SELECT * FROM whatsapp_events WHERE idempotency_key = :k OR uuid = :u', ['k' => $idem, 'u' => $idem]);
            if ($existing) {
                $existing = Catalog::decorateWhatsapp($existing);
                $existing['interaction'] = !empty($existing['interaction_id'])
                    ? $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $existing['interaction_id']])
                    : null;
                return $existing;
            }
        }
        $dataSource = Catalog::DATA_SOURCE_MANUAL;
        $duration = max(0, (int) ($input['duration_sec'] ?? $input['talk_duration_sec'] ?? 0));
        $source = Catalog::legacySource($dataSource);
        $now = $this->db->now();
        $interaction = $this->create($clientId, [
            'uuid' => $input['interaction_uuid'] ?? $this->uuid(),
            'idempotency_key' => $idem ? $idem . ':i' : null,
            'type' => $kind === 'offer' ? 'offer' : 'whatsapp',
            'result' => $input['result'] ?? null,
            'interest' => $input['interest'] ?? null,
            'topic' => $input['topic'] ?? null,
            'offered' => $input['offered'] ?? null,
            'next_action' => $input['next_action'] ?? null,
            'next_contact_at' => $input['next_contact_at'] ?? null,
            'comment' => $input['comment'] ?? null,
            'source' => $source,
            'data_source' => $dataSource,
            'payload' => [
                'kind' => $kind,
                'offer_sent' => !empty($input['offer_sent']),
                'duration_sec' => $duration,
                'data_source' => $dataSource,
            ],
        ], $user, $request);
        $id = $this->db->insert('whatsapp_events', [
            'uuid' => $input['uuid'] ?? $this->uuid(),
            'client_id' => $clientId,
            'user_id' => $user['id'],
            'interaction_id' => $interaction['id'] ?? null,
            'phone' => $input['phone'] ?? '',
            'kind' => $kind,
            'offer_sent' => !empty($input['offer_sent']) || $kind === 'offer' ? 1 : 0,
            'screenshot_file_id' => $input['screenshot_file_id'] ?? null,
            'comment' => $input['comment'] ?? null,
            'duration_sec' => $duration,
            'source' => $source,
            'data_source' => $dataSource,
            'idempotency_key' => $idem,
            'created_at' => $now,
        ]);
        $row = Catalog::decorateWhatsapp($this->db->fetch('SELECT * FROM whatsapp_events WHERE id = :id', ['id' => $id]) ?? []);
        $row['interaction'] = $interaction;
        if (!$this->isAttemptOnly($input) && !empty($input['result'])) {
            $row['follow_up'] = $this->followUp->apply($clientId, (string) $input['result'], $input, $user, $request);
        }
        return $row;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function amend(int $interactionId, array $input, array $user, Request $request): array
    {
        $old = $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $interactionId]);
        if (!$old) {
            throw HttpException::notFound('Запись не найдена');
        }
        $this->access->clientOrFail((int) $old['client_id'], $user);
        $now = $this->db->now();
        $id = $this->db->insert('interactions', [
            'uuid' => $this->uuid(),
            'client_id' => $old['client_id'],
            'object_id' => $old['object_id'],
            'user_id' => $user['id'],
            'type' => 'amendment',
            'direction' => $old['direction'],
            'result' => $input['result'] ?? $old['result'],
            'interest' => $input['interest'] ?? $old['interest'],
            'topic' => $input['topic'] ?? $old['topic'],
            'offered' => $input['offered'] ?? $old['offered'],
            'next_action' => $input['next_action'] ?? $old['next_action'],
            'next_contact_at' => $input['next_contact_at'] ?? $old['next_contact_at'],
            'comment' => $input['comment'] ?? ('Исправление записи #' . $old['id']),
            'source' => Catalog::SOURCE_MANAGER,
            'data_source' => Catalog::DATA_SOURCE_MANUAL,
            'payload_json' => json_encode(['original_id' => $old['id'], 'reason' => $input['reason'] ?? ''], JSON_UNESCAPED_UNICODE),
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'amended_from_id' => $old['id'],
            'created_at' => $now,
        ]);
        $this->audit->log((int) $user['id'], 'interaction.amend', 'interaction', $id, $request, [
            'original_id' => $old['id'],
        ]);
        return $this->db->fetch('SELECT * FROM interactions WHERE id = :id', ['id' => $id]) ?? [];
    }

    private function maybeFollowStatus(int $clientId, ?string $result, array $user, Request $request): void
    {
        $map = [
            'interested' => 'interested',
            'offer_sent' => 'offer_sent',
            'awaiting_decision' => 'awaiting_decision',
            'awaiting_offer' => 'interested',
            'meeting_scheduled' => 'meeting_scheduled',
            'application_received' => 'application_received',
            'rejected' => 'rejected',
        ];
        if (!$result || !isset($map[$result])) {
            return;
        }
        $target = $map[$result];
        if (in_array($target, Catalog::ADMIN_ONLY_STATUSES, true)) {
            return;
        }
        $client = $this->db->fetch('SELECT status FROM clients WHERE id = :id', ['id' => $clientId]);
        if (!$client || $client['status'] === $target) {
            return;
        }
        $from = (string) $client['status'];
        $isAdmin = ($user['role'] ?? '') === Catalog::ROLE_ADMIN;
        if (!$isAdmin && !Catalog::canManagerSetStatus($from, $target)) {
            return;
        }
        $now = $this->db->now();
        $this->db->update('clients', ['status' => $target, 'updated_at' => $now, 'updated_by' => $user['id']], ['id' => $clientId]);
        $this->db->insert('status_history', [
            'client_id' => $clientId,
            'user_id' => $user['id'],
            'from_status' => $from,
            'to_status' => $target,
            'created_at' => $now,
        ]);
        $this->audit->log((int) $user['id'], 'client.status', 'client', $clientId, $request, [
            'from' => $from,
            'to' => $target,
            'via' => 'interaction',
        ]);
    }

    /** @param array<string,mixed> $input */
    private function isAttemptOnly(array $input): bool
    {
        return !empty($input['attempt']) || !empty($input['skip_follow_up']);
    }

    /** @param array<string,mixed> $input */
    private function statusResult(array $input, ?string $result): ?string
    {
        if (!empty($input['create_application'])) {
            return 'application_received';
        }
        return $result;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
