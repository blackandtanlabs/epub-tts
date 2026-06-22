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

header("Cache-Control: no-store"); //1 hour (60sec * 60min)
$bookN = $_GET["book"];   // numeric string, starting point
$pageTitle = $_GET["title"];
include_once('showBook.php');

$template = file_get_contents('./titles.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint) . "<strong>$pageTitle</strong>";

$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);

$a=calibre_db_or_notice();
$sql = "select * from books where id = $bookN";
$stmt = $a->prepare($sql);
If ($stmt)
  {
$res = $stmt->execute();
if ($res)
    {
    $books = $stmt->fetchAll();
    // show the book
    echo "<div style=\"text-align:left\";>";
    $evenOdd=0;
    showBook(4, $bookN, $evenOdd, "", $books[0]['author_sort']);
    $continuePoint = $insertPoint + 5;
    echo "</div>" . substr($template, $continuePoint);
    }
}
?>