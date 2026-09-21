<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$message = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    $which = (string) $_POST['reset'];
    $keys = [
        'mysql' => ['sandbox_mysql', __DIR__ . '/sandbox/mysql.sql'],
        'mariadb' => ['sandbox_mariadb', __DIR__ . '/sandbox/mariadb.sql'],
        'postgres' => ['sandbox_postgres', __DIR__ . '/sandbox/postgres.sql'],
    ];
    if (!isset($keys[$which])) {
        $errorMessage = 'Невідома пісочниця.';
    } else {
        [$key, $file] = $keys[$which];
        try {
            $pdo = connect($key);
            if (target($key)['driver'] === 'pgsql') {
                $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
                $pdo->exec('CREATE SCHEMA public');
            } else {
                $tables = $pdo->query(
                    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
                )->fetchAll(PDO::FETCH_COLUMN);
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $table) {
                    $safe = str_replace('`', '', (string) $table);
                    $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            foreach (split_sql((string) file_get_contents($file)) as $statement) {
                $pdo->exec($statement);
            }
            $message = 'Пісочницю «' . target($key)['label'] . '» скинуто до початкового стану (усі сторонні таблиці видалено).';
        } catch (Throwable $error) {
            $errorMessage = $error->getMessage();
        }
    }
}

$countsSql = "SELECT 'customers' AS table_name, COUNT(*) AS rows_count FROM customers
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'orders', COUNT(*) FROM orders";

page_header('Пісочниця');
?>
<h1>Пісочниця</h1>
<p>
    Тут можна вільно експериментувати: створювати таблиці, вставляти, оновлювати й видаляти дані, ламати схему.
    Якщо щось пішло не так — натисніть «Скинути», і база повернеться до початкового стану.
    Це окремі бази <code>sandbox</code>, вони не впливають на навчальні дані.
</p>

<?php if ($message !== null): ?>
    <p class="success"><?= e($message) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <p class="error"><?= e($errorMessage) ?></p>
<?php endif; ?>

<?php foreach (['sandbox_mysql' => 'mysql', 'sandbox_mariadb' => 'mariadb', 'sandbox_postgres' => 'postgres'] as $key => $which): ?>
    <div class="card">
        <h3><?= e(target($key)['label']) ?></h3>
        <?php
        try {
            $counts = run_sql($key, $countsSql, 50);
            echo render_table($counts, 'Таблиць немає — натисніть «Скинути».');
        } catch (Throwable $error) {
            echo '<p class="error">' . e($error->getMessage()) . '</p>';
        }
        ?>
        <div class="controls">
            <form method="post" style="margin: 0;">
                <input type="hidden" name="reset" value="<?= e($which) ?>">
                <button type="submit" class="secondary" onclick="return confirm('Скинути пісочницю до початкового стану?');">Скинути</button>
            </form>
            <a href="/runner.php?target=<?= e($key) ?>">виконувати запити →</a>
        </div>
    </div>
<?php endforeach; ?>
<?php page_footer(); ?>
