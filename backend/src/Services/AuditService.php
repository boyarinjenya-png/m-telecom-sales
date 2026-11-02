<?php
declare(strict_types=1);

namespace MTelecom\Sales\Services;

use MTelecom\Sales\Core\Database;
use MTelecom\Sales\Core\Request;

final class AuditService
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function log(?int $userId, string $action, ?string $entityType, ?int $entityId, ?Request $request = null, array $payload = []): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => $request?->ip,
            'user_agent' => $request ? substr($request->userAgent, 0, 255) : null,
            'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $this->db->now(),
        ]);
    }
}
