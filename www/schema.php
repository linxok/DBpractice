<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$selectedTarget = (string) ($_GET['target'] ?? 'mysql');
$tables = [];
$relations = [];
$errorMessage = null;

try {
    $tables = schema_tables($selectedTarget);
    $relations = table_relations($selectedTarget);
} catch (Throwable $error) {
    $errorMessage = $error->getMessage();
}

page_header('Схема бази');
?>
<h1>Схема бази даних</h1>
<p>
    Огляд таблиць, колонок та індексів вибраної бази — дані читаються з <code>information_schema</code> безпосередньо,
    тому схема завжди актуальна. Зручно тримати відкритим поряд із <a href="/runner.php">SQL Runner</a> та
    <a href="/tasks.php">вправами</a>.
</p>

<form method="get">
    <div class="controls">
        <label>База:
            <select name="target" onchange="this.form.submit()">
                <?= target_options($selectedTarget, true) ?>
            </select>
        </label>
        <noscript><button type="submit">Показати</button></noscript>
    </div>
</form>

<?php if ($errorMessage !== null): ?>
    <p class="error"><?= e($errorMessage) ?></p>
<?php elseif ($tables === []): ?>
    <p class="hint">У цій базі немає таблиць. Для пісочниць натисніть «Скинути» на сторінці <a href="/sandbox.php">пісочниці</a>, щоб створити початкові таблиці.</p>
<?php else: ?>
    <div class="card">
        <h3>Зв'язки таблиць (зовнішні ключі)</h3>
        <?php if ($relations === []): ?>
            <p class="hint">У цій базі зовнішніх ключів немає.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Обмеження</th>
                    <th>Таблиця</th>
                    <th>Колонка</th>
                    <th>Посилається на</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($relations as $relation): ?>
                    <tr>
                        <td><?= e((string) $relation['constraint_name']) ?></td>
                        <td><?= e((string) $relation['table_name']) ?></td>
                        <td><?= e((string) $relation['column_name']) ?></td>
                        <td><?= e((string) $relation['referenced_table']) ?> (<?= e((string) $relation['referenced_column']) ?>)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">
                Схема зв'язків:
                <?php
                $lines = [];
                foreach ($relations as $relation) {
                    $lines[] = $relation['table_name'] . '.' . $relation['column_name'] . ' → ' . $relation['referenced_table'] . '.' . $relation['referenced_column'];
                }
                echo e(implode(' · ', array_unique($lines)));
                ?>
            </p>
        <?php endif; ?>
    </div>
    <?php foreach ($tables as $table): ?>
        <?php
        $name = (string) $table['table_name'];
        try {
            $columns = table_columns($selectedTarget, $name);
            $indexes = table_indexes($selectedTarget, $name);
        } catch (Throwable $error) {
            echo '<div class="card"><h3>' . e($name) . '</h3><p class="error">' . e($error->getMessage()) . '</p></div>';
            continue;
        }
        ?>
        <div class="card">
            <h3><?= e($name) ?></h3>
            <table>
                <thead>
                <tr>
                    <th>Колонка</th>
                    <th>Тип</th>
                    <th>NULL</th>
                    <th>Ключ</th>
                    <th>За замовчуванням</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($columns as $column): ?>
                    <tr>
                        <td><?= e((string) $column['column_name']) ?></td>
                        <td><?= e((string) $column['data_type']) ?></td>
                        <td><?= e((string) $column['is_nullable']) ?></td>
                        <td><?= e((string) ($column['column_key'] ?? '')) ?></td>
                        <td><?= e((string) ($column['column_default'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($indexes !== []): ?>
                <p>Індекси:</p>
                <ul>
                    <?php foreach ($indexes as $index): ?>
                        <li>
                            <code><?= e((string) $index['index_name']) ?></code>
                            <?php if (isset($index['columns'])): ?>
                                — (<?= e((string) $index['columns']) ?>)<?= ((int) ($index['non_unique'] ?? 1)) === 0 ? ', унікальний' : '' ?>
                            <?php elseif (isset($index['definition'])): ?>
                                — <?= e((string) $index['definition']) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php page_footer(); ?>
