<?
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(["httponly" => true, "samesite" => "Strict"]);
        session_start();
    }
    $url = explode("?", $_SERVER["REQUEST_URI"], 5);

    $page_array = explode("/admin/", $url[0]);
    $page = rtrim($page_array[1], "/");

    // CSRF-токен, единый на сессию
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    // Белые списки таблиц админки (защита от обращения к произвольным таблицам)
    const ADMIN_ONLY_TABLES = ["NL_USER", "NL_VIEW", "NL_MATERIAL", "NL_HOUSES"];
    const USER_TABLES = ["NL_PROP_RESALE"];

    function is_authenticated() {
        return isset($_SESSION["ID_NL_USER"]) && isset($_SESSION["ID_NL_USER_PERMISSION"]);
    }

    function is_admin() {
        return is_authenticated() && ($_SESSION["ID_NL_USER_PERMISSION"] == "2");
    }

    function require_auth() {
        if (!is_authenticated()) {
            http_response_code(403);
            die("Требуется авторизация");
        }
    }

    function require_admin() {
        require_auth();
        if (!is_admin()) {
            http_response_code(403);
            die("Недостаточно прав");
        }
    }

    // Изменяющие действия — только POST (иначе require_csrf ничего не проверит)
    function require_post() {
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
            http_response_code(405);
            header("Allow: POST");
            die("Метод не поддерживается");
        }
    }

    // Проверка CSRF-токена для любых POST-запросов
    function require_csrf() {
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
            return;
        }
        $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? ($_POST["csrf_token"] ?? "");
        if (empty($_SESSION["csrf_token"]) || !is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
            http_response_code(403);
            die("Недействительный CSRF-токен");
        }
    }

    // Проверяет право доступа к таблице (имя приходит из запроса) и возвращает его
    function require_table($tblName) {
        require_auth();
        if (in_array($tblName, ADMIN_ONLY_TABLES, true)) {
            require_admin();
            return $tblName;
        }
        if (in_array($tblName, USER_TABLES, true)) {
            return $tblName;
        }
        http_response_code(404);
        die("Неизвестная таблица");
    }

    // Черновики загрузок: файлы новой записи загружаются под ключом d<32 hex> (ID ещё нет).
    // Ключ попадает в публичный путь фото, поэтому он не секрет: право на черновик хранится
    // в БД (NL_UPLOAD_DRAFT), первый загрузивший — владелец. Черновик, по которому запись уже
    // сохранена (ключ есть в NL_FORM_SUBMIT), закрыт для новых загрузок и ссылок
    const UPLOAD_DRAFT_TTL = 86400;

    function upload_draft_consumed($token) {
        $res = db_query("SELECT 1 FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = " . db_quote($token)) or die(db_error("upload_draft_consumed"));
        return db_num_rows($res) > 0;
    }

    // Закрепляет черновик за текущим пользователем. false — черновик чужой или уже сохранён
    function upload_draft_claim($token) {
        if (upload_draft_consumed($token)) {
            return false;
        }
        db_query("INSERT IGNORE INTO NL_UPLOAD_DRAFT (NL_UPLOAD_DRAFT_TOKEN, ID_NL_USER, NL_UPLOAD_DRAFT_TIME) VALUES (" . db_quote($token) . ", " . db_int($_SESSION["ID_NL_USER"]) . ", NOW())") or die(db_error("upload_draft_claim"));
        return upload_draft_owned($token);
    }

    // Черновик принадлежит текущему пользователю и ещё не сохранён
    function upload_draft_owned($token) {
        $res = db_query("SELECT ID_NL_USER FROM NL_UPLOAD_DRAFT WHERE NL_UPLOAD_DRAFT_TOKEN = " . db_quote($token)) or die(db_error("upload_draft_owned"));
        $row = db_fetch_assoc($res);
        return $row && ((int)$row["ID_NL_USER"] === (int)$_SESSION["ID_NL_USER"]) && !upload_draft_consumed($token);
    }

    // Определение IP клиента (используется для журнала и троттлинга входа)
    function client_ip() {
        $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = "unknown";
        }
        return $ip;
    }

    class ObjectParam {
        public $dbName;
        public $rusName;
        public $required = false;
        public $type = "string"; // string, rich, integer, float, select, checkbox, photo, photos, file, encrypted, map
        public $maxLength = false;
        public $thisTable = true;
        public $onChange = false;
        public $onClick = false;
        public $onInit = false;
        public $onAddInit = false;
        public $onEditInit = false;
        public $formatter = false;
        public $defValue = false;
        public $editable = true;
        public $render = false;
        public $editHidden = true;
        public $width = 120;
        public $private = false; // поле видно только владельцу записи и администратору
    }

    class ObjectTable {
        public $leftJoins;
        public $colArray;
        public $dbName;
        public $where;
        public $jqGridCustom;
        public $editOnly = false;
        public $readOnly = false;

        function __construct($tableName) {
            $this->dbName = $tableName;
            $this->colArray = $this->getTableColArray();
            $this->leftJoins = $this->getTableLeftJoins();
            $this->where = $this->getTableWhere();
            $this->jqGridCustom = $this->getjqGridCustom();
        }

        private function getMainTableCol($colName, $prefix) {
            $colObject = new ObjectParam();
            $colObject->dbName = "NL_" . $prefix . "_" . substr($colName, 3);
            switch ($colName) {
                // ОБЩИЕ ПОЛЯ
                case "ID":
                    $colObject->dbName = "ID_NL_" . $prefix;
                    $colObject->rusName = "И/н";
                    $colObject->type = "integer";
                    $colObject->render = true;
                    $colObject->editable = true;
                    $colObject->editHidden = false;
                    // ID новой записи назначает база (AUTO_INCREMENT) при вставке;
                    // в форме добавления поле пустое, при редактировании — ID записи
                    $colObject->width = 40;
                    break;
                case "ID_NL_USER":
                    $colObject->dbName = "ID_NL_USER";
                    $colObject->rusName = "Ответственный";
                    $colObject->type = "select";
                    $colObject->defValue = $_SESSION["ID_NL_USER"];
                    if ($_SESSION["ID_NL_USER_PERMISSION"] == "2") {
                        $colObject->editable = true;
                    } elseif ($this->dbName != "NL_USER") {
                        $colObject->editable = true;
                        $colObject->editHidden = false;
                    } else {
                        $colObject->editable = false;
                    }
                    $colObject->render = true;
                    break;
                case "NL_SHORT":
                    $colObject->rusName = "Кратко";
                    $colObject->required = true;
                    $colObject->type = "string";
                    $colObject->render = true;
                    $colObject->maxLength = 25;
                    break;
                case "NL_FULL":
                    $colObject->rusName = "Полное имя";
                    $colObject->required = true;
                    $colObject->type = "string";
                    $colObject->render = true;
                    $colObject->maxLength = 2550;
                    break;
                case "NL_PHONE":
                    $colObject->rusName = "Телефон";
                    $colObject->type = "string";
                    $colObject->render = true;
                    $colObject->maxLength = 50;
                    $colObject->defValue = $_SESSION["NL_USER_PHONE"];
                    break;
                case "NL_PHONE_OWNER":
                    $colObject->rusName = "Контакт собственника";
                    $colObject->type = "string";
                    $colObject->render = false;
                    $colObject->maxLength = 255;
                    $colObject->private = true;
                    break;

                // СПРАВОЧНИКОВ ПОЛЬЗОВАТЕЛЕЙ
                case "ID_NL_USER_PERMISSION":
                    $colObject->dbName = "ID_NL_USER_PERMISSION";
                    $colObject->rusName = "Права";
                    $colObject->type = "select";
                    $colObject->render = true;
                    $colObject->defValue = 1;
                    $colObject->editable = true;
                    $colObject->editHidden = false;
                    break;
                case "NL_LOGIN":
                    $colObject->rusName = "Логин (на англ.)";
                    $colObject->required = true;
                    $colObject->type = "string";
                    $colObject->render = true;
                    $colObject->maxLength = 50;
                    break;
                case "NL_PASSWORD":
                    $colObject->rusName = "Пароль";
                    $colObject->required = true;
                    $colObject->type = "encrypted";
                    break;

                // СПРАВОЧНИКИ
                case "NL_PROP_IS_ACTIVE":
                    $colObject->rusName = "Объект активен?";
                    $colObject->type = "checkbox";
                    $colObject->render = true;
                    $colObject->required = true;
                    $colObject->defValue = '"Нет"';
                    break;

                // ЖУРНАЛЫ
                case "ID_NL_VIEW":
                    $colObject->dbName = "ID_NL_VIEW";
                    $colObject->rusName = "Вид из окна";
                    $colObject->type = "select";
                    $colObject->render = true;
                    break;
                case "ID_NL_HOUSES":
                    $colObject->dbName = "ID_NL_HOUSES";
                    $colObject->rusName = "Тип дома";
                    $colObject->type = "select";
                    $colObject->render = true;
                    break;
                case "ID_NL_MATERIAL":
                    $colObject->dbName = "ID_NL_MATERIAL";
                    $colObject->rusName = "Материал дома";
                    $colObject->type = "select";
                    $colObject->render = true;
                    break;
                case "NL_FLOOR":
                    $colObject->rusName = "Этаж";
                    $colObject->type = "string";
                    $colObject->render = true;
                    $colObject->maxLength = 25;
                    $colObject->width = 40;
                    break;
                case "NL_AREA_FULL":
                    $colObject->rusName = "Площадь (общая)";
                    $colObject->type = "float";
                    $colObject->render = true;
                    $colObject->required = true;
                    $colObject->width = 40;
                    break;
                case "NL_PHOTO_URLS":
                    $colObject->rusName = "Фотографии";
                    $colObject->type = "photos";
                    $colObject->render = true;
                    break;
                case "NL_COST_TOTAL":
                    $colObject->rusName = "Общая стоимость";
                    $colObject->type = "integer";
                    $colObject->render = true;
                    $colObject->width = 80;
                    break;
                case "NL_ADDRESS":
                    $colObject->rusName = "Адрес";
                    $colObject->type = "map";
                    $colObject->render = true;
                    $colObject->maxLength = 2550;
                    break;
                case "NL_DESCRIPTION":
                    $colObject->rusName = "Описание";
                    $colObject->type = "rich";
                    $colObject->render = false;
                    $colObject->required = true;
                    $colObject->maxLength = 5100;
                    break;
            }

            return $colObject;
        }

        private function getTableColArrayByColumnNames($tableName, $colNames) {
            $table = Array();

            array_push($table, $this->getMainTableCol("ID", $tableName));
            for ($i = 0; $i < count($colNames); $i++) {
                $colName = $colNames[$i];
                $colNameName = $colName;
                if (strpos($colName, "ID_") === false) {
                    $colNameName = mb_ereg_replace($tableName . "_", "", $colName);
                }
                array_push($table, $this->getMainTableCol($colNameName, $tableName));
            }

            $table["ID_" . $tableName] = $this->getMainTableCol("ID", $tableName);
            for ($i = 0; $i < count($colNames); $i++) {
                $colName = $colNames[$i];
                $colNameName = $colName;
                if (strpos($colName, "ID_") === false) {
                    $colNameName = mb_ereg_replace($tableName . "_", "", $colName);
                }
                $table[$colName] = $this->getMainTableCol($colNameName, $tableName);
            }

            return $table;
        }

        private function getTableColArray() {
            $table = Array();

            $tableName = substr($this->dbName, 3);
            switch ($this->dbName) {
                case "NL_USER":
                    $colNames = ["ID_NL_USER_PERMISSION", "NL_LOGIN", "NL_PASSWORD", "NL_SHORT", "NL_FULL", "NL_PHONE"];
                    $table = $this->getTableColArrayByColumnNames($tableName, $colNames);
                    break;
                case "NL_VIEW":
                    $colNames = ["NL_VIEW_SHORT"];
                    $table = $this->getTableColArrayByColumnNames($tableName, $colNames);
                    break;
                case "NL_MATERIAL":
                    $colNames = ["NL_MATERIAL_SHORT"];
                    $table = $this->getTableColArrayByColumnNames($tableName, $colNames);
                    break;
                case "NL_HOUSES":
                    $colNames = ["NL_HOUSES_SHORT", "ID_NL_MATERIAL"];
                    $table = $this->getTableColArrayByColumnNames($tableName, $colNames);
                    break;
                case "NL_PROP_RESALE":
                    $colNames = ["NL_PROP_RESALE_AREA_FULL", "NL_PROP_RESALE_ADDRESS", "NL_PROP_RESALE_FLOOR", "NL_PROP_RESALE_COST_TOTAL", "NL_PROP_RESALE_PHONE_OWNER", "ID_NL_VIEW", "ID_NL_HOUSES", "ID_NL_MATERIAL", "ID_NL_USER", "NL_PROP_RESALE_PHONE", "NL_PROP_RESALE_PHOTO_URLS", "NL_PROP_RESALE_DESCRIPTION"];
                    $table = $this->getTableColArrayByColumnNames($tableName, $colNames);
                    break;
            }

            return $table;
        }

        private function getTableLeftJoins() {
            switch ($this->dbName) {
                case "NL_USER":
                    return ["NL_USER_PERMISSION"];
                    break;
                case "NL_HOUSES":
                    return ["NL_MATERIAL"];
                    break;
                case "NL_PROP_RESALE":
                    return ["NL_VIEW", "NL_HOUSES", "NL_MATERIAL", "NL_USER"];
                    break;
                default:
                    return [];
                    break;
            }
        }

        private function getTableWhere() {
            switch ($this->dbName) {
                case "NL_USER":
                    return "(tbl.ID_NL_USER_PERMISSION != 2) AND (2 = " . $_SESSION["ID_NL_USER_PERMISSION"] . ")";
                    break;
                case "NL_PROP_RESALE":
                    if ($_SESSION["onlymy"] == "1") {
                        return "(tbl.ID_NL_USER = " . $_SESSION["ID_NL_USER"] . ")";
                    } else {
                        return "";
                    }
                    break;
                default:
                    return "";
                    break;
            }
        }

        public function getjqGridCustom() {
            switch ($this->dbName) {
                case "NL_PROP_RESALE":
                    $return = '
<script>
function showOnlyMy(jqgrid, row) {
    ';
                if ($_SESSION["ID_NL_USER_PERMISSION"] != "2") {
                    $return .= '
    if (row["ID_NL_USER"] != ' . json_encode($_SESSION["NL_USER_SHORT"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ') {
        $("#edit_" + jqgrid + "_top, #del_" + jqgrid + "_top").hide();
    } else {
        $("#edit_" + jqgrid + "_top, #del_" + jqgrid + "_top").show();
    }
                    ';
                }
                    $return .= '
}
</script>
                    ';
                    return $return;
                    break;
                default:
                    return "";
                    break;
            }
        }

        public function renderTable() {
            $jqGridHtml = "";
            $jqGridHtml .= file_get_contents($_SERVER["DOCUMENT_ROOT"] . "/admin/partial/jqgrid.html");
            if ($this->jqGridCustom) {
                $jqGridHtml .= "
                " . $this->jqGridCustom;
            }
            $colNames = '[';//""';
            $colModel = '[';//{ name: "ID_' . $this->dbName . '", index: "ID_' . $this->dbName . '", width : resizeData[pathName + "ID_' . $this->dbName . '"] ? resizeData[pathName + "ID_' . $this->dbName . '"] : 100}';
            $afterShowFormAdd = 'afterShowForm : function(formid) {
                initChosen($(formid.selector + " select:visible"));

                var $quill_elem = $(formid.selector + " .g-quill");
                if ($quill_elem.length > 0) {
                    
                    var toolbarOptions = [
                        ["bold", "italic", "underline", "strike"],        // toggled buttons
                        [{ "list": "ordered"}, { "list": "bullet" }],
                        [{ "script": "sub"}, { "script": "super" }],      // superscript/subscript
                        [{ "indent": "-1"}, { "indent": "+1" }],          // outdent/indent
                        [{ "header": [1, 2, 3, 4, 5, 6, false] }],
                        ["link", "image"],
                        [{ "color": [] }, { "background": [] }],          // dropdown with defaults from theme
                        [{ "align": [] }]
                    ];

                
                    var quill = new Quill($quill_elem[0], {
                        modules: {
                            toolbar: toolbarOptions
                        },
                        theme: "snow"
                    });
                
                    //console.log($quill_elem.data("value"));
                    if ($quill_elem.data("value")) {
                        //console.log(JSON.parse(decodeURIComponent($quill_elem.data("value"))));
                        quill.setContents(JSON.parse(decodeURIComponent($quill_elem.data("value"))));
                    }
                
                    $quill_elem.data("quill", quill);
                }

                modalResize();';

            $afterShowFormEdit = $afterShowFormAdd;

            switch ($this->dbName) {
                case "NL_PROP_RESALE":
                    $afterShowFormEdit .= '
                        if ($(formid.selector + " #ID_NL_USER").val() == ' . $_SESSION["ID_NL_USER"] . ') {
                            $(formid.selector + " #tr_' . $this->dbName . '_PHONE_OWNER").css("display", "");
                        } else {
                            $(formid.selector + " #tr_' . $this->dbName . '_PHONE_OWNER").css("display", "none");
                        }
                    ';
                    break;
            }

            for ($i = 0; $i < (count($this->colArray) / 2); $i++) {
                /* @var $col ObjectParam */
                $col = $this->colArray[$i];
                if ($i > 0) {
                    $colNames .= ',';
                    $colModel .= ',';
                }
                $colNames .= '"' . $col->rusName . '"';
                // colModel - first params
                $colWidth = 120;
                if ($col->width) {
                    $colWidth = $col->width;
                }
                $colModel .= '{ name: "' . $col->dbName . '", index: "' . $col->dbName . '", width : resizeData[pathName + "' . $col->dbName . '"] ? resizeData[pathName + "' . $col->dbName . '"] : ' . $colWidth;
                // colModel - hidden
                if (!$col->render) {
                    $colModel .= ', hidden : true';
                }
                // colModel - formatter: значения экранируются при выводе в ячейку
                if (($col->type == "photo") || ($col->type == "photos")) {
                    $colModel .= ', formatter: photosFormatter, unformat: photosUnformat';
                } elseif ($col->type == "float") {
                    $colModel .= ', formatter: numberFormatter';
                } elseif ($col->formatter === false) {
                    $colModel .= ', formatter: textFormatter, unformat: textUnformat';
                }
                // colModel - editable
                if ($col->editable) {
                    $colModel .= ', editable : true';
                    // colModel - edittype
                    if ($col->type == "string") {
                        if ($col->maxLength > 50) {
                            $colModel .= ', edittype : "textarea"';
                        }
                    } elseif ($col->type == "select") {
                        $colModel .= ', edittype : "select"';
                    } elseif ($col->type == "checkbox") {
                        $colModel .= ', edittype : "checkbox"';
                    } elseif (($col->type == "file") || ($col->type == "map")) {
                        $colModel .= ', edittype : "text"';
                    } elseif (($col->type == "photo") || ($col->type == "photos")) {
                        $colModel .= ', edittype : "text"';
                    } elseif ($col->type == "encrypted") {
                        $colModel .= ', edittype : "password"';
                    } elseif ($col->type == "rich") {
                        $colModel .= ', edittype : "custom"';
                    }
                    if (($col->formatter !== false) && ($col->type != "photo") && ($col->type != "photos")) {
                        $colModel .= ', formatter: function(cellValue, options, rowObject) {
                        ' . $col->formatter . '
                        }';
                    }
                    // colModel - edit options
                    $colModel .= ", editoptions : {";
                    $colModelEditOptions = "";
                    $dataInit = "dataInit: function(el) {";
                    if ($col->editHidden == false) {
                        $dataInit .= '
                            $(el).parent().parent().addClass("g-hidden");
                        ';
                    }
                    if ($col->type == "map") {
                        $dataInit .= '
                            $(el).addClass("g-mapInput");
                            $(el).parent().append("<span class=\'g-mapShowHide g-link\'>Показать/скрыть карту</span>");
                            $(el).next().click(function() {
                                $(this).next().slideToggle();
                            });
                            ymaps.ready(function () {
                                var idYMaps = el.id + "_ymaps";
                                $(el).parent().append("<div id=\'" + idYMaps + "\' class=\'g-mapYandex\'></div>");
                                var myMap = new ymaps.Map(idYMaps, {
                                    center: [44.891026, 37.32193],
                                    zoom: 14,
                                    controls: ["searchControl", "typeSelector", "fullscreenControl", "zoomControl"]
                                });
                                
                                var searchControl = myMap.controls.get("searchControl");
                                searchControl.events.add("change", function () {
                                    $(el).val(searchControl.getRequestString());
                                    //console.log("request: " + searchControl.getRequestString());
                                }, this);
                                
                                $(el).change(function() {
                                    if ($(el).val()) {
                                        searchControl.search($(el).val()).then(function () { searchControl.showResult(0); });
                                    }
                                });
                                
                                searchControl.options.set("fitMaxWidth", true).set("float", "left");
                                
                                if ($(el).val()) {
                                    searchControl.search($(el).val()).then(function () { searchControl.showResult(0); });
                                }
                                
                                $(".g-mapYandex").hide();
                            });
                        ';
                    } elseif ($col->type == "date") {
                        $dataInit .= '
                            $(el).datetimepicker({format: "Y-m-d",timepicker: false});
                        ';
                    } elseif ($col->type == "encrypted") {
                        $dataInit .= '
                            var rndBtn = $("<button class=\"g-btn\">случайный</button>");
                            rndBtn.click(function() {
                                $(el).attr("type", "text").val(Math.random().toString(36).slice(-8));
                            });
                            $(el).after(rndBtn);
                        ';
                    } elseif ($col->type == "select") {
                        if ($colModelEditOptions != "") {
                            $colModelEditOptions .= ", ";
                        }
                        $colModelEditOptions .= 'value : "';

                        $colModelEditOptions .= ':не выбрано';

                        $selectTableName = str_replace("_PARENT", "", str_replace("ID_", "", $col->dbName));
                        $query = "SELECT * FROM " . $selectTableName;
                        $res = db_query($query) or die(db_error($query));
                        if (db_num_rows($res) > 0) {
                            while ($row = db_fetch_assoc($res)) {
                                $optId = (int)$row[str_replace("_PARENT", "", $col->dbName)];
                                $optLabel = str_replace(array(";", ":", '"', "\\", "<", ">", "/"), array(",", " ", "'", "", "", "", ""), (string)$row[$selectTableName . "_SHORT"]);
                                $colModelEditOptions .= ";" . $optId . ":" . $optLabel;
                            }
                        }
                        $colModelEditOptions .= '"';

                    } elseif ($col->type == "checkbox") {
                        if ($colModelEditOptions != "") {
                            $colModelEditOptions .= ", ";
                        }
                        $colModelEditOptions .= 'value : "Да:Нет"';
                    } elseif (($col->type == "photo") || ($col->type == "photos") || ($col->type == "file")) {
                        /*if ($colModelEditOptions != "") {
                            $colModelEditOptions .= ", ";
                        }
                        $colModelEditOptions .= 'src: "/img/empty.png?1", dataInit: function(el) {
                            var parent = $(el).parent();
                            var rowid = $(el).attr("rowid");
                            var name = $(el).attr("name);
                            $("el").remove();
                            parent.append(\'<input type="file" id="\' + name + \'" name="\' + name + \'" rowid="\' + rowid + \'" class="FormElement">\');
                        }';*/

                        $multiple = "false";
                        if ($col->type == "photos") {
                            $multiple = "true";
                        }

                        $only_photo = "true";
                        if ($col->type == "file") {
                            $only_photo = "false";
                        }

                        /*$colModelEditOptions .= 'defaultValue: "[\"\/img\/empty.png\"]", 'dataInit: function(el){
                            dataInitFileFunction(el, "' . $this->dbName . '", "' . $col->dbName . '", ' . $multiple . ');
                        }';*/

                        // Значение уже содержит чистый JSON (см. photosUnformat), HTML не разбираем
                        $dataInit .= '
                            dataInitFileFunction(el, "' . $this->dbName . '", "' . $col->dbName . '", ' . $multiple . ', ' . $only_photo . ');
                        ';
                    } elseif ($col->type == "rich") {
                        if ($colModelEditOptions != "") {
                            $colModelEditOptions .= ", ";
                        }
                        $colModelEditOptions .= 'custom_element : get_quill_elem, custom_value : get_quill_value';
                    }
                    if ($col->defValue != false) {
                        if ($colModelEditOptions != "") {
                            $colModelEditOptions .= ", ";
                        }
                        if ($col->type == "string") {
                            $colModelEditOptions .= 'defaultValue : "' . $col->defValue . '"';
                        } else {
                            $colModelEditOptions .= 'defaultValue : ' . $col->defValue;
                        }
                    }

                    $dataInit .= " }";
                    if ($colModelEditOptions != "") {
                        $colModelEditOptions .= ", ";
                    }
                    $colModelEditOptions .= $dataInit;

                    $colModel .= $colModelEditOptions . "}";
                    // colModel - edit rules
                    $colModel .= ", editrules : {";
                    $colModelEditRules = "";
                    if ($col->required) {
                        if ($colModelEditRules != "") {
                            $colModelEditRules .= ", ";
                        }
                        $colModelEditRules .= "required: true";
                    }
                    if (!$col->render) {
                        if ($colModelEditRules != "") {
                            $colModelEditRules .= ", ";
                        }
                        $colModelEditRules .= "edithidden: true";
                    }
                    if ($col->type == "float") {
                        if ($colModelEditRules != "") {
                            $colModelEditRules .= ", ";
                        }
                        $colModelEditRules .= "number: true";
                    } elseif ($col->type == "integer") {
                        if ($colModelEditRules != "") {
                            $colModelEditRules .= ", ";
                        }
                        $colModelEditRules .= "integer: true";
                    }
                    $colModel .= $colModelEditRules . "}";
                }
                // colModel - form options
                $colModel .= ", formoptions : {";
                $colModel .= 'label: "' . $col->rusName . '"';
                if ($col->required) {
                    $colModel .= ', elmsuffix: "(*)"';
                }
                $colModel .= "}";

                $colModel .= "}";

                if (($col->onInit !== false) || ($col->onChange !== false)) {
                    $asf = '
                        (function(){
                            var el = "#' . $col->dbName . '";
                    ';
                    $afterShowFormAdd .= $asf;
                    $afterShowFormEdit .= $asf;
                }
                if ($col->onInit !== false) {
                    $asf = '
                            ' . $col->onInit . '
                        ';
                    $afterShowFormAdd .= $asf;
                    $afterShowFormEdit .= $asf;
                }
                if ($col->onChange !== false) {
                    $asf = '
                            $(el).bind("change", function(){' . $col->onChange . ';});
                        ';
                    $afterShowFormAdd .= $asf;
                    $afterShowFormEdit .= $asf;
                }
                if (($col->onInit !== false) || ($col->onChange !== false)) {
                    $asf = '
                        }());
                    ';
                    $afterShowFormAdd .= $asf;
                    $afterShowFormEdit .= $asf;
                }
                if ($col->onAddInit !== false) {
                    $asf = '
                        (function(){
                            var el = "#' . $col->dbName . '";
                    ';
                    $afterShowFormAdd .= $asf;
                    $asf = '
                            ' . $col->onAddInit . '
                        ';
                    $afterShowFormAdd .= $asf;
                    $asf = '
                        }());
                    ';
                    $afterShowFormAdd .= $asf;
                }
                if ($col->onEditInit !== false) {
                    $asf = '
                        (function(){
                            var el = "#' . $col->dbName . '";
                    ';
                    $afterShowFormEdit .= $asf;
                    $asf = '
                            ' . $col->onEditInit . '
                        ';
                    $afterShowFormEdit .= $asf;
                    $asf = '
                        }());
                    ';
                    $afterShowFormEdit .= $asf;
                }
            }
            $colNames .= "]";
            $colModel .= "]";
            $jqGridHtml = mb_ereg_replace("{tableName}", $this->dbName, $jqGridHtml);
            $jqGridHtml = mb_ereg_replace("{colNames}", $colNames, $jqGridHtml);
            $jqGridHtml = mb_ereg_replace("{colModel}", $colModel, $jqGridHtml);

            $addDelForm = "";
            if ($this->readOnly) {
                $addDelForm = 'add : false, edit : false, del : false';
            } elseif (($this->editOnly) || ($_SESSION["ID_NL_USER_PERMISSION"] == "3")) {
                $addDelForm = 'add : false, edit : true, edittext : "Изменить", del : false';
            } else {
                $addDelForm = 'add : true, addtext : "Добавить", edit : true, edittext : "Изменить", del : true, deltext : "Удалить"';
            }
            $jqGridHtml = mb_ereg_replace("{addEditForm}", $addDelForm, $jqGridHtml);

            $asf = '
            }';
            $afterShowFormAdd .= $asf;
            $afterShowFormEdit .= $asf;
            $jqGridHtml = mb_ereg_replace("{afterShowFormAdd}", $afterShowFormAdd, $jqGridHtml);
            $jqGridHtml = mb_ereg_replace("{afterShowFormEdit}", $afterShowFormEdit, $jqGridHtml);

            echo $jqGridHtml;
        }

        public function getData($page = 0, $limit = 0, $sidx = "1", $sord = "ASC", $search_where = "(1 = 1)") {
            $data = Array();

            $leftJoin = $this->get_query_left_joins();

            //if (isset($this->where) && ($this->where != false) && (trim($this->where) != "")) {
                //$query = "SELECT " .
            //} else {
                // Пароли (encrypted) НЕ расшифровываем в выборку — они не должны покидать сервер
                $query = "SELECT *";
                $query .= " FROM " . $this->dbName . " tbl " . $leftJoin . " WHERE " . $search_where;
                if (isset($this->where) && ($this->where != false) && (trim($this->where) != "")) {
                    $query .= " AND " . $this->where;
                }
                // Сортировка только по известной колонке (белый список) и в фиксированном направлении
                $sortCol = "ID_" . $this->dbName;
                foreach ($this->colArray as $col) {
                    if (is_object($col) && ($col->dbName === $sidx) && !($col->private && !is_admin()) && ($col->type != "encrypted")) {
                        $sortCol = $sidx;
                        break;
                    }
                }
                $sortCol = "tbl.`" . str_replace("`", "", $sortCol) . "`";
                $sortDir = (strtoupper((string)$sord) === "DESC") ? "DESC" : "ASC";
                $query .= " ORDER BY " . $sortCol . " " . $sortDir;
            //}
            $page = max(1, db_int($page));
            $limit = db_int($limit);
            if ($limit <= 0) { $limit = 50; }
            $query .= " LIMIT " . ($limit * ($page - 1)) . ", " . $limit;

            $res = db_query($query) or die(db_error($query));
            $i = 0;
            if (db_num_rows($res) > 0) {
                //echo print_r($this->colArray);
                while ($row = db_fetch_assoc($res)) {
                    $data[$i] = Array();
                    //array_push($data[$i], $row["ID_" . $this->dbName]);
                    //array_push($data[$i], ($i + 1) + (($page * $limit) - $limit));
                    for ($j = 0; $j < (count($this->colArray) / 2); $j++) {
                        $col = $this->colArray[$j];
                        $rowName = $this->colArray[$j]->dbName;
                        if ($col->type == "encrypted") {
                            // пароль не отдаём клиенту
                            array_push($data[$i], "");
                            continue;
                        }
                        // Приватные поля (контакт собственника) — только владельцу и админу
                        if ($col->private && !is_admin() && ((($row["ID_NL_USER"] ?? null)) != ($_SESSION["ID_NL_USER"] ?? null))) {
                            array_push($data[$i], "");
                            continue;
                        }
                        if ($col->type == "select") {
                            $rowName = mb_ereg_replace("ID_", "", $rowName) . "_SHORT";
                        }
                        array_push($data[$i], $row[$rowName] ?? "");
                    }
                    $i++;
                }
            }

            return $data;
        }

        public function showData() {
            // Номер запрашиваемой страницы
            $page = max(1, (int)($_GET['page'] ?? 1));
            // Количество запрашиваемых записей
            $limit = (int)($_GET['rows'] ?? 50);
            if ($limit <= 0) { $limit = 50; }
            // Поле, по которому следует производить сортировку
            $sidx = $_GET['sidx'];
            // Направление сортировки
            if (!isset($_GET['sord'])) {
                $sord = "ASC";
            } else {
                $sord = $_GET['sord'];
            }
            // Фильтры: колонка только из белого списка, значение — параметризовано
            $search_where = "(1=1)";
            $colByName = array();
            foreach ($this->colArray as $col) {
                if (is_object($col)) { $colByName[$col->dbName] = $col; }
            }
            $likeTypes = array("string", "rich", "photo", "photos", "file", "date", "checkbox", "map");
            foreach ($_GET as $key => $value) {
                if (strpos($key, "NL_") === false) { continue; }
                if (!is_string($value) || $value === "") { continue; }
                $baseKey = $key; $op = "=";
                if (substr($key, -5) === "_from") { $baseKey = substr($key, 0, -5); $op = ">="; }
                elseif (substr($key, -3) === "_to") { $baseKey = substr($key, 0, -3); $op = "<="; }
                if (!isset($colByName[$baseKey])) { continue; }
                $col = $colByName[$baseKey];
                // По приватным полям ищет только админ: иначе по числу найденных записей
                // можно подбирать чужой контакт, даже если сама ячейка скрыта
                if ($col->private && !is_admin()) { continue; }
                // Пароли в поиске не участвуют ни для кого
                if ($col->type == "encrypted") { continue; }
                $field = $baseKey;
                if (strpos($baseKey, "ID_") === 0) {
                    $field = substr($baseKey, 3) . "_SHORT";
                }
                $field = "`" . str_replace("`", "", $field) . "`";
                if ($op !== "=") {
                    if (!is_numeric($value)) { continue; }
                    $search_where .= " AND ($field $op " . (0 + $value) . ")";
                } elseif ((strpos($baseKey, "ID_") === 0) || in_array($col->type, $likeTypes, true)) {
                    $search_where .= " AND ($field LIKE " . db_quote("%" . $value . "%") . ")";
                } else {
                    $search_where .= " AND ($field = " . db_quote($value) . ")";
                }
            }

            $leftJoin = $this->get_query_left_joins();
            $query = "SELECT COUNT(*) AS COUNT FROM " . $this->dbName . " tbl $leftJoin WHERE $search_where";
            // Те же ограничения, что и в выборке (getData), иначе счётчик раскрывает лишнее
            if (isset($this->where) && ($this->where != false) && (trim($this->where) != "")) {
                $query .= " AND " . $this->where;
            }
            $res = db_query($query) or die(db_error($query));
            $row = db_fetch_assoc($res);
            // Теперь эта переменная хранит кол-во записей в таблице
            $count = $row['COUNT'];
            // Рассчитаем сколько всего страниц займут данные в БД
            if ($count > 0 && $limit > 0) {
                $total_pages = ceil($count / $limit);
            } else {
                $total_pages = 0;
            }
            // Если по каким-то причинам клиент запросил
            if (($page > $total_pages) && ($total_pages != 0))
                $page = $total_pages;
            // Рассчитываем стартовое значение для LIMIT запроса
            $start = $limit * $page - $limit;
            // Зашита от отрицательного значения
            if ($start < 0)
                $start = 0;
            // Запрос выборки данных
            $data = $this->getData($page, $limit, $sidx, $sord, $search_where);

            // Начало xml разметки
            $s = "<?xml version='1.0' encoding='utf-8'?>";
            $s .= "<rows>";
            $s .= "<page>" . (int)$page . "</page>";
            $s .= "<total>" . (int)$total_pages . "</total>";
            $s .= "<records>" . (int)$count . "</records>";
            // Строки данных; экранируем возможный выход из CDATA
            if (count($data) > 0) {
                for ($i = 0; $i < count($data); $i++) {
                    $s .= '<row id="' . html($data[$i][0]) . '">';
                    for ($j = 0; $j < count($data[$i]); $j++) {
                        $cell = str_replace("]]>", "]]]]><![CDATA[>", (string)$data[$i][$j]);
                        $s .= '<cell><![CDATA[' . $cell . ']]></cell>';
                    }
                    $s .= "</row>";
                }
            }
            $s .= "</rows>";
            // Перед выводом не забывайте выставить header с типом контента и кодировкой
            header("Content-type: text/xml; charset=utf-8");

            return $s;
        }

        public function saveData($oper, $id, $post) {
            // "add" - insert, "edit" - update, "del" - delete
            if (!in_array($oper, array("add", "edit", "del"), true)) {
                http_response_code(400);
                die("Неизвестная операция");
            }

            if ($this->readOnly) {
                http_response_code(403);
                die("Таблица доступна только для чтения");
            }

            // Если пользователь может только редактировать запись
            if ((($this->editOnly) || ($_SESSION["ID_NL_USER_PERMISSION"] == "3")) && (($oper == "add") || ($oper == "del"))) {
                http_response_code(403);
                die("Недостаточно прав");
            }

            // Ключ отправки формы добавления: повтор того же запроса (например, после потери
            // ответа) не создаёт дубликат, а возвращает уже созданную запись
            $submitToken = "";
            if ($oper == "add") {
                $submitToken = (string)($post["form_submit"] ?? "");
                if (!preg_match('/^[a-f0-9]{32}$/', $submitToken)) {
                    http_response_code(400);
                    die("Нет ключа отправки формы, откройте форму добавления заново");
                }
                if ($this->findSubmitted($submitToken) !== null) {
                    return;
                }
            }

            $row_cur = array();
            if ($oper != "add") {
                // Изменения одной записи выполняются строго по очереди: блокировка держится
                // до конца операции, включая удаление файлов (снимается и при обрыве соединения)
                $lockName = db_quote("rec_" . DBNAME . "_" . $this->dbName . "_" . db_int($id));
                $resLock = db_query("SELECT GET_LOCK(" . $lockName . ", 10) AS L") or die(db_error("GET_LOCK"));
                if ((int)db_fetch_assoc($resLock)["L"] !== 1) {
                    http_response_code(503);
                    die("Запись сейчас изменяется, повторите попытку");
                }

                $query_cur = "SELECT * FROM " . $this->dbName . " WHERE ID_" . $this->dbName . " = " . db_int($id);
                $res_cur = db_query($query_cur) or die(db_error($query_cur));
                $row_cur = db_fetch_assoc($res_cur);
                $this->checkRowAccess($row_cur);
            }

            // Серверная проверка значений (клиентские required/maxLength можно обойти),
            // в т.ч. что все фотографии существуют и принадлежат записи или черновику пользователя
            if ($oper != "del") {
                $error = $this->validatePost($oper, db_int($id), $post, $row_cur);
                if ($error !== "") {
                    http_response_code(400);
                    die($error);
                }
            }

            // Всё сохранение — одна транзакция: журнал, запись, ключ отправки. Файлы при
            // сохранении не перемещаются, поэтому при ошибке или падении процесса на диске
            // откатывать нечего, а черновик остаётся для повторной отправки
            db_query("START TRANSACTION") or die(db_error("START TRANSACTION"));

            if ($oper == "add") {
                $query_submit = "INSERT INTO NL_FORM_SUBMIT (NL_FORM_SUBMIT_TOKEN, NL_FORM_SUBMIT_TABLE, NL_FORM_SUBMIT_RECORD, ID_NL_USER, NL_FORM_SUBMIT_TIME) VALUES (" . db_quote($submitToken) . ", " . db_quote($this->dbName) . ", 0, " . db_int($_SESSION["ID_NL_USER"]) . ", NOW())";
                if (!db_query($query_submit)) {
                    $duplicate = (db_errno() == 1062);
                    $message = db_error($query_submit);
                    db_query("ROLLBACK");
                    // Параллельный запрос с тем же ключом успел сохранить запись
                    if ($duplicate && ($this->findSubmitted($submitToken) !== null)) {
                        http_response_code(200);
                        return;
                    }
                    die($message);
                }
            } else {
                // Перечитываем запись под блокировкой: старый список фото берём из неё
                $query_lock = "SELECT * FROM " . $this->dbName . " WHERE ID_" . $this->dbName . " = " . db_int($id) . " FOR UPDATE";
                $res_lock = db_query($query_lock) or $this->rollbackAndDie($query_lock);
                $row_cur = db_fetch_assoc($res_lock);
                // Права и данные — повторно по заблокированной строке: запись могли изменить
                // после первой проверки (например, сменить владельца)
                $this->checkRowAccess($row_cur, true);
                if ($oper != "del") {
                    $error = $this->validatePost($oper, db_int($id), $post, $row_cur);
                    if ($error !== "") {
                        db_query("ROLLBACK");
                        http_response_code(400);
                        die($error);
                    }
                }
            }

            $user_ip = client_ip();
            // log master
            $query_log = "INSERT INTO NL_LOG(NL_LOG_DATE, NL_LOG_TIME, NL_LOG_IP, NL_LOG_IUD, NL_LOG_TABLE_NAME, ID_NL_USER) VALUES(" . db_quote(date("Y.m.d")) . ", " . db_quote(date("H:i:s")) . ", " . db_quote($user_ip) . ", " . db_quote($oper) . ", " . db_quote($this->dbName) . ", " . db_int($_SESSION["ID_NL_USER"]) . ")";
            db_query($query_log) or $this->rollbackAndDie($query_log);
            $query_log = "SELECT LAST_INSERT_ID() AS ID_LOG";
            $res_log = db_query($query_log) or $this->rollbackAndDie($query_log);
            $row_log = db_fetch_assoc($res_log);

            $fields = Array();
            $values = Array();
            $filesToDelete = Array();
            for ($i = 0; $i < (count($this->colArray) / 2); $i++) {
                /* @var $col ObjectParam */
                $col = $this->colArray[$i];

                if (($col->editable) && ($col->thisTable)) {
                    // Первичный ключ: при добавлении его назначает база (AUTO_INCREMENT) —
                    // значение из формы не используется; при изменении — проверенный $id
                    if ($col->dbName === "ID_" . $this->dbName) {
                        if ($oper != "add") {
                            array_push($fields, $col->dbName);
                            array_push($values, db_int($id));
                        }
                        continue;
                    }

                    // Фактическое значение из формы; для не-админа принудительно ставим свой ID_NL_USER (защита от IDOR)
                    $postVal = isset($post[$col->dbName]) ? $post[$col->dbName] : "";
                    if (($col->dbName === "ID_NL_USER") && !is_admin()) {
                        $postVal = $_SESSION["ID_NL_USER"];
                    }

                    // Пустой пароль при редактировании — оставляем прежний (поле не трогаем)
                    if (($col->type == "encrypted") && ($oper == "edit") && (trim((string)$postVal) === "")) {
                        continue;
                    }

                    array_push($fields, $col->dbName);
                    if (trim((string)$postVal) === "") {
                        array_push($values, "NULL");
                    } elseif (($col->type == "string") || ($col->type == "rich") || ($col->type == "photo") || ($col->type == "photos") || ($col->type == "file") || ($col->type == "date") || ($col->type == "checkbox") || ($col->type == "map")) {
                        array_push($values, db_quote($postVal));
                    } elseif ($col->type == "encrypted") {
                        array_push($values, db_password_sql($postVal));
                    } else {
                        // числовые типы (integer/float/select)
                        array_push($values, is_numeric($postVal) ? (string)(0 + $postVal) : "NULL");
                    }

                    if ($col->type != "encrypted") {
                        $valold = "''";
                        if ($row_cur) {
                            $valold = db_quote($row_cur[$col->dbName] ?? "");
                        }
                        $valnew = "''";
                        if ($oper != "del") {
                            $valnew = db_quote($postVal);
                        }

                        $query_log_detail = "INSERT INTO NL_LOG_DETAIL(ID_NL_LOG, NL_LOG_DETAIL_OLD, NL_LOG_DETAIL_NEW, NL_LOG_DETAIL_FIELD) VALUES (" . db_int($row_log["ID_LOG"]) . ", " . $valold . ", " . $valnew . ", " . db_quote($col->dbName) . ")";
                        db_query($query_log_detail) or $this->rollbackAndDie($query_log_detail);
                    }
                }

                // Файлы, которые перестали быть нужны: были в сохранённой записи и отсутствуют
                // в новом списке (при удалении записи — все её файлы). Удаляются после COMMIT
                if ((($col->type == "photo") || ($col->type == "photos")) && ($oper != "add")) {
                    $oldPhotos = json_decode((string)($row_cur[$col->dbName] ?? ""), true);
                    $newPhotos = ($oper == "del") ? array() : json_decode((string)($post[$col->dbName] ?? ""), true);
                    foreach (array_diff(is_array($oldPhotos) ? $oldPhotos : array(), is_array($newPhotos) ? $newPhotos : array()) as $photo) {
                        $file = $this->photoFilePath($photo);
                        if ($file !== null) {
                            $filesToDelete[$photo] = $file;
                        }
                    }
                }
            }
            //
            $query = "";
            if ($oper == "add") {
                $query = "INSERT INTO " . $this->dbName . " (" . implode(",", $fields) . ") VALUES(" . implode(",", $values) . ")";
            } elseif ($oper == "edit") {
                $query = "UPDATE " . $this->dbName . " SET ";
                for ($i = 0; $i < count($fields); $i++) {
                    if ($i > 0) {
                        $query .= ",";
                    }
                    $query .= $fields[$i] . " = " . $values[$i];
                }
                $query .= " WHERE ID_" . $this->dbName . " = " . db_int($id);
            } elseif ($oper == "del") {
                $query = "DELETE FROM " . $this->dbName . " WHERE ID_" . $this->dbName . " = " . db_int($id);
            }
            db_query($query) or $this->rollbackAndDie($query);

            if ($oper == "add") {
                $query_new = "UPDATE NL_FORM_SUBMIT SET NL_FORM_SUBMIT_RECORD = LAST_INSERT_ID() WHERE NL_FORM_SUBMIT_TOKEN = " . db_quote($submitToken);
                db_query($query_new) or $this->rollbackAndDie($query_new);
            }

            db_query("COMMIT") or $this->rollbackAndDie("COMMIT");

            // Данные сохранены. Файл, на который ссылается другая запись, не удаляем. Сбой
            // удаления оставит лишний файл (мусор, его уберёт очистка), но не потерю данных
            foreach ($filesToDelete as $photo => $file) {
                // Проверяем все записи, включая эту: параллельная правка могла вернуть фото
                if ($this->photoReferenced($photo)) {
                    continue;
                }
                if (is_file($file) && !@unlink($file)) {
                    error_log("saveData: could not delete " . $file);
                }
            }

            if ($oper != "add") {
                db_query("SELECT RELEASE_LOCK(" . $lockName . ")");
            }
        }

        // Доступ текущего пользователя к существующей записи; при отказе — ответ и выход
        private function checkRowAccess($row, $inTransaction = false) {
            $code = 0;
            $message = "";
            if (!$row) {
                $code = 404;
                $message = "Запись не найдена";
            } elseif ($_SESSION["ID_NL_USER_PERMISSION"] == "3") {
                $code = 403;
                $message = "Недостаточно прав";
            } elseif (($_SESSION["ID_NL_USER_PERMISSION"] == "1") && ($row["ID_NL_USER"] != $_SESSION["ID_NL_USER"])) {
                $code = 403;
                $message = "Нельзя изменять чужую запись";
            }
            if ($code !== 0) {
                if ($inTransaction) {
                    db_query("ROLLBACK");
                }
                http_response_code($code);
                die($message);
            }
        }

        // Ссылается ли на файл какая-либо запись таблицы (по всем фото-колонкам)
        private function photoReferenced($photo) {
            $like = db_quote('%"' . addcslashes($photo, '%_\\') . '"%');
            for ($i = 0; $i < (count($this->colArray) / 2); $i++) {
                $col = $this->colArray[$i];
                if (($col->type != "photo") && ($col->type != "photos")) {
                    continue;
                }
                $q = "SELECT 1 FROM " . $this->dbName . " WHERE " . $col->dbName . " LIKE " . $like . " LIMIT 1";
                $res = db_query($q);
                // Если проверить не удалось — считаем, что файл используется, и не удаляем
                if (!$res || (db_num_rows($res) > 0)) {
                    return true;
                }
            }
            return false;
        }

        // Ошибка внутри транзакции: сообщение фиксируем до ROLLBACK (иначе потеряется текст ошибки)
        private function rollbackAndDie($query) {
            $message = db_error($query);
            db_query("ROLLBACK");
            die($message);
        }

        // ID записи, уже сохранённой по этому ключу отправки, или null
        private function findSubmitted($token) {
            $res = db_query("SELECT NL_FORM_SUBMIT_TABLE, NL_FORM_SUBMIT_RECORD, ID_NL_USER FROM NL_FORM_SUBMIT WHERE NL_FORM_SUBMIT_TOKEN = " . db_quote($token)) or die(db_error("findSubmitted"));
            $row = db_fetch_assoc($res);
            if (!$row) {
                return null;
            }
            if (($row["NL_FORM_SUBMIT_TABLE"] !== $this->dbName) || ((int)$row["ID_NL_USER"] !== (int)$_SESSION["ID_NL_USER"])) {
                http_response_code(409);
                die("Ключ отправки формы уже использован, откройте форму заново");
            }
            return (int)$row["NL_FORM_SUBMIT_RECORD"];
        }

        // Абсолютный путь к файлу фото этой таблицы или null, если путь недопустим
        private function photoFilePath($photo) {
            $dir = strtolower(str_replace("NL_", "", $this->dbName));
            if (!is_string($photo) || !preg_match('#^/img/' . preg_quote($dir, '#') . '/[A-Za-z0-9_.-]+\.(jpe?g|png|gif|webp)$#i', $photo)) {
                return null;
            }
            return $_SERVER["DOCUMENT_ROOT"] . $photo;
        }

        // Проверяет и нормализует значения формы. Возвращает текст ошибки или "".
        private function validatePost($oper, $id, &$post, $row_cur = array()) {
            for ($i = 0; $i < (count($this->colArray) / 2); $i++) {
                /* @var $col ObjectParam */
                $col = $this->colArray[$i];
                if (!$col->editable || !$col->thisTable || ($col->dbName === "ID_" . $this->dbName)) {
                    continue;
                }
                $value = isset($post[$col->dbName]) ? $post[$col->dbName] : "";
                if (!is_string($value)) {
                    return "Некорректное значение поля «" . $col->rusName . "»";
                }
                $empty = (trim($value) === "");
                // Пустой пароль при редактировании означает «не менять»
                if ($col->required && $empty && !(($col->type == "encrypted") && ($oper == "edit"))) {
                    return "Поле «" . $col->rusName . "» обязательно";
                }
                if ($empty) {
                    continue;
                }
                if ($col->maxLength && (mb_strlen($value) > $col->maxLength)) {
                    return "Поле «" . $col->rusName . "» длиннее " . (int)$col->maxLength . " символов";
                }
                if (($col->type == "photo") || ($col->type == "photos")) {
                    $photos = json_decode($value, true);
                    if (!is_array($photos) || ($photos !== array_values($photos))) {
                        return "Некорректный список фотографий";
                    }
                    $dir = strtolower(str_replace("NL_", "", $this->dbName));
                    $colPrefix = str_replace($this->dbName . "_", "", $col->dbName);
                    // Уже сохранённые в записи файлы допустимы при правке (в т.ч. с именем черновика)
                    $savedPhotos = json_decode((string)($row_cur[$col->dbName] ?? ""), true);
                    $savedPhotos = is_array($savedPhotos) ? $savedPhotos : array();
                    foreach ($photos as $photo) {
                        if (!is_string($photo) || !preg_match('#^/img/' . preg_quote($dir, '#') . '/[A-Za-z0-9_.-]+\.(jpe?g|png|gif|webp)$#i', $photo)) {
                            return "Недопустимый путь фотографии";
                        }
                        // Файл должен принадлежать этой записи (edit), черновику пользователя (add)
                        // или уже быть сохранён в этой записи
                        if (!preg_match('/^' . preg_quote($colPrefix, '/') . '_(\d+|d([a-f0-9]{32}))_/', basename($photo), $m)) {
                            return "Недопустимое имя файла фотографии";
                        }
                        $isDraft = isset($m[2]) && ($m[2] !== "");
                        $alreadySaved = ($oper == "edit") && in_array($photo, $savedPhotos, true);
                        // Черновик — только этой же формы (ключ черновика = ключ отправки) и только свой
                        $formToken = (string)($post["form_submit"] ?? "");
                        $allowed = $alreadySaved || ($isDraft
                            ? (($oper == "add") && ($m[2] === $formToken) && upload_draft_owned($m[2]))
                            : (($oper == "edit") && ((int)$m[1] === (int)$id)));
                        if (!$allowed) {
                            return "Фотография не принадлежит этой записи";
                        }
                        if (!is_file($_SERVER["DOCUMENT_ROOT"] . $photo)) {
                            return "Файл фотографии не найден: " . basename($photo) . ". Загрузите его заново";
                        }
                    }
                    // Храним в каноническом виде
                    $post[$col->dbName] = json_encode(array_values(array_unique($photos)), JSON_UNESCAPED_SLASHES);
                } elseif ($col->type == "rich") {
                    $delta = json_decode(rawurldecode($value), true);
                    if (!is_array($delta) || !isset($delta["ops"]) || !is_array($delta["ops"])) {
                        return "Некорректный формат описания";
                    }
                } elseif (($col->type == "integer") || ($col->type == "float") || ($col->type == "select")) {
                    if (!is_numeric($value)) {
                        return "Поле «" . $col->rusName . "» должно быть числом";
                    }
                }
            }
            return "";
        }

        public function get_query_left_joins() {
            $leftJoin = "";
            for ($i = 0; $i < count($this->leftJoins); $i++) {
                $lj = $this->leftJoins[$i];
                $leftJoin .= " LEFT JOIN " . $lj . " ON " . $lj . ".ID_" . $lj . " = tbl.ID_" . $lj;
            }
            return $leftJoin;
        }

    }

    function user_auth($login, $pass) {
        // Троттлинг перебора: не более 5 неудачных попыток c одного IP за 15 минут
        $ip = client_ip();
        $ipq = db_quote($ip);
        $resCnt = db_query("SELECT COUNT(*) AS CNT FROM NL_LOGIN_ATTEMPT WHERE NL_LOGIN_ATTEMPT_IP = $ipq AND NL_LOGIN_ATTEMPT_TIME > (NOW() - INTERVAL 15 MINUTE)");
        if ($resCnt) {
            $rowCnt = db_fetch_assoc($resCnt);
            if ((int)$rowCnt["CNT"] >= 5) {
                http_response_code(429);
                die("Слишком много попыток входа. Повторите позже.");
            }
        }

        // Расшифровываем сохранённый хеш и сверяем через password_verify
        $query = "SELECT au.*, " . db_password_decrypt_sql("au.NL_USER_PASSWORD") . " AS NL_USER_PASSWORD_DEC FROM NL_USER au WHERE au.NL_USER_LOGIN = " . db_quote($login) . " LIMIT 1";
        $res = db_query($query) or die(db_error("user_auth"));
        $row = db_fetch_assoc($res);
        $legacy = false;
        $ok = $row && password_check($row["NL_USER_PASSWORD_DEC"], $pass, $legacy);
        if (!$row) {
            // Выравниваем время ответа для несуществующего логина
            password_verify((string)$pass, '$2y$12$D/8O8FISVQty9P7iMPCEiOsjUMN1Mk8iK0Ftc3HGrsj2EEote1MOK');
        }
        if ($ok) {
            // Запись в старом формате (AES от самого пароля) переводим в новый
            if ($legacy) {
                db_query("UPDATE NL_USER SET NL_USER_PASSWORD = " . db_password_sql($pass) . " WHERE ID_NL_USER = " . db_int($row["ID_NL_USER"]));
            }
            session_regenerate_id(true);
            db_query("DELETE FROM NL_LOGIN_ATTEMPT WHERE NL_LOGIN_ATTEMPT_IP = $ipq");
            $_SESSION["ID_NL_USER"] = $row["ID_NL_USER"];
            $_SESSION["NL_USER_LOGIN"] = $row["NL_USER_LOGIN"];
            $_SESSION["NL_USER_SHORT"] = $row["NL_USER_SHORT"];
            $_SESSION["NL_USER_FULL"] = $row["NL_USER_FULL"];
            $_SESSION["NL_USER_PHONE"] = $row["NL_USER_PHONE"];
            $_SESSION["ID_NL_USER_PERMISSION"] = $row["ID_NL_USER_PERMISSION"];
            $_SESSION["onlymy"] = "0";
            return true;
        }
        // Неудачная попытка — фиксируем для троттлинга
        db_query("INSERT INTO NL_LOGIN_ATTEMPT (NL_LOGIN_ATTEMPT_IP, NL_LOGIN_ATTEMPT_TIME) VALUES ($ipq, NOW())");
        return false;
    }

    function user_logout() {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $p = session_get_cookie_params();
            setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
        }
        session_destroy();
    }

    function includeAdminPartsByLvl($wrap_start = "", $wrap_end = "") {
        global $page;

        // Белый список подключаемых частей (защита от LFI через URL)
        $allowedParts = array("login", "main");
        $safePage = in_array($page, $allowedParts, true) ? $page : "main";
        $main_file = $_SERVER["DOCUMENT_ROOT"] . "/admin/parts/" . $safePage . ".php";
        if (file_exists($main_file)) {
            if ($wrap_start != "") {
                echo $wrap_start;
            }
            include $main_file;
            if ($wrap_end != "") {
                echo $wrap_end;
            }
        } else {
            include $_SERVER["DOCUMENT_ROOT"] . "/admin/parts/main.php";
        }
    }