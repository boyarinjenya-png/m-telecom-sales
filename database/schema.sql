-- M-TELECOM SALES
-- Canonical MySQL 5.7+ / MariaDB 10.3+ schema
-- Must stay aligned with backend/src/Core/Migrator.php
-- Charset: utf8mb4
-- Timestamps: application timezone Asia/Almaty

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  login VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  phone VARCHAR(32) DEFAULT NULL,
  role VARCHAR(16) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED DEFAULT NULL,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_name VARCHAR(190) DEFAULT NULL,
  platform VARCHAR(32) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  last_used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_attempts_login (login, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  csrf_token VARCHAR(64) NOT NULL,
  ip VARCHAR(64) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_web_sessions_user (user_id),
  CONSTRAINT fk_web_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  current_provider VARCHAR(190) DEFAULT NULL,
  need TEXT DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  company_name VARCHAR(190) DEFAULT NULL,
  bin VARCHAR(32) DEFAULT NULL,
  legal_address TEXT DEFAULT NULL,
  actual_address TEXT DEFAULT NULL,
  potential_connections INT DEFAULT NULL,
  company_needs TEXT DEFAULT NULL,
  manager_id INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  next_contact_at DATETIME DEFAULT NULL,
  idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  updated_by INT UNSIGNED DEFAULT NULL,
  INDEX idx_clients_type (type),
  INDEX idx_clients_status (status),
  INDEX idx_clients_manager (manager_id),
  INDEX idx_clients_phone (phone_primary_norm),
  INDEX idx_clients_bin (bin),
  CONSTRAINT fk_clients_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_phones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  phone VARCHAR(32) NOT NULL,
  phone_norm VARCHAR(32) NOT NULL,
  label VARCHAR(64) DEFAULT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_cphones_client (client_id),
  INDEX idx_cphones_norm (phone_norm),
  CONSTRAINT fk_cphones_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  position VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(32) DEFAULT NULL,
  phone_norm VARCHAR(32) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_ccontacts_client (client_id),
  CONSTRAINT fk_ccontacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_objects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  client_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  address TEXT DEFAULT NULL,
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_cobjects_client (client_id),
  CONSTRAINT fk_cobjects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  client_id INT UNSIGNED NOT NULL,
  object_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  direction VARCHAR(16) DEFAULT NULL,
  result VARCHAR(32) DEFAULT NULL,
  interest VARCHAR(32) DEFAULT NULL,
  topic TEXT DEFAULT NULL,
  offered TEXT DEFAULT NULL,
  next_action TEXT DEFAULT NULL,
  next_contact_at DATETIME DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manager',
  payload_json TEXT DEFAULT NULL,
  idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
  amended_from_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inter_client (client_id),
  CONSTRAINT fk_inter_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_inter_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inter_object FOREIGN KEY (object_id) REFERENCES client_objects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  interaction_id INT UNSIGNED DEFAULT NULL,
  phone VARCHAR(32) NOT NULL,
  direction VARCHAR(16) NOT NULL DEFAULT 'outgoing',
  started_at DATETIME NOT NULL,
  connected TINYINT(1) NOT NULL DEFAULT 0,
  duration_sec INT NOT NULL DEFAULT 0,
  talk_duration_sec INT NOT NULL DEFAULT 0,
  category VARCHAR(32) NOT NULL,
  result VARCHAR(32) DEFAULT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manager',
  idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  INDEX idx_calls_user (user_id, started_at),
  CONSTRAINT fk_calls_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_calls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  interaction_id INT UNSIGNED DEFAULT NULL,
  phone VARCHAR(32) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  offer_sent TINYINT(1) NOT NULL DEFAULT 0,
  screenshot_file_id INT UNSIGNED DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manager',
  idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_wa_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  client_id INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  details TEXT DEFAULT NULL,
  due_at DATETIME DEFAULT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  completed_at DATETIME DEFAULT NULL,
  idempotency_key VARCHAR(64) DEFAULT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_tasks_assigned (assigned_to, status),
  CONSTRAINT fk_tasks_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tasks_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(32) DEFAULT NULL,
  to_status VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_status_hist_created (created_at),
  CONSTRAINT fk_status_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) DEFAULT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  ip VARCHAR(64) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  payload_json TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime VARCHAR(128) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_uploads_client (client_id),
  CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_uploads_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_conflicts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_uuid CHAR(36) DEFAULT NULL,
  local_payload TEXT NOT NULL,
  server_payload TEXT NOT NULL,
  resolution VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_code INT NOT NULL,
  version_name VARCHAR(32) NOT NULL,
  apk_url VARCHAR(255) DEFAULT NULL,
  changelog TEXT DEFAULT NULL,
  is_stable TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(32) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
