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
