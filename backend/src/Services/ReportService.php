<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\HttpException;

final class ReportService
{
    public function __construct(
        private Database $db,
        private AccessControl $access,
        private AnalyticsService $analytics
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{filename:string,csv:string,rows:list<array<int|string,mixed>>,headers:list<string>}
     */
    public function export(string $type, array $user, array $filters): array
    {
        [$from, $to] = $this->analytics->period($filters);
        $mf = $this->access->managerFilterSql($user, 'c');
        $params = ['from' => $from, 'to' => $to];
        if ($this->access->isAdmin($user) && !empty($filters['manager_id'])) {
            $mf .= ' AND c.manager_id = :mid';
            $params['mid'] = (int) $filters['manager_id'];
        }

        switch ($type) {
            case 'clients':
                $rows = $this->db->fetchAll(
                    "SELECT c.id, c.type, c.status, c.full_name, c.company_name, c.phone_primary, c.bin,
                            c.address, c.current_provider, u.full_name AS manager, c.created_at, c.next_contact_at
                     FROM clients c LEFT JOIN users u ON u.id = c.manager_id
                     WHERE {$mf} ORDER BY c.id",
                    $this->dropPeriod($params)
                );
                $headers = ['ID', 'Тип', 'Статус', 'ФИО', 'Компания', 'Телефон', 'БИН', 'Адрес', 'Провайдер', 'Менеджер', 'Создан', 'След. контакт'];
                $mapped = array_map(fn ($r) => [
                    $r['id'],
                    $r['type'] === Catalog::TYPE_COMPANY ? 'ЮЛ' : 'ФЛ',
                    Catalog::STATUSES[$r['status']] ?? $r['status'],
                    $r['full_name'], $r['company_name'], $r['phone_primary'], $r['bin'],
                    $r['address'], $r['current_provider'], $r['manager'], $r['created_at'], $r['next_contact_at'],
                ], $rows);
                return $this->pack('clients', $headers, $mapped);
            case 'managers':
                $this->access->requireAdmin($user);
                $rows = $this->analytics->managers($user, $filters);
                $headers = ['Менеджер', 'Статус', 'Закреплено', 'В работе', 'Новые', 'Обработано', 'SIM подтв.', 'SIM неподтв.', 'Попытки подтв.', 'Соединения', 'Разговоры подтв.', 'Сек. разговоров подтв.', 'Средняя сек', 'WhatsApp', 'Заинтересованные', 'КП', 'Встречи', 'Заявки', 'Подключения', 'Отказы', 'Просрочено', 'Конверсия новые→подкл.'];
                $mapped = array_map(fn ($r) => [
                    $r['full_name'], $r['status'], $r['assigned_clients'], $r['clients_in_work'], $r['clients_new'],
                    $r['processed_clients'], $r['sim_verified_total'] ?? 0, $r['sim_unverified'] ?? 0,
                    $r['dial_attempts'], $r['sim_verified_connected'] ?? 0, $r['talks'], $r['talk_seconds'], $r['talk_seconds_avg'],
                    $r['whatsapp'], $r['interested'], $r['offers'], $r['meetings'], $r['applications'], $r['connections'],
                    $r['rejected'], $r['overdue'], $r['conversion_new_to_connected']['label'] ?? '',
                ], $rows);
                return $this->pack('managers', $headers, $mapped);
            case 'overview':
            case 'analytics':
                $ov = $this->analytics->overview($user, $filters);
                $headers = ['Показатель', 'Значение', 'Тип'];
                $kind = $ov['metric_kind'] ?? [];
                $mapped = [];
                foreach ([
                    'clients_total' => 'Всего клиентов в базе',
                    'clients_in_work' => 'Клиенты в работе',
                    'clients_new' => 'Новые клиенты за период',
                    'sim_verified_total' => 'Подтверждённые SIM-звонки',
                    'sim_unverified' => 'Неподтверждённые SIM-звонки',
                    'dial_attempts' => 'Попытки дозвона (подтверждённые SIM)',
                    'sim_verified_connected' => 'Состоявшиеся соединения (подтверждённые SIM)',
                    'talks' => 'Состоявшиеся разговоры (подтверждённые SIM)',
                    'talk_seconds' => 'Подтверждённое время разговоров, сек',
                    'talk_seconds_avg' => 'Средняя подтверждённая длительность, сек',
                    'talk_seconds_system' => 'Длительность подтверждённая, сек',
                    'unverified_interactions' => 'Неподтверждённые взаимодействия',
                    'whatsapp_total' => 'WhatsApp-события',
                    'whatsapp_replies' => 'WhatsApp-ответы',
                    'whatsapp_calls' => 'WhatsApp-звонки',
                    'whatsapp_offers' => 'КП через WhatsApp',
                    'interested' => 'Заинтересованные (события)',
                    'offers' => 'КП отправлены (события)',
                    'meetings' => 'Встречи (события)',
                    'applications' => 'Заявки (события)',
                    'connections' => 'Подключения (события)',
                    'rejected' => 'Отказы (события)',
                    'overdue_tasks' => 'Просроченные задачи',
                ] as $k => $label) {
                    $mapped[] = [$label, $ov[$k] ?? 0, $kind[$k] ?? ''];
                }
                return $this->pack('overview', $headers, $mapped);
            case 'sales':
                $sales = $this->analytics->sales($user, $filters);
                $headers = ['Показатель', 'Значение'];
                $mapped = [
                    ['Период с', $sales['period']['from'] ?? ''],
                    ['Период по', $sales['period']['to'] ?? ''],
                    ['Метод', $sales['method'] ?? ''],
                ];
                foreach ($sales['snapshot'] as $k => $v) {
                    $mapped[] = ['Снимок: ' . $k, $v];
                }
                foreach ($sales['conversion'] as $k => $v) {
                    $mapped[] = [
                        'Конверсия: ' . $k,
                        ($v['label'] ?? '') . ' (' . ($v['numerator'] ?? 0) . '/' . ($v['denominator'] ?? 0) . ')',
                    ];
                }
                return $this->pack('sales', $headers, $mapped);
            case 'calls':
            case 'duration':
                $rows = $this->db->fetchAll(
                    "SELECT cl.id, cl.started_at, u.full_name AS manager, c.full_name, c.company_name, cl.phone,
                            cl.connected, cl.talk_duration_sec, cl.category, cl.result, cl.source
                     FROM calls cl
                     JOIN clients c ON c.id = cl.client_id
                     JOIN users u ON u.id = cl.user_id
                     WHERE {$mf} AND cl.started_at >= :from AND cl.started_at <= :to
                     ORDER BY cl.started_at",
                    $params
                );
                $headers = ['ID', 'Дата', 'Менеджер', 'ФИО', 'Компания', 'Телефон', 'Соединение', 'Длительность сек', 'Категория', 'Результат', 'Источник'];
                $mapped = array_map(fn ($r) => [
                    $r['id'], $r['started_at'], $r['manager'], $r['full_name'], $r['company_name'], $r['phone'],
                    ((int) $r['connected']) ? 'да' : 'нет',
                    $r['talk_duration_sec'],
                    Catalog::CALL_CATEGORIES[$r['category']] ?? $r['category'],
                    Catalog::RESULTS[$r['result']] ?? $r['result'],
                    $r['source'] === Catalog::SOURCE_SYSTEM ? 'система' : 'менеджер',
                ], $rows);
                return $this->pack($type, $headers, $mapped);
            case 'whatsapp':
                $rows = $this->db->fetchAll(
                    "SELECT w.id, w.created_at, u.full_name AS manager, c.full_name, c.company_name, w.phone, w.kind, w.offer_sent, w.source
                     FROM whatsapp_events w
                     JOIN clients c ON c.id = w.client_id
                     JOIN users u ON u.id = w.user_id
                     WHERE {$mf} AND w.created_at >= :from AND w.created_at <= :to
                     ORDER BY w.created_at",
                    $params
                );
                $headers = ['ID', 'Дата', 'Менеджер', 'ФИО', 'Компания', 'Телефон', 'Тип', 'КП', 'Источник'];
                $mapped = array_map(fn ($r) => [
                    $r['id'], $r['created_at'], $r['manager'], $r['full_name'], $r['company_name'], $r['phone'],
                    Catalog::WHATSAPP_KINDS[$r['kind']] ?? $r['kind'],
                    ((int) $r['offer_sent']) ? 'да' : 'нет',
                    'менеджер',
                ], $rows);
                return $this->pack('whatsapp', $headers, $mapped);
            case 'applications':
                return $this->statusReport('application_received', 'applications', $user, $mf, $params, $from, $to);
            case 'connections':
                return $this->statusReport('connected', 'connections', $user, $mf, $params, $from, $to);
            case 'rejected':
                return $this->statusReport('rejected', 'rejected', $user, $mf, $params, $from, $to);
            case 'objects':
                $rows = $this->db->fetchAll(
                    "SELECT o.id, c.company_name, c.full_name, o.name, o.address, o.lat, o.lng, u.full_name AS manager
                     FROM client_objects o
                     JOIN clients c ON c.id = o.client_id
                     LEFT JOIN users u ON u.id = c.manager_id
                     WHERE {$mf} ORDER BY c.id, o.id",
                    $this->dropPeriod($params)
                );
                $headers = ['ID', 'Компания', 'ФИО', 'Объект', 'Адрес', 'Широта', 'Долгота', 'Менеджер'];
                $mapped = array_map(fn ($r) => [
                    $r['id'], $r['company_name'], $r['full_name'], $r['name'], $r['address'], $r['lat'], $r['lng'], $r['manager'],
                ], $rows);
                return $this->pack('objects', $headers, $mapped);
            default:
                throw new HttpException('Неизвестный тип отчёта');
        }
    }

    public function toXlsx(array $headers, array $rows, string $sheetName = 'Report'): string
    {
        $xmlRows = $this->xlsxRow($headers, true);
        foreach ($rows as $row) {
            $xmlRows .= $this->xlsxRow(array_values($row), false);
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $xmlRows . '</sheetData></worksheet>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $tmp = sys_get_temp_dir() . '/mts_' . bin2hex(random_bytes(6)) . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bin = file_get_contents($tmp) ?: '';
        @unlink($tmp);
        return $bin;
    }

    /** @param list<string> $headers @param list<array<int|string,mixed>> $rows */
    private function pack(string $name, array $headers, array $rows): array
    {
        $csv = $this->csv($headers, $rows);
        return [
            'filename' => 'mtelecom-sales-' . $name . '-' . date('Ymd-His'),
            'csv' => $csv,
            'rows' => $rows,
            'headers' => $headers,
        ];
    }

    private function statusReport(string $status, string $name, array $user, string $mf, array $params, string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.full_name, c.company_name, c.phone_primary, c.address, u.full_name AS manager, sh.created_at AS event_at
             FROM status_history sh
             JOIN clients c ON c.id = sh.client_id
             LEFT JOIN users u ON u.id = c.manager_id
             WHERE {$mf} AND sh.to_status = :st AND sh.created_at >= :from AND sh.created_at <= :to
             ORDER BY sh.created_at DESC",
            array_merge($params, ['st' => $status])
        );
        $headers = ['ID', 'ФИО', 'Компания', 'Телефон', 'Адрес', 'Менеджер', 'Дата перехода'];
        $mapped = array_map(fn ($r) => [
            $r['id'], $r['full_name'], $r['company_name'], $r['phone_primary'], $r['address'], $r['manager'], $r['event_at'],
        ], $rows);
        return $this->pack($name, $headers, $mapped);
    }

    /** @param list<string> $headers @param list<array<int|string,mixed>> $rows */
    private function csv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fh, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $row), ';');
        }
        rewind($fh);
        $out = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $out;
    }

    /** @param array<int|string,mixed> $cells */
    private function xlsxRow(array $cells, bool $header): string
    {
        $out = '<row>';
        foreach ($cells as $cell) {
            $v = htmlspecialchars((string) $cell, ENT_XML1);
            $out .= '<c t="inlineStr"><is><t>' . $v . '</t></is></c>';
        }
        unset($header);
        return $out . '</row>';
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function dropPeriod(array $params): array
    {
        unset($params['from'], $params['to']);
        return $params;
    }
}
