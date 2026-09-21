# Learn DB — стенд для вивчення баз даних

Docker Compose стек із п'ятьма СУБД, веб-сервером PHP, тренажером із вправами та п'ятьма веб-інструментами адміністрування. Призначений для навчання: SQL, порівняння діалектів MySQL/MariaDB/PostgreSQL, документо-орієнтовані запити MongoDB, робота з Redis і написання PHP-застосунків із доступом до баз даних.

## Склад стенду

| Сервіс | Образ | Призначення | Порт на хості |
|---|---|---|---|
| `mysql` | mysql:8.4 | Реляційна СУБД | 3306 |
| `mariadb` | mariadb:11.4 | Форк MySQL | 3307 |
| `postgres` | postgres:17-alpine | Реляційна СУБД | 5432 |
| `mongo` | mongo:7 | Документо-орієнтована СУБД | 27017 |
| `redis` | redis:7-alpine | Сховище ключ-значення | 6379 |
| `web` | php:8.4-apache (власна збірка) | PHP/Apache: тренажер, вправи, пісочниця | 8000 |
| `adminer` | adminer:latest | Універсальний веб-клієнт | 8080 |
| `phpmyadmin` | phpmyadmin:latest | Веб-клієнт для MySQL/MariaDB | 8081 |
| `pgadmin` | dpage/pgadmin4:latest | Веб-клієнт для PostgreSQL | 5050 |
| `mongo-express` | mongo-express:latest | Веб-клієнт для MongoDB | 8082 |
| `redis-commander` | rediscommander/redis-commander | Веб-клієнт для Redis | 8083 |

Дані зберігаються в іменованих Docker-томах і не втрачаються після `docker compose down`.

## Вимоги

- Docker Engine 20.10+
- Docker Compose v2+

Перевірка:

```bash
docker --version
docker compose version
```

## Запуск

```bash
cd docker
./manage.sh up
```

Рівнозначно `docker compose up -d --build`, але `manage.sh` ще й показує статус після старту. Перший запуск завантажує образи (1–2 ГБ), збирає образ `web` (компіляція розширень `mongodb` і `redis` займає кілька хвилин) і виконує init-скрипти з навчальними даними. Зачекайте, доки контейнери стануть `healthy`:

```bash
docker compose ps
```

Очікуваний статус — `Up (healthy)` для `mysql`, `mariadb`, `postgres`, `mongo`, `redis`.

Після старту згенеруйте великі дані для тем індексів і продуктивності:

```bash
./manage.sh seed
```

Перевірити доступність усіх сервісів та отримати перелік адрес:

```bash
./manage.sh health
./manage.sh urls
```

## Облікові дані

| СУБД | Хост і порт | Користувач | Пароль | База даних |
|---|---|---|---|---|
| MySQL | `127.0.0.1:3306` | `root` | `root` | усі бази |
| MySQL | `127.0.0.1:3306` | `student` | `student` | `learn`, `shop_big`, `sandbox` |
| MySQL | `127.0.0.1:3306` | `readonly` | `readonly` | читання `learn`, `shop_big` |
| MariaDB | `127.0.0.1:3307` | `root` | `root` | усі бази |
| MariaDB | `127.0.0.1:3307` | `student` | `student` | `learn`, `shop_big`, `sandbox` |
| PostgreSQL | `127.0.0.1:5432` | `student` | `student` | `learn` (схеми `shop`, `shop_big`), `sandbox` |
| PostgreSQL | `127.0.0.1:5432` | `readonly` | `readonly` | читання `shop`, `shop_big` |
| MongoDB | `127.0.0.1:27017` | `root` | `student` | `learn`, `shop_big` (authSource `admin`) |
| Redis | `127.0.0.1:6379` | — | без пароля | — |

Пароль `root` для MySQL/MariaDB — адміністративний. Для навчальних запитів використовуйте `student`. Користувач `readonly` потрібен, щоб на практиці побачити різницю прав: він бачить дані, але не може їх змінити або створити таблицю.

Усі значення задаються у файлі `.env` — його можна редагувати до першого запуску. У PHP-скриптах ті самі дані задані у `www/config.php`.

## Веб-інтерфейси

### Adminer — http://localhost:8080

Універсальний клієнт, працює з усіма п'ятьма СУБД. На формі входу:

- **MySQL:** System `MySQL`, Server `mysql`, Username `student`, Password `student`, Database `learn`
- **MariaDB:** System `MySQL`, Server `mariadb`, Username `student`, Password `student`, Database `learn`
- **PostgreSQL:** System `PostgreSQL`, Server `postgres`, Username `student`, Password `student`, Database `learn`
- **MongoDB та Redis** Adminer не підтримує — використовуйте консольні клієнти (розділ нижче).

### phpMyAdmin — http://localhost:8081

За замовчуванням підключається до MySQL (`mysql` / `student` / `student`). Увімкнено режим довільного сервера: на сторінці входу в полі **Server** можна вказати `mariadb`, щоб перемкнутися на MariaDB (порт залишається 3306 — це внутрішній порт контейнера).

### pgAdmin — http://localhost:5050

Логін: `admin@example.com` / `admin` (у desktop-режимі може відкритися одразу без входу). Сервер `PostgreSQL (learn)` уже доданий у дерево зліва; при першому підключенні введіть пароль `student` і позначте **Save password**.

### Mongo Express — http://localhost:8082

Веб-клієнт для MongoDB: перегляд колекцій, документів, запити й агрегації. Авторизація не потрібна, підключення до бази налаштоване автоматично.

### Redis Commander — http://localhost:8083

Веб-клієнт для Redis: перегляд ключів, типів значень, TTL і виконання команд у консолі.

## Тренажер

Головний інструмент для навчання — власний застосунок на PHP (http://localhost:8000):

- **SQL Runner** (http://localhost:8000/runner.php) — виконання SELECT-запитів проти восьми баз (MySQL, MariaDB, PostgreSQL, `shop_big` та пісочниці) з показом часу виконання і плану (`EXPLAIN`, `EXPLAIN ANALYZE`) та експортом результату в CSV (до 5000 рядків). Для баз із групи «Пісочниця» дозволені будь-які операції — INSERT, UPDATE, DELETE, CREATE, DROP.
- **Схема баз** (http://localhost:8000/schema.php) — таблиці, колонки, типи, ключі, індекси та зовнішні ключі (зв'язки таблиць) будь-якої з дев'яти баз, прочитані з `information_schema` (завжди актуально).
- **NoSQL-консоль** (http://localhost:8000/nosql.php) — MongoDB `find` та агрегаційний конвеєр (`$match`, `$group`, `$unwind`, `$lookup`…) для баз `learn` і `shop_big`, а також команди Redis із прикладами. Небезпечні операції заборонено (`$out`, `$merge`, `FLUSHALL`, `CONFIG`, `SHUTDOWN`), команди Redis виконуються в окремій базі №5.
- **Вправи** (http://localhost:8000/tasks.php) — 55 задач із автоматичною перевіркою: 34 SQL (JOIN, GROUP BY, підзапити, CTE, віконні функції, плани запитів, діалекти PostgreSQL і MariaDB), 12 MongoDB (фільтри, проєкції, агрегаційні конвеєри на `learn` і `shop_big`) і 9 Redis (рядки, хеші, списки, множини, рейтинги). Ви пишете запит, система виконує його та порівнює результат з еталонним. Підказки та відповіді — на сторінці завдання. Прогрес зберігається в Redis (ключ `learn:done`).
- **Пісочниця** (http://localhost:8000/sandbox.php) — окремі бази `sandbox` у MySQL, MariaDB і PostgreSQL для вільних експериментів (INSERT, UPDATE, DELETE, CREATE). Кнопка «Скинути» повертає початковий стан і видаляє всі сторонні таблиці.
- **Транзакції та блокування** (http://localhost:8000/locks.php) — онлайн-демонстрації без двох терміналів: очікування блокування рядка (`Lock wait timeout` / `lock_timeout`), взаємне блокування з помилкою `Deadlock found` / `40P01`, різниця `REPEATABLE READ` і `READ COMMITTED` на живих даних пісочниці.
- **Конспекти** (http://localhost:8000/docs.php) — теорія з прикладами, типовими помилками та відповідями до вправ.

Поза браузером для тих самих тем є готові скрипти в каталозі `lessons/`:

```bash
docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/transactions_mysql.sql
docker exec -i learn-postgres psql -U student -d sandbox < lessons/jsonb_postgres.sql
docker cp lessons/mongo_aggregation.js learn-mongo:/tmp/lesson.js
docker exec learn-mongo mongosh -u root -p student --authenticationDatabase admin --file /tmp/lesson.js
docker exec -it learn-redis redis-cli
```

Повний перелік: `transactions_mysql.sql`, `transactions_postgres.sql`, `procedures_mysql.sql`, `triggers_postgres.sql`, `json_mysql.sql`, `jsonb_postgres.sql`, `fulltext_mysql.sql`, `fulltext_postgres.sql`, `mongo_aggregation.js`, `mongo_indexes.js`, `redis_basics.txt`.

## Керування стендом

Усі типові операції зібрані в `./manage.sh`:

```bash
./manage.sh help               # довідка з усіма командами
./manage.sh up                 # зібрати і запустити стенд
./manage.sh down               # зупинити й видалити контейнери (дані лишаються)
./manage.sh reset              # повністю скинути стенд разом із даними
./manage.sh health             # перевірити всі БД та веб-інтерфейси
./manage.sh test               # самоперевірка: підключення, 55 еталонів, обсяг shop_big
./manage.sh urls               # показати всі адреси й користувачів
./manage.sh seed               # згенерувати великі дані shop_big
./manage.sh seed-status        # показати обсяг даних shop_big
./manage.sh backup             # резервні копії всіх баз у каталог backups/
./manage.sh restore backups/mysql_20260921_120000.sql
./manage.sh shell mysql        # консоль: mysql | mariadb | postgres | mongo | redis
./manage.sh logs mysql         # логи сервісу
```

Резервне копіювання охоплює всі п'ять СУБД: `mysqldump`, `mariadb-dump`, `pg_dump`, `mongodump` (архів) і `BGSAVE` + копіювання `dump.rdb` для Redis. Тип відновлення визначається за префіксом імені файлу (`mysql_`, `mariadb_`, `postgres_`, `mongo_`, `redis_`).

## PHP-середовище

### Apache — http://localhost:8000

Файли з папки `www/` обробляються Apache і одразу доступні в браузері: `www/index.php` відкривається як http://localhost:8000/, `www/about.php` — як http://localhost:8000/about.php. Папка змонтована в контейнер, тому зміни видно без перезапуску — достатньо оновити сторінку.

Головна сторінка (http://localhost:8000/) — це dashboard: перевіряє підключення до всіх восьми баз і п'яти СУБД, показує стан сервісів і посилання на розділи тренажера та веб-інструменти.

Доступні розширення PHP: `pdo_mysql`, `mysqli`, `pdo_pgsql`, `pgsql`, `mongodb`, `redis`, `mbstring`, `opcache`. Додатково встановлено Composer.

Параметри підключення зберігаються у `www/config.php`. Хости — це імена сервісів у мережі Compose (`mysql`, `mariadb`, `postgres`, `mongo`, `redis`), порти — внутрішні (3306, 5432, 27017, 6379), а не ті, що опубліковані на хості.

Приклад власного скрипта `www/test.php`:

```php
<?php
$pdo = new PDO('mysql:host=mysql;port=3306;dbname=learn;charset=utf8mb4', 'student', 'student');
foreach ($pdo->query('SELECT full_name, city FROM customers') as $row) {
    echo htmlspecialchars($row['full_name']) . ' — ' . htmlspecialchars($row['city']) . '<br>';
}
```

Запуск скрипта з консолі та перевірка синтаксису:

```bash
docker exec -it learn-web php /var/www/html/test.php
docker exec learn-web php -l /var/www/html/index.php
```

Після зміни `web/Dockerfile` (наприклад, для додавання нових розширень PHP) образ потрібно перебудувати:

```bash
docker compose build web
docker compose up -d web
```

Контейнер читає файли з `www/` від імені користувача `www-data`. Якщо скрипту потрібен запис у папку (завантаження файлів, кеш, логи), створіть її з відповідними правами, наприклад `mkdir -p www/uploads && chmod 777 www/uploads` — для локального навчального стенду це прийнятно.

## Консольні клієнти

Якщо на хості встановлені клієнти:

```bash
mysql -h 127.0.0.1 -P 3306 -u student -p learn
mysql -h 127.0.0.1 -P 3307 -u student -p learn
psql -h 127.0.0.1 -p 5432 -U student -d learn
mongosh "mongodb://root:student@127.0.0.1:27017/learn?authSource=admin"
redis-cli -h 127.0.0.1 -p 6379
```

Якщо клієнтів немає, використовуйте контейнерні:

```bash
docker exec -it learn-mysql mysql -ustudent -pstudent learn
docker exec -it learn-mariadb mariadb -ustudent -pstudent learn
docker exec -it learn-postgres psql -U student -d learn
docker exec -it learn-mongo mongosh -u root -p student --authenticationDatabase admin
docker exec -it learn-redis redis-cli
```

У графічних клієнтах (DBeaver, DataGrip, TablePlus) використовуйте ті самі хост, порт і користувача. Для PostgreSQL схема за замовчуванням — `shop`, для MongoDB — база `learn`.

## Навчальні дані

| СУБД | База | Таблиці / колекції |
|---|---|---|
| MySQL | `learn` | `customers`, `products`, `orders` — інтернет-магазин (5–6 рядків) |
| MariaDB | `learn` | `authors`, `books` — бібліотека |
| PostgreSQL | `learn` (схема `shop`) | `customers`, `products`, `orders` — інтернет-магазин |
| MongoDB | `learn` | `students`, `courses` |
| Redis | — | порожній, наповнюється вручну |
| MySQL / MariaDB / PostgreSQL | `shop_big` | те саме, але 10 000 клієнтів, 1 000 товарів, 100 000 замовлень — для індексів і `EXPLAIN` |
| MongoDB | `shop_big` | `customers`, `products`, `orders` зі вкладеними `items` — для агрегацій |
| MySQL / MariaDB / PostgreSQL | `sandbox` | копія магазину для вільних експериментів (скидається кнопкою) |

Великі бази створюються командою `./manage.sh seed`. Без них вправи з теми «Індекси та плани» працювати не будуть.

Приклади для старту:

```sql
-- MySQL / PostgreSQL
SELECT c.full_name, p.title, o.quantity
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
ORDER BY o.ordered_at;

SELECT city, COUNT(*) AS customers
FROM customers
GROUP BY city
ORDER BY customers DESC;
```

```javascript
// MongoDB
db.students.find({ grade: { $gte: 85 } }, { name: 1, city: 1, grade: 1 })
db.students.aggregate([
  { $unwind: "$courses" },
  { $group: { _id: "$courses", count: { $sum: 1 } } }
])
```

```bash
# Redis
redis-cli SET hello "world"
redis-cli GET hello
redis-cli TTL hello
```

## Низькорівневе керування (docker compose)

```bash
docker compose up -d            # запустити всі сервіси
docker compose build web        # перебудувати образ PHP після зміни Dockerfile
docker compose ps               # статус контейнерів
docker compose logs -f mysql    # логи конкретного сервісу
docker compose restart postgres # перезапуск одного сервісу
docker compose stop             # зупинити без видалення
docker compose down             # зупинити й видалити контейнери (дані лишаються)
docker compose down -v          # видалити контейнери й томи з даними
```

Для типових операцій зручніше використовувати `./manage.sh` (див. розділ «Керування стендом»).

Перезапуск одного контейнера:

```bash
docker restart learn-mysql
```

## Структура проєкту

```
docker/
├── docker-compose.yml      # опис усіх сервісів
├── .env                    # порти та паролі
├── manage.sh               # керування: up/down/reset/seed/backup/restore/shell
├── README.md
├── mysql/init/             # схема, пісочниця, користувачі
├── mariadb/init/
├── postgres/init/
├── mongo/init/
├── seed/                   # генератори shop_big для чотирьох СУБД
├── pgadmin/servers.json    # попередньо налаштований сервер для pgAdmin
├── web/Dockerfile          # PHP 8.4 + Apache з розширеннями підключення до БД
├── www/                    # тренажер: index.php, runner.php, tasks.php, task.php, sandbox.php, docs.php
│   ├── assets/style.css
│   ├── tasks/tasks.json    # 34 SQL-вправи з еталонними запитами
│   ├── tasks/nosql.json    # 21 NoSQL-вправа (MongoDB, Redis)
│   └── sandbox/            # SQL для скидання пісочниці
├── docs/                   # конспекти (11 тем), які рендерить www/docs.php
├── lessons/                # готові SQL/JS-демонстрації: транзакції, тригери, JSON, fulltext
└── backups/                # створюється під час ./manage.sh backup
```

Скрипти з `*/init/` виконуються автоматично **лише при першому створенні тому** (порожній базі). Щоб змінити дані й застосувати скрипти заново, видаліть томи:

```bash
docker compose down -v
docker compose up -d
```

Скрипти MySQL/MariaDB починаються з `SET NAMES utf8mb4` — без цього кирилиця з SQL-файлу збережеться у спотвореному вигляді.

## Налаштування

### Зміна паролів або портів

Відредагуйте `.env` і перезапустіть:

```bash
docker compose down
docker compose up -d
```

Важливо: зміна пароля в `.env` не оновлює пароль у вже створеній базі — він задається під час ініціалізації. Щоб застосувати новий пароль, потрібно видалити томи (`down -v`).

### Додавання нового користувача або бази

Для MySQL/MariaDB виконайте `CREATE USER` / `CREATE DATABASE` під `root`. Для PostgreSQL — `CREATE ROLE` / `CREATE DATABASE` під користувачем `student`, який має права суперкористувача в цьому контейнері.

Після зміни складу сервісів завжди перевіряйте конфігурацію:

```bash
docker compose config -q
```

## Типові проблеми

**`port is already allocated`** — порт зайнятий іншим процесом. Змініть значення `*_PORT` у `.env` і виконайте `docker compose up -d`.

**Контейнер постійно перезапускається** — перегляньте логи: `docker compose logs <сервіс>`. Найчастіша причина — пошкоджений або зайнятий тому даних.

**Зміни в базі не зберігаються** — переконайтеся, що не виконували `docker compose down -v`; прапорець `-v` видаляє томи.

**pgAdmin не бачить сервер `PostgreSQL (learn)`** — `servers.json` імпортується лише при першому створенні тому `pgadmin_data`. Скиньте його: `docker compose down && docker volume rm learn-db_pgadmin_data && docker compose up -d`, або додайте сервер вручну: Host `postgres`, Port `5432`, Username `student`, Database `learn`.

**Adminer не підключається до MariaDB** — у полі Server вкажіть `mariadb` (не `localhost`): зсередини контейнера адреса — це ім'я сервісу в мережі `learn-db_learn-net`.

**Мала швидкість MongoDB на macOS/Windows** — додайте `platform: linux/amd64` у сервіс `mongo`, якщо образ не має нативної збірки для вашої архітектури.

**Кирилиця в консольному клієнті MySQL показується як `????`** — це кодування клієнта, а не втрата даних. Використовуйте `docker exec learn-mysql mysql --default-character-set=utf8mb4 -ustudent -pstudent learn` або переглядайте дані через PHP чи Adminer.

**PHP-скрипт не працює, у логах `could not find driver`** — у контейнері `web` немає потрібного розширення. Додайте його в `web/Dockerfile`, після чого виконайте `docker compose build web && docker compose up -d web`.

**`servers.json` не оновлюється в pgAdmin** — файл читається при першому запуску; після редагування перезапустіть контейнер `docker compose restart pgadmin`.

**Вправа з індексів падає з `Table 'shop_big.orders' doesn't exist`** — не згенеровані великі дані. Виконайте `./manage.sh seed`.

**`ERROR 1419: You do not have the SUPER privilege`** під час створення функції — у контейнерах MySQL/MariaDB увімкнено `--log-bin-trust-function-creators=1`, проблема може виникнути лише після ручної зміни `command` у compose. Поверніть параметр і перезапустіть сервіс.

**Користувач `readonly` не може створити таблицю в `learn`** — так і задумано: його права обмежені `SELECT`. Для повного доступу використовуйте `student`, для експериментів — базу `sandbox`.

**Пісочниця повертає помилку прав** — переконайтеся, що застосовані init-скрипти `02_sandbox.sql` (вони виконуються автоматично при першому створенні тому). Для наявних томів застосуйте вручну:
`docker exec -i -e MYSQL_PWD=root learn-mysql mysql -uroot < mysql/init/02_sandbox.sql`.

## Корисні посилання

| Що | Де |
|---|---|
| Тренажер | http://localhost:8000 |
| SQL Runner | http://localhost:8000/runner.php |
| NoSQL-консоль | http://localhost:8000/nosql.php |
| Схема баз | http://localhost:8000/schema.php |
| Вправи (55) | http://localhost:8000/tasks.php |
| Пісочниця | http://localhost:8000/sandbox.php |
| Транзакції та блокування | http://localhost:8000/locks.php |
| Конспекти | http://localhost:8000/docs.php |
| Готові SQL-демонстрації | каталог `lessons/` |

## Повне видалення стенду

```bash
docker compose down -v --rmi all
docker system prune -f
```
