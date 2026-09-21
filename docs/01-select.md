# 01. SELECT: вибірка, фільтрація та сортування

`SELECT` — команда, з якої починається робота з даними: вона повертає рядки й колонки, потрібні застосунку. У цьому конспекті — базовий синтаксис, фільтрація (`WHERE`), сортування (`ORDER BY`), обмеження кількості рядків (`LIMIT`), унікальні значення (`DISTINCT`), оператори, робота з `NULL`, шаблони `LIKE`, набори `IN` і діапазони `BETWEEN`.

## Дані стенду

```text
MySQL 8.4, база learn
customers(id, full_name, email, city, created_at)             5 рядків
products (id, title, price, stock)                            5 рядків
orders   (id, customer_id, product_id, quantity, ordered_at)  6 рядків

MariaDB 11.4, база learn
authors(id, name, country), books(id, author_id, title, published_year, pages)

PostgreSQL 17, база learn, схема shop
shop.customers, shop.products, shop.orders — ті самі колонки
```

У PostgreSQL таблиці лежать у схемі `shop`. Якщо `search_path` уже вказує на `shop`, префікс можна опускати; у прикладах він указаний явно.

## Синтаксис

```sql
SELECT [DISTINCT] column1, column2, expression AS alias
FROM table_name
WHERE conditions
ORDER BY column1 [ASC | DESC], column2 [ASC | DESC]
LIMIT count [OFFSET start];
```

Логічний порядок обробки: `FROM` → `WHERE` → `SELECT` (обчислення виразів) → `DISTINCT` → `ORDER BY` → `LIMIT`. Тому аліаси з `SELECT` недоступні у `WHERE`, але працюють у `ORDER BY`.

## Приклади

### Приклад 1. Вибірка колонок і обчислювані вирази

```sql
SELECT id, full_name, city
FROM customers;

SELECT title, price, stock, price * stock AS stock_value
FROM products;
```

`SELECT *` повертає всі колонки, але в робочому коді краще перелічувати потрібні явно: так запит не зламається після зміни схеми.

```text
title                        | price     | stock | stock_value
Ноутбук Lenovo IdeaPad       | 24999.00  | 12    | 299988.00
Смартфон Samsung Galaxy      | 18499.50  | 25    | 462487.50
Навушники Sony WH-CH720      | 4299.00   | 40    | 171960.00
Клавіатура Logitech K380     | 1799.00   | 60    | 107940.00
Монітор Dell 24"             | 7499.00   | 8     | 59992.00
```

### Приклад 2. WHERE та пріоритет логічних операторів

```sql
SELECT full_name, city
FROM customers
WHERE city = 'Київ';

SELECT title, price, stock
FROM products
WHERE price < 5000 OR stock > 50;
```

```text
full_name       | city
Олена Ковальчук | Київ
```

Другий запит повертає «Навушники Sony WH-CH720» (ціна 4299), «Клавіатура Logitech K380» (ціна 1799 і залишок 60). Оператор `AND` має вищий пріоритет за `OR`, тому складні умови беріть у дужки:

```sql
SELECT title, price, stock
FROM products
WHERE (price < 5000 OR stock > 50)
  AND stock <> 60;
```

Результат — лише «Навушники Sony WH-CH720». Дужки тут обов'язкові для читабельності й правильності: без них `AND stock <> 60` застосувався б тільки до правої частини `OR`.

### Приклад 3. ORDER BY: сортування за кількома колонками

```sql
SELECT city, full_name
FROM customers
ORDER BY city ASC, full_name ASC;

SELECT title, price
FROM products
ORDER BY price DESC;
```

```text
city   | full_name
Дніпро | Софія Ткаченко
Київ   | Олена Ковальчук
Львів  | Ігор Мельник
Одеса  | Марія Бондаренко
Харків | Андрій Шевченко

title                        | price
Ноутбук Lenovo IdeaPad       | 24999.00
Смартфон Samsung Galaxy      | 18499.50
Монітор Dell 24"             | 7499.00
Навушники Sony WH-CH720      | 4299.00
Клавіатура Logitech K380     | 1799.00
```

Порядок кириличних рядків визначає колація (`utf8mb4_unicode_ci` у MySQL/MariaDB), тому «Дніпро» стоїть перед «Києвом». `ASC` — типове значення, його можна не писати.

### Приклад 4. LIMIT та OFFSET

```sql
-- MySQL / MariaDB
SELECT title, price FROM products ORDER BY price DESC LIMIT 3;
SELECT title, price FROM products ORDER BY price DESC LIMIT 2 OFFSET 1;
SELECT title, price FROM products ORDER BY price DESC LIMIT 1, 2; -- offset, count
```

```sql
-- PostgreSQL
SELECT title, price FROM shop.products ORDER BY price DESC LIMIT 3;
SELECT title, price FROM shop.products ORDER BY price DESC LIMIT 2 OFFSET 1;
SELECT title, price FROM shop.products ORDER BY price DESC
OFFSET 1 ROWS FETCH FIRST 2 ROWS ONLY;  -- стандартний SQL, підтримує PostgreSQL
```

```text
-- LIMIT 3
Ноутбук Lenovo IdeaPad
Смартфон Samsung Galaxy
Монітор Dell 24"

-- LIMIT 2 OFFSET 1
Смартфон Samsung Galaxy
Монітор Dell 24"
```

`LIMIT` без `ORDER BY` повертає довільні рядки: без сортування СУБД не гарантує порядок. Синтаксис `LIMIT offset, count` — особливість MySQL/MariaDB, у PostgreSQL пишіть `LIMIT count OFFSET offset`.

### Приклад 5. DISTINCT: унікальні значення

```sql
SELECT DISTINCT city
FROM customers
ORDER BY city;

SELECT COUNT(DISTINCT city) AS cities
FROM customers;
```

```text
city
Дніпро
Київ
Львів
Одеса
Харків

cities
5
```

`DISTINCT` застосовується до всього списку колонок `SELECT`. Якщо додати другу колонку, унікальними вважатимуться комбінації значень, а не окремі міста.

### Приклад 6. LIKE: шаблони рядків

```sql
SELECT full_name, city
FROM customers
WHERE full_name LIKE 'О%';

SELECT full_name
FROM customers
WHERE full_name LIKE '%енко'
ORDER BY full_name;

SELECT email
FROM customers
WHERE email LIKE '%@example.com';
```

```text
-- LIKE 'О%'
Олена Ковальчук | Київ

-- LIKE '%енко'
Марія Бондаренко
Андрій Шевченко
Софія Ткаченко
```

Спецсимволи шаблону: `%` — будь-яка кількість символів (у тому числі нуль), `_` — рівно один символ. Для пошуку самого символу `_` його екранують зворотним слешем (в усіх трьох СУБД він є символом екранування `LIKE` за замовчуванням):

```sql
SELECT title FROM products WHERE title LIKE '%\_%';
```

Явно задати інший символ екранування можна клаузою `ESCAPE` — це переносимий варіант для всіх трьох СУБД:

```sql
-- PostgreSQL
SELECT title FROM shop.products WHERE title LIKE '%#_%' ESCAPE '#';

-- MySQL / MariaDB
SELECT title FROM products WHERE title LIKE '%#_%' ESCAPE '#';
```

Регістрозалежність залежить від СУБД. У MySQL/MariaDB з колацією `utf8mb4_unicode_ci` запит `WHERE title LIKE '%sony%'` знайде «Навушники Sony WH-CH720». У PostgreSQL `LIKE` чутливий до регістру й такий запит поверне 0 рядків — використовуйте `ILIKE`:

```sql
-- PostgreSQL
SELECT title FROM shop.products WHERE title ILIKE '%sony%';
```

### Приклад 7. IN і NOT IN

```sql
SELECT city, full_name
FROM customers
WHERE city IN ('Київ', 'Львів', 'Одеса')
ORDER BY city;

SELECT title, price
FROM products
WHERE id NOT IN (1, 2, 5)
ORDER BY id;
```

`IN` — скорочений запис ланцюжка `OR`: `city = 'Київ' OR city = 'Львів' OR city = 'Одеса'`. Другий запит поверне «Навушники Sony WH-CH720» та «Клавіатура Logitech K380». Обережно з `NOT IN` і підзапитами, які повертають `NULL`: деталі в конспекті 04.

### Приклад 8. BETWEEN: діапазон значень

```sql
SELECT title, price
FROM products
WHERE price BETWEEN 4000 AND 20000
ORDER BY price;

SELECT id, customer_id, ordered_at
FROM orders
WHERE ordered_at BETWEEN '2026-02-01' AND '2026-02-28 23:59:59'
ORDER BY ordered_at;
```

```text
title                     | price
Навушники Sony WH-CH720   | 4299.00
Монітор Dell 24"          | 7499.00
Смартфон Samsung Galaxy   | 18499.50
```

`BETWEEN a AND b` включає обидві межі: це `>= a AND <= b`. Для дат з часом верхню межу краще задавати як «наступний день, не включно» — тоді не потрібно вгадувати `23:59:59`:

```sql
SELECT id, ordered_at
FROM orders
WHERE ordered_at >= '2026-02-01' AND ordered_at < '2026-03-01'
ORDER BY ordered_at;
```

Результат в обох випадках — замовлення 3 (3 лютого) та 4 (18 лютого). Для `DATE`/`TIMESTAMP` колонок у PostgreSQL синтаксис такий самий; додатково можна писати `BETWEEN DATE '2026-02-01' AND DATE '2026-02-28'`, що явно фіксує тип.

### Приклад 9. NULL: тризначна логіка

```sql
SELECT full_name, city
FROM customers
WHERE city = NULL;      -- неправильно: завжди 0 рядків

SELECT full_name, city
FROM customers
WHERE city IS NULL;     -- правильно

SELECT 5 NOT IN (1, 3, NULL) AS result;  -- NULL, а не 0/1
```

`NULL` означає «значення невідоме», тому `= NULL`, `<> NULL` і навіть `NULL = NULL` дають `NULL`, а не `TRUE`. Фільтр пропускає рядок лише тоді, коли умова істинна, тож перевіряйте на `IS NULL` / `IS NOT NULL`. Для значень за замовчуванням використовують `COALESCE`:

```sql
SELECT full_name, COALESCE(city, 'не вказано') AS city
FROM customers;
```

У навчальних даних усі міста заповнені, тому `IS NULL` поверне 0 рядків. Щоб побачити ефект, додайте клієнта без міста через Adminer (http://localhost:8080) або консольний клієнт. MySQL/MariaDB мають функцію `IFNULL(a, b)` — аналог `COALESCE` для двох аргументів; у PostgreSQL є тільки `COALESCE`.

### Приклад 10. Конкатенація та дати: MySQL проти PostgreSQL

```sql
-- MySQL / MariaDB
SELECT CONCAT(full_name, ' <', email, '>') AS contact
FROM customers;

SELECT id, DATE_FORMAT(ordered_at, '%Y-%m-%d %H:%i') AS ordered_min
FROM orders
ORDER BY ordered_at;

SELECT id, YEAR(ordered_at) AS y, MONTH(ordered_at) AS m
FROM orders
ORDER BY ordered_at;

SELECT CONCAT('a', NULL, 'b') AS concat_with_null;  -- NULL
```

```sql
-- PostgreSQL
SELECT full_name || ' <' || email || '>' AS contact
FROM shop.customers;

SELECT id, to_char(ordered_at, 'YYYY-MM-DD HH24:MI') AS ordered_min
FROM shop.orders
ORDER BY ordered_at;

SELECT id, EXTRACT(YEAR FROM ordered_at) AS y, EXTRACT(MONTH FROM ordered_at) AS m
FROM shop.orders
ORDER BY ordered_at;

SELECT CONCAT('a', NULL, 'b') AS concat_with_null;  -- 'ab'
```

Ключові відмінності:

- у PostgreSQL конкатенація рядків — оператор `||`; у MySQL/MariaDB `||` означає логічне `OR` (у стандартному режимі), тому для рядків використовують `CONCAT`;
- `CONCAT` у MySQL повертає `NULL`, якщо хоч один аргумент `NULL`; у PostgreSQL `CONCAT` ігнорує `NULL`, а `||` з `NULL` дає `NULL`;
- форматування дат: `DATE_FORMAT` / `YEAR` / `MONTH` проти `to_char` / `EXTRACT`;
- рядкові літерали в обох СУБД — в одинарних лапках. У PostgreSQL подвійні лапки — це ідентифікатор колонки, у MySQL подвійні лапки працюють як рядок лише доти, доки не увімкнено `ANSI_QUOTES`.

Фільтр за датою виглядає однаково: `WHERE ordered_at >= '2026-03-01'` поверне замовлення 5 і 6.

## Типові помилки

1. **Порівняння з `NULL` через `=`.** `WHERE city = NULL` не знайде нічого ніколи. Правильно: `IS NULL` або `IS NOT NULL`.
2. **Забутий `ORDER BY` при `LIMIT`.** Без сортування СУБД повертає довільні рядки, і результат може змінюватися між запусками.
3. **Пріоритет `AND` над `OR`.** Умова `WHERE a = 1 OR b = 2 AND c = 3` читається як `a = 1 OR (b = 2 AND c = 3)`. Складні умови завжди беріть у дужки.
4. **Аліас у `WHERE`.** Запит `SELECT price * quantity AS total FROM orders WHERE total > 1000` не виконається: `WHERE` обробляється до обчислення аліасів. Повторіть вираз або оберніть запит у підзапит.
5. **Діалектні пастки.** `||` у MySQL — це `OR`, а подвійні лапки в PostgreSQL — ідентифікатор. Використовуйте `CONCAT` / `||` і одинарні лапки відповідно до СУБД.

## Вправи

1. Виведіть назву та ціну товарів, дорожчих за 5000, відсортованих за ціною від найдорожчого.
   Підказка: `WHERE price > 5000 ORDER BY price DESC`.

2. Виведіть усі міста клієнтів без повторів у алфавітному порядку.
   Підказка: `DISTINCT` і `ORDER BY`.

3. Знайдіть клієнтів, чиє прізвище закінчується на «енко», і виведіть ім'я та місто.
   Підказка: шаблон `LIKE '%енко'`.

4. Виведіть два найдорожчі товари (назва, ціна), однаково для MySQL і PostgreSQL.
   Підказка: `ORDER BY price DESC LIMIT 2`.

5. Виведіть замовлення за лютий 2026 року: `id`, `customer_id`, `ordered_at`, за зростанням дати. Напишіть варіант із `BETWEEN` і варіант із двома межами.
   Підказка: верхня межа — `'2026-03-01'` без включення.

## Відповіді

1.

```sql
SELECT title, price
FROM products
WHERE price > 5000
ORDER BY price DESC;
```

Результат: «Ноутбук Lenovo IdeaPad» (24999.00), «Смартфон Samsung Galaxy» (18499.50), «Монітор Dell 24"» (7499.00).

2.

```sql
-- MySQL / MariaDB
SELECT DISTINCT city FROM customers ORDER BY city;
```

```sql
-- PostgreSQL
SELECT DISTINCT city FROM shop.customers ORDER BY city;
```

Результат: Дніпро, Київ, Львів, Одеса, Харків.

3.

```sql
SELECT full_name, city
FROM customers
WHERE full_name LIKE '%енко'
ORDER BY full_name;
```

Результат: Марія Бондаренко (Одеса), Софія Ткаченко (Дніпро), Андрій Шевченко (Харків).

4.

```sql
-- MySQL / MariaDB
SELECT title, price FROM products ORDER BY price DESC LIMIT 2;
```

```sql
-- PostgreSQL
SELECT title, price FROM shop.products ORDER BY price DESC LIMIT 2;
```

Результат: «Ноутбук Lenovo IdeaPad», «Смартфон Samsung Galaxy».

5.

```sql
-- варіант із BETWEEN
SELECT id, customer_id, ordered_at
FROM orders
WHERE ordered_at BETWEEN '2026-02-01' AND '2026-02-28 23:59:59'
ORDER BY ordered_at;

-- варіант із напіввідкритим інтервалом (надійніший)
SELECT id, customer_id, ordered_at
FROM orders
WHERE ordered_at >= '2026-02-01'
  AND ordered_at < '2026-03-01'
ORDER BY ordered_at;
```

Результат: замовлення 3 (2026-02-03) і 4 (2026-02-18). У PostgreSQL додайте префікс `shop.` до таблиці `orders`.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
