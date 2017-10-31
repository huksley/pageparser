<?php
require("PageParser.php");
require("table2arr.php");

$p = new PageParser();
$p->cookies = "none=1";
$ok = $p->parse("http://yandex.ru");
if ($ok) {
    $tt = new table2arr($p->html, "UTF-8", "UTF-8", false); // Don`t strip tags
    table_dump($tt);
} else {
    error_log("Parse failed");
}
?>