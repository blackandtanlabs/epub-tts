<?php
require_once __DIR__ . '/config.php';

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
include_once('showBook.php');
$series = $_GET["series"];   // numeric string
$seriesName = rawurldecode($_GET["name"]);   // string

$a=calibre_db_or_notice();
$sql = "select * from books_series_link, books
   left outer join comments on comments.book = books.id
   left outer join books_ratings_link on books_ratings_link.book = books.id
   left outer join ratings on books_ratings_link.rating = ratings.id
 where books_series_link.book = books.id and series = $series order by series_index";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
if (!$res)
    return;
$books = $stmt->fetchAll();

$template = file_get_contents('./oneSeries.txt', true);

// First substitution: Series' name
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint) . $seriesName;

// Second substitution: the list of books
$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
$continuePoint = $insertPoint + 5;
$evenOdd=0;
foreach ($books as $book)
    {
    // for now, we'll just list em
    $bookN = $book['1'];
    $evenOdd++;
    showBook(3, $bookN, $evenOdd, "", $book['author_sort']);
    }                                    
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
