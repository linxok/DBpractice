<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

function line(string $step, string $result, string $detail = ''): array
{
    return ['step' => $step, 'result' => $result, 'detail' => $detail];
}

function scenario_lock(string $key): array
{
    $driver = target($key)['driver'];
    $log = [];
    $a = connect($key);
    $b = connect($key);
    $original = (int) $a->query('SELECT stock FROM products WHERE id = 1')->fetchColumn();
    $begin = $driver === 'pgsql' ? 'BEGIN' : 'START TRANSACTION';

    if ($driver === 'pgsql') {
        $a->exec("SET lock_timeout = '10s'");
        $b->exec("SET lock_timeout = '2s'");
    } else {
        $a->exec('SET SESSION innodb_lock_wait_timeout = 10');
        $b->exec('SET SESSION innodb_lock_wait_timeout = 2');
    }

    $a->exec($begin);
    $a->exec('UPDATE products SET stock = stock - 1 WHERE id = 1');
    $log[] = line('A: BEGIN і UPDATE товару 1', 'OK', 'рядок заблоковано до завершення транзакції');

    $b->exec($begin);
    $start = microtime(true);
    try {
        $b->exec('UPDATE products SET stock = stock - 1 WHERE id = 1');
        $log[] = line('B: спроба оновити той самий рядок', 'OK', 'очікування не виникло');
    } catch (Throwable $error) {
        $waited = number_format((microtime(true) - $start) * 1000, 0);
        $log[] = line('B: спроба оновити той самий рядок', 'ОЧІКУВАННЯ ' . $waited . ' мс', $error->getMessage());
    }
    $b->exec('ROLLBACK');

    $a->exec('ROLLBACK');
    $log[] = line('A: ROLLBACK', 'OK', 'блокування знято');

    $b->exec($begin);
    $b->exec('UPDATE products SET stock = stock - 1 WHERE id = 1');
    $b->exec('ROLLBACK');
    $log[] = line('B: повторює UPDATE після звільнення', 'OK', 'тепер рядок доступний');

    $a->exec('UPDATE products SET stock = ' . $original . ' WHERE id = 1');
    $log[] = line('Відновлення даних пісочниці', 'OK', 'stock = ' . $original);

    return $log;
}

function scenario_isolation(string $key): array
{
    $driver = target($key)['driver'];
    $log = [];
    $a = connect($key);
    $b = connect($key);
    $original = (int) $a->query('SELECT stock FROM products WHERE id = 1')->fetchColumn();
    $begin = $driver === 'pgsql' ? 'BEGIN' : 'START TRANSACTION';

    foreach (['REPEATABLE READ', 'READ COMMITTED'] as $level) {
        if ($driver === 'pgsql') {
            $b->exec('BEGIN ISOLATION LEVEL ' . $level);
        } else {
            $b->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $level);
            $b->exec($begin);
        }

        $before = (int) $b->query('SELECT stock FROM products WHERE id = 1')->fetchColumn();

        $a->exec($begin);
        $a->exec('UPDATE products SET stock = stock - 5 WHERE id = 1');
        $a->exec('COMMIT');
        $actual = (int) $a->query('SELECT stock FROM products WHERE id = 1')->fetchColumn();

        $seen = (int) $b->query('SELECT stock FROM products WHERE id = 1')->fetchColumn();
        $b->exec('ROLLBACK');

        $log[] = line(
            'Рівень ' . $level . ': B у відкритій транзакції бачить stock = ' . $seen,
            $seen === $before ? 'СТАРЕ ЗНАЧЕННЯ (снапшот)' : 'НОВЕ ЗНАЧЕННЯ',
            'A закомітила stock = ' . $actual . ', B бачить ' . $seen
        );

        $a->exec('UPDATE products SET stock = ' . $original . ' WHERE id = 1');
    }

    $log[] = line('Відновлення даних пісочниці', 'OK', 'stock = ' . $original);

    return $log;
}

function scenario_deadlock(string $key): array
{
    $log = [];
    $base = 'http://127.0.0.1/lock_worker.php?db=' . urlencode($key) . '&scenario=';
    $handles = [];
    $multi = curl_multi_init();

    foreach (['A' => 'deadlock_a', 'B' => 'deadlock_b'] as $label => $scenario) {
        $handle = curl_init($base . $scenario);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $handles[$label] = $handle;
        curl_multi_add_handle($multi, $handle);
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $label => $handle) {
        $body = curl_multi_getcontent($handle);
        $data = json_decode((string) $body, true);
        if (is_array($data) && isset($data['log'])) {
            foreach ($data['log'] as $item) {
                $log[] = line('[' . $label . '] ' . (string) $item['step'], (string) $item['result'], (string) ($item['detail'] ?? ''));
            }
        } else {
            $log[] = line('[' . $label . '] немає відповіді від обробника', 'ПОМИЛКА', (string) $body);
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    $log[] = line('Пояснення', 'DEADLOCK', 'Кожна транзакція захопила свій рядок і чекає на чужий — утворився цикл. СУБД виявляє його й відкочує одну з транзакцій.');

    return $log;
}

$selectedTarget = (string) ($_POST['target'] ?? 'sandbox_mysql');
$scenario = (string) ($_POST['scenario'] ?? '');
$log = [];
$errorMessage = null;

if ($scenario !== '') {
    try {
        $log = match ($scenario) {
            'lock' => scenario_lock($selectedTarget),
            'isolation' => scenario_isolation($selectedTarget),
            'deadlock' => scenario_deadlock($selectedTarget),
            default => throw new RuntimeException('Невідомий сценарій.'),
        };
    } catch (Throwable $error) {
        $errorMessage = $error->getMessage();
    }
}

page_header('Транзакції та блокування');
?>
<h1>Транзакції та блокування онлайн</h1>
<p>
    Тут можна побачити на власні очі те, що зазвичай демонструють у двох терміналах: очікування на блокування,
    взаємне блокування (deadlock) і різницю рівнів ізоляції. Сценарії виконуються на таблицях пісочниці,
    наприкінці дані повертаються до початкового стану.
</p>

<form method="post">
    <div class="controls">
        <label>База:
            <select name="target">
                <option value="sandbox_mysql"<?= $selectedTarget === 'sandbox_mysql' ? ' selected' : '' ?>>Пісочниця MySQL</option>
                <option value="sandbox_mariadb"<?= $selectedTarget === 'sandbox_mariadb' ? ' selected' : '' ?>>Пісочниця MariaDB</option>
                <option value="sandbox_postgres"<?= $selectedTarget === 'sandbox_postgres' ? ' selected' : '' ?>>Пісочниця PostgreSQL</option>
            </select>
        </label>
    </div>
    <div class="controls">
        <button type="submit" name="scenario" value="lock">Сценарій 1: очікування блокування</button>
        <button type="submit" name="scenario" value="deadlock">Сценарій 2: deadlock</button>
        <button type="submit" name="scenario" value="isolation">Сценарій 3: рівні ізоляції</button>
    </div>
</form>

<?php if ($errorMessage !== null): ?>
    <p class="error"><?= e($errorMessage) ?></p>
<?php elseif ($log !== []): ?>
    <h2>
        <?= match ($scenario) {
            'lock' => 'Очікування блокування рядка',
            'deadlock' => 'Взаємне блокування',
            'isolation' => 'Рівні ізоляції',
            default => 'Результат',
        } ?>
    </h2>
    <table>
        <thead>
        <tr>
            <th>Крок</th>
            <th>Результат</th>
            <th>Деталі</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $item): ?>
            <tr>
                <td><?= e($item['step']) ?></td>
                <td class="<?= str_contains($item['result'], 'ПОМИЛКА') || str_contains($item['result'], 'ОЧІКУВАННЯ') ? 'fail' : 'ok' ?>">
                    <?= e($item['result']) ?>
                </td>
                <td><?= e($item['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint">
        Докладніше: <a href="/docs.php?file=07-transactions-locks.md">конспект «Транзакції та блокування»</a>
        і скрипти <code>lessons/transactions_mysql.sql</code>, <code>lessons/transactions_postgres.sql</code>.
    </p>
<?php endif; ?>
<?php page_footer(); ?>
