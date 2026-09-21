-- Тема: тригери в PostgreSQL
-- Виконуйте: docker exec -i learn-postgres psql -U student -d sandbox < lessons/triggers_postgres.sql

-- 1. Таблиця для аудиту
DROP TABLE IF EXISTS orders_audit;

CREATE TABLE orders_audit (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL,
    action VARCHAR(20) NOT NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 2. Функція тригера: фіксує вставку замовлення
CREATE OR REPLACE FUNCTION log_order_insert() RETURNS trigger AS $$
BEGIN
    INSERT INTO orders_audit (order_id, action) VALUES (NEW.id, 'insert');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 3. Тригер AFTER INSERT
DROP TRIGGER IF EXISTS trg_orders_audit ON orders;

CREATE TRIGGER trg_orders_audit
AFTER INSERT ON orders
FOR EACH ROW EXECUTE FUNCTION log_order_insert();

-- 4. Перевірка
INSERT INTO orders (customer_id, product_id, quantity) VALUES (1, 2, 3);
INSERT INTO orders (customer_id, product_id, quantity) VALUES (2, 3, 1);

SELECT id, order_id, action, changed_at FROM orders_audit ORDER BY id;

-- 5. Тригер BEFORE UPDATE: не дозволяє від'ємний залишок
CREATE OR REPLACE FUNCTION check_stock() RETURNS trigger AS $$
BEGIN
    IF NEW.stock < 0 THEN
        RAISE EXCEPTION 'Залишок товару % не може бути від''ємним', NEW.id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_products_stock ON products;

CREATE TRIGGER trg_products_stock
BEFORE UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION check_stock();

-- Цей запит завершиться помилкою — так працює захист:
-- UPDATE products SET stock = -1 WHERE id = 1;

SELECT id, title, stock FROM products ORDER BY id;
