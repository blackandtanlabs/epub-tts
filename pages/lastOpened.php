<?php

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)

$template = file_get_contents('./recent.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
$pageTitle = "Recently Opened";
echo substr($template, 0, $insertPoint) . $pageTitle;
$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint - $continuePoint);
if (file_exists("../LastFile.txt"))
	{
	$lastFiles = file_get_contents("../LastFile.txt", true);
	$fileNames = explode("|",$lastFiles);
	$nFileNames = count($fileNames);
	
	// ignore most duplicates
	for ($x=1, $n=0; $x<$nFileNames; $x++)
		{
		if ($fileNames[$x] !== false
		&& $fileNames[$x-1] !== false)
			if ($fileNames[$x] === $fileNames[$x-1])
				continue;
		$n++;
		if ($n > 10)			// maximum number of books shown
			break;
		$books = explode('/', $fileNames[$x-1]);
		if (array_key_exists(3,$books))
			{
			$titles = preg_split("/[()]+/", $books[3]);
			$name0 = trim($titles[0]);
			$author = $books[2];
			$bookN = $titles[1];
			$cover = $author . '/' . $books[3];

			echo '<div class="clearfix" style="padding:3px;">';
			echo '<a href="showOneBook.php?&book=' . $bookN . "&title=$pageTitle\">"
			 . '<img src="fetch.php?&img=' . $cover . '&dim=0" width="50%" style="float:left;margin-right:3px;"></a>';
			echo '<h1>' . $name0 . '</h1><br>';
			echo '<h3>by ' . $author . '</h3><br><br></div>';
			}
		}
	}
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>