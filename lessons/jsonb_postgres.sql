-- Тема: JSONB у PostgreSQL
-- Виконуйте: docker exec -i learn-postgres psql -U student -d sandbox < lessons/jsonb_postgres.sql

-- 1. Таблиця з JSONB-колонкою
DROP TABLE IF EXISTS events;

CREATE TABLE events (
    id SERIAL PRIMARY KEY,
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO events (payload) VALUES
    ('{"type": "order", "items": 3, "total": 199.50, "tags": ["online", "card"]}'),
    ('{"type": "refund", "items": 1, "total": 49.90, "tags": ["online"]}'),
    ('{"type": "order", "items": 5, "total": 1250.00, "tags": ["store", "cash"]}');

-- 2. Витягування значень: ->> повертає текст, -> повертає JSON
SELECT id, payload->>'type' AS type, payload->>'total' AS total FROM events ORDER BY id;

-- 3. Фільтрація
SELECT id, (payload->>'total')::numeric AS total
FROM events
WHERE payload->>'type' = 'order'
ORDER BY total;

-- 4. Оператор @> перевіряє входження JSON
SELECT id FROM events WHERE payload @> '{"type": "order"}';
SELECT id FROM events WHERE payload->'tags' @> '"online"';

-- 5. Вкладені масиви
SELECT id, payload->'tags' AS tags, payload->'tags'->>0 AS first_tag FROM events ORDER BY id;

-- 6. Оновлення окремого поля
UPDATE events SET payload = payload || '{"status": "new"}' WHERE id = 1;
SELECT id, payload->>'status' AS status FROM events WHERE id = 1;

-- 7. GIN-індекс для пошуку за вмістом
CREATE INDEX idx_events_payload ON events USING gin (payload);

EXPLAIN SELECT id FROM events WHERE payload @> '{"type": "order"}';

-- 8. Агрегація
SELECT payload->>'type' AS type, COUNT(*) AS cnt, SUM((payload->>'total')::numeric) AS total
FROM events
GROUP BY type
ORDER BY type;
