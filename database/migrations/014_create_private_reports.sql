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
