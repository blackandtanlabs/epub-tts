<?php
$img = rawurldecode($_GET["f"]);

$image_file = "img";
header("Content-type: image/jpeg");
header('Content-Length: ' . filesize($image_file));
readfile($image_file);
?>
