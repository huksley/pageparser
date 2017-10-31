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
?>