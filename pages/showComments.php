<?php
include_once('htmlClean.php');

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
$bookN = rawurldecode($_GET["book"]);   // numeric string
$name = rawurldecode($_GET["name"]);
$title = rawurldecode($_GET["title"]);
$a=new PDO("sqlite:g:/callib/metadata.db");
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
    $cover = "g:/callib/" . $record[0]['path'] . "/cover.jpg";
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
