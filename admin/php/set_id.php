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

    $query = "SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE (TABLE_SCHEMA = " . db_quote(DBNAME) . ") AND (TABLE_NAME = " . db_quote($tbl) . ")";
    $res = db_query($query);
    $id = db_fetch_assoc($res)["AUTO_INCREMENT"];

    $query = "ALTER TABLE " . $tbl . " AUTO_INCREMENT = " . ($id + 1);
    db_query($query);

    echo $id;

    db_disconnect();
?>