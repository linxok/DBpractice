# Learn DB — стенд для вивчення баз даних

Локальний Docker Compose стенд із п'ятьма СУБД, веб-тренажером і п'ятьма інструментами адміністрування. Призначений для практичного навчання: SQL і діалекти MySQL / MariaDB / PostgreSQL, MongoDB, Redis, транзакції, індекси, права доступу та написання PHP-застосунків із доступом до баз.

Головне — тренажер на http://localhost:8000: 55 вправ із автоматичною перевіркою, SQL Runner із планами запитів, NoSQL-консоль, пісочниці, онлайн-демонстрації блокувань і 11 конспектів українською.

## Зміст

- [Швидкий старт](#швидкий-старт)
- [Склад стенду](#склад-стенду)
- [Тренажер](#тренажер)
- [Облікові дані](#облікові-дані)
- [Веб-інтерфейси](#веб-інтерфейси)
- [Консольні клієнти](#консольні-клієнти)
- [Навчальні дані](#навчальні-дані)
- [Керування стендом](#керування-стендом)
- [PHP-середовище](#php-середовище)
- [Як розширювати стенд](#як-розширювати-стенд)
- [Структура проєкту](#структура-проєкту)
- [Налаштування](#налаштування)
- [Типові проблеми](#типові-проблеми)
- [Швидкі посилання](#швидкі-посилання)
- [Повне видалення стенду](#повне-видалення-стенду)

## Швидкий старт

Потрібні Docker Engine 20.10+ і Docker Compose v2+ (`docker --version`, `docker compose version`).

```bash
cd docker
./manage.sh up        # перший запуск: образи + збірка web (кілька хвилин)
./manage.sh seed      # великі дані shop_big для тем індексів
./manage.sh test      # самоперевірка: підключення, 55 еталонів, обсяг даних
```

Після цього відкрийте http://localhost:8000 — головну сторінку тренажера, або `./manage.sh urls` для переліку всіх адрес.

Мінімальний сценарій знайомства: головна сторінка → «Вправи» → перша задача з теми SELECT → «SQL Runner» із запитом до `shop_big` у режимі `EXPLAIN` → «Блокування» → «Конспекти».

## Склад стенду

| Сервіс | Образ | Призначення | Порт |
|---|---|---|---|
| `mysql` | mysql:8.4 | Реляційна СУБД | 3306 |
| `mariadb` | mariadb:11.4 | Форк MySQL | 3307 |
| `postgres` | postgres:17-alpine | Реляційна СУБД | 5432 |
| `mongo` | mongo:7 | Документо-орієнтована СУБД | 27017 |
| `redis` | redis:7-alpine | Сховище ключ-значення | 6379 |
| `web` | php:8.4-apache (власна збірка) | PHP/Apache: тренажер, вправи, пісочниці | 8000 |
| `adminer` | adminer:latest | Веб-клієнт для SQL-баз | 8080 |
| `phpmyadmin` | phpmyadmin:latest | Веб-клієнт для MySQL/MariaDB | 8081 |
| `pgadmin` | dpage/pgadmin4:latest | Веб-клієнт для PostgreSQL | 5050 |
| `mongo-express` | mongo-express:latest | Веб-клієнт для MongoDB | 8082 |
| `redis-commander` | rediscommander/redis-commander | Веб-клієнт для Redis | 8083 |

Дані зберігаються в іменованих Docker-томах і не втрачаються після `docker compose down` (але видаляються разом із `-v` або `./manage.sh reset`).

## Тренажер

Власний застосунок на PHP (http://localhost:8000) — основне середовище навчання:

| Розділ | Що робить |
|---|---|
| **SQL Runner** `/runner.php` | Виконує SELECT-запити до дев'яти SQL-баз (MySQL, MariaDB, PostgreSQL, три `shop_big` і три пісочниці), показує час і план (`EXPLAIN`, `EXPLAIN ANALYZE`), експортує результат у CSV (до 5000 рядків). У пісочницях дозволені INSERT, UPDATE, DELETE, CREATE, DROP. |
| **NoSQL-консоль** `/nosql.php` | MongoDB `find` та агрегаційний конвеєр (`$match`, `$group`, `$unwind`, `$lookup`…) для `learn` і `shop_big`; команди Redis із прикладами. Заборонено `$out`, `$merge`, `FLUSHALL`, `CONFIG`, `SHUTDOWN`; Redis працює в окремій базі №5. |
| **Схема баз** `/schema.php` | Таблиці, колонки, типи, ключі, індекси та зовнішні ключі (зв'язки) будь-якої бази — з `information_schema`, завжди актуально. |
| **Вправи** `/tasks.php` | 55 задач із автоперевіркою: 34 SQL, 12 MongoDB, 9 Redis. Підказки, еталонні відповіді, збереження прогресу. |
| **Пісочниця** `/sandbox.php` | Бази `sandbox` у MySQL, MariaDB, PostgreSQL для вільних експериментів. «Скинути» повертає початковий стан і видаляє сторонні таблиці. |
| **Транзакції та блокування** `/locks.php` | Онлайн-сценарії: очікування блокування рядка, deadlock (`1213` / `40P01`), різниця `REPEATABLE READ` і `READ COMMITTED`. |
| **Конспекти** `/docs.php` | 11 тем із прикладами, типовими помилками та відповідями. |

**Прогрес вправ** зберігається в Redis (множина `learn:done`): на сторінці списку видно «Виконано X з 55», біля кожної задачі — позначку, а скинути можна однією кнопкою.

**Готові демонстрації поза браузером** — каталог `lessons/`:

```bash
docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/transactions_mysql.sql
docker exec -i learn-postgres psql -U student -d sandbox < lessons/jsonb_postgres.sql
docker cp lessons/mongo_aggregation.js learn-mongo:/tmp/lesson.js
docker exec learn-mongo mongosh -u root -p student --authenticationDatabase admin --file /tmp/lesson.js
docker exec -it learn-redis redis-cli
```

Перелік: `transactions_mysql.sql`, `transactions_postgres.sql`, `procedures_mysql.sql`, `triggers_postgres.sql`, `json_mysql.sql`, `jsonb_postgres.sql`, `fulltext_mysql.sql`, `fulltext_postgres.sql`, `mongo_aggregation.js`, `mongo_indexes.js`, `redis_basics.txt`.

## Облікові дані

| СУБД | Хост і порт | Користувач | Пароль | Доступ |
|---|---|---|---|---|
| MySQL | `127.0.0.1:3306` | `root` | `root` | усі бази |
| MySQL | `127.0.0.1:3306` | `student` | `student` | `learn`, `shop_big`, `sandbox` |
| MySQL | `127.0.0.1:3306` | `readonly` | `readonly` | читання `learn`, `shop_big` |
| MariaDB | `127.0.0.1:3307` | `root` / `student` | `root` / `student` | як у MySQL |
| PostgreSQL | `127.0.0.1:5432` | `student` | `student` | `learn` (схеми `shop`, `shop_big`), `sandbox` |
| PostgreSQL | `127.0.0.1:5432` | `readonly` | `readonly` | читання `shop`, `shop_big` |
| MongoDB | `127.0.0.1:27017` | `root` | `student` | `learn`, `shop_big` (authSource `admin`) |
| Redis | `127.0.0.1:6379` | — | без пароля | — |

`root` — адміністративний користувач MySQL/MariaDB/MongoDB. Для навчальних запитів використовуйте `student`. Користувач `readonly` існує, щоб на практиці побачити обмеження прав: він читає дані, але не може їх змінити чи створити таблицю.

Усі значення задаються у `.env` до першого запуску; у PHP ті самі дані — у `www/config.php`.

## Веб-інтерфейси

| Інструмент | Адреса | Вхід |
|---|---|---|
| Adminer | http://localhost:8080 | Server `mysql` / `mariadb` / `postgres`, User `student`, Password `student`, DB `learn` |
| phpMyAdmin | http://localhost:8081 | одразу MySQL; для MariaDB вкажіть сервер `mariadb` (порт 3306) |
| pgAdmin | http://localhost:5050 | `admin@example.com` / `admin`; сервер `PostgreSQL (learn)` уже доданий, пароль `student` |
| Mongo Express | http://localhost:8082 | без авторизації, підключення налаштоване |
| Redis Commander | http://localhost:8083 | без авторизації |

Adminer і phpMyAdmin працюють із SQL-базами; MongoDB та Redis дивіться через Mongo Express, Redis Commander або NoSQL-консоль тренажера.

## Консольні клієнти

З хоста (якщо клієнти встановлені):

```bash
mysql -h 127.0.0.1 -P 3306 -u student -p learn
mysql -h 127.0.0.1 -P 3307 -u student -p learn
psql -h 127.0.0.1 -p 5432 -U student -d learn
mongosh "mongodb://root:student@127.0.0.1:27017/learn?authSource=admin"
redis-cli -h 127.0.0.1 -p 6379
```

Без клієнтів на хості:

```bash
./manage.sh shell mysql      # mysql | mariadb | postgres | mongo | redis
```

DBeaver / DataGrip / TablePlus: ті самі хост, порт і користувач; для PostgreSQL зверніть увагу на схеми `shop` і `shop_big`.

## Навчальні дані

| СУБД | База | Вміст |
|---|---|---|
| MySQL | `learn` | `customers`, `products`, `orders` — інтернет-магазин, малі дані |
| MariaDB | `learn` | `authors`, `books` — бібліотека |
| PostgreSQL | `learn` (схема `shop`) | `customers`, `products`, `orders` |
| MongoDB | `learn` | `students`, `courses` |
| MySQL / MariaDB / PostgreSQL | `shop_big` | 10 000 клієнтів, 1 000 товарів, 100 000 замовлень — для індексів і планів |
| MongoDB | `shop_big` | `customers`, `products`, `orders` зі вкладеними `items` — для агрегацій |
| MySQL / MariaDB / PostgreSQL | `sandbox` | копія магазину для експериментів (скидається кнопкою) |
| Redis | — | порожній; практика в базі №5 через NoSQL-консоль |

Великі бази створюються командою `./manage.sh seed` (близько 10 секунд). Без них вправи з теми «Індекси та плани» не працюватимуть.

## Керування стендом

```bash
./manage.sh help               # довідка з усіма командами
./manage.sh up                 # зібрати і запустити стенд
./manage.sh down               # зупинити й видалити контейнери (дані лишаються)
./manage.sh reset              # повністю скинути стенд разом із даними
./manage.sh restart mysql      # перезапустити сервіс
./manage.sh ps                 # статус контейнерів
./manage.sh logs mysql         # логи (з -f)
./manage.sh health             # перевірити всі БД та веб-інтерфейси
./manage.sh test               # самоперевірка: підключення, 55 еталонів, обсяг даних
./manage.sh urls               # усі адреси й користувачі
./manage.sh seed               # згенерувати shop_big
./manage.sh seed-status        # показати обсяг shop_big
./manage.sh backup             # бекап усіх баз у backups/
./manage.sh restore <файл>     # відновлення з бекапу
./manage.sh shell mysql        # консоль до бази
```

Резервне копіювання використовує `mysqldump`, `mariadb-dump`, `pg_dump` (`--clean --if-exists`), `mongodump --archive --gzip` і `BGSAVE` для Redis. Тип відновлення визначається за префіксом імені файлу: `mysql_`, `mariadb_`, `postgres_`, `mongo_`, `redis_`.

Низькорівневі команди (якщо потрібен повний контроль):

```bash
docker compose up -d            # запустити всі сервіси
docker compose build web        # перебудувати PHP-образ після зміни Dockerfile
docker compose ps               # статус
docker compose down -v          # видалити контейнери й томи з даними
```

## PHP-середовище

- Apache + PHP 8.4, каталог `www/` змонтований у контейнер: новий файл `www/about.php` одразу доступний як http://localhost:8000/about.php.
- Розширення: `pdo_mysql`, `mysqli`, `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `mongodb`, `redis`, `mbstring`, `opcache`; встановлено Composer.
- Хости для підключення — імена сервісів (`mysql`, `mariadb`, `postgres`, `mongo`, `redis`), порти внутрішні (3306, 5432, 27017, 6379).
- Параметри підключення — `www/config.php`; спільні функції — `www/lib.php`.

Приклад власного скрипта `www/test.php`:

```php
<?php
$pdo = new PDO('mysql:host=mysql;port=3306;dbname=learn;charset=utf8mb4', 'student', 'student');
foreach ($pdo->query('SELECT full_name, city FROM customers') as $row) {
    echo htmlspecialchars($row['full_name']) . ' — ' . htmlspecialchars($row['city']) . '<br>';
}
```

```bash
docker exec -it learn-web php /var/www/html/test.php   # запуск
docker exec learn-web php -l /var/www/html/index.php   # перевірка синтаксису
```

Після зміни `web/Dockerfile` перебудуйте образ: `./manage.sh up` (він виконує `--build`) або `docker compose build web && docker compose up -d web`.

Якщо скрипту потрібен запис у каталог (завантаження, кеш), створіть його з відповідними правами: `mkdir -p www/uploads && chmod 777 www/uploads` — для локального стенду це прийнятно.

## Як розширювати стенд

**Власна SQL-вправа** — додайте об'єкт у `www/tasks/tasks.json`:

```json
{
  "id": "select-99",
  "topic": "SELECT",
  "level": "Початковий",
  "target": "mysql",
  "title": "Назва задачі",
  "description": "Що потрібно зробити.",
  "hint": "Підказка.",
  "reference": "SELECT ...",
  "ordered": false,
  "check": "rows"
}
```

Поля: `target` — будь-яка ціль із `www/config.php`; `ordered` — чи важливий порядок рядків; `check` — `rows` (порівняння результату) або `plan` (наявність підрядка в плані, тоді додається `plan_must_contain`).

**Власна NoSQL-вправа** — у `www/tasks/nosql.json`: для MongoDB вкажіть `engine: "mongo"`, `database` і `reference` у форматі `{"collection": "...", "find": {...}}` або з `pipeline`; для Redis — `engine: "redis"` і `reference` як масив команд.

**Власний конспект** — створіть `docs/12-tema.md`; сторінка `/docs.php` підхопить його автоматично (Markdown із заголовками, таблицями, списками та код-блоками).

**Власна демонстрація** — додайте SQL/JS-файл у `lessons/` і посилання на нього в конспекті.

## Структура проєкту

```
docker/
├── docker-compose.yml      # 11 сервісів
├── .env                    # порти й паролі
├── manage.sh               # керування стендом
├── mysql/init/             # схема, пісочниця, користувачі
├── mariadb/init/
├── postgres/init/
├── mongo/init/
├── seed/                   # генератори shop_big (MySQL, MariaDB, PostgreSQL, MongoDB)
├── pgadmin/servers.json
├── web/Dockerfile          # PHP 8.4 + Apache + розширення БД
├── www/                    # тренажер
│   ├── index.php           # огляд і статус підключень
│   ├── runner.php          # SQL Runner
│   ├── nosql.php           # NoSQL-консоль
│   ├── schema.php          # схема та зв'язки
│   ├── tasks.php, task.php # вправи
│   ├── sandbox.php         # пісочниці
│   ├── locks.php, lock_worker.php  # сценарії транзакцій
│   ├── docs.php            # рендер конспектів
│   ├── lib.php, config.php # спільні функції та цілі підключень
│   ├── assets/style.css
│   ├── tasks/tasks.json    # 34 SQL-вправи
│   ├── tasks/nosql.json    # 21 NoSQL-вправа
│   ├── sandbox/            # SQL скидання пісочниць
│   └── tools/selftest.php  # самоперевірка (manage.sh test)
├── docs/                   # 11 конспектів
├── lessons/                # 11 виконуваних демонстрацій
└── backups/                # створюється під час backup
```

Скрипти з `*/init/` виконуються **лише при першому створенні тому**. Щоб застосувати їх заново, видаліть томи (`./manage.sh reset`). Для MySQL/MariaDB скрипти починаються з `SET NAMES utf8mb4` — без цього кирилиця з SQL-файлу збережеться спотвореною.

## Налаштування

**Пароли й порти.** Відредагуйте `.env` і перезапустіть (`./manage.sh down && ./manage.sh up`). Зміна пароля не оновлює його в уже створеній базі — пароль задається під час ініціалізації, тому потрібне або `ALTER USER`, або скидання томів.

**Новий користувач або база.** MySQL/MariaDB — `CREATE USER` / `CREATE DATABASE` під `root`; PostgreSQL — `CREATE ROLE` / `CREATE DATABASE` під `student` (він суперкористувач у цьому контейнері). Приклад є в конспекті `docs/11-administration.md`.

**Перевірка конфігурації** після зміни compose-файлу:

```bash
docker compose config -q
```

## Типові проблеми

**`port is already allocated`** — порт зайнятий. Змініть відповідний `*_PORT` у `.env` і виконайте `./manage.sh up`.

**Контейнер перезапускається** — `./manage.sh logs <сервіс>`; найчастіша причина — пошкоджений том даних.

**Зміни в базі не зберігаються** — не використовуйте `down -v` / `reset`, якщо хочете зберегти дані.

**`Table 'shop_big.orders' doesn't exist`** у вправах — не згенеровані великі дані: `./manage.sh seed`.

**Кирилиця в консольному MySQL виглядає як `????`** — це кодування клієнта, дані цілі. Використовуйте `./manage.sh shell mysql` (там увімкнено `utf8mb4`) або переглядайте через PHP/Adminer.

**`could not find driver`** — у контейнері `web` немає потрібного розширення PHP. Додайте його в `web/Dockerfile` і перебудуйте образ.

**pgAdmin не бачить сервер `PostgreSQL (learn)`** — `servers.json` читається при першому створенні тому. Або скиньте том `learn-db_pgadmin_data`, або додайте сервер вручну: Host `postgres`, Port `5432`, User `student`, DB `learn`.

**Adminer не підключається до MariaDB** — у полі Server вкажіть `mariadb` (ім'я сервісу, не `localhost`).

**`readonly` не може створити таблицю** — так і задумано; для експериментів є `sandbox`, для повного доступу — `student`.

**Пісочниця повертає помилку прав на наявних томах** — застосуйте init-скрипт вручну:
`docker exec -i -e MYSQL_PWD=root learn-mysql mysql -uroot < mysql/init/02_sandbox.sql`.

**`ERROR 1419: SUPER privilege`** під час створення функції — у MySQL/MariaDB увімкнено `--log-bin-trust-function-creators=1`; перевірте, чи не змінено `command` у compose.

**MongoDB повільна на macOS/Windows** — додайте `platform: linux/amd64` сервісу `mongo`, якщо немає нативної збірки.

## Швидкі посилання

| Що | Де |
|---|---|
| Тренажер (огляд) | http://localhost:8000 |
| SQL Runner | http://localhost:8000/runner.php |
| NoSQL-консоль | http://localhost:8000/nosql.php |
| Схема баз | http://localhost:8000/schema.php |
| Вправи (55) | http://localhost:8000/tasks.php |
| Пісочниця | http://localhost:8000/sandbox.php |
| Транзакції та блокування | http://localhost:8000/locks.php |
| Конспекти (11) | http://localhost:8000/docs.php |
| Демонстрації | каталог `lessons/` |
| Зовнішні інструменти | Adminer 8080 · phpMyAdmin 8081 · pgAdmin 5050 · Mongo Express 8082 · Redis Commander 8083 |

## Повне видалення стенду

```bash
./manage.sh down
docker compose down -v --rmi all
docker system prune -f
```
