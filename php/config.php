<?
    // Пароль БД обязателен: публичного значения по умолчанию нет.
    foreach (["DB_PASSWORD"] as $secretName) {
        if (getenv($secretName) === false || getenv($secretName) === "") {
            error_log("Missing required environment variable: " . $secretName);
            http_response_code(500);
            exit("Не настроено окружение приложения");
        }
    }
    define("HOSTNAME", getenv("DB_HOST") ?: "localhost");
    define("USERNAME", getenv("DB_USER") ?: "testdb");
    define("PASSWORD", getenv("DB_PASSWORD"));
    define("DBNAME", getenv("DB_NAME") ?: "testdb");
    // Ключ AES_ENCRYPT для паролей пользователей (по заданию — в этом файле).
    // Шифруется не сам пароль, а его password_hash, поэтому знание ключа
    // не раскрывает пароли. Переменная окружения AES_KEY может его переопределить.
    define("AESKEY", getenv("AES_KEY") ?: "aes_some_key_to_testdb777");

    $mysqli = null;

    mb_internal_encoding("UTF-8");
    date_default_timezone_set("Europe/Moscow");
