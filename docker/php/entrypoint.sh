#!/bin/sh
set -e

# Ставим PHP-зависимости в примонтированный код, если их ещё нет
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ не найден — выполняю composer install..."
    composer install --no-dev --no-interaction --prefer-dist --working-dir=/var/www/html || \
        echo "[entrypoint] ВНИМАНИЕ: composer install завершился с ошибкой"
fi

exec docker-php-entrypoint apache2-foreground
