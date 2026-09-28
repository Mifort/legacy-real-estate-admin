# TestDB — legacy-админка + лендинг

Тестовое задание: модернизация legacy-проекта (Apache + PHP, MySQL). Проект поднимается
в Docker, содержит админку на jqGrid, справочники недвижимости и публичный лендинг.

## Запуск

Требуется Docker (Docker Desktop / Docker Engine).

```bash
cp .env.example .env          # при необходимости поменяйте порты/пароли
docker compose up -d --build  # сборка и запуск
```

При первом старте база инициализируется из `sql/testdb.sql` (уже содержит все изменения),
а PHP-зависимости ставятся автоматически (`composer install` при старте контейнера).

- Лендинг: <http://localhost:8080/>
- Админка: <http://localhost:8080/admin/>

### Учётные данные админки

| Логин | Пароль             |
|-------|--------------------|
| admin | `REDACTED_LOCAL_SECRET` |

Пароль хранится в БД зашифрованным через `AES_ENCRYPT` (ключ — константа `AESKEY`
в `.env` / `php/config.php`).

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
docker compose exec db mysql -uroot -p"$DB_ROOT_PASSWORD" testdb
```

## Отчёт

Подробное описание всех изменений и аудита безопасности — в [REPORT.md](REPORT.md).
