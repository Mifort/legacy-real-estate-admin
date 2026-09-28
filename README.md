# TestDB — legacy-админка + лендинг

Тестовое задание: модернизация legacy-проекта (Apache + PHP, MySQL). Проект поднимается
в Docker, содержит админку на jqGrid, справочники недвижимости и публичный лендинг.

## Запуск

Требуется Docker (Docker Desktop / Docker Engine).

```bash
python3 scripts/setup-secrets.py  # новый запуск: генерирует локальные секреты
docker compose up -d --build
docker compose exec -T web php scripts/set-admin-password.php < .local-secrets/admin-password
```

При первом старте база инициализируется из `sql/testdb.sql` (уже содержит все изменения),
а PHP-зависимости ставятся автоматически (`composer install` при старте контейнера).

- Лендинг: <http://localhost:8080/>
- Админка: <http://localhost:8080/admin/>

### Учётные данные админки

Логин: `admin`. Случайный пароль хранится только в локальном файле
`.local-secrets/admin-password` (права 0600). Для просмотра:

```bash
cat .local-secrets/admin-password
```

`.env` содержит случайные пароли MySQL и ключ `AES_KEY`. Оба локальных пути
исключены из Git и запрещены для HTTP-доступа. Не публикуйте их и не отправляйте
вывод `docker compose config`: он может содержать подставленные секреты.

Публичный дамп содержит admin с пустым шифротекстом: до выполнения команды
`set-admin-password.php` вход невозможен. Пароль устанавливается через
`AES_ENCRYPT` с ключом окружения. Веб-порт доступен только на `127.0.0.1`.

Генератор предназначен для новой установки и отказывается заменять существующую
конфигурацию. Для существующей БД изменение `.env` само по себе не меняет пароли
MySQL. Изменение ключа AES требует перешифрования паролей пользователей.

## Состав

- `index.php` + `templates/` — публичный лендинг (Smarty 5); описания квартир рендерятся
  из Quill Delta через `nadar/quill-delta-parser`.
- `admin/` — административная панель: квартиры (`NL_PROP_RESALE`) и справочники
  «Вид из окна», «Материал дома», «Тип дома», пользователи.
- `php/` — конфигурация и общие функции работы с БД.
- `sql/testdb.sql` — итоговый дамп базы; `sql/migrations/` — пошаговые изменения схемы.
- `docker/`, `docker-compose.yml` — окружение (PHP 8.2 + Apache, MySQL 8.0).

## Разработка

```bash
docker compose exec web bash
docker compose exec db mysql -uroot -p testdb  # ввести локальный DB_ROOT_PASSWORD
```

## Отчёт

Подробное описание всех изменений и аудита безопасности — в [REPORT.md](REPORT.md).
