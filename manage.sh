#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

set -a
source .env
set +a

BACKUP_DIR="backups"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '\033[32m  OK\033[0m %s\n' "$*"; }
bad() { printf '\033[31m  FAIL\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*"; }

usage() {
    cat <<'EOF'
Керування навчальним стендом баз даних

Використання: ./manage.sh <команда> [аргумент]

  up                 зібрати і запустити всі сервіси
  down               зупинити й видалити контейнери (дані лишаються)
  reset              повністю скинути стенд разом із даними
  restart [сервіс]   перезапустити все або один сервіс
  ps                 статус контейнерів
  logs [сервіс]      логи у реальному часі
  health             перевірити доступність БД та веб-інтерфейсів
  test               самоперевірка: підключення, еталони вправ, великі дані
  urls               показати всі адреси стенду
  seed               згенерувати великі дані shop_big (MySQL, MariaDB, PostgreSQL, MongoDB)
  seed-status        показати обсяг даних shop_big
  backup [ціль]      резервна копія: all | mysql | mariadb | postgres | mongo | redis
  restore <файл>     відновити з файлу (тип визначається за іменем)
  shell <ціль>       консоль: mysql | mariadb | postgres | mongo | redis
  help               ця довідка
EOF
}

cmd_up() {
    info "Запуск стенду"
    docker compose up -d --build
    docker compose ps
}

cmd_down() {
    info "Зупинка стенду"
    docker compose down
}

cmd_reset() {
    warn "Усі дані (включно з томами) буде видалено."
    printf 'Продовжити? [y/N] '
    read -r answer
    if [[ ! "$answer" =~ ^[Yy]$ ]]; then
        echo "Скасовано"
        exit 0
    fi
    docker compose down -v
    docker compose up -d --build
}

cmd_restart() {
    info "Перезапуск ${1:-усіх сервісів}"
    docker compose restart ${1:-}
}

cmd_ps() {
    docker compose ps -a
}

cmd_logs() {
    docker compose logs -f ${1:-}
}

cmd_health() {
    local failed=0 code label port pair

    info "Веб-інтерфейси"
    for pair in \
        "PHP-тренажер:$WEB_PORT" \
        "Adminer:$ADMINER_PORT" \
        "phpMyAdmin:$PHPMYADMIN_PORT" \
        "pgAdmin:$PGADMIN_PORT" \
        "Mongo Express:$MONGO_EXPRESS_PORT" \
        "Redis Commander:$REDIS_COMMANDER_PORT"; do
        label="${pair%%:*}"
        port="${pair##*:}"
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://localhost:${port}/" 2>/dev/null || true)
        if [[ "$code" == "200" || "$code" == "302" ]]; then
            ok "${label} — http://localhost:${port}"
        else
            bad "${label} — http://localhost:${port} (HTTP ${code:-000})"
            failed=$((failed + 1))
        fi
    done

    info "Бази даних"
    if docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysqladmin ping -h 127.0.0.1 -uroot --silent >/dev/null 2>&1; then
        ok "MySQL"
    else
        bad "MySQL"
        failed=$((failed + 1))
    fi
    if docker exec learn-mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
        ok "MariaDB"
    else
        bad "MariaDB"
        failed=$((failed + 1))
    fi
    if docker exec learn-postgres pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1; then
        ok "PostgreSQL"
    else
        bad "PostgreSQL"
        failed=$((failed + 1))
    fi
    if docker exec learn-mongo mongosh --quiet --eval "db.adminCommand('ping').ok" >/dev/null 2>&1; then
        ok "MongoDB"
    else
        bad "MongoDB"
        failed=$((failed + 1))
    fi
    if docker exec learn-redis redis-cli ping >/dev/null 2>&1; then
        ok "Redis"
    else
        bad "Redis"
        failed=$((failed + 1))
    fi

    if [[ "$failed" -gt 0 ]]; then
        warn "Проблемних сервісів: $failed"
        exit 1
    fi
    info "Усі сервіси доступні"
}

cmd_test() {
    info "Самоперевірка стенду"
    docker exec learn-web php /var/www/html/tools/selftest.php
}

cmd_urls() {
    cat <<EOF
  PHP-тренажер        http://localhost:${WEB_PORT}/
  SQL Runner          http://localhost:${WEB_PORT}/runner.php
  Вправи              http://localhost:${WEB_PORT}/tasks.php
  Пісочниця           http://localhost:${WEB_PORT}/sandbox.php
  Adminer             http://localhost:${ADMINER_PORT}/
  phpMyAdmin          http://localhost:${PHPMYADMIN_PORT}/
  pgAdmin             http://localhost:${PGADMIN_PORT}/
  Mongo Express       http://localhost:${MONGO_EXPRESS_PORT}/
  Redis Commander     http://localhost:${REDIS_COMMANDER_PORT}/

  MySQL      127.0.0.1:${MYSQL_PORT}      root/root, student/student, readonly/readonly
  MariaDB    127.0.0.1:${MARIADB_PORT}    root/root, student/student, readonly/readonly
  PostgreSQL 127.0.0.1:${POSTGRES_PORT}   student/student, readonly/readonly
  MongoDB    127.0.0.1:${MONGO_PORT}      root/student
  Redis      127.0.0.1:${REDIS_PORT}      без пароля
EOF
}

cmd_seed() {
    info "MySQL: shop_big"
    docker exec -i -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysql -uroot < seed/mysql_big.sql
    ok "MySQL готово"

    info "MariaDB: shop_big"
    docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" learn-mariadb mariadb -uroot --default-character-set=utf8mb4 < seed/mariadb_big.sql
    ok "MariaDB готово"

    info "PostgreSQL: shop_big"
    docker exec -i learn-postgres psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < seed/postgres_big.sql
    ok "PostgreSQL готово"

    info "MongoDB: shop_big"
    docker cp seed/mongo_big.js learn-mongo:/tmp/mongo_big.js >/dev/null
    docker exec learn-mongo mongosh --quiet -u "$MONGO_USER" -p "$MONGO_PASSWORD" --authenticationDatabase admin --file /tmp/mongo_big.js
    docker exec learn-mongo rm -f /tmp/mongo_big.js >/dev/null
    ok "MongoDB готово"

    cmd_seed_status
}

cmd_seed_status() {
    info "Обсяг даних shop_big"
    docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysql -uroot -N -e \
        "SELECT CONCAT('  MySQL     customers=', (SELECT COUNT(*) FROM shop_big.customers), ' products=', (SELECT COUNT(*) FROM shop_big.products), ' orders=', (SELECT COUNT(*) FROM shop_big.orders));" 2>/dev/null || bad "MySQL: база shop_big не знайдена, виконайте ./manage.sh seed"
    docker exec -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" learn-mariadb mariadb -uroot -N -e \
        "SELECT CONCAT('  MariaDB   customers=', (SELECT COUNT(*) FROM shop_big.customers), ' products=', (SELECT COUNT(*) FROM shop_big.products), ' orders=', (SELECT COUNT(*) FROM shop_big.orders));" 2>/dev/null || bad "MariaDB: база shop_big не знайдена, виконайте ./manage.sh seed"
    docker exec learn-postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -c \
        "SELECT '  PostgreSQL customers=' || (SELECT count(*) FROM shop_big.customers) || ' products=' || (SELECT count(*) FROM shop_big.products) || ' orders=' || (SELECT count(*) FROM shop_big.orders);" 2>/dev/null || bad "PostgreSQL: схема shop_big не знайдена, виконайте ./manage.sh seed"
    docker exec learn-mongo mongosh --quiet -u "$MONGO_USER" -p "$MONGO_PASSWORD" --authenticationDatabase admin --eval \
        "print('  MongoDB   customers=' + db.getSiblingDB('shop_big').customers.countDocuments() + ' products=' + db.getSiblingDB('shop_big').products.countDocuments() + ' orders=' + db.getSiblingDB('shop_big').orders.countDocuments())" 2>/dev/null || bad "MongoDB: база shop_big не знайдена, виконайте ./manage.sh seed"
}

backup_mysql() {
    local stamp file dbs="learn sandbox"
    stamp=$(date +%Y%m%d_%H%M%S)
    if docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysql -uroot -N -e "SHOW DATABASES LIKE 'shop_big'" 2>/dev/null | grep -q shop_big; then
        dbs="$dbs shop_big"
    fi
    file="$BACKUP_DIR/mysql_${stamp}.sql"
    docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysqldump -uroot --single-transaction --routines --databases $dbs > "$file"
    ok "MySQL -> $file ($(du -h "$file" | cut -f1))"
}

backup_mariadb() {
    local stamp file dbs="learn sandbox"
    stamp=$(date +%Y%m%d_%H%M%S)
    if docker exec -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" learn-mariadb mariadb -uroot -N -e "SHOW DATABASES LIKE 'shop_big'" 2>/dev/null | grep -q shop_big; then
        dbs="$dbs shop_big"
    fi
    file="$BACKUP_DIR/mariadb_${stamp}.sql"
    docker exec -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" learn-mariadb mariadb-dump -uroot --single-transaction --routines --databases $dbs > "$file"
    ok "MariaDB -> $file ($(du -h "$file" | cut -f1))"
}

backup_postgres() {
    local stamp file
    stamp=$(date +%Y%m%d_%H%M%S)
    file="$BACKUP_DIR/postgres_${stamp}.sql"
    docker exec learn-postgres pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --clean --if-exists > "$file"
    ok "PostgreSQL -> $file ($(du -h "$file" | cut -f1))"
}

backup_mongo() {
    local stamp file
    stamp=$(date +%Y%m%d_%H%M%S)
    file="$BACKUP_DIR/mongo_${stamp}.archive.gz"
    docker exec learn-mongo mongodump --username "$MONGO_USER" --password "$MONGO_PASSWORD" --authenticationDatabase admin --archive --gzip > "$file"
    ok "MongoDB -> $file ($(du -h "$file" | cut -f1))"
}

backup_redis() {
    local stamp file
    stamp=$(date +%Y%m%d_%H%M%S)
    file="$BACKUP_DIR/redis_${stamp}.rdb"
    docker exec learn-redis redis-cli BGSAVE >/dev/null
    sleep 2
    docker cp learn-redis:/data/dump.rdb "$file"
    ok "Redis -> $file ($(du -h "$file" | cut -f1))"
}

cmd_backup() {
    local target="${1:-all}"
    mkdir -p "$BACKUP_DIR"
    info "Резервне копіювання: $target"
    case "$target" in
        mysql) backup_mysql ;;
        mariadb) backup_mariadb ;;
        postgres) backup_postgres ;;
        mongo) backup_mongo ;;
        redis) backup_redis ;;
        all)
            backup_mysql
            backup_mariadb
            backup_postgres
            backup_mongo
            backup_redis
            ;;
        *)
            bad "Невідома ціль: $target (доступні: all, mysql, mariadb, postgres, mongo, redis)"
            exit 1
            ;;
    esac
}

cmd_restore() {
    local file="${1:-}"
    if [[ -z "$file" || ! -f "$file" ]]; then
        bad "Вкажіть наявний файл: ./manage.sh restore backups/mysql_20260921_120000.sql"
        exit 1
    fi
    info "Відновлення з $file"
    case "$(basename "$file")" in
        mysql_*)
            docker exec -i -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" learn-mysql mysql -uroot < "$file"
            ok "MySQL відновлено"
            ;;
        mariadb_*)
            docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" learn-mariadb mariadb -uroot --default-character-set=utf8mb4 < "$file"
            ok "MariaDB відновлено"
            ;;
        postgres_*)
            docker exec -i learn-postgres psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" < "$file"
            ok "PostgreSQL відновлено"
            ;;
        mongo_*)
            docker exec -i learn-mongo mongorestore --username "$MONGO_USER" --password "$MONGO_PASSWORD" --authenticationDatabase admin --archive --gzip --drop < "$file"
            ok "MongoDB відновлено"
            ;;
        redis_*)
            docker compose stop redis >/dev/null
            docker cp "$file" learn-redis:/data/dump.rdb
            docker compose start redis >/dev/null
            ok "Redis відновлено"
            ;;
        *)
            bad "Не вдалося визначити тип за іменем файлу (очікується префікс mysql_, mariadb_, postgres_, mongo_ або redis_)"
            exit 1
            ;;
    esac
}

cmd_shell() {
    case "${1:-}" in
        mysql) docker exec -it learn-mysql mysql --default-character-set=utf8mb4 -ustudent -pstudent learn ;;
        mariadb) docker exec -it learn-mariadb mariadb --default-character-set=utf8mb4 -ustudent -pstudent learn ;;
        postgres) docker exec -it learn-postgres psql -U student -d learn ;;
        mongo) docker exec -it learn-mongo mongosh -u root -p student --authenticationDatabase admin ;;
        redis) docker exec -it learn-redis redis-cli ;;
        *)
            bad "Вкажіть ціль: mysql | mariadb | postgres | mongo | redis"
            exit 1
            ;;
    esac
}

command="${1:-help}"
shift || true

case "$command" in
    up) cmd_up "$@" ;;
    down) cmd_down "$@" ;;
    reset) cmd_reset "$@" ;;
    restart) cmd_restart "$@" ;;
    ps) cmd_ps ;;
    logs) cmd_logs "$@" ;;
    health) cmd_health ;;
    test) cmd_test ;;
    urls) cmd_urls ;;
    seed) cmd_seed ;;
    seed-status) cmd_seed_status ;;
    backup) cmd_backup "${1:-all}" ;;
    restore) cmd_restore "${1:-}" ;;
    shell) cmd_shell "${1:-}" ;;
    help | *) usage ;;
esac
