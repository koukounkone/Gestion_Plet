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
