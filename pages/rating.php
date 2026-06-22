<?php
require_once __DIR__ . '/config.php';
include_once('showBook.php');

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
function intdiv_1($a, $b)
	{
    return ($a - $a % $b) / $b;
    }
$r = $_GET["r"];
if ($r==-1)
    {
    $template = file_get_contents('./titles.txt', true);
    $insertPoint = strpos($template, "{{1}}");
    $continuePoint = $insertPoint + 5;
    echo substr($template, 0, $insertPoint) . "<strong>Books by Rating</strong>";

    $insertPoint = strpos($template, "{{2}}");
    echo substr($template, $continuePoint, $insertPoint-$continuePoint);

    echo "<div style=\"text-align:center\">";

//    Not every possible rating is present in the
//	Calibre database, so we only show the ones present
    $a=calibre_db_or_notice();
    $sql = "select * from ratings";
    $stmt = $a->prepare($sql);
    $res = $stmt->execute();
    if ($res)
        {
        $rating = $stmt->fetchall();
        for ($x=0; $x<count($rating); $x++)
			 {
		     $rate = $rating[$x]['id'];
echo 'value[' . $x . ']:' . $rate . '<br>';
            echo "<p><a href=\"rating.php?r=$rate\"><strong>";
            for($i = 1; $i < $rate; $i+=2)
                echo "<span>&#x2605</span>";		// star
            if (($rate == 1)
            || ($rate == 3)
            || ($rate == 5)
            || ($rate == 7)
            || ($rate == 9))
               	echo "+";		// half-star
            echo "</strong></a><strong></p>";
			 }
	   }
    echo "</div>";
    $continuePoint = $insertPoint + 5;
    echo substr($template, $continuePoint);
    }
else
    {
    $rate = $r;
    $pageTitle = "Books Rated ";
    for($i = 1; $i < $rate; $i+=2)
          $pageTitle .= "<span>&#x2605</span>";		// star
    if (($rate == 1)
    || ($rate == 3)
    || ($rate == 5)
    || ($rate == 7)
    || ($rate == 9))
          $pageTitle .= "+";		// half-star
// get the books with that rating
    $a=calibre_db_or_notice();
    $sql = "select * from books"
          . " left outer join comments on comments.book = books.id"
          . " left outer join books_ratings_link on books_ratings_link.book = books.id"
          . " left outer join ratings on books_ratings_link.rating = ratings.id" 
          . " where books_ratings_link.book = books.id and ratings.id = $rate";
    $stmt = $a->prepare($sql);
    $res = $stmt->execute();
    if ($res)
         {
         $books = $stmt->fetchAll();
         $template = file_get_contents('./titles.txt', true);
         $insertPoint = strpos($template, "{{1}}");
         $continuePoint = $insertPoint + 5;
         echo substr($template, 0, $insertPoint) . "<strong>$pageTitle</strong>";

         $insertPoint = strpos($template, "{{2}}");
         echo substr($template, $continuePoint, $insertPoint-$continuePoint);
     
         // we're as deep as we're going -- no more groups, just titles
         echo '</div><div style=\"text-align:left\";background-color:#ffffff;><dl>';
         for ($x=0; $x<count($books); $x++)
              {
              $name0 = $books[$x]['sort'];
              $author = $books[$x]['author_sort'];
              $bookN = $books[$x]['id'];
//              echo '<dt><a href="showOneBook.php?book=' . $bookN . "&title=$pageTitle\"><strong>$name0<strong></a><br>&nbsp;&nbsp;&nbsp;<em>by $author</em></dt>";
              echo '<dt><a href="showOneBook.php?book=' . $bookN . "&title=$pageTitle\"></a></dt>";

              // show up to 5 tags
              $sql2 = "select * from books_tags_link where book = $bookN order by tag";
              $stmt2 = $a->prepare($sql2);
              $res2 = $stmt2->execute();
              if (!$res2)
                    return;
              $record2 = $stmt2->fetchAll();
              if (count($record2)>0)
                    {
                    echo '<dd>';
                    echo "Genres: ";
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
                       echo "</dd><hr>";
                       }
                  else
                        echo '<hr>';
                  echo "</dt>";
                  }
           echo '</dl></div>';
           }
      $continuePoint = $insertPoint + 5;
      echo substr($template, $continuePoint);
       }
?>