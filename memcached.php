<?php
header("Content-Type: text/html; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 'on');
$m = new Memcached();
$m->addServer("localhost", 11211);
$sel = @$_REQUEST["key"];
$del = @$_REQUEST["remove"];
if ($del && $del != "@all") {
    $m->delete($del);
}
$k = $m->getAllKeys();
if ($del == "@all") {
    for ($i = 0; $i < count($k); $i++) {
        $kk = $k[$i];
        $m->delete($kk);
    }
    $k = array();
}
$content = @$_REQUEST["content"];
if ($content) {
    $value = $m->get($content);
    $ctype = "text/html; charset=UTF-8";
    if (strstr($content, "xurl.")) {
        $value = base64_decode($value);
        $nn = substr($content, 5);
        $nn = base64_decode($nn);
        if (strstr($nn, ".jpg")) {
            $ctype = "image/jpeg";
        } else {
            echo "<base href='$nn'>";
        }
    }
    header("Content-Type: $ctype");
    echo $value;
    return;
}
echo "<a href='?remove=@all'>remove all</a><p><table><tr><th>##</th><th>key</th><th>url</th><th>size</th></tr>";
for ($i = 0; $i < count($k); $i++) {
    $kk = $k[$i];
    echo "<tr>";
    echo "<td><a href='?key=$kk'>show</a> <a href='?remove=$kk'>remove</a></td>";

    if (strstr($kk, "xurl.")) {
	   $nn = substr($kk, 5);
	   $nn = base64_decode($nn);
	   echo "<td></td>";
       echo "<td>";
	   echo $nn;
	   echo "</td>";
    } else {
	   echo "<td>$kk</td>";
       echo "<td></td>";
	}

	$value = $m->get($kk);
	if (strstr($kk, "xurl.")) {
	    $value = base64_decode($value);
	}

    echo "<td>" . strlen($value) . "</td>";

    echo "</tr>";

    if ($sel == $kk) {
	   echo "<tr>";
	   echo "<td colspan='4'>";
	   echo "<iframe id='FileFrame' width=950 height=450 scrolling=yes src='?content=$sel'>";
	   echo "</iframe>";
	   echo "</td>";
	   echo "</tr>";
    }

}
echo "</table>";
?>