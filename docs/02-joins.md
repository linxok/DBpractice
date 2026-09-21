# 02. JOIN: з'єднання таблиць

Нормалізовані дані лежать у кількох таблицях: замовлення зберігає лише `customer_id` і `product_id`, а імена клієнтів і назви товарів — в окремих таблицях. `JOIN` збирає повний рядок із кількох таблиць за спільною колонкою. Тема охоплює `INNER`, `LEFT`, `RIGHT`, `FULL JOIN`, самооб'єднання, з'єднання трьох і більше таблиць та типові помилки.

## Дані стенду

```text
MySQL 8.4, база learn
customers(id, full_name, email, city, created_at) — 5 клієнтів
products (id, title, price, stock)                — 5 товарів
orders   (id, customer_id, product_id, quantity, ordered_at) — 6 замовлень

MariaDB 11.4, база learn
authors(id, name, country) — 5 авторів
books(id, author_id, title, published_year, pages) — 6 книг

PostgreSQL 17, база learn, схема shop
customers, products, orders — ті самі колонки й дані
```

У PostgreSQL таблиці лежать у схемі `shop`; у запитах нижче префікс указано явно.

## Синтаксис

```sql
SELECT columns
FROM left_table AS l
[INNER | LEFT [OUTER] | RIGHT [OUTER] | FULL [OUTER]] JOIN right_table AS r
  ON l.key_column = r.key_column
[WHERE conditions]
[ORDER BY ...];
```

- `INNER JOIN` (те саме, що `JOIN`) — лише рядки, для яких знайшлася пара в обох таблицях.
- `LEFT JOIN` — усі рядки лівої таблиці; для «сиріт» права частина заповнюється `NULL`.
- `RIGHT JOIN` — дзеркальне відображення `LEFT JOIN`.
- `FULL OUTER JOIN` — усі рядки з обох боків; у MySQL і MariaDB не підтримується та імітується через `UNION`.
- `CROSS JOIN` — декартів добуток без умови; використовується рідко.

## Приклади

### Приклад 1. INNER JOIN: замовлення та клієнти

```sql
SELECT o.id AS order_id, c.full_name, o.quantity, o.ordered_at
FROM orders o
JOIN customers c ON c.id = o.customer_id
ORDER BY o.id;
```

```text
order_id | full_name          | quantity | ordered_at
1        | Олена Ковальчук    | 1        | 2026-01-12 10:15:00
2        | Олена Ковальчук    | 2        | 2026-01-12 10:20:00
3        | Ігор Мельник       | 1        | 2026-02-03 14:05:00
4        | Марія Бондаренко   | 2        | 2026-02-18 09:40:00
5        | Андрій Шевченко    | 1        | 2026-03-01 18:25:00
6        | Софія Ткаченко     | 1        | 2026-03-14 12:00:00
```

Аліаси `o` і `c` скорочують записи та прибирають неоднозначність: колонка `id` є в обох таблицях. Для PostgreSQL той самий запит із таблицями `shop.orders` і `shop.customers`.

### Приклад 2. З'єднання трьох таблиць

```sql
SELECT o.id AS order_id,
       c.full_name AS customer,
       p.title AS product,
       o.quantity,
       p.price * o.quantity AS amount,
       o.ordered_at
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
ORDER BY amount DESC, o.id;
```

```text
order_id | customer          | product                      | quantity | amount
1        | Олена Ковальчук   | Ноутбук Lenovo IdeaPad       | 1        | 24999.00
3        | Ігор Мельник      | Смартфон Samsung Galaxy      | 1        | 18499.50
6        | Софія Ткаченко    | Смартфон Samsung Galaxy      | 1        | 18499.50
4        | Марія Бондаренко  | Монітор Dell 24"             | 2        | 14998.00
2        | Олена Ковальчук   | Навушники Sony WH-CH720      | 2        | 8598.00
5        | Андрій Шевченко   | Клавіатура Logitech K380     | 1        | 1799.00
```

Ланцюжок `JOIN` нарощується зліва направо: кожен наступний `JOIN` приєднує ще одну таблицю до вже зібраного результату.

### Приклад 3. LEFT JOIN: усі клієнти, навіть без замовлень

```sql
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
ORDER BY c.id, o.ordered_at;
```

```text
full_name          | order_id | ordered_at
Олена Ковальчук    | 1        | 2026-01-12 10:15:00
Олена Ковальчук    | 2        | 2026-01-12 10:20:00
Ігор Мельник       | 3        | 2026-02-03 14:05:00
Марія Бондаренко   | 4        | 2026-02-18 09:40:00
Андрій Шевченко    | 5        | 2026-03-01 18:25:00
Софія Ткаченко     | 6        | 2026-03-14 12:00:00
```

У поточних даних замовлення мають усі п'ятеро клієнтів, тому `NULL` не видно. Додайте клієнта через Adminer і повторіть запит — він з'явиться з `NULL` у колонках правої таблиці. Різницю добре показує додаткова умова в `ON`:

```sql
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM customers c
LEFT JOIN orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-02-01'
ORDER BY c.id, o.ordered_at;
```

```text
full_name          | order_id | ordered_at
Олена Ковальчук    | NULL     | NULL
Ігор Мельник       | 3        | 2026-02-03 14:05:00
Марія Бондаренко   | 4        | 2026-02-18 09:40:00
Андрій Шевченко    | 5        | 2026-03-01 18:25:00
Софія Ткаченко     | 6        | 2026-03-14 12:00:00
```

Умова в `ON` фільтрує лише праву таблицю до з'єднання, тому Олена залишається в результаті з `NULL`. Якщо перенести ту саму умову в `WHERE`, вона відфільтрує вже зібрані рядки й перетворить `LEFT JOIN` на `INNER JOIN`:

```sql
SELECT c.full_name, o.id AS order_id
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
WHERE o.ordered_at >= '2026-02-01'
ORDER BY c.id;   -- 4 рядки: Олена "зникла"
```

### Приклад 4. RIGHT JOIN

```sql
SELECT c.full_name, o.id AS order_id
FROM orders o
RIGHT JOIN customers c ON c.id = o.customer_id
ORDER BY c.id, o.id;
```

Результат ідентичний `LEFT JOIN` з попереднього прикладу: `RIGHT JOIN` зберігає всі рядки правої таблиці (`customers`). На практиці `RIGHT JOIN` майже завжди переписують на `LEFT`, помінявши таблиці місцями — так запит читається зліва направо. Зворотний порядок дає всі замовлення з даними клієнта:

```sql
SELECT c.full_name, o.id AS order_id
FROM customers c
RIGHT JOIN orders o ON o.customer_id = c.id
ORDER BY o.id;   -- еквівалент customers JOIN orders
```

### Приклад 5. FULL OUTER JOIN і його емуляція

PostgreSQL підтримує `FULL OUTER JOIN` нативно:

```sql
-- PostgreSQL
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM shop.customers c
FULL OUTER JOIN shop.orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-02-01'
ORDER BY c.id NULLS LAST, o.ordered_at;
```

Результат: 5 рядків клієнтів (Олена — з `NULL`), 4 з яких мають замовлення, плюс 2 січневі замовлення з `NULL` у колонці клієнта — разом 7 рядків. Умова `NULLS LAST` — можливість PostgreSQL; MySQL/MariaDB сортують `NULL` попереду при `ASC`.

MySQL і MariaDB не мають `FULL OUTER JOIN`, тому його імітують об'єднанням лівого та правого з'єднань, де друге бере лише «сироти»:

```sql
-- MySQL / MariaDB: шаблон емуляції FULL OUTER JOIN
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM customers c
LEFT JOIN orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-02-01'
UNION
SELECT c.full_name, o.id, o.ordered_at
FROM customers c
RIGHT JOIN orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-02-01'
WHERE c.id IS NULL;
```

`UNION` (без `ALL`) прибирає дублікати рядків, які вже повернула ліва частина. Без додаткової умови `AND o.ordered_at >= ...` шаблон той самий — змінюється лише `ON`.

### Приклад 6. Самооб'єднання (self join)

Таблиця з'єднується сама із собою під двома різними аліасами. Знайдемо пари замовлень одного клієнта:

```sql
SELECT o1.customer_id,
       o1.id AS order_a,
       o2.id AS order_b,
       o1.ordered_at AS at_a,
       o2.ordered_at AS at_b
FROM orders o1
JOIN orders o2
  ON o1.customer_id = o2.customer_id
 AND o1.id < o2.id
ORDER BY o1.customer_id, order_a;
```

```text
customer_id | order_a | order_b | at_a                | at_b
1           | 1       | 2       | 2026-01-12 10:15:00 | 2026-01-12 10:20:00
```

Умова `o1.id < o2.id` прибирає зайві дзеркальні пари: без неї кожна пара повернулася б двічі, а кожне замовлення утворило б пару саме з собою. Другий варіант самооб'єднання — пошук клієнтів з однаковим містом:

```sql
SELECT a.full_name AS first_client, b.full_name AS second_client, a.city
FROM customers a
JOIN customers b
  ON a.city = b.city
 AND a.id < b.id
ORDER BY a.city;
```

У навчальних даних усі міста різні, тому запит поверне 0 рядків — це очікувано й показує, що самооб'єднання працює лише за наявності збігів.

### Приклад 7. LEFT JOIN + агрегація: кількість замовлень кожного клієнта

```sql
SELECT c.full_name,
       COUNT(o.id) AS orders_count,
       COALESCE(SUM(o.quantity), 0) AS items
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
GROUP BY c.id, c.full_name
ORDER BY orders_count DESC, c.full_name;
```

```text
full_name          | orders_count | items
Олена Ковальчук    | 2            | 3
Андрій Шевченко    | 1            | 1
Ігор Мельник       | 1            | 1
Марія Бондаренко   | 1            | 2
Софія Ткаченко     | 1            | 1
```

Тут важливо рахувати `COUNT(o.id)`, а не `COUNT(*)`: для клієнта без замовлень `LEFT JOIN` дає один рядок із `NULL`, і `COUNT(*)` помилково повернув би 1, тоді як `COUNT(o.id)` рахує лише непорожні значення й повертає 0.

### Приклад 8. Анти-з'єднання: хто нічого не замовив

```sql
SELECT c.full_name
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
WHERE o.id IS NULL;
```

У поточних даних результат порожній — усі клієнти мають замовлення. Шаблон `LEFT JOIN ... WHERE right.id IS NULL` називають анти-з'єднанням: він повертає рядки лівої таблиці без пари справа. Еквівалент через `NOT EXISTS` розглядається в конспекті 04.

### Приклад 9. Фільтри обох таблиць у тритабличному з'єднанні

```sql
SELECT o.id AS order_id, c.city, c.full_name, p.title, o.quantity
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
WHERE c.city IN ('Київ', 'Одеса')
ORDER BY o.ordered_at;
```

```text
order_id | city  | full_name         | title                   | quantity
1        | Київ  | Олена Ковальчук   | Ноутбук Lenovo IdeaPad  | 1
2        | Київ  | Олена Ковальчук   | Навушники Sony WH-CH720 | 2
4        | Одеса | Марія Бондаренко  | Монітор Dell 24"        | 2
```

Умови у `WHERE` бачать усі таблиці зібраного результату, тому фільтрувати можна за будь-якою з них.

### Приклад 10. MariaDB: автори та книги

```sql
SELECT a.name AS author, a.country, b.title AS book, b.published_year
FROM authors a
LEFT JOIN books b ON b.author_id = a.id
ORDER BY a.name, b.published_year;
```

```text
author               | country         | book            | published_year
Джордж Орвелл        | Велика Британія | Колгосп тварин  | 1945
Джордж Орвелл        | Велика Британія | 1984            | 1949
Ернест Хемінгуей     | США             | Старий і море   | 1952
Іван Франко          | Україна         | Захар Беркут    | 1883
Леся Українка        | Україна         | Лісова пісня    | 1911
Тарас Шевченко       | Україна         | Кобзар          | 1840
```

Той самий `LEFT JOIN` у PostgreSQL виглядав би як `FROM shop.authors a LEFT JOIN shop.books b ...`. Якщо додати автора без книг, він з'явиться в результаті з `NULL` у колонках книги.

## Типові помилки

1. **Забутий `ON`.** `FROM orders o JOIN customers c` без умови дає декартів добуток 6 × 5 = 30 рядків замість 6. MySQL дозволяє синтаксично `JOIN` без `ON` (це `CROSS JOIN`), тому помилку видно лише за результатом.
2. **Умова на праву таблицю у `WHERE` замість `ON`.** Перетворює `LEFT JOIN` на `INNER JOIN` і «з'їдає» рядки з `NULL`. Для фільтрів правої таблиці, які не повинні знищувати ліві рядки, умову пишіть в `ON`.
3. **Неоднозначні назви колонок.** `id`, `customer_id` можуть бути в кількох таблицях; без аліасів MySQL/MariaDB видадуть помилку `Column 'id' in field list is ambiguous`, PostgreSQL — `column reference "id" is ambiguous`.
4. **`COUNT(*)` замість `COUNT(o.id)` у `LEFT JOIN`.** Рахує рядки-«сироти» з `NULL` як 1, тому клієнт без замовлень отримує «1 замовлення».
5. **Дублікати при подвійній агрегації.** Якщо після з'єднання «один-до-багатьох» обчислити `SUM(o.quantity)` і додатково приєднати ще одну багаточленну таблицю, рядки замовлень розмножаться й сума подвоїться. Перевіряйте кількість рядків до агрегації.

## Вправи

1. Виведіть усі замовлення з ім'ям клієнта та назвою товару: `order_id`, `full_name`, `title`, `quantity`, за зростанням `order_id`.
   Підказка: два `JOIN` від `orders` до `customers` і `products`.

2. Для кожного клієнта виведіть кількість замовлень, включно з клієнтами без замовлень.
   Підказка: `LEFT JOIN` і `COUNT(o.id)`.

3. Знайдіть замовлення, сума яких (`price * quantity`) перевищує 10000: клієнт, товар, сума.
   Підказка: вираз можна використати і в `SELECT`, і в `WHERE`.

4. Для кожного автора виведіть кількість книг (0, якщо книг немає) — MariaDB, таблиці `authors` і `books`.
   Підказка: `LEFT JOIN` + `COUNT(b.id)` + `GROUP BY`.

5. Виведіть усі січневі замовлення разом із клієнтами, зберігши клієнтів без таких замовлень (емоція `FULL OUTER JOIN` для MySQL/MariaDB або нативний `FULL OUTER JOIN` для PostgreSQL).
   Підказка: умова за датою — в `ON`; для MySQL/MariaDB — `LEFT JOIN ... UNION ... RIGHT JOIN ... WHERE c.id IS NULL`.

## Відповіді

1.

```sql
SELECT o.id AS order_id, c.full_name, p.title, o.quantity
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
ORDER BY o.id;
```

У PostgreSQL: `FROM shop.orders o JOIN shop.customers c ... JOIN shop.products p ...`.

2.

```sql
SELECT c.full_name, COUNT(o.id) AS orders_count
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
GROUP BY c.id, c.full_name
ORDER BY orders_count DESC, c.full_name;
```

Результат: Олена — 2, решта — по 1.

3.

```sql
SELECT o.id AS order_id, c.full_name, p.title,
       p.price * o.quantity AS amount
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
WHERE p.price * o.quantity > 10000
ORDER BY amount DESC;
```

Результат: замовлення 1 (24999.00), 3 (18499.50), 6 (18499.50), 4 (14998.00).

4.

```sql
SELECT a.name, COUNT(b.id) AS books_count
FROM authors a
LEFT JOIN books b ON b.author_id = a.id
GROUP BY a.id, a.name
ORDER BY books_count DESC, a.name;
```

Результат: Джордж Орвелл — 2, решта — по 1.

5.

```sql
-- PostgreSQL
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM shop.customers c
FULL OUTER JOIN shop.orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-01-01'
 AND o.ordered_at <  '2026-02-01'
ORDER BY c.id NULLS LAST, o.id;
```

```sql
-- MySQL / MariaDB (емуляція)
SELECT c.full_name, o.id AS order_id, o.ordered_at
FROM customers c
LEFT JOIN orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-01-01'
 AND o.ordered_at <  '2026-02-01'
UNION
SELECT c.full_name, o.id, o.ordered_at
FROM customers c
RIGHT JOIN orders o
  ON o.customer_id = c.id
 AND o.ordered_at >= '2026-01-01'
 AND o.ordered_at <  '2026-02-01'
WHERE c.id IS NULL
ORDER BY full_name IS NULL, order_id;
```

У результаті — 5 клієнтів (Олена з двома січневими замовленнями, Ігор/Марія/Андрій/Софія з `NULL` у колонці замовлення), додаткових «сиріт» серед замовлень немає, бо січневі замовлення вже показані в лівій частині. Сортування `full_name IS NULL` ставить `NULL`-імена (якщо з'являться) у кінець списку замість `NULLS LAST` з PostgreSQL.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
