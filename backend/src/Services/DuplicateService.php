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
        $type = $this->resolveType($input);
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

    /**
     * Identity duplicates follow the client type. An omitted type is inferred
     * from a BIN so older callers that send only a company number still match.
     * Phone numbers are never an identity key.
     */
    private function resolveType(array $input): string
    {
        $explicit = (string) ($input['type'] ?? '');
        if ($explicit === Catalog::TYPE_PERSON || $explicit === Catalog::TYPE_COMPANY) {
            return $explicit;
        }
        if (Catalog::normalizeBin(isset($input['bin']) ? (string) $input['bin'] : null)) {
            return Catalog::TYPE_COMPANY;
        }
        return Catalog::TYPE_PERSON;
    }
}
