# 11. Адміністрування баз даних

## Навіщо ця тема

Адміністрування — це все, що відбувається навколо запитів: хто має доступ, як відновити дані після помилки, як знайти повільний запит, чому на диску закінчилося місце та коли запускати обслуговування таблиць. Розробнику ці знання потрібні щодня: без резервних копій одна помилкова команда `DELETE` коштує даних, а без обмежених прав застосунок може випадково зламати сусідню базу.

Розділ охоплює п'ять напрямів: користувачі й права, резервне копіювання та відновлення, перегляд процесів, логи й метрики, обслуговування таблиць. Приклади прив'язані до стенду, у якому вже є користувачі з різними правами, скрипт `manage.sh` і каталог `backups/`.

## Синтаксис

```sql
-- MySQL / MariaDB
CREATE USER 'ім'я'@'хост' IDENTIFIED BY 'пароль';
GRANT привілей ON база.* TO 'ім'я'@'хост';
REVOKE привілей ON база.* FROM 'ім'я'@'хост';
CREATE ROLE 'назва_ролі';
GRANT 'назва_ролі' TO 'ім'я'@'хост';

SHOW GRANTS FOR 'ім'я'@'хост';
SHOW FULL PROCESSLIST;
SHOW GLOBAL STATUS LIKE 'Threads_connected';
ANALYZE TABLE таблиця;
OPTIMIZE TABLE таблиця;
CHECK TABLE таблиця;
```

```sql
-- PostgreSQL
CREATE ROLE ім'я LOGIN PASSWORD 'пароль';
GRANT CONNECT ON DATABASE learn TO ім'я;
GRANT USAGE ON SCHEMA shop TO ім'я;
GRANT SELECT ON ALL TABLES IN SCHEMA shop TO ім'я;

SELECT * FROM pg_stat_activity;
SELECT pg_cancel_backend(pid);
SELECT pg_terminate_backend(pid);
VACUUM (ANALYZE) shop.таблиця;
ANALYZE shop.таблиця;
```

```bash
# Резервні копії та керування стендом
./manage.sh backup all
./manage.sh restore backups/mysql_20260921_120000.sql
mysqldump -uroot -p --single-transaction --databases learn > dump.sql
pg_dump -U student -d learn -n shop -Fc > shop.dump
mongodump --db learn --out backup_dir
redis-cli BGSAVE
```

## Користувачі стенду та принцип найменших прав

У стенді навмисно створено кілька рівнів доступу:

| Користувач | Де | Права |
|---|---|---|
| `root` | MySQL 8.4, MariaDB 11.4, MongoDB 7 | повний адміністративний доступ |
| `student` | MySQL, MariaDB, PostgreSQL | повний доступ до навчальних баз (`learn`, `sandbox`) |
| `readonly` | MySQL, MariaDB, PostgreSQL | лише `SELECT` на `learn` (у PostgreSQL — схема `shop`) |
| `root` (MongoDB) | MongoDB | роль `root` у базі `admin` |

Пароль `root/root`, `student/student`, `readonly/readonly`; Redis працює без пароля. Це свідоме спрощення для локального навчання.

Принцип найменших прав: застосунок має підключатися під користувачем, якому дозволено лише необхідні операції. Якщо застосунок зламають, збитки обмежаться одним набором прав.

## Приклад 1. Перевірка прав readonly у трьох СУБД

```bash
# MySQL: читання дозволене
docker exec -e MYSQL_PWD=readonly learn-mysql \
    mysql -ureadonly -e "SELECT COUNT(*) FROM learn.customers"

# MySQL: створення таблиці заборонене (ERROR 1142)
docker exec -e MYSQL_PWD=readonly learn-mysql \
    mysql -ureadonly -e "CREATE TABLE learn.spam (id INT)"

# MariaDB
docker exec -e MYSQL_PWD=readonly learn-mariadb \
    mariadb -ureadonly -e "SELECT COUNT(*) FROM learn.books"

# PostgreSQL
docker exec -e PGPASSWORD=readonly learn-postgres \
    psql -U readonly -d learn -c "SELECT count(*) FROM shop.customers;"
```

Якщо команда створення таблиці повертає помилку доступу — права налаштовані правильно. Для налагодження перегляньте надані привілеї:

```sql
-- MySQL / MariaDB
SHOW GRANTS FOR 'readonly'@'%';

-- PostgreSQL
\du readonly
```

## Приклад 2. Користувачі та ролі MySQL / MariaDB

```sql
-- під root: docker exec -it learn-mysql mysql -uroot -proot
CREATE USER 'reporter'@'%' IDENTIFIED BY 'Reporter_2026!';
GRANT SELECT, SHOW VIEW ON learn.* TO 'reporter'@'%';

SHOW GRANTS FOR 'reporter'@'%';

GRANT INSERT ON learn.orders TO 'reporter'@'%';
REVOKE INSERT ON learn.orders FROM 'reporter'@'%';

ALTER USER 'reporter'@'%' IDENTIFIED BY 'NewReporter_2026!';
DROP USER 'reporter'@'%';
```

Ролі (MySQL 8.0+ і MariaDB 10.0+) збирають привілеї в іменований набір:

```sql
CREATE ROLE 'learn_read';
GRANT SELECT, SHOW VIEW ON learn.* TO 'learn_read';

CREATE USER 'analyst'@'%' IDENTIFIED BY 'Analyst_2026!';
GRANT 'learn_read' TO 'analyst'@'%';
SET DEFAULT ROLE ALL TO 'analyst'@'%';

SHOW GRANTS FOR 'analyst'@'%' USING 'learn_read';
```

Роль у MySQL діє в межах сесії: якщо вона не призначена типовою, виконайте `SET ROLE 'learn_read';`. У MariaDB надані ролі активуються автоматично.

Типові набори привілеїв:

| Призначення | Привілеї |
|---|---|
| Застосунок | `SELECT, INSERT, UPDATE, DELETE` |
| Аналітик / readonly | `SELECT, SHOW VIEW` |
| Обслуговування | `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER` |
| Резервне копіювання | `SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER` |

## Приклад 3. Користувачі та ролі PostgreSQL

```sql
-- під student: docker exec -it learn-postgres psql -U student -d learn
CREATE ROLE reporter LOGIN PASSWORD 'Reporter_2026!';

GRANT CONNECT ON DATABASE learn TO reporter;
GRANT USAGE ON SCHEMA shop TO reporter;
GRANT SELECT ON ALL TABLES IN SCHEMA shop TO reporter;

-- застосувати права і до майбутніх таблиць
ALTER DEFAULT PRIVILEGES IN SCHEMA shop
    GRANT SELECT ON TABLES TO reporter;

\du reporter

REVOKE SELECT ON shop.orders FROM reporter;
ALTER ROLE reporter WITH PASSWORD 'NewReporter_2026!';
DROP ROLE reporter;
```

Група-роль без входу спрощує масове призначення прав:

```sql
CREATE ROLE learn_read NOLOGIN;
GRANT SELECT ON ALL TABLES IN SCHEMA shop TO learn_read;
GRANT learn_read TO reporter;
```

`GRANT USAGE ON SCHEMA` обов'язковий: без нього навіть із правом `SELECT` користувач отримає помилку `permission denied for schema shop`.

## Приклад 4. Користувачі MongoDB

```javascript
// mongosh -u root -p student --authenticationDatabase admin
use learn

db.createUser({
  user: 'reporter',
  pwd: 'Reporter_2026!',
  roles: [
    { role: 'read', db: 'learn' }
  ]
})

db.getUsers()
db.dropUser('reporter')
```

Роль `read` дозволяє лише читання бази `learn`; для запису використовують `readWrite`, а роль `dbAdmin` потрібна для індексів і статистики.

## Приклад 5. manage.sh: backup і restore

Скрипт у корені проєкту керує всіма п'ятьма СУБД:

```bash
cd /home/linxok/mywww/Myproject/learnMSQL/docker

./manage.sh backup             # усі бази в каталог backups/
./manage.sh backup mysql       # лише MySQL (також: mariadb, postgres, mongo, redis)
./manage.sh restore backups/mysql_20260921_200938.sql
./manage.sh logs postgres
./manage.sh shell mongo
```

Імена файлів містять СУБД і мітку часу: `mysql_YYYYmmdd_HHMMSS.sql`, `mariadb_...sql`, `postgres_...sql`, `mongo_....archive.gz`, `redis_....rdb`. `restore` визначає тип за префіксом імені, тому важливо не перейменовувати файли довільно.

Правила:

- перевіряйте відновлення, а не лише створення копії;
- зберігайте копії поза томом контейнера — каталог `backups/` лежить на хості;
- узгоджена копія гарячої бази вимагає `--single-transaction` (MySQL) або `pg_dump` без блокувань;
- для великих баз робіть повну копію рідше, а інкрементні — частіше.

## Приклад 6. mysqldump і mariadb-dump вручну

```bash
# узгоджений дамп бази learn разом зі структурою бази, процедурами й тригерами
docker exec -e MYSQL_PWD=root learn-mysql mysqldump -uroot \
    --single-transaction --routines --triggers --events --databases learn \
    > backups/learn_manual.sql

# лише структура
docker exec -e MYSQL_PWD=root learn-mysql mysqldump -uroot \
    --single-transaction --no-data learn > backups/learn_schema.sql

# окремі таблиці
docker exec -e MYSQL_PWD=root learn-mysql mysqldump -uroot \
    --single-transaction learn customers products > backups/learn_tables.sql

# відновлення
docker exec -i -e MYSQL_PWD=root learn-mysql mysql -uroot < backups/learn_manual.sql
```

MariaDB використовує той самий інструмент із назвою `mariadb-dump`:

```bash
docker exec -e MYSQL_PWD=root learn-mariadb mariadb-dump -uroot \
    --single-transaction --routines --triggers --events --databases learn \
    > backups/learn_mariadb_manual.sql

docker exec -i -e MYSQL_PWD=root learn-mariadb mariadb -uroot \
    --default-character-set=utf8mb4 < backups/learn_mariadb_manual.sql
```

Зверніть увагу на `docker exec -i` без `-t`: якщо прибрати `-i`, потік зі stdin не потрапить у контейнер і відновлення нічого не зробить.

## Приклад 7. pg_dump і pg_restore

`pg_dump` підтримує звичайний SQL (`-Fp`) і власний стиснутий формат (`-Fc`), який відновлюється через `pg_restore`.

```bash
# копія всієї бази learn (разом зі схемою shop)
docker exec learn-postgres pg_dump -U student -d learn \
    --no-owner --clean --if-exists > backups/pg_manual.sql

# лише схема shop у власному форматі
docker exec learn-postgres pg_dump -U student -d learn -n shop -Fc \
    > backups/shop_custom.dump

# відновлення
docker exec -i learn-postgres psql -U student -d learn < backups/pg_manual.sql
docker exec -i learn-postgres pg_restore -U student -d learn \
    --clean --if-exists < backups/shop_custom.dump
```

Корисні опції:

| Опція | Призначення |
|---|---|
| `-n shop` | лише одна схема |
| `-t shop.orders` | лише одна таблиця |
| `-s` | тільки структура |
| `-a` | тільки дані |
| `--no-owner` | не відтворювати власників об'єктів |
| `-Fc` | власний формат для `pg_restore` |
| `-j 4` | паралельне відновлення (лише з `-Fd` або `-Fc`) |

## Приклад 8. mongodump, mongorestore і знімок Redis

```bash
# MongoDB: архів зі стисненням одним потоком
docker exec learn-mongo mongodump \
    --username root --password student --authenticationDatabase admin \
    --archive --gzip > backups/mongo_manual.archive.gz

# відновлення з видаленням наявних колекцій
docker exec -i learn-mongo mongorestore \
    --username root --password student --authenticationDatabase admin \
    --archive --gzip --drop < backups/mongo_manual.archive.gz

# Redis: знімок RDB
docker exec learn-redis redis-cli BGSAVE
docker exec learn-redis redis-cli INFO persistence
docker cp learn-redis:/data/dump.rdb backups/redis_manual.rdb
```

Redis у стенді працює з AOF (`--appendonly yes`), тому при відновленні з RDB враховуйте, що сервіс при старті віддає перевагу журналу AOF. Для навчальної підміни стану зупиніть контейнер, замініть `dump.rdb` і перезапустіть його.

## Приклад 9. Перегляд процесів і завершення сесій

```sql
-- MySQL: усі з'єднання
SHOW FULL PROCESSLIST;

SELECT id, user, host, db, command, time, state, LEFT(info, 80) AS query
FROM information_schema.processlist
WHERE command <> 'Sleep'
ORDER BY time DESC;

-- транзакції, що тривають довше 30 секунд
SELECT trx_id, trx_started, trx_mysql_thread_id, trx_query
FROM information_schema.innodb_trx
WHERE TIMESTAMPDIFF(SECOND, trx_started, NOW()) > 30;

KILL QUERY 42;   -- скасувати запит, з'єднання лишається
KILL 42;         -- розірвати з'єднання
```

```sql
-- PostgreSQL
SELECT pid, usename, state, wait_event_type, wait_event,
       now() - query_start AS duration, LEFT(query, 80) AS query
FROM pg_stat_activity
WHERE state <> 'idle'
  AND pid <> pg_backend_pid()
ORDER BY duration DESC NULLS LAST;

-- чиї запити чекають на блокування
SELECT pid, wait_event_type, wait_event, query
FROM pg_stat_activity
WHERE wait_event_type = 'Lock';

SELECT pg_cancel_backend(12345);      -- м'яке скасування запиту
SELECT pg_terminate_backend(12345);   -- розрив з'єднання
```

```javascript
// MongoDB
db.currentOp({ active: true, secs_running: { $gte: 3 } })
db.killOp(12345)
db.serverStatus().connections
```

```text
# Redis
CLIENT LIST
CLIENT KILL ID 12
INFO clients
SLOWLOG GET 10
```

`KILL`/`pg_terminate_backend` прибирає симптом; перш ніж завершувати сесію, з'ясуйте, чому запит виконується довго.

## Приклад 10. Логи, повільні запити, метрики й обслуговування

Логи контейнерів:

```bash
docker compose logs -f mysql
docker compose logs --tail=100 postgres
./manage.sh logs mongo
```

Повільні запити MySQL:

```sql
SET GLOBAL slow_query_log = ON;
SET GLOBAL long_query_time = 0.5;

SHOW VARIABLES LIKE 'slow_query_log%';
SHOW VARIABLES LIKE 'log_output';
```

Якщо `log_output = TABLE`, читайте `mysql.slow_log`; якщо `FILE` — файл у каталозі даних контейнера.

PostgreSQL:

```sql
SHOW log_min_duration_statement;
ALTER SYSTEM SET log_min_duration_statement = 500;   -- мілісекунди
SELECT pg_reload_conf();
```

Базові метрики MySQL:

```sql
SHOW GLOBAL STATUS WHERE Variable_name IN (
    'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries',
    'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests'
);

SELECT table_name, table_rows,
       ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = 'learn'
ORDER BY data_length + index_length DESC;
```

Метрики PostgreSQL:

```sql
SELECT datname, numbackends, xact_commit, xact_rollback, blks_read, blks_hit,
       round(100.0 * blks_hit / nullif(blks_hit + blks_read, 0), 2) AS cache_hit_pct
FROM pg_stat_database
WHERE datname = 'learn';

SELECT relname, n_live_tup, n_dead_tup, last_vacuum, last_autovacuum
FROM pg_stat_user_tables
WHERE schemaname = 'shop'
ORDER BY n_dead_tup DESC;

SELECT pg_size_pretty(pg_database_size('learn')) AS db_size;

SELECT relname, pg_size_pretty(pg_total_relation_size(relid)) AS total_size
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 5;
```

Обслуговування таблиць:

```sql
-- PostgreSQL: прибрати мертві версії рядків, оновити статистику
VACUUM shop.orders;
VACUUM ANALYZE shop.orders;
ANALYZE shop.orders;
VACUUM (VERBOSE, ANALYZE) shop.orders;
REINDEX TABLE shop.orders;
```

```sql
-- MySQL / MariaDB: статистика, дефрагментація, перевірка
ANALYZE TABLE orders;
OPTIMIZE TABLE orders;
CHECK TABLE orders;
SHOW TABLE STATUS FROM learn LIKE 'orders';
```

Правила обслуговування:

- `VACUUM` не можна виконувати всередині блоку транзакції.
- `VACUUM FULL` переписує таблицю, блокує її та тимчасово потребує стільки ж диска — використовуйте його рідко.
- Autovacuum у PostgreSQL працює типово; контролюйте черги через `n_dead_tup` і `last_autovacuum`.
- `ANALYZE` дешевий і безпечний, запускайте його після масових змін даних.
- `OPTIMIZE TABLE` для InnoDB еквівалентний перебудові таблиці: може бути дорогим і потребує вільного місця.

## Типові помилки

1. **Застосунок працює під root.** Одна помилка в коді може змінити будь-яку базу. Давайте застосунку окремого користувача з мінімальними правами.
2. **`GRANT ALL PRIVILEGES`.** Надлишкові права не потрібні нікому, крім адміністратора; у стенді `readonly` демонструє протилежний підхід.
3. **Відсутній `GRANT USAGE ON SCHEMA` у PostgreSQL.** Користувач має `SELECT` на таблиці, але не може працювати зі схемою; повідомлення `permission denied for schema`.
4. **`FLUSH PRIVILEGES` після `GRANT`.** Команда потрібна лише після прямого редагування таблиць `mysql.*`; у звичайному керуванні правами вона зайва.
5. **`mysqldump` без `--single-transaction` на живій базі.** Дамп може містити неузгоджені дані.
6. **`docker exec` без `-i` під час конвеєра.** Дамп не потрапляє в контейнер, відновлення не відбувається.
7. **Відновлення без перевірки.** Копія, яку жодного разу не відновлювали, не вважається робочою.
8. **Бекапи в тому самому томі, що й дані.** `docker compose down -v` знищить і копію; каталог `backups/` на хості цього не допускає.
9. **`mongorestore --drop` без потреби.** Прапорець видаляє наявні колекції перед відновленням.
10. **`KILL` замість `KILL QUERY`.** Розрив з'єднання скасовує транзакцію клієнта, тоді як скасування запиту лише зупиняє поточну команду.
11. **`VACUUM FULL` на робочій базі вдень.** Блокує таблицю; для повернення місця часто достатньо звичайного `VACUUM`.
12. **`OPTIMIZE TABLE` без запасу диска.** Перебудова великої InnoDB-таблиці потребує тимчасового простору.
13. **`long_query_time = 0`.** Кожен запит потрапляє в лог; диск заповнюється за години.
14. **Ігнорування `idle in transaction`.** Такі сесії в PostgreSQL тримають знімок і заважають autovacuum.

## Вправи

1. Перевірте права користувача `readonly`: виконайте успішний `SELECT` і невдалий `CREATE TABLE` у MySQL та PostgreSQL.
2. Створіть у MySQL користувача `exercise_reporter` із правом лише `SELECT` на `learn`, покажіть привілеї через `SHOW GRANTS` і приберіть користувача.
3. Зробіть резервну копію лише схеми `shop` PostgreSQL у власному форматі, а потім відновіть її з очищенням наявних об'єктів.
4. Знайдіть у PostgreSQL три найдовші активні запити та скасуйте найдовший через `pg_cancel_backend`.
5. Перевірте кількість мертвих рядків у `shop.orders`, виконайте `VACUUM ANALYZE` і покажіть, що статистика оновилася.

## Відповіді

1.

```bash
docker exec -e MYSQL_PWD=readonly learn-mysql \
    mysql -ureadonly -e "SELECT COUNT(*) FROM learn.customers"
docker exec -e MYSQL_PWD=readonly learn-mysql \
    mysql -ureadonly -e "CREATE TABLE learn.spam (id INT)"

docker exec -e PGPASSWORD=readonly learn-postgres \
    psql -U readonly -d learn -c "SELECT count(*) FROM shop.customers;"
docker exec -e PGPASSWORD=readonly learn-postgres \
    psql -U readonly -d learn -c "CREATE TABLE shop.spam (id int);"
```

2.

```sql
CREATE USER 'exercise_reporter'@'%' IDENTIFIED BY 'Reporter_2026!';
GRANT SELECT ON learn.* TO 'exercise_reporter'@'%';
SHOW GRANTS FOR 'exercise_reporter'@'%';
DROP USER 'exercise_reporter'@'%';
```

3.

```bash
docker exec learn-postgres pg_dump -U student -d learn -n shop -Fc > shop_exercise.dump
docker exec -i learn-postgres pg_restore -U student -d learn \
    --clean --if-exists < shop_exercise.dump
```

4.

```sql
SELECT pid, now() - query_start AS duration, left(query, 80) AS query
FROM pg_stat_activity
WHERE state = 'active'
ORDER BY duration DESC
LIMIT 3;

SELECT pg_cancel_backend(<pid_найдовшого_запиту>);
```

5.

```sql
SELECT relname, n_dead_tup, last_vacuum, last_analyze
FROM pg_stat_user_tables
WHERE schemaname = 'shop' AND relname = 'orders';

VACUUM ANALYZE shop.orders;

SELECT relname, n_dead_tup, last_vacuum, last_analyze
FROM pg_stat_user_tables
WHERE schemaname = 'shop' AND relname = 'orders';
```

## Пов'язані матеріали

- Службові процедури MySQL: [`../lessons/procedures_mysql.sql`](../lessons/procedures_mysql.sql)
- Тригери аудиту PostgreSQL: [`../lessons/triggers_postgres.sql`](../lessons/triggers_postgres.sql)
- Керування стендом: `./manage.sh help`
- Веб-тренажер: http://localhost:8000/runner.php та http://localhost:8000/tasks.php
