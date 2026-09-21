DROP SCHEMA IF EXISTS shop_big CASCADE;
CREATE SCHEMA shop_big;
SET search_path TO shop_big;

CREATE TABLE customers (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    city VARCHAR(80) NOT NULL,
    created_at DATE NOT NULL
);

CREATE TABLE products (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(60) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
);

CREATE TABLE orders (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    total NUMERIC(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL,
    ordered_at TIMESTAMP NOT NULL
);

INSERT INTO customers (full_name, email, city, created_at)
SELECT
    'Клієнт ' || n,
    'user' || n || '@example.com',
    (ARRAY['Київ', 'Львів', 'Одеса', 'Харків', 'Дніпро', 'Запоріжжя', 'Вінниця', 'Полтава'])[1 + (n % 8)],
    DATE '2026-09-01' - (n % 1500)
FROM generate_series(1, 10000) AS n;

INSERT INTO products (title, category, price, stock)
SELECT
    'Товар ' || n,
    (ARRAY['Ноутбуки', 'Смартфони', 'Аудіо', 'Периферія', 'Монітори', 'Аксесуари'])[1 + (n % 6)],
    ROUND(100 + ((n * 37) % 50000) / 100.0, 2),
    (n * 17) % 200
FROM generate_series(1, 1000) AS n;

INSERT INTO orders (customer_id, product_id, quantity, total, status, ordered_at)
SELECT
    1 + (n % 10000),
    1 + (n % 1000),
    1 + (n % 5),
    ROUND((1 + (n % 5)) * (100 + ((n * 37) % 50000) / 100.0), 2),
    (ARRAY['new', 'paid', 'shipped', 'done'])[1 + (n % 4)],
    TIMESTAMP '2026-09-01 12:00:00' - ((n % 1095) * INTERVAL '1 day') + ((n % 86400) * INTERVAL '1 second')
FROM generate_series(1, 100000) AS n;

ANALYZE customers;
ANALYZE products;
ANALYZE orders;

GRANT USAGE ON SCHEMA shop_big TO readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA shop_big TO readonly;
