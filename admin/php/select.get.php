<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";

    db_connect();

    require_auth();
    require_csrf();

    // Обе таблицы — из белого списка, id родителя — целое
    $allowed = array_merge(ADMIN_ONLY_TABLES, USER_TABLES);
    $tblParent = $_POST["tblParent"] ?? "";
    $tblChild = $_POST["tblChild"] ?? "";
    $idParent = db_int($_POST["idParent"] ?? 0);

    if (!in_array($tblParent, $allowed, true) || !in_array($tblChild, $allowed, true)) {
        http_response_code(404);
        die("Неизвестная таблица");
    }

    $options = "";
    $query = "SELECT * FROM " . $tblChild . " WHERE ID_" . $tblParent . " = " . $idParent;
    $res = db_query($query) or die(db_error($query));
    while ($row = db_fetch_assoc($res)) {
        $options .= '<option value="' . html($row["ID_" . $tblChild]) . '">' . html($row[$tblChild . "_SHORT"]) . '</option>';
    }

    echo $options;

    db_disconnect();
?>
