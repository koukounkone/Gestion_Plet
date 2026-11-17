ALTER TABLE flocks
    ADD COLUMN flock_type ENUM('broiler', 'layer', 'cockerel', 'free_range', 'breeder')
        NOT NULL DEFAULT 'broiler' AFTER name,
    ADD COLUMN currency_code CHAR(3) NULL AFTER production_type,
    ADD COLUMN supplier_name VARCHAR(160) NULL AFTER initial_count,
    ADD COLUMN initial_cost DECIMAL(15,2) NULL AFTER supplier_name,
    ADD COLUMN budget_amount DECIMAL(15,2) NULL AFTER initial_cost,
    ADD COLUMN location VARCHAR(255) NULL AFTER budget_amount;

UPDATE flocks f
INNER JOIN farms farm ON farm.id = f.farm_id
SET f.currency_code = farm.currency_code,
    f.flock_type = CASE
        WHEN f.production_type = 'eggs' THEN 'layer'
        WHEN f.production_type = 'breeding' THEN 'breeder'
        ELSE 'broiler'
    END;

ALTER TABLE flocks
    MODIFY COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'XOF',
    ADD CONSTRAINT chk_flocks_initial_cost CHECK (initial_cost IS NULL OR initial_cost >= 0),
    ADD CONSTRAINT chk_flocks_budget_amount CHECK (budget_amount IS NULL OR budget_amount > 0);
