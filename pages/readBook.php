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


if (ob_get_level() > 0)
	ob_end_clean();
echo str_repeat(' ', 1024); // Browser padding to force rendering start

$path = rawurldecode($_GET["book"]);
$phase = rawurldecode($_GET["phase"]);
$outputParm = rawurldecode($_GET["output"]);
$temp = preg_split('/[\(\)](.*?)/', strrev($path));
$bookID = $temp[1];
require_once __DIR__ . '/config.php';
$readBookDBname = "sqlite:" . APP_DB;
$readBookDB = new PDO($readBookDBname);
//$readBookDB->exec('PRAGMA journal_mode = WAL;');
//$readBookDB->exec('PRAGMA wal_autocheckpoint=10;');
//$readBookDB->exec('PRAGMA synchronous = normal;');
$data = accessDB($readBookDB, "SELECT * FROM bookTitle WHERE ID = ?", $bookID);
if (count($data) === 0)
	{
	// book not found
	$lastParagraph = 0;
	accessDB($readBookDB, "REPLACE INTO bookTitle(ID, lastParagraph) VALUES(?, ?)", $bookID, $lastParagraph);
	}
else
	$lastParagraph = $data[0]['lastParagraph'];


//$lastParagraph = 72;




/*
 * $phase and $lastParagraph work together to control this page
 * 
 * $phase is set to '1' in every call from the page sequence for selecting a book
 * $phase is set to '3' when this page calls itself
 * $lastParagraph is set when a paragraph has started speaking and is '0' otherwise
 * 
 * if $phase === '1'
 * 	if $lastParagraph === 0
 * 		means first time this book has been processed. produce CLEAN, PRE, and TTS files
 * 	else (relabeled below as phase '2')
 * 		nothing needs to be done -- the listener is continuing the listen from $lastParagraph
 * 		but be sure not to delete any files
 * if $phase === '3'
 * 	The listener has made a change to the text requiring reprocessing fron $lastParagraph onward,
 * 		producing TTS files again
 * 	if $lastParagraph === 0, the listener has made a mistake and we would still reprocess from $lastParagraph
 * 	Reprocessing uses existing CLEAN and PRE files and is somewhat faster than initial processing
 */
if ($phase === '1')
	{
	$phase = 'initial';
	if ($lastParagraph !== 0)
		$phase = 'continue';
	}
else
	$phase = 'reprocess';
// we need to re-establish speakers, so make all voices available
accessDB($readBookDB, "UPDATE voice_params SET assigned = 0");
/////////////////////////////////////////////////////////////////////////

require '../cleanHTML.php';
require '../DigitsToWords.php';
$digitsConverter = new DigitToWordsConverter();

require 'QuestionPunctuationDecider.php';  // not necessary while computer is slow
$interrogative = new QuestionPunctuationDecider([
	'keep_threshold' => 1, // scores >= 1 => KEEP '?'
	'remove_threshold' => 0, // scores <= 0 => REMOVE '?
	]);

const N_VOICES = 904;

CONST LQ = '“';
CONST RQ = '”';
CONST LA = '‘';
CONST RA = '’';
CONST Lbrack = "⦃";   // ⦃⦄		©
CONST Rbrack = "⦄";
//     ⦃⦄
/*
 * ************************************************************************
 * Only Piper TTS is used. Piper is not perfect, but better than local Google or Acapela, and hopefully will get even better.
 * ************************************************************************
 */

//accessDB($readBookDB, "BEGIN TRANSACTION");
//header("Cache-Control: no-store");
require_once('ebookRead.php');
require_once('ebookData.php');
require_once('ChapterMarker.php');
date_default_timezone_set("America/Chicago");

function logMsg($txt, $level = "Warning")
	{
	$day = date("l");
	$date = date("Y/m/d");
	$time = date("h:i:sa");
	$msg = "$day $date $time $level: $txt \n";
	error_log($msg, 3, "readBook.log");
	if ($level === "Error")
		{
		echo "Cannot process this book.<br>$txt<br>Check htdocs/EPUB/pages/readBook.log.";
		exit;
		}
	}

$totalTime = 0;
$channel = "center";
$veryFirstParagraph = true;
$veryFirstCleanParagraph = true;

function accessDB($DB, $sql, ...$parms)
	{
	$data = array();
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!($ret))
		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}

$speakerSex = "";

$output = $outputParm;
$narrationSex = "";
$v = 0;
switch ($outputParm)
	{
	case 'MP':
		$narrationSex = 'male';
		break;
	case 'FP':
		$narrationSex = 'female';
		break;
	}
$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = '$narrationSex' AND disabled = 0 AND narratorScore >= 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	}
$narrVoiceNumber = $v;
$narrat = Lbrack . "v:$v" . Rbrack . Lbrack . "r:narrator" . Rbrack . Lbrack . "c:head" . Rbrack; //  narrator voice as narrator
$narrVoice = Lbrack . "v:$v" . Rbrack;   // narrator as generic character

$prevDepth = 0;

$prevPrevVoice = $narrVoice;  // used for reusing names from just before
$prevVoice = $narrVoice;

$prevSex = $narrationSex;
$prevPrevSex = $narrationSex;

$paragraphNumber = 0;
$voiceSource = "";
$words = array();

$possiblePreposition = "";
ob_start();
echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN">';
echo '<html lang="en-us">';
echo '<head>';
echo '<meta http-equiv="content-type" content="text/html; charset=UTF-8">';
echo '<title>EPUB TTS Reader</title>';
echo '<script type="text/javascript" src="readBook.js"></script>';
echo '<link rel="stylesheet" type="text/css" href="readBook.css" />';
?>
<style>
	body {
		font-family: Arial, sans-serif;
		text-align: left;
		padding: 20px;
	}
	#progress-container {
		width: 95%;
		border: 1px solid #ccc;
		height: 20px;
		background: #f3f3f3;
	}
	#progress-bar {
		width: 0%;
		height: 100%;
		background: #4caf50;
	}
</style>
<?php
echo '</head>';
//echo '<div id="loadingSpinner" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.8); z-index: 9999; text-align: center; padding-top: 20%;">';
//echo '    <div class="spinner"></div>';
echo '<body style="text-align:center;">';
echo '<div id=title>';
?>
<h3>Analyizing</h3>
<div id="progress-container">
	<div id="progress-bar"></div>
</div>
<div id="status" style="margin: 5px;">0%</div>
<br>
<?php
$curTimeStamp = time();
$data = accessDB($readBookDB, "SELECT * FROM bookTitle WHERE ID = ?", $bookID);
if (count($data) === 0)
// book not found
	accessDB($readBookDB, "REPLACE INTO ID, bookTitle, timeStamp) VALUES(?, ?, ?)", $bookID, $bookTitle, $curTimeStamp);
accessDB($readBookDB, "UPDATE bookTitle SET timeStamp = '$curTimeStamp' WHERE ID = '$bookID'");
$tables = accessDB($readBookDB, "SELECT name FROM sqlite_master WHERE type='table'");
$bookData = accessDB($readBookDB, "SELECT * FROM bookTitle");
for ($b = 0; $b < count($bookData); $b++)
	{
	$thisBook = $bookData[$b];
	$age = $curTimeStamp - $thisBook['timeStamp'];
	$upperLimit = 60 /* seconds */ * 60 /* minutes */ * 24 /* hours */ * 7 /* days */;
	if ($age > $upperLimit)
		{
		cleanOtherBookDataFiles($thisBook['ID']);
		// and clean $readBookDB, in reverse order
		for ($x = count($tables) - 1; $x >= 0; $x--)
			{
			$tableName = $tables[$x]['name'];
			$nums = preg_split('/(\d+)/u', $tableName, NULL, PREG_SPLIT_DELIM_CAPTURE);
			if ($nums[1] !== $bookID)
				accessDB($readBookDB, "DROP TABLE $tableName");
			}
		}
	}
$justSwappedVoices = false;
$lastParagNoText = false;
$sexAmbiguous = array();
//$zipDir = "";
$navPoint = array();
$thinSpace = json_decode('"\u2009"');
$dash = json_decode('"\u2013"');
$emDash = json_decode('"\u2014"');
$softHyphen = json_decode('"\u00AD"');
$unkDash = '—';
$dashRepl = $thinSpace . $dash . $thinSpace;
$dashRepl2 = $thinSpace . $emDash . $thinSpace;
$fullPath = LIBRARY_ROOT . "/" . $path;
$filesAvail = scandir($fullPath);
$filename = "";
$depth = 0;
$aParagraph = 'empty';
// There are 25 empty quotes below
$prevParagraphStack = array("", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "");
$prevParagraphStackSize = 25;
$prevLastSentence = "";
$prevOrder = 0;
$prevLength = 0;
$firstTOC = true;
$firstC = true;
$male1spkrDefn = "";
$male2spkrDefn = "";
$female1spkrDefn = "";
$female2spkrDefn = "";
$justSwapped = false;

if ($filesAvail === null)
	logMsg("Software error: readBook line " . __LINE__, "Error");
for ($ax = 0; $ax < count($filesAvail); $ax++)
	{
	if (strtolower(strrchr($filesAvail[$ax], ".")) == ".epub")
		{
		$filename = $filesAvail[$ax];
		break;
		}
	}
if (strlen($filename) === 0)
	logMsg("Software error: readBook line " . __LINE__, "Error");
$sourceEPUB = $fullPath . "/" . $filename;
$ebookObject = new ebookRead();
$ebookObject->ebookRead($sourceEPUB);
$contentFolder = $ebookObject->ebookData->contentFolder;
if ($contentFolder === './')
	$contentFolder = '';
$spineInfo = $ebookObject->getSpine();
$parts = explode(" - ", $filename);
$bookTitle = trim($parts[0]);
$speaker = "";
$fixData = array();

$theseSettings = "settings$bookID";
$data = accessDB($readBookDB, "SELECT name FROM sqlite_master WHERE type='table' AND name='$theseSettings'");
if (count($data) === 0)
// $theseSettings table not found
	accessDB($readBookDB, "CREATE TABLE $theseSettings AS SELECT * FROM defaultSettings");
$settings = accessDB($readBookDB, "SELECT * FROM $theseSettings");

$theseSourceFixes = "fixes$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseSourceFixes . '" (
	"cmd"				TEXT NOT NULL,
	"paragraphNumber"		INTEGER,
	"parm1"				TEXT,
	"parm2"				TEXT
	);';
accessDB($readBookDB, $sql);

$theseVoiceChanges = "voiceChanges$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseVoiceChanges . '" (
	"atParagraph"			TEXT NOT NULL,
	"specified"				TEXT,
	PRIMARY KEY("atParagraph")
	);';
accessDB($readBookDB, $sql);

$theseNotNames = "notNames$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseNotNames . '" (
	"label"	TEXT NOT NULL,
	"count"	INTEGER,
	PRIMARY KEY("label")
	);';
accessDB($readBookDB, $sql);

$theseFemales = "females$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseFemales . '" (
	"label"	TEXT NOT NULL,
	"lastName"	TEXT,
	"recipe"	TEXT,
	"count"	INTEGER,
	"voice"	TEXT,
	"context"	TEXT,
	"creatingLine" TEXT,
	"updatingLines" TEXT,
	PRIMARY KEY("label")
	);';
accessDB($readBookDB, $sql);
if ($phase === 'initial')
	{
	accessDB($readBookDB, "UPDATE $theseFemales SET count = 0, voice = '', creatingLine = '(Previously)', updatingLines=''");
	if ($narrationSex === "female")
		accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'narrator', '', '', 0, $narrVoice, "", __LINE__, "");
	}

$theseMales = "males$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseMales . '" (
	"label"	TEXT NOT NULL,
	"lastName"	TEXT,
	"recipe"		TEXT,
	"count"	INTEGER,
	"voice"	TEXT,
	"context"	TEXT,
	"creatingLine" TEXT,
	"updatingLines" TEXT,
	PRIMARY KEY("label")
	);';
accessDB($readBookDB, $sql);
if ($phase === 'initial')
	{
	accessDB($readBookDB, "UPDATE $theseMales SET count = 0, voice = '', creatingLine = '(Previously)', updatingLines=''");
	if ($narrationSex === "male")
		accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'narrator', '', '', 0, $narrVoice, "", __LINE__, "");
	}

$theseNewWords = "newWords$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseNewWords . '" (
	"label"	TEXT NOT NULL,
	"lastName"	TEXT,
	"alias"	TEXT,
	"count"	INTEGER,
	"voice"	TEXT,
	"context"	TEXT,
	"creatingLine" TEXT,
	"updatingLines" TEXT,
	PRIMARY KEY("label")
	);';
accessDB($readBookDB, $sql);
if ($phase === 'initial')
	accessDB($readBookDB, "DELETE FROM $theseNewWords; VACUUM;");
else
	accessDB($readBookDB, "UPDATE $theseNewWords SET count = 0");
//$m1 = "$narrVoice" . Lbrack . "r:masculine" . Rbrack;
//$m2 = "$narrVoice" . Lbrack . "r:masculine" . Rbrack;

$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'male' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	$m1 = Lbrack . "v:$v" . Rbrack;
	accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'm1', '', '', 0, $m1, "", __LINE__, "");
	}
$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'male' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	$m2 = Lbrack . "v:$v" . Rbrack;
	accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'm2', '', '', 0, $m2, "", __LINE__, "");
	}

$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'female' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence'");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessDB($readBookDB, "UPDATE voice_params SET assigned =1 WHERE voice_number = $v");
	$f1 = Lbrack . "v:$v" . Rbrack;
	accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'f1', '', '', 0, $f1, "", __LINE__, "");
	}
$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'female' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence'");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessDB($readBookDB, "UPDATE voice_params SET assigned =1 WHERE voice_number = $v");
	$f2 = Lbrack . "v:$v" . Rbrack;
	accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'f2', '', '', 0, $f2, "", __LINE__, "");
	}

$prevAntecedentFemale = 'f1';  // used for antecedent
$antecedentFemale = 'f2';

$prevAntecedentMale = 'm1';  // used for antecedent
$antecedentMale = 'm2';

$epubContentsFolder = __DIR__ . DIRECTORY_SEPARATOR . $bookID;
$thisDir = __DIR__;
// get spine and manifest
$author = $ebookObject->ebookData->metadata->dccreator;

if ($lastParagraph === 0)
	{
	$PREfileNumber = 0;
	$TTSfileNumber = 0;
	$CLEANfileNumber = 0;
	}
else
	{
	$PREfileNumber = $lastParagraph;
	$TTSfileNumber = $lastParagraph;
	$CLEANfileNumber = $lastParagraph;
	}

function checkDBConsistency($t1, $t2, $delWhich)
	{
	global $readBookDB;

	$data = accessDB($readBookDB, "SELECT  *  FROM $t1 A WHERE EXISTS (SELECT *  FROM $t2 B  WHERE A.label = B.label)");
	for ($x = 0; $x < count($data); $x++)
		{
		$label = $data[$x]['label'];
		logMsg("Name inconsistency $label in $t1 and $t2");
		if ($delWhich === 1 OR $delWhich === 3)
			accessDB($readBookDB, "DELETE FROM  $t1 WHERE label =  '$label'");
		if ($delWhich === 2 OR $delWhich === 3)
			accessDB($readBookDB, "DELETE FROM  $t2 WHERE label =  '$label'");
		}
	}

//checkDBConsistency('males', 'females', 3);
//checkDBConsistency($theseMales, $theseFemales, 3);
//checkDBConsistency($theseMales, 'females', 1);
//checkDBConsistency($theseFemales, 'males', 1);
$cleanedAuthor = preg_replace("/[’']/u", '', $author);
$cleanedTitle = preg_replace("/[’']/u", '', $bookTitle);
accessDB($readBookDB, "UPDATE bookTitle SET title = '$cleanedTitle', author = '$cleanedAuthor' WHERE ID = '$bookID'");
echo '<h1>' . $bookTitle . "</h1>" . "<h2>by " . $author . "</h2>";
if ($phase === 'initial')
	{
//	echo ' <p style="font-size: 12px;">Phase ';
	$time_start = hrtime(true);
	if (!(is_dir($epubContentsFolder)))
		{
		mkdir($epubContentsFolder);
		mkdir($epubContentsFolder . "/CLEAN");
		mkdir($epubContentsFolder . "/PRE");
		mkdir($epubContentsFolder . "/TTS");
		}

	// Record where each chapter (spine file) begins, for the reader's jump menu.
	$chapterTable = "chapters$bookID";
	accessDB($readBookDB, "CREATE TABLE IF NOT EXISTS $chapterTable (para INTEGER PRIMARY KEY, title TEXT, level INTEGER)");
	accessDB($readBookDB, "DELETE FROM $chapterTable");
	$chapterMarker = new ChapterMarker(['pause_ms' => 1200, 'keep_back' => 3], true, ['book','part']);

	$mainDir = $ebookObject->getContentLoc();
	if ($mainDir === "./")
		$mainDir = "";

	for ($i = 0; $i < count($spineInfo); $i++)
		{
		$id = XMLstring($spineInfo[$i]);
		$f = $ebookObject->getManifestById($id)->href;
		$manData[$id] = $ebookObject->getManifestById($id);
		$manData[$id]->href = $bookID . '/' . $mainDir . $f;
		}
//	echo '1';
//	ob_flush();
//	flush();

	$zip = new ZipArchive;
	$toc = array();
	if ($zip->open($fullPath . "/" . $filename) !== true)
		logMsg("Software error: readBook line " . __LINE__, "Error");
	for ($i = 0, $nextCSS = 0; $i < $zip->numFiles; $i++)
		{
		$zippedFile = $zip->getNameIndex($i);
		$zip->extractTo($epubContentsFolder, $zippedFile);
		$fullZippedFile = $epubContentsFolder . '/' . $zippedFile;
		$fullZippedFile = str_replace("\/", "/", $fullZippedFile);
		if (strrchr($zippedFile, ".") == ".ncx")
			{
			$temp = simplexml_load_file($fullZippedFile);
			$toc = simpleXML2Array($temp);
			if (isset($toc["navMap"]["navPoint"]["content"]))
				$navPoint[0] = $toc["navMap"]["navPoint"];
			else
				$navPoint = $toc["navMap"]["navPoint"];
			}
		}
//	echo '1';
//	ob_flush();
//	flush();
	$zip->close();
	// prepend this file onto list of prev opened
	if (!(file_exists("../LastFile.txt")))
		file_put_contents("../LastFile.txt", "");
	$fileContents = file_get_contents("../LastFile.txt");
	$fileContents = str_replace($fullPath . '|', "", $fileContents);
	$fileContents = $fullPath . "|" . $fileContents;
	file_put_contents("../LastFile.txt", $fileContents);
	if (!(chdir($epubContentsFolder)))
		logMsg("Software error: readBook line " . __LINE__, "Error");

	// process html files in the spine order
	// spine id refs manifest[id] to get file name with no subdir specified
	$totalHTMLfiles = count($spineInfo);
	for ($i = 0; $i < $totalHTMLfiles; $i++)
		{
		$id = XMLstring($spineInfo[$i]);
		$HTMLfile = urldecode($manData[$id]->href);
		if ($HTMLfile === ' ')
			continue;
		$fullZippedFile = __DIR__ . DIRECTORY_SEPARATOR . $HTMLfile;
		$htm = file_get_contents($fullZippedFile);
		$htmCheck = mb_substr($htm, 0, 500);
		if (mb_stripos($htmCheck, "generated"))
			{
			echo("A portion of the book, $HTMLfile, containing the generated TOC has been ignored until the TOC software has been written..<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (mb_stripos($htmCheck, "<im"))
			; //echo("A portion of the book, $HTMLfile, containing an image has been ACCEPTED.<br>");
		else
			{
			if (mb_stripos($htmCheck, "signup"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'signup' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
//			if (mb_strpos($htmCheck, " By "))
//				{
//				echo("A portion of the book, $HTMLfile, containing the text 'By' deleted.<br>");
//				unlink($fullZippedFile);
//				continue;
//				}
			if (mb_strpos($htmCheck, "Books by"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'Books by' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_strpos($htmCheck, "Also by"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'Also by' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_strpos($htmCheck, "Praise for"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'Praise for' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_strpos($htmCheck, "Contents")
			OR mb_strpos($htmCheck, "CONTENTS"))  // note NOT stripos
				{
				echo("A portion of the book, $HTMLfile, containing the text 'Contents' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_stripos($htmCheck, "href")
				AND !mb_stripos($htmCheck, "style"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'href'and not containg the word 'style' has been deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_stripos($htmCheck, "copyright")
				OR mb_strpos($htmCheck, "©"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'copyright' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_stripos($htmCheck, "isbn"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'isbn' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			if (mb_stripos($htmCheck, "https"))
				{
				echo("A portion of the book, $HTMLfile, containing the text 'https' deleted.<br>");
				unlink($fullZippedFile);
				continue;
				}
			}
		deleteSections($htm);
		substituteDivs($htm);  // make <div>'s into <p>'s
		$athisParagBeginsWithQuote = false;
		$bodyPos = mb_strpos($htm, "<body", 0);
		$endBodyPos = mb_strpos($htm, "</body>", $bodyPos);
		if ($bodyPos
			AND $endBodyPos)
			{
			// process body only
			$htm = mb_substr($htm, $bodyPos, $endBodyPos - $bodyPos);
			simplifyAnyHR($htm);  // make <hr..../>'s into plain <hr>'s
			$currentInternalDir = dirname($HTMLfile);
// Pass the internal path (like 'OEBPS' or 'OPS') into the cleaner
			$htmBody = trim(body_html_to_plain_text($htm, 'US', $currentInternalDir));
			if ($htmBody === "")
				continue;
			// This spine file becomes a chapter; its first paragraph is the
			// current global paragraph counter. Derive a title from the markup.
			$chapterStartPara = $CLEANfileNumber;
			$chapterMarker->onSpineEnter($bookID, $id, $htm);
			$chapterDirective = $chapterMarker->chapterDirectiveForFirstPara($bookID);
			if ($chapterDirective !== "")
				{
				$chapterTitle = "";
				$chapterLevel = 1;
				if (preg_match('/title="([^"]*)"/u', $chapterDirective, $mTitle))
					$chapterTitle = html_entity_decode($mTitle[1], ENT_QUOTES, 'UTF-8');
				if (preg_match('/level=(\d+)/', $chapterDirective, $mLevel))
					$chapterLevel = (int) $mLevel[1];
				accessDB($readBookDB, "INSERT OR REPLACE INTO $chapterTable(para, title, level) VALUES(?, ?, ?)", $chapterStartPara, $chapterTitle, $chapterLevel);
				}
			breakLongParagraphs($htmBody);
			$outTTSfile = "$thisDir/$bookID/CLEAN/";
			file_put_CLEAN_by_paragraph($outTTSfile, $htmBody, true);
			$outTTSfile = "$thisDir/$bookID/PRE/";
			file_put_PRE_by_paragraph($outTTSfile, $htmBody, true);
//			echo '1';
//			ob_flush();
//			flush();
			}
		}
	}
//else
//	{
//$percent = round(($i / $totalHTMLfiles) * 100);
//        echo "<script>
//            document.getElementById('progress-bar').style.width = '$percent%';
//            document.getElementById('status').innerText = '$percent%';
//        </script>";
//
//        // 3. Force the browser to show the update
//        flush();
//	
//	}
if ($phase !== 'continue')
	{
	$time_start = hrtime(true);
	"$thisDir/$bookID/CLEAN/";
	$directory = "$thisDir/$bookID/CLEAN";
	$files = scandir($directory);
	$fileCount = count($files) - 2;
	if ($phase === 'reprocess')
//		accessDB($readBookDB, "UPDATE $theseNewWords SET count = -99999 WHERE count < 2");
//		accessDB($readBookDB, "DELETE FROM  $theseNewWords WHERE count < 2");
		{
		$data = accessDB($readBookDB, "SELECT * FROM $theseNewWords WHERE count < 2");
		$c = count($data);
		for ($x = 0; $x < $c; $x++)
			{
			$label = $data[$x]['label'];
//			$rootWord = getRootWord($label);
//			if ($rootWord !== $label)
//				{
//				// its a verb?
//				accessDB($readBookDB, "REPLACE INTO verbs VALUES(?, ?, ?)", $label, '(verb)', 1);
//				accessDB($readBookDB, "DELETE FROM  $theseNewWords WHERE label = '$label'");			
//				}
			if ($label[0] > 'Z')
				{
				accessDB($readBookDB, "REPLACE INTO $theseNotNames VALUES(?, ?)", $label, 0);
				accessDB($readBookDB, "DELETE FROM  $theseNewWords WHERE label = '$label'");
				}
			}
		}

	for ($f = 0; $f < $fileCount; $f++)
		{
		$percent = round(($f / $fileCount) * 100);
		echo "<script>
            document.getElementById('progress-bar').style.width = '$percent%';
            document.getElementById('status').innerText = '$percent%';
        </script>";

		// 3. Force the browser to show the update
		flush();

		$htmBody = file_get_contents("$thisDir/$bookID/CLEAN/$f.txt");

		//	processCAPSwords($htmBody);
		$element = preg_split('/\n/u', $htmBody, NULL, PREG_SPLIT_DELIM_CAPTURE);
		for ($ax = 0; $ax < count($element); $ax++)
			{
//			$element[$ax] = trim($element[$ax]);
			if (strlen($element[$ax]) === 0)
				{
				unset($element[$ax]);
				$element = array_values($element);
				$ax--;
				continue;
				}
			}

		$nNoQuotesParags = 0;
//		$prevDepth = 0;
		$firstP = true;
		for ($ax = 0; $ax < count($element); $ax++)
			{
			if (mb_strlen($element[$ax] === 0))
				continue;
			else
				{
				process_B_paragraph($element[$ax], $paragraphNumber, $firstP);
				$paragraphNumber++;
				$firstP = false;
				}
			}
		if ($paragraphNumber % 100 === 0)
			{
			echo ' ';
			ob_flush();
			flush();
			}
		$htmBody = implode('', $element);
		$outTTSfile = "$thisDir/$bookID/TTS/$f.txt";
		if ($phase !== 'reprocess')
			processAllNames($htmBody);
		if ($f === 903)
			$breakpoint = 1;
		reverseMarkersForTTS($htmBody);
		file_put_contents($outTTSfile, $htmBody);
		}
	/* 	$time_end = hrtime(true);
	  $time = $time_end - $time_start;
	  $totalTime += $time;
	  $totalTime /= 1000000000;
	  logMsg("time = $totalTime seconds\n");
	 */
	}
else
	{
	echo "<script>
	document.getElementById('progress-container').style.display='none';
	</script>";
	echo "Continuing. Speak is warranted, or make more corrections.";
	}
//accessDB($readBookDB, "COMMIT TRANSACTION");					// review ****************************************
echo "</div>";  // end title div

echo "<div id=editNames></div>";
echo "<hr>";
editNames();
echo "<div style=text-align:center id=assignDiv>";

$phase = '3';
echo '<br><a href="readBook.php?book=' . rawurlencode($path) . '&output=' . $outputParm . "&phase=$phase\" class=button-link>Re-Process.</a>";
echo "<hr>";

$_SESSION['form_submitted'] = false;
echo '<a href="reader.php?book=' .
 $bookID . "&start=$lastParagraph&title=$bookTitle&author=$author&narrator=$narrVoiceNumber" . '" class=button-link>Speak</a>';

//echo "<div id=editProblems><hr>";
//editProblems();
//echo "</div>";
echo "</div><div style=text-align:center>";
//echo "</div>";
//editMisc();
//echo "<hr></div>";
echo "</div></body></html>";
exit;

function file_put_CLEAN_by_paragraph($fileBase, &$htmBody, $firstParagraph)
	{
	global $CLEANfileNumber;
	global $bookTitle;
	global $author;
	global $veryFirstCleanParagraph;
	global $narrVoiceNumber;

	$files = preg_split('/(\n)/u', $htmBody);
	$fileCount = count($files);
	for ($f = 0; $f < $fileCount; $f++)
		{
		if (trim($files[$f]) === "")
			continue;
		$fileName = $fileBase . $CLEANfileNumber++ . ".txt";
		if ($veryFirstCleanParagraph)
			$title = '<h1><b>' . $bookTitle . "</b></h1><br>" . "<h2>by " . $author .
				"</h2><br>";
		else
			$title = "";
		if ($firstParagraph)
			$files[$f] = $title . $files[$f];
		$firstParagraph = false;
		$veryFirstCleanParagraph = false;
		file_put_contents($fileName, $files[$f]);
		}
	}

function file_put_PRE_by_paragraph($fileBase, &$htmBody, $firstParagraph)
	{
	global $PREfileNumber;
	global $bookTitle;
	global $author;
	global $veryFirstParagraph;
	global $narrVoiceNumber;

	$files = preg_split('/(\n)/u', $htmBody);
	$fileCount = count($files);
	for ($f = 0; $f < $fileCount; $f++)
		{
		if (trim($files[$f]) === "")
			continue;
		$fileName = $fileBase . $PREfileNumber++ . ".txt";
		reverseMarkersForDisplay($files[$f]);
		if ($veryFirstParagraph)
			$title = '<h2>' . $bookTitle . "</h2>" . "<h2>by " . $author . "</h2>";
		else
			$title = "";
		if ($firstParagraph)
			$files[$f] = $title . $files[$f];
		$firstParagraph = false;
		$veryFirstParagraph = false;
		file_put_contents($fileName, $files[$f]);
		}
	}

function process_B_paragraph(&$paragraph, &$paragraphNumber, $firstP)
	{
	global $settings;
	global $narrat;
	global $prevParagraphStack;
	global $prevParagraphStackSize;
	global $digitsConverter;
	global $depth;

	$usedAntecedent = false;
	if (preg_match("/\d/u", $paragraph))
		{
		$conv = $digitsConverter->convertSentence($paragraph); // translate numbers into words
		if (count($conv['conversions']) > 0)
			$paragraph = $conv['converted_sentence'];
		}
//	$paragraph = Lbrack . "n:$paragraphNumber" . Rbrack . "\n" . $paragraph;
	if ($firstP)
		if (str_contains(mb_substr($paragraph, 0, 1), "“") === false)
			$paragraph = $narrat . $paragraph . "\n";
	$depth = 0;
	handleWHquestions($paragraph);
	$possibleSingleQuote = preg_split('/([a-z] )(“)(.*?)(”)/u', $paragraph, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$c = count($possibleSingleQuote);
	if ($c > 1)
		{
		// it turns out, this is not a speaker, but a '   ' reference
		// so we change to single quotes
		for ($q = 0; $q < $c; $q++)
			{
			if ($possibleSingleQuote[$q] === LQ)
				{
				if (array_key_exists($q + 2, $possibleSingleQuote))
					{
					$possibleSingleQuote[$q] = LA;
					$possibleSingleQuote[$q + 2] = RA;
					}
				}
			}
		$paragraph = implode('', $possibleSingleQuote);
		}
	$q = 0;
	array_push($prevParagraphStack, $paragraph);
	if (count($prevParagraphStack) > $prevParagraphStackSize)
		array_shift($prevParagraphStack);
	process_C_text($paragraph, $paragraphNumber);
	}

function recipe($name)
	{
	return "⦃r:$name" . "⦄";
	}

function process_C_text(&$paragraphText, &$paragraphNumber)
	{
	global $settings;
	global $depth;
	global $prevDepth;
	global $nNoQuotesParags;
	global $narrationSex;
	global $speakerSex;
	global $prevSex;
	global $prevPrevSex;
	global $narrat;
	global $prevVoice;
	global $prevPrevVoice;
	global $antecedentFemale;
	global $prevAntecedentFemale;
	global $antecedentMale;
	global $prevAntecedentMale;
	global $speaker;
	global $channel;
	global $readBookDB;
	global $theseVoiceChanges;
	global $bookID;
	global $narrVoice;
	global $prevParagraphsVoice;

	$voiceDebug = false;
	if ($voiceDebug)
		addDebugToPRE(" <sub>", $paragraphNumber);
	if ($paragraphNumber === 967)
		$breakpoint = 1;
	$quoteNumber = 0;
	$quotation = preg_split('/([“”])/u', $paragraphText, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$nQelements = count($quotation);
	//	OPTIONAL by settings(0) "Single unquoted paragraph does not change speaker"
	if ($settings[0]['settingValue'] === "True")
		{
		if ($nNoQuotesParags === 1)
			$lastParagNoText = true;
		else
			{
			$lastParagNoText = false;
			if ($channel === "left")
				$channel = "right";
			else
				$channel = "left";
			}
		if ($nQelements < 2) // no quoted string found in paragraph
			$nNoQuotesParags++;  // so count
		else
			$nNoQuotesParags = 0;   // quoted string found in paragraph, so clear count
		}

	for ($quoteElement = 0; $quoteElement < $nQelements; $quoteElement++)
		{
		if (count($quotation) === 1)
			continue;
		if ($quotation[$quoteElement] === RQ)
			{
			$quotation[$quoteElement] = $quotation[$quoteElement] . $narrat;
			$quoteElement++;
			$depth--;
			continue;
			}
		if ($quotation[$quoteElement] === LQ)
			{
			// left smart quote found, so paragraph contains speech
			// or contains embedded quotation of narrator
			// [$quoteElement] is sitting on the the left quote mark
			$quoteNumber++;  // count quotes in this pargagraph for voice re-use check below
			if ($speaker === "she"
				OR $speaker === "She")
				$speakerSex = "female";
			elseif ($speaker === "he"
				OR $speaker === "He")
				$speakerSex = "male";
			elseif ($speaker === "I")
				$speakerSex = $narrationSex;
//			elseif ($speaker === "")
//				{
//				$speaker = "m1 ";
//				$speakerSex = "male";
//				}
			// try to determine sex of speaker	:
			// set up for getVerbNameSex() routine
			$thisText = "";
			$nextText = "";
			if (array_key_exists($quoteElement - 1, $quotation))
				{
				// there is possibly prev unquoted text in this paragraph
				$prevText = $quotation[$quoteElement - 1];
//				if (mb_substr(trim($prevText), -1, 1) === ',')
//				// the previous text ends in a comma, indicating it most likely has the speaker in it
//					$examinePrevFirst = true;
//				else
//				// it probably doesn't have the speaker in it
//					$examinePrevFirst = false;
				}
			else
				{
				$prevText = '';
//				$examinePrevFirst = false;
				}
			if (array_key_exists($quoteElement + 1, $quotation))
				$thisText = $quotation[$quoteElement + 1];  // needed only for debugging								
			if (array_key_exists($quoteElement + 3, $quotation))
				$nextText .= $quotation[$quoteElement + 3];

			$voiceSource = "";

			if ($quoteNumber === 1)
				{
				//  $speaker and $verbFound may be modified
				$speakerSex = getVerbNameSex($nextText, $prevText, $speaker, true, $verbFound);
				if ($voiceDebug)
					addDebugToPRE("<b>getVerbNameSex:</b> $speaker $speakerSex $verbFound.", $paragraphNumber);
				if ($speaker === ""
					OR $speakerSex === "ambiguous"
					OR isPronoun($speaker))
					{
					$speakerspecified = 'N';
					if ($voiceDebug)
						addDebugToPRE(" <b>Non-expicit</b> ", $paragraphNumber);
					}
				else
					{
					$speakerspecified = 'Y';
					if ($voiceDebug)
						addDebugToPRE(" <b>Expicit</b> ", $paragraphNumber, true);
					}
				accessDB($readBookDB, "REPLACE INTO $theseVoiceChanges VALUES(?, ?)", $paragraphNumber, $speakerspecified);
				if (!(speakerSeenBefore($speaker)))
					{
					if ($voiceDebug)
						addDebugToPRE(" <b>Not seen before  ", $paragraphNumber);
					if ($speakerSex === 'female')
						{
						$voiceSource .= " Alternate1";
						$voice = getVoiceCommand($antecedentFemale);
						if ($voiceDebug)
							addDebugToPRE("  Voice set to $voice.</b> ", $paragraphNumber);
						}
					elseif ($speakerSex === 'male')
						{
						$voiceSource .= " Alternate2";
						$voice = getVoiceCommand($antecedentMale);
						if ($voiceDebug)
							addDebugToPRE(" <b> Voice set to $voice.</b> ", $paragraphNumber);
						}
					else
						{
						if ($paragraphNumber >= 50)
							$breakpoint = 1;
						$voice = $prevPrevVoice;
						$voiceSource .= " Alternate3";
						if ($voiceDebug)
							addDebugToPRE(" <b> Voice set to prevPrevVoice ($voice)</b> as $voiceSource. ", $paragraphNumber);
						}
					}
				else
					{
					$voice = getVoiceCommand($speaker);
					$voiceSource .= " Database";
					if ($voiceDebug)
						addDebugToPRE(" <b>Spreaker ($speaker) seen before. Voice set to $voice</b> by getVoiceCommand from $voiceSource. ", $paragraphNumber);
					}
				if (isPronoun($speaker))
					{
					if ($voiceDebug)
						addDebugToPRE(" <b> Spreaker ($speaker) is pronoun.</b> ", $paragraphNumber);
					if ($voiceDebug)
						addDebugToPRE(" <b>antecedent NOT SET for </b> $speaker ", $paragraphNumber);
					}
				else
					{
					if ($voiceDebug)
						addDebugToPRE(" <b> Speaker ($speaker) is not pronoun.</b> ", $paragraphNumber);
					if ($speakerSex === 'male'
						AND $speaker !== $antecedentMale)
						{
						$prevAntecedentMale = $antecedentMale;
						$antecedentMale = $speaker;
						if ($voiceDebug)
							addDebugToPRE(" <b> Spreaker ($speaker) is male, set to $antecedentMale</b>", $paragraphNumber);
						}
					else if ($speakerSex === 'female'
						AND $speaker !== $antecedentFemale)
						{
						$prevAntecedentFemale = $antecedentFemale;
						$antecedentFemale = $speaker;
						if ($voiceDebug)
							addDebugToPRE(" <b> Speaker ($speaker) is female, set to $antecedentFemale</b>", $paragraphNumber);
						}
					}
				}
			if ($prevDepth > 0)
				{
				$voice = $prevParagraphsVoice;
				if ($voiceDebug)
					addDebugToPRE("<b>Continuing Speech:</b> setting voice to $prevParagraphsVoice.", $paragraphNumber);
				}
			$quotation[$quoteElement + 1] = $voice . Lbrack . "c:$channel" . Rbrack . $quotation[$quoteElement + 1];
			if ($quoteNumber === 1)
				{
				$prevParagraphsVoice = $voice;
				if ($voice !== $prevVoice)
//				AND $voice !== $narrVoice)
					{
					$prevPrevVoice = $prevVoice;
					$prevVoice = $voice;
					$text = " <b>Set  prevVoice to $prevVoice.</b>";
					if ($voiceDebug)
						addDebugToPRE($text, $paragraphNumber);
					}
				$sp = getSpeakerFromVoice($voice);
				$text = "[Spkr: $sp, $voice]</sub> ";
				if ($voiceDebug) // initial sub added to PRE above
					addDebugToPRE($text, $paragraphNumber);
				else // initial sub not present in PRE
					addDebugToPRE("<sub>" . $text, $paragraphNumber);
				}
			$quoteElement++;
			$depth++;
			}
		}
	$prevDepth = $depth;
	$paragraphText = implode('', $quotation);
	$paragraphText = insertPauses($paragraphText);
	}

// ==============================================================================================================================
$debugAdded = array();

function addDebugToPRE($text, $paragraphNumber)
	{
	global $bookID;
	global $phase;
	global $thisDir;

	$ignoreLit = '/(<sub.*sub>)/u';
	$pre = file_get_contents("$thisDir/$bookID/PRE/$paragraphNumber.txt");
	if ($phase === 'reprocess')
		$pre = preg_replace($ignoreLit, "", $pre);
	file_put_contents("$thisDir/$bookID/PRE/$paragraphNumber.txt", $pre . $text);
	}

function speakerSeenBefore($speaker)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	global $theseNewWords;

	$speaker = trim($speaker);
	if ($speaker === "")
		return false;
	$data = accessDB($readBookDB, "SELECT
	  label
	FROM
	  $theseMales
	WHERE label = '$speaker'
	UNION ALL
	SELECT
	  label
	FROM
	  $theseFemales
	WHERE label = '$speaker'
	UNION ALL
	SELECT
	  label
	FROM
	  $theseNewWords
	WHERE label = '$speaker'");
	if (count($data) === 1)
		return true;
	return false;
	}

function getSpeakerFromVoice($voice)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	global $theseNewWords;

	$data = accessDB($readBookDB, "SELECT
	  label
	FROM
	  $theseMales
	WHERE voice = '$voice'
	UNION ALL
	SELECT
	  label
	FROM
	  $theseFemales
	WHERE voice = '$voice'
	UNION ALL
	SELECT
	  label
	FROM
	  $theseNewWords
	WHERE voice = '$voice'");
	if (count($data) === 1)
		return $data[0]['label'];
	for ($x = 0; $x < count($data); $x++)
		{
		if ($data[$x]['label'] === 'narrator')
			continue;
		return $data[$x]['label'];
		}
	return 'narrator';
//	logMsg("Software error: readBook line " . __LINE__, "Error");
	}

function getSexFromVoice($voice)
	{
	global $readBookDB;

	$end = mb_strpos($voice, '⦄');
	if ($end === false)
//		return "";
		logMsg("Software error: readBook line " . __LINE__, "Error");
	$v = (int) mb_substr($voice, 3, $end - 3);
	$sexData = accessDB($readBookDB, "SELECT sex FROM voice_params WHERE voice_number = ? AND disabled = 0", $v);
	$sex = $sexData[0]['sex'];
	return $sex;
	}

function editNames()
	{
	global $readBookDB;
	global $bookID;
	global $theseNewWords;
	global $theseMales;
	global $theseFemales;
	global $theseNotNames;
	global $phase;

//	$newWords = accessDB($readBookDB, "SELECT label FROM $theseNewWords WHERE count = 1");
//	$wordCount = count($newWords);
//	for ($x=0; $x<$wordCount; $x++)
//		{
//		$word = $newWords[$x]['label'];
//		accessDB($readBookDB, "REPLACE INTO $theseNotNames VALUES(?, ?)", $word, 0);
//		}

	accessDB($readBookDB, "DELETE FROM  $theseNewWords WHERE count < 1");
	$newWords = accessDB($readBookDB, "SELECT * FROM $theseNewWords ORDER BY count DESC");
	$wordCount = count($newWords);
	echo "</div>";
	echo "<div id=stepOneDiv>";
	echo "\n<select class='box' id=wordBox onfocus=this.selectedIndex=0 onchange=chosenPossibleName(this.value) >";
	echo "<option value=''>Possible Names</option>\n";
	for ($x = 0; $x < $wordCount; $x++)
		{
		$thisWord = $newWords[$x];

		$count = $thisWord['count'];
//		if ($count < 2)
//			continue;
		$v = $thisWord['voice'];
		$voice = preg_replace('/(⦄)/u', '', $v);
		$voice = mb_substr($voice, 3);
		$context = rawurlencode($thisWord['context']);
		$display = "$thisWord[label] (n=$count)";
		$value = "$bookID@@$thisWord[label]@@$count@@$context";
		$value = str_replace("'", "`", $value);  // kill single straight quotes
		echo "<option value='$value'>$display</option>\n";
		}
//	echo "<option value='Done'>Unassign all others.</option>\n";
	$maleLit = "Male";
	$femaleLit = "Female";
	$notLit = "Not";
	$verbLit = "Verb";
//	$nounLit = "Noun";
	echo "</select></div>";
	echo "<div hidden id=nameButtons style=text-align:center;background-color:BurlyWood>";
	echo "<p style=\"margin:2px\"> For this Book</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseMales', '$maleLit')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseFemales', '$femaleLit')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseNotNames', '$notLit')\">Not Name</button>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> Learn for ALL Books</p>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('males', '$maleLit')\">Male</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('females', '$femaleLit')\">Female</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('verbs', '$verbLit')\">$verbLit</button>";
//	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('nouns', '$nounLit')\">$nounLit</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('notName', '$notLit')\">Not Any</button>";
	echo "<hr>";
	echo "<div id=context1 style=font-size:2.5vh;text-align:left;padding:2vh;margin:3vh;>";
	echo "</div>";
	echo "<div style=margin:0;text-align:center;background-color:BurlyWood>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> For this Book</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseMales', '$maleLit')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseFemales', '$femaleLit')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseNotNames', '$notLit')\">Not Name</button>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> Learn for ALL Books</p>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('males', '$maleLit')\">Male</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('females', '$femaleLit')\">Female</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('verbs', '$verbLit')\">$verbLit</button>";
//	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('nouns', '$nounLit')\">$nounLit</button>";
	echo "<button type=button class=tinyButton onclick=\"changeVoiceAll('notName', '$notLit')\">Not Any</button>";
	echo "<hr>";

	accessDB($readBookDB, "DELETE FROM  $theseMales WHERE count < 2");
	accessDB($readBookDB, "DELETE FROM  $theseFemales WHERE count < 2");
	$names = accessDB($readBookDB, "SELECT * FROM $theseMales ORDER BY count DESC");
	$mCount = count($names);
	$names = array_merge($names, accessDB($readBookDB, "SELECT * FROM $theseFemales ORDER BY count DESC;"));
	$fCount = count($names);
	echo "</div></div>";
	echo "<br><br><select class='box' id=nameBox1 onfocus=this.selectedIndex=0 onchange='rememberName(this.value)' >";
	echo "<option value=''>Equate Names</option>\n";
	for ($x = 0; $x < $fCount; $x++)
		{
		$entry = $names[$x];
		if ($entry['label'] === '')
			continue;
		if ($x < $mCount)
			$gender = 'Male';
		elseif ($x < $fCount)
			$gender = 'Female';
		$count = $entry['count'];
		$display = "Equate $gender $entry[label] ($entry[count]) . . .";
		$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender[0]";
		$value = str_replace("'", "`", $value);  // kill single straight quotes
		echo "<option value='$value'>$display</option>\n";
		}
	echo "</select></div></div><br>";
	echo "<div>";
	echo "<select class='box' id=nameBox1a onfocus=this.selectedIndex=0 onchange='equateName(this.value)' >";
	echo "<option value=''>To . . . </option>\n";
	$prevAlias = '';
	for ($x = 0; $x < $fCount; $x++)
		{
		$thisNameArray = $names[$x];
		$name = $thisNameArray['label'];
		if ($x < $mCount)
			{
			$table = $theseMales;
			$gender = 'Male';
			}
		else
			{
			$table = $theseFemales;
			$gender = 'Female';
			}
		$value = "$name@@$table";
		$display = "Equate to $gender $name";
		echo "<option value='$value'>$display</option>\n";
		$prevAlias = $thisAlias;
		}
	echo "</select>";
	echo "<br><br><br><select class='box' id=nameBox2 onfocus=this.selectedIndex=0 onchange='rememberName(this.value)' >";
	echo "<option value=''>Change Speaking Manner</option>\n";
	for ($x = 0; $x < $fCount; $x++)
		{
		$entry = $names[$x];
		if ($entry['label'] === '')
			continue;
		if ($x < $mCount)
			$gender = 'Male';
		elseif ($x < $fCount)
			$gender = 'Female';
		if ($entry['recipe']
			AND $entry['recipe'] !== "")
			$recipe = "(Now " . $entry['recipe'] . ")";
		else
			$recipe = "";
		$count = $entry['count'];
		$display = "Manner for $gender $entry[label] ($entry[count]) . . . $recipe";
		$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender";
		$value = str_replace("'", "`", $value);  // kill single straight quotes
		echo "<option value='$value'>$display</option>\n";
		}
	echo "</select></div></div><br>";
	echo "<div >";
	$recipes = accessDB($readBookDB, "SELECT name FROM recipes WHERE manner = 'manner' ORDER BY name");
	$recipeCount = count($recipes);
	echo "<select class='box' onfocus=this.selectedIndex=0 onchange='setRecipe(this.value)'>";
	echo "<option value=''>To . . . </option>\n";
	for ($x = 0; $x < $recipeCount; $x++)
		{
		$name = $recipes[$x]['name'];
		$value = "$name@@$theseMales@@$theseFemales";
		$display = "Speak as if $name";
		echo "<option value='$value'>$display</option>\n";
		}
	echo "</select><br><br>";
	echo "</div>";
	echo "<br</div></div></div>";
	return;
	}

function editMisc()
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	global $theseNewWords;
	global $theseNotNames;
	global $bookID;

	echo "<div id=modifyNames style=background-image: radial-gradient(yellow,green);>";
	echo "<div style=text-align:left>";
	echo "<hr><p><b>DEFINE NEW ITEMS</b></p><input type=text placeholder='VERB' id=newVerb onchange=newVerb(this.value)>";
	echo "<input type=text placeholder='MALE' id=newMale onchange=newMale(this.value)>";
	echo "<input type=text placeholder='FEMALE' id=newFemale onchange=newFemale(this.value)>";
	echo "<input type=text class=wideInput placeholder='SUBSTITUTE FOR ...' id=newFor>";
	echo "<input type=text class=wideInput placeholder='THIS ...' id=newSubstitute onchange=newSubstitute(this.value)>";
	echo "<input type=text class=wideInput placeholder='CONNECTIVE' id=newConnective onchange=newConnective(this.value)><br>";
	echo "</div>";
	echo "</div>";
	?>
	</div>
	<?php
	ob_end_flush();
	return;
	}

function insertPauses($txt)
	{
	$sentences = preg_split('/([!\?\.][ ”])/u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	if (!(is_array($sentences)))
		return($txt);
	$l = 0;
	for ($i = 1; $i < count($sentences) - 3; $i++)
		{ // ignore last sentence and delimiter
		$isSentence = true;
		$sent = $sentences[$i];
		if (strlen($sent) > 0)
			{
			if ($sent[0] === '.')
				{
				$sent = $sentences[$i - 1];
				if (mb_strlen($sent) === 1)
					{
					$isSentence = false;
					continue;
					}
				$lastChar = substr($sent, -1);
				$nextLastChar = substr($sent, -2, 1);
				if (($lastChar >= "A") AND ($lastChar <= "Z")
					OR ($nextLastChar >= "A") AND ($nextLastChar <= "Z")
				)
					$isSentence = false;  // this period is not a sentence terminator. skip it.
				elseif (($lastChar >= '0') AND ($lastChar <= '9'))
					$isSentence = false;  // this period is not a sentence terminator. skip it.
				}
			$k = str_word_count($sentences[$i - 1]);
			$l += $k;
			if (($k > 20)
				OR ($l > 30))
				{
				if ($isSentence)
					{
					$l = 0;
					$sentences[$i] = Lbrack . "  w:444" . Rbrack . $sentences[$i];
//					$sentences[$i] = " ; " . $sentences[$i];
					}
				}
			}
		}
	return(implode('', $sentences));
	}

function substituteDivs(&$txt)
	{
	$txt = preg_replace('/<div.*?>/iu', "<p>", $txt);
	$txt = preg_replace('/<\/div>/iu', "</p>", $txt);
	}

function simplifyAnyHR(&$txt)
	{
	$txt = preg_replace('/<hr.*?>/iu', "", $txt);
	$txt = preg_replace('/<\/hr>/iu', "", $txt);
	}

function deleteSections(&$txt)
	{
	$txt = preg_replace('/<section.*?>/iu', "", $txt);
	$txt = preg_replace('/<\/section>/iu', "", $txt);
	}

function getRootWord($word)
	{
	// apostrophe
	$rootWord = preg_replace('/(.*?)[’\'].+$/', '\1', $word);
	if ($rootWord !== $word)
		$breakpoint = 1;
//	// 5 letter suffixes
//	$rootWord = preg_replace('/(.*?)bbing$/', '\1b', $rootWord); // bbing
//if ($rootWord !== $word)
//  $breakpoint=1;
//	$rootWord = preg_replace('/(.*?)dding$/', '\1d', $rootWord); // dding
//if ($rootWord !== $word)
//  $breakpoint=1;
//	$rootWord = preg_replace('/(.*?)pping$/', '\1p', $rootWord); // pping
//if ($rootWord !== $word)
//  $breakpoint=1;
//	$rootWord = preg_replace('/(.*?)tting$/', '\1t', $rootWord); // tting
	// 4 letter suffixes
	$rootWord = preg_replace('/(.*?)bbed$/', '\1b', $rootWord); // bbed
	if ($rootWord !== $word)
		$breakpoint = 1;
	$rootWord = preg_replace('/(.*?)dded$/', '\1d', $rootWord); // dded
	if ($rootWord !== $word)
		$breakpoint = 1;
	$rootWord = preg_replace('/(.*?)pped$/', '\1p', $rootWord); // pped
	if ($rootWord !== $word)
		$breakpoint = 1;
	$rootWord = preg_replace('/(.*?)tted$/', '\1t', $rootWord); // tted
	if ($rootWord !== $word)
		$breakpoint = 1;
	// 3 letter suffixes
	$rootWord = preg_replace('/(.*?)n[’\']t$/', '\1', $rootWord); // n't
	if ($rootWord !== $word)
		$breakpoint = 1;
//	$rootWord = preg_replace('/(.*?)ies$/', '\1y', $rootWord); // ies
//if ($rootWord !== $word)
//  $breakpoint=1;
//	$rootWord = preg_replace('/(.*?)ie[sd]$/', '\1y', $rootWord); // ies and ied
//	$rootWord = preg_replace('/(.*?)ing$/', '\1', $rootWord);  // ing
//if ($rootWord !== $word)
//  $breakpoint=1;
	// 2 letter suffixes
	$rootWord = preg_replace('/(.*?)ed$/', '\1', $rootWord);
	if ($rootWord !== $word)
		$breakpoint = 1;
//	$rootWord = preg_replace('/(.*?)es$/', '\1', $rootWord);
	// 1 letter suffixes
//	$rootWord = preg_replace('/(.*?)s$/', '\1', $rootWord);
	return $rootWord;
	}

function isSAIDword($word)
	{
	global $readBookDB;
	global $theseNewWords;
	global $theseMales;
	global $theseFemales;
	$word = trim($word);
	$labels = accessDB($readBookDB, "SELECT * FROM verbs WHERE label = ?", $word);
	if (count($labels) > 0)
		{
		if (strpos($labels[0]['usage'], "(verb)") !== false)
			return true;  // primarily a verb
		else
			return false; // possibly not a verb
		}
	if (in_any_DB($readBookDB, $word, $theseNewWords, 'males', 'females', $theseMales, $theseFemales, 'connectives'))
		return false;
	elseif (strlen($word) > 3)  // try to find base word
		{
		$rootWord = getRootWord($word);
//		// apostrophe
//		$rootWord = preg_replace('/(.*?)[’\'].+$/', '\1', $word);
//		// 5 letter suffixes
//		$rootWord = preg_replace('/(.*?)bbing$/', '\1b', $rootWord); // bbing
//		$rootWord = preg_replace('/(.*?)dding$/', '\1d', $rootWord); // dding
//		$rootWord = preg_replace('/(.*?)pping$/', '\1p', $rootWord); // pping
//		$rootWord = preg_replace('/(.*?)tting$/', '\1t', $rootWord); // tting
//		// 4 letter suffixes
//		$rootWord = preg_replace('/(.*?)bbed$/', '\1b', $rootWord); // bbed
//		$rootWord = preg_replace('/(.*?)dded$/', '\1d', $rootWord); // dded
//		$rootWord = preg_replace('/(.*?)pped$/', '\1p', $rootWord); // pped
//		$rootWord = preg_replace('/(.*?)tted$/', '\1t', $rootWord); // tted
//		// 3 letter suffixes
//		$rootWord = preg_replace('/(.*?)n[’\']t$/', '\1', $rootWord); // n't
//		$rootWord = preg_replace('/(.*?)ie[sd]$/', '\1y', $rootWord); // ies and ied
//		$rootWord = preg_replace('/(.*?)ing$/', '\1', $rootWord);  // ing
//		// 2 letter suffixes
//		$rootWord = preg_replace('/(.*?)ed$/', '\1', $rootWord);
//		$rootWord = preg_replace('/(.*?)es$/', '\1', $rootWord);
//		// 1 letter suffixes
//		$rootWord = preg_replace('/(.*?)s$/', '\1', $rootWord);
		if ($rootWord !== $word)
			{
			$labels = accessDB($readBookDB, "SELECT * FROM verbs WHERE label = ?", $rootWord);
			if (count($labels) > 0)
				return true; // a verb
			else
				{
				$rootWord .= 'e';
				$labels = accessDB($readBookDB, "SELECT * FROM verbs WHERE label = ?", $rootWord);
				if (count($labels) > 0)
					return true; // a verb
				}
			}
		}
	return false;
	}

function in_DBcount($readBookDB, $name, $table)
	{
	$labels = accessDB($readBookDB, "SELECT * FROM $table WHERE label = ?", $name);
	if (count($labels) === 0)
		return false; // not in database
	// it is already present in the DB, so add 1, either to name or Alias
	$alias = $labels[0]['label'];  // might be name or Alias
	accessDB($readBookDB, "UPDATE $table SET count = count+1 WHERE label = ?", $alias);
	return true;
	}

function in_any_DB($readBookDB, $word, ...$tables)
	{
	if (trim($word) === "")
		return false;
	$sql = "";
	foreach ($tables as $table)
		{
		// build SELECT statement
		$sql .= "SELECT label FROM $table WHERE label = '$word' UNION ALL ";
		}
	$sql = mb_substr($sql, 0, mb_strlen($sql) - 11);
	$labels = accessDB($readBookDB, $sql);
	if (count($labels) === 0)
		return false; // not in database
	return true;
	}

function in_DB($readBookDB, $word, $table)
	{
	$labels = accessDB($readBookDB, "SELECT * FROM $table WHERE label = ?", $word);
	if (count($labels) === 0)
		return false; // not in database
	return true;
	}

function replicateInto($table, $speaker, $fromSex)
	{
	global $readBookDB;
	// don't replicate into "these" databases any non-specific labels
	if (isPronoun($speaker))
		return $fromSex;
	accessDB($readBookDB, "REPLACE INTO $table VALUES(?, ?, ?, ?, ?,?,?,?)", $speaker, '', '', 1, '', "", __LINE__, "");
	return($fromSex);
	}

function sexByFirstOrOnlyName($speaker)
	{
	global $prevParagraphStack;
	global $prevParagraphStackSize;
	global $readBookDB;
	global $narrationSex;
	global $theseNewWords;
	global $theseMales;
	global $theseFemales;
	global $theseNotNames;
	global $phase;

	$sex = 'Undetermined';
	if (strlen($speaker) > 2)
		{
		$quotePos1 = mb_strpos($speaker, '’');
		$quotePos2 = mb_strpos($speaker, "'");
		if ($quotePos1 !== false)
			{
			if ($quotePos1 > 0)
				$speaker = mb_substr($speaker, 0, $quotePos1);
			}
		elseif ($quotePos2 !== false)
			{
			if ($quotePos2 > 0)
				$speaker = mb_substr($speaker, 0, $quotePos2);
			}
		}
	if (strlen($speaker) < 1)
		return "";
	$pos = mb_strpos($speaker, " ");
	if ($pos !== false)
		$soughtName = mb_substr($speaker, 0, $pos);
	else
		$soughtName = $speaker;
	$lowerName = mb_strtolower($soughtName);
	if (in_any_DB($readBookDB, $lowerName, $theseNotNames, 'verbs', 'notName'))
		return "";
	if (in_any_DB($readBookDB, $soughtName, $theseNotNames, 'verbs', 'notName'))
		return "";

	if ($soughtName === "I")
		{
		// special treatment of "I"
		$sex = $narrationSex;  // the sex of the narrator
		if ($sex === "male")
			$table = $theseMales;
		else
			$table = $theseFemales;
		if (!(in_DB($readBookDB, $soughtName, $table)))
			accessDB($readBookDB, "REPLACE INTO $table VALUES(?, ?, ?, ?, ?,?,?,?)", $soughtName, '', '', 1, '', "", __LINE__, "");
		else
			in_DBcount($readBookDB, $soughtName, $table);
		return $sex;
		}
	// use "these" DBs before normal ones
	elseif (in_DBcount($readBookDB, $soughtName, $theseMales))
		$sex = 'male';
	elseif (in_DBcount($readBookDB, $soughtName, $theseFemales))
		$sex = 'female';
	elseif (in_DBcount($readBookDB, $soughtName, $theseNewWords))
		{
		$data = accessDB($readBookDB, "SELECT count FROM $theseNewWords WHERE label = '$soughtName'");
		if ($data[0]['count'] < 0)
			return 'Undetermined';
		$sex = 'ambiguous';
		}
	elseif (in_DBcount($readBookDB, $soughtName, 'males'))
		$sex = replicateInto($theseMales, $soughtName, 'male');
	elseif (in_DBcount($readBookDB, $soughtName, 'females'))
		$sex = replicateInto($theseFemales, $soughtName, 'female');
	else
		{
		$sex = "ambiguous";
		$v = "";
		$context = "}}^";
		for ($x = 0; $x < $prevParagraphStackSize; $x++)
			$context .= "^$prevParagraphStack[$x]";
		accessDB($readBookDB, "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?,?,?,?)", "$soughtName", '', "", 1, $v, "$context", __LINE__, "");
		$v = getVoiceOfKnownNewWords($soughtName, $v);
		accessDB($readBookDB, "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$soughtName'");
		}
	return $sex;
	}

function isPronoun($word)
	{
	$word = trim($word);
	if ($word === "he"
		OR $word === "He"
		OR $word === "she"
		OR $word === "She")
		return true;
	return false;
	}

function findPronoun($word, &$sex)
	{
	$word = trim($word);
	if ($word === "he"
		OR $word === "He")
		{
		$sex = "male";
		return $word;
		}
	if ($word === "she"
		OR $word === "She")
		{
		$sex = "female";
		return $word;
		}
	return "";
	}

function findUCspeakerOrPronoun($x, &$aThisSpeaker, $lastSubscript, &$closeness)
	{
	// find an upper case name or a SINGULAR personal pronoun (i.e., not it or they, LGBTQ+ pronouns not considered)
	// if found, set $aThisSpeaker and $closeness
	// return $sex

	global $words;
	global $readBookDB;
	global $possiblePreposition;
// EXAMINE WORDS TO LEFT OF VERB
	$aThisSpeaker = "ucNone";
	$sex = "Undetermined"; // in case for immediately satisfied
	for ($y = $x - 1; $y != -1; $y--)
		{
		$sex = "Undetermined";   // in case a continue was executed
		$possiblePreposition = "";
		$char1 = mb_substr($words[$y], 0, 1);
		$delimiter = mb_substr($words[$y + 1], 0, 1); // array entry known to exist
		$pronoun = findPronoun($words[$y], $sex);  // $sex is set if pronoun, $pronoun found is returned
		if ($pronoun > "" // it's a pronoun
			OR (($char1 >= "A") AND ($char1 <= "Z"))) // it's a capitalized word
			{
			$aThisSpeaker = $words[$y];   // might be pronoun or an apostophe
			if (array_key_exists($y - 2, $words))
			// if the word is affected by preposition, it's not the speaker
			// e.g. she said to Bill ...
				$possiblePreposition = $words[$y - 2]; // if the word is affected by preposition, it's not the speaker
			if ($pronoun === "")
				{
				// if not pronoun, it's a capitalized word
				if ($delimiter === "'"
					OR $delimiter === "’")
					continue;  // it's an apostrophe. so if it's a name, is possessive and not a speaker
//				if ($aThisSpeaker !== 'I')
//					checkDoubleName($words, $y, $aThisSpeaker);  // might change $possiblePreposition
				$sex = sexByFirstOrOnlyName($aThisSpeaker);
				}
//			else
			// it was a pronoun, so sex is knowm
			if (trim($possiblePreposition) !== "")
				if (in_DBcount($readBookDB, $possiblePreposition, "connectives"))
					continue;  // ignore anything we might have found

				if ($sex !== "")
				{
				$closeness = $x - $y;
				return $sex;
				}
			}
		}
// EXAMINE WORDS TO RIGHT OF VERB
	$aThisSpeaker = "ucNone";
	$sex = "Undetermined";  // in case for satisfied immediately
	for ($y = $x + 1; $y != $lastSubscript + 1; $y++)
		{
		$sex = "Undetermined";   // in case a continue was executed
		$possiblePreposition = "";
		if (array_key_exists($y + 1, $words))
			$delimiter = mb_substr($words[$y + 1], 0, 1);
		else
			$delimiter = "";
		$char1 = substr($words[$y], 0, 1);
		$pronoun = findPronoun($words[$y], $sex);
		if ($pronoun > ""
			OR (($char1 >= "A") AND ($char1 <= "Z")))
			{
			$aThisSpeaker = $words[$y];
			if (array_key_exists($y - 2, $words))
				$possiblePreposition = $words[$y - 2];
			if ($pronoun === "")
				{
				if ($delimiter === "'"
					OR $delimiter === "’")
					continue;
				// it's an apostrophe. so if it's a name, is possessive and not a speaker
				$sex = sexByFirstOrOnlyName($aThisSpeaker);
				}
			if (trim($possiblePreposition) !== "")
				if (in_DBcount($readBookDB, $possiblePreposition, "connectives"))
					continue;

			if ($sex !== "")
				{
				$closeness = $x - $y;
				return $sex;
				}
			}
		}
	return "";
	}

function findLCspeaker($x, &$aThisSpeaker, $lastSubscript, &$closeness)
	{
	// if found, set $aThisSpeaker and $closeness
	// return $sex

	global $words;
	global $readBookDB;
	$aThisSpeaker = "lcNone";
	$sex = "";
	for ($y = $x - 1; $y != -1; $y--) // moving left
		{
		$word = $words[$y];
		if ($word !== ""
			AND $word[0] >= 'a') // lower case ASCII
			{
			if (!(in_DB($readBookDB, $word, 'verbs')))
				{
				$sex = sexByFirstOrOnlyName($words[$y]);
				if ($sex !== "")
					{
					$closeness = $x - $y;
					$aThisSpeaker = $word;
					return $sex;
					}
				}
			}
		}
	for ($y = $x + 1; $y != $lastSubscript + 1; $y++) // moving right
		{
		$word = $words[$y];
		if ($word !== ""
			AND $word[0] >= 'a') // lower case ASCII
			{
			if (!(in_DB($readBookDB, $word, 'verbs')))
				{
				if ($word === 'minimum')
					$breakpoint = 1;
				$sex = sexByFirstOrOnlyName($words[$y]);
				if ($sex !== "")
					{
					$closeness = $y - $x;
					$aThisSpeaker = $word;
					return $sex;
					}
				}
			}
		}
	return "";
	}

function getVerbNameSex($nextText, $prevText, &$aThisSpeaker, $examinePrevFirst, &$verbFound)
	{
	// called consecutively through global $quotation, which are quoted sentences
	global $words;

	$ignoreLit = '/(⦃v.*?⦄|⦃r.*?⦄|⦃w.*?|⦃n.*?⦄|⦃d.*?⦄|⦃c.*?⦄|⦃p:|⦄)/u';
//	$pronounceLit = '/(⦃p:)(.*?)⦄/u';

	if ($nextText !== "")
		$nextText = preg_replace($ignoreLit, '', $nextText);
	if ($prevText !== "")
		$prevText = preg_replace($ignoreLit, '', $prevText);
	$nextText = trim($nextText);
	$prevText = trim($prevText);

	$splitLit = '/([\/\'’ ,\.!\?:—…])/u';

	if ($examinePrevFirst)
		{
		// EXAMINE PREVIOUS PHRASE FROM RIGHT TO LEFT
		$aThisSpeaker = '';
		$ret = "";
		$words = preg_split($splitLit, $prevText, NULL, PREG_SPLIT_DELIM_CAPTURE);
		$maxSubscript = count($words) - 1;
		for ($x = $maxSubscript; $x >= 0; $x--) // in prev text, move right to left, to find closest to $thisText
			{
			if (strlen($words[$x]) == 0)
				continue;
			if ($words[$x] == ' ')
				continue;

			if (isSAIDword($words[$x]))
				{
				$verbFound = $words[$x];
				if ($verbFound === 'kept')
					$breakpoint = 1;
				// if only one potential name found, use it
				// if two potential names are found, use the one closest to "said" verb
				// XXsex is returned.
				// if found, xThisSpeaker and $XXcloseness are set
				$UCspeaker = "";
				$UCcloseness = 999;
				$UCsex = findUCspeakerOrPronoun($UCstart = $x, $UCspeaker, $maxSubscript, $UCcloseness);
				if ($UCcloseness !== 999
					AND $UCsex !== "Undetermined")
					{
					// a potential Upper case speaker was found
					$aThisSpeaker = $UCspeaker;
					return $UCsex;
					}
				else
					{
					$LCspeaker = "";
					$LCcloseness = 999;
					$LCsex = findLCspeaker($LCstart = $x, $LCspeaker, $maxSubscript, $LCcloseness);
					if ($LCcloseness !== 999
						AND $LCsex !== "Undetermined") // a Lower but no Upper case potential speaker was found
						{
						$aThisSpeaker = $LCspeaker . ' ';
						return $LCsex;
						}
					}
				// else no potential speaker was found (yet)
				}
			}
		}
	// EXAMINE NEXT PHRASE FROM LEFT TO RIGHT
	$aThisSpeaker = '';
	$ret = "";
	$words = preg_split($splitLit, $nextText, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$maxSubscript = count($words) - 1;
	for ($x = 0; $x <= $maxSubscript; $x++) // in next text, move left to right, to find closest to $thisText
		{
		if (strlen($words[$x]) == 0)
			continue;
		if ($words[$x] == ' ')
			continue;
		if (isSAIDword($words[$x]))
			{
			$verbFound = $words[$x];
			// if only one potential name found, use it
			// if two potential names are found, use the one closest to "said" verb
			// XXsex is returned.
			// if found, xThisSpeaker and $XXcloseness are set
			$UCspeaker = "";
			$UCcloseness = 999;
			$UCsex = findUCspeakerOrPronoun($UCstart = $x, $UCspeaker, $maxSubscript, $UCcloseness);
			if ($UCcloseness !== 999)
//			AND $UCsex !== "Undetermined")
				{
				// a potential Upper case speaker was found
				$aThisSpeaker = $UCspeaker;
				return $UCsex;
				}
			else
				{
				$LCspeaker = "";
				$LCcloseness = 999;
				$LCsex = findLCspeaker($LCstart = $x, $LCspeaker, $maxSubscript, $LCcloseness);
				if ($LCcloseness !== 999
					AND $LCsex !== "Undetermined") // a Lower but no Upper case potential speaker was found
					{
					$aThisSpeaker = $LCspeaker;
					return $LCsex;
					}
				}
			// else no potential speaker was found (yet)
			}
		}
	if (!($examinePrevFirst))
		{
		// EXAMINE PREVIOUS PHRASE FROM RIGHT TO LEFT
		$aThisSpeaker = '';
		$ret = "";
		// NB NB The literal below is present THREE TIMES in this routine and they must match!!
		$words = preg_split($splitLit, $prevText, NULL, PREG_SPLIT_DELIM_CAPTURE);
		$maxSubscript = count($words) - 1;
		for ($x = $maxSubscript; $x >= 0; $x--) // in prev text, move right to left, to find closest to $thisText
			{
			if (strlen($words[$x]) == 0)
				continue;
			if ($words[$x] == ' ')
				continue;
			if (isSAIDword($words[$x]))
				{
				$verbFound = $words[$x];
				// if only one potential name found, use it
				// if two potential names are found, use the one closest to "said" verb
				// XXsex is returned.
				// if found, xThisSpeaker and $XXcloseness are set
				$UCspeaker = "";
				$UCcloseness = 999;
				$UCsex = findUCspeakerOrPronoun($UCstart = $x, $UCspeaker, $maxSubscript, $UCcloseness);
				if ($UCcloseness !== 999
					AND $UCsex !== "Undetermined")
					{
					// a potential  pronoun or Upper case speaker was found
					$aThisSpeaker = $UCspeaker;
					return $UCsex;
					}
				else
					{
					$LCspeaker = "";
					$LCcloseness = 999;
					$LCsex = findLCspeaker($LCstart = $x, $LCspeaker, $maxSubscript, $LCcloseness);
					if ($LCcloseness !== 999
						AND $LCsex !== "Undetermined") // a Lower but no Upper case potential speaker was found
						{
						$aThisSpeaker = $LCspeaker;
						return $LCsex;
						}
					}
				// else no potential speaker was found (yet)
				}
			}
		}
	$aThisSpeaker = "";
	return "Undetermined";
	}

function XMLstring($data)
	{
	// return string if string, or string in XML if XML
	if (is_string($data))
		{
		return($data);
		}
	else
		{
		// assume it's SimpleXMLElement
		$data = (array) $data;
		//echo "<br />displayXMLstring <br />".var_dump($data);
		$info = "";
		if (is_array($data))
			{
			foreach ($data as $element)
				{
				if ($info == "")
					$info = $element;
				else
					$info = $info . ", " . $element;
				}
			}
		return($info);
		}
	}

function simpleXML2Array($xml)
	{
	$json = json_encode($xml);
	$array = json_decode($json, TRUE);
	return $array;
	}

function rrmdir($src)
	{
	if (file_exists($src))
		{
		$dir = opendir($src);
		while (false !== ($file = readdir($dir)))
			{
			if (($file != '.')
				AND ($file != '..'))
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

function cleanOtherBookDataFiles($bookID)
	{
	$audioFiles = __DIR__ . "/../audio/";
	if (file_Exists($audioFiles))
		{
		// files only, contains multiple books, delete all not $bookID
		$dir = opendir($audioFiles);
		while (false !== ($file = readdir($dir)))
			if ($file !== '.'
				AND $file !== '..'
				AND !str_contains($file, $bookID))
				{
				$full = $thisDir . "/" . $file;
				unlink($full);
				}
		closedir($dir);
		}
	$bookDirs = "$thisDir\\";
	$dir = opendir($bookDirs);
	while (false !== ($file = readdir($dir))) // files and dirs, all not $bookID
		if (is_numeric($file)
			AND $file !== $bookID)
			rrmdir($file);
	closedir($dir);
	}

function reduceMonotany(&$htmBody)
	{
	// eliminate many phrases such as "he said". pretty difficult
	}

function handleWHquestions(&$parag)
	{
	global $interrogative;
	$sentences = preg_split('/([!\?\.⦃⦄])/u', $parag, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$sentences = preg_split('/(\?)/u', $parag, NULL, PREG_SPLIT_DELIM_CAPTURE);
	if (is_array($sentences))
		{
		// 'wh' words usually do not have a rising inflection at the end of the sentence
		// in which case we change '?' to '.'.
		$sentenceCount = count($sentences);
		for ($s = 1; $s < $sentenceCount; $s += 2)
			{
			if ($sentences[$s] !== "?")
				continue;
			// so it is a question in [$s-1]
			// which is known to exist
			$result = $interrogative->analyzeQuestion($sentences[$s - 1] . "?");
			if ($result['action'] != "keep")
				$sentences[$s] = ".";
			}
		}
	}

function getVoiceOfKnownMale($speaker)
	{
	global $readBookDB;
	global $theseMales;

	$v = accessDB($readBookDB, "SELECT * FROM $theseMales WHERE label='$speaker'");
	if (count($v) > 0) // speaker found in theseMales
		{
		$voice = $v[0]['voice'];
		$recipe = $v[0]['recipe'];
		if ($voice === "")  // but has no assignment
			{
			$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'male' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
			if (count($data) > 0)
				{
				$voiceNumber = $data[0]['voice_number'];
				$voice = Lbrack . "v:$voiceNumber" . Rbrack;
				accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
				accessDB($readBookDB, "UPDATE $theseMales SET voice = '$voice' WHERE label = '$speaker'");
				}
			}
		if ($recipe
			AND $recipe !== "")
			$voice .= Lbrack . "r:$recipe" . Rbrack;
		return $voice;
		}
	else
		return "";
	}

function getVoiceOfKnownFemale($speaker)
	{
	global $readBookDB;
	global $theseFemales;

	$v = accessDB($readBookDB, "SELECT * FROM $theseFemales WHERE label='$speaker'");
	if (count($v) > 0) // speaker found in theseFemales
		{
		$voice = $v[0]['voice'];
		$recipe = $v[0]['recipe'];
		if ($voice === "")  // but has no assignment
			{
			$data = accessDB($readBookDB, "SELECT voice_number FROM voice_params WHERE sex = 'female' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
			if (count($data) > 0)
				{
				$voiceNumber = $data[0]['voice_number'];
				$voice = Lbrack . "v:$voiceNumber" . Rbrack;
				accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
				accessDB($readBookDB, "UPDATE $theseFemales SET voice = '$voice' WHERE label = '$speaker'");
				}
			}
		if ($recipe
			AND $recipe !== "")
			$voice .= Lbrack . "r:$recipe" . Rbrack;
		return $voice;
		}
	else
		return "";
	}

function getVoiceOfKnownNewWords($speaker)
	{
	global $readBookDB;
	global $theseNewWords;
	global $narrVoice;

	if (mb_strlen($speaker) > 1)
		if ($speaker[1] === '1'
			OR $speaker[1] === '2')
			{
			if ($speaker[0] === 'm')
				{
				$v = $narrVoice . Lbrack . "r:masculine" . Rbrack;
				accessDB($readBookDB, "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$speaker'");
				return $v;
				}
			elseif ($speaker[0] === 'f')
				{
				$v = $narrVoice . Lbrack . "r:feminine" . Rbrack;
				accessDB($readBookDB, "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$speaker'");
				return $v;
				}
			}
	accessDB($readBookDB, "UPDATE $theseNewWords SET voice = '$narrVoice' WHERE label = '$speaker'");
	return $narrVoice;
	}

function getVoiceCommand(&$speaker)
	{
	global $paragraphNumber;

	$speaker = trim($speaker);
	if ($speaker === "")
		return "";
	$v = getVoiceOfKnownMale($speaker);
	if ($v !== "")
		{
		$sex = "male";
		return $v;
		}
	$v = getVoiceOfKnownFemale($speaker);
	if ($v !== "")
		{
		$sex = "female";
		return $v;
		}
	$v = getVoiceOfKnownNewWords($speaker);
	if ($v !== "")
		{
		$sex = "undetermined";
		return $v;
		}
	}

/**
 * High-speed routine to modify a large string in-place.
 * Converts "Chapter IV" -> "Chapter 4" AND "(iv)" -> "(4)".
 */
function polishDocumentNumerals(&$input)
	{
	if (strlen($input) > 300)
		return;
	// Regex Pattern:
	// Pattern A: \b(Keywords)\s+([ivxlcdm]++)\b  -> e.g. Chapter IV
	// Pattern B: \(([ivxlcdm]++)\)               -> e.g. (iv)
//	$pattern = "/\b(Chapter|Section|Part|Volume|Book|Appendix|Unit|Lesson|Plate)\s+([ivxlcdm]++)\b|\(([ivxlcdm]++)\)/i";

	// Regex Pattern:
	// \b(Keywords)\s+([ivxlcdm]++)\b  -> e.g. Chapter IV
	$pattern = "/\b(Chapter|Section|Part|Volume|Book|Appendix|Unit|Lesson|Plate)\s+([ivxlcdm]++)\b/iu";

	$input = preg_replace_callback($pattern, function ($matches)
		{
		static $cache = [];

		// Build 1-250 cache once
		if (empty($cache))
			{
			$romans = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
			for ($i = 1; $i <= 250; $i++)
				{
				$n = $i;
				$res = "";
				foreach ($romans as $r => $v)
					{
					$res .= str_repeat($r, intval($n / $v));
					$n %= $v;
					}
				$cache[$res] = $i;
				}
			}

		// Check which pattern matched
		if (!empty($matches[3]))
			{
			// It matched the parenthetical pattern: (iv)
			$numeral = strtoupper($matches[3]);
			return isset($cache[$numeral]) ? "(" . $cache[$numeral] . ")" : $matches[0];
			}
		else
			{
			// It matched the keyword pattern: Chapter IV
			$numeral = strtoupper($matches[2]);
			return isset($cache[$numeral]) ? $matches[1] . ' ' . $cache[$numeral] : $matches[0];
			}
		}, $input);
	}

function reverseMarkersForTTS(&$htmBody)
	{
	// Find the <img> tag and replace it with a wait
	$waitCommand = Lbrack . "w:2000" . Rbrack;
	if (strpos($htmBody, '<img') !== false)
		$htmBody = preg_replace('/<img.*?>/i', $waitCommand, $htmBody);
	$htmBody = preg_replace('/\n/', '', $htmBody);

	$textFront = mb_substr($htmBody, 0, 500);
	polishDocumentNumerals($textFront);
	$htmBody = $textFront . mb_substr($htmBody, 501);

	$ignoreLit = '/(⦃d:.*⦄)/u';
	$htmBody = preg_replace($ignoreLit, '', $htmBody);
	$commands = preg_split('/(⦃p:.*?⦄)/u', $htmBody, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$n = count($commands);
	if ($n > 1)
		{
		for ($x = 0; $x < $n; $x++)
			{
			$cmd = $commands[$x];
			if (mb_substr($cmd, 0, 3) !== "⦃p:")
				continue;
			$end = mb_strpos($cmd, '⦄');
			if ($end === false)
				logMsg("Software error: readBook line " . __LINE__, "Error");
			$commands[$x] = mb_substr($cmd, 3, $end - 3);
			}
		$htmBody = implode('', $commands);
		}
	$markers = preg_split('/( [\\x{e000}-\\x{e029}] )/u', $htmBody, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$n = count($markers);
	if ($n > 1)
		{
		for ($x = 0; $x < $n; $x++)
			{
			if (mb_strlen($markers[$x]) === 3)
				{
				switch ($markers[$x])
					{
					case ITALIC_OPEN:
						$markers[$x] = Lbrack . "r:italic" . Rbrack;
						break;
					case ITALIC_CLOSE:
						$markers[$x] = Lbrack . "x:" . Rbrack;
						break;
					case BOLD_OPEN:
						$markers[$x] = Lbrack . "r:bold" . Rbrack;
						break;
					case BOLD_CLOSE:
						$markers[$x] = Lbrack . "x:" . Rbrack;
						break;
					case UNDER_OPEN:
						$markers[$x] = Lbrack . "r:under" . Rbrack;
						break;
					case UNDER_CLOSE:
						$markers[$x] = Lbrack . "x:" . Rbrack;
						break;
//				case STRIKE_OPEN:
//				case STRIKE_CLOSE:
//				case INS_OPEN:
//				case INS_CLOSE:
//				case MARK_OPEN:
//				case MARK_CLOSE:
//				case SMALL_OPEN:
//				case SMALL_CLOSE:
//				case SUB_OPEN:
//					$markers[$x] = Lbrack . "r:sub" . Rbrack;
//					break;
//				case SUB_CLOSE:
//					$markers[$x] = Lbrack . "x:" . Rbrack;
//					break;
//				case SUP_OPEN:
//					$markers[$x] = Lbrack . "r:sup" . Rbrack;
//					break;
//				case SUP_CLOSE:
//					$markers[$x] = Lbrack . "x:" . Rbrack;
//					break;
//				case CODE_OPEN:
//				case CODE_CLOSE:
//				case KBD_OPEN:
//				case KBD_CLOSE:
//				case SAMP_OPEN:
//				case SAMP_CLOSE:
//				case VAR_OPEN:
//				case VAR_CLOSE:
//				case Q_OPEN:
//				case Q_CLOSE:
//				case CITE_OPEN:
//				case CITE_CLOSE:
//				case ABBR_OPEN:
//				case ABBR_CLOSE:
//				case TIME_OPEN:
//				case TIME_CLOSE:
//				case SPAN_OPEN:
//				case SPAN_CLOSE:
//				case CUSTOM_OPEN:
//				case CUSTOM_CLOSE:
					case EM_OPEN:
						$markers[$x] = Lbrack . "r:em" . Rbrack;
						break;
					case EM_CLOSE:
						$markers[$x] = Lbrack . "x:" . Rbrack;
						break;
					case STRONG_OPEN:
						$markers[$x] = Lbrack . "r:strong" . Rbrack;
						break;
					case STRONG_CLOSE:
						$markers[$x] = Lbrack . "x:" . Rbrack;
						break;
//					default:
//						$markers[$x] = "";
//						break;
					}
				}
			}
		$htmBody = implode('', $markers);
		}
	// special handling
	$htmBody = preg_replace('/<br>/', Lbrack . "  w:222" . Rbrack, $htmBody);
	$htmBody = preg_replace('/\b(hmm)\b/i', Lbrack . 'r:slow' . Rbrack . 'm' . Lbrack . "x:" . Rbrack, $htmBody);
	$htmBody = preg_replace('/\b(Ahh+?)\b/i', Lbrack . "r:slow" . Rbrack . "ah" . Lbrack . "x:" . Rbrack, $htmBody);
	$htmBody = preg_replace('/<.*?>/', '', $htmBody);
	$htmBody = preg_replace('/ex-/', 'x ', $htmBody);
	$htmBody = preg_replace('/-/', ' ', $htmBody);
	$htmBody = preg_replace('/—/', Lbrack . " w:99" . Rbrack, $htmBody);
	$htmBody = preg_replace('/…/', Lbrack . " w:99" . Rbrack, $htmBody);
	$htmBody = preg_replace('/ ‘/', Lbrack . " w:88" . Rbrack, $htmBody);
	}

function reverseMarkersForDisplay(&$input)
	{
	global $bookID;

	// This is the "Surgical Strike" for your specific path:
	// It changes src="Images/2.jpg" 
	// to src="76341/OEBPS/Images/2.jpg"
	$input = preg_replace('/src=["\']Images\//i', 'src="' . $bookID . '/OEBPS/Images/', $input);
	$input = preg_replace('/\n/', '', $input);

	// Keep your other marker logic below this
	$ignoreLit = '/(⦃v.*?⦄|⦃r.*?⦄|⦃w.*?|⦃n.*?⦄|⦃p.*?⦄|⦃c.*?⦄|⦃d:⦄)/u';
	$input = preg_replace($ignoreLit, '', $input);
	$ignoreLit = '/(⦃v.*?⦄|⦃r.*?⦄|⦃w.*?|⦃n.*?⦄|⦃p.*?⦄|⦃c.*?⦄|⦃d:⦄)/u';
	$input = preg_replace($ignoreLit, '', $input);

	$markers = preg_split('/( [\\x{e000}-\\x{e029}] )/u', $input, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$n = count($markers);
	if ($n > 1)
		{
		for ($x = 0; $x < $n; $x++)
			{
			if (mb_strlen($markers[$x]) === 3)
				{
				switch ($markers[$x])
					{
					case ITALIC_OPEN:
						$markers[$x] = " <i>";
						break;
					case ITALIC_CLOSE:
						$markers[$x] = "</i> ";
						break;
					case BOLD_OPEN:
						$markers[$x] = " <b>";
						break;
					case BOLD_CLOSE:
						$markers[$x] = "</b> ";
						break;
					case UNDER_OPEN:
						$markers[$x] = " <u>";
						break;
					case UNDER_CLOSE:
						$markers[$x] = "</u> ";
						break;
//				case STRIKE_OPEN:
//				case STRIKE_CLOSE:
//				case INS_OPEN:
//				case INS_CLOSE:
//				case MARK_OPEN:
//				case MARK_CLOSE:
//				case SMALL_OPEN:
//				case SMALL_CLOSE:
//				case SUB_OPEN:
//					$markers[$x] = Lbrack . "r:sub" . Rbrack;
//					break;
//				case SUB_CLOSE:
//					$markers[$x] = Lbrack . "x:" . Rbrack;
//					break;
//				case SUP_OPEN:
//					$markers[$x] = Lbrack . "r:sup" . Rbrack;
//					break;
//				case SUP_CLOSE:
//					$markers[$x] = Lbrack . "x:" . Rbrack;
//					break;
//				case CODE_OPEN:
//				case CODE_CLOSE:
//				case KBD_OPEN:
//				case KBD_CLOSE:
//				case SAMP_OPEN:
//				case SAMP_CLOSE:
//				case VAR_OPEN:
//				case VAR_CLOSE:
//				case Q_OPEN:
//				case Q_CLOSE:
//				case CITE_OPEN:
//				case CITE_CLOSE:
//				case ABBR_OPEN:
//				case ABBR_CLOSE:
//				case TIME_OPEN:
//				case TIME_CLOSE:
//				case SPAN_OPEN:
//				case SPAN_CLOSE:
//				case CUSTOM_OPEN:
//				case CUSTOM_CLOSE:
					case EM_OPEN:
						$markers[$x] = " <em>";
						break;
					case EM_CLOSE:
						$markers[$x] = "</em> ";
						break;
					case STRONG_OPEN:
						$markers[$x] = " <strong>";
						break;
					case STRONG_CLOSE:
						$markers[$x] = "</strong> ";
						break;
//					default:
//						$markers[$x] = "";
//						break;
					}
				}
			}
		$input = implode('', $markers);
		}
	}

function processAllNames(&$txt)
	{
// find names like O’Reilly and d’Antonio -- first char can be upper or lower, second is ’, third is capitalized
	$splits = preg_split('/([A-Za-z]’[A-Za-z]{3,}) /u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	if (count($splits) > 1)
		processNamesHavingQuotes($splits);

// find Double names
//	$splits = preg_split('/([^’][A-Z][a-z])/u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$splits = preg_split('/\b([A-Z][a-z]+ [A-Z][a-z]+)\b/u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$c = count($splits);
	if (count($splits) > 1)
		processTwoNames($splits, $c);
	}

function processOrdinaryNames(&$splits, $c)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;

	for ($x = 1; $x < $c; $x += 2)
		{
		$wholeName = trim($splits[$x]);
		if ($wholeName === "She"
			OR $wholeName === "He")
			continue;
		$lowercaseWord1 = mb_strtolower($wholeName);
		if (in_DB($readBookDB, $lowercaseWord1, "verbs")
			OR in_DB($readBookDB, $lowercaseWord1, "notName"))
			continue;
		if (in_DB($readBookDB, $wholeName, "males"))
			{
			$line = __LINE__;
			accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?, ?)", $wholeName, "", '', 0, "", "", __LINE__, "");
			accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?,?)", $wholeName, "", '', 0, "", "", __LINE__, "");
			accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		}
	}

function processTwoNames(&$splits, $c)
	{
	global $readBookDB;
	for ($x = 1; $x < $c; $x += 2)
		{
		$pos = mb_strpos($splits[$x], ' ');
		if ($pos !== false)
			{
			$wholeName = trim($splits[$x]);
			$firstName = mb_substr($wholeName, 0, $pos);
			$lastName = mb_substr($wholeName, $pos + 1);
			$lowercaseWord1 = mb_strtolower($firstName);
			if (in_DB($readBookDB, $lowercaseWord1, "verbs")
				OR in_DB($readBookDB, $lowercaseWord1, "notName"))
				continue;
			$lowercaseWord2 = mb_strtolower($lastName);
			if (in_DB($readBookDB, $lowercaseWord2, "verbs")
				OR in_DB($readBookDB, $lowercaseWord2, "notName"))
				continue;
			handleDoubleName($firstName, $lastName, $wholeName);
			}
		}
	}

function handleDoubleName($firstName, $lastName, $wholeName)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	global $theseNotNames;
	global $theseNewWords;
	global $prevParagraphStack;
	global $prevParagraphStackSize;

	if ($firstName === "She"
		OR $firstName === "He")
		return;
	$context = "}}^";
	for ($x = 0; $x < $prevParagraphStackSize; $x++)
		$context .= "^$prevParagraphStack[$x]";
	if (in_DB($readBookDB, $firstName, "males")) // only first names are in primary table, supposedly
		{
		if (in_DB($readBookDB, $lastName, $theseMales))
			{
			// delete any last name present
			accessDB($readBookDB, "DELETE FROM  $theseMales WHERE label =  '$lastName'");
			// and make sure not found later
			//		accessDB($readBookDB, "REPLACE INTO $theseNotNames VALUES(?, ?)", $lastName, 1);
			}
		if (!(in_DB($readBookDB, $firstName, $theseMales)))
			{
			// add with count of 0
			accessDB($readBookDB, "INSERT INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", '', 1, "", "", __LINE__, ""
			);
//getVoiceOfKnownMale($firstName, "");
			accessDB($readBookDB, "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", '', 0, "", $context, __LINE__, ""
			);
			}
		else
			{
			$line = __LINE__;
			accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $firstName);
			}
		}
	elseif (in_DB($readBookDB, $firstName, "females")) // only first names are in primary table, supposedly
		{
		if (in_DB($readBookDB, $lastName, $theseFemales))
			{
			accessDB($readBookDB, "DELETE FROM  $theseFemales WHERE label =  '$lastName'");
			// and make sure not found later
			//		accessDB($readBookDB, "REPLACE INTO $theseNotNames VALUES(?, ?)", $lastName, 1);
			}
		if (!(in_DB($readBookDB, $firstName, $theseFemales)))
			{
			// add with count of 0
			getVoiceOfKnownFemale($firstName, "");
			accessDB($readBookDB, "INSERT INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", '', 1, "", "", __LINE__, ""
			);
			accessDB($readBookDB, "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", '', 0, "", $context, __LINE__, ""
			);
			}
		else
			{
			$line = __LINE__;
			accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $firstName);
			}
		}
	}

function processNamesHavingQuotes(&$splits)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	$c = count($splits);
	for ($x = 1; $x < $c; $x += 2)
		{
		$wholeName = trim($splits[$x]);
		if (mb_strpos($wholeName, "’") === false)
			continue;
		$lowercaseWord1 = mb_strtolower($wholeName);
		if (in_DB($readBookDB, $lowercaseWord1, "verbs")
			OR in_DB($readBookDB, $lowercaseWord1, "notName"))
			continue;
		if (in_DB($readBookDB, $wholeName, "males"))
			{
			if (!(in_DB($readBookDB, $wholeName, $theseMales)))
				accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?)", $wholeName, "", '', 0, "", __LINE__, "");
			else
				{
				$line = __LINE__;
				accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
				}
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			if (!(in_DB($readBookDB, $wholeName, $theseFemales)))
				accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $wholeName, "", '', 0, "", __LINE__, "");
			else
				accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		}
	}

function breakLongParagraphs(&$htmBody)
	{
	$pars = preg_split('/(\n)/u', $htmBody, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$sum = 0;
	$max_allowed = 512;
	$half_max = $max_allowed / 2;
	$this_max = $max_allowed;
	foreach ($pars as &$par)
		{
		$len = mb_strlen(trim($par));
		if ($len === 0)
			continue;
//		if (mb_strpos($par,LQ) === false)
		if ($len > $max_allowed)
			{
			$extra = $len % $max_allowed;
			$n = intval($len / $max_allowed) + 1;
//			if ($extra < $half_max)
			$this_max = intval($len / $n) + 10;
			$long = false;
			$sum = 0;
			$sents = preg_split('/(\.)/u', $par, NULL, PREG_SPLIT_DELIM_CAPTURE);
			foreach ($sents as &$sent)
				{
				$sum += mb_strlen(trim($sent));
				if ($sum > $this_max)
					{
					if ($sent === ".")
						{
						$sent .= "\n";
						$long = true;
						$sum = 0;
						}
					}
				}
			$par = implode('', $sents);
			}
		}
	$htmBody = implode('', $pars);
	$breakpoint = 1;
	}
