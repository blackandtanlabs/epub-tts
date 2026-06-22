<?php
session_start();

header("Cache-Control: max-age=2592000"); //30days (60sec * 60min * 24hours * 30days)
$template = file_get_contents('page1.html', true);
echo $template;
?>
