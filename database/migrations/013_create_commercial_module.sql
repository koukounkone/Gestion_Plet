ALTER TABLE sales
    ADD COLUMN client_name_snapshot VARCHAR(160) NOT NULL DEFAULT '' AFTER client_id,
    ADD COLUMN delivery_status ENUM('pending', 'preparing', 'shipped', 'delivered', 'cancelled')
        NOT NULL DEFAULT 'pending' AFTER status,
    ADD CONSTRAINT chk_sales_delivery_status CHECK (
        (status = 'posted' AND delivery_status <> 'cancelled')
        OR (status = 'void' AND delivery_status = 'cancelled')
    );

UPDATE sales s
LEFT JOIN clients c ON c.farm_id = s.farm_id AND c.id = s.client_id
SET s.client_name_snapshot = COALESCE(c.name, 'Client non renseigné');

ALTER TABLE sale_lines
    MODIFY COLUMN line_total DECIMAL(15,2)
        GENERATED ALWAYS AS (ROUND(quantity * unit_price, 0)) STORED;

ALTER TABLE stock_movements
    DROP INDEX uq_stock_movements_sale_line,
    ADD KEY idx_stock_movements_sale_line (farm_id, sale_line_id);
