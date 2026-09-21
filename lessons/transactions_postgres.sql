-- Тема: транзакції та рівні ізоляції в PostgreSQL
-- Виконуйте: docker exec -i learn-postgres psql -U student -d sandbox < lessons/transactions_postgres.sql
-- Або через SQL Runner (база «Пісочниця PostgreSQL»).

-- Початковий стан товарів
SELECT id, title, stock FROM products ORDER BY id;

-- 1. ROLLBACK скасовує зміни
BEGIN;
UPDATE products SET stock = stock - 5 WHERE id = 1;
SELECT id, title, stock FROM products WHERE id = 1;
ROLLBACK;
SELECT id, title, stock FROM products WHERE id = 1;

-- 2. COMMIT зберігає зміни
BEGIN;
UPDATE products SET stock = stock - 5 WHERE id = 1;
COMMIT;
SELECT id, title, stock FROM products WHERE id = 1;

-- 3. SAVEPOINT
BEGIN;
UPDATE products SET stock = stock - 1 WHERE id = 2;
SAVEPOINT before_big_change;
UPDATE products SET stock = stock - 100 WHERE id = 2;
ROLLBACK TO SAVEPOINT before_big_change;
COMMIT;
SELECT id, title, stock FROM products WHERE id = 2;

-- 4. Рівні ізоляції
SHOW transaction_isolation;
SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
SHOW transaction_isolation;

-- 5. Блокування рядка: виконайте у двох терміналах
-- Термінал A:
--   BEGIN;
--   SELECT * FROM products WHERE id = 3 FOR UPDATE;
-- Термінал B (зачекає):
--   BEGIN;
--   UPDATE products SET stock = stock - 1 WHERE id = 3;
--   COMMIT;
-- Термінал A:
--   COMMIT;

-- 6. Deadlock: кожна транзакція блокує різні рядки й чекає на чужий
-- Термінал A:
--   BEGIN;
--   UPDATE products SET stock = stock - 1 WHERE id = 4;
-- Термінал B:
--   BEGIN;
--   UPDATE products SET stock = stock - 1 WHERE id = 5;
-- Термінал A:
--   UPDATE products SET stock = stock - 1 WHERE id = 5;
-- Термінал B (PostgreSQL розірве цикл і поверне помилку deadlock detected):
--   UPDATE products SET stock = stock - 1 WHERE id = 4;
