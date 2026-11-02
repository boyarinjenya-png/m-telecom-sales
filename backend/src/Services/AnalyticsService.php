<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;

final class AnalyticsService
{
    private const STUCK_DAYS = 14;
    private const SILENT_DAYS = 14;

    /** Funnel snapshot: each client in exactly one stage. */
    private const FUNNEL_MAP = [
        'new' => ['new', 'unprocessed'],
        'processed' => ['in_progress'],
        'interested' => ['interested'],
        'offer_sent' => ['offer_sent'],
        'awaiting' => ['awaiting_decision', 'meeting_scheduled'],
        'application' => ['application_received', 'connecting'],
        'connected' => ['connected'],
    ];

    public function __construct(
        private Database $db,
        private AccessControl $access
    ) {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function overview(array $user, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $ctx = $this->context($user, $filters, $from, $to);
        $cw = $ctx['client_where'];
        $params = $ctx['params'];
        $callWhere = $ctx['join_where'];
        $pperiod = $ctx['period_params'];

        $totalClients = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM clients c WHERE {$cw}", $this->without($params, ['from', 'to']));
        $newClients = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM clients c WHERE {$cw} AND c.created_at >= :from AND c.created_at <= :to",
            $params
        );
        $inWork = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM clients c WHERE {$cw} AND c.status NOT IN ('connected','rejected')",
            $this->without($params, ['from', 'to'])
        );

        $calls = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN data_source = 'system_verified' THEN 1 ELSE 0 END) AS sim_verified_total,
                    SUM(CASE WHEN data_source = 'system_verified' AND (connected = 0 OR talk_duration_sec <= 20) THEN 1 ELSE 0 END) AS sim_verified_attempts,
                    SUM(CASE WHEN data_source = 'system_verified' AND connected = 1 THEN 1 ELSE 0 END) AS sim_verified_connected,
                    SUM(CASE WHEN data_source = 'system_verified' AND connected = 1 AND talk_duration_sec > 20 THEN 1 ELSE 0 END) AS talks,
                    SUM(CASE WHEN data_source = 'system_verified' AND connected = 1 AND talk_duration_sec > 20 THEN talk_duration_sec ELSE 0 END) AS talk_sec,
                    AVG(CASE WHEN data_source = 'system_verified' AND connected = 1 AND talk_duration_sec > 20 THEN talk_duration_sec ELSE NULL END) AS avg_talk,
                    SUM(CASE WHEN data_source = 'unverified' THEN 1 ELSE 0 END) AS sim_unverified,
                    SUM(CASE WHEN data_source = 'manual' THEN 1 ELSE 0 END) AS calls_manual,
                    SUM(CASE WHEN data_source = 'system_verified' THEN 1 ELSE 0 END) AS calls_system,
                    SUM(CASE WHEN data_source <> 'system_verified' THEN 1 ELSE 0 END) AS calls_manager,
                    SUM(CASE WHEN data_source = 'system_verified' AND connected = 1 AND talk_duration_sec > 20 THEN talk_duration_sec ELSE 0 END) AS talk_sec_system
             FROM calls cl
             JOIN clients c ON c.id = cl.client_id
             WHERE {$callWhere} AND cl.started_at >= :from AND cl.started_at <= :to",
            $pperiod
        ) ?: [];

        $wa = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN kind = 'call' THEN 1 ELSE 0 END) AS calls,
                    SUM(CASE WHEN kind IN ('message_out','chat') THEN 1 ELSE 0 END) AS messages_out,
                    SUM(CASE WHEN kind = 'message_in' THEN 1 ELSE 0 END) AS replies,
                    SUM(CASE WHEN offer_sent = 1 OR kind = 'offer' THEN 1 ELSE 0 END) AS offers
             FROM whatsapp_events w
             JOIN clients c ON c.id = w.client_id
             WHERE {$callWhere} AND w.created_at >= :from AND w.created_at <= :to",
            $pperiod
        ) ?: [];

        $statusCounts = [];
        foreach (array_keys(Catalog::STATUSES) as $st) {
            $p = $this->without($params, ['from', 'to']);
            $p['st'] = $st;
            $statusCounts[$st] = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM clients c WHERE {$cw} AND c.status = :st",
                $p
            );
        }

        $periodStatusEvents = $this->periodStatusEvents($callWhere, $pperiod);
        $taskFilter = $this->taskFilter($user, $ctx['mid']);
        $now = $this->db->now();
        $overdue = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE {$taskFilter} AND status = 'open' AND due_at < :n",
            ['n' => $now]
        );
        $todayTasks = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE {$taskFilter} AND status = 'open'
             AND due_at >= :d1 AND due_at < :d2",
            [
                'd1' => $this->today()->format('Y-m-d 00:00:00'),
                'd2' => $this->today()->modify('+1 day')->format('Y-m-d 00:00:00'),
            ]
        );

        $talkSec = (int) ($calls['talk_sec'] ?? 0);
        $simVerifiedAttempts = (int) ($calls['sim_verified_attempts'] ?? 0);
        $simUnverified = (int) ($calls['sim_unverified'] ?? 0);
        $callsManual = (int) ($calls['calls_manual'] ?? 0);
        $waTotal = (int) ($wa['total'] ?? 0);
        $waCalls = (int) ($wa['calls'] ?? 0);
        return [
            'period' => ['from' => $from, 'to' => $to, 'timezone' => date_default_timezone_get()],
            'clients_total' => $totalClients,
            'clients_new' => $newClients,
            'clients_in_work' => $inWork,
            'calls_total' => (int) ($calls['total'] ?? 0),
            'dial_attempts' => $simVerifiedAttempts,
            'talks' => (int) ($calls['talks'] ?? 0),
            'talk_seconds' => $talkSec,
            'talk_seconds_avg' => $calls['avg_talk'] !== null ? (int) round((float) $calls['avg_talk']) : 0,
            'talk_seconds_system' => (int) ($calls['talk_sec_system'] ?? 0),
            'talk_seconds_manager' => 0,
            'calls_system' => (int) ($calls['calls_system'] ?? 0),
            'calls_manager' => (int) ($calls['calls_manager'] ?? 0),
            'sim_verified_total' => (int) ($calls['sim_verified_total'] ?? 0),
            'sim_verified_attempts' => $simVerifiedAttempts,
            'sim_verified_connected' => (int) ($calls['sim_verified_connected'] ?? 0),
            'sim_verified_talks' => (int) ($calls['talks'] ?? 0),
            'sim_verified_talk_seconds' => $talkSec,
            'sim_verified_talk_avg' => $calls['avg_talk'] !== null ? (int) round((float) $calls['avg_talk']) : 0,
            'sim_unverified' => $simUnverified,
            'calls_manual' => $callsManual,
            'unverified_interactions' => $simUnverified + $callsManual + $waTotal,
            'whatsapp_total' => $waTotal,
            'whatsapp_calls' => $waCalls,
            'whatsapp_messages' => (int) ($wa['messages_out'] ?? 0),
            'whatsapp_replies' => (int) ($wa['replies'] ?? 0),
            'whatsapp_offers' => (int) ($wa['offers'] ?? 0),
            'whatsapp_manual' => $waTotal,
            'interested' => $periodStatusEvents['interested'] ?? 0,
            'offers' => $periodStatusEvents['offer_sent'] ?? 0,
            'meetings' => $periodStatusEvents['meeting_scheduled'] ?? 0,
            'applications' => $periodStatusEvents['application_received'] ?? 0,
            'connections' => $periodStatusEvents['connected'] ?? 0,
            'rejected' => $periodStatusEvents['rejected'] ?? 0,
            'overdue_tasks' => $overdue,
            'today_contacts' => $todayTasks,
            'by_status' => $statusCounts,
            'by_status_period' => $periodStatusEvents,
            'metric_kind' => [
                'clients_total' => 'snapshot',
                'clients_in_work' => 'snapshot',
                'by_status' => 'snapshot',
                'overdue_tasks' => 'snapshot',
                'today_contacts' => 'snapshot',
                'clients_new' => 'period_events',
                'calls_total' => 'period_events',
                'dial_attempts' => 'period_events',
                'talks' => 'period_events',
                'talk_seconds' => 'period_events',
                'sim_verified_total' => 'period_events',
                'sim_verified_attempts' => 'period_events',
                'sim_verified_connected' => 'period_events',
                'sim_verified_talk_seconds' => 'period_events',
                'sim_unverified' => 'period_events',
                'unverified_interactions' => 'period_events',
                'whatsapp_total' => 'period_events',
                'interested' => 'period_events',
                'offers' => 'period_events',
                'meetings' => 'period_events',
                'applications' => 'period_events',
                'connections' => 'period_events',
                'rejected' => 'period_events',
            ],
            'notes' => [
                'talks' => 'Подтверждённый SIM-разговор: data_source=system_verified, соединение состоялось и длительность > 20 сек. Нажатие «Позвонить» само по себе разговором не является.',
                'talk_seconds' => 'Сумма только подтверждённой системой длительности SIM-разговоров. Неподтверждённая и ручная длительность сюда не входят.',
                'sim_verified' => 'SIM-звонки с фактической длительностью из системных данных Android. Классификацию длительности считает сервер.',
                'sim_unverified' => 'SIM-звонки без достоверных системных данных. Длительность не показывается как фактическая.',
                'whatsapp' => 'WhatsApp фиксирует менеджер вручную (сообщение, ответ, звонок). Длительность WhatsApp всегда «Введено вручную». Переписка не читается. Официальная WhatsApp Business Platform может быть подключена позже.',
            ],
        ];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function managers(array $user, array $filters): array
    {
        $this->access->requireAdmin($user);
        $managers = $this->db->fetchAll(
            "SELECT * FROM users WHERE role = :r ORDER BY full_name",
            ['r' => Catalog::ROLE_MANAGER]
        );
        $out = [];
        foreach ($managers as $m) {
            $f = array_merge($filters, ['manager_id' => $m['id']]);
            $ov = $this->overview($user, $f);
            $assigned = (int) $this->db->fetchColumn(
                'SELECT COUNT(*) FROM clients WHERE manager_id = :id',
                ['id' => $m['id']]
            );
            $processed = $this->uniqueReached($user, $f, ['in_progress', 'interested', 'offer_sent', 'awaiting_decision', 'meeting_scheduled', 'application_received', 'connecting', 'connected']);
            $conv = $this->conversion(
                $this->cohortReached($user, $f, ['connected']),
                $ov['clients_new']
            );
            $out[] = [
                'manager_id' => (int) $m['id'],
                'full_name' => $m['full_name'],
                'status' => $m['status'],
                'assigned_clients' => $assigned,
                'clients_in_work' => $ov['clients_in_work'],
                'clients_new' => $ov['clients_new'],
                'processed_clients' => $processed,
                'calls' => $ov['calls_total'],
                'dial_attempts' => $ov['dial_attempts'],
                'talks' => $ov['talks'],
                'talk_seconds' => $ov['talk_seconds'],
                'talk_seconds_avg' => $ov['talk_seconds_avg'],
                'sim_verified_total' => $ov['sim_verified_total'],
                'sim_verified_connected' => $ov['sim_verified_connected'],
                'sim_verified_talk_seconds' => $ov['sim_verified_talk_seconds'],
                'sim_unverified' => $ov['sim_unverified'],
                'unverified_interactions' => $ov['unverified_interactions'],
                'whatsapp' => $ov['whatsapp_total'],
                'interested' => $ov['interested'],
                'offers' => $ov['offers'],
                'meetings' => $ov['meetings'],
                'applications' => $ov['applications'],
                'connections' => $ov['connections'],
                'rejected' => $ov['rejected'],
                'overdue' => $ov['overdue_tasks'],
                'conversion_new_to_connected' => $conv,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function rankings(array $user, array $filters): array
    {
        return $this->rankFrom($this->managers($user, $filters));
    }

    /**
     * @param list<array<string,mixed>> $managers
     * @return array<string,mixed>
     */
    public function rankFrom(array $managers): array
    {
        $rows = array_values(array_filter(
            $managers,
            fn ($m) => ($m['status'] ?? '') === Catalog::USER_ACTIVE
        ));
        $by = function (array $rows, string $key, bool $conv = false): array {
            $copy = $rows;
            usort($copy, function ($a, $b) use ($key, $conv) {
                if ($conv) {
                    $av = $a['conversion_new_to_connected']['value'];
                    $bv = $b['conversion_new_to_connected']['value'];
                    if ($av === null && $bv === null) {
                        return 0;
                    }
                    if ($av === null) {
                        return 1;
                    }
                    if ($bv === null) {
                        return -1;
                    }
                    return $bv <=> $av;
                }
                return ($b[$key] ?? 0) <=> ($a[$key] ?? 0);
            });
            $rank = 1;
            $out = [];
            foreach ($copy as $row) {
                if ($conv && ($row['conversion_new_to_connected']['value'] ?? null) === null) {
                    $out[] = [
                        'rank' => null,
                        'manager_id' => $row['manager_id'],
                        'full_name' => $row['full_name'],
                        'value' => null,
                        'label' => 'Недостаточно данных',
                    ];
                    continue;
                }
                $out[] = [
                    'rank' => $rank++,
                    'manager_id' => $row['manager_id'],
                    'full_name' => $row['full_name'],
                    'value' => $conv ? $row['conversion_new_to_connected']['value'] : $row[$key],
                    'label' => $conv ? $row['conversion_new_to_connected']['label'] : (string) $row[$key],
                ];
            }
            return $out;
        };
        return [
            'by_connections' => $by($rows, 'connections'),
            'by_applications' => $by($rows, 'applications'),
            'by_conversion' => $by($rows, 'conversion_new_to_connected', true),
            'by_processed' => $by($rows, 'processed_clients'),
            'basis' => 'Рейтинг по объективным событиям периода: подключения, заявки, конверсия новых→подключения (когорта созданных за период), обработанные клиенты (уникальные переходы дальше new/unprocessed). Звонки и длительность в рейтинг не входят.',
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function funnel(array $user, array $filters): array
    {
        $ov = $this->overview($user, $filters);
        $snap = $ov['by_status'];
        $stages = [];
        foreach (self::FUNNEL_MAP as $key => $statuses) {
            $n = 0;
            foreach ($statuses as $st) {
                $n += (int) ($snap[$st] ?? 0);
            }
            $stages[] = [
                'key' => $key,
                'label' => $this->funnelLabel($key),
                'count' => $n,
                'kind' => 'snapshot',
                'statuses' => $statuses,
            ];
        }

        $hist = [
            'new' => $ov['clients_new'],
            'processed' => $this->uniqueReached($user, $filters, ['in_progress', 'interested', 'offer_sent', 'awaiting_decision', 'meeting_scheduled', 'application_received', 'connecting', 'connected']),
            'interested' => $this->uniqueReached($user, $filters, ['interested']),
            'offer_sent' => $this->uniqueReached($user, $filters, ['offer_sent']),
            'awaiting' => $this->uniqueReached($user, $filters, ['awaiting_decision', 'meeting_scheduled']),
            'application' => $this->uniqueReached($user, $filters, ['application_received', 'connecting']),
            'connected' => $this->uniqueReached($user, $filters, ['connected']),
        ];
        $chain = ['new', 'processed', 'interested', 'offer_sent', 'awaiting', 'application', 'connected'];
        $conversions = [];
        for ($i = 1; $i < count($chain); $i++) {
            $fromKey = $chain[$i - 1];
            $toKey = $chain[$i];
            $conversions[$fromKey . '_to_' . $toKey] = $this->conversion($hist[$toKey], $hist[$fromKey]);
        }

        return [
            'period' => $ov['period'],
            'snapshot' => $stages,
            'historical_unique' => $hist,
            'step_conversion' => $conversions,
            'method' => 'Снимок: каждый клиент ровно в одном текущем этапе. Историческая конверсия: уникальные клиенты с переходом status_history.to_status в периоде. Не смешиваются.',
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function series(array $user, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $ctx = $this->context($user, $filters, $from, $to);
        $grain = $this->grain($filters, $from, $to);

        $map = function (string $sql, array $params, string $col) : array {
            $expr = $this->dayExpr($col);
            $sql = str_replace('{bucket}', $expr, $sql);
            $rows = $this->db->fetchAll($sql, $params);
            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r['bucket']] = (int) $r['n'];
            }
            return $out;
        };

        $jw = $ctx['join_where'];
        $pp = $ctx['period_params'];
        $cw = $ctx['client_where'];

        $new = $map(
            "SELECT {bucket} AS bucket, COUNT(*) AS n FROM clients c WHERE {$cw} AND c.created_at >= :from AND c.created_at <= :to GROUP BY 1",
            $ctx['params'],
            'c.created_at'
        );
        $calls = $map(
            "SELECT {bucket} AS bucket, COUNT(*) AS n FROM calls cl JOIN clients c ON c.id = cl.client_id WHERE {$jw} AND cl.started_at >= :from AND cl.started_at <= :to GROUP BY 1",
            $pp,
            'cl.started_at'
        );
        $talks = $map(
            "SELECT {bucket} AS bucket, COUNT(*) AS n FROM calls cl JOIN clients c ON c.id = cl.client_id WHERE {$jw} AND cl.started_at >= :from AND cl.started_at <= :to AND cl.data_source = 'system_verified' AND cl.connected = 1 AND cl.talk_duration_sec > 20 GROUP BY 1",
            $pp,
            'cl.started_at'
        );

        $statusSeries = [];
        foreach (['interested', 'offer_sent', 'application_received', 'connected', 'rejected'] as $st) {
            $p = $pp + ['st' => $st];
            $statusSeries[$st] = $map(
                "SELECT {bucket} AS bucket, COUNT(DISTINCT sh.client_id) AS n FROM status_history sh JOIN clients c ON c.id = sh.client_id WHERE {$jw} AND sh.created_at >= :from AND sh.created_at <= :to AND sh.to_status = :st GROUP BY 1",
                $p,
                'sh.created_at'
            );
        }

        $keys = [
            'clients_new' => $new,
            'calls' => $calls,
            'talks' => $talks,
            'interested' => $statusSeries['interested'],
            'offers' => $statusSeries['offer_sent'],
            'applications' => $statusSeries['application_received'],
            'connections' => $statusSeries['connected'],
            'rejected' => $statusSeries['rejected'],
        ];
        $series = [];
        foreach ($keys as $name => $counts) {
            $series[$name] = $this->fillBuckets($from, $to, $grain, $counts);
        }
        return [
            'period' => ['from' => $from, 'to' => $to],
            'grain' => $grain,
            'timezone' => date_default_timezone_get(),
            'series' => $series,
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function control(array $user, array $filters): array
    {
        $ctx = $this->context($user, $filters, ...$this->period($filters));
        $cw = $ctx['client_where'];
        $base = $this->without($ctx['params'], ['from', 'to']);
        $now = $this->db->now();
        $stuckBefore = $this->today()->modify('-' . self::STUCK_DAYS . ' days')->format('Y-m-d 00:00:00');
        $silentBefore = $this->today()->modify('-' . self::SILENT_DAYS . ' days')->format('Y-m-d 00:00:00');

        $select = "SELECT c.id, c.full_name, c.company_name, c.status, c.phone_primary, c.updated_at, c.next_contact_at, u.full_name AS manager_name
                   FROM clients c LEFT JOIN users u ON u.id = c.manager_id";

        $noFirst = $this->db->fetchAll(
            "{$select} WHERE {$cw} AND c.status NOT IN ('connected','rejected')
             AND NOT EXISTS (SELECT 1 FROM calls cl WHERE cl.client_id = c.id)
             AND NOT EXISTS (SELECT 1 FROM whatsapp_events w WHERE w.client_id = c.id)
             AND NOT EXISTS (SELECT 1 FROM interactions i WHERE i.client_id = c.id AND i.type IN ('call','whatsapp','meeting'))
             ORDER BY c.created_at LIMIT 50",
            $base
        );
        $noNext = $this->db->fetchAll(
            "{$select} WHERE {$cw} AND c.status NOT IN ('connected','rejected') AND (c.next_contact_at IS NULL OR c.next_contact_at = '')
             ORDER BY c.updated_at LIMIT 50",
            $base
        );
        $overdueNext = $this->db->fetchAll(
            "{$select} WHERE {$cw} AND c.status NOT IN ('connected','rejected') AND c.next_contact_at IS NOT NULL AND c.next_contact_at < :n
             ORDER BY c.next_contact_at LIMIT 50",
            $base + ['n' => $now]
        );
        $stuck = $this->db->fetchAll(
            "{$select} WHERE {$cw} AND c.status NOT IN ('connected','rejected') AND c.updated_at < :stuck
             ORDER BY c.updated_at LIMIT 50",
            $base + ['stuck' => $stuckBefore]
        );
        $silent = $this->db->fetchAll(
            "{$select} WHERE {$cw} AND c.status NOT IN ('connected','rejected')
             AND NOT EXISTS (
                SELECT 1 FROM interactions i WHERE i.client_id = c.id AND i.created_at >= :silent
             )
             AND NOT EXISTS (SELECT 1 FROM calls cl WHERE cl.client_id = c.id AND cl.started_at >= :silent2)
             AND NOT EXISTS (SELECT 1 FROM whatsapp_events w WHERE w.client_id = c.id AND w.created_at >= :silent3)
             ORDER BY c.updated_at LIMIT 50",
            $base + ['silent' => $silentBefore, 'silent2' => $silentBefore, 'silent3' => $silentBefore]
        );

        $tf = $this->taskFilter($user, $ctx['mid']);
        $overdueTasks = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE {$tf} AND status = 'open' AND due_at < :n",
            ['n' => $now]
        );
        $doneOnTime = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE {$tf} AND status = 'done' AND due_at IS NOT NULL AND completed_at IS NOT NULL AND completed_at <= due_at
             AND completed_at >= :from AND completed_at <= :to",
            ['from' => $ctx['params']['from'], 'to' => $ctx['params']['to']]
        );
        $doneLate = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE {$tf} AND status = 'done' AND due_at IS NOT NULL AND completed_at IS NOT NULL AND completed_at > due_at
             AND completed_at >= :from AND completed_at <= :to",
            ['from' => $ctx['params']['from'], 'to' => $ctx['params']['to']]
        );

        $countSql = fn (string $extra, array $p) => (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM clients c WHERE {$cw} {$extra}",
            $p
        );
        $decorate = fn (array $rows) => array_map(function ($r) {
            $r['id'] = (int) $r['id'];
            $r['display_name'] = $r['company_name'] ?: $r['full_name'];
            $r['status_label'] = Catalog::STATUSES[$r['status']] ?? $r['status'];
            return $r;
        }, $rows);

        return [
            'thresholds' => ['stuck_days' => self::STUCK_DAYS, 'silent_days' => self::SILENT_DAYS],
            'counts' => [
                'no_first_contact' => $countSql(
                    "AND c.status NOT IN ('connected','rejected')
                     AND NOT EXISTS (SELECT 1 FROM calls cl WHERE cl.client_id = c.id)
                     AND NOT EXISTS (SELECT 1 FROM whatsapp_events w WHERE w.client_id = c.id)
                     AND NOT EXISTS (SELECT 1 FROM interactions i WHERE i.client_id = c.id AND i.type IN ('call','whatsapp','meeting'))",
                    $base
                ),
                'no_next_contact' => $countSql(
                    "AND c.status NOT IN ('connected','rejected') AND (c.next_contact_at IS NULL OR c.next_contact_at = '')",
                    $base
                ),
                'overdue_next_contact' => $countSql(
                    "AND c.status NOT IN ('connected','rejected') AND c.next_contact_at IS NOT NULL AND c.next_contact_at < :n",
                    $base + ['n' => $now]
                ),
                'stuck_on_stage' => $countSql(
                    "AND c.status NOT IN ('connected','rejected') AND c.updated_at < :stuck",
                    $base + ['stuck' => $stuckBefore]
                ),
                'no_recent_activity' => $countSql(
                    "AND c.status NOT IN ('connected','rejected')
                     AND NOT EXISTS (SELECT 1 FROM interactions i WHERE i.client_id = c.id AND i.created_at >= :silent)
                     AND NOT EXISTS (SELECT 1 FROM calls cl WHERE cl.client_id = c.id AND cl.started_at >= :silent2)
                     AND NOT EXISTS (SELECT 1 FROM whatsapp_events w WHERE w.client_id = c.id AND w.created_at >= :silent3)",
                    $base + ['silent' => $silentBefore, 'silent2' => $silentBefore, 'silent3' => $silentBefore]
                ),
                'tasks_done_on_time' => $doneOnTime,
                'tasks_done_late' => $doneLate,
                'overdue_tasks' => $overdueTasks,
            ],
            'lists' => [
                'no_first_contact' => $decorate($noFirst),
                'no_next_contact' => $decorate($noNext),
                'overdue_next_contact' => $decorate($overdueNext),
                'stuck_on_stage' => $decorate($stuck),
                'no_recent_activity' => $decorate($silent),
            ],
            'notes' => [
                'no_first_contact' => 'Нет звонка, WhatsApp и встречи в журнале.',
                'stuck_on_stage' => 'Нет обновления карточки ' . self::STUCK_DAYS . ' дней, статус не финальный.',
                'no_recent_activity' => 'Нет взаимодействий ' . self::SILENT_DAYS . ' дней.',
                'lists_cap' => 'Счётчики полные. В списках до 50 записей для перехода в карточку.',
            ],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function sales(array $user, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $ov = $this->overview($user, $filters);
        $funnel = $this->funnel($user, $filters);
        $cohortNew = $ov['clients_new'];
        $cohort = [
            'new' => $cohortNew,
            'interested' => $this->cohortReached($user, $filters, ['interested']),
            'application' => $this->cohortReached($user, $filters, ['application_received', 'connecting']),
            'connected' => $this->cohortReached($user, $filters, ['connected']),
        ];
        return [
            'period' => $ov['period'],
            'snapshot' => [
                'new' => ((int) ($ov['by_status']['new'] ?? 0)) + ((int) ($ov['by_status']['unprocessed'] ?? 0)),
                'interested' => (int) ($ov['by_status']['interested'] ?? 0),
                'offer_sent' => (int) ($ov['by_status']['offer_sent'] ?? 0),
                'awaiting_decision' => (int) ($ov['by_status']['awaiting_decision'] ?? 0),
                'application_received' => (int) ($ov['by_status']['application_received'] ?? 0),
                'connecting' => (int) ($ov['by_status']['connecting'] ?? 0),
                'connected' => (int) ($ov['by_status']['connected'] ?? 0),
                'rejected' => (int) ($ov['by_status']['rejected'] ?? 0),
            ],
            'period_events' => [
                'clients_new' => $ov['clients_new'],
                'interested' => $ov['interested'],
                'offers' => $ov['offers'],
                'awaiting' => $ov['by_status_period']['awaiting_decision'] ?? 0,
                'applications' => $ov['applications'],
                'connecting' => $ov['by_status_period']['connecting'] ?? 0,
                'connections' => $ov['connections'],
                'rejected' => $ov['rejected'],
            ],
            'cohort' => $cohort,
            'conversion' => [
                'new_to_interested' => $this->conversion($cohort['interested'], $cohort['new']),
                'interested_to_application' => $this->conversion(
                    $this->cohortReachedAll($user, $filters, [['interested'], ['application_received', 'connecting']]),
                    $cohort['interested']
                ),
                'application_to_connected' => $this->conversion(
                    $this->cohortReachedAll($user, $filters, [['application_received', 'connecting'], ['connected']]),
                    $cohort['application']
                ),
                'new_to_connected' => $this->conversion($cohort['connected'], $cohort['new']),
            ],
            'method' => 'Когорта: клиенты, созданные в периоде [' . $from . ' … ' . $to . '], часовой пояс ' . date_default_timezone_get() . '. Числитель — уникальные из этой когорты, у которых позже (до текущего момента) есть переход status_history в этап. Снимок воронки считается отдельно и в конверсию не входит.',
            'funnel' => $funnel,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:string}
     */
    public function period(array $filters): array
    {
        $preset = $filters['period'] ?? 'last_30';
        $today = $this->today();
        $now = new DateTimeImmutable('now', $this->tz());
        $end = $now->format('Y-m-d 23:59:59');
        switch ($preset) {
            case 'today':
                return [$today->format('Y-m-d 00:00:00'), $end];
            case 'yesterday':
                $y = $today->modify('-1 day');
                return [$y->format('Y-m-d 00:00:00'), $y->format('Y-m-d 23:59:59')];
            case 'last_7':
                return [$today->modify('-6 days')->format('Y-m-d 00:00:00'), $end];
            case 'this_month':
                return [$today->format('Y-m-01 00:00:00'), $end];
            case 'last_month':
                $start = $today->modify('first day of last month');
                $lastEnd = $today->modify('last day of last month');
                return [$start->format('Y-m-d 00:00:00'), $lastEnd->format('Y-m-d 23:59:59')];
            case 'custom':
                $from = $filters['from'] ?? $today->modify('-29 days')->format('Y-m-d 00:00:00');
                $to = $filters['to'] ?? $end;
                return [$from, $to];
            case 'last_30':
            default:
                return [$today->modify('-29 days')->format('Y-m-d 00:00:00'), $end];
        }
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function context(array $user, array $filters, string $from, string $to): array
    {
        $managerSql = $this->access->managerFilterSql($user, 'c');
        $mid = $this->access->isAdmin($user) && !empty($filters['manager_id']) ? (int) $filters['manager_id'] : null;
        $rawType = isset($filters['type']) ? (string) $filters['type'] : '';
        $type = in_array($rawType, [Catalog::TYPE_PERSON, Catalog::TYPE_COMPANY], true) ? $rawType : null;
        $clientWhere = [$managerSql];
        $params = ['from' => $from, 'to' => $to];
        if ($mid) {
            $clientWhere[] = 'c.manager_id = :mid';
            $params['mid'] = $mid;
        }
        if ($type) {
            $clientWhere[] = 'c.type = :type';
            $params['type'] = $type;
        }
        $cw = implode(' AND ', $clientWhere);
        return [
            'mid' => $mid,
            'type' => $type,
            'client_where' => $cw,
            'join_where' => $this->joinClientFilter($user, $mid, $type),
            'params' => $params,
            'period_params' => $this->periodParams($params, $mid, $type),
        ];
    }

    /** @param list<string> $statuses */
    private function uniqueReached(array $user, array $filters, array $statuses): int
    {
        [$from, $to] = $this->period($filters);
        $ctx = $this->context($user, $filters, $from, $to);
        $in = $this->inList($statuses);
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT sh.client_id)
             FROM status_history sh
             JOIN clients c ON c.id = sh.client_id
             WHERE {$ctx['join_where']} AND sh.to_status IN ({$in})
               AND sh.created_at >= :from AND sh.created_at <= :to",
            $ctx['period_params']
        );
    }

    /** Cohort: created in period, later reached one of statuses (any time after create). */
    /** @param list<string> $statuses */
    private function cohortReached(array $user, array $filters, array $statuses): int
    {
        return $this->cohortReachedAll($user, $filters, [$statuses]);
    }

    /**
     * Cohort clients who later reached every listed status group.
     * @param list<list<string>> $groups
     */
    private function cohortReachedAll(array $user, array $filters, array $groups): int
    {
        [$from, $to] = $this->period($filters);
        $ctx = $this->context($user, $filters, $from, $to);
        $exists = [];
        foreach ($groups as $i => $statuses) {
            $in = $this->inList($statuses);
            $alias = 'sh' . $i;
            $exists[] = "EXISTS (
                SELECT 1 FROM status_history {$alias}
                WHERE {$alias}.client_id = c.id AND {$alias}.to_status IN ({$in}) AND {$alias}.created_at >= c.created_at
            )";
        }
        $extra = $exists ? ' AND ' . implode(' AND ', $exists) : '';
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT c.id)
             FROM clients c
             WHERE {$ctx['client_where']} AND c.created_at >= :from AND c.created_at <= :to {$extra}",
            $ctx['params']
        );
    }

    /** @param array<string,mixed> $pperiod @return array<string,int> */
    private function periodStatusEvents(string $callWhere, array $pperiod): array
    {
        $eventRows = $this->db->fetchAll(
            "SELECT sh.to_status, COUNT(DISTINCT sh.client_id) AS n
             FROM status_history sh
             JOIN clients c ON c.id = sh.client_id
             WHERE {$callWhere} AND sh.created_at >= :from AND sh.created_at <= :to
             GROUP BY sh.to_status",
            $pperiod
        );
        $periodStatusEvents = [];
        foreach (array_keys(Catalog::STATUSES) as $st) {
            $periodStatusEvents[$st] = 0;
        }
        foreach ($eventRows as $row) {
            $periodStatusEvents[(string) $row['to_status']] = (int) $row['n'];
        }
        return $periodStatusEvents;
    }

    /** @return array{value:?float,label:string,numerator:int,denominator:int} */
    private function conversion(int $num, int $den): array
    {
        if ($den <= 0) {
            return [
                'value' => null,
                'label' => 'Недостаточно данных',
                'numerator' => $num,
                'denominator' => $den,
            ];
        }
        $pct = round(100 * $num / $den, 1);
        return [
            'value' => $pct,
            'label' => $pct . '%',
            'numerator' => $num,
            'denominator' => $den,
        ];
    }

    private function grain(array $filters, string $from, string $to): string
    {
        $g = (string) ($filters['grain'] ?? 'auto');
        if (in_array($g, ['day', 'week', 'month'], true)) {
            return $g;
        }
        $start = new DateTimeImmutable($from, $this->tz());
        $end = new DateTimeImmutable($to, $this->tz());
        $days = (int) $start->diff($end)->days + 1;
        if ($days <= 21) {
            return 'day';
        }
        if ($days <= 120) {
            return 'week';
        }
        return 'month';
    }

    private function dayExpr(string $col): string
    {
        return $this->db->isSqlite() ? "strftime('%Y-%m-%d', {$col})" : "DATE({$col})";
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{bucket:string,n:int}>
     */
    private function fillBuckets(string $from, string $to, string $grain, array $counts): array
    {
        $tz = $this->tz();
        $cursor = new DateTimeImmutable($from, $tz);
        $end = new DateTimeImmutable($to, $tz);
        $out = [];
        $guard = 0;
        while ($cursor <= $end && $guard < 800) {
            $guard++;
            if ($grain === 'month') {
                $key = $cursor->format('Y-m');
                $n = 0;
                foreach ($counts as $day => $v) {
                    if (str_starts_with((string) $day, $key)) {
                        $n += $v;
                    }
                }
                $out[] = ['bucket' => $key, 'n' => $n];
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0);
            } elseif ($grain === 'week') {
                $weekEnd = $cursor->add(new DateInterval('P6D'));
                if ($weekEnd > $end) {
                    $weekEnd = $end;
                }
                $n = 0;
                $d = $cursor;
                while ($d <= $weekEnd) {
                    $n += $counts[$d->format('Y-m-d')] ?? 0;
                    $d = $d->add(new DateInterval('P1D'));
                }
                $out[] = ['bucket' => $cursor->format('Y-m-d'), 'n' => $n];
                $cursor = $cursor->add(new DateInterval('P7D'));
            } else {
                $key = $cursor->format('Y-m-d');
                $out[] = ['bucket' => $key, 'n' => $counts[$key] ?? 0];
                $cursor = $cursor->add(new DateInterval('P1D'));
            }
        }
        return $out;
    }

    private function joinClientFilter(array $user, ?int $mid, ?string $type): string
    {
        $parts = [$this->access->managerFilterSql($user, 'c')];
        if ($mid) {
            $parts[] = 'c.manager_id = :mid';
        }
        if ($type) {
            $parts[] = 'c.type = :type';
        }
        return implode(' AND ', $parts);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function periodParams(array $params, ?int $mid, ?string $type): array
    {
        $out = ['from' => $params['from'], 'to' => $params['to']];
        if ($mid) {
            $out['mid'] = $mid;
        }
        if ($type) {
            $out['type'] = $type;
        }
        return $out;
    }

    private function taskFilter(array $user, ?int $mid): string
    {
        if ($mid) {
            return 'assigned_to = ' . $mid;
        }
        return $this->access->isAdmin($user) ? '1=1' : 'assigned_to = ' . (int) $user['id'];
    }

    /** @param array<string,mixed> $params @param list<string> $keys @return array<string,mixed> */
    private function without(array $params, array $keys): array
    {
        foreach ($keys as $k) {
            unset($params[$k]);
        }
        return $params;
    }

    /** @param list<string> $values */
    private function inList(array $values): string
    {
        $clean = [];
        foreach ($values as $v) {
            $clean[] = "'" . str_replace("'", "''", $v) . "'";
        }
        return $clean ? implode(',', $clean) : "''";
    }

    private function funnelLabel(string $key): string
    {
        return match ($key) {
            'new' => 'Новые клиенты',
            'processed' => 'Обработанные',
            'interested' => 'Заинтересованные',
            'offer_sent' => 'КП отправлены',
            'awaiting' => 'Ожидают решения',
            'application' => 'Заявки получены',
            'connected' => 'Подключены',
            default => $key,
        };
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get() ?: 'Asia/Almaty');
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $this->tz());
    }
}
