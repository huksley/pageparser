<?php
require("Html2Text.php");
require("ErrorTrap.class.php");
require("util.php");

if (!defined("ENT_XML1")) {
	define("ENT_XML1", 16);
}

/*
Adaptive, flexible page parser
*/
class PageParser {

	static $PROXIES = null;

	var $processId = "1";
	var $memcached;
	var $referer = "http://yandex.ru";
	var $proxies = array();
	var $proxyIndex = 0;
	var $cacheTimeout = 3600;
	var $userAgent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36";
	var $cookies = "coo_sv=1";
	var $connectTimeout = 10;
	var $readTimeout = 40; // seconds
	var $url = null;
	var $cache = true; // Should use cache at all?
	var $useCache = true; // If false, only save to cache, don`t use it
	var $badWords = array("gov.ru", "The requested URL could not be retrieved", "cgi-bin/getdenied", "This site was categorized in", 
		"This domain is blocked due to content filtering", "Web Page Blocked", "lightspeedsystems.com", "Unable to complete", 
		"Bad Gateway", "Connection Failed", "Server Connection Closed", "Zscaler", "Maximum number of open connections reached", 
		"Запрет доступа", "Internal Privoxy Error", "Invalid request", "Server dropped connection", 
		"by Polipo", "assets.gg", "Service Unavailable", "Connection refused", "ConnectSafe", "Gateway Time-out", "Gateway Timeout",
		"ERR_ZERO_SIZE_OBJECT", "Proxy Authentication Required", 
		"No server or forwarder data received", "Forwarding failure", "Squid Error",
		"Access to the requested resource has been blocked", 
		"has been denied", "Page Restricted",
		"DNS resolving failed", "was not found on this server", "Too many open files", "Redirection Error", "page has been blocked", "You have been restricted");

	function PageParser() {
		$this->memcached = new Memcached();
		$this->memcached->addServer("localhost", 11211);		
		$sid = $this->memcached->get("session_sid");
		if ($sid) {
			$this->cookies = "PHPSESSID=" . $sid;
		}
		
		// $this->proxyIndex = -3; // try 3 times without PROXY
		$this->proxyIndex = 0;
		$this->processId = microtime();
	}

	static function parseProxyUrl($purl) {
		$proxies = array();
		$p = new PageParser();
		$p->proxyIndex = -1; // Make sure going without proxies
		$p->parse($purl);
		$s = $p->getText();
		$s = strip_tags($s);
        $s = str_replace(":", "\n", $s);
        $s = str_replace(" ", "\n", $s);
        $s = str_replace("\t", "\n", $s);
        $s = explode("\n", trim($s));
        $ss = array();
        // trim empty lines
        for ($i = 0; $i < count($s); $i++) {
            $s[$i] = trim($s[$i]);
            if ($s[$i] != "") {
                $ss[] = $s[$i];
            }
        }   
        $s = $ss;
        for ($i = 0; $i < count($s); $i++) {
            $str = $s[$i];
            if (preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $str)) {
                $ip = $str;
                $port = intval($s[$i + 1]);
                if ($port > 0) {
	                $proxies[] = "tcp://$ip:$port";
	                out_error_log("Found proxy " . $proxies[count($proxies) - 1]);
	                $i ++;
	            }
            } else {
            	// out_error_log("Skipping " . $str);
            }
        }

        out_error_log("Found " . count($proxies) . " proxies");
        return $proxies;
	}
 
	static function findProxies() {
		$proxies = PageParser::$PROXIES;
		if (is_array($proxies)) {
			return $proxies;
		}

		$proxies = array();
		//$pp = PageParser::parseProxyUrl("http://www.freeproxy-list.ru/api/proxy?accessibility=95&anonymity=false&port=3128&token=c6d31db0997d429eaa0b09669b895373");
		//array_splice($proxies, 0, 0, $pp);
		// LATER PARSED IS GOING FIRST!
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl&pnum=1");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl&pnum=2");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl&pnum=3");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl&pnum=4");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=US&reliability=5000&ssl=ssl&pnum=5");
		array_splice($proxies, 0, 0, $pp);
		$pp = PageParser::parseProxyUrl("http://www.xroxy.com/proxylist.php?type=All_http&country=GB&reliability=5000&ssl=ssl");
		array_splice($proxies, 0, 0, $pp);
		out_error_log("Total " . count($proxies) . " proxies");

		// Adding good proxies on top
		$memcached = new Memcached();
		$memcached->addServer("localhost", 11211);	
		if ($memcached->get("proxy_good") !== false) {
			$good = $memcached->get("proxy_good");
			$good = explode("\n", $good);
			array_splice($proxies, 0, 0, $good);
			out_error_log("Parsed KNOWN GOOD proxies: " . join(", ", $good));
		}

        PageParser::$PROXIES = &$proxies;
		return $proxies;
	}

	function dom_to_array($root, &$stat, &$ref, $level = 0) { 
		if ($stat["maxlevel"] < $level) {
	    	$stat["maxlevel"] = $level;
	    }
	    if (!$ref["level" . $level]) {
	    	$ref["level" . $level] = array();
	    }
	    
	    $result = array(); 
	    if ($root->hasAttributes()) { 
	        $attrs = $root->attributes; 

	        foreach ($attrs as $i => $attr) {
	            $result[$attr->name] = $attr->value; 
	            $stat["attrs"]++;
	        }
	    } 

	    $children = $root->childNodes;
	    if ($children && $children->length == 1) { 
	        $child = $children->item(0); 
	        $stat["nodes"]++;
	        if ($child->nodeType == XML_TEXT_NODE) 
	        { 
	            $result['_value'] = $child->nodeValue; 

	            if (count($result) == 1) {
	            	$stat["#textbytes"] += count($result['_value']);
	                return $result['_value'];
	            } else {
	                return $result; 
	            }
	        } 
	    } 

	    $group = array(); 
	    if ($children)
	    for($i = 0; $i < $children->length; $i++) { 
	        $child = $children->item($i); 
	        $stat["nodes"]++;

	        if (!isset($result[$child->nodeName])) {
	            $result[$child->nodeName] = $this->dom_to_array($child, $stat, $ref, $level + 1); 
	            $stat[$child->nodeName]++;
	            if (!$ref[$child->nodeName]) {
	            	$ref[$child->nodeName] = array();
	            } 
	            $ref[$child->nodeName][] = $result[$child->nodeName];
	            if ($child->nodeName != "#text") {
	            	$ref["level" . $level][] = array($child->nodeName, $child);
	            }
	        } else { 
	            if (!isset($group[$child->nodeName])) 
	            { 
	                $tmp = $result[$child->nodeName]; 
	                $result[$child->nodeName] = array($tmp); 
	                $group[$child->nodeName] = 1; 
	            } 

	            $result[$child->nodeName][] = $this->dom_to_array($child, $stat, $ref, $level + 1);
	            $stat[$child->nodeName]++;
	            if ($child->nodeName != "#text") {
	            	$ref["level" . $level][] = array($child->nodeName, $child);
	            }
	        } 
	    } 

	    $result["_src"	] = &$root;
	    return $result; 
	} 

	function push(&$arr, $elem) {
		if (!$arr) {
			$arr = array();
		}
		$arr[] = $elem;
		return $arr;
	}

	var $responseHeaders = array();

	function responseHeader($ch, $header) { 
		$s = trim($header);
		// out_error_log("Got response header: $s");
	    if (preg_match("/([a-zA-Z-]*): (.*)/",$s,$matches)) { 
	        $this->responseHeaders[$matches[1]] = $matches[2];
	    }
	    return strlen($header); 
	} 

	// Fetch without or with proxies
	function fetchUrl($url, $method = "GET", $body = "", &$headers = array()) {
		$proxies = &$this->proxies;
		$pindex = $this->proxyIndex;
		
		while ($pindex < count($proxies)) {
			// First try without proxy, then try with it
			$proxy = $pindex < 0 ? "" : $proxies[$pindex];
			// Only use proxy if it not hot (and not ours last)
			while ($pindex >= 0 && 
					$pindex < count($proxies) && 
					$this->memcached->get("proxy.hot." . $proxies[$pindex]) !== FALSE &&
					$this->memcached->get("proxy.hot." . $proxies[$pindex]) != $this->processId
					) {
				out_error_log("Proxy " . $proxies[$pindex] . " is hot, skipping");
				$pindex ++;
			}
			if ($pindex >= count($proxies)) {
				break;
			}
			
			$ch = curl_init();
			$rh = array(
    			"Accept-language: ru",
				"Cookie: " . $this->cookies,
				"User-Agent: " . $this->userAgent,
				"Referer: " . $this->referer,
				"Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
		    );
		    if ($body != "") {
		    	$rh[] = "Content-Type: application/x-www-form-urlencoded";
		    	$rh[] = "Content-Length: " + strlen($body);
		    }
			

			if ($proxy != "") {
		    	curl_setopt($ch, CURLOPT_PROXY, $proxy);
		    	// curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
		    }
		    if ($method == "POST" && $body != "") {
		    	curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		    }
		    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		    curl_setopt($ch, CURLOPT_HTTPHEADER, $rh);
		    // curl_setopt($ch, CURLOPT_VERBOSE, 1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			// curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		    curl_setopt($ch, CURLOPT_URL, $url);
		    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);// return page 1:yes
		    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
		    curl_setopt($ch, CURLOPT_TIMEOUT, $this->readTimeout); // http request timeout 0.1 * X seconds
		    // curl_setopt($ch, CURLOPT_HEADER, 0); // return headers 0 no 1 yes
		    curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, 'responseHeader')); 
		    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		    curl_setopt($ch, CURLOPT_COOKIESESSION, 1);
		    curl_setopt($ch, CURLOPT_FAILONERROR, false);
		    curl_setopt($ch, CURLOPT_HTTP200ALIASES, array(400, 401, 403, 500, 301, 302));
		    $this->effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		    $this->responseHeaders = array();
		    out_error_log("Http $method $url" . 
		    	($proxy ? ", proxy $proxy ($pindex from " . count($proxies) . ")" : "") .
		    	($this->cookies ? ", cookie " . $this->cookies : "") .
		    	($body ? " body " . $body : ""));
		    $data = curl_exec($ch);
		    if (curl_errno($ch)) {
		    	out_error_log("Http error $method $url, CURL errno: " . curl_errno($ch) . " " . curl_error($ch));
		    }
		    $this->responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		    if ($this->responseCode == 403) {
		    	out_error_log("Http forbidden $method $url");
		    	$data = null;
		    } else 
		    if ($this->responseCode > 399 || $this->responseCode == 0) {
		    	out_error_log("Http error $method $url CODE " . $this->responseCode . " BODY [" . strip_tags($data) . "]	");
		    	$data = null;
		    }

		    $redir = false;
		    if ($this->responseCode == 301 || $this->responseCode == 302) {
		    	$loc = $this->responseHeaders["Location"];
		    	out_error_log("Redirect to $loc, trying...");
		    	$data = null;
		    	$url = $loc;
		    	$redir = true;
		    }

		    $bad = $this->badWords;
		    for ($i = 0; $i < count($bad); $i++) {
				if (strstr($data, $bad[$i])) {
					out_error_log("Response contains blocked " . $bad[$i] . ", trying again...");
					$data = null;
					break;
				}
				if (strstr($this->effectiveUrl, $bad[$i])) {
					out_error_log("Effective URL contains blocked " . $bad[$i] . ", trying again...");
					$data = null;
					break;
				}
		    }

		    curl_close($ch);
		    if ($data != null) {
		    	$s = base64_encode($data);
		    	out_error_log("Http $method " . $this->responseCode . " $url (" . $this->effectiveUrl . ") OK, got " . strlen($s) . " bytes of data");
		    	foreach ($this->responseHeaders as $key => $value) {
		    		$headers[$key] = $value;
		    	}
		    	break;
		    } else {
		    	out_error_log("Http error $method $url NO DATA, response code: " . $this->responseCode);
		    }

		    if (!$redir) {
			    // Start from first proxy and not from last saved proxy index. Or else code runs out of proxies!
			    if ($pindex == $this->proxyIndex && $pindex > 0) {
			    	$this->proxyIndex = -1;
			    	$pindex = -1;
			    } else {
					$pindex ++;
				}
			}
		}

		if ($data && $pindex > 0) {
			// Move valid proxy to the top
			$this->proxyIndex = $pindex;
			$good = array();
			if ($this->memcached->get("proxy_good") !== false) {
				$good = $this->memcached->get("proxy_good");
				$good = explode("\n", $good);
			}

			$goodFound = array_search($proxies[$pindex], $good);
			if ($goodFound !== FALSE) {
				array_splice($good, $goodFound, 1);
			}

			// Mark proxy as hot (used) for 5...15 seconds (random)
			$this->memcached->set("proxy.hot." . $proxies[$pindex], $this->processId, 5 + rand(0, 10));
			array_splice($good, 0, 0, array($proxies[$pindex]));
			out_error_log("Saving KNOWN GOOD proxies: " . join("\n", $good));
			$this->memcached->set("proxy_good", join("\n", $good), 600);
			out_error_log("Priority proxy usage: " . $pindex . ", " . $proxies[$pindex]);
			PageParser::findProxies();
		} else
		if (!$data && $pindex > 0) {
			out_error_log("Run out of proxies for $url!!! Request failed!");
		}

		return $data;
	}

	function loadUrl($url, $method = "GET", $body = "", &$headers = array(), $textCharset = null) {
		if (!$this->cache) {
			$data = $this->fetchUrl($url, $method, $body, $headers);
			if (!$data) {
				return null;
			}
			return $data;
		}

		$key = "xurl." . base64_encode($url);
		$data = $this->memcached->get($key);
		if (!$data || !$this->useCache) {
			if ($this->useCache) {
				out_error_log("No cache for " . $key . " fetching from " . $url);
			}
			$data = $this->fetchUrl($url, $method, $body, $headers);
			if (!$data) {
				return null;
			}

			if ($textCharset) { // Convert and store in UTF-8
				$trans = get_html_translation_table(HTML_SPECIALCHARS); // DON`T CONVERT CYRILLIC - HTML_ENTITIES
    			$trans["Ђ"]="&euro;";
    			$trans = array_flip($trans);
				$data = mb_convert_encoding($data, "UTF-8", $textCharset); // (data, TARGET, SOURCE)
				// onvert all &ent; -> char
				$data = strtr($data, $trans);
				$data = str_replace($textCharset, "UTF-8", $data);
				$data = str_replace(strtolower($textCharset), "UTF-8", $data);
			}

			$this->memcached->set($key, base64_encode($data), $this->cacheTimeout);
			return $data;
		} else {
			out_error_log("Found in cache: $url, data: " . strlen($data) . " bytes");
			$s = base64_decode($data);
			return $s;
		}
	}

	/** Parse page, produce DOM and object trees, level array */
	function parse($url, $charset = "UTF-8") {
		$this->url = $url;
		$headers = array();
		$html = $this->loadUrl($url, "GET", "", $headers, $charset);
		if (!$html) {
			// Init empty
			$this->document = new DOMDocument();
			$this->stat = array();
			$this->ref = array();
			$this->object = array();
			return false;
		}

		out_error_log("Parsing html from $url, " . strlen(base64_encode($html)) . " bytes");
		
		$doc = new DOMDocument();
		$caller = new ErrorTrap(array($doc, 'loadHTML'));
		$caller->call($html);
		$stat = array();
		$ref = array();
		$o = $this->dom_to_array($doc, $stat, $ref);
		$this->html = $html;
		$this->document = $doc;
		$this->object = $o;
		$this->ref = $ref;
		$this->stat = $stat;
		return true;
	}

	function dumpLevels() {
		$stat = $this->stat;
		$ref = $this->ref;
		$m = $stat["maxlevel"];
		for ($i = 0; $i < $m; $i++) {
			$c = count($ref["level" . $i]);	
			echo "Level $i count $c<br>";
		}

		for ($ll = $m; $ll--; $ll > 0) {
			$l = $ref["level" . $ll];
			$elcounts = array();
			for ($i = 0; $i < count($l); $i++) {
				$elcounts[$l[$i][0]]++;
			}
			echo "Level " . $ll . ": ";
			print_r($elcounts);
			echo "<br>";
		}
	}

	/**
	 * Finds link at the level, produces list of PageParser
	 */
	function findByLink($str, $up = 0) {
		$m = $this->stat["maxlevel"];
		// traverse elements at each level, by type, and look inside for link, start from most deep
		$target = -1;
		$links = array();
		for ($ll = $m; $ll--; $ll > 0) {
			$l = $this->ref["level" . $ll];

			for ($i = 0; $i < count($l); $i++) {
				$tag = $l[$i][0];
				$dom = $l[$i][1];
				if ($tag == "a" && strstr($dom->getAttribute("href"), $str)) {
					$c = $up;
					while ($c > 0 && $dom->parentNode) {
						$dom = $dom->parentNode;
						$c --;
					}
					$links[] = $dom;
				}
			}
		}

		if (count($links) > 0) {
			$pp = array();
			foreach ($links as $link) {
				$ppp = new PageParser();
				$ppp->deriveDom($this, $link);
				$pp[] = $ppp;
			}
			return $pp;
		}
	}

	/**
	 * Derive page from specified page and DOM list of nodes.
	 */
	function deriveDom($p, $node) {
		$this->url = $p->url;
		$this->sourcePage = $p;
		$doc = new DOMDocument();
		$doc->appendChild($doc->importNode($node, TRUE));
		$stat = array();
		$ref = array();
		$o = $this->dom_to_array($doc, $stat, $ref);
		$this->document = $doc;
		$this->object = $o;
		$this->ref = $ref;
		$this->stat = $stat;
		$this->html = $this->serialize($node);
	}

	function getUrl($url) {
		if (strstr($url, "http://")) {
			return $url;
		} else
		if (strstr($url, "https://")) {
			return $url;
		} else
		if (strstr($url, "//")) { // same protocol
			$proto = substr($this->url, 0, strpos($this->url, "://") + 1);
			return $proto . $url;
		} else 
		if (strstr($url, "/") == $url) { // same proto + host + port
			$proto = substr($this->url, 0, strpos($this->url, "://") + 3);
			$s = substr($this->url, strlen($proto));
			$s = substr($s, 0, strpos($s, "/"));
			return $s . $url;
		} else { // same proto + host + port + path
			$s = substr($this->url, 0, strrpos($this->url, "/"));
			return $s . "/" . $url;
		}
	}

	/** Find all links in DOM and returns these links as array of hrefs */
	function getLinks($match = "") {
		$l = $this->document->getElementsByTagName("a");
		$ll = array();
		foreach ($l as $link) {
			$href = $link->getAttribute("href");
			if ($match == "" || ($href && strstr($href, $match))) {
				$ll[] = $href;
			}
		}
		return $ll;
	}

	/** Returns all tables array of HTML */
	function getElements($name, $up = 0) {
		$ll = array();
		$l = $this->document->getElementsByTagName($name);
		foreach ($l as $el) {
			$c = $up;
			while ($c > 0 && $el->parentNode) {
				$el = $el->parentNode;
				$c --;
			}
			$ll[] = $this->serialize($el);
		}
		return $ll;
	}

	/** Return all src of images href matches by */
	function getImages($match = "", $up = 0) {
		$l = $this->document->getElementsByTagName("img");
		$ll = array();
		foreach ($l as $link) {
			$src = $link->getAttribute("src");
			if ($match == "" || ($src && strstr($src, $match))) {
				if ($up > 0) {
					$c = $up;
					$el = $link;
					while ($c > 0 && $el->parentNode) {
						$el = $el->parentNode;
						$c --;
					}
					$pp = new PageParser();
					$pp->deriveDom($this, $el);
					$ll[] = $pp;
				} else {
					$pp = new PageParser();
					$pp->deriveDom($this, $link);
					$ll[] = $pp;
				}
			}
		}
		return $ll;
	}

	/**
	 * Render page as text
	 */
	function getText() {
		$html = $this->document->saveHTML();
		$h2t = new Html2Text\Html2Text();
		$h2t->set_html($html);
		return $h2t->get_text();
	}

	/**
	 * Serialize DOM element to XML
	 */
	function serialize($el) {
		$doc = new DOMDocument();
		if (get_class($el) == "DOMDocument") {
			return "";
		} else {
			$res = $doc->importNode($el, TRUE);
			if ($res === FALSE) {
				return "";
			}
	  		$doc->appendChild($res);
		}
		$html = $doc->saveXML();
		return html_entity_decode($html, ENT_XML1, 'UTF-8');
	}

	/**
	 * Return all TD with content matching the string (optionally not TD but $up level higher in DOM)
	 */
	function getCells($match = "", $up = 0) {
		$ll = array();
		$l = $this->document->getElementsByTagName("td");
		foreach ($l as $el) {
			$val = $this->serialize($el);	
			if ($match == "" || ($val && strstr($val, $match))) {
				$c = $up;
				while ($c > 0 && $el->parentNode) {
					$el = $el->parentNode;
					$c --;
				}
				$ll[] = $this->serialize($el);
			}
		}
		return $ll;
	}

	/**
	 * Looks for cell which matches specified string, goes $up up and returns everthing except string.
	 * Usefull for <td>name</td><td>value</td> structures.
	 */
	function getCellData($name, $up = 0) {
		$l = $this->getCells($name, $up);
		return trim(strip_tags(str_replace($name, "", join("", $l))));
	}
}
?>