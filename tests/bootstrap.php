<?php
// Загрузчик тестов. Тесты выполняются ВНУТРИ web-контейнера изолированного стенда:
// приложение доступно по http://localhost, база — по хосту "db". Рабочие данные и
// основной стенд не затрагиваются (у тестового стенда свой проект, том и порты).

require __DIR__ . '/../vendor/autoload.php';

// Базовый URL приложения (Apache внутри того же контейнера)
if (!getenv('TEST_BASE_URL')) {
    putenv('TEST_BASE_URL=http://localhost');
}

// Подключение к БД берём из окружения контейнера (DB_HOST/DB_USER/DB_PASSWORD/DB_NAME)
Tests\Db::connect();

// Фиксированные учётные данные для тестов
define('TEST_ADMIN_LOGIN', 'admin');
define('TEST_ADMIN_PASSWORD', trim(@file_get_contents(__DIR__ . '/../.local-secrets/admin-password') ?: ''));
define('TEST_AGENT_LOGIN', 'test_agent');
define('TEST_AGENT_PASSWORD', 'Agent-Pass-2026-xyz');
define('TEST_GUEST_LOGIN', 'test_guest');
define('TEST_GUEST_PASSWORD', 'Guest-Pass-2026-xyz');

// Сидинг: агент (роль 1) и гость (роль 3) с известными паролями.
// db_password_sql() формирует AES_ENCRYPT(password_hash(...)) — тот же формат, что в приложении.
require __DIR__ . '/../php/config.php';
require __DIR__ . '/../php/functions.php';
db_connect();

Tests\Db::seedUser(TEST_AGENT_LOGIN, TEST_AGENT_PASSWORD, 1, 'Тест-агент');
Tests\Db::seedUser(TEST_GUEST_LOGIN, TEST_GUEST_PASSWORD, 3, 'Тест-гость');
