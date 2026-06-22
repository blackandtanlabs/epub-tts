<?php
require_once __DIR__ . '/config.php';

header("Cache-Control: max-age=3600"); //1 hour (60sec * 60min)
$a = calibre_db_or_notice();
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
$tag_counts = array_count_values($tags);
arsort($tag_counts);
$template = file_get_contents('./popularGenre.txt', true);
$insertPoint = strpos($template, "{{1}}");
$continuePoint = $insertPoint + 5;
$n_tags = count($tag_counts);
$max_tags_shown = 300;
echo substr($template, 0, $insertPoint);
$insertPoint = strpos($template, "{{2}}");
echo substr($template, $continuePoint, $insertPoint - $continuePoint);
/*

  <li>Choice A</li>
  <li>Choice B
  <ul>
  <li>Sub 1</li>
  <li>Sub 2</li>
  </ul>
  </li>
  </ul>
 */

if ($n_tags > 0)
	{
	$x = 0;
	echo '<div class="clearfix" style="width:100%;"><ul>';
	foreach ($tag_counts as $key => $value)
		{
		$x++;
		if (($x > 2) && ($x < ($max_tags_shown + 2)))
			{
			$sql = "select * from tags where id = $key";
			$stmt = $a->prepare($sql);
			$ret = $stmt->execute();
			if ($ret)
				{
				$record2 = $stmt->fetchAll();
				echo '<ul>';
				echo '<li><h2><a href="oneGenre.php?tag=' . $key
				. '&name=' . rawurlencode($record2[0]['name'])
				. '&g=0"';
				echo '>' . $record2[0]['name'] . '</a> (All Books)</h2></li>';
				$genreName = "%" . $record2[0]['name'] . "%";
				// at this point, we have the name of one of the desired genres
				// we now have to start over and find all genres which have that name in it
				// in order to account for sub-genres. Normal users wouldn't understand
				// if we have "loose" sub-genres, where the sub's name contains the name
				// selected, but only has the sub-genre listed in it's tags.  Clear???
				$sql = 'select * from tags where name like "' . $genreName . '"';
				$stmt = $a->prepare($sql);
				$ret = $stmt->execute();
				if ($ret)
					{
					echo '<ul>';
					$record3 = $stmt->fetchAll();
					foreach ($record3 as $row3)
						{
						if ($record2[0]['name'] !== $row3['name'])
							{
							// not main genre again
							echo '<li><h3><a href="oneGenre.php?tag=' . $row3['id']
							. '&name=' . rawurlencode($row3['name'])
							. '&g=1"';
							echo '>' . $row3['name'] . '</a></h3></li>';
							}
						}
					echo '</ul></li>';
					}
				echo '</ul></li>';
				}
			}
		}
	echo '</div>';
	}
$continuePoint = $insertPoint + 5;
echo substr($template, $continuePoint);
?>
