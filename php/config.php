<?
    // Секреты обязательны: публичных значений по умолчанию нет.
    foreach (["DB_PASSWORD", "AES_KEY"] as $secretName) {
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
    define("AESKEY", getenv("AES_KEY"));

    $mysqli = null;

    mb_internal_encoding("UTF-8");
    date_default_timezone_set("Europe/Moscow");
