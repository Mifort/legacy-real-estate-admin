<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";

    db_connect();

    require_auth();
    require_csrf();
    $_SESSION["onlymy"] = (($_POST["onlymy"] ?? "0") == "1") ? "1" : "0";

    db_disconnect();
?>