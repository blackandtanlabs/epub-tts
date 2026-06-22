<?php
include_once('showBook.php');
//
//header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
$author = $_GET["author"];   // numeric string
$a=new PDO("sqlite:g:/callib/metadata.db");
$template = file_get_contents('./oneAuthor.txt', true);

// First substitution: Author's name
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
$sql = "select * from authors where id = $author";
$stmt = $a->prepare($sql);
$ret = $stmt->execute();
if ($ret)
    {
    $record = $stmt->fetchAll();
    foreach ($record as $row)
        {
        $name = $row["name"];
        }
    }
else
    {
    $name = "unknown";
    }
echo substr($template, 0, $insertPoint) . $name;

// Same Sql used for next two substitutions
$sql = "select * from books_authors_link, books left outer join comments on comments.book = books.id
    left outer join books_ratings_link on books_ratings_link.book = books.id
    left outer join ratings on books_ratings_link.rating = ratings.id 
    left outer join books_series_link on books_series_link.book = books.id
    where books_authors_link.book = books.id and author = $author order by series desc, series_index asc, pubdate desc";
$stmt = $a->prepare($sql);
$ret = $stmt->execute();
if ($ret)
    {
    $books = $stmt->fetchAll(); 
    }

// Second substitution: number of books by author
// $n book(s) in order by series, if any, else title
$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
$continuePoint = $insertPoint + 5;
/*
$sql = "select * from books_authors_link where author = $author";
$stmt = $a->prepare($sql);
$ret = $stmt->execute();
*/
$n2 = 0;
if ($ret)
    {
    foreach ($books as $book)
        $n2 = $n2+1;
    }
if ($n2 == 1)
    {
    echo $n2 . " book";
    }
else
    {
    echo $n2 . " books";
    }
// Third substitution: book data
$insertPoint = strpos($template, "{{3}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
$continuePoint = $insertPoint + 5;
// we're at the insert point. Generate the book list
if ($ret)
    {
    $evenOdd=0;
    foreach ($books as $book)
        {
        $evenOdd++;
        $bookN = $book['1'];
        showBook(1, $bookN, $evenOdd, "", $name);
        }
    }
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
