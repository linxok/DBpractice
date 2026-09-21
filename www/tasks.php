<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_progress'])) {
    reset_task_progress();
    header('Location: /tasks.php');
    exit;
}

$allTasks = all_tasks();
$topics = [];
foreach ($allTasks as $task) {
    $topics[$task['topic']] = ($topics[$task['topic']] ?? 0) + 1;
}

$done = task_progress();
$doneCount = count(array_intersect(array_column($allTasks, 'id'), $done));

$selectedTopic = (string) ($_GET['topic'] ?? '');
$shown = $selectedTopic === ''
    ? $allTasks
    : array_values(array_filter($allTasks, static fn (array $task): bool => $task['topic'] === $selectedTopic));

page_header('Вправи');
?>
<h1>Вправи</h1>
<p>Кожна вправа перевіряється автоматично: система виконує ваш запит і порівнює результат з еталонним. Підказки та відповіді доступні на сторінці завдання.</p>

<p>
    Виконано <strong><?= $doneCount ?></strong> з <strong><?= count($allTasks) ?></strong>.
    <?php if ($doneCount > 0): ?>
        <form method="post" style="display: inline;">
            <button type="submit" name="reset_progress" value="1" class="secondary" onclick="return confirm('Скинути позначки виконаних вправ?');">скинути прогрес</button>
        </form>
    <?php endif; ?>
</p>

<p>Фільтр за темою:
    <a href="/tasks.php">усі (<?= count($allTasks) ?>)</a>
    <?php foreach ($topics as $topic => $count): ?>
        | <a href="/tasks.php?topic=<?= urlencode((string) $topic) ?>"><?= e((string) $topic) ?> (<?= $count ?>)</a>
    <?php endforeach; ?>
</p>

<?php if ($shown === []): ?>
    <p class="hint">Для цієї теми завдань немає.</p>
<?php endif; ?>

<div class="grid">
    <?php foreach ($shown as $task): ?>
        <?php $isDone = in_array($task['id'], $done, true); ?>
        <div class="card">
            <h3>
                <a href="/task.php?id=<?= urlencode($task['id']) ?>"><?= e($task['title']) ?></a>
                <?php if ($isDone): ?><span class="ok">✓</span><?php endif; ?>
            </h3>
            <p><?= e($task['description']) ?></p>
            <p>
                <span class="tag"><?= e($task['topic']) ?></span>
                <span class="tag"><?= e($task['level']) ?></span>
                <?php if (($task['engine'] ?? 'sql') === 'sql'): ?>
                    <span class="tag"><?= e(target($task['target'])['label']) ?></span>
                <?php elseif ($task['engine'] === 'mongo'): ?>
                    <span class="tag">MongoDB · <?= e((string) $task['database']) ?></span>
                <?php else: ?>
                    <span class="tag">Redis</span>
                <?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>
</div>
<p class="hint">Прогрес зберігається в Redis стенду (ключ <code>learn:done</code>) і спільний для всіх вкладок браузера.</p>
<?php page_footer(); ?>
