<?php
require_once __DIR__ . '/config.php';
$img = rawurldecode($_GET["img"]);

$image_file = LIBRARY_ROOT . "/" . $img . "/cover.jpg";
if (!is_file($image_file))
	{
	http_response_code(404);
	exit;
	}
header("Content-type: image/jpeg");
header('Content-Length: ' . filesize($image_file));
readfile($image_file);
?>
