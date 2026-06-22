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
include_once('htmlClean.php');

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
$bookN = rawurldecode($_GET["book"]);   // numeric string
$name = rawurldecode($_GET["name"]);
$title = rawurldecode($_GET["title"]);
$a=calibre_db_or_notice();
$template = file_get_contents('./showComments.txt', true);

// First substitution: The whole page of the comments
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
    $sql = "select * from books" 
            . " left outer join comments on comments.book = books.id"
            . " left outer join books_authors_link on books_authors_link.book = books.id"
            . " left outer join authors on books_authors_link.author = authors.id"
            . " left outer join books_ratings_link on books_ratings_link.book = books.id"
            . " left outer join ratings on books_ratings_link.rating = ratings.id"
            . " left outer join books_series_link on books_series_link.book = books.id"
            . " where books.id = $bookN";
$stmt = $a->prepare($sql);
$ret = $stmt->execute();
if ($ret)
    {
    $record = $stmt->fetchAll();
    $cover = LIBRARY_ROOT . "/" . $record[0]['path'] . "/cover.jpg";
    if (file_exists($cover))
        {
        echo "<h1>$title</h1>";
        echo '<img src="fetch.php?img=' .$record[0]['path'] .'&dim=0" width="33%" style="float:left;margin-right:3px;">';
        }
    else
        echo '<h1>' . $title . '</h1>';
    echo "<h2>$name</h2>";
    $comment = $record[0]['text'];
    $comment = cleanSomeHTML ($comment);
    echo substr($template, 0, $insertPoint) . $comment;
    outputMissingEntities($comment);
    }
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
