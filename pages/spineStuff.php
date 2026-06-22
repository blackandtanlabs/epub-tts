<?php
/*
 * This file is part of EPUB TTS, created by Patrick Clark.
 *
 * EPUB TTS is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License, version 3 or (at your option) any
 * later version, as published by the Free Software Foundation. It comes with NO
 * WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
 *
 * Copyright (C) 2016-2026 Patrick Clark and family.
 *
 * Patrick built EPUB TTS over many years. The GPL licensing was applied by his
 * family when the project was made public, to keep his work free for everyone --
 * honoring his wishes. It was not part of the original source.
 */
    require_once('ebookRead.php');
    require_once('ebookData.php');

    if(!isset($_SESSION)){
        
    }
    $newname = $_SESSION['PFC'];
    $zipDir = $_SESSION['PFC2'];
    $ebook = new ebookRead($newname);
    $spineInfo = $ebook->getSpine();

    function buildToc($ebook){
        $found = false;
        $spineInfo = $ebook->getSpine();
        for($x = 0;$x < count($spineInfo);$x+=1){        
            $content = $ebook->getContentById($spineInfo[$x]);        
            if($found)
                break;            
            for($y = 0;$y < count($spineInfo);$y+=1){
                $manItem = $ebook->getManifestById($spineInfo[$y]);                
                if(preg_match("~".$manItem->href."~", $content)){
                    $found = true;
                    break;                
                }                    
            }        
        }
        if(!$found){
            echo "<h2>Table of Contents</h2><br />";
            $toc = $ebook->getTOC();
            for($x = 0;$x < count($spineInfo);$x+=1){
                if(isset($toc)){
                    $cToc = $toc[$x];
//                    $tag = substr($cToc->fileName, 0, strrpos($cToc->fileName, '.'));    
//                    echo "<a href=\"?".$tag."\" >".$cToc->name."</a>\n<br />\n";
                    echo "<a href=\"./upload/".$_SESSION['zipDir']."/".$cToc->fileName."\">".$cToc->name."</a>\n<br />\n";
                }else{
                    $manItem = $ebook->getManifestById($spineInfo[$x]);
                    $tag = substr($manItem->href, 0,strrpos($manItem->href, '.'));        
                    echo "<a href=\"#".$tag."\" >".$manItem->id."</a>\n<br />\n";
                }
            }
            echo "<br />";
        }
    }    
    
    function editToc($content, $ebook){
        $spineInfo = $ebook->getSpine();    
        for($x = 0;$x < count($spineInfo);$x+=1){
            $manItem = $ebook->getManifestById($spineInfo[$x]);
            $tag = substr($manItem->href, 0,strrpos($manItem->href, '.'));        
            $content = str_replace($manItem->href, "#".$tag, $content);
        }
        return $content;
    }
function XMLstring($data)
    {
    // return string if string, or string in XML if XML
    if (is_string($data))
        return($data);
    else
        {
        // assume it's SimpleXMLElement
        $data = (array)$data;
        //echo "<br />displayXMLstring <br />".var_dump($data);
        $info = "";
        if (is_array($data))
            {
            foreach ($data as $element)
                {
                if ($info == "")
                    $info = $element;
                else
                    $info = $info . ", " . $element;
                }
            }
        return($info);
        }
    }
?>
<HTML>
<HEAD>
    <TITLE>OPL's Open eBook Reader - <?php echo XMLstring($ebook->getDcTitle());?></TITLE>
    <meta name="author" content="Jacob Weigand">
</HEAD>
<BODY>
    <?php
        echo "<P ALIGN=LEFT STYLE=\"margin-bottom: 0in\">\n";
            echo buildToc($ebook);
            for($x = 0;$x < count($spineInfo);$x+=1){        
                $manItem = $ebook->getManifestById($spineInfo[$x]);                
                echo "<div id=\"section\">";
//                $a = substr($manItem->href, 0,strrpos($manItem->href, '.'));
                $a = $manItem->href;
                echo "<a href=".$zipDir."/".$a."/>" . $manItem->id;
//                $content = $ebook->getContentById($spineInfo[$x]);
//                echo editToc($content, $ebook);
                echo "</div>\n";
            }
        echo "</P><br />\n";
    ?>
    </BODY>
</HTML>