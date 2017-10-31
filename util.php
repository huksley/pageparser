<?php
if (!class_exists("Memcached")) {
	// http://stackoverflow.com/questions/14243745/memcached-not-memcache-php-extension-on-windows
	class Memcached {
		const OPT_LIBKETAMA_COMPATIBLE = true;
		const OPT_COMPRESSION = true;
		const OPT_NO_BLOCK = true;

		var $_instance;

		public function __construct() {
			$this->_instance = new Memcache;
		}

		public function __call($name, $args) {
			return call_user_func_array(array($this->_instance, $name), $args);
		}

		public function setOption() {			
		}

		// https://craig.is/archiving/memcached-multiget-using-php-pecl-memcache-class/19
		public function getMulti(array $keys, $preserveOrder = true) {
		    $items = $this->get($keys);
		    if (!$preserveOrder) {
        		return $items;
    		}
		    $results = array_fill_keys($keys, null);
			return array_merge($results, $items);
		}
    }
}

/**
 * Cleans up and generates stdClass out of XML
 */
function xmljson($out) {
	$dom = new DOMDocument();
	if ($dom->loadXML($out) === FALSE) {
		throw new Exception("Failed to parse XML: " . $out);
	}
	json_prepare_xml($dom);
	$xml = $dom->saveXML();
	$out = simplexml_load_string($xml);
	$out = json_decode(json_encode($out));
	return $out;
}

/**
 * Returns milliseconds current time.
 */
function currenttimemillis() {
    $mt = explode(' ', microtime());
    return $mt[1] * 1000 + round($mt[0] * 1000);
}

/**
 * Parse DOM and move all attrs as elements, CDATA as simple text
 * so this XML is fully accessible after simplexml -> json_encode -> json_decode
 */
function json_prepare_xml($domNode) {
	$texts = array();
	$renames = array();
	foreach ($domNode->childNodes as $node) {
		if ($node->nodeType == XML_ELEMENT_NODE) {
			json_prepare_xml($node);
			if (strstr($node->nodeName, "-")) {
				$renames[] = $node;
			}
		} else
		if ($node->nodeType == XML_CDATA_SECTION_NODE) {
			$texts[] = $node;
		}
	}

	foreach ($texts as $node) {
		$domNode->removeChild($node);
		$domNode->appendChild($domNode->ownerDocument->createTextNode($node->nodeValue));
	}
	
	if ($domNode->attributes)
	while ($domNode->attributes->length) {
		$attr = $domNode->attributes->item(0);
		$domNode->appendChild($domNode->ownerDocument->createElement($attr->nodeName, $attr->nodeValue));
		$domNode->removeAttributeNode($attr);
	}

	foreach ($renames as $node) {
		// capitalize next letter
		$nn = explode("-", $node->nodeName);
		$s = "";
		foreach ($nn as $n) {
			if ($s != "") {
				$n = strtoupper(substr($n, 0, 1)) . substr($n, 1);
			}
			$s .= $n;
		}
		$nn = $node->ownerDocument->createElement($s);
		foreach ($node->childNodes as $subnode) {
			$nn->appendChild($subnode);
			//$node->removeChild($subnode);
		}
		$domNode->removeChild($node);
		$domNode->appendChild($nn);
	}
}

/**
 * Connect to database.
 */
function pdo() {
	$dbase = $_SERVER["DB_NAME"];
	$username = $_SERVER["DB_USER"];
	$password = $_SERVER["DB_PASS"];
	$pdo = new PDO('mysql:host=localhost;dbname=' . $dbase . ';charset=utf8', $username, $password, array(PDO::ATTR_PERSISTENT => true));
	$pdo->exec("set names utf8");
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	return $pdo;
}

if (!defined("JSON_UNESCAPED_SLASHES")) {
        define("JSON_UNESCAPED_SLASHES", 64);
}

if (!defined("JSON_PRETTY_PRINT")) {
        define("JSON_PRETTY_PRINT", 128);
}

if (!defined("JSON_UNESCAPED_UNICODE")) {
        define("JSON_UNESCAPED_UNICODE", 256);
}

if (!function_exists('getimagesizefromstring')) {
    function getimagesizefromstring($str) {
        $uri = 'data://application/octet-stream;base64,' . base64_encode($str);
        return getimagesize($uri);
    }
}

function table_dump($tt) {
    // echo count($tt) . "," . count($tt[0]);
    echo "<table border=1>";
    for ($j = 0; $j < count($tt); $j++) {
        if ($j == 0) {
            echo "<tr>";
            for ($i = 0; $i < count($tt[$j]); $i++) {
	        echo "<th>$i</th>";
            }
            echo "</tr>";
        }
        echo "<tr>";
        $r = $tt[$j];
        for ($i = 0; $i < count($r); $i++) {
            echo "<td>" . $r[$i] . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

$__xhr = false;
$__cli = php_sapi_name() == "cli";

if (!$__cli && isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === "xmlhttprequest") {
        $__xhr = true;
} else
if (!$__cli && $_SERVER["HTTP_ACCEPT"] == "application/json" ||
        $_SERVER["HTTP_ACCEPT"] == "application/xml" ||
        $_SERVER["HTTP_ACCEPT"] == "text/xml") {
        $__xhr = true;
}

if (!$__cli && !$__xhr) {
        header("Content-Type: text/html; charset=UTF-8");
}

function out_error_log($s) {
    global $__xhr, $__cli;
    // echo "xhr: $__xhr, cli: $__cli, accept: " . $_SERVER["HTTP_ACCEPT"] . "<br>";
    if (!$__xhr && !$__cli) {
        error_log($s);
        echo $s;
        // Force flush -> emit 512 bytes
        echo "<!-- ";
        for ($i = 0; $i < 512; $i++) echo rand(0, 256);
        echo " -->\n";
	echo "<br>\n";
        flush();
    } else {
        error_log(strftime("%Y-%m-%d %H:%M:%S") . " " . $s);
    }

    if (function_exists("out_mongo_log")) {
	out_mongo_log($s);
    }
}
?>