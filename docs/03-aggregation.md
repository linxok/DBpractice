# 03. GROUP BY, HAVING та агрегатні функції

Агрегатні функції стискають багато рядків в один підсумок: скільки замовлень, яка сумарна виручка, середня ціна. `GROUP BY` ділить рядки на групи й обчислює агрегат для кожної, а `HAVING` фільтрує самі групи. Тема охоплює `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, групування за колонками, відмінність `WHERE` від `HAVING` і агрегацію рядків `GROUP_CONCAT` (MySQL/MariaDB) проти `STRING_AGG` (PostgreSQL).

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
SELECT column_or_expression, AGG(column) AS alias
FROM table_name
[WHERE row_conditions]
GROUP BY column_or_expression
[HAVING group_conditions]
[ORDER BY ...]
[LIMIT ...];
```

Порядок обробки: `WHERE` фільтрує рядки **до** групування, `GROUP BY` формує групи, агрегати обчислюються для груп, `HAVING` відкидає групи **після** агрегації. В `SELECT` можна виводити або колонки з `GROUP BY`, або агрегати.

## Приклади

### Приклад 1. COUNT: три різні питання

```sql
SELECT COUNT(*)                  AS orders_total,
       COUNT(customer_id)        AS orders_with_customer,
       COUNT(DISTINCT customer_id) AS unique_customers
FROM orders;

SELECT COUNT(*)     AS customers_total,
       COUNT(city)  AS cities_filled
FROM customers;
```

```text
orders_total | orders_with_customer | unique_customers
6            | 6                    | 5

customers_total | cities_filled
5               | 5
```

`COUNT(*)` рахує рядки, `COUNT(column)` — рядки, де колонка не `NULL`, `COUNT(DISTINCT column)` — унікальні непорожні значення. У навчальних даних `city` заповнене в усіх клієнтів, тому `COUNT(city)` = 5; якщо додати клієнта з `NULL` у місті, `COUNT(*)` дасть 6, а `COUNT(city)` — 5.

### Приклад 2. GROUP BY за однією колонкою

```sql
SELECT customer_id,
       COUNT(*)       AS orders_count,
       SUM(quantity)  AS items
FROM orders
GROUP BY customer_id
ORDER BY customer_id;
```

```text
customer_id | orders_count | items
1           | 2            | 3
2           | 1            | 1
3           | 1            | 2
4           | 1            | 1
5           | 1            | 1
```

Кожен рядок результату — одна група. Клієнт 1 (Олена) має два замовлення із сумарною кількістю 3 одиниці.

### Приклад 3. GROUP BY + JOIN: замовлення за містами

```sql
SELECT c.city,
       COUNT(o.id)      AS orders_count,
       SUM(o.quantity)  AS items
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city
ORDER BY orders_count DESC, c.city;
```

```text
city   | orders_count | items
Київ   | 2            | 3
Дніпро | 1            | 1
Львів  | 1            | 1
Одеса  | 1            | 2
Харків | 1            | 1
```

`JOIN` виконується до групування: спершу кожне замовлення отримує місто клієнта, потім рядки групуються за містом. Для PostgreSQL додайте схему: `FROM shop.orders o JOIN shop.customers c ...`.

### Приклад 4. SUM, AVG, MIN, MAX

```sql
SELECT COUNT(*)              AS products,
       SUM(price)            AS sum_price,
       ROUND(AVG(price), 2)  AS avg_price,
       MIN(price)            AS min_price,
       MAX(price)            AS max_price
FROM products;
```

```text
products | sum_price | avg_price | min_price | max_price
5        | 57095.50  | 11419.10  | 1799.00   | 24999.00
```

Агрегати повертають одне число на всю таблицю, якщо немає `GROUP BY`. Приклад із групуванням за містом:

```sql
SELECT c.city,
       COUNT(o.id)                     AS orders_count,
       SUM(p.price * o.quantity)       AS revenue,
       ROUND(AVG(p.price * o.quantity), 2) AS avg_order_amount
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
GROUP BY c.city
ORDER BY revenue DESC;
```

```text
city   | orders_count | revenue  | avg_order_amount
Київ   | 2            | 33597.00 | 16798.50
Львів  | 1            | 18499.50 | 18499.50
Дніпро | 1            | 18499.50 | 18499.50
Одеса  | 1            | 14998.00 | 14998.00
Харків | 1            | 1799.00  | 1799.00
```

`AVG` ігнорує `NULL`; якщо група порожня, `SUM` повертає `NULL` (не 0), тому для звітів використовують `COALESCE(SUM(...), 0)`.

### Приклад 5. HAVING: фільтр груп

```sql
SELECT customer_id, COUNT(*) AS orders_count
FROM orders
GROUP BY customer_id
HAVING COUNT(*) > 1;
```

```text
customer_id | orders_count
1           | 2
```

`HAVING` працює з результатами агрегатів. Умова на «сирі» рядки, навпаки, належить `WHERE`:

```sql
SELECT customer_id, SUM(quantity) AS items
FROM orders
WHERE ordered_at >= '2026-02-01'   -- фільтр рядків до групування
GROUP BY customer_id
HAVING SUM(quantity) >= 1          -- фільтр груп після групування
ORDER BY items DESC, customer_id;
```

```text
customer_id | items
3           | 2
2           | 1
4           | 1
5           | 1
```

Клієнт 1 не потрапив у результат, бо всі його замовлення — січневі.

### Приклад 6. HAVING із JOIN: міста з високою середньою ціною товару

```sql
SELECT c.city, ROUND(AVG(p.price), 2) AS avg_price
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
GROUP BY c.city
HAVING AVG(p.price) > 10000
ORDER BY avg_price DESC;
```

```text
city   | avg_price
Львів  | 18499.50
Дніпро | 18499.50
Київ   | 14649.00
```

Для міст Одеса (7499.00) і Харків (1799.00) середня ціна нижча за поріг, тому `HAVING` їх відкинув.

### Приклад 7. GROUP_CONCAT проти STRING_AGG

```sql
-- MySQL / MariaDB
SELECT c.city,
       GROUP_CONCAT(c.full_name ORDER BY c.full_name SEPARATOR '; ') AS customers
FROM customers c
GROUP BY c.city
ORDER BY c.city;
```

```sql
-- PostgreSQL
SELECT c.city,
       STRING_AGG(c.full_name, '; ' ORDER BY c.full_name) AS customers
FROM shop.customers c
GROUP BY c.city
ORDER BY c.city;
```

```text
city   | customers
Дніпро | Софія Ткаченко
Київ   | Олена Ковальчук
Львів  | Ігор Мельник
Одеса  | Марія Бондаренко
Харків | Андрій Шевченко
```

Відмінності:

- `GROUP_CONCAT` типово розділяє значення комою; `SEPARATOR` задає свій розділювач. У `STRING_AGG` розділювач — обов'язковий другий аргумент.
- `ORDER BY` усередині виклику: у `GROUP_CONCAT` пишеться між колонкою та `SEPARATOR`, у `STRING_AGG` — після розділювача.
- `GROUP_CONCAT` обмежений довжиною `group_concat_max_len` (типово 1024 байти) — довгий список обрізається; у PostgreSQL обмеження немає.

Щоб побачити ефект групування з кількома значеннями, зберіть товари в межах замовлення:

```sql
SELECT o.id AS order_id,
       GROUP_CONCAT(p.title ORDER BY p.title SEPARATOR ', ') AS products
FROM orders o
JOIN products p ON p.id = o.product_id
GROUP BY o.id
ORDER BY o.id;
```

### Приклад 8. LEFT JOIN + GROUP BY: нулі для порожніх груп

```sql
SELECT p.id, p.title,
       COUNT(o.id)                    AS times_ordered,
       COALESCE(SUM(o.quantity), 0)   AS units
FROM products p
LEFT JOIN orders o ON o.product_id = p.id
GROUP BY p.id, p.title
ORDER BY units DESC, p.id;
```

```text
id | title                      | times_ordered | units
2  | Смартфон Samsung Galaxy    | 2             | 2
3  | Навушники Sony WH-CH720    | 1             | 2
5  | Монітор Dell 24"           | 1             | 2
1  | Ноутбук Lenovo IdeaPad     | 1             | 1
4  | Клавіатура Logitech K380   | 1             | 1
```

Якби якийсь товар не замовляли, він усе одно залишився б у звіті з `times_ordered = 0`, бо `LEFT JOIN` зберігає ліву таблицю. `SUM` для такої групи дав би `NULL`, тому його загортають у `COALESCE`.

### Приклад 9. MariaDB: статистика авторів

```sql
SELECT a.name,
       COUNT(b.id)              AS books_count,
       COALESCE(SUM(b.pages), 0) AS total_pages,
       ROUND(AVG(b.pages), 1)   AS avg_pages
FROM authors a
LEFT JOIN books b ON b.author_id = a.id
GROUP BY a.id, a.name
ORDER BY total_pages DESC, a.name;
```

```text
name              | books_count | total_pages | avg_pages
Джордж Орвелл     | 2           | 440         | 220.0
Тарас Шевченко    | 1           | 320         | 320.0
Іван Франко       | 1           | 280         | 280.0
Леся Українка     | 1           | 160         | 160.0
Ернест Хемінгуей  | 1           | 128         | 128.0
```

`GROUP BY a.id, a.name` перелічує і ключ, і відображувану колонку — це сумісно з режимом `ONLY_FULL_GROUP_BY` (див. приклад 10).

### Приклад 10. ONLY_FULL_GROUP_BY та діалектні відмінності

```sql
SELECT c.city, c.full_name, COUNT(o.id) AS orders_count
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city;
```

У MySQL 8.4 режим `ONLY_FULL_GROUP_BY` увімкнений типово, тому запит завершиться помилкою:

```text
ERROR 1055 (42000): Expression #2 of SELECT list is not in GROUP BY clause
and contains nonaggregated column 'learn.c.full_name' which is not functionally
dependent on columns in GROUP BY clause; this is incompatible with
sql_mode=only_full_group_by
```

PostgreSQL теж відхилить запит:

```text
ERROR: column "c.full_name" must appear in the GROUP BY clause
or be used in an aggregate function
```

MariaDB 11.4 не вмикає `ONLY_FULL_GROUP_BY` типово й поверне результат, вибравши з групи довільний рядок — це небезпечно непомітною некоректністю. Правильні варіанти:

```sql
-- якщо потрібне конкретне значення з групи
SELECT c.city, MAX(c.full_name) AS example_customer, COUNT(o.id) AS orders_count
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city;

-- якщо потрібні всі рядки — групуйте за обома колонками
SELECT c.city, c.full_name, COUNT(o.id) AS orders_count
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city, c.full_name;
```

## Типові помилки

1. **Агрегат у `WHERE`.** `WHERE COUNT(*) > 1` — синтаксична помилка: `WHERE` виконується до групування. Умови на агрегати пишіть у `HAVING`.
2. **Неагрегована колонка без `GROUP BY`.** У MySQL 8/PG це помилка (`ONLY_FULL_GROUP_BY`), у MariaDB — тихий вибір довільного рядка. Додайте колонку в `GROUP BY` або загорніть в агрегат.
3. **`HAVING` замість `WHERE` для звичайних умов.** `HAVING city = 'Київ'` працює, але змушує групувати зайві рядки; фільтри рядків завжди ставте у `WHERE`.
4. **`COUNT(*)` замість `COUNT(col)` після `LEFT JOIN`.** Рядки-«сироти» з `NULL` рахуються як 1 — клієнт без замовлень отримує 1 замовлення.
5. **`SUM` і `AVG` по дубльованих рядках.** Після з'єднання «один-до-багатьох» рядки розмножуються, і сума завищується. Перевіряйте `COUNT(*)` до агрегації та згадуйте про `DISTINCT` усередині агрегату.
6. **Порожня група й `NULL`.** `SUM`, `AVG`, `MIN`, `MAX` повертають `NULL` для групи без значень; використовуйте `COALESCE`, якщо звіт має показувати 0.

## Вправи

1. Порахуйте для кожного клієнта кількість замовлень і сумарну кількість одиниць (`SUM(quantity)`), відсортуйте за кількістю одиниць спадно.
   Підказка: `GROUP BY customer_id` у таблиці `orders`.

2. Виведіть для кожного міста кількість замовлень, відсортувавши за спаданням; міста без замовлень не потрібні.
   Підказка: `JOIN customers`, `GROUP BY c.city`.

3. Виведіть товари, які замовляли більше ніж один раз (`times_ordered >= 2`).
   Підказка: `LEFT JOIN` + `GROUP BY p.id, p.title` + `HAVING COUNT(o.id) >= 2`.

4. Для кожного автора виведіть кількість книг і сумарну кількість сторінок, включно з авторами без книг (MariaDB).
   Підказка: `LEFT JOIN books` і `COALESCE(SUM(b.pages), 0)`.

5. Обчисліть виручку за місяцями (`SUM(p.price * o.quantity)`) і виведіть місяць із найбільшою виручкою. Дату форматуйте як `РРРР-ММ`: `DATE_FORMAT` у MySQL/MariaDB, `to_char` у PostgreSQL.
   Підказка: `GROUP BY` за виразом форматування, `ORDER BY revenue DESC LIMIT 1`.

## Відповіді

1.

```sql
SELECT customer_id,
       COUNT(*)      AS orders_count,
       SUM(quantity) AS items
FROM orders
GROUP BY customer_id
ORDER BY items DESC, customer_id;
```

Результат: клієнт 1 — 2 замовлення, 3 одиниці; клієнти 2, 3, 4, 5 — по 1 замовленню (1, 2, 1, 1 одиниця відповідно).

2.

```sql
SELECT c.city, COUNT(o.id) AS orders_count
FROM orders o
JOIN customers c ON c.id = o.customer_id
GROUP BY c.city
ORDER BY orders_count DESC, c.city;
```

Результат: Київ — 2, Дніпро/Львів/Одеса/Харків — по 1. PostgreSQL: додайте `shop.` до назв таблиць.

3.

```sql
SELECT p.id, p.title, COUNT(o.id) AS times_ordered
FROM products p
LEFT JOIN orders o ON o.product_id = p.id
GROUP BY p.id, p.title
HAVING COUNT(o.id) >= 2
ORDER BY times_ordered DESC, p.id;
```

Результат: «Смартфон Samsung Galaxy» — 2.

4.

```sql
SELECT a.name,
       COUNT(b.id)               AS books_count,
       COALESCE(SUM(b.pages), 0) AS total_pages
FROM authors a
LEFT JOIN books b ON b.author_id = a.id
GROUP BY a.id, a.name
ORDER BY total_pages DESC, a.name;
```

Результат: Джордж Орвелл — 2 книги, 440 сторінок; Тарас Шевченко — 320; Іван Франко — 280; Леся Українка — 160; Ернест Хемінгуей — 128.

5.

```sql
-- MySQL / MariaDB
SELECT DATE_FORMAT(o.ordered_at, '%Y-%m') AS month,
       SUM(p.price * o.quantity)          AS revenue
FROM orders o
JOIN products p ON p.id = o.product_id
GROUP BY DATE_FORMAT(o.ordered_at, '%Y-%m')
ORDER BY revenue DESC
LIMIT 1;
```

```sql
-- PostgreSQL
SELECT to_char(o.ordered_at, 'YYYY-MM') AS month,
       SUM(p.price * o.quantity)        AS revenue
FROM shop.orders o
JOIN shop.products p ON p.id = o.product_id
GROUP BY to_char(o.ordered_at, 'YYYY-MM')
ORDER BY revenue DESC
LIMIT 1;
```

Результат: `2026-01` із виручкою 33597.00 (замовлення 1 і 2). Для довідки: лютий — 33497.50, березень — 20298.50.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
