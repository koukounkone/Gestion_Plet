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

INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_by_user_id)
SELECT 'default_currency', 'XOF', admins.id FROM (SELECT 1) seed
LEFT JOIN (SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1) admins ON 1 = 1;
INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_by_user_id)
SELECT 'application_name', 'Mon Élevage', admins.id FROM (SELECT 1) seed
LEFT JOIN (SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1) admins ON 1 = 1;
INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_by_user_id)
SELECT 'support_email', '', admins.id FROM (SELECT 1) seed
LEFT JOIN (SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1) admins ON 1 = 1;

INSERT IGNORE INTO system_alert_thresholds (threshold_key, threshold_value, updated_by_user_id)
SELECT thresholds.threshold_key, thresholds.threshold_value, admins.id
FROM (
    SELECT 'indoor_temperature_high' AS threshold_key, 35.00 AS threshold_value
    UNION ALL SELECT 'indoor_temperature_low', 10.00
    UNION ALL SELECT 'indoor_humidity_high', 80.00
    UNION ALL SELECT 'indoor_humidity_low', 30.00
    UNION ALL SELECT 'outdoor_temperature_high', 35.00
    UNION ALL SELECT 'outdoor_temperature_low', 5.00
) AS thresholds
LEFT JOIN (
    SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1
) AS admins ON 1 = 1;

INSERT IGNORE INTO system_external_services
    (service_key, is_enabled, endpoint_url, updated_by_user_id)
SELECT 'weather', 1, 'https://api.open-meteo.com/v1/forecast', admins.id
FROM (SELECT 1) seed
LEFT JOIN (SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1) admins ON 1 = 1;

INSERT IGNORE INTO system_reference_items
    (reference_type, code, label, position, updated_by_user_id)
SELECT items.reference_type, items.code, items.label, items.position, admins.id
FROM (
    SELECT 'flock_type' AS reference_type, 'broiler' AS code, 'Poulet de chair' AS label, 10 AS position
    UNION ALL SELECT 'flock_type', 'layer', 'Pondeuse', 20
    UNION ALL SELECT 'flock_type', 'cockerel', 'Coquelet', 30
    UNION ALL SELECT 'flock_type', 'free_range', 'Élevage plein air', 40
    UNION ALL SELECT 'flock_type', 'breeder', 'Reproducteur', 50
    UNION ALL SELECT 'poultry_type', 'chicken', 'Poulet', 10
    UNION ALL SELECT 'poultry_type', 'turkey', 'Dinde', 20
    UNION ALL SELECT 'poultry_type', 'duck', 'Canard', 30
    UNION ALL SELECT 'poultry_type', 'other', 'Autre volaille', 40
    UNION ALL SELECT 'production_type', 'meat', 'Viande', 10
    UNION ALL SELECT 'production_type', 'eggs', 'Œufs', 20
    UNION ALL SELECT 'production_type', 'breeding', 'Reproduction', 30
    UNION ALL SELECT 'production_type', 'other', 'Autre production', 40
    UNION ALL SELECT 'product_category', 'feed', 'Aliment', 10
    UNION ALL SELECT 'product_category', 'health', 'Santé et hygiène', 20
    UNION ALL SELECT 'product_category', 'equipment', 'Matériel', 30
    UNION ALL SELECT 'product_category', 'packaging', 'Emballage', 40
    UNION ALL SELECT 'expense_category', 'feed', 'Alimentation', 10
    UNION ALL SELECT 'expense_category', 'health', 'Santé et soins', 20
    UNION ALL SELECT 'expense_category', 'labor', 'Main-d’œuvre', 25
    UNION ALL SELECT 'expense_category', 'utilities', 'Eau et énergie', 27
    UNION ALL SELECT 'expense_category', 'transport', 'Transport', 30
    UNION ALL SELECT 'expense_category', 'maintenance', 'Entretien et réparations', 35
    UNION ALL SELECT 'expense_category', 'equipment', 'Matériel réutilisable', 40
    UNION ALL SELECT 'expense_category', 'other', 'Autres charges', 50
) AS items
LEFT JOIN (
    SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1
) AS admins ON 1 = 1;

INSERT IGNORE INTO system_calendar_defaults
    (poultry_type, production_type, target_cycle_days, updated_by_user_id)
SELECT calendar.poultry_type, calendar.production_type, calendar.target_cycle_days, admins.id
FROM (
    SELECT 'chicken' AS poultry_type, 'meat' AS production_type, 42 AS target_cycle_days
    UNION ALL SELECT 'chicken', 'eggs', 365
    UNION ALL SELECT 'chicken', 'breeding', 180
    UNION ALL SELECT 'turkey', 'meat', 120
    UNION ALL SELECT 'duck', 'meat', 56
    UNION ALL SELECT 'other', 'other', 90
) AS calendar
LEFT JOIN (
    SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1
) AS admins ON 1 = 1;
