<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$scenario = (string) ($_GET['scenario'] ?? '');
$key = (string) ($_GET['db'] ?? 'sandbox_mysql');
$log = [];

function line(string $step, string $result, string $detail = ''): array
{
    return ['step' => $step, 'result' => $result, 'detail' => $detail];
}

try {
    target($key);
    $driver = target($key)['driver'];
    $pdo = connect($key);

    if ($scenario === 'deadlock_a' || $scenario === 'deadlock_b') {
        $first = $scenario === 'deadlock_a' ? 1 : 2;
        $second = $scenario === 'deadlock_a' ? 2 : 1;
        $label = $scenario === 'deadlock_a' ? 'A' : 'B';

        if ($driver === 'pgsql') {
            $pdo->exec("SET lock_timeout = '5s'");
            $pdo->exec('BEGIN');
        } else {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
            $pdo->exec('START TRANSACTION');
        }

        $pdo->exec("UPDATE products SET stock = stock - 1 WHERE id = $first");
        $log[] = line("Транзакція $label: блокує рядок $first", 'OK');
        usleep(700000);

        try {
            $pdo->exec("UPDATE products SET stock = stock - 1 WHERE id = $second");
            $log[] = line("Транзакція $label: бере рядок $second", 'OK', 'очікування не виникло');
        } catch (Throwable $error) {
            $log[] = line("Транзакція $label: чекає на рядок $second", 'ПОМИЛКА', $error->getMessage());
        }
        $pdo->exec('ROLLBACK');
        $log[] = line("Транзакція $label: ROLLBACK", 'OK');
    }

    echo json_encode(['ok' => true, 'log' => $log], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'log' => $log, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
