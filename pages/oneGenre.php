<?php

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
include_once('showBook.php');
$a=new PDO("sqlite:g:/callib/metadata.db");
$tag = htmlspecialchars($_GET["tag"]);   // numeric string
$tag_title = rawurldecode($_GET["name"]);   // string
//$nBooks = htmlspecialchars($_GET["n"]);   // numeric string
$main_genre = htmlspecialchars($_GET["g"]);   // numeric string
//======================================================================================================
$sql = "select tag from books_tags_link order by tag";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
if (!$res)
    return;
$record = $stmt->fetchAll();
$tags = array();
foreach ($record as $row)
    {
    $tags[] = $row['tag'];
    }
$tag_counts = array_count_values($tags);		// now we have how many uniquw tags are present, by name
//==============================================================================================================
$template = file_get_contents('./oneGenre.txt', true);

// First substitution: Genre's name
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
/*
if ($main_genre == 1){
    echo substr($template, 0, $insertPoint) . '"' . $tag_title . '" Genre<br />';}
else  {
    echo substr($template, 0, $insertPoint) . '"' . $tag_title . '" Sub-Genre<br />';}
*/
echo substr($template, 0, $insertPoint) . $tag_title;
/*
// Second substitution: number of books in Genre
$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
$continuePoint = $insertPoint + 5;
if ($nBooks == 1)
    {
    echo "(" . $nBooks . " book)";
    }
else if ($nBooks > 1)
    {
    echo "(" . $nBooks . " books)";
    }
*/
// Third substitution: either the list of books if no sub-genres, or the sub-genres
// $n book(s) in order by series, if any, else title
$insertPoint = strpos($template, "{{3}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
$continuePoint = $insertPoint + 5;
//============================================================================================================
if ($main_genre === 1)
    $sql = "select * from tags where name = '$tag_title'";
else
    $sql = "select * from tags where name like '%$tag_title%'";
//echo "sql = $sql<br>";
$stmt = $a->prepare($sql);
$ret = $stmt->execute();
if ($ret)
    {
    $similar_tags = $stmt->fetchAll();
    $n_similar = count($similar_tags);
    if ($n_similar > 1)
        {
        // we have sub-genres
        echo "Sub-Genres:";
        foreach ($similar_tags as $row)
            {
		$titleFound = $row['name'];
            if ($tag_title == $titleFound)
                {
                // it's the "main" genre
                echo '<div class="clearfix" style="width:100%;"><h3>';
                echo '<a href="oneGenre.php?tag=' . $row['id'] 
                    . '&name=' . rawurlencode($titleFound)
//                    . '&n=' . $tag_counts[$row['id']]
                    . '&g=1"'; 
                echo '>' . $titleFound . '</a> (All ' . $tag_counts[$row['id']] . " books)</a>\n";
                echo '</h3></div>';
                }
            elseif (str_contains($titleFound, $tag_title))
                {
                // it's a sub genre
                echo '<div class="clearfix" style="width:100%;"><h3>';
                echo '<a href="oneGenre.php?tag=' . $row['id'] 
                    . '&name=' . rawurlencode($row['name'])
                    . '&n=' . $tag_counts[$row['id']]
                    . '&g=0"'; 
                echo '>' . $row['name'] . '</a> (' . $tag_counts[$row['id']] . " books)</a>\n";
                echo '</h3></div>';
                }
            } http://192.168.68.69:8888/EPUB/pages/oneGenre.php?tag=5201&name=Fantasy%20Romance%3B%20Fantasy%20New%20Adult%3B%20Fantasy%3B%20Red%20Tower%3B%20Red%20Tower%20Books%3B%20New%20Adult%3B%20Wraith%3B%20Oracle%3B%20Seer%3B%20Shadows%3B%20Queen%3B%20Elements%3B%20Elemental%20Emergence%3B%20Elemental%20War%3B%20Circus%3B%20Fortune%20teller%3B%20Hybrids%3B%20Wings%3B%20fire%3B%20water%3B%20Tarot%20cards%3B%20Elemental%20Queens%3B%20Avatar%20the%20last%20airbender%3B%20Magic%3B%20Magical%20Circus%3B%20Romance%3B%20Forbidden%20Romance%3B%20Enchanter%3B%20Tarot%3B%20The%20Sandman%3B%20The%20Greatest%20Show%3B%20Sanctuary%20of%20the%20Shadow%3B%20Sanctuary%20of%20the%20Shadow%20by%20Aurora%20Ascher%3B%20Aurora%20Ascher%3B%20action%20and%20adventure%3B%20betrayal%3B%20magic%3B%20powers%3B%20winged%20creatures%3B%20imaginative%3B%20debut%3B%20dark%3B%20light%3B%20dark%20and%20light%3B%20opposites%20attract%3B%20forbidden%3B%20opposites%3B%20trust%3B%20loyalty%3B%20mysterious%3B%20mystery%3B%20abandoned%3B%20new%20beginnings%3B%20revenge%3B%20overcoming%20odds%3B%20Wraith%3B%20Oracle%3B%20Seer%3B%20Shadows%3B%20Queen%3B%20Elements%3B%20Elemental%20Emergence%3B%20Elemental%20War%3B%20Circus%3B%20Fortune%20teller%3B%20Hybrids%3B%20Wings%3B%20fire%3B%20water%3B%20Tarot%20cards%3B%20Elemental%20Queens%3B%20Avatar%20the%20last%20airbender&n=1&g=0
        }
    else if ($n_similar == 1)
        {
        // at this point, we have either just one genre,
        // what remains is similar to oneAuthor.php's main function
//        echo "<h2>All Books.  In order by series, if any, else by title</h2>";
        
        $sql = "select * from books_tags_link, books" 
        . " left outer join comments on comments.book = books.id"
        . " left outer join books_ratings_link on books_ratings_link.book = books.id"
        . " left outer join ratings on books_ratings_link.rating = ratings.id"
        . " left outer join books_series_link on books_series_link.book = books.id"
        . " where books_tags_link.book = books.id and tag = $tag order by series desc, series_index asc, sort asc";
        $stmt = $a->prepare($sql);
        $ret = $stmt->execute();
        if ($ret)
            {
            $books = $stmt->fetchAll(); 
            }
        // we're at the insert point. Generate the book list
        if ($ret)
            {
            $evenOdd=0;
            foreach ($books as $book)
                {
                // for now, we'll just list em
                $bookN = $book['1'];
                $evenOdd++;
                showBook(2, $bookN, $evenOdd, $tag_title, $book['author_sort']);
                }                                    
            }
        }
    }

$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
