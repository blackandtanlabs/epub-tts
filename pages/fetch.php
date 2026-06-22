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
