#!/bin/sh
set -e

# Код примонтирован с хоста. Apache (www-data) должен писать в img/ (загрузки фото),
# поэтому приравниваем uid www-data к владельцу каталога (обычно пользователь хоста)
APP_UID=$(stat -c %u /var/www/html)
if [ "$APP_UID" != "0" ] && [ "$(id -u www-data)" != "$APP_UID" ]; then
    usermod -o -u "$APP_UID" www-data
fi

# Ставим PHP-зависимости в примонтированный код, если их ещё нет
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ не найден — выполняю composer install..."
    if ! composer install --no-dev --no-interaction --prefer-dist --working-dir=/var/www/html; then
        echo "[entrypoint] ОШИБКА: composer install не выполнен — без vendor/ лендинг не работает" >&2
        exit 1
    fi
    # vendor/ создан root — отдаём тому же владельцу, что и остальной код
    chown -R www-data /var/www/html/vendor
fi

exec docker-php-entrypoint apache2-foreground
