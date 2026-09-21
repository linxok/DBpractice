<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$status = [];

foreach (targets() as $key => $info) {
    try {
        connect($key);
        $status[] = [$info['label'], true, 'підключення активне'];
    } catch (Throwable $error) {
        $status[] = [$info['label'], false, $error->getMessage()];
    }
}

try {
    $manager = new MongoDB\Driver\Manager(config()['mongo']['uri']);
    $cursor = $manager->executeCommand('learn', new MongoDB\Driver\Command(['count' => 'students']));
    $result = $cursor->toArray()[0];
    $status[] = ['MongoDB — learn.students', true, 'документів: ' . $result->n];
} catch (Throwable $error) {
    $status[] = ['MongoDB — learn.students', false, $error->getMessage()];
}

try {
    $redis = new Redis();
    $redis->connect(config()['redis']['host'], config()['redis']['port'], 2.0);
    $status[] = ['Redis', true, 'PING: ' . $redis->ping()];
} catch (Throwable $error) {
    $status[] = ['Redis', false, $error->getMessage()];
}

page_header('Огляд');
?>
<h1>Навчальний стенд баз даних</h1>
<p>П'ять СУБД, тренажер із вправами, SQL Runner з планами запитів, пісочниця для експериментів і конспекти українською.</p>

<div class="grid">
    <div class="card">
        <h3><a href="/runner.php">SQL Runner</a></h3>
        <p>Виконуйте SELECT-запити, дивіться результат, час і план виконання (EXPLAIN) для кожної бази. Результат можна вивантажити у CSV.</p>
    </div>
    <div class="card">
        <h3><a href="/schema.php">Схема баз</a></h3>
        <p>Таблиці, колонки, типи та індекси будь-якої бази стенду — актуальні дані з information_schema.</p>
    </div>
    <div class="card">
        <h3><a href="/nosql.php">NoSQL-консоль</a></h3>
        <p>Запити MongoDB (find, агрегації) та команди Redis просто з браузера, з прикладами й захистом від небезпечних операцій.</p>
    </div>
    <div class="card">
        <h3><a href="/tasks.php">Вправи</a></h3>
        <p>55 завдань із автоматичною перевіркою: SQL (JOIN, GROUP BY, CTE, віконні функції, індекси), MongoDB (агрегації) і Redis. Прогрес зберігається.</p>
    </div>
    <div class="card">
        <h3><a href="/sandbox.php">Пісочниця</a></h3>
        <p>Вільні експерименти з даними й схемою. Кнопка «Скинути» повертає все як було.</p>
    </div>
    <div class="card">
        <h3><a href="/locks.php">Транзакції та блокування</a></h3>
        <p>Онлайн-демонстрації: очікування блокування рядка, deadlock, рівні ізоляції — без двох терміналів.</p>
    </div>
    <div class="card">
        <h3><a href="/docs.php">Конспекти</a></h3>
        <p>Теорія з прикладами, типовими помилками та відповідями до вправ.</p>
    </div>
</div>

<h2>Великі дані</h2>
<p>
    Для тем індексів і продуктивності згенеровано базу <code>shop_big</code>: 10 000 клієнтів, 1 000 товарів, 100 000 замовлень.
    Якщо її немає, виконайте у каталозі стенду: <code>./manage.sh seed</code>.
</p>

<h2>Веб-інструменти</h2>
<ul>
    <li><a href="http://localhost:8080/" target="_blank" rel="noreferrer">Adminer</a> — універсальний клієнт для SQL-баз</li>
    <li><a href="http://localhost:8081/" target="_blank" rel="noreferrer">phpMyAdmin</a> — MySQL та MariaDB</li>
    <li><a href="http://localhost:5050/" target="_blank" rel="noreferrer">pgAdmin</a> — PostgreSQL</li>
    <li><a href="http://localhost:8082/" target="_blank" rel="noreferrer">Mongo Express</a> — MongoDB</li>
    <li><a href="http://localhost:8083/" target="_blank" rel="noreferrer">Redis Commander</a> — Redis</li>
</ul>

<h2>Стан підключень</h2>
<table>
    <thead>
    <tr>
        <th>Джерело</th>
        <th>Статус</th>
        <th>Деталі</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($status as [$name, $ok, $detail]): ?>
        <tr>
            <td><?= e($name) ?></td>
            <td class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? 'OK' : 'ПОМИЛКА' ?></td>
            <td><?= e($detail) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="hint">
    PHP <?= e(PHP_VERSION) ?> · розширення: pdo_mysql, mysqli, pdo_pgsql, pgsql, mongodb, redis, pdo_sqlite.
    Керування стендом: <code>./manage.sh</code> — довідка, <code>./manage.sh seed</code> — генерація даних,
    <code>./manage.sh backup</code> — резервні копії, <code>./manage.sh shell mysql</code> — консоль.
</p>
<?php page_footer(); ?>
