<?php
require_once __DIR__ . '/config.php';

include_once('showBook.php');
//header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
function intdiv_1($a, $b)
    {
    return ($a - $a % $b) / $b;
    }
function ratingValue($b)
    {
    $a=calibre_db_or_notice();
    $sql = "select * from books" 
        . " left outer join books_ratings_link on books_ratings_link.book = books.id"
        . " left outer join ratings on books_ratings_link.rating = ratings.id"
        . " where books.id=$b";
    $stmt = $a->prepare($sql);
    If ($stmt)
        {

        $res = $stmt->execute();
        if ($res)
            {
            $r = $stmt->fetchAll();
            return($r[0]['rating']);
            }
        else
            {
echo 'res:' . $res . '<br>';
            return(0);
            }
        }
    else
        {
echo 'stmt:' . $stmt . '<br>';
        return(0);
        }
    }
$a=calibre_db_or_notice();
$sql = "select * from books" 
    . " where 1=1 order by timestamp desc";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
if ($res)
    $books = $stmt->fetchAll();
else
    exit;
$template = file_get_contents('./recent.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint) . "100 Most Recent Books";

$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);

for ($x=0, $evenOdd=0; $x<count($books) && $x<100; $x++)
    {
    $evenOdd++;
    $name0 = $books[$x]['title'];
    $author = $books[$x]['author_sort'];
    $bookN = $books[$x]['id'];
    $rating = ratingValue($bookN);
    $cover = LIBRARY_ROOT . "/" . $books[$x]['path'] . "/cover.jpg";
    echo '<div class="clearfix" style="padding:3px;';
    $odd = $evenOdd % 2;
    if ($odd == 1)
        echo ' background-image: radial-gradient(yellow,green);">';
    else
        echo ' background-image: radial-gradient(green,yellow);">';
    if (file_exists($cover))
        {
        echo '<a href="showOneBook.php?&book=' . $bookN . "&title=Most Recent Books\">"
        . '<img src="fetch.php?img=' . $books[$x]['path'] .'&dim=0" width="90px" style="float:left;margin-right:3px;"></a>';
//        echo '<img src="fetch.php?img=' .$book[0]['path'] .'" width="25%" style="float:left;margin-right:3px;"/>';
        }
else echo "file not found: " . $cover . "<br>";
//    else
//        {
//        echo '<a href="readBook.php?book=' . rawurlencode($book[0]['path']) . '"><strong>GET BOOK</strong><br><br></a>';
//        }

    $num = $x+1 . '. ';
//  $rating is 0-10 half-stars. Convert to full stars plus possibly a half star and display
    $tit = '<h1><span style="color:  red">';
    for($i = 1; $i < $rating; $i+=2)
        $tit .= "<span>&#x2605</span>";		// star
    if (($rating == 1)
    || ($rating == 3)
    || ($rating == 5)
    || ($rating == 7)
    || ($rating == 9))
    	$tit .= "+";		// half-star
    $tit .= '</span>';
    if ($rating > 0)
        $tit .= '<br>';
    $tit .= '<a href="showOneBook.php?&book=' . $bookN . "&title=Most Recent Books\"><strong>$name0<strong></a></h1>";
//    $tit .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<em>by $author</em></dt>";
//$tit .= "</div></dt>";
    echo $tit;

    // show up to 5 tags
    $sql2 = "select * from books_tags_link where book = $bookN order by tag";
    $stmt2 = $a->prepare($sql2);
    If ($stmt2)
        {
        echo '<span style="font-size: 12px">by ' . $author . '<br>';
        $res2 = $stmt2->execute();
        if ($res2)
            {
	    $record2 = $stmt2->fetchAll();
	    if (count($record2)>0)
	        {
                for ($t=0; $t<5 && $t<count($record2); )
	            {
	            $tagName = genreTitle($record2[$t]['tag']);
	            echo '<a href="oneGenre.php?tag=' . $record2[$t]['tag']
	                 . '&name=' . rawurlencode($tagName)
	                 . '&g=0"'; 
	            echo '>' . $tagName . '</a>';
	            $t++;
	            if ($t < count($record2))
	                echo ", ";
	            }
	        }
            }
        echo '</span>';
        }
    echo "</div>";
    }
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>