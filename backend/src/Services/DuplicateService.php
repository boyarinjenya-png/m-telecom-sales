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
        $type = $input['type'] ?? Catalog::TYPE_PERSON;
        $matches = [];

        // Телефон не является уникальным идентификатором: один номер может
        // принадлежать физлицу, компании или нескольким контактам.
        if ($type === Catalog::TYPE_PERSON) {
            $iin = preg_replace('/\\D+/', '', (string) ($input['iin'] ?? ''));
            if ($iin !== '') {
                $sql = 'SELECT id, uuid, type, full_name, company_name, phone_primary, iin, bin, status, manager_id
                        FROM clients WHERE type = :type AND iin = :iin';
                $params = ['type' => Catalog::TYPE_PERSON, 'iin' => $iin];
                if ($excludeId) {
                    $sql .= ' AND id <> :id';
                    $params['id'] = $excludeId;
                }
                foreach ($this->db->fetchAll($sql, $params) as $row) {
                    $row['match_reason'] = 'iin';
                    $row['match_value'] = $iin;
                    $matches[] = $row;
                }
            }
        } elseif ($type === Catalog::TYPE_COMPANY) {
            $bin = Catalog::normalizeBin($input['bin'] ?? null);
            if ($bin) {
                $sql = 'SELECT id, uuid, type, full_name, company_name, phone_primary, iin, bin, status, manager_id
                        FROM clients WHERE type = :type AND bin = :bin';
                $params = ['type' => Catalog::TYPE_COMPANY, 'bin' => $bin];
                if ($excludeId) {
                    $sql .= ' AND id <> :id';
                    $params['id'] = $excludeId;
                }
                foreach ($this->db->fetchAll($sql, $params) as $row) {
                    $row['match_reason'] = 'bin';
                    $row['match_value'] = $bin;
                    $matches[] = $row;
                }
            }
        }

        return $matches;
    }
}
