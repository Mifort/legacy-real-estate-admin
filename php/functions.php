<?
    /* ==========================================================================
       DATABASE FUNCTIONS
       ========================================================================== */

    function db_connect() {
        global $mysqli;

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = new mysqli(HOSTNAME, USERNAME, PASSWORD, DBNAME);

        if ($mysqli->connect_errno) {
            // Подробности — только в лог, пользователю — общее сообщение
            error_log("DB connect error: " . $mysqli->connect_error);
            http_response_code(500);
            echo "Ошибка подключения к базе данных";
            exit();
        }

        if (!$mysqli->set_charset("utf8mb4")) {
            error_log("DB charset error: " . $mysqli->error);
        }
    }

    function db_disconnect() {
        global $mysqli;
        $mysqli->close();
    }

    function db_data_seek($res, $row_number) {
        return $res->data_seek($row_number);
    }

    function db_query($query) {
        global $mysqli;
        return $mysqli->query($query);
    }

    function db_fetch_assoc($res) {
        return $res->fetch_assoc();
    }

    function db_num_rows($res) {
        return $res->num_rows;
    }

    function db_error($query) {
        // Текст запроса и ошибки не показываем пользователю (раскрытие структуры БД), пишем в лог
        global $mysqli;
        // Ключ AES и хеши паролей в лог не пишем
        $query = preg_replace('/AES_(EN|DE)CRYPT\((.*?)\)/s', 'AES_$1CRYPT(<redacted>)', (string)$query);
        error_log("DB query error: " . $mysqli->error . " | " . $query);
        http_response_code(500);
        return "Ошибка при работе с базой данных";
    }

    function db_real_escape_string($escapestr) {
        global $mysqli;
        return $mysqli->real_escape_string($escapestr);
    }

    // Экранированное строковое значение в кавычках для подстановки в SQL
    function db_quote($value) {
        return "'" . db_real_escape_string((string)$value) . "'";
    }

    // Целое значение для подстановки в SQL
    function db_int($value) {
        return (int)$value;
    }

    // Экранирование для вывода в HTML
    function html($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }

    /* ==========================================================================
       PASSWORD FUNCTIONS
       Пароль хранится как AES_ENCRYPT(password_hash(пароль), AESKEY):
       по заданию используется aes_encrypt с ключом из config.php, но даже при
       утечке ключа и дампа расшифровывается только bcrypt-хеш, а не пароль.
       ========================================================================== */

    // SQL-выражение для записи пароля в NL_USER_PASSWORD
    function db_password_sql($password) {
        $hash = password_hash((string)$password, PASSWORD_DEFAULT);
        return "AES_ENCRYPT(" . db_quote($hash) . ", " . db_quote(AESKEY) . ")";
    }

    // SQL-выражение для чтения: расшифровывает сохранённое значение (хеш)
    function db_password_decrypt_sql($column) {
        return "AES_DECRYPT(" . $column . ", " . db_quote(AESKEY) . ")";
    }

    // Проверка пароля по расшифрованному значению. Возвращает true/false.
    // $legacy выставляется в true, если запись в старом формате
    // AES_ENCRYPT(пароль) — её нужно перезаписать в новом формате.
    function password_check($decrypted, $password, &$legacy = false) {
        $legacy = false;
        if (!is_string($decrypted) || $decrypted === "") {
            return false;
        }
        $info = password_get_info($decrypted);
        if (($info["algo"] ?? null) !== null && ($info["algo"] ?? 0) !== 0) {
            return password_verify((string)$password, $decrypted);
        }
        // Старый формат: внутри AES лежал сам пароль
        if (hash_equals($decrypted, (string)$password)) {
            $legacy = true;
            return true;
        }
        return false;
    }
