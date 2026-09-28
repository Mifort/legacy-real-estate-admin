<?php
    // Лендинг: дома (NL_HOUSES) и квартиры (NL_PROP_RESALE).
    // Описание квартир хранится как Quill Delta и рендерится через nadar/quill-delta-parser,
    // вывод — через шаблонизатор Smarty.
    require $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    require $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    require $_SERVER["DOCUMENT_ROOT"] . "/vendor/autoload.php";

    use nadar\quill\Lexer;
    use Smarty\Smarty;

    db_connect();

    // Рендер описания из Quill Delta в безопасный HTML ("" при некорректных данных)
    function render_description($stored) {
        if ($stored === null || $stored === "") {
            return "";
        }
        $json = rawurldecode((string)$stored);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data["ops"])) {
            return "";
        }
        try {
            $lexer = new Lexer($json); // escapeInput=true по умолчанию — текст экранируется
            return $lexer->render();
        } catch (\Throwable $e) {
            error_log("Quill render error: " . $e->getMessage());
            return "";
        }
    }

    // Только локальные пути к загруженным изображениям
    function safe_photos($stored) {
        $photos = json_decode((string)$stored, true);
        if (!is_array($photos)) {
            return array();
        }
        $out = array();
        foreach ($photos as $p) {
            if (is_string($p) && preg_match('#^/img/[A-Za-z0-9_./-]+\.(jpe?g|png|gif|webp)$#i', $p)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    // Дома с материалом и числом квартир этого типа
    $houses = array();
    $q = "SELECT h.ID_NL_HOUSES, h.NL_HOUSES_SHORT, m.NL_MATERIAL_SHORT,
                 (SELECT COUNT(*) FROM NL_PROP_RESALE p WHERE p.ID_NL_HOUSES = h.ID_NL_HOUSES) AS FLATS_COUNT
          FROM NL_HOUSES h
          LEFT JOIN NL_MATERIAL m ON m.ID_NL_MATERIAL = h.ID_NL_MATERIAL
          ORDER BY h.NL_HOUSES_SHORT";
    $res = db_query($q) or die(db_error($q));
    while ($row = db_fetch_assoc($res)) {
        $houses[] = array(
            "name"     => $row["NL_HOUSES_SHORT"],
            "material" => $row["NL_MATERIAL_SHORT"],
            "count"    => (int)$row["FLATS_COUNT"],
        );
    }

    // Квартиры (без контакта собственника — приватное поле)
    $flats = array();
    $q = "SELECT p.ID_NL_PROP_RESALE, p.NL_PROP_RESALE_FLOOR, p.NL_PROP_RESALE_AREA_FULL,
                 p.NL_PROP_RESALE_COST_TOTAL, p.NL_PROP_RESALE_ADDRESS, p.NL_PROP_RESALE_DESCRIPTION,
                 p.NL_PROP_RESALE_PHOTO_URLS, p.NL_PROP_RESALE_PHONE,
                 v.NL_VIEW_SHORT, h.NL_HOUSES_SHORT, m.NL_MATERIAL_SHORT
          FROM NL_PROP_RESALE p
          LEFT JOIN NL_VIEW v ON v.ID_NL_VIEW = p.ID_NL_VIEW
          LEFT JOIN NL_HOUSES h ON h.ID_NL_HOUSES = p.ID_NL_HOUSES
          LEFT JOIN NL_MATERIAL m ON m.ID_NL_MATERIAL = p.ID_NL_MATERIAL
          ORDER BY p.ID_NL_PROP_RESALE DESC";
    $res = db_query($q) or die(db_error($q));
    while ($row = db_fetch_assoc($res)) {
        $photos = safe_photos($row["NL_PROP_RESALE_PHOTO_URLS"]);
        $flats[] = array(
            "id"          => (int)$row["ID_NL_PROP_RESALE"],
            "floor"       => $row["NL_PROP_RESALE_FLOOR"],
            "area"        => (float)$row["NL_PROP_RESALE_AREA_FULL"],
            "cost"        => (int)$row["NL_PROP_RESALE_COST_TOTAL"],
            "address"     => $row["NL_PROP_RESALE_ADDRESS"],
            "phone"       => $row["NL_PROP_RESALE_PHONE"],
            "view"        => $row["NL_VIEW_SHORT"],
            "house"       => $row["NL_HOUSES_SHORT"],
            "material"    => $row["NL_MATERIAL_SHORT"],
            "photo"       => $photos ? $photos[0] : "",
            "description" => render_description($row["NL_PROP_RESALE_DESCRIPTION"]),
        );
    }

    db_disconnect();

    $compileDir = $_SERVER["DOCUMENT_ROOT"] . "/templates_c";
    if (!is_dir($compileDir)) {
        mkdir($compileDir, 0775, true);
    }
    $smarty = new Smarty();
    $smarty->setTemplateDir($_SERVER["DOCUMENT_ROOT"] . "/templates");
    $smarty->setCompileDir($compileDir);
    $smarty->setEscapeHtml(true); // авто-экранирование всех переменных в шаблонах
    $smarty->assign("houses", $houses);
    $smarty->assign("flats", $flats);
    $smarty->display("index.tpl");
