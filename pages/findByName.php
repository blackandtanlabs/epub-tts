<?php
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
