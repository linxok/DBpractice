<?php
declare(strict_types=1);

function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function targets(): array
{
    return config()['targets'];
}

function target(string $key): array
{
    $targets = targets();
    if (!isset($targets[$key])) {
        throw new RuntimeException('Невідома ціль: ' . $key);
    }
    return $targets[$key];
}

function connect(string $key): PDO
{
    $target = target($key);
    $pdo = new PDO($target['dsn'], $target['user'], $target['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    foreach ($target['init'] ?? [] as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function strip_literals(string $sql): string
{
    return preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? $sql;
}

function statement_kind(string $sql): string
{
    $clean = strip_literals($sql);
    if (preg_match('/^\s*(select|with|show|explain|describe|desc|table|values)\b/i', $clean, $match)) {
        return strtolower($match[1]);
    }
    return '';
}

function is_readonly_query(string $sql): bool
{
    return statement_kind($sql) !== '';
}

function has_multiple_statements(string $sql): bool
{
    return str_contains(strip_literals($sql), ';');
}

function run_sql(string $key, string $sql, int $limit = 300): array
{
    $pdo = connect($key);
    $start = microtime(true);
    $stmt = $pdo->query($sql);
    $rows = [];
    $truncated = false;
    try {
        while ($row = $stmt->fetch()) {
            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }
    } finally {
        $stmt->closeCursor();
    }
    $elapsed = (microtime(true) - $start) * 1000;
    $columns = $rows !== [] ? array_keys($rows[0]) : [];
    return ['columns' => $columns, 'rows' => $rows, 'ms' => $elapsed, 'truncated' => $truncated];
}

function normalize_cell(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    $string = (string) $value;
    if (is_numeric($string)) {
        $number = (float) $string;
        $formatted = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
        return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
    }
    return trim($string);
}

function rows_equal(array $a, array $b, bool $ordered): bool
{
    $normalize = static function (array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $result[] = implode(' | ', array_map('normalize_cell', array_values($row)));
        }
        return $result;
    };

    $left = $normalize($a);
    $right = $normalize($b);

    if (count($left) !== count($right)) {
        return false;
    }
    if (!$ordered) {
        sort($left);
        sort($right);
    }
    return $left === $right;
}

function tasks(): array
{
    static $tasks = null;
    if ($tasks === null) {
        $raw = file_get_contents(__DIR__ . '/tasks/tasks.json');
        $tasks = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    return $tasks;
}

function task_by_id(string $id): ?array
{
    foreach (all_tasks() as $task) {
        if ($task['id'] === $id) {
            return $task;
        }
    }
    return null;
}

function check_task(array $task, string $sql): array
{
    if (($task['check'] ?? 'rows') === 'plan') {
        $result = run_sql($task['target'], $sql, 200);
        $plan = '';
        foreach ($result['rows'] as $row) {
            $plan .= implode(' ', array_map('normalize_cell', array_values($row))) . ' ';
        }
        $planLower = mb_strtolower($plan);
        foreach ($task['plan_must_contain'] as $needle) {
            if (!str_contains($planLower, mb_strtolower($needle))) {
                return [false, 'План запиту не містить очікуваної ознаки: «' . $needle . '».', $result];
            }
        }
        return [true, 'План запиту містить потрібні ознаки.', $result];
    }

    $reference = run_sql($task['target'], $task['reference'], 1000);
    $attempt = run_sql($task['target'], $sql, 1000);
    $ok = rows_equal($attempt['rows'], $reference['rows'], (bool) ($task['ordered'] ?? false));
    $message = $ok
        ? 'Результат збігається з еталоном.'
        : 'Результат відрізняється від еталона. Перевірте колонки, фільтри та сортування.';

    return [$ok, $message, $attempt];
}

function split_sql(string $sql): array
{
    $parts = [];
    $current = '';
    $inString = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($char === "'") {
            $inString = !$inString;
        }
        if ($char === ';' && !$inString) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $parts[] = trim($current);
    }

    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

function render_table(array $result, string $emptyText = 'Запит не повернув рядків.'): string
{
    if ($result['rows'] === []) {
        return '<p class="hint">' . e($emptyText) . '</p>';
    }

    $html = '<table><thead><tr>';
    foreach ($result['columns'] as $column) {
        $html .= '<th>' . e((string) $column) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($result['rows'] as $row) {
        $html .= '<tr>';
        foreach ($result['columns'] as $column) {
            $value = $row[$column] ?? null;
            $html .= '<td>' . e($value === null ? 'NULL' : (string) $value) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    if ($result['truncated']) {
        $html .= '<p class="hint">Показано перші ' . count($result['rows']) . ' рядків.</p>';
    }

    return $html;
}

function target_options(string $selected, bool $onlyReadonly = false): string
{
    $html = '';
    $groups = [];
    foreach (targets() as $key => $target) {
        if ($onlyReadonly && ($target['writable'] ?? false)) {
            continue;
        }
        $groups[$target['group']][] = [$key, $target];
    }

    foreach ($groups as $group => $items) {
        $html .= '<optgroup label="' . e((string) $group) . '">';
        foreach ($items as [$key, $target]) {
            $isSelected = $key === $selected ? ' selected' : '';
            $html .= '<option value="' . e($key) . '"' . $isSelected . '>' . e($target['label']) . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
}

function page_header(string $title): void
{
    echo '<!DOCTYPE html><html lang="uk"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — Learn DB</title>';
    echo '<link rel="stylesheet" href="/assets/style.css"></head><body>';
    echo '<header><strong>Learn DB</strong><nav class="menu">';
    echo '<a href="/">Огляд</a>';
    echo '<a href="/runner.php">SQL Runner</a>';
    echo '<a href="/nosql.php">NoSQL</a>';
    echo '<a href="/schema.php">Схема</a>';
    echo '<a href="/tasks.php">Вправи</a>';
    echo '<a href="/sandbox.php">Пісочниця</a>';
    echo '<a href="/locks.php">Блокування</a>';
    echo '<a href="/docs.php">Конспекти</a>';
    echo '</nav></header><main>';
}

function page_footer(): void
{
    echo '</main></body></html>';
}

function redis(): Redis
{
    static $redis = null;
    if ($redis === null) {
        $redis = new Redis();
        $redis->connect(config()['redis']['host'], config()['redis']['port'], 2.0);
    }
    return $redis;
}

function task_progress(): array
{
    try {
        $done = redis()->sMembers('learn:done');
    } catch (Throwable $error) {
        return [];
    }
    return is_array($done) ? $done : [];
}

function mark_task_done(string $id): void
{
    try {
        redis()->sAdd('learn:done', $id);
    } catch (Throwable $error) {
    }
}

function reset_task_progress(): void
{
    try {
        redis()->del('learn:done');
    } catch (Throwable $error) {
    }
}

function quoted(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function lower_keys(array $row): array
{
    $result = [];
    foreach ($row as $key => $value) {
        $result[strtolower((string) $key)] = $value;
    }
    return $result;
}

function table_columns(string $key, string $table): array
{
    $target = target($key);

    if ($target['driver'] === 'pgsql') {
        $sql = "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                       CASE WHEN pk.column_name IS NULL THEN '' ELSE 'PRI' END AS column_key
                FROM information_schema.columns c
                LEFT JOIN (
                    SELECT kcu.column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                      ON kcu.constraint_name = tc.constraint_name
                     AND kcu.table_schema = tc.table_schema
                    WHERE tc.table_schema = current_schema()
                      AND tc.table_name = :table
                      AND tc.constraint_type = 'PRIMARY KEY'
                ) pk ON pk.column_name = c.column_name
                WHERE c.table_schema = current_schema() AND c.table_name = :table
                ORDER BY c.ordinal_position";
    } else {
        $sql = 'SELECT column_name, column_type AS data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = :table
                ORDER BY ordinal_position';
    }

    $stmt = connect($key)->prepare($sql);
    $stmt->execute(['table' => $table]);
    return array_map('lower_keys', $stmt->fetchAll());
}

function table_indexes(string $key, string $table): array
{
    $target = target($key);

    if ($target['driver'] === 'pgsql') {
        $sql = 'SELECT indexname AS index_name, indexdef AS definition
                FROM pg_indexes
                WHERE schemaname = current_schema() AND tablename = :table
                ORDER BY indexname';
    } else {
        $sql = 'SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns, non_unique
                FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = :table
                GROUP BY index_name, non_unique
                ORDER BY index_name';
    }

    $stmt = connect($key)->prepare($sql);
    $stmt->execute(['table' => $table]);
    return array_map('lower_keys', $stmt->fetchAll());
}

function table_relations(string $key): array
{
    $target = target($key);

    if ($target['driver'] === 'pgsql') {
        $sql = "SELECT tc.constraint_name, tc.table_name, kcu.column_name,
                       ccu.table_name AS referenced_table, ccu.column_name AS referenced_column
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON kcu.constraint_name = tc.constraint_name
                 AND kcu.table_schema = tc.table_schema
                JOIN information_schema.constraint_column_usage ccu
                  ON ccu.constraint_name = tc.constraint_name
                 AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = current_schema()
                ORDER BY tc.table_name, tc.constraint_name";
    } else {
        $sql = "SELECT constraint_name, table_name, column_name,
                       referenced_table_name AS referenced_table,
                       referenced_column_name AS referenced_column
                FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL
                ORDER BY table_name, constraint_name";
    }

    $stmt = connect($key)->query($sql);
    return $stmt !== false ? array_map('lower_keys', $stmt->fetchAll()) : [];
}

function schema_tables(string $key): array
{
    $target = target($key);

    if ($target['driver'] === 'pgsql') {
        $sql = "SELECT table_name, table_type
                FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
                ORDER BY table_name";
    } else {
        $sql = "SELECT table_name, table_type
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                ORDER BY table_name";
    }

    $stmt = connect($key)->query($sql);
    return $stmt !== false ? array_map('lower_keys', $stmt->fetchAll()) : [];
}

function normalize_mongo(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('normalize_mongo', $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
        $value[$key] = normalize_mongo($item);
    }
    return $value;
}

function mongo_rows_equal(array $a, array $b, bool $ordered): bool
{
    $normalize = static fn (array $rows): array => array_map(
        static fn ($row): string => (string) json_encode(normalize_mongo($row), JSON_UNESCAPED_UNICODE),
        $rows
    );

    $left = $normalize($a);
    $right = $normalize($b);

    if (count($left) !== count($right)) {
        return false;
    }
    if (!$ordered) {
        sort($left);
        sort($right);
    }
    return $left === $right;
}

function run_mongo_spec(array $spec, int $limit = 200): array
{
    if (!isset($spec['collection'])) {
        throw new RuntimeException('Очікується JSON-об\'єкт із полем "collection" і одним із полів "pipeline" або "find".');
    }

    $database = (string) ($spec['database'] ?? 'learn');
    $collection = (string) $spec['collection'];
    if (preg_match('/[^A-Za-z0-9_]/', $collection) === 1) {
        throw new RuntimeException('Неприпустима назва колекції.');
    }

    $typeMap = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    $manager = new MongoDB\Driver\Manager(config()['mongo']['uri']);
    $rows = [];

    if (isset($spec['pipeline'])) {
        if (!is_array($spec['pipeline'])) {
            throw new RuntimeException('Поле "pipeline" має бути масивом стадій.');
        }
        foreach ($spec['pipeline'] as $stage) {
            foreach (array_keys((array) $stage) as $operator) {
                if (in_array($operator, ['$out', '$merge'], true)) {
                    throw new RuntimeException('Стадії $out і $merge у навчальному середовищі заборонені.');
                }
            }
        }
        $command = new MongoDB\Driver\Command([
            'aggregate' => $collection,
            'pipeline' => $spec['pipeline'],
            'cursor' => new stdClass(),
        ]);
        $cursor = $manager->executeCommand($database, $command, ['typeMap' => $typeMap]);
        foreach ($cursor as $document) {
            $rows[] = $document;
        }
    } else {
        $filter = $spec['find'] ?? [];
        if (!is_array($filter)) {
            throw new RuntimeException('Поле "find" має бути JSON-об\'єктом фільтра.');
        }
        $options = [
            'limit' => max(1, min($limit, (int) ($spec['limit'] ?? $limit))),
            'typeMap' => $typeMap,
        ];
        if (isset($spec['sort']) && is_array($spec['sort'])) {
            $options['sort'] = $spec['sort'];
        }
        if (isset($spec['skip'])) {
            $options['skip'] = max(0, (int) $spec['skip']);
        }
        if (isset($spec['projection']) && is_array($spec['projection'])) {
            $options['projection'] = $spec['projection'];
        }
        $cursor = $manager->executeQuery($database . '.' . $collection, new MongoDB\Driver\Query($filter, $options));
        foreach ($cursor as $document) {
            $rows[] = $document;
        }
    }

    return $rows;
}

function redis_state(Redis $redis): array
{
    $keys = $redis->keys('*');
    sort($keys);
    $state = [];

    foreach ($keys as $key) {
        $type = $redis->type($key);
        switch ($type) {
            case Redis::REDIS_STRING:
                $state[(string) $key] = ['string', (string) $redis->get($key)];
                break;
            case Redis::REDIS_LIST:
                $state[(string) $key] = ['list', $redis->lRange((string) $key, 0, -1)];
                break;
            case Redis::REDIS_SET:
                $members = $redis->sMembers((string) $key);
                sort($members);
                $state[(string) $key] = ['set', $members];
                break;
            case Redis::REDIS_HASH:
                $hash = $redis->hGetAll((string) $key);
                ksort($hash);
                $state[(string) $key] = ['hash', $hash];
                break;
            case Redis::REDIS_ZSET:
                $state[(string) $key] = ['zset', $redis->zRange((string) $key, 0, -1, true)];
                break;
            default:
                $state[(string) $key] = ['other', null];
        }
    }

    return $state;
}

function run_redis_commands(array $lines, int $database = 4): array
{
    $redis = new Redis();
    $redis->connect(config()['redis']['host'], config()['redis']['port'], 2.0);
    $redis->select($database);
    $redis->flushDB();

    $output = [];
    $error = null;

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = str_getcsv($line, ' ', '"', '');
        $parts = array_values(array_filter($parts, static fn ($part): bool => $part !== null && $part !== ''));
        if ($parts === []) {
            continue;
        }
        try {
            $result = $redis->rawCommand($parts[0], ...array_slice($parts, 1));
            $output[] = ['command' => $line, 'result' => format_redis_reply($result), 'error' => null];
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $output[] = ['command' => $line, 'result' => null, 'error' => $exception->getMessage()];
        }
    }

    $state = redis_state($redis);
    $redis->close();

    return ['output' => $output, 'state' => $state, 'error' => $error];
}

function format_redis_reply(mixed $value): string
{
    if ($value === false) {
        return '(nil)';
    }
    if ($value === true) {
        return 'OK';
    }
    if (is_array($value)) {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return (string) $value;
}

function nosql_tasks(): array
{
    static $tasks = null;
    if ($tasks === null) {
        $path = __DIR__ . '/tasks/nosql.json';
        $tasks = is_file($path)
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : [];
    }
    return $tasks;
}

function all_tasks(): array
{
    return array_merge(tasks(), nosql_tasks());
}

function check_mongo_task(array $task, array $spec): array
{
    $reference = run_mongo_spec($task['reference'] + ['database' => $task['database']]);
    $attempt = run_mongo_spec($spec + ['database' => $task['database']]);
    $ok = mongo_rows_equal($attempt, $reference, (bool) ($task['ordered'] ?? false));

    $message = $ok
        ? 'Результат збігається з еталоном.'
        : 'Результат відрізняється від еталона. Перевірте фільтр, проєкцію та сортування.';

    return [$ok, $message, $attempt];
}

function check_redis_task(array $task, array $lines): array
{
    $reference = run_redis_commands($task['reference']);
    $attempt = run_redis_commands($lines);

    $ok = $attempt['state'] === $reference['state']
        && count($attempt['output']) === count($reference['output']);

    $message = $ok
        ? 'Стан бази після ваших команд збігається з еталоном.'
        : 'Стан бази відрізняється від еталона. Перевірте команди та їхні аргументи.';

    return [$ok, $message, $attempt];
}
