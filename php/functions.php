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
