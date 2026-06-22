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
include_once('showBook.php');
function intdiv_1($a, $b)
    {
    return ($a - $a % $b) / $b;
    }
$s = $_GET["s"];   // numeric string, starting point
$e = $_GET["e"];   // numeric string, ending point

$a=calibre_db_or_notice();
$sql = "select * from books order by sort";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
$n = 0;
if ($res)
    {
    $books = $stmt->fetchAll();
    $n = count($books);
    }
if ($e==-1)
    {
    $e = $n-1;
    }
else
    {
    $n = $e - $s + 1;
    }
$template = file_get_contents('./titles.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
$pageTitle = substr($template, 0, $insertPoint);
if ($n>1)
    $pageTitle .= "<strong>Books by Title</strong> ($n Titles)";
else
    $pageTitle .= "<strong>Books by Title</strong> (1 Title)";
echo substr($template, 0, $insertPoint) . $pageTitle;

$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);

if ($n > 100)
    {
    $aGoodSize = intdiv_1 ($n, 10);
    if ($n > 0)
        {
        // now split the titles into groups of aGoodSize
        $group = 0;
//        echo "<div style=\"text-align:center; font-size: 18px;\">";
        for ($x=$s; $x<=$e; )
             {
             $group++;
             $name0 = $books[$x]['sort'];
             $first = $x;
             $x = $x+$aGoodSize;
             if ($x>$e)
                $x = $e;
/*
             echo "<tr><td style=\"width:25%\"><a href=\"titles.php?s=$first&e=$x\"><strong>Group $group</strong></a></td><td><strong>"
              . $books[$first]['sort'] . "</strong><br>to";
             echo '<br /><strong>' . $books[$x]['sort'] . "</strong></td></tr>\n";
*/
             echo "<a href=\"titles.php?s=$first&e=$x\"><strong>" . $books[$first]['sort']
              . "</strong><br>.<br><strong>" . $books[$x]['sort'] . "</strong></a><hr>";
             $x++;
             }
//        echo "</div>\n";
        }
    }
elseif ($n > 1)
    {
    // we're as deep as we're going -- no more groups, just titles
    for ($x=$s; $x<=$e; )
         {
         $name0 = $books[$x]['sort'];
         $author = $books[$x]['author_sort'];
         echo "<p><a href=\"titles.php?s=$x&e=$x\"><strong>$name0<strong></a><br>by $author</p>";
         $x++;
         }
    }
elseif ($n == 1)
    {
    // show the book
    echo "<div style=\"text-align:left;\"></strong>";
    $evenOdd=0;
    $bookN = $books[$s]['id'];
    showBook(4, $bookN, $evenOdd, "", $books[$s]['author_sort']);
    echo "</div>\n";
    }
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>