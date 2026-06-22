<?php

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
$a=new PDO("sqlite:g:/callib/metadata.db");
/*
// sql for the books in the series
$sql = "select * from books_series_link, books "
. "left outer join comments on comments.book = books.id "
. "left outer join books_ratings_link on books_ratings_link.book = books.id "
. "left outer join ratings on books_ratings_link.rating = ratings.id "
. "where books_series_link.book = books.id and series = $series order by series_index";
*/
$sql = "select * from series order by sort";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
if (!$res)
    return;
$record = $stmt->fetchAll();

$template = file_get_contents('./series.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint);
$seriesN = 1;
foreach ($record as $row)
    {
    $series = $row['id'];
    $sql = "select * from books_series_link, books
     where books_series_link.book = books.id and series = $series order by series_index";
    $stmt = $a->prepare($sql);
    $res = $stmt->execute();
    if ($res)
        {
        $books = $stmt->fetchAll();
        $nBooks = count($books);
        }
    else
        $nBooks = 9999;
    if ($nBooks > 9)
        {
        echo '<li><h2><a href="oneSeries.php?series=' . $series
        . '&name=' . rawurlencode($row['name'])
        . '">' . $seriesN . '. ' . $row['sort'] . "</a> [$nBooks]</h2></li>";
        $seriesN = $seriesN + 1;
        }
    }
echo '</ul></div>';
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
