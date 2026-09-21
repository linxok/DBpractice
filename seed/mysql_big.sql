SET NAMES utf8mb4;
SET SESSION cte_max_recursion_depth = 200000;

DROP DATABASE IF EXISTS shop_big;
CREATE DATABASE shop_big CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE shop_big;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    city VARCHAR(80) NOT NULL,
    created_at DATE NOT NULL,
    UNIQUE KEY customers_email_key (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(60) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    total DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL,
    ordered_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO customers (full_name, email, city, created_at)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 10000
)
SELECT
    CONCAT('Клієнт ', n),
    CONCAT('user', n, '@example.com'),
    ELT(1 + (n % 8), 'Київ', 'Львів', 'Одеса', 'Харків', 'Дніпро', 'Запоріжжя', 'Вінниця', 'Полтава'),
    DATE_SUB('2026-09-01', INTERVAL (n % 1500) DAY)
FROM seq;

INSERT INTO products (title, category, price, stock)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 1000
)
SELECT
    CONCAT('Товар ', n),
    ELT(1 + (n % 6), 'Ноутбуки', 'Смартфони', 'Аудіо', 'Периферія', 'Монітори', 'Аксесуари'),
    ROUND(100 + ((n * 37) % 50000) / 100, 2),
    (n * 17) % 200
FROM seq;

INSERT INTO orders (customer_id, product_id, quantity, total, status, ordered_at)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 100000
)
SELECT
    1 + (n % 10000),
    1 + (n % 1000),
    1 + (n % 5),
    ROUND((1 + (n % 5)) * (100 + ((n * 37) % 50000) / 100), 2),
    ELT(1 + (n % 4), 'new', 'paid', 'shipped', 'done'),
    DATE_SUB('2026-09-01 12:00:00', INTERVAL (n % 1095) DAY) + INTERVAL (n % 86400) SECOND
FROM seq;

ANALYZE TABLE customers, products, orders;

CREATE USER IF NOT EXISTS 'readonly'@'%' IDENTIFIED BY 'readonly';
GRANT ALL PRIVILEGES ON shop_big.* TO 'student'@'%';
GRANT SELECT ON shop_big.* TO 'readonly'@'%';
FLUSH PRIVILEGES;
