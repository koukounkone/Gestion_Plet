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
