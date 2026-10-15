<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Самоперевірка доступна лише з консолі\n");
}

require dirname(__DIR__) . '/lib.php';

$failures = 0;

function report(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  %s %-44s %s\n", $ok ? 'OK  ' : 'FAIL', $label, $detail);
}

function is_write_block_error(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    foreach (['read-only', 'read only', 'permission denied', 'command denied'] as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }
    return false;
}

echo "Підключення до баз:\n";
foreach (targets() as $key => $info) {
    try {
        connect($key)->query('SELECT 1');
        report(true, $info['label']);
    } catch (Throwable $error) {
        report(false, $info['label'], $error->getMessage());
    }
}

echo "\nСпеціальні сховища:\n";
try {
    $manager = new MongoDB\Driver\Manager(config()['mongo']['uri']);
    $cursor = $manager->executeCommand('learn', new MongoDB\Driver\Command(['count' => 'students']));
    report(true, 'MongoDB learn.students', 'документів: ' . $cursor->toArray()[0]->n);
} catch (Throwable $error) {
    report(false, 'MongoDB learn.students', $error->getMessage());
}

try {
    report(redis()->ping() !== false, 'Redis', 'ключів у базі: ' . redis()->dbSize());
} catch (Throwable $error) {
    report(false, 'Redis', $error->getMessage());
}

echo "\nЕталонні запити вправ:\n";
$tasks = tasks();
$bad = 0;
foreach ($tasks as $task) {
    try {
        run_sql($task['target'], $task['reference'], 3);
    } catch (Throwable $error) {
        $bad++;
        printf("  FAIL %-10s %s\n", $task['id'], $error->getMessage());
    }
}
report($bad === 0, 'Еталони (' . count($tasks) . ' шт.)', $bad > 0 ? 'проблемних: ' . $bad : '');

echo "\nЗахист read-only запитів:\n";
foreach ([
    'mysql' => 'customers',
    'mariadb' => 'authors',
    'postgres' => 'customers',
] as $key => $table) {
    try {
        run_sql($key, 'DELETE FROM ' . $table . ' WHERE 1 = 0');
        report(false, $key . ' блокує DML у read-only режимі', 'DELETE несподівано виконано');
    } catch (Throwable $error) {
        report(
            is_write_block_error($error),
            $key . ' блокує DML за роллю або транзакцією',
            $error->getMessage()
        );
    }
}
try {
    run_sql(
        'postgres',
        'WITH attempted_write AS (DELETE FROM customers WHERE 1 = 0 RETURNING id) SELECT COUNT(*) FROM attempted_write'
    );
    report(false, 'PostgreSQL блокує DML у CTE', 'запит несподівано виконано');
} catch (Throwable $error) {
    report(
        is_write_block_error($error),
        'PostgreSQL блокує DML у CTE',
        $error->getMessage()
    );
}
report(
    !redis_command_allowed('SELECT') && !redis_command_allowed('FLUSHDB') && redis_command_allowed('HSET'),
    'Redis-консоль обмежена allowlist-командами'
);

echo "\nNoSQL-еталони:\n";
$nosqlTasks = nosql_tasks();
$nosqlBad = 0;
foreach ($nosqlTasks as $task) {
    try {
        if (($task['engine'] ?? '') === 'mongo') {
            check_mongo_task($task, $task['reference']);
        } else {
            check_redis_task($task, $task['reference']);
        }
    } catch (Throwable $error) {
        $nosqlBad++;
        printf("  FAIL %-10s %s\n", $task['id'], $error->getMessage());
    }
}
report($nosqlBad === 0, 'NoSQL (' . count($nosqlTasks) . ' шт.)', $nosqlBad > 0 ? 'проблемних: ' . $nosqlBad : '');

echo "\nВеликі дані shop_big:\n";
foreach (['mysql_big' => 'MySQL', 'mariadb_big' => 'MariaDB', 'postgres_big' => 'PostgreSQL'] as $key => $label) {
    try {
        $count = (int) connect($key)->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        report($count > 1000, $label, 'orders=' . $count);
    } catch (Throwable $error) {
        report(false, $label, $error->getMessage());
    }
}

try {
    $manager = new MongoDB\Driver\Manager(config()['mongo']['uri']);
    $cursor = $manager->executeCommand('shop_big', new MongoDB\Driver\Command(['count' => 'orders']));
    $count = (int) $cursor->toArray()[0]->n;
    report($count > 1000, 'MongoDB', 'orders=' . $count);
} catch (Throwable $error) {
    report(false, 'MongoDB', $error->getMessage());
}

echo "\n";
if ($failures === 0) {
    echo "Усі перевірки пройдено.\n";
    exit(0);
}

printf("Помилок: %d. Якщо не згенеровані великі дані — виконайте ./manage.sh seed\n", $failures);
exit(1);
