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
$readBookDBname = "sqlite:" . __DIR__ . "\..\..\TTS\labelCheck.db";
$readBookDB = new PDO($readBookDBname);

function accessDB($DB, $sql, ...$parms)
	{
//accessTable($DB, $sql, ...$parms);
	$data = array();
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}

if (isset($_GET['name']) && $_GET['name'] !== '')
	$name = $_GET['name'];
if (isset($_GET['males']) && $_GET['males'] !== '')
	$males = $_GET['males'];
if (isset($_GET['females']) && $_GET['females'] !== '')
	$females = $_GET['females'];
$c = accessDB($readBookDB, "SELECT * FROM  $males WHERE label = '$name'");
if ($c !== false)
	{
	header('Content-Type: application/json');
	echo json_encode($c);
	return;
	}

$c = accessDB($readBookDB, "SELECT * FROM  $females WHERE label = '$name'");
if ($c !== false)
	{
	header('Content-Type: application/json');
	echo json_encode($c);
	return;
	}
