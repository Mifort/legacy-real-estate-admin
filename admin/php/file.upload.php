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
    // Гость не сохраняет записи — и файлы ему загружать незачем
    if ($_SESSION["ID_NL_USER_PERMISSION"] == "3") {
        http_response_code(403);
        die("Недостаточно прав");
    }

    // id — либо ID существующей записи, либо ключ черновика новой записи d<32 hex>
    // (ID новой записи назначит база при сохранении, тогда файлы будут переименованы)
    $reqId = (string)($_REQUEST["id"] ?? "");
    if (preg_match('/^d([a-f0-9]{32})$/', $reqId, $m)) {
        // Ключ черновика публичен (виден в пути фото), поэтому владелец проверяется на сервере
        if (!upload_draft_claim($m[1])) {
            http_response_code(403);
            die("Черновик принадлежит другому пользователю или уже сохранён, откройте форму заново");
        }
        $fileKey = "d" . $m[1];
    } elseif (ctype_digit($reqId) && ((int)$reqId > 0)) {
        $id = (int)$reqId;
        // Файлы существующей записи может добавлять только её владелец или администратор
        $resOwner = db_query("SELECT * FROM " . $tbl . " WHERE ID_" . $tbl . " = " . $id) or die(db_error("upload owner"));
        $rowOwner = db_fetch_assoc($resOwner);
        if (!$rowOwner) {
            http_response_code(404);
            die("Запись не найдена");
        }
        if (!is_admin() && array_key_exists("ID_NL_USER", $rowOwner) && ($rowOwner["ID_NL_USER"] != $_SESSION["ID_NL_USER"])) {
            http_response_code(403);
            die("Нельзя загружать файлы в чужую запись");
        }
        $fileKey = (string)$id;
    } else {
        http_response_code(400);
        die("Некорректный идентификатор записи");
    }

    $baseDir = realpath($_SERVER["DOCUMENT_ROOT"] . "/img");
    $targetDir = $baseDir . "/" . $dir;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Очистка: файлы старше суток, на которые не ссылается ни одна запись таблицы
    // (брошенные черновики, лишние файлы после сбоя удаления). Файлы сохранённых записей
    // не трогаются; незавершённых операций не бывает — сохранение атомарно
    $referenced = array();
    foreach ($table->colArray as $c) {
        if (is_object($c) && (($c->type === "photo") || ($c->type === "photos"))) {
            $resRef = db_query("SELECT " . $c->dbName . " AS P FROM " . $tbl . " WHERE " . $c->dbName . " IS NOT NULL") or die(db_error("upload cleanup"));
            while ($rowRef = db_fetch_assoc($resRef)) {
                foreach ((array)json_decode((string)$rowRef["P"], true) as $p) {
                    if (is_string($p)) {
                        $referenced[basename($p)] = true;
                    }
                }
            }
        }
    }
    // Старые записи о черновиках: файлы несохранённых уже убраны, а ключи сохранённых
    // защищены от повторного захвата через NL_FORM_SUBMIT
    db_query("DELETE FROM NL_UPLOAD_DRAFT WHERE NL_UPLOAD_DRAFT_TIME < (NOW() - INTERVAL 2 DAY)") or die(db_error("upload draft prune"));
    foreach ((glob($targetDir . "/*") ?: array()) as $stale) {
        if (is_file($stale) && !isset($referenced[basename($stale)]) && (time() - filemtime($stale) > UPLOAD_DRAFT_TTL)) {
            @unlink($stale);
        }
    }

    $filename = "/img/" . $dir . "/" . $col . "_" . $fileKey . "_" . $date . "." . $ext;
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
