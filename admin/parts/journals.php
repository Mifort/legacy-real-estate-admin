<?php
    include $_SERVER["DOCUMENT_ROOT"] . "/php/config.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/php/functions.php";
    include $_SERVER["DOCUMENT_ROOT"] . "/admin/php/functions.admin.php";
    require_auth();
?>
<div class="objects g-tabs-full">
    <ul>
        <li><a href="/admin/php/jqgrid.table.php?cache=20&tblName=NL_PROP_RESALE">Вторичка</a></li>
    </ul>
</div>
<div class="g-tabs-hide">←</div>
<script>
    detailTabs(".objects");
</script>