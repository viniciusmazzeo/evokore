CREATE TABLE IF NOT EXISTS clients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  legal_name VARCHAR(180) NULL,
  document_type ENUM('CNPJ', 'CPF', 'OTHER') NOT NULL DEFAULT 'CNPJ',
  document_number VARCHAR(32) NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_name (name),
  KEY idx_clients_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  unit_code VARCHAR(64) NOT NULL,
  unit_name VARCHAR(150) NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_client_code (client_id, unit_code),
  KEY idx_units_client (client_id),
  KEY idx_units_status (status),
  CONSTRAINT fk_units_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_evo_credentials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  unit_id BIGINT UNSIGNED NOT NULL,
  evo_dns VARCHAR(100) NOT NULL,
  token_encrypted TEXT NOT NULL,
  token_hint VARCHAR(24) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_unit_evo_credentials_unit (unit_id),
  KEY idx_unit_evo_credentials_active (unit_id, is_active),
  CONSTRAINT fk_unit_evo_credentials_unit
    FOREIGN KEY (unit_id) REFERENCES units(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NULL,
  unit_id BIGINT UNSIGNED NULL,
  provider ENUM('EVO', 'N8N', 'SYSTEM') NOT NULL DEFAULT 'EVO',
  endpoint VARCHAR(255) NOT NULL,
  method VARCHAR(10) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  request_id VARCHAR(100) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_code VARCHAR(100) NULL,
  error_message VARCHAR(500) NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_integration_logs_client_unit (client_id, unit_id),
  KEY idx_integration_logs_provider (provider),
  KEY idx_integration_logs_success (success),
  KEY idx_integration_logs_created_at (created_at),
  CONSTRAINT fk_integration_logs_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT fk_integration_logs_unit
    FOREIGN KEY (unit_id) REFERENCES units(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS debtor_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('DELINQUENT_FOUND', 'PAYMENT_LINK_SENT', 'REGULARIZED', 'STILL_DELINQUENT') NOT NULL,
  external_member_id BIGINT UNSIGNED NULL,
  cpf_hash CHAR(64) NOT NULL,
  cpf_mask VARCHAR(14) NULL,
  customer_name VARCHAR(180) NULL,
  debt_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  debt_age_days INT UNSIGNED NOT NULL DEFAULT 0,
  checkout_link TEXT NULL,
  reference_date DATE NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_debtor_events_dedupe (unit_id, event_type, cpf_hash, reference_date),
  KEY idx_debtor_events_client_unit (client_id, unit_id),
  KEY idx_debtor_events_event_type (event_type),
  KEY idx_debtor_events_reference_date (reference_date),
  CONSTRAINT fk_debtor_events_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_debtor_events_unit
    FOREIGN KEY (unit_id) REFERENCES units(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  competence_month DATE NOT NULL,
  delinquent_total_count INT UNSIGNED NOT NULL DEFAULT 0,
  delinquent_total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  regularized_count INT UNSIGNED NOT NULL DEFAULT 0,
  regularized_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  still_delinquent_count INT UNSIGNED NOT NULL DEFAULT 0,
  still_delinquent_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_reports_scope (client_id, unit_id, competence_month),
  KEY idx_monthly_reports_competence (competence_month),
  CONSTRAINT fk_monthly_reports_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_monthly_reports_unit
    FOREIGN KEY (unit_id) REFERENCES units(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
