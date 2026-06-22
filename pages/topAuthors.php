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
$a=calibre_db_or_notice();
$sql = "select * from tag_browser_authors order by count desc";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
$nAuthorsShown = 20;
if ($res)
    {
    $authors = $stmt->fetchAll();

    $template = file_get_contents('./topAuthors.txt', true);
    $insertPoint = strpos($template, "{{1}}");
    $continuePoint = $insertPoint + 5;
    echo substr($template, 0, $insertPoint) . $nAuthorsShown . " Prolific Authors with " . $authors[$nAuthorsShown-1]['count'] . " or more books";

    $insertPoint = strpos($template, "{{2}}");
    echo substr($template, $continuePoint, $insertPoint-$continuePoint);
    echo "  <ul>\n";
    for ($x=0; $x<$nAuthorsShown; $x++)
        {
        $author = $authors[$x]['id'];
        echo "  <li>\n";
        echo '    <a href="oneAuthor.php?author=' . $author . "\">"; 
        echo "<strong>" . ($x+1) . '. ' . $authors[$x]['name'] . "</strong></a> (" . $authors[$x]['count'] . " books)<br />";
        echo "  </li>\n";
        }
    echo "  </ul>\n";
    /*
        {
        foreach ($authors as $row)
            {
            if ($row['count'] > 4)
                {
                {
                $author = $row['id'];
                echo "  <li>\n";
                echo '    <a href="oneAuthor.php?author=' . $row['id'] . "\">"; 
                $sql = "select * from books_authors_link where author = $author";
                $stmt = $a->prepare($sql);
                $ret = $stmt->execute();
                $n2 = 0;
                if ($ret)
                    {
                    $record2 = $stmt->fetchAll();
                    $n2 = count($record2);
                    echo "<strong>" . $row['sort'] . " (" . $n2 . " books)</strong></a>";
                    }
                echo "  </li>\n";
                }
            }
        }
    */
    $continuePoint = $insertPoint + 5;
    echo substr($template, $continuePoint);
    }
?>
