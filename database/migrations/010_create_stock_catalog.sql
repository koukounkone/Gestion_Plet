ALTER TABLE products
    ADD COLUMN product_type ENUM('consumable', 'equipment') NOT NULL DEFAULT 'consumable'
        AFTER category,
    ADD COLUMN default_unit_price DECIMAL(14,4) NOT NULL DEFAULT 0.0000
        AFTER unit_code,
    ADD CONSTRAINT chk_products_default_unit_price CHECK (default_unit_price >= 0);

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    address VARCHAR(500) NULL,
    notes TEXT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_farm_id (farm_id, id),
    KEY idx_suppliers_farm_name_status (farm_id, name, status),
    CONSTRAINT fk_suppliers_farm FOREIGN KEY (farm_id)
        REFERENCES farms (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_suppliers_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_lots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_code VARCHAR(100) NULL,
    received_on DATE NOT NULL,
    expires_on DATE NULL,
    received_quantity DECIMAL(14,3) NOT NULL,
    origin ENUM('purchase', 'manual_receipt', 'adjustment') NOT NULL,
    notes VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_lots_farm_id (farm_id, id),
    UNIQUE KEY uq_stock_lots_product_scope (farm_id, id, product_id),
    KEY idx_stock_lots_expiry (farm_id, product_id, expires_on),
    CONSTRAINT chk_stock_lots_received_quantity CHECK (received_quantity > 0),
    CONSTRAINT chk_stock_lots_expiry CHECK (expires_on IS NULL OR expires_on >= received_on),
    CONSTRAINT fk_stock_lots_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stock_lots_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    flock_id BIGINT UNSIGNED NULL,
    purchased_on DATE NOT NULL,
    reference VARCHAR(100) NULL,
    currency_code CHAR(3) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    expense_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchases_farm_id (farm_id, id),
    UNIQUE KEY uq_purchases_expense (farm_id, expense_id),
    KEY idx_purchases_farm_date (farm_id, purchased_on),
    KEY idx_purchases_supplier_date (farm_id, supplier_id, purchased_on),
    CONSTRAINT chk_purchases_total CHECK (total_amount > 0),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (farm_id, supplier_id)
        REFERENCES suppliers (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_flock FOREIGN KEY (farm_id, flock_id)
        REFERENCES flocks (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_expense FOREIGN KEY (farm_id, expense_id)
        REFERENCES expenses (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(14,3) NOT NULL,
    unit_price DECIMAL(14,4) NOT NULL,
    line_total DECIMAL(15,2) NOT NULL,
    expires_on DATE NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_items_farm_id (farm_id, id),
    UNIQUE KEY uq_purchase_items_lot (farm_id, lot_id),
    UNIQUE KEY uq_purchase_items_product_scope (farm_id, id, product_id),
    KEY idx_purchase_items_purchase (farm_id, purchase_id),
    CONSTRAINT chk_purchase_items_values CHECK (
        quantity > 0 AND unit_price > 0 AND line_total > 0
    ),
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (farm_id, purchase_id)
        REFERENCES purchases (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_items_lot FOREIGN KEY (farm_id, lot_id, product_id)
        REFERENCES stock_lots (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_counts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    farm_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    counted_on DATE NOT NULL,
    expected_quantity DECIMAL(14,3) NOT NULL,
    counted_quantity DECIMAL(14,3) NOT NULL,
    variance_quantity DECIMAL(14,3) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_counts_farm_id (farm_id, id),
    UNIQUE KEY uq_inventory_counts_product_scope (farm_id, id, product_id),
    KEY idx_inventory_counts_product_date (farm_id, product_id, counted_on),
    CONSTRAINT chk_inventory_counts_values CHECK (
        expected_quantity >= 0
        AND counted_quantity >= 0
        AND variance_quantity = counted_quantity - expected_quantity
    ),
    CONSTRAINT fk_inventory_counts_product FOREIGN KEY (farm_id, product_id)
        REFERENCES products (farm_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_counts_creator FOREIGN KEY (farm_id, created_by_user_id)
        REFERENCES farm_members (farm_id, user_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_movements
    MODIFY movement_type ENUM(
        'opening', 'receipt', 'return', 'consumption', 'sale', 'loss', 'adjustment',
        'equipment_issue', 'equipment_return'
    ) NOT NULL,
    ADD COLUMN lot_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN purchase_item_id BIGINT UNSIGNED NULL AFTER expense_id,
    ADD COLUMN inventory_count_id BIGINT UNSIGNED NULL AFTER purchase_item_id,
    ADD KEY idx_stock_movements_lot_product (farm_id, lot_id, product_id),
    ADD KEY idx_stock_movements_purchase_item_product (farm_id, purchase_item_id, product_id),
    ADD KEY idx_stock_movements_inventory_product (farm_id, inventory_count_id, product_id);

ALTER TABLE stock_movements
    DROP CHECK chk_stock_movements_sign,
    ADD CONSTRAINT chk_stock_movements_sign CHECK (
        (movement_type IN ('opening', 'receipt', 'return', 'equipment_return') AND quantity_delta > 0)
        OR (movement_type IN ('consumption', 'sale', 'loss', 'equipment_issue') AND quantity_delta < 0)
        OR (movement_type = 'adjustment' AND quantity_delta <> 0)
    ),
    ADD CONSTRAINT chk_stock_movements_purchase_source CHECK (
        purchase_item_id IS NULL OR movement_type = 'receipt'
    ),
    ADD CONSTRAINT chk_stock_movements_inventory_source CHECK (
        inventory_count_id IS NULL OR movement_type = 'adjustment'
    ),
    ADD CONSTRAINT chk_stock_movements_equipment_source CHECK (
        movement_type NOT IN ('equipment_issue', 'equipment_return')
        OR lot_id IS NOT NULL
    ),
    ADD CONSTRAINT fk_stock_movements_lot FOREIGN KEY (farm_id, lot_id, product_id)
        REFERENCES stock_lots (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_stock_movements_purchase_item FOREIGN KEY (farm_id, purchase_item_id, product_id)
        REFERENCES purchase_items (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_stock_movements_inventory FOREIGN KEY (farm_id, inventory_count_id, product_id)
        REFERENCES inventory_counts (farm_id, id, product_id) ON UPDATE RESTRICT ON DELETE RESTRICT;
