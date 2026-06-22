<?php

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)

function intdiv_1($a, $b)
    {
    return ($a - $a % $b) / $b;
    }
$s = $_GET["s"];   // numeric string, starting point
$e = $_GET["e"];   // numeric string, ending point
$a=new PDO("sqlite:g:/callib/metadata.db");
$sql = "select * from tag_browser_authors order by sort";
$stmt = $a->prepare($sql);
$res = $stmt->execute();
$n = 0;
$popularityMin = 10;
if ($res)
    {
    $authors = $stmt->fetchAll();
    // build an array of author's indexes who match our "popularity" minimum books
    $authorsIndex = array();
    for ($x=0; $x<count($authors); $x++)
        {
        if ($authors[$x]['count'] >= $popularityMin)
            {
            $authorsIndex[] = $x;
            $n++;
            }
        }
    }
if ($e==-1)
    {
    $e = count($authorsIndex) - 1;
    }
else
    {
    $n = $e - $s + 1;
    }
$template = file_get_contents('./popularAuthors.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
echo substr($template, 0, $insertPoint) . $n . " authors with $popularityMin or more books";

$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint-$continuePoint);
if ($n > 100)		// with more than 100 books, we split into groups below
    {
    $aGoodSize = intdiv_1 ($n, 10);
    if ($n > 0)
        {
        // now split the indexes into groups of aGoodSize
        $nIndexes = count($authorsIndex);
        $group = 0;
        echo "<div style=\"text-align:center\">";
        for ($x=$s; $x<$nIndexes && $x<$e; )
             {
             $group++;
             $name0 = $authors[$authorsIndex[$x]]['sort'];
             $first = $x;
             $x = $x+$aGoodSize;
             if ($x>$e)
                $x = $e;
/*
             echo "<tr><td style=\"width:25%\"><a href=\"popularAuthors.php?s=$first&e=$x\"><strong>Group $group</strong></a></td><td><strong>"
              . $authors[$authorsIndex[$first]]['sort'] . "</strong> to";
             echo '<br /><strong>' . $authors[$authorsIndex[$x]]['sort'] . "</strong></td></tr>\n";
*/
             echo "<a href=\"popularAuthors.php?s=$first&e=$x\"><strong>" . $authors[$authorsIndex[$first]]['sort']
              . "</strong><br>to<br><strong>" . $authors[$authorsIndex[$x]]['sort'] . "</strong></a><hr>";
             $x++;
             }
        echo "</div>\n";
        }
    }
else
    {
    echo "  <ul>\n";
    for ($x=$s; $x<=$e; $x++)
        {
        $author = $authors[$authorsIndex[$x]]['id'];
        echo "  <li>\n";
        echo '    <a href="oneAuthor.php?author=' . $author . "\">"; 
        $sql = "select * from books_authors_link where author = $author";
        $stmt = $a->prepare($sql);
        $ret = $stmt->execute();
        $n2 = 0;
        if ($ret)
            {
            $record2 = $stmt->fetchAll();
            $n2 = count($record2);
            echo "<strong>" . $authors[$authorsIndex[$x]]['sort'] . "</strong></a> (" . $n2 . " books)<br />";
            }
        echo "  </li>\n";
        }
    echo "  </ul>\n";
    }
/*
    {
    foreach ($authors as $row)
        {
        if ($row['count'] > 4)
            {
            {
            $author = $row['id'];
            echo "  <li>\n";
            echo '    <a href="oneAuthor.php?author=' . $row['id'] . "\">"; 
            $sql = "select * from books_authors_link where author = $author";
            $stmt = $a->prepare($sql);
            $ret = $stmt->execute();
            $n2 = 0;
            if ($ret)
                {
                $record2 = $stmt->fetchAll();
                $n2 = count($record2);
                echo "<strong>" . $row['sort'] . " (" . $n2 . " books)</strong></a>";
                }
            echo "  </li>\n";
            }
        }
    }
*/
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
