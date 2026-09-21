<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$mongoDatabases = ['learn', 'shop_big'];
$mongoDb = (string) ($_POST['mongo_db'] ?? 'learn');
if (!in_array($mongoDb, $mongoDatabases, true)) {
    $mongoDb = 'learn';
}

$mongoJson = trim((string) ($_POST['mongo_json'] ?? ''));
$mongoOutput = null;
$mongoError = null;
$mongoSummary = '';

$redisCommands = trim((string) ($_POST['redis_commands'] ?? ''));
$redisResult = null;
$redisError = null;

$forbiddenRedis = [
    'FLUSHALL', 'SHUTDOWN', 'CONFIG', 'DEBUG', 'SLAVEOF', 'REPLICAOF',
    'MONITOR', 'SAVE', 'BGREWRITEAOF', 'BGSAVE', 'MIGRATE', 'SWAPDB', 'ACL',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_mongo']) && $mongoJson !== '') {
    try {
        $spec = json_decode($mongoJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($spec)) {
            throw new RuntimeException('Очікується JSON-об\'єкт.');
        }
        $spec['database'] = $mongoDb;
        $rows = run_mongo_spec($spec, 200);
        $mongoSummary = isset($spec['pipeline'])
            ? 'aggregate, стадій: ' . count($spec['pipeline']) . ', документів: ' . count($rows)
            : 'find, документів: ' . count($rows);
        $mongoOutput = (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        $mongoError = $error->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_redis']) && $redisCommands !== '') {
    $lines = preg_split('/\r\n|\n|\r/', $redisCommands) ?: [];
    $filtered = [];
    $forbiddenFound = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = str_getcsv($line, ' ', '"', '');
        $command = strtoupper((string) ($parts[0] ?? ''));
        if (in_array($command, $forbiddenRedis, true)) {
            $forbiddenFound = $command;
            break;
        }
        $filtered[] = $line;
    }

    if ($forbiddenFound !== null) {
        $redisError = 'Команда ' . $forbiddenFound . ' заборонена у навчальній консолі.';
    } else {
        try {
            $redisResult = run_redis_commands($filtered, 5);
            $redisError = $redisResult['error'];
        } catch (Throwable $error) {
            $redisError = $error->getMessage();
        }
    }
}

page_header('NoSQL-консоль');
?>
<h1>NoSQL-консоль</h1>
<p>
    Виконуйте запити до MongoDB та команди Redis просто з браузера. Для MongoDB доступні <code>find</code> і
    агрегаційний конвеєр, команди Redis виконуються в окремій базі №5, тому не зачіпають дані тренажера.
</p>

<h2>MongoDB</h2>
<form method="post">
    <div class="controls">
        <label>База:
            <select name="mongo_db">
                <?php foreach ($mongoDatabases as $database): ?>
                    <option value="<?= e($database) ?>"<?= $database === $mongoDb ? ' selected' : '' ?>><?= e($database) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" name="run_mongo" value="1">Виконати</button>
        <button type="button" class="secondary" data-mongo='{"collection": "students", "find": {}, "sort": {"name": 1}}'>Приклад: find</button>
        <button type="button" class="secondary" data-mongo='{"collection": "students", "pipeline": [{"$unwind": "$courses"}, {"$group": {"_id": "$courses", "students": {"$sum": 1}}}, {"$sort": {"students": -1, "_id": 1}}]}'>Приклад: $unwind + $group</button>
        <button type="button" class="secondary" data-mongo='{"collection": "orders", "pipeline": [{"$match": {"status": "done"}}, {"$group": {"_id": "$customerId", "total": {"$sum": "$total"}}}, {"$sort": {"total": -1}}, {"$limit": 5}]}' data-db="shop_big">Приклад: топ клієнтів</button>
    </div>
    <textarea name="mongo_json" placeholder='{"collection": "students", "find": {"grade": {"$gte": 80}}}'><?= e($mongoJson) ?></textarea>
</form>

<script>
document.querySelectorAll('button[data-mongo]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelector('textarea[name="mongo_json"]').value = button.dataset.mongo;
        if (button.dataset.db) {
            document.querySelector('select[name="mongo_db"]').value = button.dataset.db;
        }
    });
});
</script>

<?php if ($mongoError !== null): ?>
    <p class="error"><?= e($mongoError) ?></p>
<?php elseif ($mongoOutput !== null): ?>
    <p class="hint"><?= e($mongoSummary) ?> · база <code><?= e($mongoDb) ?></code></p>
    <pre><?= e($mongoOutput) ?></pre>
<?php endif; ?>

<h2>Redis — база №5</h2>
<form method="post">
    <div class="controls">
        <button type="submit" name="run_redis" value="1">Виконати</button>
        <button type="button" class="secondary" data-redis='SET user:1:name "Олена"
GET user:1:name
INCR counter:visits
EXPIRE counter:visits 600
TTL counter:visits'>Приклад: рядки та TTL</button>
        <button type="button" class="secondary" data-redis='HSET product:1 title "Ноутбук" price 24999 stock 12
HGETALL product:1
HINCRBY product:1 stock -1'>Приклад: хеш</button>
        <button type="button" class="secondary" data-redis='MULTI
INCR counter:orders
INCR counter:events
EXEC'>Приклад: транзакція</button>
    </div>
    <textarea name="redis_commands" placeholder="SET key value&#10;GET key"><?= e($redisCommands) ?></textarea>
</form>

<script>
document.querySelectorAll('button[data-redis]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelector('textarea[name="redis_commands"]').value = button.dataset.redis;
    });
});
</script>

<?php if ($redisError !== null): ?>
    <p class="error"><?= e($redisError) ?></p>
<?php elseif ($redisResult !== null): ?>
    <table>
        <thead>
        <tr><th>Команда</th><th>Відповідь</th></tr>
        </thead>
        <tbody>
        <?php foreach ($redisResult['output'] as $entry): ?>
            <tr>
                <td><?= e((string) $entry['command']) ?></td>
                <td class="<?= $entry['error'] !== null ? 'fail' : 'ok' ?>"><?= e((string) ($entry['error'] ?? $entry['result'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p class="hint">
    Повний довідник команд — у конспектах <a href="/docs.php?file=08-mongodb.md">MongoDB</a> і
    <a href="/docs.php?file=09-redis.md">Redis</a>, а практичні завдання — на сторінці
    <a href="/tasks.php?topic=MongoDB">вправ</a>.
</p>
<?php page_footer(); ?>
