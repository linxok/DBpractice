-- Тема: транзакції в MySQL
-- Виконуйте: docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/transactions_mysql.sql
-- Або через SQL Runner (база «Пісочниця MySQL»).

USE sandbox;

-- Початковий стан товарів
SELECT id, title, stock FROM products ORDER BY id;

-- 1. ROLLBACK скасовує зміни всередині транзакції
START TRANSACTION;
UPDATE products SET stock = stock - 5 WHERE id = 1;
SELECT id, title, stock FROM products WHERE id = 1;
ROLLBACK;
SELECT id, title, stock FROM products WHERE id = 1;

-- 2. COMMIT зберігає зміни
START TRANSACTION;
UPDATE products SET stock = stock - 5 WHERE id = 1;
COMMIT;
SELECT id, title, stock FROM products WHERE id = 1;

-- 3. SAVEPOINT дозволяє відкотити частину транзакції
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 2;
SAVEPOINT before_big_change;
UPDATE products SET stock = stock - 100 WHERE id = 2;
ROLLBACK TO SAVEPOINT before_big_change;
COMMIT;
SELECT id, title, stock FROM products WHERE id = 2;

-- 4. Рівні ізоляції
SELECT @@transaction_isolation;
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
SELECT @@transaction_isolation;
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SELECT @@transaction_isolation;

-- 5. Блокування рядка: виконайте у двох терміналах
-- Термінал A:
--   START TRANSACTION;
--   SELECT * FROM products WHERE id = 3 FOR UPDATE;
-- Термінал B (зачекає, доки A не завершить транзакцію):
--   START TRANSACTION;
--   UPDATE products SET stock = stock - 1 WHERE id = 3;
--   COMMIT;
-- Термінал A:
--   COMMIT;
