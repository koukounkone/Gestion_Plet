ALTER TABLE daily_followups
    ADD COLUMN entries_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER observed_count,
    ADD COLUMN sick_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER entries_count,
    ADD COLUMN mortality_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER sick_count,
    ADD COLUMN sales_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER mortality_count,
    ADD COLUMN other_exits_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER sales_count,
    ADD COLUMN feed_consumed_kg DECIMAL(12,3) NULL AFTER average_weight_g,
    ADD COLUMN humidity_percent DECIMAL(5,2) NULL AFTER temperature_celsius,
    ADD CONSTRAINT chk_daily_followups_measurements CHECK (
        (average_weight_g IS NULL OR average_weight_g BETWEEN 0 AND 100000)
        AND (feed_consumed_kg IS NULL OR feed_consumed_kg >= 0)
        AND (water_consumed_liters IS NULL OR water_consumed_liters >= 0)
        AND (temperature_celsius IS NULL OR temperature_celsius BETWEEN -50 AND 70)
        AND (humidity_percent IS NULL OR humidity_percent BETWEEN 0 AND 100)
    );

ALTER TABLE daily_followups
    ADD UNIQUE KEY uq_daily_followups_movement_flock (farm_id, id, flock_id);

ALTER TABLE animal_movements
    ADD COLUMN daily_followup_id BIGINT UNSIGNED NULL AFTER reversal_of_id,
    ADD UNIQUE KEY uq_animal_movements_followup_type (
        farm_id, daily_followup_id, movement_type
    ),
    ADD KEY idx_animal_movements_followup (farm_id, daily_followup_id),
    ADD CONSTRAINT fk_animal_movements_daily_followup
        FOREIGN KEY (farm_id, daily_followup_id, flock_id)
        REFERENCES daily_followups (farm_id, id, flock_id) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE animal_movements
    DROP CHECK chk_animal_movements_sources,
    ADD CONSTRAINT chk_animal_movements_sources CHECK (
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
    );

ALTER TABLE animal_movements
    ADD CONSTRAINT chk_animal_movements_daily_source CHECK (
        daily_followup_id IS NULL
        OR (
            movement_type IN ('entry', 'mortality', 'sale', 'loss')
            AND reversal_of_id IS NULL
        )
    );
