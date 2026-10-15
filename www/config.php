<?php

$env = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException('Не задано змінну середовища ' . $name . '.');
    }
    return $value;
};

$mysqlUser = $env('MYSQL_USER');
$mysqlPassword = $env('MYSQL_PASSWORD');
$mariadbUser = $env('MARIADB_USER');
$mariadbPassword = $env('MARIADB_PASSWORD');
$postgresUser = $env('POSTGRES_USER');
$postgresPassword = $env('POSTGRES_PASSWORD');
$readOnlyUser = 'readonly';
$readOnlyPassword = 'readonly';

return [
    'targets' => [
        'mysql' => [
            'label' => 'MySQL — learn (магазин)',
            'group' => 'Навчальні бази',
            'driver' => 'mysql',
            'analyze' => 'EXPLAIN ANALYZE ',
            'dsn' => 'mysql:host=mysql;port=3306;dbname=learn;charset=utf8mb4',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
        ],
        'mariadb' => [
            'label' => 'MariaDB — learn (бібліотека)',
            'group' => 'Навчальні бази',
            'driver' => 'mysql',
            'analyze' => 'ANALYZE ',
            'dsn' => 'mysql:host=mariadb;port=3306;dbname=learn;charset=utf8mb4',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
        ],
        'postgres' => [
            'label' => 'PostgreSQL — learn / shop (магазин)',
            'group' => 'Навчальні бази',
            'driver' => 'pgsql',
            'analyze' => 'EXPLAIN (ANALYZE, BUFFERS) ',
            'dsn' => 'pgsql:host=postgres;port=5432;dbname=learn',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
            'init' => ['SET search_path TO shop'],
        ],
        'mysql_big' => [
            'label' => 'MySQL — shop_big (100000 замовлень)',
            'group' => 'Великі дані',
            'driver' => 'mysql',
            'analyze' => 'EXPLAIN ANALYZE ',
            'dsn' => 'mysql:host=mysql;port=3306;dbname=shop_big;charset=utf8mb4',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
        ],
        'mariadb_big' => [
            'label' => 'MariaDB — shop_big (100000 замовлень)',
            'group' => 'Великі дані',
            'driver' => 'mysql',
            'analyze' => 'ANALYZE ',
            'dsn' => 'mysql:host=mariadb;port=3306;dbname=shop_big;charset=utf8mb4',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
        ],
        'postgres_big' => [
            'label' => 'PostgreSQL — shop_big (100000 замовлень)',
            'group' => 'Великі дані',
            'driver' => 'pgsql',
            'analyze' => 'EXPLAIN (ANALYZE, BUFFERS) ',
            'dsn' => 'pgsql:host=postgres;port=5432;dbname=learn',
            'user' => $readOnlyUser,
            'password' => $readOnlyPassword,
            'init' => ['SET search_path TO shop_big'],
        ],
        'sandbox_mysql' => [
            'label' => 'Пісочниця MySQL',
            'group' => 'Пісочниця',
            'driver' => 'mysql',
            'analyze' => 'EXPLAIN ANALYZE ',
            'dsn' => 'mysql:host=mysql;port=3306;dbname=sandbox;charset=utf8mb4',
            'user' => $mysqlUser,
            'password' => $mysqlPassword,
            'writable' => true,
        ],
        'sandbox_mariadb' => [
            'label' => 'Пісочниця MariaDB',
            'group' => 'Пісочниця',
            'driver' => 'mysql',
            'analyze' => 'ANALYZE ',
            'dsn' => 'mysql:host=mariadb;port=3306;dbname=sandbox;charset=utf8mb4',
            'user' => $mariadbUser,
            'password' => $mariadbPassword,
            'writable' => true,
        ],
        'sandbox_postgres' => [
            'label' => 'Пісочниця PostgreSQL',
            'group' => 'Пісочниця',
            'driver' => 'pgsql',
            'analyze' => 'EXPLAIN (ANALYZE, BUFFERS) ',
            'dsn' => 'pgsql:host=postgres;port=5432;dbname=sandbox',
            'user' => $postgresUser,
            'password' => $postgresPassword,
            'writable' => true,
        ],
    ],
    'mongo' => [
        'uri' => sprintf(
            'mongodb://%s:%s@mongo:27017/learn?authSource=admin',
            rawurlencode($env('MONGO_APP_USER')),
            rawurlencode($env('MONGO_APP_PASSWORD'))
        ),
    ],
    'redis' => [
        'host' => 'redis',
        'port' => 6379,
    ],
];
