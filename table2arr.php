<?php
require_once("ErrorTrap.class.php");

class table2arr
{
var $cells;
var $table;
var $trans;
var $tablecount;
var $colspan;

function findfirst($table,$level)
// correct bug for multi extract problem, thanks Daniel Sepeur
        {
        foreach ($table as $key=>$arr)
          {
          $flevel=$arr["level"];
          $flen=$arr["len"];
          if (($flevel==$level) AND
             ($flen==0)) return $key;
          }
        return -1;
        }


function table2arr($html, $encoding = "UTF-8", $sourceEncoding = "Windows-1251", $stripTags = true) {
    $this->trans = get_html_translation_table(HTML_SPECIALCHARS); // DON`T CONVERT CYRILLIC - HTML_ENTITIES
    $this->trans["€"]="&euro;";
    $this->trans = array_flip($this->trans);
    $this->tablecount=0;
    $this->table = array();
    $this->colspan = false;
    $this->encoding = $encoding;
    $this->sourceEncoding = $sourceEncoding;
    $this->stripTags = $stripTags;
    $this->parsetable($html, $encoding, $sourceEncoding);
}

// Operate in 1byte encoding, return in $encoding everything
function parsetable($html, $encoding, $sourceEncoding)
{
    // Resort to using this, error in Ubuntu 12.04.5 LTS, PHP 5.3.10-1ubuntu3.15
    // when converting Russian Rouble symbol [e2 82 bd 0a] -> illegal character found... EVEN with //IGNORE!!!
    // LATEST FIND: aborts parsing! returns part of HTML!!!
    if ($sourceEncoding != $encoding) {
        $html = mb_convert_encoding($html, $encoding, $sourceEncoding);
    }
    $shtml=strtolower($html);
    $level=0;
    $idx=0;
    $tabpos = array();
    $posbegin = strpos($shtml, '<table', 0);
    while ($posbegin !== FALSE) {
        $tabpos[$posbegin] = "b";
        $posbegin = strpos($shtml, '<table', $posbegin+1);
    }  
    $posend = strpos($shtml, '</table>', 0);    
    while ($posend !== FALSE) {
        $tabpos[$posend+8]="e";
        $posend = strpos($shtml, '</table>', $posend+1);
    }
    ksort($tabpos);
    $idx=0;

    foreach ($tabpos as $posbegin=>$beginend) {
        if ($beginend=="b") {
            $level++;
            $this->table[$idx]=array("parent"=>false,"level"=>$level,"begin"=>$posbegin,"len"=>0,"content"=>"");
            $idx++;
        }

        if ($beginend=="e") {
            $findidx=$this->findfirst($this->table,$level);
            // correct bug for multi extract problem, thanks Daniel Sepeur
            if ($findidx>=0) {
                $tmpbeg=$this->table[$findidx]["begin"];
                $len=$posbegin-$tmpbeg;
                $this->table[$findidx]["len"]=$len;
                $this->table[$findidx]["content"]=substr($html, $tmpbeg, $len) ;
                $level--;
            }
        }
    }
    $this->tablecount=$idx;

    foreach ($this->table as $k=>$tab) {
      if ($k>0) {
        $level=$tab["level"];
        if ($level>$lastlevel) $this->table[$k-1]["parent"]=true;
      }
      $lastlevel=$tab["level"];
    }
}

function getTable($tabidx) {
  $this->cells = array();
  $this->getcells($tabidx);
  return $this->cells;
}

function getcells($tabidx) {
    $curtable=$this->table[$tabidx]["content"];
    $stable=strtolower($curtable);
    $this->cells=array(); // initialise array, add this line for repaired bug finded by Braukmann, Juergen thanks

    $rowbegin=strpos($stable, '<tr', 0);
    $rowend=strpos($stable, '</tr>', $rowbegin + 1);
    $rowidx=0;

    while ($rowbegin !== FALSE) {
        $row = substr($curtable, $rowbegin, $rowend - $rowbegin);
        $srow = strtolower($row);

        $colbegin = 0;
        $colend = 0;
        $colidx = 0;
        $oldcolbegin = $colbegin;
        $colbegin = strpos($srow, '<td', $oldcolbegin);
        if ($colbegin !== FALSE) {
            $colend = strpos($srow, '</td>', $colbegin);
        } else {
            $colbegin = strpos($srow, '<th', $oldcolbegin);
            if ($colbegin !== FALSE) {
                $colend = strpos($srow, '</th>', $colbegin);    
            }
        }
      
        while ($colbegin !== FALSE) {
            $col = substr($row, $colbegin, $colend - $colbegin + strlen('</td>') + 2);

            // colspan detection
            $span = 1;
            if ($this->colspan) {
                if (preg_match("|colspan\s?=[\s\'\"]+?(\d+)[\s\'\"]+?|s",$col,$match)) {
                    $span = $match[1];
                }
            }
            if ($span <= 0) {
                $span=1;
            }

            if ($this->stripTags) {
                $col = strip_tags($col);
            }
            $col = trim($col);
            $col = strtr($col, $this->trans);
            if ($this->encoding != $this->sourceEncoding) {
                $col = mb_convert_encoding($col, $this->encoding, $this->sourceEncoding);
            }
            $this->cells[$rowidx][$colidx] = $col;
            $oldcolbegin = $colbegin + 1;
            $colbegin = strpos($srow, '<td', $oldcolbegin);
            if ($colbegin !== FALSE) {
                $colend = strpos($srow, '</td>', $colbegin);
            } else {
                $colbegin = strpos($srow, '<th', $oldcolbegin);
                if ($colbegin !== FALSE) {
                    $colend = strpos($srow, '</th>', $colbegin);    
                }
            }
            $colidx += $span;

            if ($colidx > 100) {
                die("Infloop");
            }
        }
        
        $rowbegin = strpos($stable, '<tr', $rowbegin+1);
        $rowend = strpos($stable, '</tr>', $rowbegin+1);
        $rowidx++;

        if ($rowidx > 66000) {
          die("Infloop");
        }
    }
}


} // class table2arr
?>