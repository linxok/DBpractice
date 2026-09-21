SET NAMES utf8mb4;

USE learn;

CREATE TABLE authors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(80)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    published_year SMALLINT NOT NULL,
    pages INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_books_author FOREIGN KEY (author_id) REFERENCES authors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO authors (name, country) VALUES
    ('Тарас Шевченко', 'Україна'),
    ('Леся Українка', 'Україна'),
    ('Іван Франко', 'Україна'),
    ('Джордж Орвелл', 'Велика Британія'),
    ('Ернест Хемінгуей', 'США');

INSERT INTO books (author_id, title, published_year, pages) VALUES
    (1, 'Кобзар', 1840, 320),
    (2, 'Лісова пісня', 1911, 160),
    (3, 'Захар Беркут', 1883, 280),
    (4, '1984', 1949, 328),
    (4, 'Колгосп тварин', 1945, 112),
    (5, 'Старий і море', 1952, 128);
