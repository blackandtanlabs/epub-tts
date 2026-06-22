<?php

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

$a=new PDO("sqlite:g:/callib/metadata.db");
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