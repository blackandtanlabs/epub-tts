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
require_once __DIR__ . '/config.php';

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)

function intdiv_1($a, $b)
    {
    return ($a - $a % $b) / $b;
    }
$s = $_GET["s"];   // numeric string, starting point
$e = $_GET["e"];   // numeric string, ending point
$a=calibre_db_or_notice();
$sql = "select * from tag_browser_authors order by sort";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
$n = 0;
if ($res)
    {
    $authors = $stmt->fetchAll();
    $n = count($authors);
    }
if ($e==-1)
    {
    $e = $n-1;
    }
else
    {
    $n = $e - $s + 1;
    }
header("Cache-Control: max-age=2592000"); //30days (60sec * 60min * 24hours * 30days)
$template = file_get_contents('./allAuthors.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint) . $n . " Authors<br>";

$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);

if ($n > 100)
    {
    $aGoodSize = intdiv_1 ($n, 10);
    if ($n > 0)
        {
        // now split the authors into groups of aGoodSize
        $group = 0;
        echo "<div style=\"text-align:center\">";
        for ($x=$s; $x<=$e; )
             {
             $group++;
             $name0 = $authors[$x]['sort'];
             $first = $x;
             $x = $x+$aGoodSize;
             if ($x>$e)
                $x = $e;
/*
             echo "<tr><td style=\"width:25%\"><a href=\"allAuthors.php?s=$first&e=$x\"><strong>Group $group</strong></a></td><td><strong>"
              . $authors[$first]['sort'] . "</strong> to";
             echo '<br /><strong>' . $authors[$x]['sort'] . "</strong></td></tr>\n";
*/
             echo "<a href=\"allAuthors.php?s=$first&e=$x\"><strong>" . $authors[$first]['sort']
              . "</strong><br>to<br><strong>" . $authors[$x]['sort'] . "</strong></a><hr>";
             $x++;
             }
        echo "</div>\n";
        }
    }
else if ($n > 0)
    {
    echo "<ul>\n";
    for ($x=$s; $x<=$e; $x++)
        {
        $author = $authors[$x]['id'];
        echo "  <li>\n";
        echo '    <a href="oneAuthor.php?author=' . $author . "\">"; 
        $sql = "select * from books_authors_link where author = $author";
        $stmt = $a->prepare($sql);
        $ret = $stmt->execute();
        $n2 = 0;
        if ($ret)
            {
            $record2 = $stmt->fetchAll();
            $n2 = count($record2);
            echo "<strong>" . $authors[$x]['sort'] . "</strong> (" . $n2 . " books)</h3></a>\n";
            }
        echo "  </li>\n";
        }
    echo "</ul>";
    }
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
