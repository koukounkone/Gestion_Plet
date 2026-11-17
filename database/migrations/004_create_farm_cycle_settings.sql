-- Per-farm, editable cycle durations used only for explicitly labelled estimates.
CREATE TABLE IF NOT EXISTS farm_cycle_settings (
    farm_id BIGINT UNSIGNED NOT NULL,
    poultry_type ENUM('chicken', 'turkey', 'duck', 'other') NOT NULL,
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
