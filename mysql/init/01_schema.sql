SET NAMES utf8mb4;

USE learn;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    city VARCHAR(80),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    (1, 1, 1, '2026-01-12 10:15:00'),
    (1, 3, 2, '2026-01-12 10:20:00'),
    (2, 2, 1, '2026-02-03 14:05:00'),
    (3, 5, 2, '2026-02-18 09:40:00'),
    (4, 4, 1, '2026-03-01 18:25:00'),
    (5, 2, 1, '2026-03-14 12:00:00');
