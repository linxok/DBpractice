-- Тема: повнотекстовий пошук у PostgreSQL
-- Виконуйте: docker exec -i learn-postgres psql -U student -d sandbox < lessons/fulltext_postgres.sql

-- 1. Таблиця
DROP TABLE IF EXISTS articles;

CREATE TABLE articles (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL
);

INSERT INTO articles (title, body) VALUES
    ('Індекси в базах даних', 'Індекс прискорює пошук рядків у таблиці та зменшує час виконання запитів.'),
    ('Транзакції та ACID', 'Транзакція гарантує атомарність, узгодженість, ізольованість і довговічність.'),
    ('Планувальник запитів', 'Оптимізатор обирає між послідовним скануванням та використанням індексу.'),
    ('Реплікація даних', 'Репліка приймає зміни від основного сервера та використовується для читання.');

-- 2. Перетворення тексту у вектор і пошук
SELECT id, title,
       to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс') AS match
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс');

-- 3. Ранжування результатів
SELECT id, title,
       ts_rank(to_tsvector('simple', title || ' ' || body), to_tsquery('simple', 'індекс')) AS rank
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс')
ORDER BY rank DESC;

-- 4. Булеві оператори у запиті
SELECT id, title
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'транзакція & !реплікація');

SELECT id, title
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс:*');

-- 5. Пошук фрази
SELECT id, title
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ phraseto_tsquery('simple', 'послідовним скануванням');

-- 6. GIN-індекс для швидкого пошуку
CREATE INDEX idx_articles_fts ON articles USING gin (to_tsvector('simple', title || ' ' || body));

EXPLAIN SELECT id FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс');

-- 7. Пошук із підсвічуванням знайденого фрагмента
SELECT id, title, ts_headline('simple', body, to_tsquery('simple', 'індекс')) AS snippet
FROM articles
WHERE to_tsvector('simple', title || ' ' || body) @@ to_tsquery('simple', 'індекс');
