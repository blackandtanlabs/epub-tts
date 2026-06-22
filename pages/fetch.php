<?php
$img = rawurldecode($_GET["img"]);

$image_file = "c:/xampp/htdocs/callib/" . $img . "/cover.jpg";
header("Content-type: image/jpeg");
header('Content-Length: ' . filesize($image_file));
readfile($image_file);
?>
