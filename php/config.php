<?
    // Параметры подключения берутся из окружения (docker-compose / .env), значения по умолчанию — для локального запуска
    define("HOSTNAME", getenv("DB_HOST") ?: "localhost");
    define("USERNAME", getenv("DB_USER") ?: "root");
    define("PASSWORD", getenv("DB_PASSWORD") ?: "");
    define("DBNAME", getenv("DB_NAME") ?: "testdb");
    define("AESKEY", getenv("AES_KEY") ?: "REDACTED_LOCAL_SECRET");

    $mysqli = null;

    mb_internal_encoding("UTF-8");
    date_default_timezone_set("Europe/Moscow");
