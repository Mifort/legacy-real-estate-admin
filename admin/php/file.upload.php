<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";

    db_connect();

    // Загружать файлы может только авторизованный пользователь, с валидным CSRF-токеном
    require_post();
    require_csrf();
    $tbl = require_table($_REQUEST["table"] ?? "");

    // Достаём загруженный файл (совместимо со старым форматом FileAPI)
    if (isset($_FILES["files"]) && isset($_FILES["files"]["tmp_name"][0])) {
        $baseFileName = $_FILES["files"]["name"][0];
        $baseTmpName = $_FILES["files"]["tmp_name"][0];
    } elseif (isset($_FILES[0])) {
        $baseFileName = $_FILES[0]["name"];
        $baseTmpName = $_FILES[0]["tmp_name"];
    } else {
        http_response_code(400);
        die("Файл не получен");
    }

    if (!is_uploaded_file($baseTmpName)) {
        http_response_code(400);
        die("Некорректная загрузка");
    }

    // Разрешаем только изображения: и по расширению (белый список), и по реальному содержимому
    $allowedExt = array("jpg", "jpeg", "png", "gif", "webp");
    $ext = strtolower(pathinfo($baseFileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        http_response_code(415);
        die("Разрешены только изображения (jpg, jpeg, png, gif, webp)");
    }
    $info = @getimagesize($baseTmpName);
    $allowedMime = array("image/jpeg", "image/png", "image/gif", "image/webp");
    if (($info === false) || !in_array($info["mime"] ?? "", $allowedMime, true)) {
        http_response_code(415);
        die("Файл не является изображением");
    }

    // Колонка — только из полей этой таблицы; каталог и имя формируем сами
    $table = new ObjectTable($tbl);
    $reqCol = $_REQUEST["col"] ?? "";
    $colValid = false;
    foreach ($table->colArray as $c) {
        if (is_object($c) && $c->dbName === $reqCol && (($c->type === "photo") || ($c->type === "photos") || ($c->type === "file"))) {
            $colValid = true;
            break;
        }
    }
    if (!$colValid) {
        http_response_code(400);
        die("Недопустимое поле");
    }

    $col = mb_ereg_replace($tbl . "_", "", $reqCol);
    $dir = strtolower(str_replace("NL_", "", $tbl));
    // 24-часовой формат + случайный суффикс: несколько файлов за секунду не перезапишут друг друга
    $date = date('ymd_His', time()) . "_" . bin2hex(random_bytes(4));
    $id = db_int($_REQUEST["id"] ?? 0);

    $baseDir = realpath($_SERVER["DOCUMENT_ROOT"] . "/img");
    $targetDir = $baseDir . "/" . $dir;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $filename = "/img/" . $dir . "/" . $col . "_" . $id . "_" . $date . "." . $ext;
    $uploadfile = $_SERVER["DOCUMENT_ROOT"] . $filename;

    if (file_exists($uploadfile)) {
        http_response_code(409);
        die("Файл с таким именем уже существует, повторите загрузку");
    }

    if (move_uploaded_file($baseTmpName, $uploadfile)) {
        echo '"' . $filename . '"';
    } else {
        http_response_code(500);
        error_log("File upload failed for " . $uploadfile);
        die("Ошибка загрузки файла");
    }

    db_disconnect();
?>
