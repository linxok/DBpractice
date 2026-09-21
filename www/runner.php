<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$selectedTarget = (string) ($_POST['target'] ?? $_GET['target'] ?? 'mysql');
$sql = trim((string) ($_POST['sql'] ?? ''));
$mode = (string) ($_POST['mode'] ?? 'none');
$export = (string) ($_POST['export'] ?? '');
$result = null;
$errorMessage = null;
$executed = '';

if ($sql !== '') {
    try {
        $targetInfo = target($selectedTarget);
        $writable = (bool) ($targetInfo['writable'] ?? false);

        if (!is_readonly_query($sql) && !$writable) {
            throw new RuntimeException('Дозволені лише SELECT, WITH, SHOW, EXPLAIN, DESCRIBE. Для зміни даних оберіть базу з групи «Пісочниця».');
        }
        if (has_multiple_statements($sql)) {
            throw new RuntimeException('Виконуйте по одному запиту за раз (без символу «;»).');
        }

        if ($mode === 'explain') {
            $executed = 'EXPLAIN ' . $sql;
        } elseif ($mode === 'analyze') {
            $executed = ($targetInfo['analyze'] ?? 'EXPLAIN ') . $sql;
        } else {
            $executed = $sql;
        }

        $limit = $export === 'csv' ? 5000 : 300;
        $result = run_sql($selectedTarget, $executed, $limit);
    } catch (Throwable $error) {
        $errorMessage = $error->getMessage();
    }
}

if ($export === 'csv' && $result !== null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="query-result.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $result['columns'], ';', '"', '');
        foreach ($result['rows'] as $row) {
            $line = [];
            foreach (array_values($row) as $value) {
                $line[] = $value === null ? '' : (string) $value;
            }
            fputcsv($out, $line, ';', '"', '');
        }
        fclose($out);
    }
    exit;
}

page_header('SQL Runner');
?>
<h1>SQL Runner</h1>
<p>Виконуйте SELECT-запити проти будь-якої бази стенду та одразу бачте план виконання й час. Для баз із групи «Пісочниця» дозволені будь-які операції: INSERT, UPDATE, DELETE, CREATE, DROP — це безпечне місце для експериментів, яке можна скинути на сторінці <a href="/sandbox.php">пісочниці</a>.</p>

<form method="post">
    <div class="controls">
        <label>База:
            <select name="target"><?= target_options($selectedTarget) ?></select>
        </label>
        <label>Режим:
            <select name="mode">
                <option value="none"<?= $mode === 'none' ? ' selected' : '' ?>>виконати запит</option>
                <option value="explain"<?= $mode === 'explain' ? ' selected' : '' ?>>EXPLAIN (план)</option>
                <option value="analyze"<?= $mode === 'analyze' ? ' selected' : '' ?>>EXPLAIN ANALYZE (план + час)</option>
            </select>
        </label>
    </div>
    <textarea name="sql" placeholder="SELECT * FROM customers LIMIT 10"><?= e($sql) ?></textarea>
    <div class="controls">
        <button type="submit">Виконати</button>
        <button type="submit" name="export" value="csv" class="secondary">Експорт CSV (до 5000 рядків)</button>
        <button type="button" class="secondary" data-example="SELECT c.full_name, p.title, o.quantity, o.ordered_at
FROM orders o
JOIN customers c ON c.id = o.customer_id
JOIN products p ON p.id = o.product_id
ORDER BY o.ordered_at">Приклад: JOIN</button>
        <button type="button" class="secondary" data-example="SELECT city, COUNT(*) AS customers
FROM customers
GROUP BY city
ORDER BY customers DESC">Приклад: GROUP BY</button>
        <button type="button" class="secondary" data-example="SELECT title, price,
       ROW_NUMBER() OVER (ORDER BY price DESC) AS row_num
FROM products">Приклад: віконна функція</button>
        <button type="button" class="secondary" data-example="SELECT * FROM orders WHERE customer_id = 42">Приклад: без індексу</button>
    </div>
</form>

<script>
document.querySelectorAll('button[data-example]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelector('textarea[name="sql"]').value = button.dataset.example;
    });
});
</script>

<?php if ($errorMessage !== null): ?>
    <h2>Помилка</h2>
    <p class="error"><?= e($errorMessage) ?></p>
<?php elseif ($result !== null): ?>
    <h2>Результат</h2>
    <p class="hint">
        Запит: <code><?= e($executed) ?></code><br>
        Час виконання: <?= e(number_format($result['ms'], 2)) ?> мс.
        <?= $result['truncated'] ? 'Показано перші ' . count($result['rows']) . ' рядків.' : '' ?>
    </p>
    <?= render_table($result) ?>
<?php endif; ?>
<?php page_footer(); ?>
