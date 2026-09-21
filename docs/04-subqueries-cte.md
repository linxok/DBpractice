# 04. Підзапити та CTE (WITH), рекурсивні CTE

Підзапит — це `SELECT` усередині іншого запиту. Він дозволяє порівнювати рядки із середнім, шукати «тих, хто має/не має пари» та будувати проміжні результати. `CTE` (Common Table Expression, конструкція `WITH`) — той самий підзапит, але названий і винесений наперед, що робить складні запити читабельними. Рекурсивний CTE будує послідовності: числа, дати, ієрархії.

## Дані стенду

```text
MySQL 8.4, база learn
customers(id, full_name, email, city, created_at) — 5 клієнтів
products (id, title, price, stock)                — 5 товарів
orders   (id, customer_id, product_id, quantity, ordered_at) — 6 замовлень

MariaDB 11.4, база learn
authors(id, name, country), books(id, author_id, title, published_year, pages)

PostgreSQL 17, база learn, схема shop
shop.customers, shop.products, shop.orders — ті самі дані
```

## Синтаксис

```sql
-- скалярний підзапит у SELECT або WHERE (повертає одне значення)
SELECT ..., (SELECT expression FROM other WHERE other.key = main.key) AS alias
FROM main
WHERE column = (SELECT expression FROM other);

-- підзапит-набір у WHERE
SELECT ... FROM main WHERE column IN (SELECT column FROM other);
SELECT ... FROM main WHERE EXISTS (SELECT 1 FROM other WHERE other.key = main.key);

-- CTE
WITH name AS (
    SELECT ...
)
SELECT ... FROM name;

-- рекурсивний CTE
WITH RECURSIVE name AS (
    SELECT ...                              -- базовий член, виконується один раз
    UNION ALL
    SELECT ... FROM name WHERE condition    -- рекурсивний член, спирається на name
)
SELECT ... FROM name;
```

Усі три СУБД підтримують CTE та рекурсивні CTE (MySQL 8+, MariaDB 10.2+, PostgreSQL 8.4+). Ключове слово `RECURSIVE` обов'язкове в MySQL і PostgreSQL; MariaDB приймає `WITH RECURSIVE` так само, тому цей варіант універсальний.

## Приклади

### Приклад 1. Скалярний підзапит у WHERE

```sql
SELECT title, price
FROM products
WHERE price > (SELECT AVG(price) FROM products)
ORDER BY price DESC;
```

```text
title                   | price
Ноутбук Lenovo IdeaPad  | 24999.00
Смартфон Samsung Galaxy | 18499.50
```

Спочатку обчислюється середня ціна (11419.10), потім із нею порівнюється кожен рядок. PostgreSQL: `FROM shop.products WHERE price > (SELECT AVG(price) FROM shop.products)`.

### Приклад 2. Корельований скалярний підзапит у SELECT

```sql
SELECT c.full_name,
       c.city,
       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS orders_count
FROM customers c
ORDER BY orders_count DESC, c.id;
```

```text
full_name          | city   | orders_count
Олена Ковальчук    | Київ   | 2
Ігор Мельник       | Львів  | 1
Марія Бондаренко   | Одеса  | 1
Андрій Шевченко    | Харків | 1
Софія Ткаченко     | Дніпро | 1
```

Підзапит називають корельованим, бо він посилається на `c.id` із зовнішнього запиту й обчислюється для кожного рядка. Скалярний підзапит зобов'язаний повертати рівно одну колонку й не більше одного рядка; інакше MySQL видає помилку `ERROR 1242 (21000): Subquery returns more than 1 row`, PostgreSQL — `ERROR: more than one row returned by a subquery used as an expression`.

### Приклад 3. IN з підзапитом

```sql
SELECT full_name, city
FROM customers
WHERE id IN (SELECT customer_id FROM orders WHERE product_id = 2)
ORDER BY full_name;
```

```text
full_name        | city
Ігор Мельник     | Львів
Софія Ткаченко   | Дніпро
```

Внутрішній запит повертає `customer_id` усіх замовлень товару з `id = 2` («Смартфон Samsung Galaxy»): це 2 та 5. Зовнішній запит вибирає цих клієнтів. Еквівалент через `JOIN`:

```sql
SELECT DISTINCT c.full_name, c.city
FROM customers c
JOIN orders o ON o.customer_id = c.id
WHERE o.product_id = 2;
```

`DISTINCT` потрібен, бо `JOIN` продублював би клієнта за кожне його замовлення.

### Приклад 4. NOT IN і пастка NULL

```sql
SELECT title
FROM products
WHERE id NOT IN (SELECT product_id FROM orders);
```

Результат порожній — усі 5 товарів мають замовлення. Але в `NOT IN` є підступ: якщо підзапит поверне хоч одне `NULL`, результат стане порожнім незалежно від даних, бо `x NOT IN (1, 2, NULL)` обчислюється як `NOT (x = 1 OR x = 2 OR x = NULL)`, а `x = NULL` дає `NULL`.

```sql
SELECT 5 NOT IN (1, 3, NULL) AS result;   -- NULL, а не TRUE
```

Якщо підзапит може повертати `NULL`, використовуйте `NOT EXISTS` — він порівнює рядки предикатом і не має цієї проблеми.

### Приклад 5. EXISTS і NOT EXISTS

```sql
SELECT p.id, p.title
FROM products p
WHERE EXISTS (SELECT 1 FROM orders o WHERE o.product_id = p.id)
ORDER BY p.id;
```

`EXISTS` перевіряє лише факт наявності пари, тому в підзапиті пишуть `SELECT 1`, а не список колонок. Корельований `EXISTS` зупиняється на першому знайденому рядку. Приклад із додатковою умовою — клієнти, які замовляли щонайменше 2 одиниці товару за раз:

```sql
SELECT c.full_name
FROM customers c
WHERE EXISTS (
    SELECT 1 FROM orders o
    WHERE o.customer_id = c.id
      AND o.quantity >= 2
)
ORDER BY c.full_name;
```

```text
full_name
Марія Бондаренко
Олена Ковальчук
```

`NOT EXISTS` — надійний спосіб знайти «сиріт»:

```sql
SELECT c.full_name
FROM customers c
WHERE NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.id);
```

Результат порожній (усі клієнти мають замовлення), але запит коректно працюватиме навіть за наявності `NULL` у ключах.

### Приклад 6. Похідна таблиця (підзапит у FROM)

```sql
SELECT c.full_name,
       t.orders_count,
       t.total_amount
FROM (
    SELECT o.customer_id,
           COUNT(*)                      AS orders_count,
           SUM(p.price * o.quantity)      AS total_amount
    FROM orders o
    JOIN products p ON p.id = o.product_id
    GROUP BY o.customer_id
) AS t
JOIN customers c ON c.id = t.customer_id
ORDER BY t.total_amount DESC, c.id;
```

```text
full_name          | orders_count | total_amount
Олена Ковальчук    | 2            | 33597.00
Ігор Мельник       | 1            | 18499.50
Софія Ткаченко     | 1            | 18499.50
Марія Бондаренко   | 1            | 14998.00
Андрій Шевченко    | 1            | 1799.00
```

Підзапит у `FROM` обов'язково отримує аліас (`AS t`) — інакше MySQL скаже `Every derived table must have its own alias`, PostgreSQL — `subquery in FROM must have an alias`. Агрегацію можна робити лише один раз: `SUM` по вже згрупованих рядках дав би неправильний результат.

### Приклад 7. CTE: той самий розрахунок читабельніше

```sql
WITH order_totals AS (
    SELECT o.customer_id,
           COUNT(*)                 AS orders_count,
           SUM(p.price * o.quantity) AS total_amount
    FROM orders o
    JOIN products p ON p.id = o.product_id
    GROUP BY o.customer_id
)
SELECT c.full_name, t.orders_count, t.total_amount
FROM order_totals t
JOIN customers c ON c.id = t.customer_id
WHERE t.total_amount > 10000
ORDER BY t.total_amount DESC, c.id;
```

```text
full_name          | orders_count | total_amount
Олена Ковальчук    | 2            | 33597.00
Ігор Мельник       | 1            | 18499.50
Софія Ткаченко     | 1            | 18499.50
Марія Бондаренко   | 1            | 14998.00
```

CTE не змінює логіку запиту — це той самий похідний підзапит, але з іменем, яке можна використати кілька разів і прочитати зверху вниз. На відміну від підзапиту в `FROM`, CTE оголошується до основного запиту.

### Приклад 8. Кілька CTE і повторне використання

```sql
WITH order_totals AS (
    SELECT o.customer_id,
           SUM(p.price * o.quantity) AS total_amount
    FROM orders o
    JOIN products p ON p.id = o.product_id
    GROUP BY o.customer_id
)
SELECT c.full_name, t.total_amount
FROM order_totals t
JOIN customers c ON c.id = t.customer_id
WHERE t.total_amount > (SELECT AVG(total_amount) FROM order_totals)
ORDER BY t.total_amount DESC, c.id;
```

```text
full_name          | total_amount
Олена Ковальчук    | 33597.00
Ігор Мельник       | 18499.50
Софія Ткаченко     | 18499.50
```

Середня сума по п'яти клієнтах — 17478.60 (`87393.00 / 5`), тому поріг перетнули три клієнти. CTE `order_totals` використано двічі: у `FROM` і у скалярному підзапиті. MySQL 8 може матеріалізувати такий CTE один раз і перевикористати; у PostgreSQL 12+ CTE без `MATERIALIZED` за замовчуванням вбудовується (inlined), тож план може відрізнятися, а результат — ні.

MariaDB-варіант із таблицями `authors`/`books`:

```sql
WITH book_stats AS (
    SELECT author_id,
           COUNT(*)  AS books_count,
           SUM(pages) AS total_pages
    FROM books
    GROUP BY author_id
)
SELECT a.name, b.books_count, b.total_pages
FROM authors a
JOIN book_stats b ON b.author_id = a.id
ORDER BY b.total_pages DESC, a.name;
```

Результат: Джордж Орвелл — 2 книги, 440 сторінок; решта — по одній (320, 280, 160, 128).

### Приклад 9. Рекурсивний CTE: числа від 1 до 10

```sql
WITH RECURSIVE seq AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 10
)
SELECT n FROM seq ORDER BY n;
```

```text
n
1
2
...
10
```

Базовий член `SELECT 1` дає перший рядок, рекурсивний член додає `n + 1`, доки умова `n < 10` істинна. Обмеження:

- MySQL: `cte_max_recursion_depth` типово 1000 — глибша рекурсія перерветься помилкою `Recursive query aborted after 1001 iterations`.
- MariaDB: аналогічний параметр `max_recursive_iterations`.
- PostgreSQL: жорсткого ліміту немає, тому необережний запит може виконуватися дуже довго — завжди перевіряйте умову виходу.

### Приклад 10. Рекурсивний CTE: календар місяців із замовленнями

```sql
-- MySQL / MariaDB
WITH RECURSIVE months AS (
    SELECT DATE('2026-01-01') AS month_start
    UNION ALL
    SELECT month_start + INTERVAL 1 MONTH
    FROM months
    WHERE month_start < '2026-03-01'
)
SELECT m.month_start,
       COUNT(o.id)               AS orders_count,
       COALESCE(SUM(o.quantity), 0) AS items
FROM months m
LEFT JOIN orders o
  ON o.ordered_at >= m.month_start
 AND o.ordered_at <  m.month_start + INTERVAL 1 MONTH
GROUP BY m.month_start
ORDER BY m.month_start;
```

```sql
-- PostgreSQL
WITH RECURSIVE months AS (
    SELECT DATE '2026-01-01' AS month_start
    UNION ALL
    SELECT (month_start + INTERVAL '1 month')::date
    FROM months
    WHERE month_start < DATE '2026-03-01'
)
SELECT m.month_start,
       COUNT(o.id)               AS orders_count,
       COALESCE(SUM(o.quantity), 0) AS items
FROM months m
LEFT JOIN shop.orders o
  ON o.ordered_at >= m.month_start
 AND o.ordered_at <  m.month_start + INTERVAL '1 month'
GROUP BY m.month_start
ORDER BY m.month_start;
```

```text
month_start | orders_count | items
2026-01-01  | 2            | 3
2026-02-01  | 2            | 3
2026-03-01  | 2            | 2
```

Різниця діалектів: арифметика дат — `+ INTERVAL 1 MONTH` (MySQL/MariaDB і PostgreSQL), але в PostgreSQL додавання інтервалу до `date` дає `timestamp`, тому результат явно приводять назад до `date` через `::date`. Умови з'єднання працюють з піввідкритим інтервалом `[month_start, month_start + 1 month)` — це коректніше за `BETWEEN` із часом.

## Типові помилки

1. **Скалярний підзапит повернув багато рядків.** Порівняння `= (SELECT ...)` падає з помилкою 1242 (MySQL) / «more than one row» (PostgreSQL). Якщо значень може бути кілька, використовуйте `IN` або `EXISTS`.
2. **`NOT IN` із `NULL`.** Підзапит, що повертає `NULL`, робить результат `NOT IN` порожнім. Надійна альтернатива — `NOT EXISTS`.
3. **Забутий аліас похідної таблиці.** `FROM (SELECT ...)` без `AS t` — помилка. Аліас обов'язковий у MySQL, MariaDB і PostgreSQL.
4. **Безмежна рекурсія.** У рекурсивному CTE забута або неправильна умова виходу; у MySQL/MariaDB запит перерветься за лімітом, у PostgreSQL може працювати дуже довго.
5. **CTE як «безкоштовний кеш».** Матеріалізований CTE у MySQL може виконуватися один раз і давати повільніший план, ніж еквівалентний `JOIN`; перевіряйте `EXPLAIN`, а не припускайте.
6. **Підзапит у `SELECT` на кожен рядок.** Корельований скалярний підзапит виконується для кожного рядка зовнішнього запиту; на великих таблицях `JOIN` з агрегацією зазвичай швидший.

## Вправи

1. Виведіть товари, ціна яких вища за середню, без `JOIN` — лише таблиця `products`.
   Підказка: скалярний підзапит `(SELECT AVG(price) FROM products)`.

2. Виведіть імена клієнтів, які замовляли «Смартфон Samsung Galaxy» (`product_id = 2`), через `IN`.
   Підказка: спочатку знайдіть `customer_id` у `orders`, потім відфільтруйте `customers`.

3. Виведіть клієнтів, які мають хоча б одне замовлення з `quantity >= 2`, через `EXISTS`.
   Підказка: корельована умова `o.customer_id = c.id AND o.quantity >= 2`.

4. Через CTE виведіть клієнтів, чия сумарна вартість замовлень (`SUM(price * quantity)`) перевищує середню суму по всіх клієнтах.
   Підказка: CTE з групуванням за `customer_id`, потім порівняння з `(SELECT AVG(total_amount) FROM totals)`.

5. Рекурсивним CTE виведіть числа від 1 до 20, а потім — місяці з квітня до червня 2026 року (MySQL/MariaDB або PostgreSQL).
   Підказка: базовий член задає початкове значення, рекурсивний додає крок; для дат — `INTERVAL 1 MONTH` і умова виходу `month_start < '2026-06-01'`.

## Відповіді

1.

```sql
SELECT title, price
FROM products
WHERE price > (SELECT AVG(price) FROM products)
ORDER BY price DESC;
```

Результат: «Ноутбук Lenovo IdeaPad» (24999.00), «Смартфон Samsung Galaxy» (18499.50).

2.

```sql
SELECT full_name, city
FROM customers
WHERE id IN (SELECT customer_id FROM orders WHERE product_id = 2)
ORDER BY full_name;
```

Результат: Ігор Мельник (Львів), Софія Ткаченко (Дніпро).

3.

```sql
SELECT c.full_name
FROM customers c
WHERE EXISTS (
    SELECT 1 FROM orders o
    WHERE o.customer_id = c.id
      AND o.quantity >= 2
)
ORDER BY c.full_name;
```

Результат: Марія Бондаренко, Олена Ковальчук.

4.

```sql
WITH totals AS (
    SELECT o.customer_id,
           SUM(p.price * o.quantity) AS total_amount
    FROM orders o
    JOIN products p ON p.id = o.product_id
    GROUP BY o.customer_id
)
SELECT c.full_name, t.total_amount
FROM totals t
JOIN customers c ON c.id = t.customer_id
WHERE t.total_amount > (SELECT AVG(total_amount) FROM totals)
ORDER BY t.total_amount DESC, c.id;
```

Результат: Олена Ковальчук (33597.00), Ігор Мельник (18499.50), Софія Ткаченко (18499.50).

5.

```sql
WITH RECURSIVE seq AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 20
)
SELECT n FROM seq ORDER BY n;
```

```sql
-- MySQL / MariaDB
WITH RECURSIVE months AS (
    SELECT DATE('2026-04-01') AS month_start
    UNION ALL
    SELECT month_start + INTERVAL 1 MONTH
    FROM months
    WHERE month_start < '2026-06-01'
)
SELECT month_start FROM months ORDER BY month_start;
```

```sql
-- PostgreSQL
WITH RECURSIVE months AS (
    SELECT DATE '2026-04-01' AS month_start
    UNION ALL
    SELECT (month_start + INTERVAL '1 month')::date
    FROM months
    WHERE month_start < DATE '2026-06-01'
)
SELECT month_start FROM months ORDER BY month_start;
```

Результат: 2026-04-01, 2026-05-01, 2026-06-01.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
