<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Catalog;
use MTelecom\Sales\Core\Database;

final class DuplicateService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function find(array $input, ?int $excludeId = null): array
    {
        $phones = [];
        foreach (['phone_primary', 'phone_secondary', 'phone'] as $k) {
            $n = Catalog::normalizePhone($input[$k] ?? null);
            if ($n) {
                $phones[$n] = true;
            }
        }
        if (!empty($input['phones']) && is_array($input['phones'])) {
            foreach ($input['phones'] as $p) {
                $raw = is_array($p) ? ($p['phone'] ?? '') : (string) $p;
                $n = Catalog::normalizePhone($raw);
                if ($n) {
                    $phones[$n] = true;
                }
            }
        }
        if (!empty($input['contacts']) && is_array($input['contacts'])) {
            foreach ($input['contacts'] as $c) {
                $n = Catalog::normalizePhone($c['phone'] ?? null);
                if ($n) {
                    $phones[$n] = true;
                }
            }
        }

        $matches = [];
        $seen = [];

        foreach (array_keys($phones) as $norm) {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT c.id, c.uuid, c.type, c.full_name, c.company_name, c.phone_primary, c.bin, c.status, c.manager_id
                 FROM clients c
                 LEFT JOIN client_phones p ON p.client_id = c.id
                 LEFT JOIN client_contacts ct ON ct.client_id = c.id
                 WHERE (c.phone_primary_norm = :n OR c.phone_secondary_norm = :n2 OR p.phone_norm = :n3 OR ct.phone_norm = :n4)'
                 . ($excludeId ? ' AND c.id <> ' . (int) $excludeId : ''),
                ['n' => $norm, 'n2' => $norm, 'n3' => $norm, 'n4' => $norm]
            );
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row['match_reason'] = 'phone';
                $row['match_value'] = $norm;
                $matches[] = $row;
            }
        }

        $bin = Catalog::normalizeBin($input['bin'] ?? null);
        if ($bin) {
            $sql = 'SELECT id, uuid, type, full_name, company_name, phone_primary, bin, status, manager_id
                    FROM clients WHERE bin = :b';
            $params = ['b' => $bin];
            if ($excludeId) {
                $sql .= ' AND id <> :id';
                $params['id'] = $excludeId;
            }
            foreach ($this->db->fetchAll($sql, $params) as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row['match_reason'] = 'bin';
                $row['match_value'] = $bin;
                $matches[] = $row;
            }
        }

        $name = trim((string) ($input['company_name'] ?? ''));
        if ($name !== '' && mb_strlen($name) >= 3) {
            $sql = 'SELECT id, uuid, type, full_name, company_name, phone_primary, bin, status, manager_id
                    FROM clients WHERE type = :t AND company_name IS NOT NULL';
            $params = ['t' => Catalog::TYPE_COMPANY];
            if ($excludeId) {
                $sql .= ' AND id <> :id';
                $params['id'] = $excludeId;
            }
            foreach ($this->db->fetchAll($sql, $params) as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $existing = (string) $row['company_name'];
                similar_text(mb_strtolower($name), mb_strtolower($existing), $pct);
                if ($pct >= 80 || mb_stripos($existing, $name) !== false || mb_stripos($name, $existing) !== false) {
                    $seen[$id] = true;
                    $row['match_reason'] = 'company_name';
                    $row['match_value'] = $existing;
                    $row['similarity'] = round($pct, 1);
                    $matches[] = $row;
                }
            }
        }

        return $matches;
    }
}
