# 09. Redis 7

## Навіщо ця тема

Redis — сховище даних у пам'яті типу «ключ-значення». Воно тримає дані в оперативній пам'яті, тому відповідає за мікросекунди, і підтримує багато структур: рядки, списки, хеші, множини, сортедовані множини. Звідси типові задачі: кеш результатів SQL-запитів, сесії користувачів, черги завдань, лічильники, рейтинги, обмеження частоти запитів.

Тема важлива для розуміння архітектури застосунків: Redis не замінює реляційну базу, а розвантажує її. У цьому розділі всі команди виконуються в контейнері `learn-redis`, дані можна переглядати через Redis Commander.

## Підключення

Консоль `redis-cli`:

```bash
docker exec -it learn-redis redis-cli
```

Одна команда без інтерактивного режиму:

```bash
docker exec -it learn-redis redis-cli SET hello "world"
docker exec -it learn-redis redis-cli GET hello
docker exec -it learn-redis redis-cli TTL hello
```

З хоста, якщо встановлено redis-cli:

```bash
redis-cli -h 127.0.0.1 -p 6379
```

Веб-інтерфейс Redis Commander:

```text
http://localhost:8083
```

Redis Commander показує дерево ключів ліворуч. Кнопка «+» створює ключ, клік по ключу відкриває редактор значення, вкладка Console дозволяє виконувати довільні команди. Інтерфейс періодично оновлюється, тому зміни з `redis-cli` з'являються в ньому без перезавантаження.

Перевірка з'єднання та версії:

```text
PING
PONG

INFO server
# redis_version:7.x.x
```

## Синтаксис

```text
КОМАНДА ключ [аргументи]
```

```text
SET ключ значення [EX секунди] [NX | XX]
GET ключ
DEL ключ [ключ ...]

HSET ключ поле значення [поле значення ...]
HGET ключ поле

LPUSH ключ значення [значення ...]
LRANGE ключ початок кінець

SADD ключ елемент [елемент ...]
SMEMBERS ключ

ZADD ключ бал елемент [бал елемент ...]
ZRANGE ключ початок кінець [WITHSCORES]

MULTI ... EXEC
SUBSCRIBE канал
PUBLISH канал повідомлення
```

Особливості мови команд:

- команди нечутливі до регістру: `SET`, `set` і `Set` — те саме;
- імена ключів чутливі до регістру;
- аргументи з пробілами беруть у подвійні лапки;
- кожна команда повертає результат певного типу: рядок, число, масив або `(nil)`;
- довідка: `HELP SET`, `COMMAND DOCS GET`.

## Простори імен і ключі

Redis не має таблиць і баз у звичному розумінні: є 16 логічних баз (`SELECT 0..15`), а ключі прийнято групувати префіксами через двокрапку:

```text
user:42:profile
user:42:cart
cache:product:1001
session:abc123
ratelimit:user:42:2026-09-21
queue:emails
leaderboard:sql-course
```

Довгі ключі збільшують споживання пам'яті, короткі ускладнюють підтримку. Орієнтовне правило: 3–5 сегментів, зрозумілих із назви.

Базові операції з ключами:

```text
EXISTS user:42:profile
TYPE user:42:profile
DEL user:42:profile
UNLINK user:42:profile        # асинхронне видалення великого ключа
RENAME old:key new:key
DBSIZE                        # кількість ключів у поточній базі
```

## Рядки (strings)

Найпростіший тип: будь-які байти, зокрема JSON і числа.

```text
SET hello "Привіт"
GET hello
APPEND hello ", Redis"
STRLEN hello

SET counter:visits 0
INCR counter:visits
INCRBY counter:visits 10
GET counter:visits

MSET user:1:city "Київ" user:2:city "Львів"
MGET user:1:city user:2:city
GETSET counter:visits 0
```

Запис лише за відсутності ключа (захист від перезапису):

```text
SET lock:report worker-1 NX EX 30
OK
SET lock:report worker-2 NX EX 30
(nil)
```

`NX` — встановити, тільки якщо ключа немає; `EX 30` — TTL 30 секунд. Це основа розподілених замків і захисту від «лавини» при оновленні кешу.

## Списки (lists)

Список — впорядкована послідовність. Зручний як черга або стрічка подій.

```text
RPUSH queue:emails "лист-1" "лист-2" "лист-3"
LRANGE queue:emails 0 -1
LLEN queue:emails
LPOP queue:emails
RPOP queue:emails
LINDEX queue:emails 0
LTRIM queue:emails 0 9          # залишити останні 10 елементів
```

Черга завдань: продюсер пише зліва, воркер читає справа (`LPUSH` + `RPOP`), або навпаки. Блокуюче читання не витрачає ресурс на опитування:

```text
# Термінал 1 (воркер чекає до 5 секунд)
BRPOP queue:emails 5

# Термінал 2
LPUSH queue:emails "новий лист"
```

Надійніша черга використовує `LMOVE` (у Redis 7 — `LMOVE`/`BLMOVE`) для перенесення завдання в список «в обробці», щоб воно не загубилося при падінні воркера:

```text
LPUSH queue:jobs "job:1"
LMOVE queue:jobs queue:processing LEFT RIGHT
LRANGE queue:processing 0 -1
LREM queue:processing 1 "job:1"
```

## Хеші (hashes)

Хеш — це вкладений набір пар «поле: значення», зручний для об'єктів.

```text
HSET session:abc123 user_id 42 role "student" authenticated 1
HGET session:abc123 role
HMGET session:abc123 user_id role
HGETALL session:abc123
HEXISTS session:abc123 role
HINCRBY session:abc123 visits 1
HDEL session:abc123 authenticated
HLEN session:abc123
HKEYS session:abc123
HVALS session:abc123
```

Сесія з часом життя:

```text
HSET session:abc123 user_id 42 created_at "2026-09-21T10:00:00Z"
EXPIRE session:abc123 3600
TTL session:abc123
```

Окремі поля хешу можна оновлювати без перезапису всього об'єкта, що економніше за зберігання JSON у рядку, коли потрібні лише частини.

## Множини (sets)

Множина зберігає унікальні елементи без порядку. Типові задачі: теги, унікальні відвідувачі, перевірка членства, перетин інтересів.

```text
SADD tags:post:1 "sql" "mysql" "postgres"
SADD tags:post:2 "sql" "mongodb"
SMEMBERS tags:post:1
SISMEMBER tags:post:1 "mysql"
SCARD tags:post:1
SREM tags:post:1 "mysql"

SINTER tags:post:1 tags:post:2        # спільні теги
SUNION tags:post:1 tags:post:2        # усі теги
SDIFF tags:post:1 tags:post:2         # є в першому, немає в другому
```

Підрахунок унікальних відвідувачів за день:

```text
SADD visitors:2026-09-21 "user:1" "user:2" "user:1"
SCARD visitors:2026-09-21
```

## Сортедовані множини (sorted sets)

Кожен елемент має числовий бал (score), за яким множина автоматично впорядкована. Ідеально для рейтингів і черг із пріоритетом.

```text
ZADD leaderboard 92 "Олена" 85 "Ігор" 78 "Марія"
ZRANGE leaderboard 0 -1 WITHSCORES
ZREVRANGE leaderboard 0 1 WITHSCORES
ZSCORE leaderboard "Ігор"
ZRANK leaderboard "Ігор"
ZINCRBY leaderboard 5 "Ігор"
ZRANGEBYSCORE leaderboard 80 100 WITHSCORES
ZCARD leaderboard
ZREM leaderboard "Марія"
```

Рейтинг студентів за оцінкою:

```text
ZADD students:rating 92 "olena@example.com" 85 "ihor@example.com" 78 "maria@example.com"
ZREVRANGE students:rating 0 2 WITHSCORES
```

Черга з пріоритетом: бал — час виконання у Unix-часі, тому `ZRANGEBYSCORE queue:delayed 0 <now> LIMIT 0 10` повертає завдання, час яких настав.

## Час життя ключів (TTL)

TTL — головний інструмент керування пам'яттю в кеші.

```text
SET cache:homepage "<html>...</html>" EX 300
TTL cache:homepage
PERSIST cache:homepage
TTL cache:homepage
EXPIRE cache:homepage 60
EXPIRE cache:homepage 120 NX     # встановити, лише якщо TTL немає
EXPIREAT cache:homepage 1790000000
```

Значення `TTL`:

- додатне число — секунди до видалення;
- `-1` — ключ існує, але без TTL (живе вічно);
- `-2` — ключа не існує або він уже видалений.

Перегляд залишку в мілісекундах: `PTTL cache:homepage`.

## Транзакції MULTI/EXEC

Транзакція Redis групує команди і виконує їх послідовно, без втручання інших клієнтів.

```text
MULTI
INCR counter:orders
INCR counter:events
EXEC
```

- `MULTI` починає накопичення команд (кожна відповідає `QUEUED`);
- `EXEC` виконує все й повертає масив результатів;
- `DISCARD` скасовує накопичене.

Важлива відмінність від SQL: Redis не має часткового відкату. Якщо команда має синтаксичну помилку, `EXEC` скасовується повністю (`EXECABORT`). Якщо ж помилка виникає під час виконання (наприклад, `INCR` над рядком), інші команди все одно виконуються.

Оптимістичне блокування через `WATCH`:

```text
# Термінал A
WATCH balance:user:1
GET balance:user:1            # 100
MULTI
DECRBY balance:user:1 30
EXEC                          # (nil), якщо хтось змінив ключ після WATCH

# Термінал B (між WATCH і EXEC)
INCR balance:user:1
```

Якщо `EXEC` повертає `(nil)`, транзакцію слід повторити: клієнт перечитує значення й намагається знову. Команда `UNWATCH` знімає всі спостереження.

## Pub/Sub

Публікація та підписка — обмін повідомленнями без збереження історії: підписник отримує лише те, що опубліковано після підписки.

```text
# Термінал 1
SUBSCRIBE news

# Термінал 2
PUBLISH news "Нова стаття на сайті"
PUBLISH news "Оновлення розкладу"
```

Підписка за шаблоном:

```text
# Термінал 1
PSUBSCRIBE news.*

# Термінал 2
PUBLISH news.sport "Матч перенесено"
```

Службові команди:

```text
PUBSUB CHANNELS
PUBSUB NUMSUB news
```

У режимі підписки `redis-cli` більше не приймає звичайних команд; для виходу натисніть `Ctrl+C`. Повідомлення не зберігаються: якщо підписник відключився, вони втрачені. Для гарантованої доставки використовують Redis Streams (`XADD`, `XREADGROUP`).

## Патерни кешування

### 1. Cache-aside (ліниве завантаження)

Найпоширеніший патерн: застосунок сам читає кеш, а при промаху — базу.

```text
GET cache:product:1
(nil)

SET cache:product:1 '{"id":1,"title":"Ноутбук Lenovo IdeaPad","price":24999.00}' EX 300
GET cache:product:1
TTL cache:product:1
```

Псевдокод:

```text
read(key):
    value = GET key
    if value is nil:
        value = SELECT ... FROM products WHERE id = 1
        SET key value EX 300
    return value

update(product):
    UPDATE products ...
    DEL key                 # інвалідація після зміни
```

Після `UPDATE` в базі ключ обов'язково видаляють, інакше клієнти читатимуть старі дані до кінця TTL.

### 2. Захист від лавини (cache stampede)

Якщо популярний ключ зник, сотні запитів одночасно підуть у базу. Рятує замок через `SET NX`:

```text
SET lock:product:1 worker-1 NX EX 10
OK
# лише один процес оновлює кеш; решта чекає або віддає застаріле значення
DEL lock:product:1
```

Додатково до TTL додають випадкові секунди, щоб ключі не спливали одночасно:

```text
SET cache:product:1 '{"id":1}' EX 300
```

### 3. Write-through і write-behind

- **Write-through:** застосунок пише одночасно в базу й у Redis — кеш завжди актуальний, ціна — повільніший запис.
- **Write-behind:** запис спершу в Redis, потім асинхронно в базу — швидко, але при збої частина даних може загубитися.

### 4. Лічильники та обмеження частоти

```text
SET ratelimit:user:42 0 EX 60 NX
INCR ratelimit:user:42
GET ratelimit:user:42
TTL ratelimit:user:42
```

Якщо значення перевищує ліміт — запит відхиляють. `EXPIRE ... NX` (Redis 7) встановлює TTL лише один раз, тому вікно не продовжується при кожному запиті.

## Діагностика

```text
INFO server
INFO memory
INFO stats
INFO persistence
DBSIZE
MEMORY USAGE cache:product:1
```

Ітерація ключів без блокування сервера:

```text
SCAN 0 MATCH cache:* COUNT 100
```

`SCAN` повертає курсор і порцію ключів; викликайте його в циклі, доки курсор не стане `0`. Команда `KEYS cache:*` на реальному сервері небезпечна: вона блокує обробку всіх команд, поки не перебере весь простір ключів.

Перевірка збереження даних (у стенді ввімкнено AOF):

```text
BGSAVE
INFO persistence
# rdb_last_bgsave_status:ok
# aof_enabled:1
```

## Типові помилки

1. **Відсутній TTL.** Ключі без часу життя поступово витісняють корисні дані й призводять до `OOM`. Для кешу TTL обов'язковий.
2. **`KEYS *` на робочому сервері.** Блокує Redis на час повного перебору; використовуйте `SCAN`.
3. **Зберігання великих значень.** Один ключ на кілька мегабайтів сповільнює реплікацію та збереження; розбивайте на частини або обмежуйте розмір.
4. **Очікування відкату від MULTI.** Redis не має `ROLLBACK`: помилки виконання не скасовують інші команди транзакції.
5. **Різниця між `DEL` і `UNLINK`.** `DEL` великого ключа блокує сервер, `UNLINK` видаляє у фоні.
6. **Використання `SET` без `NX` для замків.** Два процеси одночасно вважають, що володіють замком.
7. **Інвалідація не після кожної зміни.** Оновлення в MySQL без `DEL` ключа залишає кеш застарілим до кінця TTL.
8. **Очікування, що Pub/Sub зберігає повідомлення.** Повідомлення, надіслані до підписки або під час відключення підписника, втрачаються назавжди.
9. **Зберігання бінарних даних у рядках без урахування кодування.** `redis-cli` може показати екрановані послідовності; для перегляду значення використовуйте застосунок.
10. **Ігнорування `EXPIRE` при оновленні кешу.** `SET key value` без `EX` знімає TTL, і ключ стає вічним.

## Вправи

1. Створіть хеш `session:exercise` з полями `user_id = 7` і `role = student`, встановіть TTL 600 секунд і виведіть `HGETALL` та `TTL`.
2. Створіть список `queue:tasks` із трьома завданнями, зчитайте його через `LRANGE`, вилучте перше завдання та покажіть залишок.
3. Створіть сортедовану множину `leaderboard:exercise` з трьома учасниками, виведіть топ-2 і додайте одному з них 10 балів.
4. Виконайте транзакцію `MULTI/EXEC`, яка збільшує два лічильники `stats:pageviews` і `stats:logins` на 1, і покажіть результати.
5. Реалізуйте cache-aside: збережіть JSON-рядок `cache:product:5` з TTL 120 секунд, перевірте `TTL`, видаліть ключ і переконайтеся, що `GET` повертає `(nil)`.

## Відповіді

1.

```text
HSET session:exercise user_id 7 role student
EXPIRE session:exercise 600
HGETALL session:exercise
TTL session:exercise
```

2.

```text
RPUSH queue:tasks "task-1" "task-2" "task-3"
LRANGE queue:tasks 0 -1
LPOP queue:tasks
LRANGE queue:tasks 0 -1
```

3.

```text
ZADD leaderboard:exercise 90 "Андрій" 85 "Софія" 70 "Максим"
ZREVRANGE leaderboard:exercise 0 1 WITHSCORES
ZINCRBY leaderboard:exercise 10 "Софія"
ZREVRANGE leaderboard:exercise 0 1 WITHSCORES
```

4.

```text
MULTI
INCR stats:pageviews
INCR stats:logins
EXEC
GET stats:pageviews
GET stats:logins
```

5.

```text
SET cache:product:5 '{"id":5,"title":"Монітор Dell 24\"","price":7499.00}' EX 120
TTL cache:product:5
GET cache:product:5
DEL cache:product:5
GET cache:product:5
```

## Пов'язані матеріали

- Основи Redis: [`../lessons/redis_basics.txt`](../lessons/redis_basics.txt)
- Веб-інтерфейс: http://localhost:8083 (Redis Commander)
- Веб-тренажер: http://localhost:8000/runner.php та http://localhost:8000/tasks.php
