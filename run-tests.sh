#!/bin/sh
# Интеграционные тесты на изолированном стеке. Копия проекта разворачивается во временном
# каталоге со своими портами, БД и секретами; рабочий каталог и основной стек не затрагиваются.
set -eu

DOCKER="docker"
command -v docker >/dev/null 2>&1 || DOCKER="docker.exe"
PROJECT="testii_test"
WEB_PORT="${TEST_WEB_PORT:-8090}"
DB_PORT="${TEST_DB_PORT:-33076}"
SRC="$(cd "$(dirname "$0")" && pwd)"

# Windows-путь для -v, если работаем через docker.exe (нет нативного docker)
hostpath() {
    if [ "$DOCKER" = "docker.exe" ] && command -v wslpath >/dev/null 2>&1; then
        wslpath -w "$1"
    else
        printf '%s' "$1"
    fi
}

WORK="$(mktemp -d)"
cleanup() {
    ( cd "$WORK" && "$DOCKER" compose -p "$PROJECT" down -v >/dev/null 2>&1 || true )
    # vendor/ мог быть создан root внутри контейнера — удаляем через контейнер
    "$DOCKER" run --rm -v "$(hostpath "$WORK")":/w alpine rm -rf /w >/dev/null 2>&1 || rm -rf "$WORK" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[tests] копирую проект в $WORK"
tar --exclude=./.git --exclude=./vendor --exclude=./.env --exclude=./.local-secrets \
    --exclude=./composer --exclude=./templates_c -C "$SRC" -cf - . | tar -C "$WORK" -xf -

cd "$WORK"
echo "[tests] генерирую секреты"
"$DOCKER" run --rm --user "$(id -u):$(id -g)" -v "$(hostpath "$WORK")":/app -w /app \
    php:8.2-cli php scripts/setup-secrets.php
sed -i "s/^WEB_PORT=.*/WEB_PORT=$WEB_PORT/; s/^DB_PORT=.*/DB_PORT=$DB_PORT/" .env

echo "[tests] поднимаю стек (порт $WEB_PORT)"
"$DOCKER" compose -p "$PROJECT" up -d --build

echo "[tests] жду готовности БД"
until "$DOCKER" compose -p "$PROJECT" exec -T db mysqladmin ping -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2)" >/dev/null 2>&1; do sleep 2; done
echo "[tests] жду composer install (vendor/)"
until [ -f "$WORK/vendor/autoload.php" ]; do sleep 2; done

echo "[tests] ставлю dev-зависимости (phpunit) и пароль admin"
"$DOCKER" compose -p "$PROJECT" exec -T web composer install --no-interaction --quiet
"$DOCKER" compose -p "$PROJECT" exec -T web php scripts/set-admin-password.php < .local-secrets/admin-password

echo "[tests] запускаю PHPUnit"
set +e
"$DOCKER" compose -p "$PROJECT" exec -T web vendor/bin/phpunit --colors=never
CODE=$?
set -e

echo "[tests] готово, код выхода $CODE"
exit $CODE
