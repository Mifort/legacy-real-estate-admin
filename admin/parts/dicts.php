<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";
    require_admin();
?>
<div class="dicts g-tabs-full">
    <ul>
        <li><a href="/admin/php/jqgrid.table.php?cache=20&tblName=NL_VIEW">Вид из окна</a></li>
        <li><a href="/admin/php/jqgrid.table.php?cache=20&tblName=NL_MATERIAL">Материал дома</a></li>
        <li><a href="/admin/php/jqgrid.table.php?cache=20&tblName=NL_HOUSES">Тип дома</a></li>
        <li><a href="/admin/php/jqgrid.table.php?cache=20&tblName=NL_USER">Пользователи</a></li>
    </ul>
</div>
<div class="g-tabs-hide">←</div>
<script>
    detailTabs(".dicts");
</script>