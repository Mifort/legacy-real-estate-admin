<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";

    db_connect();

    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        die("Метод не поддерживается");
    }
    require_csrf();
    $tbl = require_table($_REQUEST["table"] ?? "");
    // Гостю добавлять записи нельзя — и резервировать ID тоже
    if ($_SESSION["ID_NL_USER_PERMISSION"] == "3") {
        http_response_code(403);
        die("Недостаточно прав");
    }

    // Резервирование ID под именованной блокировкой: параллельные запросы не получат один номер
    $lockName = db_quote("set_id_" . DBNAME . "_" . $tbl);
    $resLock = db_query("SELECT GET_LOCK(" . $lockName . ", 5) AS L");
    if (!$resLock || (int)db_fetch_assoc($resLock)["L"] !== 1) {
        http_response_code(503);
        die("Не удалось зарезервировать номер, повторите");
    }

    // MySQL 8 кеширует AUTO_INCREMENT в INFORMATION_SCHEMA — читаем актуальное значение
    db_query("SET SESSION information_schema_stats_expiry = 0");
    $query = "SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE (TABLE_SCHEMA = " . db_quote(DBNAME) . ") AND (TABLE_NAME = " . db_quote($tbl) . ")";
    $res = db_query($query);
    $id = (int)db_fetch_assoc($res)["AUTO_INCREMENT"];

    $query = "ALTER TABLE " . $tbl . " AUTO_INCREMENT = " . ($id + 1);
    db_query($query);

    db_query("SELECT RELEASE_LOCK(" . $lockName . ")");

    echo $id;

    db_disconnect();
?>