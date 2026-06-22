<?php

// moved pronunciation from speak to client_para
// need to make reader start speaking proper place after menu. Almost done but BIG CHANGE to go traditional route
// incoming <hr ... /> not recognized by cleaner?
// update lastParagraph and exit fir speaker change
// add skip back command in safe way

$path = rawurldecode($_GET["book"]);
$phase = rawurldecode($_GET["phase"]);
$outputParm = rawurldecode($_GET["output"]);
$temp = preg_split('/[\(\)](.*?)/', strrev($path));
$bookID = $temp[1];
$readBookDBname = "sqlite:" . __DIR__ . "\..\labelCheck.db";
$readBookDB = new PDO($readBookDBname);
$transientDBname = "sqlite:" . __DIR__ . "\..\/transient.db";
$transientDB = new PDO($transientDBname);
//$readBookDB->exec('PRAGMA journal_mode = WAL;');
$data = accessProperDB( "SELECT * FROM bookTitle WHERE ID = ?", $bookID);
if (count($data) === 0)
	{
	// book not found
	$lastParagraph = 0;
	accessProperDB( "REPLACE INTO bookTitle(ID, lastParagraph) VALUES(?, ?)", $bookID, $lastParagraph);
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
/////////////////////////////////////////////////////////////////////////

require '../cleanHTML.php';
require '../DigitsToWords.php';
$digitsConverter = new DigitToWordsConverter();

//require 'QuestionPunctuationDecider.php';		// not necessary while computer is slow
//$interrogative = new QuestionPunctuationDecider([
//	'keep_threshold' => 1, // scores >= 1 => KEEP '?'
//	'remove_threshold' => 0, // scores <= 0 => REMOVE '?
//	]);

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

//accessProperDB( "BEGIN TRANSACTION");
// we need to re-establish speakers, so make all voices available except 0-3 which are invalid
accessProperDB( "UPDATE voice_params SET assigned = 0");

//$AllVoices = accessProperDB( "SELECT * FROM voice_params");
//$MaleVoices = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'male' AND narratorScore < 3.0 ORDER BY 'sexConfidence' DESC");
//$FemaleVoices = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'female' AND narratorScore < 3.0 ORDER BY 'sexConfidence' DESC");
//$MaleNarratorVoices = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'male' AND narratorScore > 3.0 ORDER BY narratorScore");
//$FemaleNarratorVoices = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'female' AND narratorScore > 3.0 ORDER BY narratorScore");
//$AmbiguousVoices = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' ORDER BY narratorScore");
//$PronouncePatterns = accessProperDB( "SELECT pattern FROM pronunciation ORDER BY seq");
//$PronounceReplacements = accessProperDB( "SELECT replacement FROM pronunciation ORDER BY seq");
//$PronouncePatterns = array_column($PronouncePatterns, 'pattern');
//$PronounceReplacements = array_column($PronounceReplacements, 'replacement');

header("Cache-Control: no-store");
require_once('ebookRead.php');
require_once('ebookData.php');
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

function accessProperDB($sql, ...$parms)
	{
	global $readBookDB;
	global $transientDB;
	$data = array();
	
	if (str_contains($sql, "Transient"))
		$DB = $transientDB;
	else
		$DB = $readBookDB;
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}
function accessReadBookDB($sql, ...$parms)
	{
	global $readBookDB;
	$data = array();
	
	$DB = $readBookDB;
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}
function accessTransientDB($sql, ...$parms)
	{
	global $transientDB;
	$data = array();

	$DB = $transientDB;
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
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
$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = '$narrationSex' AND disabled = 0 AND narratorScore >= 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	}
$narrVoiceNumber = $v;
$narrat = Lbrack . "v:$v" . Rbrack . Lbrack . "r:narrator" . Rbrack . Lbrack . "c:head" . Rbrack; //  narrator voice as narrator
$narrVoice = Lbrack . "v:$v" . Rbrack;   // narrator as generic character

$prevDepth = 0;

$prevPrevUsedName = '';  // used for reusing names from just before
$prevUsedName = '';

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
echo '</head>';
//echo '<div id="loadingSpinner" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.8); z-index: 9999; text-align: center; padding-top: 20%;">';
//echo '    <div class="spinner"></div>';
echo '<body style="text-align:center;">';
echo '<div id=title>';

$curTimeStamp = time();
$data = accessReadBookDB( "SELECT * FROM bookTitle WHERE ID = ?", $bookID);
if (count($data) === 0)
	// book not found
	accessReadBookDB( "REPLACE INTO ID, bookTitle, timeStamp) VALUES(?, ?, ?)", $bookID, $bookTitle, $curTimeStamp);
accessReadBookDB( "UPDATE bookTitle SET timeStamp = '$curTimeStamp' WHERE ID = '$bookID'");
$transientTables = accessTransientDB( "SELECT name FROM sqlite_master WHERE type='table'");
$bookData = accessProperDB( "SELECT * FROM bookTitle");
for ($b = 0; $b < count($bookData); $b++)
	{
	$thisBook = $bookData[$b];
	$age = $curTimeStamp - $thisBook['timeStamp'];
	$upperLimit = 60 /* seconds */ * 60 /* minutes */ * 24 /* hours */ * 7 /* days */;
	if ($age > $upperLimit)
		{
		cleanOtherBookDataFiles($thisBook['ID']);
		// and clean $transientDB, in reverse order
		for ($x = count($transientTables) - 1; $x >= 0; $x--)
			{
			$tableName = $transientTables[$x]['name'];
			accessTransientDB( "DROP TABLE $tableName");
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
$fullPath = "g:/callib/" . $path;
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

//function get_unused_voice(&$from)
//	{
//	global $AllVoices;
//	global $readBookDB;
//
//	for ($x = 0; $x < count($from); $x++)
//		{
//		$v = $from[$x]['voice_number'];
//		if ($v <= 4) 
//			continue;		// because 0-3 are screwed up
//		if ($AllVoices[$v]['assigned'] === 1)
//			continue;
//		if ($AllVoices[$v]['disabled'] === 1)
//			continue;
//		$AllVoices[$v]['assigned'] = 1;
//		accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
//		return $v;
//		}
//	}
//function assignVoice(&$from)
//	{
//	$v = get_unused_voice($from);
//	return "⦃v:" . $v . "⦄";
//	}

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
$reuseVoice = false;
$fixData = array();

$theseSettings = "settingsTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseSettings . '" (
	"settingID"				INTEGER NOT NULL,
	"settingText"			TEXT NOT NULL,
	"settingValue"			TEXT NOT NULL,
	PRIMARY KEY("settingID" AUTOINCREMENT));
	);';
accessProperDB($sql);
$defaultSettings = accessProperDB("SELECT * FROM defaultSettings");
foreach ($defaultSettings as $set)
	{
	$settingID = $set['settingID'];
	$settingText = "'" . $set['settingText'] . "'";
	$settingValue = "'" . $set['settingValue'] . "'";
	accessProperDB("REPLACE INTO $theseSettings VALUES(?, ?, ?)", $settingID, $settingText, $settingValue);
	}
$settings = accessProperDB("SELECT * FROM $theseSettings");

$theseTTSdata = "dataTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseTTSdata . '" (
	"atParagraph"			INTEGER,
	"CLEAN"				TEXT,
	"PRE"				TEXT,
	"TTS"				TEXT,
	PRIMARY KEY("atParagraph")
	);';
accessProperDB($sql);

$theseVoiceChanges = "voiceChangesTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseVoiceChanges . '" (
	"atParagraph"		INTEGER,
	"specified"				TEXT,
	PRIMARY KEY("atParagraph")
	);';
accessProperDB($sql);

//$theseVoiceChanges = "voiceChangesTransient$bookID";
//$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseVoiceChanges . '" (
//	"atParagraph"			TEXT NOT NULL,
//	"specified"				TEXT,
//	PRIMARY KEY("atParagraph")
//	);';
//accessProperDB( $sql);

$theseNotNames = "notNamesTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseNotNames . '" (
	"label"	TEXT NOT NULL,
	"count"	INTEGER,
	PRIMARY KEY("label")
	);';
accessProperDB( $sql);

$theseFemales = "femalesTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseFemales . '" (
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
accessProperDB( $sql);
if ($phase === 'initial')
	accessProperDB( "UPDATE $theseFemales SET count = 0, voice = '', creatingLine = '(Previously)', updatingLines=''");

$theseMales = "malesTransient$bookID";
$sql = 'CREATE TABLE IF NOT EXISTS "' . $theseMales . '" (
	"label"	TEXT NOT NULL,
	"lastName"	TEXT,
	"alias"		TEXT,
	"count"	INTEGER,
	"voice"	TEXT,
	"context"	TEXT,
	"creatingLine" TEXT,
	"updatingLines" TEXT,
	PRIMARY KEY("label")
	);';
accessProperDB( $sql);
if ($phase === 'initial')
	accessProperDB( "UPDATE $theseMales SET count = 0, voice = '', creatingLine = '(Previously)', updatingLines=''");


$theseNewWords = "newWordsTransient$bookID";
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
accessProperDB( $sql);
if ($phase !== 'reprocess')
	accessProperDB( "UPDATE $theseNewWords SET count = 0, voice = '', context = '', creatingLine = '(Previously)', updatingLines=''");
//	accessProperDB( "UPDATE $theseNewWords SET count = 0");
//$m1 = "$narrVoice" . Lbrack . "r:masculine" . Rbrack;
//$m2 = "$narrVoice" . Lbrack . "r:masculine" . Rbrack;

$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	$m1 = Lbrack . "v:$v" . Rbrack;
	accessProperDB( "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'm1', '', 'm1', 0, $m1, "", __LINE__, "");
	}
$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
	$m2 = Lbrack . "v:$v" . Rbrack;
	accessProperDB( "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'm2', '', 'm2', 0, $m2, "", __LINE__, "");
	}

//$f1 = "$narrVoice" . Lbrack . "r:feminine" . Rbrack;
//$f2 = "$narrVoice" . Lbrack . "r:feminine" . Rbrack;

$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessProperDB( "UPDATE voice_params SET assigned =1 WHERE voice_number = $v");
	$f1 = Lbrack . "v:$v" . Rbrack;
	accessProperDB( "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'f1', '', 'f1', 0, $f1, "", __LINE__, "");
	}
$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
if (count($data) > 0)
	{
	$v = $data[0]['voice_number'];
	accessProperDB( "UPDATE voice_params SET assigned =1 WHERE voice_number = $v");
	$f2 = Lbrack . "v:$v" . Rbrack;
	accessProperDB( "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)", 'f2', '', 'f2', 0, $f2, "", __LINE__, "");
	}

$prevAntecedentFemale = 'f1';  // used for antecedent
$antecedentFemale = 'f2';

$prevAntecedentMale = 'm1';  // used for antecedent
$antecedentMale = 'm2';

$epubContentsFolder = "d:\\\\" . $bookID;
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

	$data = accessProperDB( "SELECT  *  FROM $t1 A WHERE EXISTS (SELECT *  FROM $t2 B  WHERE A.label = B.label)");
//	if (count($data) > 0)
//		logMsg("Software error: readBook line " . __LINE__, "Error");
	for ($x = 0; $x < count($data); $x++)
		{
		$label = $data[$x]['label'];
		logMsg("Name inconsistency $label in $t1 and $t2");
		if ($delWhich === 1 OR $delWhich === 3)
			accessProperDB( "DELETE FROM  $t1 WHERE label =  '$label'");
		if ($delWhich === 2 OR $delWhich === 3)
			accessProperDB( "DELETE FROM  $t2 WHERE label =  '$label'");
		}
	}

//checkDBConsistency('males', 'females', 3);
//checkDBConsistency($theseMales, $theseFemales, 3);
//checkDBConsistency($theseMales, 'females', 1);
//checkDBConsistency($theseFemales, 'males', 1);
$cleanedAuthor = preg_replace("/[’']/u", '', $author);
$cleanedTitle = preg_replace("/[’']/u", '', $bookTitle);
accessProperDB( "UPDATE bookTitle SET title = '$cleanedTitle', author = '$cleanedAuthor' WHERE ID = '$bookID'");
echo '<h1>' . $bookTitle . "</h1>" . "<h2>by " . $author . "</h2>";
if ($phase === 'initial')
	{
	echo ' <p style="font-size: 12px;">Phase ';
	accessProperDB( "UPDATE $theseFemales SET count = 0, voice = '', creatingLine = '(Initiial)', updatingLines='(Initiial)'");
	accessProperDB( "UPDATE $theseMales SET count = 0, voice = '', creatingLine = '(Initiial)', updatingLines='(Initiial)'");
	accessProperDB( "UPDATE $theseNewWords SET count = 0, voice = '', creatingLine = '(Initiial)', updatingLines='(Initiial)'");

	$time_start = hrtime(true);
	if (!is_dir($epubContentsFolder))
		mkdir($epubContentsFolder);
//	$temp = __DIR__ . "\\..\\audio";
//	if (is_dir($temp))
//		rrmdir($temp);
//	if (!mkdir($temp))
//		logMsg("Software error: readBook line " . __LINE__, "Error");
//	if (is_dir($bookID))
//		rrmdir($bookID);
//	if (!is_dir($epubContentsFolder))
//		{
//		mkdir($epubContentsFolder);
//		mkdir($epubContentsFolder . "\\CLEAN");
//		mkdir($epubContentsFolder . "\\PRE");
//		mkdir($epubContentsFolder . "\\TTS");
//		}

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
	echo '1';
	ob_flush();
	flush();

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
	echo '1';
	ob_flush();
	flush();
	$zip->close();
// prepend this file onto list of prev opened
	if (!file_exists("../LastFile.txt"))
		file_put_contents("../LastFile.txt", "");
	$fileContents = file_get_contents("../LastFile.txt");
	$lastFiles = $fullPath . "|" . $fileContents;
	file_put_contents("../LastFile.txt", $lastFiles);
	if (!chdir($epubContentsFolder))
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
		$fullZippedFile = "d:\\\\"  . $HTMLfile;
		$htm = file_get_contents($fullZippedFile);
		if (stripos($htm, "signup"))
			{
//		echo("A portion of the book, $HTMLfile, containing the text 'signup' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (strpos($htm, " By "))
			{
//			echo("A portion of the book, $HTMLfile, containing the text 'Books by' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (stripos($htm, "Books by"))
			{
//			echo("A portion of the book, $HTMLfile, containing the text 'Books by' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (stripos($htm, "Also by"))
			{
//			echo("A portion of the book, $HTMLfile, containing the text 'Also by' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (strpos($htm, "Contents")
		OR strpos($htm, "CONTENTS"))  // note NOT stripos
			{
//			echo("A portion of the book, $HTMLfile, containing the text 'Contents' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (stripos($htm, "href")
		AND !stripos($htm, "style"))
			{
			echo("A portion of the book, $HTMLfile, containing the text 'href'and not containg the word 'style' has been deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (stripos($htm, "copyright")
		OR strpos($htm, "©"))
			{
			//echo("A portion of the book, $HTMLfile, containing the text 'copyright' deleted.<br>");
			unlink($fullZippedFile);
			continue;
			}
		if (stripos($htm, "isbn"))
			{
			//echo("A portion of the book, $HTMLfile, containing the text 'isbn' deleted.<br>");
			unlink($fullZippedFile);
			continue;
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
			$htmBody = body_html_to_plain_text($htm);
			if ($htmBody === "")
				continue;
			breakLongParagraphs($htmBody);
			$outTTSfile = "d:\\\\$bookID\\CLEAN\\";
			file_put_CLEAN_by_paragraph($outTTSfile, $htmBody, true);
			$outTTSfile = "d:\\\\$bookID\\PRE\\";
			file_put_PRE_by_paragraph($outTTSfile, $htmBody, true);
			echo '1';
			ob_flush();
			flush();
			}
		}
	$breakpoint = 1;
	}
else
	{
	echo ' <p style="font-size: 12px;">Phase ';
	ob_flush();
	flush();
	}
if ($phase !== 'continue')
	{
	$time_start = hrtime(true);
//	$directory = "d:\\\\$bookID\\CLEAN";
//	$files = scandir($directory);
	$fileCount = accessProperDB("SELECT COUNT(*) FROM $theseTTSdata");
	$n = $fileCount[0][0];
	for ($f = 0; $f < $n; $f++)
		{
		$data = db_get_contents("CLEAN", $f);
		if (count($data) === 1)
			$htmBody = $data[0][0];
		else
			break;

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
		$prevDepth = 0;
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
			echo '2';
			ob_flush();
			flush();
			}
		$htmBody = implode('', $element);
		$breakpoint = 1;
//		$outTTSfile = "d:\\\\$bookID\\TTS\\$f.txt";
		if ($phase !== 'reprocess')
			processAllNames($htmBody);
		reverseMarkersForTTS($htmBody);
		db_put_contents("TTS", $f, $htmBody);
		}
	/* 	$time_end = hrtime(true);
	  $time = $time_end - $time_start;
	  $totalTime += $time;
	  $totalTime /= 1000000000;
	  logMsg("time = $totalTime seconds\n");
	 */
	}
//accessProperDB( "COMMIT TRANSACTION");					// review ****************************************
echo "</div>";  // end title div

echo "<div id=editNames></div>";
echo "<hr>";
editNames();
echo "<div style=text-align:center id=assignDiv>";

$phase = '3';
echo '<a href="readBook.php?book=' . rawurlencode($path) . '&output=' . $outputParm . "&phase=$phase\" class=button-link>Re-Process.</a>";
echo "<hr>";
echo "<h1>";
echo "Ready to listen?</h1>";
echo '<a href="reader.php?book=' .
 $bookID . "&start=$lastParagraph&title=$bookTitle&author=$author&narrator=$narrVoiceNumber" . '" class=button-link>Speak</a>';

//echo "<div id=editProblems><hr>";
//editProblems();
//echo "</div>";
echo "</div><div style=text-align:center>";
echo "</div>";
editMisc();
echo "<hr></div>";
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
//		$fileName = $fileBase . $CLEANfileNumber++ . ".txt";
		if ($veryFirstCleanParagraph)
			$title = '<h1><b>' . $bookTitle . "</b></h1><br>" . "<h2>by " . $author .
				"</h2><br>";
		else
			$title = "";
		if ($firstParagraph)
			$files[$f] = $title . "<hr><h3>" . $files[$f] . "</h3>";
		$firstParagraph = false;
		$veryFirstCleanParagraph = false;
		db_put_contents("CLEAN", $CLEANfileNumber++ , $files[$f]);
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
//		$fileName = $fileBase . $PREfileNumber++ . ".txt";
		reverseMarkersForDisplay($files[$f]);
		if ($veryFirstParagraph)
			$title = '<h2>' . $bookTitle . "</h2>" . "<h2>by " . $author . "</h2>";
		else
			$title = "";
		if ($firstParagraph)
			$files[$f] = $title . "<hr><h3>" . $files[$f] . "</h3>";
		$firstParagraph = false;
		$veryFirstParagraph = false;
		db_put_contents("PRE", $PREfileNumber++, $files[$f]);
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
		if (str_contains($paragraph, "-3000"))
			$breakpoint = 1;
		$conv = $digitsConverter->convertSentence($paragraph); // translate numbers into words
		if (count($conv['conversions']) > 0)
			{
			$breakpoint = 1;
			$paragraph = $conv['converted_sentence'];
			}
		}
//	$paragraph = Lbrack . "n:$paragraphNumber" . Rbrack . "\n" . $paragraph;
	if ($firstP)
		if (str_contains(mb_substr($paragraph, 0, 1), "“") === false)
			$paragraph = $narrat . $paragraph . "\n";
	$depth = 0;
//	handleWHquestions($paragraph);
	$possibleSingleQuote = preg_split('/([a-z] )(“)(.*?)(”)/u', $paragraph, NULL, PREG_SPLIT_DELIM_CAPTURE);
	$c = count($possibleSingleQuote);
	if ($c > 1)
		{
		// it turns out, this is not a speaker, but a '   ' reference
		// so we change to single quotes
		$breakpoint = 1;
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
//	handlePronunciation($paragraph);
	$breakpoint = 1;
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
	global $prevUsedName;
	global $prevPrevUsedName;
	global $antecedentFemale;
	global $prevAntecedentFemale;
	global $antecedentMale;
	global $prevAntecedentMale;
	global $speaker;
	global $channel;
	global $readBookDB;
	global $theseVoiceChanges;

if ($paragraphNumber === 15)
  $breakpoint=1;
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
			continue;
			}
		if ($quotation[$quoteElement] === LQ)
			{
			// left smart quote found, so paragraph contains speech
			// or contains embedded quotation of narrator
			// [$quoteElement] is sitting on the the left quote mark
			if ($quoteNumber === 0)
				{
				$speakerOfParagraph = "";
				$sexOfParagraph = "";
				$verbOfParagraph = "";
				}
			$quoteNumber++;  // count quotes in this pargagraph for voice re-use check below
//if ($speaker === "she"
//OR $speaker === "She")
//	$speakerSex = "female";
//if ($speaker === "he"
//OR $speaker === "He")
//	$speakerSex = "male";
//if (isPronoun($speaker))
//  $breakpoint = 1;
//			if ($speaker === "I")
//				$speakerSex = $narrationSex;
//			if (!$speaker
//			OR $speaker === "")
//				{
//				$speaker = "m1 ";
//				$speakerSex = "male";
//				}
//			if (mb_strlen($speaker) > 1)
//				{
//				if ($speaker[1] !== '1'
//				AND $speaker[1] !== '2')
//					{
//					if ($speakerSex === 'male')
//						{
//						$prevAntecedentMale = $antecedentMale;
//						$antecedentMale = $speaker;
//						}
//					else if ($speakerSex === 'female')
//						{
//						$prevAntecedentFemale = $antecedentFemale;
//						$antecedentFemale = $speaker;
//						}
//					}
//				}
			if (mb_strlen($speaker) > 0
			AND $speaker !== $prevUsedName)
				{
				if (!isPronoun($speaker))
					{
					$prevPrevUsedName = $prevUsedName;
					$prevUsedName = $speaker;
					}
				}
			// try to determine sex of speaker	:
			// set up for getVerbNameSex() routine
			$thisText = "";
			$nextText = "";
			if (array_key_exists($quoteElement - 1, $quotation))
				{
				// there is possibly prev unquoted text in this paragraph
				$prevText = $quotation[$quoteElement - 1];
				if (mb_substr(trim($prevText), -1, 1) === ',')
				// the previous text ends in a comma, indicating it most likely has the speaker in it
					$examinePrevFirst = true;
				else
				// it probably doesn't have the speaker in it
					$examinePrevFirst = false;
				}
			else
				{
				$prevText = '';
				$examinePrevFirst = false;
				}
			if (array_key_exists($quoteElement + 1, $quotation))
				$thisText = $quotation[$quoteElement + 1];  // needed only for debugging								
			if (array_key_exists($quoteElement + 3, $quotation))
				$nextText .= $quotation[$quoteElement + 3];

			$voiceSource = "";
			$thisVoice = "";

// POSSIBLE IMPROVEMENT IN ../EPUB WHICH USES PREVIOUS PARAGRAPH'S ENDING TO DECIDE WHETHER TO SET
// $examinePrevFirst to true or false
//$examinePrevFirst = true;
			// for performance reasons, all following params passed by reference except $examinePrevFirst
			//  $speaker and $verbFound may be modified
			$p = accessProperDB( "SELECT * FROM $theseVoiceChanges WHERE atParagraph = $paragraphNumber");
			$reuseVoice = false;
			if ($quoteNumber === 1)
				{
				$speakerSex = getVerbNameSex($nextText, $prevText, $speaker, $examinePrevFirst, $verbFound);
if (isPronoun($speaker))
 $breakpoint = 1;
				$speakerOfParagraph = $speaker;
				$sexOfParagraph = $speakerSex;
				$verbOfParagraph = $verbFound;
				}
			elseif ($quoteNumber > 1
			AND $speakerOfParagraph > "")
				{
				$speaker = $speakerOfParagraph;
				$speakerSex = $sexOfParagraph;
				$verbFound = $verbOfParagraph;
				$reuseVoice = true;   // keep using same voice
				}
			$speaker = trim($speaker);
//			forceVoiceIfNecessary($paragraphNumber, $speaker, $speakerSex);
			$voiceSource = $verbFound;
//			if (isPronoun($speaker)
//			OR $speaker === ""
//			OR $speakerSex === "ambiguous")
			if ($speaker === ""
			OR $speakerSex === "ambiguous")
				{
				$speakerspecified = 'N';
//addDebugToPRE(" <sub>Non-expicit $speaker $speakerSex $verbFound.</sub> ", $paragraphNumber, true);
				}
			else
				{
				$speakerspecified = 'Y';
				if ($speakerSex === 'male')
					{
					$prevAntecedentMale = $antecedentMale;
					$antecedentMale = $speaker;
					}
				else if ($speakerSex === 'female')
					{
					$prevAntecedentFemale = $antecedentFemale;
					$antecedentFemale = $speaker;
					}
//addDebugToPRE(" <sub>specified $speaker $speakerSex $verbFound.</sub> ", $paragraphNumber, true);
				}
			if ($quoteNumber === 1)
				{
				accessProperDB( "REPLACE INTO $theseVoiceChanges VALUES(?, ?)", $paragraphNumber, $speakerspecified);
				}
			else if ($prevDepth > 0)  // first quote in this paragraph, and no ending quote in previous paragraph
				{
				$reuseVoice = true;
				}
			if (!$reuseVoice)
				{
//addDebugToPRE(" <sub>!reuseVoice $speaker.</sub> ", $paragraphNumber, true);
				$speaker = trim($speaker);
				// we have to figure it out, if possible
				// if this speakerName not seen before,
				//	we have added it
				// if this speaker WAS seen before,
				//	we'll just use it
				$spk = mb_strtolower($speaker);
				if ($spk === "she") // if $speaker is a personal pronoun, find the antecedent
					{
					$voiceSource .= " Antecedent,";
					$speaker = $antecedentFemale;
//addDebugToPRE(" <sub>Speaker set to $speaker.</sub> ", $paragraphNumber, true);
					$speakerSex = 'female';
					}
				elseif ($spk === "he")
					{
					$voiceSource .= " Antecedent,";
					$speaker = $antecedentMale;
//addDebugToPRE(" <sub>Speaker set to $speaker.⁷</sub> ", $paragraphNumber, true);
					$speakerSex = 'male';
					}
				elseif (!speakerSeenBefore($speaker))
					{
//addDebugToPRE(" <sub>$speaker not seen before.</sub> ", $paragraphNumber, true);
					if ($speakerSex === 'Undetermined')
						{
						$voiceSource .= " Alternate";
						$speaker = $prevPrevUsedName;
if ($speaker === "Lorna")
	$breakpoint=1;
						if ($speaker === "")
							{
							if (($paragraphNumber % 2) === 0)
								$speaker = 'm1';
							else
								$speaker = 'm2';
							}
						}
					}
				}
			if ($reuseVoice)
				$voiceSource .= " reused,";
			else
				$voice = getVoiceCommand($speaker);

			$quotation[$quoteElement + 1] = $voice . Lbrack . "c:$channel" . Rbrack . $quotation[$quoteElement + 1];
if ($speaker === "she")
	$breakpoint=2;
addDebugToPRE(" <sub>$speaker:$verbFound:$voice</sub> ", $paragraphNumber, true);
			$quoteElement++;
			$depth++;
			}
		// end if left quote
		elseif (str_contains($quotation[$quoteElement], RQ))
			{
			$depth--;
			$quotation[$quoteElement] = $narrat . $quotation[$quoteElement];
			$quoteElement++;
			}
		}
	$prevDepth = $depth;
	$paragraphText = implode('', $quotation);
	$paragraphText = insertPauses($paragraphText);
	}

// ==============================================================================================================================
$debugAdded = array();

function db_get_contents($field, $paragraphNumber)
	{
	global $theseTTSdata;
	
	$ret = accessProperDB("SELECT $field FROM $theseTTSdata WHERE atParagraph = $paragraphNumber");
	return $ret;
	}
function db_put_contents($field, $paragraphNumber, $content)
	{
	global $theseTTSdata;
	
	$fileCount = accessProperDB("SELECT COUNT(*) FROM $theseTTSdata WHERE atParagraph = $paragraphNumber");
	$n = $fileCount[0][0];
	if ($n === 0)
		$ret = accessProperDB( "INSERT INTO $theseTTSdata VALUES(?, ?, ?, ?)", $paragraphNumber, '', '', '');
	$ret = accessProperDB( "UPDATE $theseTTSdata SET $field = ? WHERE atParagraph = $paragraphNumber", $content);
	return $ret;
	}
function addDebugToPRE($text, $paragraphNumber, $force = false)
	{
	global $bookID;
//	global $debugAdded;
	$ignoreLit = '/( <sub.*sub> )/u';

	$data = db_get_contents("PRE", $paragraphNumber);
	if ($data === 1)
		$pre = $data[0][0];
	else
		return;
	$pre = preg_replace($ignoreLit, "", $pre) . $text;
	db_put_contents("PRE", $paragraphNumber, $pre);
//	$debugAdded[$paragraphNumber] = true;
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
//	if ($speaker === "narrator")
//		return false;
//	if (isPronoun($speaker))
//		return false;

	$data = accessProperDB( "SELECT
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

function getSexFromVoice($voice)
	{
	global $readBookDB;

	$end = mb_strpos($voice, '⦄');
	if ($end === false)
		return "";
//		logMsg("Software error: readBook line " . __LINE__, "Error");
	$v = (int) mb_substr($voice, 3, $end - 3);
	$sexData = accessProperDB( "SELECT sex FROM voice_params WHERE voice_number = ? AND disabled = 0", $v);
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

	accessProperDB( "DELETE FROM  $theseNewWords WHERE count = 0");
	$newWords = accessProperDB( "SELECT * FROM $theseNewWords ORDER BY count DESC");
	$wordCount = count($newWords);
	echo "</div>";
	echo "<div id=stepOneDiv>";
	echo "\n<select class='box' id=wordBox onfocus=this.selectedIndex=0 onchange='chosenPossibleName(this.value)' >";
	echo "<option value=''>Possible Names</option>\n";
	for ($x = 0; $x < $wordCount; $x++)
		{
		$thisWord = $newWords[$x];
		$count = $thisWord['count'];
		if ($count < 2)
			continue;
		$v = $thisWord['voice'];
		$voice = preg_replace('/(⦄)/u', '', $v);
		$voice = mb_substr($voice, 3);
		$context = rawurlencode($thisWord['context']);
		$display = "$thisWord[label] (n=$count)";
		$value = "$bookID@@$thisWord[label]@@$count@@$context";
		$value = str_replace("'", "`", $value);  // kill single straight quotes
		echo "<option value='$value'>$display</option>\n";
		}
	//echo "<option value='Done'>Done</option>\n";
	echo "</select></div>";
	echo "<div hidden id=nameButtons style=text-align:center;background-color:BurlyWood>";
	echo "<p style=\"margin:2px\"> For this Book</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseMales, male')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseFemales, female')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseNotNames, not')\">Not Name</button>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> For ALL Books</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('males, male')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('females, female')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('notName, not')\">Not Name</button>";
	echo "<hr>";
	echo "<div id=context1 style=font-size:2.5vh;text-align:left;padding:2vh;margin:3vh;>";
	echo "</div>";
	echo "<div style=margin:0;text-align:center;background-color:BurlyWood>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> For this Book</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseMales, male')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseFemales, female')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('$theseNotNames, not')\">Not Name</button>";
	echo "<hr>";
	echo "<p style=\"margin:2px\"> For ALL Books</p>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('males, male')\">Male</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('females, female')\">Female</button>";
	echo "<button type=button class=smallButton onclick=\"changeVoice('notName, not')\">Not Name</button>";
	echo "<hr>";
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
	accessProperDB( "DELETE FROM  $theseMales WHERE count < 2");
	accessProperDB( "DELETE FROM  $theseFemales WHERE count < 2");
	$names = accessProperDB( "SELECT * FROM $theseMales ORDER BY count");
	$mCount = count($names);
	$names = array_merge($names, accessProperDB( "SELECT * FROM $theseFemales ORDER BY count;"));
	$fCount = count($names);
	echo "</div></div>";
	echo "<hr><select class='box' id=nameBox onfocus=this.selectedIndex=0 onchange='rememberName(this.value)' >";
	echo "<option value=''>Delete, Change Gender, or Equate Name</option>\n";
	for ($x = 0; $x < $fCount; $x++)
		{
		$entry = $names[$x];
		if ($entry['label'] === '')
			continue;
		if ($x < $mCount)
			$gender = 'M';
		elseif ($x < $fCount)
			$gender = 'F';
		$count = $entry['count'];
		$display = "$gender: $entry[label] ($entry[count])";
		$value = "$entry[label]@@$entry[count]@@$entry[voice]@@$gender";
		$value = str_replace("'", "`", $value);  // kill single straight quotes
		echo "<option value='$value'>$display</option>\n";
		}
	echo "</select></div></div><br><br>";
	echo "<div style=display:none; id=changeNames >";
	echo "<select class='box' id=changeNameBox onfocus=this.selectedIndex=0 onchange='equateName(this.value)' >";
	echo "<option value=''>Equate Name</option>\n";
	$prevAlias = '';
//	$names = accessProperDB( "SELECT * FROM $theseMales WHERE count >= 0 ORDER BY count DESC");
//	$nameCount = count($names);
	for ($x = 0; $x < $fCount; $x++)
		{
		$thisNameArray = $names[$x];
		$count = $thisNameArray['count'];
		$name = $thisNameArray['label'];
		if ($count === 0)
			continue;
		if ($x < $mCount)
			$gender = 'M';
		else if ($x < $fCount)
			$gender = 'F';
		$value = "$name@@$count@@$gender@@$thisNameArray[voice]@@$theseMales@@$theseFemales@@$theseNewWords@@$theseNotNames";
		$value = str_replace("'", "`", $value);  // kill any single straight quotes
		$display = "$gender: $name ($count)";
		echo "<option value='$value'>$display</option>\n";
		$prevAlias = $thisAlias;
		}
	echo "</select><br>";
//	echo "<div style=display:none id=changeFemales >";
//	echo "\n<select class='box' id=femalesBox onfocus=this.selectedIndex=0 onchange='equateName(this.value)' >";
//	echo "<option value=''>Change Female's Alias</option>\n";
//	$prevAlias = '';
//	$names = accessProperDB( "SELECT * FROM $theseFemales WHERE count >=0  ORDER BY count DESC");
//	$nameCount = count($names);
//	for ($x = 0; $x < $nameCount; $x++)
//		{
//		$thisNameArray = $names[$x];
//		$count = $thisNameArray['count'];
//		$name = $thisNameArray['label'];
//		$thisAlias = $thisNameArray['alias'];
//		if (substr($name, 0, 5) === "Other")
//			$thisAlias = "";
//		elseif ($count === 0)
//			continue;
//		if ($count > 1
//			OR str_contains($name, "Other:") !== false)
//			{
//			if ($thisAlias === "")
//				$display = $name;
//			else
//				$display = "$name>>[$thisAlias]($count)";
//			$value = "$name";
//			$value = str_replace("'", "`", $value);  // kill single straight quotes
//			echo "<option value='$value'>$display</option>\n";
//			$prevAlias = $thisAlias;
//			}
//		}
//	echo "</select>";
//	echo "</div>";
//	echo "<div id=showChangeGenderBtn>";
	echo "<button type=button class=mediumButton onclick=changeGender()>Reverse Gender</button><br>";
	echo "<button type=button class=mediumButton onclick=deleteName()>Delete Name</button><br>";
	echo "</div>";
	echo "<div id=modifyNames style=background-image: radial-gradient(yellow,green);>";
	echo "<div style=text-align:left>";
	echo "<hr><p><b>DEFINE NEW ITEMS</b></p><input type=text placeholder='VERB' id=newVerb onchange=newVerb(this.value)>";
	echo "<input type=text placeholder='MALE' id=newMale onchange=newMale(this.value)>";
	echo "<input type=text placeholder='FEMALE' id=newFemale onchange=newFemale(this.value)>";
	echo "<input type=text class=wideInput placeholder='SUBSTITUTE FOR ...' id=newFor>";
	echo "<input type=text class=wideInput placeholder='THIS ...' id=newSubstitute onchange=newSubstitute(this.value)>";
	echo "<input type=text class=wideInput placeholder='CONNECTIVE' id=newConnective onchange=newConnective(this.value)><br>";
	echo "</div>";
//	echo "<div style=text-align:left;>";
//	echo "<hr><b>CHANGE <u>ALL</u> BOOKS WITH REGX</b><br>Enter fields from left to right.<br>";
//	echo "Large changes will issue warning when executed.<br>";
//	echo "To undo huge changes, use DB manager to fix or remove the offending REGX.<br><br>";
//	echo "Commands are: All, TTS, AfterTTS, and Piper.<br>";
//	echo "<input type=text placeholder='Command' id=regxCmd onchange=newregxCmd(this.value)>";
//	echo "<input type=text placeholder='REGx' id=newREGx onchange=newREGx(this.value)>";
//	echo "<input type=text placeholder='Replacement' id=newRepl onchange=newRepl(this.value,$bookID)>";
//	echo "</div>";
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
					$sentences[$i] = Lbrack . "w:444" . Rbrack . $sentences[$i];
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

function deleteSections(&$txt)
	{
	$txt = preg_replace('/<section.*?>/iu', "", $txt);
	$txt = preg_replace('/<\/section>/iu', "", $txt);
	}

function isSAIDword($word)
	{
	global $readBookDB;
	global $transientDB;
	global $theseNewWords;
	global $theseMales;
	global $theseFemales;
	$word = trim($word);
	$labels = accessProperDB( "SELECT * FROM verbs WHERE label = ?", $word);
	if (count($labels) > 0)
		{
		if (strpos($labels[0]['usage'], "(verb)") !== false)
			return true;  // primarily a verb
		else
			return false; // possibly not a verb
		}
	if (in_any_DB($transientDB, $word, $theseNewWords, $theseMales, $theseFemales))
		return false;
	elseif (in_any_DB($readBookDB, $word, 'males', 'females', 'connectives'))
		return false;
	elseif (strlen($word) > 3)  // try to find base word
		{
		// apostrophe
		$rootWord = preg_replace('/(.*?)[’\'].+$/', '\1', $word);
		// 5 letter suffixes
		$rootWord = preg_replace('/(.*?)bbing$/', '\1b', $rootWord); // bbing
		$rootWord = preg_replace('/(.*?)dding$/', '\1d', $rootWord); // dding
		$rootWord = preg_replace('/(.*?)pping$/', '\1p', $rootWord); // pping
		$rootWord = preg_replace('/(.*?)tting$/', '\1t', $rootWord); // tting
		// 4 letter suffixes
		$rootWord = preg_replace('/(.*?)bbed$/', '\1b', $rootWord); // bbed
		$rootWord = preg_replace('/(.*?)dded$/', '\1d', $rootWord); // dded
		$rootWord = preg_replace('/(.*?)pped$/', '\1p', $rootWord); // pped
		$rootWord = preg_replace('/(.*?)tted$/', '\1t', $rootWord); // tted
		// 3 letter suffixes
		$rootWord = preg_replace('/(.*?)n[’\']t$/', '\1', $rootWord); // n't
		$rootWord = preg_replace('/(.*?)ie[sd]$/', '\1y', $rootWord); // ies and ied
		$rootWord = preg_replace('/(.*?)ing$/', '\1', $rootWord);  // ing
		// 2 letter suffixes
		$rootWord = preg_replace('/(.*?)ed$/', '\1', $rootWord);
		$rootWord = preg_replace('/(.*?)es$/', '\1', $rootWord);
		// 1 letter suffixes
		$rootWord = preg_replace('/(.*?)s$/', '\1', $rootWord);
		if ($rootWord !== $word)
			{
			$labels = accessProperDB( "SELECT * FROM verbs WHERE label = ?", $rootWord);
			if (count($labels) > 0)
				return true; // a verb
			else
				{
				$rootWord .= 'e';
				$labels = accessProperDB( "SELECT * FROM verbs WHERE label = ?", $rootWord);
				if (count($labels) > 0)
					return true; // a verb
				}
			}
		}
	return false;
	}

function in_DBcount($readBookDB, $name, $table)
	{
	$labels = accessProperDB( "SELECT * FROM $table WHERE label = ?", $name);
	if (count($labels) === 0)
		return false; // not in database
	// it is already present in the DB, so add 1, either to name or Alias
	$alias = $labels[0]['label'];  // might be name or Alias
	accessProperDB( "UPDATE $table SET count = count+1 WHERE label = ?", $alias);
	return true;
	}

function in_any_DB($theDB, $word, ...$tables)
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
//	$sql = mb_substr($sql, 0, mb_strlen($sql)-1) ;
//	foreach ($tables as $table)
//		{
//		// add to SELECT statement
//		$sql .= ", '$word'";
//		}
	$labels = accessProperDB( $sql);
	if (count($labels) === 0)
		return false; // not in database
	return true;
	}

function in_DB($readBookDB, $word, $table)
	{
	$labels = accessProperDB( "SELECT * FROM $table WHERE label = ?", $word);
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
	accessProperDB( "REPLACE INTO $table VALUES(?, ?, ?, ?, ?,?,?,?)", $speaker, '', $speaker, 1, '', "", __LINE__, "");
	return($fromSex);
	}

function sexByFirstOrOnlyName($speaker)
	{
	global $prevParagraphStack;
	global $prevParagraphStackSize;
	global $readBookDB;
	global $transientDB;
	global $narrationSex;
	global $theseNewWords;
	global $theseMales;
	global $theseFemales;
	global $theseNotNames;

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
	if (in_any_DB($transientDB, $lowerName, $theseNotNames))
		return "";
	if (in_any_DB($readBookDB, $soughtName, 'verbs', 'notName'))
		return "";

	if ($soughtName === "I")
		{
		// special treatment of "I"
		$sex = $narrationSex;  // the sex of the narrator
		if ($sex === "male")
			$table = $theseMales;
		else
			$table = $theseFemales;
		if (!in_DB($readBookDB, $soughtName, $table))
			accessProperDB( "REPLACE INTO $table VALUES(?, ?, ?, ?, ?,?,?,?)", $soughtName, '', 'I', 1, '', "", __LINE__, "");
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
		$sex = 'ambiguous';
	elseif (in_DBcount($readBookDB, $soughtName, 'males'))
		$sex = replicateInto($theseMales, $soughtName, 'male');
	elseif (in_DBcount($readBookDB, $soughtName, 'females'))
		$sex = replicateInto($theseFemales, $soughtName, 'female');
//	elseif ($soughtName[0] > 'Z')
//		accessProperDB( "REPLACE INTO notName VALUES(?, ?)", "$soughtName", 1);
	else
		{
		$sex = "ambiguous";
		$v = "";
		$context = "}}^";
		for ($x = 0; $x < $prevParagraphStackSize; $x++)
			$context .= "^$prevParagraphStack[$x]";
		accessProperDB( "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?,?,?,?)", "$soughtName", '', "$soughtName", 1, $v, "$context", __LINE__, "");
		$v = getVoiceOfKnownNewWords($soughtName, $v);
		accessProperDB( "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$soughtName'");
		}
//	if ($sex === "ambiguous")
//		{
//		$data = accessProperDB( "SELECT count FROM $theseNewWords WHERE label = '$soughtName'");
//		if ($data === 1)
//			$count = $data[0]['count'];
//		else
//			logMsg("Software error: New Word error: $soughtName readBook line " . __LINE__, "Error");
//		if ($count === 0)
//			{
//			$context = "}}^";
//			for ($x = 0; $x < $prevParagraphStackSize; $x++)
//				$context .= "^$prevParagraphStack[$x]";
//			accessProperDB( "UPDATE $theseNewWords SET context = '$context' label = '$soughtName");
//			}
//		}
	return $sex;
	}

function isPronoun($word)
	{
	if ($word === "he"
	OR $word === "He"
	OR $word === "she"
	OR $word === "She")
		return true;
	return false;
	}

function findPronoun($word, &$sex)
	{
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
		if (str_contains($words[$y], "narr"))
			$breakpoint = 1;
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
			else
			// it was a pronoun, so sex is knowm
				$breakpoint = 1;
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
			else
				$breakpoint = 1;
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
			if (!in_DB($readBookDB, $word, 'verbs'))
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
			if (!in_DB($readBookDB, $word, 'verbs'))
				{
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
	global $debug;

	$ignoreLit = '/(⦃v.*?⦄|⦃r.*?⦄|⦃w.*?|⦃n.*?⦄|⦃d.*?⦄|⦃c.*?⦄|⦃p:|⦄)/u';
//	$pronounceLit = '/(⦃p:)(.*?)⦄/u';

	if ($nextText !== "")
		$nextText = preg_replace($ignoreLit, '', $nextText);
	if ($prevText !== "")
		$prevText = preg_replace($ignoreLit, '', $prevText);

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
				// if only one potential name found, use it
				// if two potential names are found, use the one closest to "said" verb
				// XXsex is returned.
				// if found, xThisSpeaker and $XXcloseness are set
				if ($debug)
					$breakpoint = 1;
				$UCspeaker = "";
				$UCcloseness = 999;
				$UCsex = findUCspeakerOrPronoun($UCstart = $x, $UCspeaker, $maxSubscript, $UCcloseness);
				if ($UCcloseness !== 999
					AND $UCsex !== "Undetermined")
					{
					// a potential Upper case speaker was found
					$aThisSpeaker = $UCspeaker;
					if ($debug)
						$breakpoint = 1;
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
						if ($debug)
							$breakpoint = 1;
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
	if (!$examinePrevFirst)
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
	$audioFiles = __DIR__ . "\\..\\audio\\";
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
	$bookDirs = "d:\\\\";
	$dir = opendir($bookDirs);
	while (false !== ($file = readdir($dir))) // files and dirs, all not $bookID
		if (is_numeric($file)
		AND $file !== $bookID)
			rrmdir($file);
	closedir($dir);
	}

//function forceVoiceIfNecessary($atParagraph, &$speaker, &$speakerSex)
//	{
//	global $readBookDB;
//	global $theseMales;
//	global $theseFemales;
//	global $theseSourceFixes;
//	global $theseNewWords;
//
//	$fixData = accessProperDB( "SELECT * FROM $theseSourceFixes "
//		. " WHERE paragraphNumber = '$atParagraph' AND cmd = 'changeVoice'"
//	);
//	if (count($fixData) == 0)
//		return("");
//	$to = $fixData[0]['parm1'];
//	$isTo = accessProperDB( "SELECT * FROM $theseMales WHERE label  = '$to'");
//	if (count($isTo) > 0)
//		$sex = "male";
//	else
//		{
//		$isTo = accessProperDB( "SELECT * FROM $theseFemales WHERE label  = '$to'");
//		if (count($isTo) > 0)
//			$sex = "female";
//		else
//			{
//			$isTo = accessProperDB( "SELECT * FROM $theseNewWords WHERE label  = '$to'");
//			if (count($isTo) > 0)
//				$sex = "ambiguous";
//			else
//				logMsg("Software error: readBook line " . __LINE__, "Error");
//			}
//		}
//	if (count($isTo) > 0)
//		{
//		$speaker = $to;
//		$speakerSex = $sex;
//		return ($isTo[0]['voice']);
//		}
//	else
//		logMsg("Software error: readBook line " . __LINE__, "Error");
//	}

//function forceName($paragraphNumber, &$speakerSex, &$speaker)
//	{
//	global $readBookDB;
//	global $theseSourceFixes;
//	$fixData = accessProperDB( "SELECT * FROM $theseSourceFixes WHERE paragraphNumber = '$paragraphNumber'");
//	if (count($fixData) > 0)
//		{
//		if ($fixData[0]['cmd'] === "Name")
//			{
//			$speaker = $fixData[0]['parm'];
//			$speakerSex = $fixData[0]['sex'];
//			return true;
//			}
//		}
//	return false;
//	}
//
//function forceSex($paragraphNumber, &$speakerSex, &$speaker)
//	{
//	global $readBookDB;
//	global $theseSourceFixes;
//	$fixData = accessProperDB( "SELECT * FROM $theseSourceFixes WHERE paragraphNumber = '$paragraphNumber'");
//	if (count($fixData) > 0)
//		{
//		if ($fixData[0]['cmd'] === "Sex")
//			{
//			$speakerSex = $fixData[0]['sex'];
//			$speaker = "Forced $speakerSex";
//			return true;
//			}
//		}
//	return false;
//	}

function reduceMonotany(&$htmBody)
	{
	// eliminate many phrases such as "he said". pretty difficult
	}

//function handleWHquestions(&$parag)
//	{
//	global $interrogative;
//	$sentences = preg_split('/([!\?\.⦃⦄])/u', $parag, NULL, PREG_SPLIT_DELIM_CAPTURE);
////	$sentences = preg_split('/(\?)/u', $parag, NULL, PREG_SPLIT_DELIM_CAPTURE);
//	if (is_array($sentences))
//		{
//		// 'wh' words usually do not have a rising inflection at the end of the sentence
//		// in which case we change '?' to '.'.
//		$sentenceCount = count($sentences);
//		for ($s = 1; $s < $sentenceCount; $s += 2)
//			{
//			if ($sentences[$s] !== "?")
//				continue;
//			// so it is a question in [$s-1]
//			// which is known to exist
//			$breakpoint = 1;
//			$result = $interrogative->analyzeQuestion($sentences[$s - 1] . "?");
//			if ($result['action'] != "keep")
//				$sentences[$s] = ".";
//			}
//		}
//	}

function getVoiceOfKnownMale($speaker)
	{
	global $readBookDB;
	global $theseMales;

	$v = accessProperDB( "SELECT voice FROM $theseMales WHERE label='$speaker'");
	if (count($v) > 0) // speaker found in theseMales
		{
		$voice = $v[0]['voice'];
		if ($voice === "")  // but has no assignment
			{
			$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'male' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
			if (count($data) > 0)
				{
				$voiceNumber = $data[0]['voice_number'];
				$voice = Lbrack . "v:$voiceNumber" . Rbrack;
				accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
				accessProperDB( "UPDATE $theseMales SET voice = '$voice' WHERE label = '$speaker'");
				}
			}
		return $voice;
		}
	else
		return "";
	}

function getVoiceOfKnownFemale($speaker)
	{
	global $readBookDB;
	global $theseFemales;

	$v = accessProperDB( "SELECT voice FROM $theseFemales WHERE label='$speaker'");
	if (count($v) > 0) // speaker found in theseFemales
		{
		$voice = $v[0]['voice'];
		if ($voice === "")  // but has no assignment
			{
			$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'female' AND disabled = 0 AND narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
			if (count($data) > 0)
				{
				$voiceNumber = $data[0]['voice_number'];
				$voice = Lbrack . "v:$voiceNumber" . Rbrack;
				accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
				accessProperDB( "UPDATE $theseFemales SET voice = '$voice' WHERE label = '$speaker'");
				}
			}
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
				accessProperDB( "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$speaker'");
				return $v;
				}
			elseif ($speaker[0] === 'f')
				{
				$v = $narrVoice . Lbrack . "r:feminine" . Rbrack;
				accessProperDB( "UPDATE $theseNewWords SET voice = '$v' WHERE label = '$speaker'");
				return $v;
				}
			}
	accessProperDB( "UPDATE $theseNewWords SET voice = '$narrVoice' WHERE label = '$speaker'");
	return $narrVoice;
//	$v = accessProperDB( "SELECT voice FROM $theseNewWords WHERE label='$speaker'");
//	if (count($v) > 0) // speaker found in $theseNewWords
//		{
//		$voice = $v[0]['voice'];
//		if ($voice === "")  // but has no assignment
//			{
//			$data = accessProperDB( "SELECT voice_number FROM voice_params WHERE sex = 'ambiguous' AND AND disabled = 0 narratorScore < 3.0 AND assigned = 0 ORDER BY 'sexConfidence' DESC");
//			if (count($data) > 0)
//				{
//				$voiceNumber = $data[0]['voice_number'];
//				$voice = Lbrack . "v:$voiceNumber" . Rbrack;
//				accessProperDB( "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
//				accessProperDB( "UPDATE $theseNewWords SET voice = '$voice' WHERE label = '$speaker'");
//				}
//			}
//		return $voice;
//		}
//	else
//		return "";
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
//		$v2 = forceVoiceIfNecessary($paragraphNumber, $speaker, $sex);
//		if ($v2 !== "")
//			return $v2;
		return $v;
		}
	$v = getVoiceOfKnownFemale($speaker);
	if ($v !== "")
		{
		$sex = "female";
//		$v2 = forceVoiceIfNecessary($paragraphNumber, $speaker, $sex);
//		if ($v2 !== "")
//			return $v2;
		return $v;
		}
	$v = getVoiceOfKnownNewWords($speaker);
	if ($v !== "")
		{
		$sex = "undetermined";
//		$v2 = forceVoiceIfNecessary($paragraphNumber, $speaker, $sex);
//		if ($v2 !== "")
//			return $v2;
		return $v;
		}
	}

function reverseMarkersForTTS(&$htmBody)
	{
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
		$breakpoint = 1;
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
				$breakpoint = 1;
				}
			}
		$htmBody = implode('', $markers);
		}
	// special handling
	$htmBody = preg_replace('/<br>/', Lbrack . "w:222" . Rbrack, $htmBody);
	$htmBody = preg_replace('/\b(hmm)\b/i', Lbrack . 'r:slow' . Rbrack . 'm' . Lbrack . "x:" . Rbrack, $htmBody);
	$htmBody = preg_replace('/\b(Ahh+?)\b/i', Lbrack . "r:slow" . Rbrack . "ah" . Lbrack . "x:" . Rbrack, $htmBody);
	$htmBody = preg_replace('/<.*?>/', '', $htmBody);
	$htmBody = preg_replace('/ex-/', 'x ', $htmBody);
	$htmBody = preg_replace('/-/', ' ', $htmBody);
	$htmBody = preg_replace('/—/', Lbrack . "w:99" . Rbrack, $htmBody);
	$htmBody = preg_replace('/…/', Lbrack . "w:99" . Rbrack, $htmBody);
	$htmBody = preg_replace('/ ‘/', Lbrack . "w:88" . Rbrack, $htmBody);
//	$htmBody = preg_replace('/’ /', Lbrack . "w:88" . Rbrack, $htmBody);
//	$htmBody = preg_replace('/No\./', "Nah.", $htmBody);
//	$htmBody .= Lbrack . "w:333" . Rbrack;
	}

function reverseMarkersForDisplay(&$input)
	{
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
				$breakpoint = 1;
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
			accessProperDB( "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?, ?)", $wholeName, "", $wholeName, 0, "", "", __LINE__, "");
			accessProperDB( "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			accessProperDB( "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?,?)", $wholeName, "", $wholeName, 0, "", "", __LINE__, "");
			accessProperDB( "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
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
			accessProperDB( "DELETE FROM  $theseMales WHERE label =  '$lastName'");
			// and make sure not found later
			//		accessProperDB( "REPLACE INTO $theseNotNames VALUES(?, ?)", $lastName, 1);
			}
		if (!in_DB($readBookDB, $firstName, $theseMales))
			{
			// add with count of 0
			accessProperDB( "INSERT INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", $firstName, 1, "", "", __LINE__, ""
			);
//getVoiceOfKnownMale($firstName, "");
			accessProperDB( "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", $firstName, 0, "", $context, __LINE__, ""
			);
			}
		else
			{
			$line = __LINE__;
			accessProperDB( "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $firstName);
			}
		}
	elseif (in_DB($readBookDB, $firstName, "females")) // only first names are in primary table, supposedly
		{
		if (in_DB($readBookDB, $lastName, $theseFemales))
			{
			accessProperDB( "DELETE FROM  $theseFemales WHERE label =  '$lastName'");
			// and make sure not found later
			//		accessProperDB( "REPLACE INTO $theseNotNames VALUES(?, ?)", $lastName, 1);
			}
		if (!in_DB($readBookDB, $firstName, $theseFemales))
			{
			// add with count of 0
			getVoiceOfKnownFemale($firstName, "");
			accessProperDB( "INSERT INTO $theseFemales VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", $firstName, 1, "", "", __LINE__, ""
			);
			accessProperDB( "REPLACE INTO $theseNewWords VALUES(?, ?, ?, ?, ?, ?, ?,?)",
				$firstName, "", $firstName, 0, "", $context, __LINE__, ""
			);
			}
		else
			{
			$line = __LINE__;
			accessProperDB( "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $firstName);
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
			if (!in_DB($readBookDB, $wholeName, $theseMales))
				accessProperDB( "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				{
				$line = __LINE__;
				accessProperDB( "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
				}
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			if (!in_DB($readBookDB, $wholeName, $theseFemales))
				accessProperDB( "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				accessProperDB( "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
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
		$len = mb_strlen($par);
		if (mb_strpos($par,LQ) === false)
		if ($len > $max_allowed)
			{
			$extra = $len % $max_allowed;
			$n = intval($len / $max_allowed) +1;
//			if ($extra < $half_max)
				$this_max = intval($len / $n)+10;
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
if ($long)
  $breakpoint=1;
			}
		}
	$htmBody = implode('', $pars);
	}

