-- Тема: повнотекстовий пошук у MySQL 8
-- Виконуйте: docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/fulltext_mysql.sql

USE sandbox;

-- 1. Таблиця з FULLTEXT-індексом
DROP TABLE IF EXISTS articles;

CREATE TABLE articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    FULLTEXT KEY ft_articles (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO articles (title, body) VALUES
    ('Індекси в базах даних', 'Індекс прискорює пошук рядків у таблиці та зменшує час виконання запитів.'),
    ('Транзакції та ACID', 'Транзакція гарантує атомарність, узгодженість, ізольованість і довговічність.'),
    ('Планувальник запитів', 'Оптимізатор обирає між послідовним скануванням та використанням індексу.'),
    ('Реплікація даних', 'Репліка приймає зміни від основного сервера та використовується для читання.');

-- 2. Пошук у природній мові: рядки з найбільшою релевантністю мають вищий score
SELECT id, title, MATCH(title, body) AGAINST ('індекс' IN NATURAL LANGUAGE MODE) AS score
FROM articles
WHERE MATCH(title, body) AGAINST ('індекс' IN NATURAL LANGUAGE MODE)
ORDER BY score DESC;

-- 3. Пошук у булевому режимі: обов'язкові та заборонені слова
SELECT id, title
FROM articles
WHERE MATCH(title, body) AGAINST ('+транзакція -реплікація' IN BOOLEAN MODE);

SELECT id, title
FROM articles
WHERE MATCH(title, body) AGAINST ('індекс*' IN BOOLEAN MODE);

-- 4. Пошук фрази
SELECT id, title
FROM articles
WHERE MATCH(title, body) AGAINST ('"послідовним скануванням"' IN BOOLEAN MODE);

-- 5. Комбінація повнотекстового пошуку та фільтра
SELECT id, title
FROM articles
WHERE MATCH(title, body) AGAINST ('даних' IN NATURAL LANGUAGE MODE)
  AND title LIKE '%баз%';

-- 6. Порівняння продуктивності: LIKE проти MATCH
EXPLAIN SELECT id FROM articles WHERE body LIKE '%індекс%';
EXPLAIN SELECT id FROM articles WHERE MATCH(title, body) AGAINST ('індекс' IN NATURAL LANGUAGE MODE);
