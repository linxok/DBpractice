# 06. Індекси та EXPLAIN

На п'яти рядках будь-який запит миттєвий, тому користь індексів видно лише на великих обсягах. Для цієї теми на стенді є окремі великі бази: MySQL/MariaDB `shop_big` і схема `shop_big` у PostgreSQL із тими самими таблицями, але `customers` = 10 000 рядків, `products` = 1 000, `orders` = 100 000. `EXPLAIN` показує, як СУБД збирається виконувати запит: послідовним скануванням чи через індекс. Уміння читати план — базовий навик оптимізації.

## Дані стенду

```text
Малі бази (для перевірки логіки):
MySQL 8.4, база learn — customers, products, orders
MariaDB 11.4, база learn — authors, books
PostgreSQL 17, база learn, схема shop — customers, products, orders

Великі бази (для EXPLAIN):
MySQL / MariaDB, база shop_big:
  customers  10 000 рядків
  products    1 000 рядків
  orders    100 000 рядків
PostgreSQL, база learn, схема shop_big — ті самі таблиці й обсяги
```

`shop_big.customers` можна писати однаково в MySQL (база `shop_big`) і в PostgreSQL (схема `shop_big`) — синтаксично це виглядає як `shop_big.customers`.

## Синтаксис

Створення та видалення індексів:

```sql
-- MySQL / MariaDB
CREATE INDEX idx_orders_ordered_at ON shop_big.orders (ordered_at);
CREATE UNIQUE INDEX uq_products_title ON shop_big.products (title);
DROP INDEX idx_orders_ordered_at ON shop_big.orders;
SHOW INDEX FROM shop_big.orders;
```

```sql
-- PostgreSQL
CREATE INDEX idx_orders_ordered_at ON shop_big.orders (ordered_at);
CREATE UNIQUE INDEX uq_products_title ON shop_big.products (title);
DROP INDEX shop_big.idx_orders_ordered_at;
SELECT indexname, indexdef FROM pg_indexes
WHERE schemaname = 'shop_big' AND tablename = 'orders';
```

Команди аналізу плану:

```sql
EXPLAIN SELECT ...;                      -- усі три СУБД: план без виконання запиту
EXPLAIN ANALYZE SELECT ...;              -- MySQL 8.0.18+ і PostgreSQL: виконує запит
EXPLAIN (ANALYZE, BUFFERS) SELECT ...;   -- PostgreSQL: фактичний час і робота з кешем
ANALYZE SELECT ...;                      -- MariaDB: виконує запит (EXPLAIN ANALYZE немає)
```

SQL Runner виконує лише `SELECT` і `EXPLAIN`. Команди `CREATE INDEX` / `DROP INDEX` виконуйте через Adminer (http://localhost:8080) або консольний клієнт:

```bash
docker exec -it learn-mysql mysql -ustudent -pstudent learn
docker exec -it learn-postgres psql -U student -d learn
```

Плани нижче — орієнтовні: числа залежать від статистики, версії та кешу. Дивіться не на конкретні значення, а на `type`, `key`, `Extra` (MySQL) та назви вузлів і `Buffers` (PostgreSQL).

## Як читати EXPLAIN

MySQL:

```text
id  select_type  table  type  possible_keys  key  key_len  ref  rows  filtered  Extra
```

- `type` — спосіб доступу, від найкращого до найгіршого: `system`, `const`, `eq_ref`, `ref`, `range`, `index`, `ALL`. `ALL` — повне сканування таблиці.
- `possible_keys` / `key` — які індекси можна було застосувати / який обрано.
- `rows` — оцінка кількості рядків, які доведеться переглянути.
- `filtered` — оцінка відсотка рядків, що пройдуть умову.
- `Extra` — важливі позначки: `Using where` (фільтр після читання), `Using index` (дані взято з індексу, таблиця не читалася), `Using filesort` / `Using temporary` (сортування або тимчасова таблиця).

PostgreSQL:

- вузли плану: `Seq Scan` (послідовне сканування), `Index Scan`, `Index Only Scan` (лише індекс), `Bitmap Index Scan` + `Bitmap Heap Scan`, `Nested Loop` / `Hash Join` / `Merge Join`, `Sort`, `Aggregate`.
- `cost=старт..разом` — оціночна вартість, `rows=` — оцінка рядків, `actual time=... rows=... loops=` — фактичні значення при `ANALYZE`.
- `Buffers: shared hit=... read=...` — скільки сторінок узято з кешу / прочитано з диска (є тільки з опцією `BUFFERS`).

Індекс — це впорядкована структура (B-tree), у якій зберігаються значення колонки та посилання на рядки. Пошук замість перегляду всіх `N` рядків робить `log N` кроків. Ціна: додаткове місце на диску та сповільнення `INSERT` / `UPDATE` / `DELETE`, бо кожен індекс потрібно оновлювати.

## Приклади

### Приклад 1. Послідовне сканування: умова без індексу

```sql
EXPLAIN SELECT id, full_name, city
FROM shop_big.customers
WHERE city = 'Київ';
```

MySQL (скорочено):

```text
id | select_type | table     | type | possible_keys | key  | key_len | ref  | rows | filtered | Extra
1  | SIMPLE      | customers | ALL  | NULL          | NULL | NULL    | NULL | 9867 | 10.00    | Using where
```

PostgreSQL:

```text
Seq Scan on customers  (cost=0.00..239.00 rows=1250 width=34)
  Filter: ((city)::text = 'Київ'::text)
```

`type=ALL` і `Seq Scan` означають повний перегляд 10 000 рядків: індексу на `city` немає, а `rows ≈ 9867` — оцінка кількості рядків, які доведеться перевірити. У PostgreSQL навіть після створення індексу на `city` оптимізатор може обрати `Seq Scan`: міст вісім, у кожному близько 1250 клієнтів (12,5 % таблиці), а читати 12,5 % через індекс дорожче, ніж сканувати всю таблицю. Це називається низька селективність.

### Приклад 2. Індексний доступ за унікальним індексом

```sql
EXPLAIN SELECT id, full_name, email
FROM shop_big.customers
WHERE email = 'user42@example.com';
```

MySQL:

```text
id | select_type | table     | type  | possible_keys       | key                 | key_len | ref   | rows | filtered | Extra
1  | SIMPLE      | customers | const | customers_email_key | customers_email_key | 642     | const | 1    | 100.00   | NULL
```

PostgreSQL:

```text
Index Scan using customers_email_key on customers  (cost=0.29..8.30 rows=1 width=41)
  Index Cond: ((email)::text = 'user42@example.com'::text)
```

`type=const` у MySQL означає, що за унікальним індексом знайдено рівно один рядок; `key_len=642` — довжина ключа (160 символів × 4 байти utf8mb4 + 2 байти довжини). У PostgreSQL — `Index Scan` за унікальним індексом `customers_email_key` з оцінкою `rows=1`. Це ідеальний сценарій: замість 10 000 рядків переглядається один.

### Приклад 3. Функція на колонці блокує індекс

Поганий варіант — фільтр через функцію від колонки:

```sql
EXPLAIN SELECT COUNT(*)
FROM shop_big.orders
WHERE YEAR(ordered_at) = 2026
  AND MONTH(ordered_at) = 6;
```

MySQL:

```text
id | select_type | table  | type | possible_keys | key  | key_len | ref  | rows   | filtered | Extra
1  | SIMPLE      | orders | ALL  | NULL          | NULL | NULL    | NULL | 100089 | 100.00   | Using where
```

PostgreSQL:

```text
Aggregate  (cost=2840.01..2840.02 rows=1 width=8)
  ->  Seq Scan on orders  (cost=0.00..2840.00 rows=2 width=0)
        Filter: ((date_part('year'::text, ordered_at) = '2026'::double precision)
             AND (date_part('month'::text, ordered_at) = '6'::double precision))
```

Індекс на `ordered_at` (навіть якщо він є) не застосовується, бо запит запитує «результат функції від колонки», а індекс зберігає вихідні значення. Перепишіть умову як діапазон:

```sql
EXPLAIN SELECT COUNT(*)
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

MySQL:

```text
id | select_type | table  | type  | possible_keys         | key                   | key_len | ref  | rows | Extra
1  | SIMPLE      | orders | range | idx_orders_ordered_at | idx_orders_ordered_at | 5       | NULL | 2760 | Using where; Using index
```

PostgreSQL (варіант плану):

```text
Aggregate  (cost=117.11..117.12 rows=1 width=8)
  ->  Index Only Scan using idx_orders_ordered_at on orders  (cost=0.29..110.35 rows=2703 width=0)
        Index Cond: ((ordered_at >= '2026-06-01 00:00:00'::timestamp without time zone)
                 AND (ordered_at <  '2026-07-01 00:00:00'::timestamp without time zone))
        Heap Fetches: 0
```

`type=range` — пошук за діапазоном індексу; `Using index` / `Index Only Scan` — навіть рядки таблиці не читалися, усе взято з індексу. Запит із функцією переглядає 100 000 рядків, запит із діапазоном — близько 2 740.

### Приклад 4. Створення індексу: до і після

```sql
CREATE INDEX idx_orders_ordered_at ON shop_big.orders (ordered_at);
```

Виконайте через Adminer, консольний клієнт або SQL Runner (у пісочниці він приймає будь-які команди, для `shop_big` — лише читання й плани). До створення запит `WHERE ordered_at >= '2026-06-01' AND ordered_at < '2026-07-01'` давав `type=ALL` / `Seq Scan`, після — `type=range` / `Index Only Scan` (див. приклад 3). Перевірка наявності індексів:

```sql
-- MySQL / MariaDB
SHOW INDEX FROM shop_big.orders;
```

```text
Table  | Non_unique | Key_name              | Seq_in_index | Column_name | Cardinality
orders | 0          | PRIMARY               | 1            | id          | 100089
orders | 1          | idx_orders_ordered_at | 1            | ordered_at  | 100089
```

`Cardinality` — оцінка кількості унікальних значень у колонці; чим вона ближча до кількості рядків, тим краща селективність.

```sql
-- PostgreSQL
SELECT indexname, indexdef FROM pg_indexes
WHERE schemaname = 'shop_big' AND tablename = 'orders';
```

Після масових змін даних статистику оновлюють: `ANALYZE TABLE shop_big.orders;` (MySQL/MariaDB) або `ANALYZE shop_big.orders;` (PostgreSQL). Без актуальної статистики оптимізатор може обрати поганий план.

### Приклад 5. Складений індекс і лівий префікс

```sql
CREATE INDEX idx_orders_customer_date
  ON shop_big.orders (customer_id, ordered_at);
```

Складений індекс працює як телефонний довідник, відсортований спершу за `customer_id`, а всередині — за `ordered_at`. Використовується **лівий префікс**:

```sql
-- (а) використовує обидві колонки
EXPLAIN SELECT id, ordered_at
FROM shop_big.orders
WHERE customer_id = 42
  AND ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';

-- (б) використовує лівий префікс (лише customer_id)
EXPLAIN SELECT id
FROM shop_big.orders
WHERE customer_id = 42;

-- (в) НЕ може використати цей індекс для доступу
EXPLAIN SELECT id
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01';
```

У запиті (в) немає умови на `customer_id` — провідну колонку індексу, тому для доступу до рядків індекс непридатний. MySQL 8 інколи застосовує `Index Skip Scan` (у плані це видно як `Using index for skip scan`), але це працює лише за певних умов; PostgreSQL такого механізму не має й виконує `Seq Scan`. Правильний висновок: порядок колонок у складеному індексі визначається запитами, які ви обслуговуєте.

Покривний запит — коли всі потрібні колонки вже є в індексі:

```sql
EXPLAIN SELECT customer_id, ordered_at
FROM shop_big.orders
WHERE customer_id = 42;
```

MySQL покаже `Extra: Using index`, PostgreSQL — `Index Only Scan`. Обидва означають, що таблиця не читалася взагалі. Для PostgreSQL `Index Only Scan` ефективний лише за актуальної карти видимості (регулярний `VACUUM` / autovacuum); інакше з'являться `Heap Fetches`.

У малій базі `learn` зовнішній ключ MySQL InnoDB автоматично створює індекс на `orders.customer_id` (у списку `SHOW INDEX` це `fk_orders_customer`). У `shop_big` зовнішніх ключів немає, тому індекс створюють явно — саме тому в прикладі 8 запит спочатку сканує `orders`. PostgreSQL не створює індекс для зовнішнього ключа ніколи — його додають вручну.

### Приклад 6. Коли індекс не використовується

**Ведучий `%` у `LIKE`:**

```sql
EXPLAIN SELECT id FROM shop_big.customers WHERE email LIKE 'user12%';   -- префікс
EXPLAIN SELECT id FROM shop_big.customers WHERE email LIKE '%user12%';  -- підрядок
```

Перший запит може використати індекс як `range` (пошук за префіксом), другий — ні: шаблон із ведучим `%` не має точки входу в упорядкований індекс, тому буде `ALL` / `Seq Scan`. Умова на кшталт `LOWER(title) LIKE '%sony%'` не використає індекс ще й через функцію.

**Низька селективність:**

```sql
EXPLAIN SELECT id, full_name FROM shop_big.customers WHERE city = 'Київ';
```

Навіть якщо індекс на `city` створити, PostgreSQL майже напевно обере `Seq Scan` (близько 1250 із 10 000 рядків — 12,5 %). Порівняйте в psql із примусовим вимкненням сканування: `SET enable_seqscan = off;` — план зміниться на індексний, але фактичний час, найімовірніше, зросте. Для фільтрів із низькою селективністю індекси не допомагають.

**`OR` по різних колонках:**

```sql
EXPLAIN SELECT id
FROM shop_big.orders
WHERE customer_id = 42 OR product_id = 7;
```

MySQL може застосувати `index_merge` (об'єднання двох індексів), PostgreSQL — `BitmapOr`; якщо індексів немає, обидві СУБД сканують таблицю. `OR` заважає використати один складений індекс — іноді запит варто переписати на `UNION` двох умов.

### Приклад 7. EXPLAIN ANALYZE: фактичні числа

```sql
-- MySQL 8.0.18+ / PostgreSQL
EXPLAIN ANALYZE
SELECT COUNT(*)
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

MySQL (вивід дерева, скорочено):

```text
-> Aggregate: count(0)  (cost=832 rows=1) (actual time=0.462..0.462 rows=1 loops=1)
    -> Filter: ((ordered_at >= ...) and (ordered_at < ...))  (cost=556 rows=2760) (actual time=0.0238..0.392 rows=2760 loops=1)
        -> Covering index range scan on orders using idx_orders_ordered_at over ('2026-06-01' <= ordered_at < '2026-07-01')  (cost=556 rows=2760) (actual time=0.0173..0.218 rows=2760 loops=1)
```

PostgreSQL:

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT COUNT(*)
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

```text
Aggregate  (cost=120.14..120.15 rows=1 width=8) (actual time=0.256..0.257 rows=1 loops=1)
  Buffers: shared hit=1 read=10
  ->  Index Only Scan using idx_orders_ordered_at on orders
        (cost=0.29..113.05 rows=2838 width=0) (actual time=0.012..0.176 rows=2760 loops=1)
        Index Cond: ((ordered_at >= '2026-06-01 00:00:00'::timestamp without time zone)
                 AND (ordered_at <  '2026-07-01 00:00:00'::timestamp without time zone))
        Heap Fetches: 0
        Buffers: shared hit=1 read=10
Planning Time: 0.111 ms
Execution Time: 0.286 ms
```

Різниця `EXPLAIN` і `EXPLAIN ANALYZE`: перший показує оцінку (`rows`), другий виконує запит і додає факт (`actual time`, `rows`, `loops`, `Buffers`). Якщо оцінка суттєво розходиться з фактом — статистика застаріла. `loops` показує, скільки разів виконувався вузол плану: у вкладеному циклі це важливо, бо `actual time` множиться на `loops`.

MariaDB не має `EXPLAIN ANALYZE`; замість нього:

```sql
-- MariaDB, база learn
ANALYZE SELECT author_id, COUNT(*) AS books_count, AVG(pages) AS avg_pages
FROM books
GROUP BY author_id;
```

```text
id | select_type | table | type  | key             | rows | r_rows | filtered | r_filtered
1  | SIMPLE      | books | index | fk_books_author | 6    | 6.00   | 100.00   | 100.00
```

У табличному виводі з'являються колонки `r_rows` (фактична кількість рядків) і `r_filtered` (фактичний відсоток), а `ANALYZE FORMAT=JSON` додає час виконання. Це аналог `EXPLAIN ANALYZE` у MySQL. У SQL Runner для цього є режим «EXPLAIN ANALYZE» (для MariaDB він виконує `ANALYZE`), або скористайтеся консольним клієнтом чи Adminer.

### Приклад 8. Індекси зовнішніх ключів: MySQL проти PostgreSQL

```sql
-- PostgreSQL: перевіряємо план з'єднання до створення індексу
EXPLAIN
SELECT c.full_name, o.ordered_at
FROM shop_big.customers c
JOIN shop_big.orders o ON o.customer_id = c.id
WHERE c.id = 42;
```

PostgreSQL до індексу:

```text
Nested Loop  (cost=0.29..2098.40 rows=10 width=25)
  ->  Index Scan using customers_pkey on customers c  (cost=0.29..8.30 rows=1 width=21)
        Index Cond: (id = 42)
  ->  Seq Scan on orders o  (cost=0.00..2090.00 rows=10 width=12)
        Filter: (customer_id = 42)
```

Зовнішній ключ у PostgreSQL не створює індекс автоматично, тому для `orders.customer_id` виконується `Seq Scan` на 100 000 рядків. Створюємо індекс і повторюємо:

```sql
CREATE INDEX idx_orders_customer ON shop_big.orders (customer_id);

EXPLAIN
SELECT c.full_name, o.ordered_at
FROM shop_big.customers c
JOIN shop_big.orders o ON o.customer_id = c.id
WHERE c.id = 42;
```

```text
Nested Loop  (cost=4.66..49.62 rows=10 width=25)
  ->  Index Scan using customers_pkey on customers c  (cost=0.29..8.30 rows=1 width=21)
        Index Cond: (id = 42)
  ->  Bitmap Heap Scan on orders o  (cost=4.37..41.22 rows=10 width=12)
        Recheck Cond: (customer_id = 42)
        ->  Bitmap Index Scan on idx_orders_customer  (cost=0.00..4.37 rows=10 width=0)
              Index Cond: (customer_id = 42)
```

У MySQL/MariaDB цей запит теж виконує повний перегляд, доки індекс не створено вручну: `CREATE INDEX idx_orders_customer ON orders (customer_id);`. Загальне правило: якщо таблиці часто з'єднують за колонкою, індекс на неї створюють явно.

### Приклад 9. План з'єднання трьох таблиць

```sql
EXPLAIN
SELECT c.city, COUNT(*) AS orders_count, SUM(o.quantity) AS items
FROM shop_big.orders o
JOIN shop_big.customers c ON c.id = o.customer_id
JOIN shop_big.products p ON p.id = o.product_id
GROUP BY c.city;
```

PostgreSQL (орієнтовно):

```text
HashAggregate  (cost=3487.72..3487.80 rows=8 width=29)
  Group Key: c.city
  ->  Hash Join  (cost=371.50..2737.72 rows=100000 width=17)
        Hash Cond: (o.product_id = p.id)
        ->  Hash Join  (cost=339.00..2441.61 rows=100000 width=21)
              Hash Cond: (o.customer_id = c.id)
              ->  Seq Scan on orders o  (cost=0.00..1840.00 rows=100000 width=12)
              ->  Hash  (cost=214.00..214.00 rows=10000 width=17)
                    ->  Seq Scan on customers c  (cost=0.00..214.00 rows=10000 width=17)
        ->  Hash  (cost=20.00..20.00 rows=1000 width=4)
              ->  Seq Scan on products p  (cost=0.00..20.00 rows=1000 width=4)
```

`Hash Join` означає, що СУБД будує хеш-таблицю з меншої таблиці й пробігає більшу один раз. Для звіту, який читає весь `orders`, послідовне сканування — нормальний вибір; індекс тут не потрібен, бо читаються всі 100 000 рядків. Індекси допомагають точковим запитам і діапазонам, а не повним вибіркам.

### Приклад 10. Мала таблиця: вирішує оптимізатор, а не розмір

```sql
-- MariaDB, база learn: індексу на title немає
EXPLAIN SELECT id, title, pages
FROM books
WHERE title = 'Кобзар';
```

```text
id | select_type | table | type | possible_keys | key  | rows | Extra
1  | SIMPLE      | books | ALL  | NULL          | NULL | 6    | Using where
```

А тут використовується індекс, який MariaDB створила автоматично для зовнішнього ключа `fk_books_author`:

```sql
-- MariaDB, база learn
EXPLAIN SELECT id, title, pages
FROM books
WHERE author_id = 4;
```

```text
id | select_type | table | type | possible_keys  | key            | rows | Extra
1  | SIMPLE      | books | ref  | fk_books_author | fk_books_author | 2    | NULL
```

Висновок: рішення ухвалює оптимізатор за оцінкою вартості, а не «розмір таблиці» сам собою. На шести рядках різниця між планами — мікросекунди, тому індекси тут мають сенс лише під конкретні часті запити. Справжня різниця між `ALL` і `range`/`Index Scan` видна на `shop_big` зі 100 000 замовлень. Пам'ятайте і про ціну: кожен індекс сповільнює `INSERT` / `UPDATE` / `DELETE`, тому «про запас» їх не створюють.

## Типові помилки

1. **Функція або вираз навколо колонки.** `WHERE YEAR(ordered_at) = 2026`, `WHERE DATE(ordered_at) = '2026-01-12'`, `WHERE LOWER(email) = ...` блокують звичайний індекс на цій колонці: індекс знає вихідні значення, а не результат функції. Переписуйте на діапазони; окремий випадок — функціональні індекси (`CREATE INDEX ... ((YEAR(ordered_at)))`), але вони менш переносимі.
2. **Ведучий `%` у `LIKE`.** `LIKE '%text%'` не має точки входу в упорядкований індекс. Префіксний пошук `LIKE 'text%'` індекс використовує.
3. **Неправильний порядок колонок у складеному індексі.** Індекс `(customer_id, ordered_at)` не обслуговує запити лише за `ordered_at` — працює лівий префікс. Порядок колонок обирають за реальними запитами.
4. **Індексування всього підряд.** Кожен індекс потрібно оновлювати при `INSERT` / `UPDATE` / `DELETE`, він займає диск і пам'ять. Зайві індекси сповільнюють запис і можуть заплутати оптимізатор.
5. **`EXPLAIN` як гарантія.** Без `ANALYZE` це лише оцінка за статистикою; застарілі статистики дають неправильні плани. Оновлюйте їх (`ANALYZE TABLE` / `ANALYZE`) і перевіряйте факти — `EXPLAIN ANALYZE`.
6. **`EXPLAIN ANALYZE` на зміні даних.** Команда виконує запит: `EXPLAIN ANALYZE DELETE ...` справді видалить рядки. Для навчального стенду SQL Runner обмежує виконання `SELECT` і `EXPLAIN`, але в інших середовищах будьте уважні.
7. **Сподівання на автоматичний індекс зовнішнього ключа в PostgreSQL.** InnoDB створює індекс під FK, PostgreSQL — ні. З'єднання за колонкою без індексу сканує всю таблицю.

## Вправи

1. Порівняйте `EXPLAIN` для `WHERE email = 'user42@example.com'` і `WHERE city = 'Київ'` на `shop_big.customers`. Поясніть, чому плани різні, та знайдіть `type`/вузол плану й оцінку `rows`.
   Підказка: у першому запиті є унікальний індекс на `email`, у другого індексу немає; селективність `city` низька.

2. Перепишіть запит `SELECT COUNT(*) FROM shop_big.orders WHERE YEAR(ordered_at) = 2026 AND MONTH(ordered_at) = 6;` так, щоб він міг використати індекс на `ordered_at`, і доведіть це через `EXPLAIN`.
   Підказка: замініть функції на межі `ordered_at >= '2026-06-01' AND ordered_at < '2026-07-01'`.

3. Створіть індекс `idx_orders_ordered_at` на `shop_big.orders (ordered_at)` (Adminer або консоль) і порівняйте `EXPLAIN` для запиту за червень 2026 до і після створення.
   Підказка: очікуйте `type=ALL` без індексу та `type=range` / `Index Only Scan` з ним; у PostgreSQL перевірте `ANALYZE` після створення індексу.

4. Створіть складений індекс `(customer_id, ordered_at)` на `shop_big.orders`. Наведіть один запит, який його використає, і один, який не зможе (без умови на `customer_id`).
   Підказка: лівий префікс; для другого запиту подивіться `Using index for skip scan` (MySQL) або `Seq Scan` (PostgreSQL).

5. Поясніть через `EXPLAIN`, чому `WHERE email LIKE '%user12%'` не використовує індекс, і покажіть запит із префіксним шаблоном, який його використовує.
   Підказка: `LIKE 'user12%'` перетворюється на діапазон у B-tree; ведучий `%` — ні.

## Відповіді

1.

```sql
EXPLAIN SELECT id, full_name, email
FROM shop_big.customers
WHERE email = 'user42@example.com';

EXPLAIN SELECT id, full_name, city
FROM shop_big.customers
WHERE city = 'Київ';
```

Перший запит: MySQL `type=const`, ключ унікального індексу `email`, `rows=1`; PostgreSQL `Index Scan using customers_email_key`. Другий: MySQL `type=ALL`, `possible_keys=NULL`, `rows≈9867`; PostgreSQL `Seq Scan` із `Filter`. Різниця в селективності: електронна пошта унікальна, а міст вісім, і на кожне припадає близько 12,5 % таблиці.

2.

```sql
EXPLAIN SELECT COUNT(*)
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

За наявності `idx_orders_ordered_at` план показує `type=range` / `Index Only Scan`, `rows≈2740` замість `type=ALL`, `rows=100000`. Без індексу запит усе одно коректний, але сканує всю таблицю; тоді як запит із `YEAR`/`MONTH` не використає звичайний індекс на `ordered_at`.

3.

```sql
-- до створення індексу
EXPLAIN SELECT id, ordered_at
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

```sql
-- створення (Adminer або консоль)
CREATE INDEX idx_orders_ordered_at ON shop_big.orders (ordered_at);
```

```sql
-- після створення
EXPLAIN SELECT id, ordered_at
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01'
  AND ordered_at <  '2026-07-01';
```

Очікувано: `type=ALL` → `type=range`, `key=idx_orders_ordered_at`, `rows` зменшується приблизно зі 100 000 до 2 740. У PostgreSQL додайте `ANALYZE shop_big.orders;` після створення, щоб оновлена статистика дала точніший план.

4.

```sql
CREATE INDEX idx_orders_customer_date
  ON shop_big.orders (customer_id, ordered_at);

-- використовує індекс: обидві колонки
EXPLAIN SELECT id, ordered_at
FROM shop_big.orders
WHERE customer_id = 42
  AND ordered_at >= '2026-06-01';

-- не використовує для доступу: немає провідної колонки
EXPLAIN SELECT id, ordered_at
FROM shop_big.orders
WHERE ordered_at >= '2026-06-01';
```

Перший запит: `type=ref/range`, `key=idx_orders_customer_date`. Другий: `type=ALL` (MySQL може показати `Using index for skip scan`); PostgreSQL — `Seq Scan`. Запит лише за `customer_id` також використає індекс, бо це лівий префікс.

5.

```sql
EXPLAIN SELECT id FROM shop_big.customers WHERE email LIKE '%user12%';
-- ALL / Seq Scan: шаблон не має префікса, точку входу в B-tree не знайти

EXPLAIN SELECT id FROM shop_big.customers WHERE email LIKE 'user12%';
-- range / Index Scan: префікс "user12" перетворюється на діапазон значень
```

Другий запит перетворюється оптимізатором на умову `email >= 'user12' AND email < 'user13'` (спрощено), тому унікальний індекс на `email` працює. Перший запит змушений перевіряти кожен рядок: 10 000 читань замість кількох.

---

Практика: http://localhost:8000/tasks.php
SQL Runner: http://localhost:8000/runner.php
