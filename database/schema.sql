-- Mon Élevage — initial schema
-- Target: MySQL 8.0.16+ or MariaDB 10.6+, InnoDB, utf8mb4.
-- Import into an already selected, empty database. This script does not create users
-- or grant database privileges. Business rows are tenant-scoped by farm_id.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'pending', 'disabled') NOT NULL DEFAULT 'pending',
    profile_photo_data MEDIUMBLOB NULL,
    profile_photo_mime_type VARCHAR(32) NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    terms_version VARCHAR(32) NOT NULL,
    privacy_version VARCHAR(32) NOT NULL,
    authorization_confirmed TINYINT(1) NOT NULL DEFAULT 1,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_consents_user_date (user_id, accepted_at),
    CONSTRAINT chk_user_consents_authorization CHECK (authorization_confirmed = 1),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('google') NOT NULL,
    provider_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_identities_provider_subject (provider, provider_subject),
    UNIQUE KEY uq_oauth_identities_user_provider (user_id, provider),
    CONSTRAINT fk_oauth_identities_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_modules_slug (slug),
    KEY idx_training_modules_status_order (status, position, id),
    CONSTRAINT fk_training_modules_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_training_modules_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_lessons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    objectives TEXT NOT NULL,
    content MEDIUMTEXT NOT NULL,
    validated_advice TEXT NOT NULL,
    checklist TEXT NOT NULL,
    references_text TEXT NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_lessons_module_title (module_id, title),
    KEY idx_training_lessons_module_status (module_id, status, position, id),
    CONSTRAINT fk_training_lessons_module FOREIGN KEY (module_id)
        REFERENCES training_modules (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_training_lessons_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_training_lessons_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_progress (
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_training_progress_lesson (lesson_id, completed_at),
    CONSTRAINT fk_training_progress_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_training_progress_lesson FOREIGN KEY (lesson_id)
        REFERENCES training_lessons (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    country_code CHAR(2) NULL,
    currency_code CHAR(3) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_farms_id (id),
    KEY idx_farms_owner (owner_user_id),
    CONSTRAINT fk_farms_owner FOREIGN KEY (owner_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_members (
    farm_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'manager', 'operator', 'accountant') NOT NULL,
    status ENUM('active', 'invited', 'suspended') NOT NULL DEFAULT 'active',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (farm_id, user_id),
    KEY idx_farm_members_user_status (user_id, status),
    CONSTRAINT fk_farm_members_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_farm_members_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_cycle_settings (
    farm_id BIGINT UNSIGNED NOT NULL,
    poultry_type ENUM('chicken', 'turkey', 'duck', 'quail', 'guinea_fowl', 'goose', 'pigeon', 'other') NOT NULL,
    production_type ENUM('meat', 'eggs', 'breeding', 'other') NOT NULL,
    target_cycle_days SMALLINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (farm_id, poultry_type, production_type),
    KEY idx_farm_cycle_settings_updated_by (farm_id, updated_by_user_id),
    CONSTRAINT chk_farm_cycle_settings_days CHECK (target_cycle_days BETWEEN 1 AND 2000),
    CONSTRAINT fk_farm_cycle_settings_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_farm_cycle_settings_editor FOREIGN KEY (farm_id, updated_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('password_reset', 'email_verification') NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user_purpose_expiry (user_id, purpose, expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_registrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    farm_name VARCHAR(160) NOT NULL,
    terms_version VARCHAR(32) NOT NULL,
    privacy_version VARCHAR(32) NOT NULL,
    authorization_confirmed TINYINT(1) NOT NULL DEFAULT 1,
    consented_at DATETIME NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pending_registrations_email (email),
    KEY idx_pending_registrations_expiry (expires_at),
    CONSTRAINT chk_pending_registrations_authorization CHECK (authorization_confirmed = 1),
    CONSTRAINT chk_pending_registrations_attempts CHECK (code_attempts <= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    identifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (identifier_hash),
    KEY idx_login_attempts_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    flock_type ENUM('broiler', 'layer', 'cockerel', 'free_range', 'breeder', 'quail', 'turkey', 'duck', 'guinea_fowl', 'goose', 'pigeon') NOT NULL DEFAULT 'broiler',
    poultry_type ENUM('chicken', 'turkey', 'duck', 'quail', 'guinea_fowl', 'goose', 'pigeon', 'other') NOT NULL DEFAULT 'chicken',
    production_type ENUM('meat', 'eggs', 'breeding', 'other') NOT NULL DEFAULT 'meat',
    currency_code CHAR(3) NOT NULL DEFAULT 'XOF',
    breed VARCHAR(120) NULL,
    started_on DATE NOT NULL,
    initial_count INT UNSIGNED NOT NULL,
    supplier_name VARCHAR(160) NULL,
    initial_cost DECIMAL(15,2) NULL,
    budget_amount DECIMAL(15,2) NULL,
    location VARCHAR(255) NULL,
    status ENUM('draft', 'active', 'paused', 'closed') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flocks_farm_id (farm_id, id),
    UNIQUE KEY uq_flocks_farm_code (farm_id, code),
    KEY idx_flocks_farm_status_date (farm_id, status, started_on),
    KEY idx_flocks_creator (farm_id, created_by_user_id),
    CONSTRAINT chk_flocks_initial_count CHECK (initial_count > 0),
    CONSTRAINT chk_flocks_initial_cost CHECK (initial_cost IS NULL OR initial_cost >= 0),
    CONSTRAINT chk_flocks_budget_amount CHECK (budget_amount IS NULL OR budget_amount > 0),
    CONSTRAINT fk_flocks_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flocks_creator_member FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_lifecycle_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created', 'activated', 'paused', 'resumed', 'closed', 'reopened') NOT NULL,
    resulting_status ENUM('draft', 'active', 'paused', 'closed') NOT NULL,
    event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closing_count INT UNSIGNED NULL,
    closing_revenue DECIMAL(15,2) NULL,
    closing_expenses DECIMAL(15,2) NULL,
    closing_result DECIMAL(15,2) NULL,
    currency_code CHAR(3) NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_lifecycle_farm_id (farm_id, id),
    KEY idx_flock_lifecycle_history (farm_id, flock_id, event_at),
    CONSTRAINT chk_flock_lifecycle_closure CHECK (
        (event_type = 'closed'
            AND closing_count IS NOT NULL
            AND closing_revenue IS NOT NULL
            AND closing_revenue >= 0
            AND closing_expenses IS NOT NULL
            AND closing_expenses >= 0
            AND closing_result IS NOT NULL
            AND currency_code IS NOT NULL)
        OR
        (event_type <> 'closed'
            AND closing_count IS NULL
            AND closing_revenue IS NULL
            AND closing_expenses IS NULL
            AND closing_result IS NULL
            AND currency_code IS NULL)
    ),
    CONSTRAINT chk_flock_lifecycle_resulting_status CHECK (
        (event_type = 'created' AND resulting_status = 'draft')
        OR (event_type IN ('activated', 'resumed', 'reopened') AND resulting_status = 'active')
        OR (event_type = 'paused' AND resulting_status = 'paused')
        OR (event_type = 'closed' AND resulting_status = 'closed')
    ),
    CONSTRAINT fk_flock_lifecycle_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_lifecycle_actor FOREIGN KEY (farm_id, actor_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_stages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    planned_on DATE NOT NULL,
    completed_on DATE NULL,
    status ENUM('planned', 'in_progress', 'completed') NOT NULL DEFAULT 'planned',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_stages_farm_id (farm_id, id),
    KEY idx_flock_stages_calendar (farm_id, flock_id, planned_on, status),
    KEY idx_flock_stages_priority (farm_id, status, priority),
    CONSTRAINT chk_flock_stages_completion CHECK (
        (status = 'completed' AND completed_on IS NOT NULL)
        OR (status <> 'completed' AND completed_on IS NULL)
    ),
    CONSTRAINT fk_flock_stages_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_stages_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_stage_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    stage_id BIGINT UNSIGNED NOT NULL,
    observation TEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_flock_stage_observations_history (farm_id, stage_id, created_at),
    CONSTRAINT chk_flock_stage_observation_length CHECK (CHAR_LENGTH(observation) BETWEEN 1 AND 4000),
    CONSTRAINT fk_flock_stage_observations_stage FOREIGN KEY (farm_id, stage_id)
        REFERENCES flock_stages (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_stage_observations_author FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_stage_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    stage_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created', 'updated', 'rescheduled', 'started', 'completed', 'reopened') NOT NULL,
    old_planned_on DATE NULL,
    new_planned_on DATE NULL,
    old_status ENUM('planned', 'in_progress', 'completed') NULL,
    new_status ENUM('planned', 'in_progress', 'completed') NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_flock_stage_history (farm_id, stage_id, created_at),
    CONSTRAINT fk_flock_stage_history_stage FOREIGN KEY (farm_id, stage_id)
        REFERENCES flock_stages (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_stage_history_actor FOREIGN KEY (farm_id, actor_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    from_flock_id BIGINT UNSIGNED NOT NULL,
    to_flock_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_transfers_farm_id (farm_id, id),
    KEY idx_flock_transfers_from_date (farm_id, from_flock_id, occurred_at),
    KEY idx_flock_transfers_to_date (farm_id, to_flock_id, occurred_at),
    CONSTRAINT chk_flock_transfer_positive CHECK (quantity > 0),
    CONSTRAINT chk_flock_transfer_distinct_flocks CHECK (from_flock_id <> to_flock_id),
    CONSTRAINT fk_flock_transfers_from FOREIGN KEY (farm_id, from_flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_transfers_to FOREIGN KEY (farm_id, to_flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_transfers_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    address VARCHAR(500) NULL,
    notes TEXT NULL,
    archived_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_farm_id (farm_id, id),
    KEY idx_clients_farm_name (farm_id, name),
    KEY idx_clients_farm_archived (farm_id, archived_at),
    CONSTRAINT fk_clients_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_clients_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    address VARCHAR(500) NULL,
    notes TEXT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_farm_id (farm_id, id),
    KEY idx_suppliers_farm_name_status (farm_id, name, status),
    CONSTRAINT fk_suppliers_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_suppliers_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(60) NOT NULL,
    product_type ENUM('consumable', 'equipment') NOT NULL DEFAULT 'consumable',
    unit_code VARCHAR(20) NOT NULL,
    default_unit_price DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    minimum_stock DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_farm_id (farm_id, id),
    UNIQUE KEY uq_products_farm_sku (farm_id, sku),
    KEY idx_products_farm_category_status (farm_id, category, status),
    KEY idx_products_creator (farm_id, created_by_user_id),
    CONSTRAINT chk_products_minimum_stock CHECK (minimum_stock >= 0),
    CONSTRAINT chk_products_default_unit_price CHECK (default_unit_price >= 0),
    CONSTRAINT fk_products_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_products_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_lots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_code VARCHAR(100) NULL,
    received_on DATE NOT NULL,
    expires_on DATE NULL,
    received_quantity DECIMAL(14,3) NOT NULL,
    origin ENUM('purchase', 'manual_receipt', 'adjustment') NOT NULL,
    notes VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_lots_farm_id (farm_id, id),
    UNIQUE KEY uq_stock_lots_product_scope (farm_id, id, product_id),
    KEY idx_stock_lots_expiry (farm_id, product_id, expires_on),
    CONSTRAINT chk_stock_lots_received_quantity CHECK (received_quantity > 0),
    CONSTRAINT chk_stock_lots_expiry CHECK (expires_on IS NULL OR expires_on >= received_on),
    CONSTRAINT fk_stock_lots_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_lots_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NULL,
    incurred_on DATE NOT NULL,
    category VARCHAR(60) NOT NULL,
    expense_type ENUM('consumable', 'charge', 'investment', 'stock_purchase')
        NOT NULL DEFAULT 'charge',
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    receipt_file_name VARCHAR(255) NULL,
    receipt_storage_key CHAR(64) NULL,
    receipt_mime_type ENUM('application/pdf', 'image/jpeg', 'image/png') NULL,
    status ENUM('posted', 'void') NOT NULL DEFAULT 'posted',
    voided_at DATETIME NULL,
    voided_by_user_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expenses_farm_id (farm_id, id),
    KEY idx_expenses_farm_date_status (farm_id, incurred_on, status),
    KEY idx_expenses_farm_flock_date (farm_id, flock_id, incurred_on),
    KEY idx_expenses_creator (farm_id, created_by_user_id),
    CONSTRAINT chk_expenses_positive CHECK (amount > 0),
    CONSTRAINT chk_expenses_receipt_metadata CHECK (
        (receipt_file_name IS NULL AND receipt_storage_key IS NULL AND receipt_mime_type IS NULL)
        OR
        (receipt_file_name IS NOT NULL AND receipt_storage_key IS NOT NULL AND receipt_mime_type IS NOT NULL)
    ),
    CONSTRAINT chk_expenses_void_metadata CHECK (
        (status = 'posted' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
        OR
        (status = 'void' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL)
    ),
    CONSTRAINT fk_expenses_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_void_actor FOREIGN KEY (farm_id, voided_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_budgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_budgets_farm_id (farm_id, id),
    KEY idx_financial_budgets_period (farm_id, status, starts_on, ends_on),
    KEY idx_financial_budgets_flock_period (farm_id, flock_id, status, starts_on, ends_on),
    CONSTRAINT chk_financial_budgets_dates CHECK (ends_on >= starts_on),
    CONSTRAINT fk_financial_budgets_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_financial_budgets_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_financial_budgets_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_budget_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    budget_id BIGINT UNSIGNED NOT NULL,
    financial_type ENUM('cash', 'consumed', 'investment') NOT NULL,
    category VARCHAR(60) NOT NULL,
    planned_amount DECIMAL(15,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_budget_line (farm_id, budget_id, financial_type, category),
    KEY idx_financial_budget_lines_budget (farm_id, budget_id),
    CONSTRAINT chk_financial_budget_lines_amount CHECK (
        planned_amount > 0 AND planned_amount = ROUND(planned_amount, 0)
    ),
    CONSTRAINT fk_financial_budget_lines_budget FOREIGN KEY (farm_id, budget_id)
        REFERENCES financial_budgets (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NULL,
    purchased_on DATE NOT NULL,
    reference VARCHAR(100) NULL,
    currency_code CHAR(3) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    expense_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchases_farm_id (farm_id, id),
    UNIQUE KEY uq_purchases_expense (farm_id, expense_id),
    KEY idx_purchases_farm_date (farm_id, purchased_on),
    KEY idx_purchases_supplier_date (farm_id, supplier_id, purchased_on),
    CONSTRAINT chk_purchases_total CHECK (total_amount > 0),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (farm_id, supplier_id)
        REFERENCES suppliers (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_expense FOREIGN KEY (farm_id, expense_id)
        REFERENCES expenses (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(14,3) NOT NULL,
    unit_price DECIMAL(14,4) NOT NULL,
    line_total DECIMAL(15,2) NOT NULL,
    expires_on DATE NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_items_farm_id (farm_id, id),
    UNIQUE KEY uq_purchase_items_lot (farm_id, lot_id),
    UNIQUE KEY uq_purchase_items_product_scope (farm_id, id, product_id),
    KEY idx_purchase_items_purchase (farm_id, purchase_id),
    CONSTRAINT chk_purchase_items_values CHECK (
        quantity > 0 AND unit_price > 0 AND line_total > 0
    ),
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (farm_id, purchase_id)
        REFERENCES purchases (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_items_lot FOREIGN KEY (farm_id, lot_id, product_id)
        REFERENCES stock_lots (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_counts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    counted_on DATE NOT NULL,
    expected_quantity DECIMAL(14,3) NOT NULL,
    counted_quantity DECIMAL(14,3) NOT NULL,
    variance_quantity DECIMAL(14,3) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_counts_farm_id (farm_id, id),
    UNIQUE KEY uq_inventory_counts_product_scope (farm_id, id, product_id),
    KEY idx_inventory_counts_product_date (farm_id, product_id, counted_on),
    CONSTRAINT chk_inventory_counts_values CHECK (
        expected_quantity >= 0
        AND counted_quantity >= 0
        AND variance_quantity = counted_quantity - expected_quantity
    ),
    CONSTRAINT fk_inventory_counts_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_counts_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    client_name_snapshot VARCHAR(160) NOT NULL DEFAULT '',
    sold_at DATETIME NOT NULL,
    currency_code CHAR(3) NOT NULL,
    status ENUM('posted', 'void') NOT NULL DEFAULT 'posted',
    delivery_status ENUM('pending', 'preparing', 'shipped', 'delivered', 'cancelled')
        NOT NULL DEFAULT 'pending',
    notes VARCHAR(1000) NULL,
    voided_at DATETIME NULL,
    voided_by_user_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_farm_id (farm_id, id),
    KEY idx_sales_farm_date_status (farm_id, sold_at, status),
    KEY idx_sales_farm_client_date (farm_id, client_id, sold_at),
    KEY idx_sales_creator (farm_id, created_by_user_id),
    CONSTRAINT chk_sales_void_metadata CHECK (
        (status = 'posted' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
        OR
        (status = 'void' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL)
    ),
    CONSTRAINT chk_sales_delivery_status CHECK (
        (status = 'posted' AND delivery_status <> 'cancelled')
        OR (status = 'void' AND delivery_status = 'cancelled')
    ),
    CONSTRAINT fk_sales_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sales_client FOREIGN KEY (farm_id, client_id)
        REFERENCES clients (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sales_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sales_void_actor FOREIGN KEY (farm_id, voided_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('animal', 'product') NOT NULL,
    flock_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(14,3) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (ROUND(quantity * unit_price, 0)) STORED,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_lines_farm_id (farm_id, id),
    KEY idx_sale_lines_sale (farm_id, sale_id),
    KEY idx_sale_lines_flock (farm_id, flock_id),
    KEY idx_sale_lines_product (farm_id, product_id),
    CONSTRAINT chk_sale_lines_amounts CHECK (quantity > 0 AND unit_price >= 0),
    CONSTRAINT chk_sale_lines_source CHECK (
        (source_type = 'animal' AND flock_id IS NOT NULL AND product_id IS NULL AND quantity = TRUNCATE(quantity, 0))
        OR
        (source_type = 'product' AND product_id IS NOT NULL)
    ),
    CONSTRAINT fk_sale_lines_sale FOREIGN KEY (farm_id, sale_id)
        REFERENCES sales (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sale_lines_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sale_lines_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('received', 'refunded') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    paid_at DATETIME NOT NULL,
    method ENUM('cash', 'bank_transfer', 'mobile_money', 'other') NOT NULL,
    reference VARCHAR(120) NULL,
    status ENUM('posted', 'void') NOT NULL DEFAULT 'posted',
    voided_at DATETIME NULL,
    voided_by_user_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_payments_farm_id (farm_id, id),
    KEY idx_sale_payments_sale_date (farm_id, sale_id, paid_at),
    CONSTRAINT chk_sale_payments_positive CHECK (amount > 0),
    CONSTRAINT chk_sale_payments_void_metadata CHECK (
        (status = 'posted' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
        OR
        (status = 'void' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL)
    ),
    CONSTRAINT fk_sale_payments_sale FOREIGN KEY (farm_id, sale_id)
        REFERENCES sales (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sale_payments_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_sale_payments_void_actor FOREIGN KEY (farm_id, voided_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS animal_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('entry', 'mortality', 'loss', 'sale', 'transfer', 'adjustment') NOT NULL,
    quantity_delta INT NOT NULL,
    occurred_at DATETIME NOT NULL,
    transfer_id BIGINT UNSIGNED NULL,
    sale_line_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    daily_followup_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_animal_movements_farm_id (farm_id, id),
    UNIQUE KEY uq_animal_movements_sale_line (farm_id, sale_line_id),
    UNIQUE KEY uq_animal_movements_transfer_flock (farm_id, transfer_id, flock_id),
    UNIQUE KEY uq_animal_movements_reversal (farm_id, reversal_of_id),
    UNIQUE KEY uq_animal_movements_followup_type (farm_id, daily_followup_id, movement_type),
    KEY idx_animal_movements_flock_date (farm_id, flock_id, occurred_at),
    KEY idx_animal_movements_followup (farm_id, daily_followup_id),
    KEY idx_animal_movements_creator (farm_id, created_by_user_id),
    CONSTRAINT chk_animal_movements_nonzero CHECK (quantity_delta <> 0),
    CONSTRAINT chk_animal_movements_sign CHECK (
        (movement_type = 'entry' AND quantity_delta > 0)
        OR (movement_type IN ('mortality', 'loss', 'sale') AND quantity_delta < 0)
        OR movement_type IN ('transfer', 'adjustment')
    ),
    CONSTRAINT chk_animal_movements_sources CHECK (
        (
            movement_type = 'sale'
            AND (
                (sale_line_id IS NOT NULL AND daily_followup_id IS NULL)
                OR (sale_line_id IS NULL AND daily_followup_id IS NOT NULL)
            )
            AND transfer_id IS NULL
        )
        OR
        (
            movement_type = 'transfer'
            AND transfer_id IS NOT NULL
            AND sale_line_id IS NULL
            AND daily_followup_id IS NULL
        )
        OR
        (
            movement_type NOT IN ('sale', 'transfer')
            AND sale_line_id IS NULL
            AND transfer_id IS NULL
            AND (
                daily_followup_id IS NULL
                OR movement_type IN ('entry', 'mortality', 'loss')
            )
        )
    ),
    CONSTRAINT chk_animal_movements_daily_source CHECK (
        daily_followup_id IS NULL
        OR (
            movement_type IN ('entry', 'mortality', 'sale', 'loss')
            AND reversal_of_id IS NULL
        )
    ),
    CONSTRAINT fk_animal_movements_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_animal_movements_transfer FOREIGN KEY (farm_id, transfer_id)
        REFERENCES flock_transfers (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_animal_movements_sale_line FOREIGN KEY (farm_id, sale_line_id)
        REFERENCES sale_lines (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_animal_movements_reversal FOREIGN KEY (farm_id, reversal_of_id)
        REFERENCES animal_movements (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_animal_movements_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    flock_id BIGINT UNSIGNED NULL,
    movement_type ENUM(
        'opening', 'receipt', 'return', 'consumption', 'sale', 'loss', 'adjustment',
        'equipment_issue', 'equipment_return'
    ) NOT NULL,
    quantity_delta DECIMAL(14,3) NOT NULL,
    occurred_at DATETIME NOT NULL,
    expense_id BIGINT UNSIGNED NULL,
    purchase_item_id BIGINT UNSIGNED NULL,
    inventory_count_id BIGINT UNSIGNED NULL,
    sale_line_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_movements_farm_id (farm_id, id),
    KEY idx_stock_movements_sale_line (farm_id, sale_line_id),
    UNIQUE KEY uq_stock_movements_reversal (farm_id, reversal_of_id),
    KEY idx_stock_movements_lot_product (farm_id, lot_id, product_id),
    KEY idx_stock_movements_purchase_item_product (farm_id, purchase_item_id, product_id),
    KEY idx_stock_movements_inventory_product (farm_id, inventory_count_id, product_id),
    KEY idx_stock_movements_product_date (farm_id, product_id, occurred_at),
    KEY idx_stock_movements_flock_date (farm_id, flock_id, occurred_at),
    KEY idx_stock_movements_expense (farm_id, expense_id),
    CONSTRAINT chk_stock_movements_nonzero CHECK (quantity_delta <> 0),
    CONSTRAINT chk_stock_movements_sign CHECK (
        (movement_type IN ('opening', 'receipt', 'return', 'equipment_return') AND quantity_delta > 0)
        OR (movement_type IN ('consumption', 'sale', 'loss', 'equipment_issue') AND quantity_delta < 0)
        OR (movement_type = 'adjustment' AND quantity_delta <> 0)
    ),
    CONSTRAINT chk_stock_movements_sale_source CHECK (
        (movement_type = 'sale' AND sale_line_id IS NOT NULL)
        OR
        (movement_type <> 'sale' AND sale_line_id IS NULL)
    ),
    CONSTRAINT chk_stock_movements_purchase_source CHECK (
        purchase_item_id IS NULL OR movement_type = 'receipt'
    ),
    CONSTRAINT chk_stock_movements_inventory_source CHECK (
        inventory_count_id IS NULL OR movement_type = 'adjustment'
    ),
    CONSTRAINT chk_stock_movements_equipment_source CHECK (
        movement_type NOT IN ('equipment_issue', 'equipment_return')
        OR lot_id IS NOT NULL
    ),
    CONSTRAINT fk_stock_movements_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_lot FOREIGN KEY (farm_id, lot_id, product_id)
        REFERENCES stock_lots (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_expense FOREIGN KEY (farm_id, expense_id)
        REFERENCES expenses (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_purchase_item FOREIGN KEY (farm_id, purchase_item_id, product_id)
        REFERENCES purchase_items (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_inventory FOREIGN KEY (farm_id, inventory_count_id, product_id)
        REFERENCES inventory_counts (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_sale_line FOREIGN KEY (farm_id, sale_line_id)
        REFERENCES sale_lines (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_reversal FOREIGN KEY (farm_id, reversal_of_id)
        REFERENCES stock_movements (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_movements_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_followups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    followup_date DATE NOT NULL,
    observed_count INT UNSIGNED NULL,
    entries_count INT UNSIGNED NOT NULL DEFAULT 0,
    sick_count INT UNSIGNED NOT NULL DEFAULT 0,
    mortality_count INT UNSIGNED NOT NULL DEFAULT 0,
    sales_count INT UNSIGNED NOT NULL DEFAULT 0,
    other_exits_count INT UNSIGNED NOT NULL DEFAULT 0,
    average_weight_g DECIMAL(9,2) NULL,
    feed_consumed_kg DECIMAL(12,3) NULL,
    water_consumed_liters DECIMAL(12,3) NULL,
    temperature_celsius DECIMAL(5,2) NULL,
    humidity_percent DECIMAL(5,2) NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_followups_farm_id (farm_id, id),
    UNIQUE KEY uq_daily_followups_movement_flock (farm_id, id, flock_id),
    UNIQUE KEY uq_daily_followups_flock_date (farm_id, flock_id, followup_date),
    KEY idx_daily_followups_date (farm_id, followup_date),
    CONSTRAINT chk_daily_followups_nonnegative CHECK (
        (average_weight_g IS NULL OR average_weight_g BETWEEN 0 AND 100000)
        AND (feed_consumed_kg IS NULL OR feed_consumed_kg >= 0)
        AND (water_consumed_liters IS NULL OR water_consumed_liters >= 0)
        AND (temperature_celsius IS NULL OR temperature_celsius BETWEEN -50 AND 70)
        AND (humidity_percent IS NULL OR humidity_percent BETWEEN 0 AND 100)
    ),
    CONSTRAINT fk_daily_followups_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_daily_followups_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_weighings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    measured_on DATE NOT NULL,
    sample_size SMALLINT UNSIGNED NOT NULL,
    total_weight_g DECIMAL(14,2) NOT NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_weighings_farm_id (farm_id, id),
    UNIQUE KEY uq_flock_weighings_flock_date (farm_id, flock_id, measured_on),
    KEY idx_flock_weighings_date (farm_id, measured_on),
    CONSTRAINT chk_flock_weighings_sample CHECK (sample_size BETWEEN 1 AND 10000),
    CONSTRAINT chk_flock_weighings_total CHECK (
        total_weight_g > 0 AND total_weight_g / sample_size <= 100000
    ),
    CONSTRAINT fk_flock_weighings_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_weighings_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_egg_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    record_date DATE NOT NULL,
    opening_stock_count INT UNSIGNED NOT NULL DEFAULT 0,
    collected_count INT UNSIGNED NOT NULL DEFAULT 0,
    broken_count INT UNSIGNED NOT NULL DEFAULT 0,
    sold_count INT UNSIGNED NOT NULL DEFAULT 0,
    closing_stock_count INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_egg_records_farm_id (farm_id, id),
    UNIQUE KEY uq_flock_egg_records_flock_date (farm_id, flock_id, record_date),
    KEY idx_flock_egg_records_date (farm_id, record_date),
    CONSTRAINT chk_flock_egg_records_counts CHECK (
        opening_stock_count <= 1000000
        AND collected_count <= 1000000
        AND broken_count <= 1000000
        AND sold_count <= 1000000
        AND closing_stock_count <= 1000000
        AND broken_count <= opening_stock_count + collected_count
        AND closing_stock_count + broken_count + sold_count
            = opening_stock_count + collected_count
    ),
    CONSTRAINT fk_flock_egg_records_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_egg_records_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flock_performance_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    metric ENUM('average_weight_g', 'daily_gain_g', 'laying_rate_percent') NOT NULL,
    target_value DECIMAL(12,3) NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    source_title VARCHAR(255) NOT NULL,
    source_notes TEXT NOT NULL,
    source_url VARCHAR(2048) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flock_performance_targets_farm_id (farm_id, id),
    UNIQUE KEY uq_flock_performance_targets_start (farm_id, flock_id, metric, valid_from),
    KEY idx_flock_performance_targets_period (farm_id, flock_id, valid_from, valid_until),
    CONSTRAINT chk_flock_performance_targets_value CHECK (
        (metric = 'average_weight_g' AND target_value BETWEEN 0.001 AND 100000)
        OR (metric = 'daily_gain_g' AND target_value BETWEEN 0.001 AND 10000)
        OR (metric = 'laying_rate_percent' AND target_value BETWEEN 0.001 AND 100)
    ),
    CONSTRAINT chk_flock_performance_targets_dates CHECK (
        valid_until IS NULL OR valid_until >= valid_from
    ),
    CONSTRAINT fk_flock_performance_targets_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_flock_performance_targets_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE animal_movements
    ADD CONSTRAINT fk_animal_movements_daily_followup
        FOREIGN KEY (farm_id, daily_followup_id, flock_id)
        REFERENCES daily_followups (farm_id, id, flock_id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    resource_type VARCHAR(80) NOT NULL,
    resource_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_farm_date (farm_id, created_at),
    KEY idx_audit_logs_actor_date (actor_user_id, created_at),
    KEY idx_audit_logs_resource (resource_type, resource_id, created_at),
    CONSTRAINT fk_audit_logs_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS health_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('symptom', 'veterinary_visit', 'vaccination', 'prescribed_treatment') NOT NULL,
    observed_on DATE NOT NULL,
    affected_count INT UNSIGNED NULL,
    symptoms TEXT NULL,
    details TEXT NOT NULL,
    professional_name VARCHAR(160) NULL,
    prescription_details TEXT NULL,
    next_due_on DATE NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_health_records_farm_id (farm_id, id),
    KEY idx_health_records_flock_date (farm_id, flock_id, observed_on),
    KEY idx_health_records_due_date (farm_id, event_type, next_due_on),
    CONSTRAINT chk_health_records_affected_count CHECK (
        affected_count IS NULL OR affected_count BETWEEN 1 AND 2147483647
    ),
    CONSTRAINT chk_health_records_due_date CHECK (
        next_due_on IS NULL OR next_due_on >= observed_on
    ),
    CONSTRAINT chk_health_records_professional CHECK (
        event_type <> 'prescribed_treatment'
        OR (professional_name IS NOT NULL AND prescription_details IS NOT NULL)
    ),
    CONSTRAINT fk_health_records_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_health_records_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_weather_settings (
    farm_id BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(9,6) NOT NULL,
    longitude DECIMAL(9,6) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (farm_id),
    CONSTRAINT chk_farm_weather_latitude CHECK (latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_farm_weather_longitude CHECK (longitude BETWEEN -180 AND 180),
    CONSTRAINT fk_farm_weather_settings_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_farm_weather_settings_editor FOREIGN KEY (farm_id, updated_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    fetched_at DATETIME NOT NULL,
    payload JSON NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weather_snapshots_farm_id (farm_id, id),
    KEY idx_weather_snapshots_latest (farm_id, fetched_at),
    CONSTRAINT fk_weather_snapshots_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_weather_snapshots_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    alert_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(500) NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL,
    source VARCHAR(40) NOT NULL,
    occurred_on DATE NOT NULL,
    suggested_action VARCHAR(500) NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_farm_notifications_alert (farm_id, user_id, alert_key),
    KEY idx_farm_notifications_user_unread (farm_id, user_id, read_at, created_at),
    CONSTRAINT fk_farm_notifications_member FOREIGN KEY (farm_id, user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    observation TEXT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL,
    generated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_farm_reports_farm_id (farm_id, id),
    UNIQUE KEY uq_farm_reports_storage_key (storage_key),
    UNIQUE KEY uq_farm_reports_version (
        farm_id, created_by_user_id, flock_id, starts_on, ends_on, version_number
    ),
    KEY idx_farm_reports_history (
        farm_id, created_by_user_id, generated_at, flock_id
    ),
    CONSTRAINT chk_farm_reports_dates CHECK (ends_on >= starts_on),
    CONSTRAINT chk_farm_reports_version CHECK (version_number > 0),
    CONSTRAINT chk_farm_reports_file_size CHECK (file_size_bytes > 0),
    CONSTRAINT fk_farm_reports_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_farm_reports_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_farm_reports_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_role_permissions (
    role_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    permission_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role_name, permission_key),
    CONSTRAINT fk_system_role_permissions_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_member_permission_overrides (
    farm_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    permission_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_allowed TINYINT(1) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (farm_id, user_id, permission_key),
    KEY idx_farm_member_permission_overrides_editor (updated_by_user_id),
    CONSTRAINT chk_farm_member_permission_overrides_allowed CHECK (is_allowed IN (0, 1)),
    CONSTRAINT fk_farm_member_permission_overrides_member
        FOREIGN KEY (farm_id, user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_farm_member_permission_overrides_editor
        FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_reference_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_reference_items_type_code (reference_type, code),
    KEY idx_system_reference_items_type_status (reference_type, status, position, id),
    CONSTRAINT fk_system_reference_items_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_calendar_defaults (
    poultry_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    production_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_cycle_days SMALLINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (poultry_type, production_type),
    CONSTRAINT chk_system_calendar_defaults_days CHECK (target_cycle_days BETWEEN 1 AND 2000),
    CONSTRAINT fk_system_calendar_defaults_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    setting_value VARCHAR(500) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_system_settings_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_alert_thresholds (
    threshold_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    threshold_value DECIMAL(8,2) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (threshold_key),
    CONSTRAINT fk_system_alert_thresholds_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_external_services (
    service_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    endpoint_url VARCHAR(255) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (service_key),
    CONSTRAINT fk_system_external_services_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('application_name', 'Mon Élevage'),
    ('default_currency', 'XOF'),
    ('support_email', '');

INSERT IGNORE INTO system_alert_thresholds (threshold_key, threshold_value) VALUES
    ('indoor_temperature_high', 35),
    ('indoor_temperature_low', 10),
    ('indoor_humidity_high', 80),
    ('indoor_humidity_low', 30),
    ('outdoor_temperature_high', 35),
    ('outdoor_temperature_low', 5);

INSERT IGNORE INTO system_external_services (service_key, is_enabled, endpoint_url) VALUES
    ('weather', 1, 'https://api.open-meteo.com/v1/forecast');

INSERT IGNORE INTO system_reference_items
    (reference_type, code, label, position)
VALUES
    ('flock_type', 'broiler', 'Poulet de chair', 10),
    ('flock_type', 'layer', 'Pondeuse', 20),
    ('flock_type', 'cockerel', 'Coquelet', 30),
    ('flock_type', 'free_range', 'Élevage plein air', 40),
    ('flock_type', 'quail', 'Caille', 60),
    ('flock_type', 'turkey', 'Dinde', 70),
    ('flock_type', 'duck', 'Canard', 80),
    ('flock_type', 'guinea_fowl', 'Pintade', 90),
    ('flock_type', 'goose', 'Oie', 100),
    ('flock_type', 'pigeon', 'Pigeon', 110),
    ('flock_type', 'breeder', 'Reproducteur', 120),
    ('poultry_type', 'chicken', 'Poulet', 10),
    ('poultry_type', 'turkey', 'Dinde', 20),
    ('poultry_type', 'duck', 'Canard', 30),
    ('poultry_type', 'quail', 'Caille', 40),
    ('poultry_type', 'guinea_fowl', 'Pintade', 50),
    ('poultry_type', 'goose', 'Oie', 60),
    ('poultry_type', 'pigeon', 'Pigeon', 70),
    ('poultry_type', 'other', 'Autre volaille', 80),
    ('production_type', 'meat', 'Viande', 10),
    ('production_type', 'eggs', 'Œufs', 20),
    ('production_type', 'breeding', 'Reproduction', 30),
    ('production_type', 'other', 'Autre production', 40),
    ('product_category', 'feed', 'Aliment', 10),
    ('product_category', 'health', 'Santé et hygiène', 20),
    ('product_category', 'equipment', 'Matériel', 30),
    ('product_category', 'packaging', 'Emballage', 40),
    ('expense_category', 'feed', 'Alimentation', 10),
    ('expense_category', 'health', 'Santé et prévention', 20),
    ('expense_category', 'labor', 'Main-d’œuvre', 25),
    ('expense_category', 'utilities', 'Eau et énergie', 27),
    ('expense_category', 'transport', 'Transport', 30),
    ('expense_category', 'maintenance', 'Entretien et réparations', 35),
    ('expense_category', 'equipment', 'Matériel réutilisable', 40),
    ('expense_category', 'other', 'Autres charges', 50);

INSERT IGNORE INTO system_calendar_defaults
    (poultry_type, production_type, target_cycle_days)
VALUES
    ('chicken', 'meat', 42),
    ('chicken', 'eggs', 365),
    ('chicken', 'breeding', 180),
    ('turkey', 'meat', 120),
    ('duck', 'meat', 56),
    ('other', 'other', 90);
