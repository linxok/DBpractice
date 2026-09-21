-- Тема: JSON у MySQL 8
-- Виконуйте: docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/json_mysql.sql

USE sandbox;

-- 1. Таблиця з JSON-колонкою
DROP TABLE IF EXISTS events;

CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payload JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO events (payload) VALUES
    ('{"type": "order", "items": 3, "total": 199.50, "tags": ["online", "card"]}'),
    ('{"type": "refund", "items": 1, "total": 49.90, "tags": ["online"]}'),
    ('{"type": "order", "items": 5, "total": 1250.00, "tags": ["store", "cash"]}');

-- 2. Витягування значень
SELECT id, payload->>'$.type' AS type, payload->>'$.total' AS total FROM events ORDER BY id;

-- 3. Фільтрація за полем JSON
SELECT id, payload->>'$.total' AS total
FROM events
WHERE payload->>'$.type' = 'order'
ORDER BY CAST(payload->>'$.total' AS DECIMAL(10,2));

-- 4. Робота з масивами
SELECT id, payload->'$.tags' AS tags, payload->>'$.tags[0]' AS first_tag FROM events ORDER BY id;

-- 5. Оновлення окремого поля без перезапису всього документа
UPDATE events
SET payload = JSON_SET(payload, '$.status', 'new')
WHERE id = 1;

SELECT id, payload->>'$.status' AS status FROM events WHERE id = 1;

-- 6. Функціональний індекс для частих фільтрів
CREATE INDEX idx_events_type ON events ((payload->>'$.type'));

EXPLAIN SELECT id FROM events WHERE payload->>'$.type' = 'order';

-- 7. Агрегація значень із JSON
SELECT payload->>'$.type' AS type, COUNT(*) AS cnt, SUM(payload->>'$.total') AS total
FROM events
GROUP BY type
ORDER BY type;
