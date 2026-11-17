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
