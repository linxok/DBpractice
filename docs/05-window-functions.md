# 05. Віконні функції

Віконні функції обчислюють агрегат або позицію рядка в межах групи, **не згортаючи** рядки. `GROUP BY` перетворює шість замовлень на п'ять підсумків по клієнтах, а віконна функція залишає всі шість рядків і додає до кожного номер, рейтинг, попереднє значення або накопичувальну суму. Це стандартний інструмент для топ-N у групі, порівняння сусідніх подій і часток від цілого.

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

Усі три СУБД підтримують віконні функції: MySQL 8, MariaDB 10.2+, PostgreSQL. Синтаксис `OVER` ідентичний; відмінності стосуються лише окремих функцій фреймів (про них нижче).

## Синтаксис

```sql
function_name(arguments) OVER (
    [PARTITION BY column, ...]        -- розбиття на групи-вікна
    [ORDER BY column [ASC|DESC], ...] -- порядок усередині вікна
    [ROWS | RANGE frame]              -- межі фрейму
)
```

Класифікація:

- нумерація: `ROW_NUMBER`, `RANK`, `DENSE_RANK`, `NTILE`;
- зсув: `LAG`, `LEAD`, `FIRST_VALUE`, `LAST_VALUE`;
- агрегати як віконні: `SUM`, `AVG`, `MIN`, `MAX`, `COUNT` із `OVER`;
- `PARTITION BY` ділить результат на незалежні вікна (у межах одного запиту їх може бути кілька);
- `ORDER BY` усередині `OVER` задає порядок рядків у вікні; для агрегатних вікон він також вмикає накопичувальний режим;
- фрейм за замовчуванням — `RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW`: для `SUM(...) OVER (ORDER BY ...)` це накопичення, а рядки з однаковим ключем сортування додаються разом.

Віконні функції обчислюються після `WHERE`, `GROUP BY` і `HAVING`, тому їх не можна використовувати у `WHERE`. Щоб відфільтрувати результат вікна, оберніть запит у CTE або похідну таблицю.

## Приклади

### Приклад 1. ROW_NUMBER: нумерація замовлень у межах клієнта

```sql
SELECT o.id AS order_id,
       o.customer_id,
       o.ordered_at,
       ROW_NUMBER() OVER (
           PARTITION BY o.customer_id
           ORDER BY o.ordered_at
       ) AS rn
FROM orders o
ORDER BY o.customer_id, o.ordered_at;
```

```text
order_id | customer_id | ordered_at          | rn
1        | 1           | 2026-01-12 10:15:00 | 1
2        | 1           | 2026-01-12 10:20:00 | 2
3        | 2           | 2026-02-03 14:05:00 | 1
4        | 3           | 2026-02-18 09:40:00 | 1
5        | 4           | 2026-03-01 18:25:00 | 1
6        | 5           | 2026-03-14 12:00:00 | 1
```

`PARTITION BY o.customer_id` означає «лічильник скидається для кожного клієнта». `ROW_NUMBER` завжди дає унікальні послідовні номери; при однакових значеннях `ORDER BY` порядок між «пір'ями» недетермінований.

### Приклад 2. RANK і DENSE_RANK при однакових значеннях

```sql
SELECT id, quantity,
       ROW_NUMBER() OVER (ORDER BY quantity DESC) AS rn,
       RANK()       OVER (ORDER BY quantity DESC) AS rnk,
       DENSE_RANK() OVER (ORDER BY quantity DESC) AS dense_rnk
FROM orders
ORDER BY quantity DESC, id;
```

```text
id | quantity | rn | rnk | dense_rnk
2  | 2        | 1  | 1   | 1
4  | 2        | 2  | 1   | 1
1  | 1        | 3  | 3   | 2
3  | 1        | 4  | 3   | 2
5  | 1        | 5  | 3   | 2
6  | 1        | 6  | 3   | 2
```

`ROW_NUMBER` дає довільний порядок серед однакових `quantity`; `RANK` пропускає номери після нічиєї (1, 1, 3), `DENSE_RANK` не пропускає (1, 1, 2). Вибір функції залежить від питання: «місце з пропусками» — `RANK`, «щільний рейтинг» — `DENSE_RANK`.

### Приклад 3. Top-N у групі: останнє замовлення кожного клієнта

```sql
WITH ranked AS (
    SELECT o.*,
           ROW_NUMBER() OVER (
               PARTITION BY o.customer_id
               ORDER BY o.ordered_at DESC
           ) AS rn
    FROM orders o
)
SELECT c.full_name, p.title AS last_product, r.ordered_at
FROM ranked r
JOIN customers c ON c.id = r.customer_id
JOIN products p ON p.id = r.product_id
WHERE r.rn = 1
ORDER BY c.id;
```

```text
full_name          | last_product                | ordered_at
Олена Ковальчук    | Навушники Sony WH-CH720     | 2026-01-12 10:20:00
Ігор Мельник       | Смартфон Samsung Galaxy     | 2026-02-03 14:05:00
Марія Бондаренко   | Монітор Dell 24"            | 2026-02-18 09:40:00
Андрій Шевченко    | Клавіатура Logitech K380    | 2026-03-01 18:25:00
Софія Ткаченко     | Смартфон Samsung Galaxy     | 2026-03-14 12:00:00
```

Фільтр `WHERE r.rn = 1` не можна поставити в тому самому `SELECT`, де оголошено вікно: спершу CTE обчислює номери, потім зовнішній запит їх фільтрує. Для топ-2 змініть умову на `r.rn <= 2`.

### Приклад 4. LAG і LEAD: сусідні події

```sql
SELECT o.id AS order_id,
       o.customer_id,
       o.ordered_at,
       LAG(o.ordered_at)  OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at) AS prev_at,
       LEAD(o.ordered_at) OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at) AS next_at
FROM orders o
ORDER BY o.customer_id, o.ordered_at;
```

```text
order_id | customer_id | ordered_at          | prev_at             | next_at
1        | 1           | 2026-01-12 10:15:00 | NULL                | 2026-01-12 10:20:00
2        | 1           | 2026-01-12 10:20:00 | 2026-01-12 10:15:00 | NULL
3        | 2           | 2026-02-03 14:05:00 | NULL                | NULL
4        | 3           | 2026-02-18 09:40:00 | NULL                | NULL
5        | 4           | 2026-03-01 18:25:00 | NULL                | NULL
6        | 5           | 2026-03-14 12:00:00 | NULL                | NULL
```

У першого й останнього рядка вікна відповідно `LAG` і `LEAD` дають `NULL`: сусіднього рядка немає. Різниця в днях між замовленнями:

```sql
-- MySQL / MariaDB
SELECT o.id AS order_id,
       o.customer_id,
       o.ordered_at,
       DATEDIFF(o.ordered_at,
                LAG(o.ordered_at) OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at)
       ) AS days_since_prev
FROM orders o
ORDER BY o.customer_id, o.ordered_at;
```

```sql
-- PostgreSQL
SELECT o.id AS order_id,
       o.customer_id,
       o.ordered_at,
       o.ordered_at - LAG(o.ordered_at) OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at)
           AS since_prev_interval
FROM shop.orders o
ORDER BY o.customer_id, o.ordered_at;
```

`DATEDIFF` — функція MySQL/MariaDB; у PostgreSQL дати й мітки часу віднімають оператором `-`, отримуючи `interval` (наприклад, `00:05:00` для замовлень 1 і 2). Другого аргументу `DATEDIFF` у PostgreSQL немає.

### Приклад 5. Накопичувальна сума SUM OVER

```sql
SELECT o.id AS order_id,
       o.ordered_at,
       p.price * o.quantity AS amount,
       SUM(p.price * o.quantity) OVER (ORDER BY o.ordered_at, o.id) AS running_total
FROM orders o
JOIN products p ON p.id = o.product_id
ORDER BY o.ordered_at, o.id;
```

```text
order_id | ordered_at          | amount    | running_total
1        | 2026-01-12 10:15:00 | 24999.00  | 24999.00
2        | 2026-01-12 10:20:00 | 8598.00   | 33597.00
3        | 2026-02-03 14:05:00 | 18499.50  | 52096.50
4        | 2026-02-18 09:40:00 | 14998.00  | 67094.50
5        | 2026-03-01 18:25:00 | 1799.00   | 68893.50
6        | 2026-03-14 12:00:00 | 18499.50  | 87393.00
```

`ORDER BY` усередині `OVER` перетворює `SUM` на накопичення: до суми додається кожен наступний рядок. Порядок можна робити детермінованим, додавши другу колонку (`o.id`) — інакше при однакових мітках часу результат залежатиме від порядку рядків у фреймі.

### Приклад 6. PARTITION BY: підсумок клієнта в кожному рядку

```sql
SELECT o.id AS order_id,
       o.customer_id,
       p.price * o.quantity AS amount,
       SUM(p.price * o.quantity) OVER (PARTITION BY o.customer_id) AS customer_total,
       ROUND(100.0 * (p.price * o.quantity)
             / SUM(p.price * o.quantity) OVER (), 2) AS pct_of_total
FROM orders o
JOIN products p ON p.id = o.product_id
ORDER BY o.id;
```

```text
order_id | customer_id | amount    | customer_total | pct_of_total
1        | 1           | 24999.00  | 33597.00       | 28.61
2        | 1           | 8598.00   | 33597.00       | 9.84
3        | 2           | 18499.50  | 18499.50       | 21.17
4        | 3           | 14998.00  | 14998.00       | 17.16
5        | 4           | 1799.00   | 1799.00        | 2.06
6        | 5           | 18499.50  | 18499.50       | 21.17
```

`OVER ()` без `PARTITION BY` і `ORDER BY` охоплює всі рядки результату (загальна сума 87393.00), а `OVER (PARTITION BY customer_id)` — лише рядки відповідного клієнта. Сума `pct_of_total` трохи більша за 100 через округлення.

### Приклад 7. Фрейм: ковзне середнє

```sql
SELECT o.id AS order_id,
       o.ordered_at,
       p.price * o.quantity AS amount,
       ROUND(AVG(p.price * o.quantity) OVER (
           ORDER BY o.ordered_at, o.id
           ROWS BETWEEN 1 PRECEDING AND CURRENT ROW
       ), 2) AS avg_last_two
FROM orders o
JOIN products p ON p.id = o.product_id
ORDER BY o.ordered_at, o.id;
```

```text
order_id | ordered_at          | amount    | avg_last_two
1        | 2026-01-12 10:15:00 | 24999.00  | 24999.00
2        | 2026-01-12 10:20:00 | 8598.00   | 16798.50
3        | 2026-02-03 14:05:00 | 18499.50  | 13548.75
4        | 2026-02-18 09:40:00 | 14998.00  | 16748.75
5        | 2026-03-01 18:25:00 | 1799.00   | 8398.50
6        | 2026-03-14 12:00:00 | 18499.50  | 10149.25
```

`ROWS BETWEEN 1 PRECEDING AND CURRENT ROW` задає вікно з двох рядків: поточного та попереднього. Типове вікно агрегатних функцій ширше — `RANGE UNBOUNDED PRECEDING AND CURRENT ROW`, тому без явного фрейму `AVG(...) OVER (ORDER BY ...)` рахувало б середнє від початку до поточного рядка.

### Приклад 8. GROUP BY проти OVER

```sql
-- один рядок на групу, кількість рядків зменшується
SELECT c.city, COUNT(*) AS orders_count
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city
ORDER BY orders_count DESC, c.city;
```

```sql
-- рядки зберігаються, агрегат по всій вибірці додається до кожного
SELECT c.city, COUNT(*) AS orders_count,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (), 1) AS pct
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city
ORDER BY orders_count DESC, c.city;
```

```text
city   | orders_count | pct
Київ   | 2            | 33.3
Дніпро | 1            | 16.7
Львів  | 1            | 16.7
Одеса  | 1            | 16.7
Харків | 1            | 16.7
```

У другому запиті `GROUP BY` все ще згортає рядки до міст, але `SUM(COUNT(*)) OVER ()` додає загальну кількість (6) як окреме вікно поверх агрегату. Так рахують частки й відсотки без підзапитів.

### Приклад 9. MariaDB: нумерація та підсумок у межах автора

```sql
SELECT a.name AS author,
       b.title,
       b.pages,
       ROW_NUMBER() OVER (PARTITION BY b.author_id ORDER BY b.pages DESC) AS rn,
       SUM(b.pages) OVER (PARTITION BY b.author_id) AS author_total_pages
FROM books b
JOIN authors a ON a.id = b.author_id
ORDER BY a.name, rn;
```

```text
author            | title           | pages | rn | author_total_pages
Джордж Орвелл     | 1984            | 328   | 1  | 440
Джордж Орвелл     | Колгосп тварин  | 112   | 2  | 440
Ернест Хемінгуей  | Старий і море   | 128   | 1  | 128
Іван Франко       | Захар Беркут    | 280   | 1  | 280
Леся Українка     | Лісова пісня    | 160   | 1  | 160
Тарас Шевченко    | Кобзар          | 320   | 1  | 320
```

Синтаксис ідентичний MySQL і PostgreSQL; відрізняється лише набір даних (MariaDB у цьому стенді містить `authors` і `books`).

### Приклад 10. NTILE і WINDOW: іменовані вікна

```sql
SELECT title, price,
       NTILE(2) OVER (ORDER BY price DESC) AS bucket
FROM products
ORDER BY price DESC;
```

```text
title                      | price     | bucket
Ноутбук Lenovo IdeaPad     | 24999.00  | 1
Смартфон Samsung Galaxy    | 18499.50  | 1
Монітор Dell 24"           | 7499.00   | 1
Навушники Sony WH-CH720    | 4299.00   | 2
Клавіатура Logitech K380   | 1799.00   | 2
```

`NTILE(2)` ділить відсортований набір на дві максимально рівні частини (5 рядків → 3 і 2). Якщо те саме вікно потрібне кільком функціям, його описують один раз у клаузі `WINDOW` (підтримують MySQL 8 і PostgreSQL; MariaDB 10.2+ також):

```sql
SELECT o.id, o.customer_id, o.ordered_at,
       ROW_NUMBER() OVER w AS rn,
       LAG(o.ordered_at) OVER w AS prev_at
FROM orders o
WINDOW w AS (PARTITION BY o.customer_id ORDER BY o.ordered_at)
ORDER BY o.customer_id, o.ordered_at;
```

## Типові помилки

1. **Віконна функція у `WHERE` або `HAVING`.** `WHERE ROW_NUMBER() OVER (...) = 1` не виконається: MySQL скаже `You cannot use the window function 'row_number' in this context`, PostgreSQL — `window functions are not allowed in WHERE`. Оберніть у CTE й фільтруйте зовні.
2. **`ROW_NUMBER` замість `RANK`.** Якщо потрібні «однакові місця», `ROW_NUMBER` роздасть різні номери й вибір «топ-1» буде довільним при нічиїй. Для нічиїх використовуйте `RANK`/`DENSE_RANK`, а для детермінованого вибору додайте унікальну колонку в `ORDER BY`.
3. **Забутий `ORDER BY` у вікні.** Для `ROW_NUMBER`, `LAG`, `LEAD` і накопичувальних агрегатів порядок визначає сенс результату. Запит без `ORDER BY` виконається, але номери та зсуви залежатимуть від випадкового порядку читання рядків, тому `ORDER BY` усередині `OVER` задавайте завжди.
4. **Плутанина фреймів `ROWS` і `RANGE`.** При однакових ключах `ORDER BY` режим `RANGE` включає у фрейм усі рядки-«пір'я» одразу, а `ROWS` — по одному в порядку читання. Для накопичення по одному рядку явно пишіть `ROWS BETWEEN ...`.
5. **Дублювання рядків.** На відміну від `GROUP BY`, віконна функція не зменшує кількість рядків. Якщо потрібен один рядок на групу (звіт), комбінуйте з `GROUP BY` або `DISTINCT`.
6. **`FILTER` і `QUALIFY`.** `QUALIFY` (фільтр вікон) у MySQL, MariaDB і PostgreSQL відсутній; `FILTER (WHERE ...)` для агрегатів є в PostgreSQL, але не в MySQL/MariaDB. Тримайтеся переносимого підходу через CTE.

## Вправи

1. Для кожного замовлення виведіть `id`, `customer_id`, `ordered_at` і порядковий номер у межах клієнта за датою (від найдавнішого).
   Підказка: `ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at)`.

2. Виведіть найдорожче замовлення кожного клієнта (сума `price * quantity`): ім'я клієнта, товар, сума.
   Підказка: CTE з `ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY p.price * o.quantity DESC)`, зовні `rn = 1`.

3. Обчисліть накопичувальну виручку за часом для всіх замовлень.
   Підказка: `SUM(p.price * o.quantity) OVER (ORDER BY o.ordered_at, o.id)`.

4. Для кожного товару виведіть ціну, середню ціну всіх товарів та відхилення від середньої.
   Підказка: `AVG(price) OVER ()`; округліть через `ROUND`.
5. Для кожного автора пронумеруйте книги за спаданням сторінок і покажіть частку сторінок книги від суми сторінок автора у відсотках (MariaDB).
   Підказка: `ROW_NUMBER() OVER (PARTITION BY b.author_id ORDER BY b.pages DESC)` і `100.0 * b.pages / SUM(b.pages) OVER (PARTITION BY b.author_id)`.

## Відповіді

1.

```sql
SELECT o.id AS order_id,
       o.customer_id,
       o.ordered_at,
       ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at) AS rn
FROM orders o
ORDER BY o.customer_id, o.ordered_at;
```

Результат: у клієнта 1 — замовлення 1 (rn=1) і 2 (rn=2), у решти — по одному замовленню з rn=1.

2.

```sql
WITH ranked AS (
    SELECT o.customer_id,
           p.title,
           p.price * o.quantity AS amount,
           ROW_NUMBER() OVER (
               PARTITION BY o.customer_id
               ORDER BY p.price * o.quantity DESC, o.id
           ) AS rn
    FROM orders o
    JOIN products p ON p.id = o.product_id
)
SELECT c.full_name, r.title, r.amount
FROM ranked r
JOIN customers c ON c.id = r.customer_id
WHERE r.rn = 1
ORDER BY r.amount DESC;
```

Результат: Олена — «Ноутбук Lenovo IdeaPad» (24999.00), Ігор — «Смартфон Samsung Galaxy» (18499.50), Марія — «Монітор Dell 24"» (14998.00), Андрій — «Клавіатура Logitech K380» (1799.00), Софія — «Смартфон Samsung Galaxy» (18499.50).

3.

```sql
SELECT o.id AS order_id,
       o.ordered_at,
       p.price * o.quantity AS amount,
       SUM(p.price * o.quantity) OVER (ORDER BY o.ordered_at, o.id) AS running_total
FROM orders o
JOIN products p ON p.id = o.product_id
ORDER BY o.ordered_at, o.id;
```

Накопичення: 24999.00, 33597.00, 52096.50, 67094.50, 68893.50, 87393.00.

4.

```sql
SELECT title,
       price,
       ROUND(AVG(price) OVER (), 2) AS avg_price,
       ROUND(price - AVG(price) OVER (), 2) AS diff
FROM products
ORDER BY price DESC;
```

Середня ціна — 11419.10. Відхилення: «Ноутбук» +13579.90, «Смартфон» +7080.40, «Монітор» −3920.10, «Навушники» −7120.10, «Клавіатура» −9620.10.

5.

```sql
SELECT a.name AS author,
       b.title,
       b.pages,
       ROW_NUMBER() OVER (PARTITION BY b.author_id ORDER BY b.pages DESC) AS rn,
       ROUND(100.0 * b.pages / SUM(b.pages) OVER (PARTITION BY b.author_id), 1) AS pct
FROM books b
JOIN authors a ON a.id = b.author_id
ORDER BY a.name, rn;
```

Результат для Джорджа Орвелла: «1984» — 74.5 %, «Колгосп тварин» — 25.5 % (328 і 112 від 440 сторінок). Для авторів з однією книгою — 100.0 %.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
