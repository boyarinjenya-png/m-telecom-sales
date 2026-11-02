<?php
declare(strict_types=1);

namespace MTelecom\Sales;

final class Catalog
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';

    public const USER_ACTIVE = 'active';
    public const USER_BLOCKED = 'blocked';

    public const TYPE_PERSON = 'person';
    public const TYPE_COMPANY = 'company';

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_MANAGER = 'manager';

    public const DATA_SOURCE_SYSTEM = 'system_verified';
    public const DATA_SOURCE_MANUAL = 'manual';
    public const DATA_SOURCE_UNVERIFIED = 'unverified';

    public const DATA_SOURCES = [
        'system_verified' => 'Подтверждено системой',
        'manual' => 'Введено вручную',
        'unverified' => 'Не подтверждено системой',
    ];

    public const STATUSES = [
        'new' => 'Новый',
        'unprocessed' => 'Не обработан',
        'in_progress' => 'В работе',
        'interested' => 'Заинтересован',
        'offer_sent' => 'КП отправлено',
        'awaiting_decision' => 'Ожидает решения',
        'meeting_scheduled' => 'Встреча назначена',
        'application_received' => 'Заявка получена',
        'connecting' => 'В процессе подключения',
        'connected' => 'Подключён',
        'rejected' => 'Отказ',
    ];

    public const RESULTS = [
        'no_answer' => 'Не дозвонился',
        'answered' => 'Клиент ответил',
        'short_talk' => 'Короткий разговор',
        'interested' => 'Заинтересован',
        'not_interested' => 'Не заинтересован',
        'offer_sent' => 'Отправлено КП',
        'awaiting_decision' => 'Ожидает решения',
        'awaiting_offer' => 'Ожидает коммерческое предложение',
        'meeting_scheduled' => 'Согласовал встречу',
        'callback' => 'Попросил перезвонить',
        'application_received' => 'Получена заявка',
        'application_ready' => 'Готов оставить заявку',
        'rejected' => 'Отказ',
    ];

    /** Compact result set for the post-call dialog. */
    public const CONTACT_RESULTS = [
        'no_answer' => 'Не дозвонился',
        'answered' => 'Клиент ответил',
        'interested' => 'Заинтересован',
        'not_interested' => 'Не заинтересован',
        'callback' => 'Попросил перезвонить',
        'awaiting_offer' => 'Ожидает коммерческое предложение',
        'meeting_scheduled' => 'Согласовал встречу',
        'application_ready' => 'Готов оставить заявку',
        'rejected' => 'Отказ',
    ];

    public const INTEREST = [
        'unknown' => 'Не указана',
        'interested' => 'Заинтересован',
        'not_interested' => 'Не заинтересован',
        'undecided' => 'Не определился',
    ];

    public const CALL_CATEGORIES = [
        'dial_attempt' => 'Попытка дозвона',
        'short_talk' => 'Короткий разговор',
        'negotiation' => 'Переговоры',
        'extended_negotiation' => 'Развёрнутые переговоры',
        'long_negotiation' => 'Длительные переговоры',
    ];

    public const CALL_CHANNELS = [
        'sim' => 'SIM-звонок',
        'whatsapp' => 'WhatsApp-звонок',
    ];

    public const CALL_DIRECTIONS = [
        'outgoing' => 'Исходящий',
        'incoming' => 'Входящий',
    ];

    public const INTERACTION_TYPES = [
        'call' => 'Звонок',
        'whatsapp' => 'WhatsApp',
        'meeting' => 'Встреча',
        'offer' => 'Коммерческое предложение',
        'status' => 'Смена статуса',
        'task' => 'Задача',
        'note' => 'Комментарий',
        'amendment' => 'Исправление записи',
    ];

    public const WHATSAPP_KINDS = [
        'chat' => 'Открыт чат',
        'call' => 'Звонок WhatsApp',
        'message_out' => 'Исходящее сообщение',
        'message_in' => 'Ответ клиента',
        'offer' => 'Отправка КП',
    ];

    public const TASK_OPEN = 'open';
    public const TASK_DONE = 'done';
    public const TASK_CANCELLED = 'cancelled';

    public const MAX_ACTIVE_MANAGERS = 5;

    /** Statuses a manager cannot set; ADMIN confirms these. */
    public const ADMIN_ONLY_STATUSES = ['connected'];

    /**
     * Allowed manager transitions. «Подключён» is never in this map.
     * @var array<string,list<string>>
     */
    public const MANAGER_TRANSITIONS = [
        'new' => ['unprocessed', 'in_progress', 'interested', 'rejected'],
        'unprocessed' => ['in_progress', 'interested', 'rejected'],
        'in_progress' => ['unprocessed', 'interested', 'offer_sent', 'awaiting_decision', 'meeting_scheduled', 'application_received', 'rejected'],
        'interested' => ['in_progress', 'offer_sent', 'awaiting_decision', 'meeting_scheduled', 'application_received', 'rejected'],
        'offer_sent' => ['interested', 'awaiting_decision', 'meeting_scheduled', 'application_received', 'rejected'],
        'awaiting_decision' => ['interested', 'offer_sent', 'meeting_scheduled', 'application_received', 'rejected'],
        'meeting_scheduled' => ['in_progress', 'awaiting_decision', 'application_received', 'rejected'],
        'application_received' => ['connecting', 'awaiting_decision', 'rejected'],
        'connecting' => ['application_received', 'rejected'],
        'connected' => [],
        'rejected' => ['unprocessed', 'in_progress'],
    ];

    public static function canManagerSetStatus(string $from, string $to): bool
    {
        if (in_array($to, self::ADMIN_ONLY_STATUSES, true)) {
            return false;
        }
        $allowed = self::MANAGER_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    public static function nextWorkday(?string $from = null, string $time = '10:00:00'): string
    {
        $tz = new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Almaty');
        $dt = new \DateTimeImmutable($from ?: 'now', $tz);
        $parts = array_map('intval', explode(':', $time));
        $dt = $dt->modify('+1 day')->setTime($parts[0] ?? 10, $parts[1] ?? 0, $parts[2] ?? 0);
        while ((int) $dt->format('N') >= 6) {
            $dt = $dt->modify('+1 day');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function followUpPlan(string $result): ?array
    {
        return match ($result) {
            'no_answer' => [
                'mode' => 'auto',
                'kind' => 'callback',
                'title' => 'Повторный звонок',
                'due' => 'next_workday',
                'ask_datetime' => true,
            ],
            'callback' => [
                'mode' => 'auto',
                'kind' => 'callback',
                'title' => 'Перезвонить клиенту',
                'due' => 'next_workday',
                'ask_datetime' => true,
            ],
            'interested' => [
                'mode' => 'auto',
                'kind' => 'offer_prepare',
                'title' => 'Подготовить коммерческое предложение',
                'due' => 'next_workday',
                'ask_datetime' => true,
            ],
            'awaiting_offer' => [
                'mode' => 'auto',
                'kind' => 'offer_send',
                'title' => 'Отправить коммерческое предложение',
                'due' => 'next_workday',
                'ask_datetime' => true,
            ],
            'meeting_scheduled' => [
                'mode' => 'auto',
                'kind' => 'meeting',
                'title' => 'Встреча с клиентом',
                'due' => 'next_workday',
                'ask_datetime' => true,
            ],
            'application_ready' => [
                'mode' => 'propose_application',
                'kind' => 'application',
                'title' => 'Создать заявку',
                'propose_application' => true,
            ],
            'rejected' => [
                'mode' => 'none',
                'ask_reason' => true,
            ],
            'not_interested', 'answered' => [
                'mode' => 'none',
            ],
            default => null,
        };
    }

    public static function callCategory(int $talkSeconds, bool $connected): string
    {
        if (!$connected || $talkSeconds <= 20) {
            return 'dial_attempt';
        }
        if ($talkSeconds <= 59) {
            return 'short_talk';
        }
        if ($talkSeconds <= 300) {
            return 'negotiation';
        }
        if ($talkSeconds <= 600) {
            return 'extended_negotiation';
        }
        return 'long_negotiation';
    }

    public static function normalizeDataSource(?string $dataSource, ?string $legacySource = null): string
    {
        $raw = strtolower(trim((string) $dataSource));
        if ($raw === self::DATA_SOURCE_SYSTEM || $raw === self::SOURCE_SYSTEM) {
            return self::DATA_SOURCE_SYSTEM;
        }
        if ($raw === self::DATA_SOURCE_UNVERIFIED) {
            return self::DATA_SOURCE_UNVERIFIED;
        }
        if ($raw === self::DATA_SOURCE_MANUAL || $raw === self::SOURCE_MANAGER) {
            return self::DATA_SOURCE_MANUAL;
        }
        $legacy = strtolower(trim((string) $legacySource));
        if ($legacy === self::SOURCE_SYSTEM || $legacy === self::DATA_SOURCE_SYSTEM) {
            return self::DATA_SOURCE_SYSTEM;
        }
        if ($legacy === self::DATA_SOURCE_UNVERIFIED) {
            return self::DATA_SOURCE_UNVERIFIED;
        }
        if ($legacy === self::SOURCE_MANAGER || $legacy === self::DATA_SOURCE_MANUAL) {
            return self::DATA_SOURCE_MANUAL;
        }
        return self::DATA_SOURCE_UNVERIFIED;
    }

    public static function legacySource(string $dataSource): string
    {
        return $dataSource === self::DATA_SOURCE_SYSTEM ? self::SOURCE_SYSTEM : self::SOURCE_MANAGER;
    }

    public static function isSystemVerified(string $dataSource): bool
    {
        return $dataSource === self::DATA_SOURCE_SYSTEM;
    }

    public static function dataSourceLabel(string $dataSource): string
    {
        return self::DATA_SOURCES[$dataSource] ?? self::DATA_SOURCES[self::DATA_SOURCE_UNVERIFIED];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decorateCall(array $row): array
    {
        $ds = self::normalizeDataSource(
            isset($row['data_source']) ? (string) $row['data_source'] : null,
            isset($row['source']) ? (string) $row['source'] : null
        );
        $row['data_source'] = $ds;
        $row['data_source_label'] = self::dataSourceLabel($ds);
        $row['source'] = self::legacySource($ds);
        $row['source_label'] = $row['data_source_label'];
        $ch = (string) ($row['channel'] ?? 'sim');
        $dir = (string) ($row['direction'] ?? 'outgoing');
        $row['category_label'] = self::CALL_CATEGORIES[$row['category'] ?? ''] ?? ($row['category'] ?? '');
        $row['channel_label'] = self::CALL_CHANNELS[$ch] ?? $ch;
        $row['direction_label'] = self::CALL_DIRECTIONS[$dir] ?? $dir;
        $row['result_label'] = !empty($row['result']) ? (self::RESULTS[$row['result']] ?? $row['result']) : null;
        $talk = (int) ($row['talk_duration_sec'] ?? 0);
        $connected = ((int) ($row['connected'] ?? 0) === 1);
        $verified = self::isSystemVerified($ds);
        $row['is_talk'] = $verified && $connected && $talk > 20;
        $row['duration_is_actual'] = $verified;
        $row['talk_duration_display'] = $verified ? $talk : null;
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decorateWhatsapp(array $row): array
    {
        $ds = self::normalizeDataSource(
            isset($row['data_source']) ? (string) $row['data_source'] : self::DATA_SOURCE_MANUAL,
            isset($row['source']) ? (string) $row['source'] : self::SOURCE_MANAGER
        );
        if ($ds === self::DATA_SOURCE_SYSTEM) {
            $ds = self::DATA_SOURCE_MANUAL;
        }
        $row['data_source'] = $ds;
        $row['data_source_label'] = self::dataSourceLabel($ds);
        $row['source_label'] = $row['data_source_label'];
        $row['kind_label'] = self::WHATSAPP_KINDS[$row['kind'] ?? ''] ?? ($row['kind'] ?? '');
        $row['duration_is_actual'] = false;
        return $row;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    public static function normalizeBin(?string $bin): ?string
    {
        if ($bin === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $bin) ?? '';
        return $digits !== '' ? $digits : null;
    }
}
