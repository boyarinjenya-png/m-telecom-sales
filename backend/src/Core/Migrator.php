<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

final class Migrator
{
    public const VERSION = '6';

    public function __construct(private Database $db)
    {
    }

    public function up(): void
    {
        $sqlite = $this->db->isSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $dt = $sqlite ? 'TEXT' : 'DATETIME';
        $bool = $sqlite ? 'INTEGER' : 'TINYINT(1)';
        $dec = $sqlite ? 'REAL' : 'DECIMAL(10,7)';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->exec("CREATE TABLE IF NOT EXISTS users (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            login VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            role VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_by {$int} DEFAULT NULL,
            last_login_at {$dt} DEFAULT NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
            id {$pk},
            user_id {$int} NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(190) DEFAULT NULL,
            platform VARCHAR(32) DEFAULT NULL,
            expires_at {$dt} NOT NULL,
            revoked_at {$dt} DEFAULT NULL,
            last_used_at {$dt} DEFAULT NULL,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'user_id', 'users', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id {$pk},
            login VARCHAR(64) NOT NULL,
            ip VARCHAR(64) NOT NULL,
            success {$bool} NOT NULL DEFAULT 0,
            created_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS web_sessions (
            id VARCHAR(128) PRIMARY KEY,
            user_id {$int} NOT NULL,
            csrf_token VARCHAR(64) NOT NULL,
            ip VARCHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            expires_at {$dt} NOT NULL,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'user_id', 'users', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS clients (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            type VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            full_name VARCHAR(190) DEFAULT NULL,
            iin VARCHAR(12) DEFAULT NULL,
            phone_primary VARCHAR(32) DEFAULT NULL,
            phone_primary_norm VARCHAR(32) DEFAULT NULL,
            phone_secondary VARCHAR(32) DEFAULT NULL,
            phone_secondary_norm VARCHAR(32) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            lat {$dec} DEFAULT NULL,
            lng {$dec} DEFAULT NULL,
            current_provider VARCHAR(190) DEFAULT NULL,
            need TEXT DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            company_name VARCHAR(190) DEFAULT NULL,
            bin VARCHAR(32) DEFAULT NULL,
            legal_address TEXT DEFAULT NULL,
            actual_address TEXT DEFAULT NULL,
            potential_connections INTEGER DEFAULT NULL,
            company_needs TEXT DEFAULT NULL,
            manager_id {$int} DEFAULT NULL,
            created_by {$int} DEFAULT NULL,
            next_contact_at {$dt} DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL,
            updated_by {$int} DEFAULT NULL
            " . $this->fkInline($sqlite, 'manager_id', 'users', 'SET NULL') . "
            " . $this->fkInline($sqlite, 'created_by', 'users', 'SET NULL') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS client_phones (
            id {$pk},
            client_id {$int} NOT NULL,
            phone VARCHAR(32) NOT NULL,
            phone_norm VARCHAR(32) NOT NULL,
            label VARCHAR(64) DEFAULT NULL,
            is_primary {$bool} NOT NULL DEFAULT 0,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS client_contacts (
            id {$pk},
            client_id {$int} NOT NULL,
            full_name VARCHAR(190) NOT NULL,
            position VARCHAR(190) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            phone_norm VARCHAR(32) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            is_primary {$bool} NOT NULL DEFAULT 0,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS client_objects (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            client_id {$int} NOT NULL,
            name VARCHAR(190) NOT NULL,
            address TEXT DEFAULT NULL,
            lat {$dec} DEFAULT NULL,
            lng {$dec} DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS interactions (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            client_id {$int} NOT NULL,
            object_id {$int} DEFAULT NULL,
            user_id {$int} NOT NULL,
            type VARCHAR(32) NOT NULL,
            direction VARCHAR(16) DEFAULT NULL,
            result VARCHAR(32) DEFAULT NULL,
            interest VARCHAR(32) DEFAULT NULL,
            topic TEXT DEFAULT NULL,
            offered TEXT DEFAULT NULL,
            next_action TEXT DEFAULT NULL,
            next_contact_at {$dt} DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'manager',
            data_source VARCHAR(32) NOT NULL DEFAULT 'manual',
            payload_json TEXT DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
            amended_from_id {$int} DEFAULT NULL,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
            " . $this->fkInline($sqlite, 'user_id', 'users', 'RESTRICT') . "
            " . $this->fkInline($sqlite, 'object_id', 'client_objects', 'SET NULL') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS calls (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            client_id {$int} NOT NULL,
            user_id {$int} NOT NULL,
            interaction_id {$int} DEFAULT NULL,
            phone VARCHAR(32) NOT NULL,
            direction VARCHAR(16) NOT NULL DEFAULT 'outgoing',
            channel VARCHAR(16) NOT NULL DEFAULT 'sim',
            started_at {$dt} NOT NULL,
            connected {$bool} NOT NULL DEFAULT 0,
            duration_sec INTEGER NOT NULL DEFAULT 0,
            talk_duration_sec INTEGER NOT NULL DEFAULT 0,
            category VARCHAR(32) NOT NULL,
            result VARCHAR(32) DEFAULT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'manager',
            data_source VARCHAR(32) NOT NULL DEFAULT 'unverified',
            idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
            " . $this->fkInline($sqlite, 'user_id', 'users', 'RESTRICT') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS whatsapp_events (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            client_id {$int} NOT NULL,
            user_id {$int} NOT NULL,
            interaction_id {$int} DEFAULT NULL,
            phone VARCHAR(32) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            offer_sent {$bool} NOT NULL DEFAULT 0,
            screenshot_file_id {$int} DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            duration_sec INTEGER NOT NULL DEFAULT 0,
            source VARCHAR(16) NOT NULL DEFAULT 'manager',
            data_source VARCHAR(32) NOT NULL DEFAULT 'manual',
            idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
            " . $this->fkInline($sqlite, 'user_id', 'users', 'RESTRICT') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS tasks (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            client_id {$int} DEFAULT NULL,
            created_by {$int} NOT NULL,
            assigned_to {$int} NOT NULL,
            title VARCHAR(255) NOT NULL,
            details TEXT DEFAULT NULL,
            due_at {$dt} DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'open',
            completed_at {$dt} DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'SET NULL') . "
            " . $this->fkInline($sqlite, 'created_by', 'users', 'RESTRICT') . "
            " . $this->fkInline($sqlite, 'assigned_to', 'users', 'RESTRICT') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS status_history (
            id {$pk},
            client_id {$int} NOT NULL,
            user_id {$int} NOT NULL,
            from_status VARCHAR(32) DEFAULT NULL,
            to_status VARCHAR(32) NOT NULL,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'CASCADE') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id {$pk},
            user_id {$int} DEFAULT NULL,
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) DEFAULT NULL,
            entity_id {$int} DEFAULT NULL,
            ip VARCHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            payload_json TEXT DEFAULT NULL,
            created_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS uploads (
            id {$pk},
            uuid CHAR(36) NOT NULL UNIQUE,
            user_id {$int} NOT NULL,
            client_id {$int} DEFAULT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime VARCHAR(128) NOT NULL,
            size_bytes {$int} NOT NULL,
            created_at {$dt} NOT NULL
            " . $this->fkInline($sqlite, 'user_id', 'users', 'RESTRICT') . "
            " . $this->fkInline($sqlite, 'client_id', 'clients', 'SET NULL') . "
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS sync_conflicts (
            id {$pk},
            user_id {$int} NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_uuid CHAR(36) DEFAULT NULL,
            local_payload TEXT NOT NULL,
            server_payload TEXT NOT NULL,
            resolution VARCHAR(32) NOT NULL DEFAULT 'pending',
            created_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS app_versions (
            id {$pk},
            version_code INTEGER NOT NULL,
            version_name VARCHAR(32) NOT NULL,
            apk_url VARCHAR(255) DEFAULT NULL,
            changelog TEXT DEFAULT NULL,
            is_stable {$bool} NOT NULL DEFAULT 1,
            created_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}");

        $this->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(32) PRIMARY KEY,
            applied_at {$dt} NOT NULL
        ){$engine}");

        $this->ensureColumn('clients', 'iin', $sqlite ? 'TEXT DEFAULT NULL' : 'VARCHAR(12) DEFAULT NULL');
        $this->ensureColumn('uploads', 'client_id', $sqlite ? 'INTEGER DEFAULT NULL' : 'INT UNSIGNED DEFAULT NULL');
        $this->ensureColumn('calls', 'channel', $sqlite ? "TEXT NOT NULL DEFAULT 'sim'" : "VARCHAR(16) NOT NULL DEFAULT 'sim'");
        $this->ensureColumn('calls', 'data_source', $sqlite ? "TEXT NOT NULL DEFAULT 'unverified'" : "VARCHAR(32) NOT NULL DEFAULT 'unverified'");
        $this->ensureColumn('interactions', 'data_source', $sqlite ? "TEXT NOT NULL DEFAULT 'manual'" : "VARCHAR(32) NOT NULL DEFAULT 'manual'");
        $this->ensureColumn('whatsapp_events', 'data_source', $sqlite ? "TEXT NOT NULL DEFAULT 'manual'" : "VARCHAR(32) NOT NULL DEFAULT 'manual'");
        $this->ensureColumn('whatsapp_events', 'duration_sec', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT NOT NULL DEFAULT 0');
        $this->backfillDataSources();
        $this->ensureMysqlForeignKeys();
        $this->ensureIndexes();
        $this->ensureUniqueClientIin();
        $this->seedSettings();
        $this->markVersion();
    }

    private function fkInline(bool $sqlite, string $column, string $table, string $onDelete): string
    {
        if (!$sqlite) {
            return '';
        }
        return ", FOREIGN KEY ({$column}) REFERENCES {$table}(id) ON DELETE {$onDelete}";
    }

    private function ensureMysqlForeignKeys(): void
    {
        if ($this->db->isSqlite()) {
            return;
        }
        $fks = [
            ['fk_tokens_user', 'auth_tokens', 'user_id', 'users', 'CASCADE'],
            ['fk_web_sessions_user', 'web_sessions', 'user_id', 'users', 'CASCADE'],
            ['fk_clients_manager', 'clients', 'manager_id', 'users', 'SET NULL'],
            ['fk_clients_created_by', 'clients', 'created_by', 'users', 'SET NULL'],
            ['fk_cphones_client', 'client_phones', 'client_id', 'clients', 'CASCADE'],
            ['fk_ccontacts_client', 'client_contacts', 'client_id', 'clients', 'CASCADE'],
            ['fk_cobjects_client', 'client_objects', 'client_id', 'clients', 'CASCADE'],
            ['fk_inter_client', 'interactions', 'client_id', 'clients', 'CASCADE'],
            ['fk_inter_user', 'interactions', 'user_id', 'users', 'RESTRICT'],
            ['fk_inter_object', 'interactions', 'object_id', 'client_objects', 'SET NULL'],
            ['fk_calls_client', 'calls', 'client_id', 'clients', 'CASCADE'],
            ['fk_calls_user', 'calls', 'user_id', 'users', 'RESTRICT'],
            ['fk_wa_client', 'whatsapp_events', 'client_id', 'clients', 'CASCADE'],
            ['fk_wa_user', 'whatsapp_events', 'user_id', 'users', 'RESTRICT'],
            ['fk_tasks_client', 'tasks', 'client_id', 'clients', 'SET NULL'],
            ['fk_tasks_created', 'tasks', 'created_by', 'users', 'RESTRICT'],
            ['fk_tasks_assigned', 'tasks', 'assigned_to', 'users', 'RESTRICT'],
            ['fk_status_client', 'status_history', 'client_id', 'clients', 'CASCADE'],
            ['fk_uploads_user', 'uploads', 'user_id', 'users', 'RESTRICT'],
            ['fk_uploads_client', 'uploads', 'client_id', 'clients', 'SET NULL'],
        ];
        foreach ($fks as [$name, $table, $column, $ref, $onDelete]) {
            if ($this->hasForeignKey($name)) {
                continue;
            }
            try {
                $this->exec(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$ref}` (`id`) ON DELETE {$onDelete}"
                );
            } catch (\Throwable $e) {
                // Existing databases may contain orphans; do not abort install.
            }
        }
    }

    private function ensureIndexes(): void
    {
        $indexes = [
            ['idx_users_role', 'users', 'role'],
            ['idx_users_status', 'users', 'status'],
            ['idx_tokens_user', 'auth_tokens', 'user_id'],
            ['idx_web_sessions_user', 'web_sessions', 'user_id'],
            ['idx_attempts_login', 'login_attempts', 'login, ip, created_at'],
            ['idx_clients_type', 'clients', 'type'],
            ['idx_clients_status', 'clients', 'status'],
            ['idx_clients_manager', 'clients', 'manager_id'],
            ['idx_clients_phone', 'clients', 'phone_primary_norm'],
            ['idx_clients_bin', 'clients', 'bin'],
            ['idx_cphones_norm', 'client_phones', 'phone_norm'],
            ['idx_inter_client', 'interactions', 'client_id'],
            ['idx_calls_user', 'calls', 'user_id, started_at'],
            ['idx_calls_client', 'calls', 'client_id, started_at'],
            ['idx_calls_client', 'calls', 'client_id, started_at'],
            ['idx_tasks_assigned', 'tasks', 'assigned_to, status'],
            ['idx_audit_created', 'audit_log', 'created_at'],
            ['idx_status_hist_created', 'status_history', 'created_at'],
            ['idx_status_hist_to', 'status_history', 'to_status, created_at'],
            ['idx_calls_started', 'calls', 'started_at'],
            ['idx_wa_created', 'whatsapp_events', 'created_at'],
            ['idx_clients_next', 'clients', 'next_contact_at'],
            ['idx_inter_created', 'interactions', 'created_at'],
            ['idx_uploads_client', 'uploads', 'client_id'],
        ];
        foreach ($indexes as [$name, $table, $cols]) {
            if ($this->hasIndex($name, $table)) {
                continue;
            }
            $this->exec("CREATE INDEX {$name} ON {$table} ({$cols})");
        }
    }

    /**
     * One IIN belongs to one client. Blank values become NULL so they do not
     * collide. Existing duplicate IINs stop the migration before the index
     * is created; no client rows are deleted.
     */
    private function ensureUniqueClientIin(): void
    {
        $index = 'idx_clients_iin_unique';
        if ($this->hasIndex($index, 'clients')) {
            return;
        }
        $this->exec("UPDATE clients SET iin = NULL WHERE iin IS NOT NULL AND TRIM(iin) = ''");
        $duplicates = $this->db->fetchAll(
            "SELECT iin, COUNT(*) AS cnt FROM clients WHERE iin IS NOT NULL AND iin <> '' GROUP BY iin HAVING COUNT(*) > 1"
        );
        if ($duplicates !== []) {
            $samples = [];
            foreach (array_slice($duplicates, 0, 10) as $row) {
                $samples[] = (string) ($row['iin'] ?? '');
            }
            $listed = implode(', ', $samples);
            throw new \RuntimeException(
                'Миграция остановлена: повторяющийся ИИН у ' . count($duplicates)
                . ' значений (' . $listed . '). Уникальный индекс не создан, данные клиентов не удалялись. '
                . 'Устраните повторы и повторите миграцию.'
            );
        }
        $this->exec("CREATE UNIQUE INDEX {$index} ON clients (iin)");
    }

    private function hasIndex(string $name, string $table): bool
    {
        if ($this->db->isSqlite()) {
            $row = $this->db->fetch(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :n",
                ['n' => $name]
            );
            return $row !== null;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return true;
        }
        $row = $this->db->fetch("SHOW INDEX FROM `{$table}` WHERE Key_name = :n", ['n' => $name]);
        return $row !== null;
    }

    private function hasForeignKey(string $name): bool
    {
        $row = $this->db->fetch(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :n AND CONSTRAINT_TYPE = :t',
            ['n' => $name, 't' => 'FOREIGN KEY']
        );
        return $row !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return true;
        }
        if ($this->db->isSqlite()) {
            $cols = $this->db->fetchAll("PRAGMA table_info({$table})");
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $row = $this->db->fetch(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return $row !== null;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }
        $this->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function backfillDataSources(): void
    {
        $this->exec("UPDATE calls SET data_source = 'system_verified' WHERE source = 'system' AND (data_source IS NULL OR data_source = '' OR data_source = 'unverified')");
        $this->exec("UPDATE calls SET data_source = 'unverified' WHERE source <> 'system' AND (data_source IS NULL OR data_source = '')");
        $this->exec("UPDATE interactions SET data_source = 'system_verified' WHERE source = 'system' AND (data_source IS NULL OR data_source = '' OR data_source = 'manual')");
        $this->exec("UPDATE interactions SET data_source = 'manual' WHERE source <> 'system' AND (data_source IS NULL OR data_source = '')");
        $this->exec("UPDATE whatsapp_events SET data_source = 'manual' WHERE data_source IS NULL OR data_source = ''");
    }

    private function seedSettings(): void
    {
        $now = $this->db->now();
        $exists = $this->db->fetch('SELECT setting_key FROM settings WHERE setting_key = :k', ['k' => 'app_name']);
        if ($exists) {
            return;
        }
        $defaults = [
            'app_name' => 'M-TELECOM SALES',
            'locale' => 'ru',
            'backup_retention_days' => '14',
            'whatsapp_business_ready' => '0',
        ];
        foreach ($defaults as $k => $v) {
            $this->db->insert('settings', [
                'setting_key' => $k,
                'setting_value' => $v,
                'updated_at' => $now,
            ]);
        }
    }

    private function markVersion(): void
    {
        $exists = $this->db->fetch('SELECT version FROM schema_migrations WHERE version = :v', ['v' => self::VERSION]);
        if ($exists) {
            return;
        }
        $this->db->insert('schema_migrations', [
            'version' => self::VERSION,
            'applied_at' => $this->db->now(),
        ]);
    }

    private function exec(string $sql): void
    {
        $this->db->pdo()->exec($sql);
    }
}
