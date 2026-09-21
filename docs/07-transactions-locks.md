# 07. Транзакції та блокування

## Навіщо ця тема

Транзакції — це механізм, який дозволяє об'єднати кілька змін бази даних в одну неподільну операцію. Без них неможливо коректно реалізувати навіть просте оформлення замовлення: якщо між списанням товару зі складу та створенням рядка замовлення станеться збій, база залишиться в суперечливому стані — товар списано, а замовлення немає.

Друга причина вивчати тему — конкурентність. Коли з базою одночасно працюють кілька клієнтів, вони можуть читати й змінювати одні й ті самі рядки. Рівні ізоляції та блокування визначають, хто кого бачить і хто кого чекає. Розуміння цих механізмів відрізняє робочий код від коду, який «інколи дивно поводиться» під навантаженням.

У цьому розділі всі приклади виконуються на навчальних таблицях `customers`, `products`, `orders` (MySQL, база `learn`) та схемі `shop` PostgreSQL.

Порада: приклади, які завершуються `COMMIT` і змінюють дані, безпечніше виконувати в пісочниці — база `sandbox` (MySQL, MariaDB, PostgreSQL), яку можна скинути на http://localhost:8000/sandbox.php. У прикладах нижче після змін наведено команди повернення даних стенду до початкового стану; якщо стан усе ж зіпсовано, скористайтеся пісочницею або відновіть том з резервної копії (`./manage.sh restore`).

## Підключення та дві сесії

Більшість прикладів вимагають двох одночасних сесій. Відкрийте два термінали.

MySQL 8.4:

```bash
# Термінал A
docker exec -it learn-mysql mysql -ustudent -pstudent learn

# Термінал B
docker exec -it learn-mysql mysql -ustudent -pstudent learn
```

PostgreSQL 17:

```bash
# Термінал A
docker exec -it learn-postgres psql -U student -d learn

# Термінал B
docker exec -it learn-postgres psql -U student -d learn
```

У psql не забудьте вказати схему:

```sql
SET search_path TO shop;
```

MariaDB 11.4 використовує той самий синтаксис транзакцій, що й MySQL (рушій InnoDB):

```bash
docker exec -it learn-mariadb mariadb -ustudent -pstudent learn
```

## Синтаксис керування транзакціями

```sql
-- MySQL 8.4 / MariaDB 11.4
START TRANSACTION;              -- або BEGIN
UPDATE products SET stock = stock - 1 WHERE id = 1;
SAVEPOINT sp1;                  -- проміжна точка відкату
UPDATE products SET stock = stock - 1 WHERE id = 2;
ROLLBACK TO SAVEPOINT sp1;      -- скасувати лише другу зміну
RELEASE SAVEPOINT sp1;          -- точка більше не потрібна
COMMIT;                         -- зафіксувати все
```

```sql
-- PostgreSQL 17
BEGIN;                          -- або START TRANSACTION
UPDATE shop.products SET stock = stock - 1 WHERE id = 1;
SAVEPOINT sp1;
UPDATE shop.products SET stock = stock - 1 WHERE id = 2;
ROLLBACK TO SAVEPOINT sp1;
RELEASE SAVEPOINT sp1;
COMMIT;
```

Порівняння керуючих конструкцій:

| Дія | MySQL 8.4 / MariaDB 11.4 | PostgreSQL 17 |
|---|---|---|
| Почати транзакцію | `START TRANSACTION` / `BEGIN` | `BEGIN` / `START TRANSACTION` |
| Зафіксувати | `COMMIT` | `COMMIT` |
| Скасувати | `ROLLBACK` | `ROLLBACK` |
| Точка збереження | `SAVEPOINT s` | `SAVEPOINT s` |
| Відкат до точки | `ROLLBACK TO SAVEPOINT s` | `ROLLBACK TO SAVEPOINT s` |
| Прибрати точку | `RELEASE SAVEPOINT s` | `RELEASE SAVEPOINT s` |
| Поточний рівень ізоляції | `SELECT @@transaction_isolation;` | `SHOW transaction_isolation;` |
| Змінити рівень | `SET SESSION TRANSACTION ISOLATION LEVEL ...` | `SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ...` |
| Змінити рівень лише для транзакції | `SET TRANSACTION ISOLATION LEVEL ...` до першого запиту | `BEGIN ISOLATION LEVEL ...` або `SET TRANSACTION ...` |
| Автокоміт | `SET autocommit = 0/1` (змінна сервера) | `\set AUTOCOMMIT off` (лише клієнт psql) |
| Блокування рядків | `SELECT ... FOR UPDATE` / `FOR SHARE` | `SELECT ... FOR UPDATE` / `FOR SHARE` / `FOR NO KEY UPDATE` / `FOR KEY SHARE` |
| Не чекати на блокування | `FOR UPDATE NOWAIT` | `FOR UPDATE NOWAIT` |
| Пропустити зайняті рядки | `FOR UPDATE SKIP LOCKED` | `FOR UPDATE SKIP LOCKED` |

Довідка MariaDB: у версії 11.1+ змінна `tx_isolation` перейменована на `transaction_isolation` (стара назва працює як синонім), тому в MariaDB 11.4 запит `SELECT @@transaction_isolation;` коректний.

## Властивості ACID

| Властивість | Що гарантує | Як забезпечується |
|---|---|---|
| Atomicity (атомарність) | Усі операції транзакції виконуються повністю або не виконуються зовсім | `ROLLBACK`, журнал скасування (undo log) |
| Consistency (узгодженість) | Транзакція переводить базу з одного коректного стану в інший; обмеження не порушуються | `PRIMARY KEY`, `FOREIGN KEY`, `CHECK`, `NOT NULL` |
| Isolation (ізольованість) | Паралельні транзакції не бачать проміжних станів одна одної | Рівні ізоляції, блокування, MVCC |
| Durability (довговічність) | Після `COMMIT` дані збережуться навіть при аварійному завершенні | Журнал попереднього запису (redo log / WAL), `fsync` |

## Рівні ізоляції

Стандарт SQL визначає чотири рівні. Аномалії, які вони допускають:

| Рівень | Брудне читання | Неповторюване читання | Фантомне читання |
|---|---|---|---|
| `READ UNCOMMITTED` | можливе | можливе | можливе |
| `READ COMMITTED` | немає | можливе | можливе |
| `REPEATABLE READ` | немає | немає | за стандартом можливе |
| `SERIALIZABLE` | немає | немає | немає |

Практичні особливості рушіїв:

- MySQL 8.4 / MariaDB 11.4 (InnoDB): типово `REPEATABLE READ`; завдяки MVCC і next-key блокуванням фантомне читання для звичайних `SELECT` не спостерігається.
- PostgreSQL 17: типово `READ COMMITTED`; `REPEATABLE READ` будує знімок на всю транзакцію, а `SERIALIZABLE` використовує перевірку конфліктів (SSI) і може завершувати транзакцію помилкою серіалізації, яку клієнт має повторити.

Перегляд і зміна рівня:

```sql
-- MySQL / MariaDB
SELECT @@transaction_isolation;                 -- REPEATABLE-READ
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
```

```sql
-- PostgreSQL
SHOW transaction_isolation;
SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ;
BEGIN ISOLATION LEVEL SERIALIZABLE;
```

## Практичні приклади

### Приклад 1. Атомарне оформлення замовлення (MySQL)

Списання товару зі складу та створення замовлення мають відбутися разом.

```sql
START TRANSACTION;

UPDATE products
SET stock = stock - 1
WHERE id = 1 AND stock > 0;

INSERT INTO orders (customer_id, product_id, quantity)
VALUES (1, 1, 1);

COMMIT;

SELECT stock FROM products WHERE id = 1;
SELECT * FROM orders ORDER BY id DESC LIMIT 1;
```

Умова `stock > 0` не дозволяє продати відсутній товар. У MySQL перевірити, чи справді змінився рядок, можна через `ROW_COUNT()`:

```sql
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 1 AND stock > 0;
SELECT ROW_COUNT() AS updated_rows;

-- якщо updated_rows = 0, товар закінчився:
-- ROLLBACK;
COMMIT;
```

У PostgreSQL аналогічна перевірка робиться через `RETURNING` і CTE:

```sql
BEGIN;

WITH updated AS (
    UPDATE shop.products
    SET stock = stock - 1
    WHERE id = 1 AND stock > 0
    RETURNING id
)
SELECT count(*) AS updated_rows FROM updated;

-- якщо updated_rows = 0 — виконайте ROLLBACK;
COMMIT;
```

### Приклад 2. Скасування транзакції через ROLLBACK

```sql
SELECT COUNT(*) AS before_delete FROM orders WHERE customer_id = 4;

START TRANSACTION;
DELETE FROM orders WHERE customer_id = 4;
SELECT COUNT(*) AS inside_transaction FROM orders WHERE customer_id = 4;   -- 0
ROLLBACK;

SELECT COUNT(*) AS after_rollback FROM orders WHERE customer_id = 4;       -- 1
```

`ROLLBACK` скасовує всі зміни транзакції, навіть якщо вони вже виконувалися на диску всередині незавершеної транзакції.

### Приклад 3. SAVEPOINT: частковий відкат

```sql
START TRANSACTION;

INSERT INTO customers (full_name, email, city)
VALUES ('Тестовий Клієнт', 'test.tx@example.com', 'Суми');
SAVEPOINT after_customer;

UPDATE products SET stock = stock - 100 WHERE id = 3;   -- хибна дія
ROLLBACK TO SAVEPOINT after_customer;                   -- скасовуємо лише її

UPDATE products SET stock = stock - 2 WHERE id = 3;     -- правильна дія
RELEASE SAVEPOINT after_customer;

COMMIT;

-- Повернення стенду до початкового стану:
DELETE FROM customers WHERE email = 'test.tx@example.com';
UPDATE products SET stock = stock + 2 WHERE id = 3;
```

`ROLLBACK TO SAVEPOINT` не завершує транзакцію: після нього можна продовжувати роботу й зафіксувати решту змін.

### Приклад 4. Той самий сценарій у PostgreSQL

```sql
SET search_path TO shop;

BEGIN;

INSERT INTO customers (full_name, email, city)
VALUES ('Тестовий Клієнт', 'test.tx@example.com', 'Суми');
SAVEPOINT after_customer;

UPDATE products SET stock = stock - 100 WHERE id = 3;
ROLLBACK TO SAVEPOINT after_customer;

UPDATE products SET stock = stock - 2 WHERE id = 3;
RELEASE SAVEPOINT after_customer;

COMMIT;

-- Прибирання:
DELETE FROM customers WHERE email = 'test.tx@example.com';
UPDATE products SET stock = stock + 2 WHERE id = 3;
```

Синтаксис ідентичний, відрізняється лише кваліфікація таблиць схемою `shop`.

### Приклад 5. Автокоміт і неявні коміти

У MySQL кожна окрема команда без `START TRANSACTION` виконується як власна транзакція:

```sql
SELECT @@autocommit;    -- 1

SET autocommit = 0;
UPDATE products SET stock = stock - 1 WHERE id = 4;
SELECT stock FROM products WHERE id = 4;    -- 59
ROLLBACK;                                   -- скасовує зміну
SELECT stock FROM products WHERE id = 4;    -- 60
SET autocommit = 1;
```

DDL у MySQL викликає неявний `COMMIT` до і після команди:

```sql
START TRANSACTION;
UPDATE products SET stock = 59 WHERE id = 4;

CREATE TABLE tmp_demo (id INT PRIMARY KEY);   -- неявний COMMIT!

ROLLBACK;                                     -- уже нічого не скасовує
SELECT stock FROM products WHERE id = 4;      -- 59

DROP TABLE tmp_demo;
UPDATE products SET stock = 60 WHERE id = 4;
```

У PostgreSQL DDL транзакційний, тому той самий код поводиться інакше:

```sql
BEGIN;
UPDATE shop.products SET stock = 59 WHERE id = 4;
CREATE TABLE shop.tmp_demo (id int PRIMARY KEY);
ROLLBACK;
SELECT stock FROM shop.products WHERE id = 4;   -- 60
```

### Приклад 6. Неповторюване читання: READ COMMITTED проти REPEATABLE READ

Сесія A (MySQL):

```sql
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
START TRANSACTION;
SELECT stock FROM products WHERE id = 5;    -- 8
```

Сесія B:

```sql
UPDATE products SET stock = 7 WHERE id = 5;
```

Сесія A:

```sql
SELECT stock FROM products WHERE id = 5;    -- READ COMMITTED: 7
COMMIT;
```

Тепер те саме на рівні `REPEATABLE READ`:

```sql
-- Сесія A
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION;
SELECT stock FROM products WHERE id = 5;    -- 7
```

```sql
-- Сесія B
UPDATE products SET stock = 6 WHERE id = 5;
```

```sql
-- Сесія A
SELECT stock FROM products WHERE id = 5;    -- 7, знімок транзакції
COMMIT;
SELECT stock FROM products WHERE id = 5;    -- 6, нова транзакція бачить зміну

UPDATE products SET stock = 8 WHERE id = 5; -- відновлення даних стенду
```

У PostgreSQL звичайний `SELECT` на рівні `READ COMMITTED` побачить зміну після `COMMIT` сесії B, а на рівні `REPEATABLE READ` — ні.

### Приклад 7. Фантомне читання

Сесія A (MySQL, типовий `REPEATABLE READ`):

```sql
START TRANSACTION;
SELECT COUNT(*) AS cnt FROM orders WHERE customer_id = 3;   -- 1
```

Сесія B:

```sql
INSERT INTO orders (customer_id, product_id, quantity)
VALUES (3, 1, 1);
SELECT COUNT(*) AS cnt FROM orders WHERE customer_id = 3;   -- 2
```

Сесія A:

```sql
SELECT COUNT(*) AS cnt FROM orders WHERE customer_id = 3;   -- 1: знімок не змінився
COMMIT;
```

Прибирання тестового рядка:

```sql
DELETE FROM orders WHERE customer_id = 3 AND product_id = 1;
```

У PostgreSQL на `READ COMMITTED` сесія A після фіксації сесії B побачила б `2`; на `REPEATABLE READ` — залишилося б `1`.

### Приклад 8. Блокування рядків: FOR UPDATE, FOR SHARE, SKIP LOCKED

`SELECT ... FOR UPDATE` блокує вибрані рядки до кінця транзакції — інші транзакції не зможуть їх змінити або заблокувати.

Сесія A:

```sql
START TRANSACTION;
SELECT id, stock FROM products WHERE id = 2 FOR UPDATE;
-- рядок заблоковано; тримаємо транзакцію відкритою
```

Сесія B (ця команда чекатиме):

```sql
START TRANSACTION;
SELECT id, stock FROM products WHERE id = 2 FOR UPDATE;
```

Сесія A завершує:

```sql
UPDATE products SET stock = stock - 1 WHERE id = 2;
COMMIT;
```

Після цього сесія B отримує блокування й бачить уже нове значення `stock`. Якщо чекати не потрібно, використовують `NOWAIT`:

```sql
-- MySQL: ERROR 3572, PostgreSQL: ERROR 55P03
SELECT id FROM products WHERE id = 2 FOR UPDATE NOWAIT;
```

`FOR SHARE` ставить спільне блокування: читати можуть усі, писати — ніхто. Патерн черги завдань використовує `SKIP LOCKED`:

```sql
START TRANSACTION;

SELECT id, product_id
FROM orders
WHERE customer_id = 1
ORDER BY id
LIMIT 1
FOR UPDATE SKIP LOCKED;

COMMIT;
```

`SKIP LOCKED` підтримують MySQL 8.4, MariaDB 11.4 і PostgreSQL 17.

### Приклад 9. Взаємне блокування (deadlock) у двох терміналах

Deadlock виникає, коли дві транзакції чекають на ресурси одна одної. Відтворення:

Сесія A:

```sql
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 1;
```

Сесія B:

```sql
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 2;
```

Сесія A (чекає, бо рядок 2 заблоковано сесією B):

```sql
UPDATE products SET stock = stock - 1 WHERE id = 2;
```

Сесія B (замикає коло — СУБД вибирає жертву):

```sql
UPDATE products SET stock = stock - 1 WHERE id = 1;
```

MySQL поверне помилку одній із сесій:

```text
ERROR 1213 (40001): Deadlock found when trying to get lock; try restarting transaction
```

PostgreSQL:

```text
ERROR:  deadlock detected
DETAIL:  Process 12345 waits for ShareLock on transaction 678; blocked by process 23456.
HINT:  See server log for query details.
```

Після помилки транзакція-жертва вже скасована; її слід повторити:

```sql
ROLLBACK;
-- повторити транзакцію повністю
```

У PostgreSQL після помилки також потрібен `ROLLBACK`, бо транзакція перебуває в стані `aborted`.

Діагностика останнього deadlock у MySQL:

```sql
SHOW ENGINE INNODB STATUS\G
-- секція LATEST DETECTED DEADLOCK
```

У PostgreSQL перегляд заблокованих транзакцій:

```sql
SELECT pid, pg_blocking_pids(pid) AS blockers, wait_event_type, query
FROM pg_stat_activity
WHERE cardinality(pg_blocking_pids(pid)) > 0;
```

### Приклад 10. Очікування блокувань, тайм-аути та моніторинг

Керування часом очікування:

```sql
-- MySQL: за замовчуванням 50 секунд
SET SESSION innodb_lock_wait_timeout = 5;
SHOW VARIABLES LIKE 'innodb_lock_wait_timeout';
```

```sql
-- PostgreSQL
SET lock_timeout = '5s';
SET statement_timeout = '10s';
SHOW lock_timeout;
```

Моніторинг блокувань у MySQL (потрібні права `root`):

```sql
SELECT
    r.trx_mysql_thread_id AS waiting_thread,
    r.trx_query           AS waiting_query,
    b.trx_mysql_thread_id AS blocking_thread,
    b.trx_query           AS blocking_query
FROM performance_schema.data_lock_waits w
JOIN information_schema.innodb_trx r
     ON r.trx_id = w.waiting_engine_transaction_id
JOIN information_schema.innodb_trx b
     ON b.trx_id = w.blocking_engine_transaction_id;
```

Моніторинг блокувань у PostgreSQL:

```sql
SELECT
    blocked.pid   AS blocked_pid,
    blocked.query AS blocked_query,
    blocking.pid  AS blocking_pid,
    blocking.query AS blocking_query
FROM pg_stat_activity blocked
JOIN LATERAL unnest(pg_blocking_pids(blocked.pid)) AS blk(pid) ON true
JOIN pg_stat_activity blocking ON blocking.pid = blk.pid;
```

Завершення завислих сесій:

```sql
-- MySQL
KILL QUERY 42;   -- скасувати запит
KILL 42;         -- розірвати з'єднання
```

```sql
-- PostgreSQL
SELECT pg_cancel_backend(12345);
SELECT pg_terminate_backend(12345);
```

## Типові помилки

1. **Забутий COMMIT.** Транзакцію відкрито, зміни зроблено, але без `COMMIT` вони не видні іншим сесіям і зникають після розриву з'єднання. У PostgreSQL така сесія видна як `idle in transaction` і блокує очищення старих версій рядків.
2. **Очікування, що MySQL відкотить усе при помилці.** Без `ROLLBACK` або обробки помилки в застосунку транзакція лишається відкритою і тримає блокування.
3. **`SELECT ... FOR UPDATE` без індексу.** У MySQL таке блокування може зачепити значно більше рядків (аж до всієї таблиці), ніж очікувалося.
4. **Різні рівні ізоляції в різних СУБД за замовчуванням.** MySQL — `REPEATABLE READ`, PostgreSQL — `READ COMMITTED`; перенесений код без явного рівня поводиться інакше.
5. **Зміна рівня ізоляції після першого запиту.** `SET TRANSACTION ISOLATION LEVEL` діє лише до першої команди в транзакції; інакше буде помилка або зміна не застосується.
6. **Непослідовний порядок блокувань.** Якщо одна транзакція блокує рядки 1 → 2, а друга 2 → 1, deadlock майже неминучий. Домовляйтеся про єдиний порядок.
7. **Довгі транзакції з користувацьким очікуванням.** Відкрита транзакція під час очікування введення з клавіатури тримає блокування; у реальному застосунку це неприпустимо.
8. **`autocommit = 0` без повернення назад.** Забутий `SET autocommit = 1` призводить до несподіваних «зависших» змін у наступних сесіях.
9. **Очікування, що `ROLLBACK` скасує DDL.** У MySQL `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE` викликають неявний `COMMIT`.
10. **Ігнорування помилки серіалізації в PostgreSQL.** На `SERIALIZABLE` помилка `could not serialize access due to concurrent update` (SQLSTATE 40001) означає, що транзакцію треба повторити, а не показати користувачу.

## Вправи

1. Напишіть транзакцію MySQL, яка зменшує `stock` товару `id = 2` на 3 та додає замовлення (`customer_id = 1`, `product_id = 2`, `quantity = 3`). Переконайтеся, що після `COMMIT` змінилися обидві таблиці.
2. Виконайте в одній транзакції вставку двох нових клієнтів, скасуйте вставку другого через `SAVEPOINT`, зафіксуйте транзакцію. Потім приберіть створені дані.
3. У двох терміналах продемонструйте різницю між `READ COMMITTED` і `REPEATABLE READ` для поля `stock` товару `id = 3`.
4. У сесії A заблокуйте рядок `products.id = 4` через `FOR UPDATE`. У сесії B спробуйте оновити цей рядок і покажіть, що команда чекає. Завершіть обидві транзакції без помилок.
5. Відтворіть deadlock на таблиці `products` (рядки 1 і 2) у двох сесіях MySQL і знайдіть звіт про нього в `SHOW ENGINE INNODB STATUS`.

## Відповіді

1.

```sql
START TRANSACTION;

UPDATE products SET stock = stock - 3 WHERE id = 2 AND stock >= 3;
INSERT INTO orders (customer_id, product_id, quantity) VALUES (1, 2, 3);

COMMIT;

SELECT stock FROM products WHERE id = 2;
SELECT * FROM orders ORDER BY id DESC LIMIT 1;
```

2.

```sql
START TRANSACTION;

INSERT INTO customers (full_name, email, city)
VALUES ('Клієнт Один', 'client.one@example.com', 'Київ');
SAVEPOINT sp_second;

INSERT INTO customers (full_name, email, city)
VALUES ('Клієнт Два', 'client.two@example.com', 'Львів');
ROLLBACK TO SAVEPOINT sp_second;
RELEASE SAVEPOINT sp_second;

COMMIT;

SELECT * FROM customers WHERE email = 'client.one@example.com';
-- Прибирання:
DELETE FROM customers WHERE email = 'client.one@example.com';
```

3.

```sql
-- Сесія A
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
START TRANSACTION;
SELECT stock FROM products WHERE id = 3;   -- 40

-- Сесія B
UPDATE products SET stock = 39 WHERE id = 3;

-- Сесія A
SELECT stock FROM products WHERE id = 3;   -- 39
COMMIT;

-- Повторіть із REPEATABLE READ: друга вибірка поверне 40 (до COMMIT).
UPDATE products SET stock = 40 WHERE id = 3;
```

4.

```sql
-- Сесія A
START TRANSACTION;
SELECT id, stock FROM products WHERE id = 4 FOR UPDATE;

-- Сесія B (чекає)
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 4;

-- Сесія A
COMMIT;

-- Сесія B: після звільнення блокування UPDATE виконується
COMMIT;
```

5.

```sql
-- Сесія A
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 1;

-- Сесія B
START TRANSACTION;
UPDATE products SET stock = stock - 1 WHERE id = 2;

-- Сесія A (чекає)
UPDATE products SET stock = stock - 1 WHERE id = 2;

-- Сесія B (одна з транзакцій отримає ERROR 1213)
UPDATE products SET stock = stock - 1 WHERE id = 1;

-- Перевірка:
SHOW ENGINE INNODB STATUS\G
ROLLBACK;
```

## Пов'язані матеріали

- Транзакції MySQL: [`../lessons/transactions_mysql.sql`](../lessons/transactions_mysql.sql)
- Транзакції PostgreSQL: [`../lessons/transactions_postgres.sql`](../lessons/transactions_postgres.sql)
- Веб-тренажер: http://localhost:8000/runner.php та http://localhost:8000/tasks.php
