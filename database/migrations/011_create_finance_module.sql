ALTER TABLE expenses
    ADD COLUMN expense_type ENUM('consumable', 'charge', 'investment', 'stock_purchase')
        NOT NULL DEFAULT 'charge' AFTER category,
    ADD COLUMN receipt_file_name VARCHAR(255) NULL AFTER currency_code,
    ADD COLUMN receipt_storage_key CHAR(64) NULL AFTER receipt_file_name,
    ADD COLUMN receipt_mime_type ENUM('application/pdf', 'image/jpeg', 'image/png') NULL
        AFTER receipt_storage_key,
    ADD KEY idx_expenses_finance_type_date (farm_id, expense_type, incurred_on, status),
    ADD CONSTRAINT chk_expenses_receipt_metadata CHECK (
        (receipt_file_name IS NULL AND receipt_storage_key IS NULL AND receipt_mime_type IS NULL)
        OR
        (receipt_file_name IS NOT NULL AND receipt_storage_key IS NOT NULL AND receipt_mime_type IS NOT NULL)
    );

UPDATE expenses e
INNER JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
SET e.expense_type = 'stock_purchase',
    e.category = 'stock_purchase';

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
