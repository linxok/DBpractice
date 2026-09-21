CREATE SCHEMA IF NOT EXISTS shop;
SET search_path TO shop;

CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    city VARCHAR(80),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    price NUMERIC(10,2) NOT NULL CHECK (price >= 0),
    stock INT NOT NULL DEFAULT 0
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL REFERENCES customers(id),
    product_id INT NOT NULL REFERENCES products(id),
    quantity INT NOT NULL DEFAULT 1 CHECK (quantity > 0),
    ordered_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO customers (full_name, email, city) VALUES
    ('Олена Ковальчук', 'olena@example.com', 'Київ'),
    ('Ігор Мельник', 'ihor@example.com', 'Львів'),
    ('Марія Бондаренко', 'maria@example.com', 'Одеса'),
    ('Андрій Шевченко', 'andrii@example.com', 'Харків'),
    ('Софія Ткаченко', 'sofia@example.com', 'Дніпро'),
    ('Петро Гриценко', 'petro@example.com', 'Київ'),
    ('Наталія Романюк', 'natalia@example.com', 'Львів'),
    ('Богдан Кравець', 'bohdan@example.com', 'Київ');

INSERT INTO products (title, price, stock) VALUES
    ('Ноутбук Lenovo IdeaPad', 24999.00, 12),
    ('Смартфон Samsung Galaxy', 18499.50, 25),
    ('Навушники Sony WH-CH720', 4299.00, 40),
    ('Клавіатура Logitech K380', 1799.00, 60),
    ('Монітор Dell 24"', 7499.00, 8);

INSERT INTO orders (customer_id, product_id, quantity, ordered_at) VALUES
    (1, 1, 1, '2026-01-12 10:15:00+02'),
    (1, 3, 2, '2026-01-12 10:20:00+02'),
    (2, 2, 1, '2026-02-03 14:05:00+02'),
    (3, 5, 2, '2026-02-18 09:40:00+02'),
    (4, 4, 1, '2026-03-01 18:25:00+02'),
    (5, 2, 1, '2026-03-14 12:00:00+02');
