<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$id = (string) ($_GET['id'] ?? '');
$task = task_by_id($id);

if ($task === null) {
    http_response_code(404);
    page_header('Завдання не знайдено');
    echo '<h1>Завдання не знайдено</h1><p><a href="/tasks.php">Повернутися до списку вправ</a></p>';
    page_footer();
    exit;
}

$engine = (string) ($task['engine'] ?? 'sql');
$check = null;
$inputValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($engine === 'sql') {
            $inputValue = trim((string) ($_POST['sql'] ?? ''));
            if ($inputValue === '') {
                throw new RuntimeException('Введіть запит.');
            }
            if (!is_readonly_query($inputValue)) {
                throw new RuntimeException('Дозволені лише SELECT, WITH, SHOW, EXPLAIN, DESCRIBE.');
            }
            if (has_multiple_statements($inputValue)) {
                throw new RuntimeException('Виконуйте по одному запиту за раз (без символу «;»).');
            }
            [$ok, $message, $result] = check_task($task, $inputValue);
            $check = ['ok' => $ok, 'message' => $message, 'result' => $result];
        } elseif ($engine === 'mongo') {
            $inputValue = trim((string) ($_POST['mongo_json'] ?? ''));
            if ($inputValue === '') {
                throw new RuntimeException('Введіть JSON-специфікацію запиту.');
            }
            $spec = json_decode($inputValue, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($spec)) {
                throw new RuntimeException('Очікується JSON-об\'єкт.');
            }
            [$ok, $message, $result] = check_mongo_task($task, $spec);
            $check = ['ok' => $ok, 'message' => $message, 'result' => $result];
        } else {
            $inputValue = trim((string) ($_POST['redis_commands'] ?? ''));
            if ($inputValue === '') {
                throw new RuntimeException('Введіть команди Redis.');
            }
            $lines = preg_split('/\r\n|\n|\r/', $inputValue) ?: [];
            [$ok, $message, $result] = check_redis_task($task, $lines);
            $check = ['ok' => $ok, 'message' => $message, 'result' => $result];
        }

        if ($check['ok']) {
            mark_task_done($task['id']);
        }
    } catch (Throwable $error) {
        $check = ['ok' => false, 'message' => $error->getMessage(), 'result' => null];
    }
}

$showAnswer = isset($_GET['show']);
$isDone = in_array($task['id'], task_progress(), true);

page_header($task['title']);
?>
<p><a href="/tasks.php">← Усі вправи</a></p>
<h1><?= e($task['title']) ?></h1>
<p>
    <span class="tag"><?= e($task['topic']) ?></span>
    <span class="tag"><?= e($task['level']) ?></span>
    <?php if ($engine === 'sql'): ?>
        <span class="tag"><?= e(target($task['target'])['label']) ?></span>
    <?php else: ?>
        <span class="tag"><?= $engine === 'mongo' ? 'MongoDB · ' . e((string) $task['database']) : 'Redis' ?></span>
    <?php endif; ?>
    <?php if ($isDone): ?>
        <span class="tag ok">виконано</span>
    <?php endif; ?>
</p>

<div class="card">
    <p><strong>Умова.</strong> <?= e($task['description']) ?></p>
    <?php if (!empty($task['hint'])): ?>
        <p class="hint">Підказка: <?= e($task['hint']) ?></p>
    <?php endif; ?>
</div>

<form method="post" action="/task.php?id=<?= urlencode($task['id']) ?>">
    <?php if ($engine === 'sql'): ?>
        <textarea name="sql" placeholder="SELECT ..."><?= e($inputValue) ?></textarea>
    <?php elseif ($engine === 'mongo'): ?>
        <textarea name="mongo_json" placeholder='{"collection": "students", "find": {}}'><?= e($inputValue) ?></textarea>
    <?php else: ?>
        <textarea name="redis_commands" placeholder="SET key value"><?= e($inputValue) ?></textarea>
    <?php endif; ?>
    <div class="controls">
        <button type="submit">Перевірити</button>
        <?php if ($engine === 'sql'): ?>
            <a class="hint" href="/runner.php?target=<?= urlencode($task['target']) ?>">відкрити в SQL Runner</a>
        <?php elseif ($engine === 'mongo'): ?>
            <a class="hint" href="/nosql.php">відкрити в NoSQL-консолі</a>
        <?php else: ?>
            <a class="hint" href="/docs.php?file=09-redis.md">конспект Redis</a>
        <?php endif; ?>
        <?php if (!$showAnswer): ?>
            <a class="hint" href="/task.php?id=<?= urlencode($task['id']) ?>&show=1">показати відповідь</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($check !== null): ?>
    <h2>Перевірка</h2>
    <p class="<?= $check['ok'] ? 'success' : 'error' ?>">
        <?= $check['ok'] ? 'Правильно!' : 'Ще не те.' ?>
        <?= e($check['message']) ?>
    </p>

    <?php if ($engine === 'sql' && $check['result'] !== null): ?>
        <p class="hint">Ваш результат (<?= e(number_format($check['result']['ms'], 2)) ?> мс):</p>
        <?= render_table($check['result']) ?>
    <?php elseif ($engine === 'mongo' && $check['result'] !== null): ?>
        <p class="hint">Ваш результат (<?= count($check['result']) ?> документів):</p>
        <pre><?= e((string) json_encode($check['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php elseif ($engine === 'redis' && $check['result'] !== null): ?>
        <p class="hint">Ваші команди:</p>
        <table>
            <thead>
            <tr><th>Команда</th><th>Відповідь</th></tr>
            </thead>
            <tbody>
            <?php foreach ($check['result']['output'] as $entry): ?>
                <tr>
                    <td><?= e((string) $entry['command']) ?></td>
                    <td class="<?= $entry['error'] !== null ? 'fail' : 'ok' ?>"><?= e((string) ($entry['error'] ?? $entry['result'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">Стан бази після виконання:</p>
        <pre><?= e((string) json_encode($check['result']['state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endif; ?>
<?php endif; ?>

<?php if ($showAnswer): ?>
    <h2>Еталонна відповідь</h2>
    <?php if ($engine === 'sql'): ?>
        <pre><?= e($task['reference']) ?></pre>
    <?php elseif ($engine === 'mongo'): ?>
        <pre><?= e((string) json_encode($task['reference'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php else: ?>
        <pre><?= e(implode("\n", $task['reference'])) ?></pre>
    <?php endif; ?>
<?php endif; ?>
<?php page_footer(); ?>
