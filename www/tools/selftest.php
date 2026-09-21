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
