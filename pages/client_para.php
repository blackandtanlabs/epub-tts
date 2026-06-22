<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');

function accessDB($DB, $sql, ...$parms)
	{
	$data = array();
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}
function handlePronunciation(&$text)
	{
	$readBookDBname = "sqlite:" . __DIR__ . "\..\..\TTS\labelCheck.db";
	$readBookDB = new PDO($readBookDBname);
	$Pronunciations = accessDB($readBookDB, "SELECT * FROM pronunciation ORDER BY priority DESC");
//	$PronounceReplacements = accessDB($readBookDB, "SELECT replacement FROM pronunciation ORDER BY priority DESC");
	$PronouncePatterns = array_column($Pronunciations, 'pattern');
	$PronounceReplacements = array_column($Pronunciations, 'replacement');

// !!!!!!!!!!!!!!!!!!!!!!!! to test failing software for pronunciation changes
// !!!!!!!!!!!!!!!!!!!!!!!! uncomment the below, and comment out the last line before the return
//	$n = count($PronouncePatterns);
//	for ($x=0; $x<$n; $x++)
//		{
//		$newText = preg_replace($PronouncePatterns[$x],
//			$PronounceReplacements[$x], $text);
//		if ($newText === $text)
//			continue;
//		else
//			{
//			$p = $PronouncePatterns[$x];
//			$r = $PronounceReplacements[$x];
//if (str_contains($p, "Gene"))
//  $breakpoint=1;
//			}
//		$text = $newText;
//		}
	$text = preg_replace($PronouncePatterns, $PronounceReplacements, $text);
	return;
	}
function fail(int $code, string $msg, array $extra = []): never
	{
	http_response_code($code);
	echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
			ob_flush();
			flush();

	exit;
	}

$book = isset($_GET['book']) ? trim((string) $_GET['book']) : '';
$p = isset($_GET['p']) ? (int) $_GET['p'] : 0;

if ($book === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $book))
	fail(400, 'invalid_book');
if ($p < 0)
	fail(400, 'invalid_p');

// TODO: adapt these to your real storage
$ROOT = 'c:/xampp/htdocs/';
$pre = $ROOT . "\\$book\\PRE\\$p.txt";
$tts = $ROOT . "\\$book\\TTS\\$p.txt";

if (!is_file($pre))
	fail(404, 'pre_not_found', ['book' => $book, 'p' => $p, 'path' => $pre]);
if (!is_file($tts))
	fail(404, 'tts_not_found', ['book' => $book, 'p' => $p, 'path' => $tts]);

$pre_text = @file_get_contents($pre);
$tts_text = @file_get_contents($tts);

handlePronunciation($tts_text);
$ignoreLit = '/(⦃d:)(.*)(⦄)/u';
$tts_text = preg_replace($ignoreLit, '\2', $tts_text);

//$pre_text = "<sup>[$p] </sup>" . $pre_text;

if ($pre_text === false || $tts_text === false)
	fail(500, 'read_failed');

http_response_code(200);
$temp = json_encode([
	'ok' => true, 'book' => $book, 'p' => $p,
	'pre_text' => rtrim($pre_text, "\r\n"),
	'tts_text' => rtrim($tts_text, "\r\n"),
	], JSON_UNESCAPED_UNICODE);
echo $temp;
