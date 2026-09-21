DROP TABLE IF EXISTS orders CASCADE;
DROP TABLE IF EXISTS products CASCADE;
DROP TABLE IF EXISTS customers CASCADE;

CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    city VARCHAR(80),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL REFERENCES customers(id),
    product_id INT NOT NULL REFERENCES products(id),
    quantity INT NOT NULL DEFAULT 1,
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

INSERT INTO orders (customer_id, product_id, quantity) VALUES
    (1, 1, 1),
    (1, 3, 2),
    (2, 2, 1),
    (3, 5, 2),
    (4, 4, 1),
    (5, 2, 1);
