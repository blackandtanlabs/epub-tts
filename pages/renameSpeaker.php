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

if (isset($_GET['bookNo']))
	$bookNo = $_GET['bookNo'];
else
	{
	http_response_code(400);
	header('Content-Type: text/plain');
	echo "Missing bookNo";
	exit;
	}
$theseMales = "males$bookNo";
$theseFemales = "females$bookNo";
$theseNewWords = "newWords$bookNo";
$theseProcesses = "reprocess$bookNo";
if (isset($_GET['atParagraph']))
	$atParagraph = $_GET['atParagraph'];
else
	{
	http_response_code(400);
	header('Content-Type: text/plain');
	echo "Missing starting paragraph number";
	exit;
	}
if (isset($_GET['toName']))
	$toName = $_GET['toName'];
else
	{
	http_response_code(400);
	header('Content-Type: text/plain');
	echo "Missing new name";
	exit;
	}
$processData = accessDB($readBookDB, "SELECT * FROM $theseProcesses WHERE atParagraph = $atParagraph");
// Lbrack = "⦃";   // ⦃⦄		©

$text = file_get_contents(__DIR__ . "\\$bookID\\CLEAN\\$atParagraph.txt");
$start = mb_strpos("⦃v:", $text);
if ($start !== false)
	{
	$end = mb_strpos("⦄", mb_substr($text, $start));
	if ($end !== false)
		{
		$sought = mb_substr($text, $start, $end - $start);
		}
	}
if (count($processData) > 0)
	{
	$toSpeakerVoice = accessDB($readBookDB, "SELECT voice FROM $theseMales WHERE label = '$toName'
	UNION ALL SELECT voice FROM $theseFemales WHERE label = '$toName'
	UNION ALL SELECT voice FROM $theseNewWords WHERE label = '$toName'");
	if (count($toSpeakerVoice) === 0)
		{
		http_response_code(400);
		header('Content-Type: text/plain');
		echo "Name $toName not found in database";
		exit;
		}
	setTTSspeakerVoice($atParagraph, $toSpeakerVoice);
	$breakPoint = 1;
	}

