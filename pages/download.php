<?php

$down = rawurldecode($_GET["down"]);
$dir = rawurldecode($_GET["dir"]);
$labelDBname = rawurldecode($_GET["db"]);
$bookID = rawurldecode($_GET["book"]);

if (file_exists($down))
	{
	header('Content-Description: File Transfer');
	header('Content-Type: application/epub+zip');
	header('Content-Disposition: attachment; filename="' . basename($down) . '"');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
//	header('Content-Length: ' . filesize($down));
	readfile($down);

	cleanUp();
	unlink($down);
	rrmdir($dir);
	}
function cleanUp()
	{
	$dir = opendir(".");
	while (false !== ( $file = readdir($dir)))
		{
		if (( $file != '.' ) && ( $file != '..' ))
			{
			$full = "./$file";
			if (is_dir($full))
				{
				$first = substr($file, 0, 1);
				if ($first >= 0
				AND $first <= 9)
					rrmdir($full);
				}
			else
				{
				$sfx = strrchr($full, ".");
				if ($sfx === ".epub"
				OR $sfx === ".jpeg"
				OR $sfx === ".jpg"
				OR $sfx === ".xhtml"
				)
					unlink($full);
				}
			}
		}
	}
function rrmdir($src)
	{
	if (file_exists($src))
		{
		$dir = opendir($src);
		while (false !== ( $file = readdir($dir)))
			{
			if (( $file != '.' ) && ( $file != '..' ))
				{
				$full = $src . '/' . $file;
				if (is_dir($full))
					{
					rrmdir($full);
					}
				else
					{
					unlink($full);
					}
				}
			}
		closedir($dir);
		rmdir($src);
		}
	}
?>
