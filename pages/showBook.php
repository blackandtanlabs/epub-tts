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
include_once('htmlClean.php');
function seriesTitle($seriesN)
	{
	$a = calibre_db_or_notice();
	$sql = "select id, name  from series where id = $seriesN";
	$stmt = $a->prepare($sql);
	$ret = $stmt->execute();
	if ($ret)
		{
		$record = $stmt->fetchAll();
		foreach ($record as $row)
			{
			$seriesTitle = $row["name"];
			}
		$a = NULL;
		return($seriesTitle);
		}
	$a = NULL;
	return("Unknown");
	}
function genreTitle($tag)
	{
	$a = calibre_db_or_notice();
	$sql = "select *  from tags where id = $tag";
	$stmt = $a->prepare($sql);
	$ret = $stmt->execute();
	if ($ret)
		{
		$record = $stmt->fetchAll();
		foreach ($record as $row)
			{
			$genreTitle = $row["name"];
			}
		$a = NULL;
		return($genreTitle);
		}
	$a = NULL;
	return("Unknown");
	}
function showBook($type, $bookN, $evenOdd, $genre, $authorName)
	{
	// type" 1=by author, 2=by Genre, not done yet [3=by Series, 4=by Title (and by latest)
	// 5=by Rating]
	$a = calibre_db_or_notice();
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
		$book = $stmt->fetchAll();
		}
	$bookTitle = $book[0]['title'];
	$authorName = $book[0]['name'];
	$authorNum = $book[0]['author'];
	$rating = $book[0]['rating'];
	echo '<div class="clearfix" style="padding:3px;';
	$odd = $evenOdd % 2;
	if ($odd == 1) echo ' background-image: radial-gradient(yellow,green);">';
	else echo ' background-image: radial-gradient(green,yellow);">';
	echo "<h1>$bookTitle";
	$tit = '   ';
	$tit .= '<strong><span style="color:  red">';
	for ($i = 1; $i < $rating; $i += 2) $tit .= "<span>&#x2605</span>";
	if (($rating == 1) || ($rating == 3) || ($rating == 5) || ($rating == 7) || ($rating == 9))
			$tit .= "+";  // half-star
	$tit .= '</span></strong>';
	$tit .= '</h1>';
	echo $tit;
	if ($type != 1)
		{
		/* todo */
		// echo as href
		echo '    <a href="oneAuthor.php?author=' . $authorNum . "\">";
		echo "<h2>by $authorName</h2></a>";
		}
	$cover = LIBRARY_ROOT . "/" . $book[0]['path'] . "/cover.jpg";
	$bookIDparm = rawurlencode($book[0]['path']);
	if (file_exists($cover))
		echo '<img src="fetch.php?img=' . $book[0]['path'] . '&dim=0" width="40%" style="float:left;margin-right:3px;">';

	echo '<strong>GET BOOK</strong><br>';
//	echo '<i>Speech multi-voice</i><br>';
	echo '<a href="readBook.php?book=' . rawurlencode($book[0]['path']) . '&output=FP&phase=1"><span style="color:Blue">Female Narrator</span><br><br></a>';
	echo '<a href="readBook.php?book=' . rawurlencode($book[0]['path']) . '&output=MP&phase=1"><span style="color:Blue">Male Narrator</span><br><br></a>';
//	echo '<b>WITH NARRATOR:<br></b>';
//	echo "<select id=choiceBox onchange='location = this.value;' >";
//	echo "<option value=''>Choose Narrator</option>\n";
//	
////	echo "<option value=readBook.php?book=$bookIDparm&output=Piper>Piper</option>\n";
//	
//	echo "<option value=readBook.php?book=$bookIDparm&output=MP>Male, Piper</option>\n";
//	echo "<option value=readBook.php?book=$bookIDparm&output=ML>Male, Google</option>\n";
//	echo "<option value=readBook.php?book=$bookIDparm&output=MA>Male, Acapella</option>\n";
//
//	echo "<option value=readBook.php?book=$bookIDparm&output=FP>Female, Piper</option>\n";
//	echo "<option value=readBook.php?book=$bookIDparm&output=FL>Female, Google, Local</option>\n";
//	echo "<option value=readBook.php?book=$bookIDparm&output=FA>Female, Acapella, Local</option>\n";
//	echo "</select>\n<br><br>";

	if ($type != 2)
		{
		/* todo */
		// show up to 5 tags
		$a = calibre_db_or_notice();
		$sql2 = "select * from books_tags_link where book = $bookN order by tag";
		$stmt2 = $a->prepare($sql2);
		$res2 = $stmt2->execute();
		if ($res2)
			{
			$record2 = $stmt2->fetchAll();
			if (count($record2) > 0)
				{
				echo "<b>Genres: </b>";
				for ($t = 0; $t < 5 && $t < count($record2);)
					{
					$tagName = genreTitle($record2[$t]['tag']);
					echo '<a href="oneGenre.php?tag=' . $record2[$t]['tag']
					. '&name=' . rawurlencode($tagName)
					. '&g=0"';
					echo '>' . $tagName . '</a>';
					$t++;
					if ($t < count($record2)) echo ", ";
					}
				echo "<br />";
				}
			// do echo an href to genre
//        echo "Genre: <strong>$genre</strong><br />";
			}
		}
	if ($type == 3)
		{
		// a series list
		echo "<b>Series: </b><strong>";
		if ($book[0]['series'] > 0)
			{
			// do not show the name and do not echo an href to show the series
			echo " #" . $book[0]['series_index'];
			}
		}
	else
		{
		// not a series list
		echo "<b>Series: </b>";
		if ($book[0]['series'] > 0)
			{
			// echo the name but not the index, and do show an href to show the series
			echo '<a href="oneSeries.php?series=' . $book[0]['series']
			. '&name=' . rawurlencode(seriesTitle($book[0]['series']))
			. '">' . seriesTitle($book[0]['series']) . '</a>';
//            echo seriesTitle($book[0]['series']);
			}
		}
	echo "<br /><b>Publication Date: </b>";
	echo substr($book[0]['pubdate'], 0, 7);
	echo "<br /><b>Numeric Rating: </b>";
	if ($book[0]['rating'] > "")
		{
		if ($type == 5)
			{
			// no href
			echo $book[0]['rating'];
			}
		else
			{
			/* todo */
			// echo as href
			echo $book[0]['rating'];
			}
		}
	echo "<br><br>";
	$usableText = cleanSomeHTML($book[0]['text']);
	$len = strlen($usableText);
	$maxLen = 300;
	for ($x = $maxLen; $x < $len; $x++)
		{
		if ($usableText[$x] == ' ') break;
		$maxLen++;
		}
	if ($len > $maxLen)
		{
		$text = substr($usableText, 0, $maxLen);
		echo $text;
		outputMissingEntities($text);
		echo '<a href="showComments.php?book=' . rawurlencode($bookN) . '&name=' . rawurlencode($authorName) . '&title=' . rawurlencode($bookTitle) . '"><b>...Read all commentary.</b></a><br /><br /><br />';
		}
	else
		{
		if ($len > 0)
			{
			echo $usableText;
			// the following should not be necessary, but it is
			outputMissingEntities($usableText);
			}
		}
//    echo '<a href="readBook.php?book=' . rawurlencode($book[0]['path']) . '"><strong>READ BOOK</strong></a>';
	echo '</div>';
	}
echo '</body></html>';
?>