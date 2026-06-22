<?php


/**
<script src="https://cdn.jsdelivr.net/npm/eruda">
</script>
<script>eruda.init();
</script>
 * reader.php — Paragraph-indexed reader with byte-based buffering.
 * Baseline: your original (as pasted)
 * Additions:
 *   - PRE is rendered as HTML (innerHTML)
 *   - Fixed-height scrolling container (#readerShell) instead of <main>
 *   - Robust auto-scroll: directly sets readerShell.scrollTop using precise offset math (no scrollIntoView)
 *
 * URL: /piper/reader.php?book=<bookNo>
 */

//error_reporting(E_ALL & ~E_NOTICE);
//ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
$readBookDBname = "sqlite:" . APP_DB;
$readBookDB = new PDO($readBookDBname);
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

// ----------- CONFIG -----------
// Sibling pages, addressed relative to this one so the reader works wherever
// the site is mounted.
$CLIENT_PARA_URL = 'client_para.php'; // GET ?book=&p=
$BUFFER_MIN_BYTES = 3000000; // 3 MB before starting speaking/resuming buffering
$BUFFER_MAX_BYTES = 3500000; // 3.5 MB ceiling to avoid CPU waste
$MAX_INFLIGHT = 2; // max paragraphs concurrently being synthesized
$SPEAK_PROXY_URL = 'speak.php'; // local relay to the speech engine
$AUDIO_PROXY_URL = 'audio.php'; // streams WAVs from wav_path

$book = $_GET['book'];
// These arrive in the URL when opened from a book page; otherwise fall back to
// what was stored when the book was processed.
$bookRow = accessDB($readBookDB, "SELECT title, author, narrVoice FROM bookTitle WHERE ID = ?", $book);
$bookRow = $bookRow[0] ?? [];
$title = $_GET['title']  ?? $bookRow['title']  ?? '';
$author = $_GET['author'] ?? $bookRow['author'] ?? '';
$narrVoiceNumber = $_GET['narrator'] ?? $bookRow['narrVoice'] ?? '';
$theseMales = "males$book";
$theseFemales = "females$book";
$theseNewWords = "newWords$book";
$theseFixes = "fixes$book";
$theseProcesses = "voiceChanges$book";
$startup = accessDB($readBookDB, "SELECT  lastParagraph FROM bookTitle WHERE ID = $book");
if (count($startup) === 1)
	{
	$start = $startup[0]['lastParagraph'] ;
	if ($start < 0)
		$start = 0;
	}
else
	$start = 0;
// An explicit ?start= (used by chapter jumps) overrides the saved position.
if (isset($_GET['start']) && is_numeric($_GET['start']) && (int) $_GET['start'] >= 0)
	$start = (int) $_GET['start'];

// Chapters recorded during processing, for the jump menu.
$chapterTable = "chapters$book";
$chapters = [];
try {
	$chapters = accessDB($readBookDB, "SELECT para, title, level FROM $chapterTable ORDER BY para");
} catch (Exception $e) {
	$chapters = [];
}
$toolBarSize=0;

$conf = [
    'book' => $book,
    'start' => $start,
    'toolBarSize' => $toolBarSize,
    'clientParaUrl' => $CLIENT_PARA_URL,
    'speakProxyUrl' => $SPEAK_PROXY_URL,
    'audioProxyUrl' => $AUDIO_PROXY_URL,
    'bufferMin' => $BUFFER_MIN_BYTES,
    'bufferMax' => $BUFFER_MAX_BYTES,
    'maxInflight' => $MAX_INFLIGHT,
];
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<title>Reader — <?= htmlspecialchars($book) ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" type="text/css" href="reader.css" />
<style>
    /* This forces those huge images to behave */
    img {
        max-width: 60% !important;
        max-height: 60vh !important;
        width: auto !important;
        height: auto !important;
        display: block !important;
        margin: 20px auto !important;
    }
    
    /* Ensuring your preference for serif and left-alignment */
    body, .paragraph-container {
        font-family: "libertinus serif regular", Georgia, serif !important;
        text-align: left !important;
        hyphens:auto;
    }
</style>
</head>
<body>
	<header>
		<div class="toolbar">
			<p>
<?php
echo "<h1>$title</h1><h3>by $author</h3><h4>[$narrVoiceNumber]</h4>";
?>
			</p>
		</div>	
		<div id=toolbar class="toolbar">
			<button id="btnPlay">&nbsp;&nbsp;&nbsp;Play&nbsp;&nbsp;&nbsp;</button>
<!--			<span class="pill" id="pillState">Paused</span>-->
			<button id="btnMenu" class="secondary" title="Menu">Menu</button>
<?php if (count($chapters) > 1): ?>
			<select id="chapterJump" class="secondary" title="Jump to chapter"
				onchange="if(this.value!=='')location.href='reader.php?book=<?= htmlspecialchars($book, ENT_QUOTES) ?>&start='+this.value;">
				<option value="">Chapters…</option>
<?php foreach ($chapters as $ch):
		$label = trim((string)$ch['title']) !== '' ? $ch['title'] : ('Section at ¶' . $ch['para']);
		$sel = ((int)$ch['para'] === (int)$start) ? ' selected' : ''; ?>
				<option value="<?= (int)$ch['para'] ?>"<?= $sel ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
<?php endforeach; ?>
			</select>
<?php endif; ?>
			<button class="secondary" onclick="increaseFontSize()">Aa+</button>
			<button class="secondary" onclick="decreaseFontSize()">Aa-</button>
			<div id="status">
				<span class="pill" id="pillBuf">Buf 0.0 / 0.0 MB</span>
<!--				<span class="pill" id="pillInflight">Inflight 0</span>-->
			</div>
		</div>
	</header>

	<div id=menu style=display:flex;background-color:LightYellow;>
		<div id=divMenu  class="toolbar" style=display:none; >
			<form  id=formMenu method="post" action="">
				<fieldset>
					<legend >Change Speaker</legend>
					<div>
						<input type="number" id="atParagraph" name="atParagraph" placeholder="At Paragraph">
						<input type="text" id="toName" name="toName" placeholder="To Name">
					</div>
				</fieldset>
				<fieldset>
					<legend >Pronunciation and Type of search</legend>
					<div>
						<input type="text" id="regex" name="regex" placeholder="Pattern">
						<label for="repl"> (No /'s if REGEX)</label><br>
						<input type="text" id="repl" name="repl" placeholder="Replacement">
						<input type="checkbox" id="frontMenu" name="frontMenu">
						<label for="frontMenu"> From front</label><br>
						<input type="checkbox" id="backMenu" name="backMenu">
						<label for="backMenu"> To back</label><br>
						<input type="checkbox" id="caseSensitiveMenu" name="caseSensitiveMenu">
						<label for="caseSensitiveMenu">Case Sensitive</label><br>
					</div>
					<div>
						<input type="checkbox" id="regexCheckBox" name="regexCheckBox">
						<label for="regexCheckBox"> REGEX</label><br>
					</div>
				</fieldset>
		</div>
		<div id=divMenu2  class="toolbar" style=display:none; >
				<fieldset>
					<legend >Change Voice Params: MOSTLY MULTIPLY</legend>
					<div>
						<input type="text" id="voiceNumber" name="voiceNumber" placeholder="Voice Number">
						<input type="text" id="voiceSpeed" name="voiceSpeed" placeholder="Speed multiplier">
						<input type="text" id="voicePitch" name="voicePitch" placeholder="Pitch multiplier">
						<input type="text" id="voiceVolume" name="voiceVolume" placeholder="Volume multiplier">
						<input type="text" id="voiceSex" name="voiceSex" placeholder="male, female, ambiguous">
						<input type="text" id="voiceDisabled" name="voiceDisabled" placeholder="Disabled: 0 or 1">
						<input type="text" id="voiceNoise" name="voiceNoise" placeholder="Prosody multiplier">
						<input type="text" id="voiceNoiseW" name="voiceNoiseW" placeholder="Hesitation Multiplier">
					</div>
				</fieldset>
				<fieldset>
					<legend >Skip Forward or Backward</legend>
					<div>
						<input type="number" id="howFar" name="howFar" placeholder="N paragraphs">
					</div>
				</fieldset>
				<input style=font-size:large;width:33% type="submit" value="Done">			
			</form>
		</div>
		<div id=bookDisplay>
		</div>
	</div>

<?php
if ($_SERVER["REQUEST_METHOD"] === "POST")
	{
	if (isset($_POST['voiceNumber']))
		{
?>
<script>
document.getElementById("formMenu").reset();
// hide Menu form
f = document.getElementById('divMenu');
f.style.display = 'none';
f = document.getElementById('divMenu2');
f.style.display = 'none';
</script>
<?php
		$voice_number = trim($_POST['voiceNumber']);
		if ($voice_number != "")
			{
			if (isset($_POST['voiceDisabled'])
			AND trim($_POST['voiceDisabled']) != "")
				{
				$voiceDisabled = trim($_POST['voiceDisabled']);
				accessDB($readBookDB, "UPDATE voice_params SET disabled = '$voiceDisabled'  WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voiceSpeed'])
			AND trim($_POST['voiceSpeed']) != "")

				{
				$newSpeed = trim($_POST['voiceSpeed']);
				accessDB($readBookDB, "UPDATE voice_params SET newSpeed = newSpeed * '$newSpeed' WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voicePitch'])
			AND trim($_POST['voicePitch']) != "")
				{
				$voicePitch = trim($_POST['voicePitch']);
				accessDB($readBookDB, "UPDATE voice_params SET pitch = pitch * '$voicePitch' WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voiceVolume'])
			AND trim($_POST['voiceVolume']) != "")
				{
				$voiceVolume = trim($_POST['voiceVolume']);
				accessDB($readBookDB, "UPDATE voice_params SET volume = volume * '$voiceVolume' WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voiceSex'])
			AND trim($_POST['voiceSex']) != "")
				{
				$voiceSex = trim($_POST['voiceSex']);
				accessDB($readBookDB, "UPDATE voice_params SET sex = '$voiceSex' WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voiceNoise'])
			AND trim($_POST['voiceNoise']) != "")
				{
				$voiceNoise = trim($_POST['voiceNoise']);
				accessDB($readBookDB, "UPDATE voice_params SET noise = noise * '$voiceNoise' WHERE voice_number = '$voice_number'");
				}
			if (isset($_POST['voiceNoiseW'])
			AND trim($_POST['voiceNoiseW']) != "")
				{
				$voiceNoiseW = trim($_POST['voiceNoiseW']);
				accessDB($readBookDB, "UPDATE voice_params SET noise_w = noise_w * '$voiceNoiseW' WHERE voice_number = '$voice_number'");
				}
			$currentP = accessDB($readBookDB, "SELECT lastParagraph FROM bookTitle WHERE ID = $book");
			if (count($currentP) === 1)
				{
				$next = $currentP[0]['lastParagraph'] - 3;
				if ($next < 0)
					$next = 0;
				accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = $next WHERE ID = $book");
				}
			}
		}

	// handle name errors
	if (isset($_POST['atParagraph'])
	AND $_POST['atParagraph'] > "")
		{
?>
<script>
document.getElementById("formMenu").reset();
// hide Menu form
f = document.getElementById('divMenu');
f.style.display = 'none';
f = document.getElementById('divMenu2');
f.style.display = 'none';
</script>
<?php
		while (true)
			{
			// change speaker in TTS file, ID'ed by a leading LQ and a Lbrack
			// add debugging to PRE file to indicate what was done
			$atParagraph = $_POST['atParagraph'];
			$desiredName = $_POST['toName'];
			$fileNameTTS = "./$book/TTS/$atParagraph.txt";
			$fileNamePRE = "./$book/PRE/$atParagraph.txt";
			$textTTS = file_get_contents($fileNameTTS);
			$textPRE = file_get_contents($fileNamePRE);
			if ($textTTS === false)
				{
?>
<script>
alert("Invalid paragraph number.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
				break;
				}
			$processData = accessDB($readBookDB, "SELECT * FROM $theseProcesses WHERE atParagraph = '$atParagraph'");
			if (count($processData) === 0)
				{
//				$textPRE .= "<sup>Attempted name change failed because paragraph specified not spoken.</sup>";
//				file_put_contents($fileNamePRE, $textPRE);
?>
<script>
alert("Attempted name change failed because paragraph specified not spoken.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
				return;
				}
			accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = $atParagraph WHERE ID = $book");
			// Lbrack = "⦃";   // ⦃⦄		©		“ (LQ)			just for copy and pasting chars
			accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = $atParagraph - 3 WHERE ID = $book");

			$soughtSpeakerVoice =  getSpeakerVoiceFromTTSfile($textTTS);
//echo "1. currentSpeakerVoice = $soughtSpeakerVoice<br>";
				
			$desiredSpeakerVoice = getSpeakerVoiceFromDatabase($desiredName);
			if ($desiredSpeakerVoice === "")
				{
//				$textPRE .= "<sup>Attempted name change failed because $desiredName not found at the time.</sup>";
//				file_put_contents($fileNamePRE, $textPRE);
?>
<script>
alert("Attempted name change failed because desired name not found at the time.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php

				return;
				}
			$textTTS = preg_replace("/“$soughtSpeakerVoice/u", "“$desiredSpeakerVoice", $textTTS);
			file_put_contents($fileNameTTS, $textTTS);
			$textPRE .= "<sub>Changed to $desiredName $desiredSpeakerVoice</sub>";
			file_put_contents($fileNamePRE, $textPRE);
//echo "Started on paragraph '$atParagraph' replacing $soughtSpeakerVoice with $desiredSpeakerVoice.<br>";
			while (true)
				{
				$atParagraph+=2;
				$prevP = $atParagraph -1;
//				$fileNameTTS = "./$book/TTS/$atParagraph.txt";
				$fileNamePRE = "./$book/PRE/$prevP.txt";
//				$textTTS = file_get_contents($fileNameTTS);
				$textPRE = file_get_contents($fileNamePRE);
				$processData = accessDB($readBookDB, "SELECT * FROM $theseProcesses WHERE atParagraph = '$prevP'");
				if (count($processData) === 0)
					{
//					$textPRE .= "<sup>Stopped voice changes because non-spoken paragraph handling not yet adequate.</sup>";
//					file_put_contents($fileNamePRE, $textPRE);
?>
<script>
alert("Stopped voice changes because non-spoken paragraph handling not yet adequate, case 1.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
					break 2;
					}
				$fileNameTTS = "./$book/TTS/$atParagraph.txt";
				$fileNamePRE = "./$book/PRE/$atParagraph.txt";
				$textTTS = file_get_contents($fileNameTTS);
				$textPRE = file_get_contents($fileNamePRE);
				$processData = accessDB($readBookDB, "SELECT * FROM $theseProcesses WHERE atParagraph = '$atParagraph'");
				if (count($processData) === 0)
					{
//					$textPRE .= "<sup>Stopped voice changes because non-spoken paragraph handling not yet adequate.</sup>";
//					file_put_contents($fileNamePRE, $textPRE);
?>
<script>
alert("Stopped voice changes because non-spoken paragraph handling not yet adequate, case 2.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
					break 2;
					}
				if ($textTTS === false)
					{
?>
<script>
alert("Stopped because no more text files found.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
//					$textPRE .= "<sup>Stopped on paragraph '$atParagraph' because no more text files found.</sup>";
//					file_put_contents($fileNamePRE, $textPRE);
					break 2;	// no text to work on
					}
				// next file exists and processData exists
				$foundSpeakerVoice = getSpeakerVoiceFromTTSfile($textTTS);
//echo "2. foundSpeakerVoice = $foundSpeakerVoice<br>";
				if ($processData[0]['specified'] === 'Y')
					{
					if ($foundSpeakerVoice !== $desiredSpeakerVoice)
						{
//$textPRE .= "<sup>Stopped on paragraph '$atParagraph' because different specifically named speaker, $foundSpeakerVoice, found instead of $desiredSpeakerVoice.</sup>";
//					file_put_contents($fileNamePRE, $textPRE);
?>
<script>
alert("Stopped because different specifically named speaker found.");
setTimeout(function() {
    history.go(-1);
}, 150); // The delay can be adjusted if needed
</script>
<?php
					break 2;
//echo "Stopped on paragraph '$atParagraph' because different specifically named speaker, $foundSpeaker Voice, found instead of $desiredSpeakerVoice.<br>";
//					break 2;	// finished processData with name other than $soughtSpeakerVoice
						}
//					continue;
					}
				if ($desiredSpeakerVoice !== $foundSpeakerVoice)
					{
					$textTTS = preg_replace("/“$foundSpeakerVoice/u", "“$desiredSpeakerVoice", $textTTS);
					file_put_contents($fileNameTTS, $textTTS);
					$textPRE .= "<sub>Changed to $desiredName $desiredSpeakerVoice</sub>";
					file_put_contents($fileNamePRE, $textPRE);
//echo "Changed speaker $foundSpeakerVoice in paragraph '$atParagraph' to $desiredSpeakerVoice'<br>";
					}
				}
			}
?>
<script>
history.go(-1);
</script>
<?php
		}
	if (isset($_POST['regex']))
		{
		// pronunciation errors
		$regex = $_POST['regex'];
?>
<script>
document.getElementById("formMenu").reset();
// hide Menu form
f = document.getElementById('divMenu');
f.style.display = 'none';
f = document.getElementById('divMenu2');
f.style.display = 'none';
</script>
<?php
		if ($regex !== "")
			{
			$repl = $_POST['repl'];
			$msg = "Changing pronunciation of '$regex' to '$repl' ";

			$delimitedRegex = $regex;

			if (isset($_POST['regexCheckBox']))
				$delimitedRegex = $_POST['regexCheckBox'];
			else
				{
				if (isset($_POST['frontMenu']))
					{
					$delimitedRegex = "\b" . $delimitedRegex;
					$msg .= "when found at the front of the text";
					}
				if (isset($_POST['backMenu']))
					{
					$delimitedRegex .= "\b";
					if (!isset($_POST['frontMenu']))
						$msg .= "when found at the back of the text";
					else
						$msg .= " or at the back";
					}
				}
			$delimitedRegex = "/" . $delimitedRegex . "/u";
			if (!isset($_POST['caseSensitiveMenu']))
				{
				$delimitedRegex .= 'i';
				$msg .= ", ignoring capital letters.";
				}
			else
				$msg .= ", spelled exactly as entered.";

			// Validate the regex pattern
			if (@preg_match($delimitedRegex, '') === false)
				{
//				echo "Invalid regex pattern.";
?>
<script>
alert("Invalid regex pattern.");
history.go(-1);
</script>
<?php
				}
			else
				{
				// add or replece data into "pronunciation" table
				accessDB($readBookDB, "REPLACE INTO pronunciation VALUES(?, ?, ?, ?)", 0,
					$delimitedRegex, $repl, $regex);
//				echo "$msg<br><br>Use back arrow to continue.";
				}
			$currentP = accessDB($readBookDB, "SELECT lastParagraph FROM bookTitle WHERE ID = $book");
			if (count($currentP) === 1)
				{
				$next = $currentP[0]['lastParagraph'] - 3;
				if ($next < 0)
					$next = 0;
				accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = $next WHERE ID = $book");
				}
?>
<script>
history.go(-1);
</script>
<?php
			}
		}
	if (isset($_POST['howFar']))
		{
		// pronunciation errors
		$howFar = $_POST['howFar'];
?>
<script>
document.getElementById("formMenu").reset();
// hide Menu form
f = document.getElementById('divMenu');
f.style.display = 'none';
f = document.getElementById('divMenu2');
f.style.display = 'none';
</script>
<?php
		if ($howFar !== "")
			{
			$currentP = accessDB($readBookDB, "SELECT lastParagraph FROM bookTitle WHERE ID = $book");
			if (count($currentP) === 1)
				{
				$next = $currentP[0]['lastParagraph'] + $howFar;
				if ($next < 0)
					$next = 0;
				else
					{
					$fileNameTTS = "./$book/TTS/$next.txt";
					if (file_get_contents($fileNameTTS) === false)
						{
//						$next = $currentP[0]['lastParagraph'];
?>
<script>
alert("Movement out of range.");
history.go(-1);
</script>
<?php
						return;
						}
					}
				accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = $next WHERE ID = $book");
				}
			else
				logMsg("Software error: Failed at readBook line " . __LINE__, "Error");
?>
<script>
history.go(-1);
</script>
<?php
			}
		}
	}
function getSpeakerVoiceFromTTSfile(&$textTTS)
	{
	$start = mb_strpos($textTTS, '“' . "⦃v:");
	if ($start !== false)
		{
		$end = mb_strpos($textTTS, "⦄", $start);
		if ($end !== false)
			{
			$speakerVoice = "⦃" . mb_substr($textTTS, $start+2, $end - 1 - $start);
			return($speakerVoice);
			}
		}
//	echo "Software error in reader.php at line " . __LINE__ . ".<br>";
//	echo "Use back arrow to continue.";
?>
<script>
alert("Software error 487 in reader.php.");
history.go(-1);
</script>
<?php
	}
function  getParagraphFromBookTitleTable($book)
	{
	global $readBookDB;
	
	$startup = accessDB($readBookDB, "SELECT  lastParagraph FROM bookTitle WHERE ID = $book");
	if (count($startup) === 1)
		{
		$start = $startup[0]['lastParagraph'] ;
		if ($start < 0)
			$start = 0;
		}
	else
		$start = 0;
	return $start;
	}
function getSpeakerVoiceFromDatabase($desiredName)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	global $theseNewWords;

	$desiredVoice = accessDB($readBookDB, "SELECT voice FROM $theseMales WHERE label = '$desiredName'
		UNION ALL SELECT voice FROM $theseFemales WHERE label = '$desiredName'
		UNION ALL SELECT voice FROM $theseNewWords WHERE label = '$desiredName'");
	if (count($desiredVoice) === 1)
		return $desiredVoice[0]['voice'];
	return "";
	}
?>
	<div id="readerShell">
		<div id="content">
			<!-- paragraphs appended here -->
		</div>
	</div>
	
	<footer>
		Reader: <span id="bookLabel"><?= htmlspecialchars($book) ?></span> —
		<span class="small">↑/↓ or PgUp/PgDn to scroll • Enter = Play/Pause</span>
	</footer>

<script>
const CONF = <?= json_encode($conf, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const KEEP_BACK = 2; // set 0 to keep none, 2 is a nice default
// ---- State ----
let curP = 0;                    // current paragraph index (playing cursor)
let maxFetchedP = -1;            // highest paragraph fetched from Client
let inflight = new Set();        // paragraphs currently in /speak
let ready = new Map();           // p -> { segments:[{url, bytes, dur}], totalBytes }
let preCache = new Map();        // p -> pre_text (HTML)
let ttsCache = new Map();        // p -> tts_text
let isPlaying = false;
let audio = new Audio();
audio.preload = "auto";
let curSegIdx = 0;               // segment index within current paragraph
let curSegList = [];             // urls for current paragraph
let bufBytesAhead = 0;
let curBufferLimit = CONF.bufferMin;

// ---- DOM ----
const elToolbar   = document.getElementById('toolbar');
const elShell   = document.getElementById('readerShell');
const elContent = document.getElementById('content');
const elPlay    = document.getElementById('btnPlay');
//const elPause   = document.getElementById('btnPause');
//const elBack    = document.getElementById('btnBack');
const elMenu    = document.getElementById('btnMenu');
//const pillState = document.getElementById('pillState');
const pillBuf   = document.getElementById('pillBuf');
//const pillInflight = document.getElementById('pillInflight');

function disableSubmitButton(form)
	{
	// Find the submit button by its ID
	let submitButton = document.getElementById('submitBtn');

	// Disable the button and optionally change its text
	submitButton.disabled = true;
	submitButton.value = 'Processing...';
	}
function setState(s)
	{
//console.log("setState1");
//	pillState.textContent = s;
	if (s === "Idle"
	|| s === "Paused")
		{
		// "Idle" is the ready-to-start state; only call it Paused once playback
		// has actually been interrupted.
		btnPlay.textContent = (s === "Paused") ? 'Paused' : 'Play';
		curP = CONF.start;
		}
//console.log("setState2");
	}
function fmtMB(n) { return (n / 1048576).toFixed(1); }
function updatePills() {
  pillBuf.textContent = `Buf ${fmtMB(bufBytesAhead)} / ${fmtMB(curBufferLimit)} MB`;
//  pillInflight.textContent = `Inflight ${inflight.size}`;
}

function paraEl(p) { return document.getElementById(`p-${p}`); }

// --------- Precise scrolling inside #readerShell (no scrollIntoView) ----------
function offsetTopWithin(el, ancestor){
  let top = 0;
  let node = el;
  while (node && node !== ancestor) {
    top += node.offsetTop;
    node = node.offsetParent;
  }
  return top;
}
//function scrollActiveIntoView(p, topPad=60){
//  const el = paraEl(p);
//  if (!el) return;
//  const target = Math.max(0, offsetTopWithin(el, elContent), topPad);
//  elShell.scrollTo({ top: target, behavior: 'smooth' });
//}
//function scrollActiveIntoView(p){
//  const el = paraEl(p);
//  if (!el) return;
//  if (CONF.toolBarSize === 0)
//	  CONF.toolBarSize = elToolbar.offsetHeight;
//}
function pruneBefore(floorP)
	{
	// Remove all paragraphs with index < floorP
	for (let p = 0; p < floorP; p++)
		{
		const el = document.getElementById(`p-${p}`);
		if (el && el.parentNode)
			{
//			pHeight = el.scrollHeight;
//			elShell.scrollTo({ top: CONF.toolBarSize, behavior: 'smooth' });
			el.parentNode.removeChild(el);
			}
		preCache.delete(p);
		ttsCache.delete(p);
		ready.delete(p);
		}
	recomputeBuffer();
	}

// --------- Fetch & speak ----------
async function fetchPara(p)
	{
	if (preCache.has(p)
	&& ttsCache.has(p))
		return;

	const u = new URL(CONF.clientParaUrl, window.location.href);
	u.searchParams.set('book', CONF.book);
	u.searchParams.set('p', p);
	const res = await fetch(u.href, {cache: 'no-cache'});
	const raw = await res.text();
	const ct = res.headers.get('content-type') || '';
	if (!ct.includes('application/json'))
		{
		console.error('Non-JSON response', res.status, raw.slice(0, 500));
		throw new Error(`client_para: non-JSON (${res.status})`);
		}
	let j;
	try
		{
		j = JSON.parse(raw);
		}
	catch
		{
		console.error('JSON parse failed', res.status, raw.slice(0, 500));
		throw new Error(`client_para: bad JSON (${res.status})`);
		}

	if (j.p !== p)
		{
		 maxFetchedP = j.p;
		 curP = maxFetchedP;
		}

	const preHtml = j.pre_text || '';
	const ttsText = j.tts_text || '';
//	const { preHtml, ttsText } = stripAndCaptureDirectives(p, rawPre, rawTts);

	preCache.set(p, preHtml);
	ttsCache.set(p, ttsText);

	if (!document.getElementById(`p-${p}`))
		{
		const div = document.createElement('div');
		div.className = 'p';
		div.id = `p-${p}`;
		// Render PRE as HTML
		div.innerHTML = preHtml;
		elContent.appendChild(div);
		}

	if (p > maxFetchedP)
		maxFetchedP = p;
	}

async function speakPara(p)
	{
	if (ready.has(p)
	|| inflight.has(p))
		return;
	const text = ttsCache.get(p);
	if (!text)
		throw new Error(`No tts_text for p=${p}`);

//console.log("Adding " + p + " to inflight.");
	inflight.add(p);
	updatePills();
	try
		{
		const sid = `${CONF.book}:p${String(p)}`;
		const res = await fetch(CONF.speakProxyUrl,
			{
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({sid, text})
			});
		if (!res.ok)
			throw new Error(`speak HTTP ${res.status}`);
		const j = await res.json();
		if (!j.ok)
			throw new Error(`speak error: ${j.error || 'unknown'}`);

		const {segs, topBytes} = buildSegmentsFromSpeak(j);
		let totalBytes = segs.reduce((a, b) => a + (Number.isFinite(b.bytes) ? b.bytes : 0), 0);
		if (totalBytes === 0)
			{
			totalBytes = await probeSegmentsBytes(segs);
			}
		if (totalBytes === 0
		&& topBytes > 0)
			{
			totalBytes = topBytes;
			if (segs.length)
				segs[0].bytes = topBytes;
			}
		if (totalBytes === 0)
			totalBytes = 1; // guard

		ready.set(p, {segments: segs, totalBytes});
		const div = paraEl(p);
		if (div)
			div.classList.add('ready');
		}
	finally
		{
		inflight.delete(p);
		updatePills();
		}

	recomputeBuffer();
	}
// --------- Precise scrolling inside #readerShell ----------
function offsetTopWithin(el, ancestor){
  let top = 0;
  let node = el;
  while (node && node !== ancestor) {
    top += node.offsetTop;
    node = node.offsetParent;
  }
  return top;
}

/**
 * Custom Smooth Scroll: Controls speed more precisely than native 'smooth'
 */
function slowScrollTo(targetY, duration) {
    const startY = elShell.scrollTop;
    const diff = targetY - startY;
    let start = null;

    function step(timestamp) {
        if (!start) start = timestamp;
        const progress = timestamp - start;
        const percent = Math.min(progress / duration, 1);
        
        // Easing function for smoother feel
        const ease = percent < 0.5 ? 2 * percent * percent : -1 + (4 - 2 * percent) * percent;
        
        elShell.scrollTop = startY + diff * ease;

        if (progress < duration) {
            window.requestAnimationFrame(step);
        }
    }
    window.requestAnimationFrame(step);
}

// ---- Updated State ----
let isBuffering = false; // Concurrency Lock

/**
 * Anchor the active paragraph strictly to the top of the shell.
 */
function scrollActiveIntoView(p) {
    const el = paraEl(p);
    if (!el) return;

    // Ensure the container is positioned so offsetTop is accurate
    // targetY is the distance from the top of #content to the paragraph
    const targetY = el.offsetTop;
    
    // Instant jump to the top for stability
    elShell.scrollTop = targetY;
}

/**
 * Highlighting and Scroll Trigger
 */
function highlight(p, active) {
    const div = paraEl(p);
    if (!div) return;

    if (active) {
        div.classList.add('playing');
        scrollActiveIntoView(p);
    } else {
        div.classList.remove('playing');
    }
}


/**
 * Stable Pruning
 */
function pruneBefore(floorP) {
    for (let p = 0; p < floorP; p++) {
        const el = document.getElementById(`p-${p}`);
        if (el) {
            const h = el.offsetHeight;
            el.remove();
            elShell.scrollTop -= h; // Compensate for deleted height
            preCache.delete(p);
            ttsCache.delete(p);
            ready.delete(p);
        }
    }
}
function buildAudioUrlFromPaths(raw)
{
  if (!raw) return '';
  // Windows absolute path? use win=
  if (/^[A-Za-z]:\\/.test(raw) || raw.startsWith('\\\\')) {
    return `${CONF.audioProxyUrl}?win=${encodeURIComponent(raw)}`;
  }
  // Anything else (e.g., /mnt/c/...), base64 in path=
  return `${CONF.audioProxyUrl}?path=${encodeURIComponent(btoa(raw))}`;
}

async function probeSegmentsBytes(segs)
	{
	let sum = 0;
	for (const s of segs)
		{
		if (!s.url)
			continue;
		// Try HEAD for Content-Length
		try
			{
			const r = await fetch
				(s.url,
					{method:'HEAD'
					}
				);
			const cl = r.headers.get('content-length');
			if (cl
			&& Number(cl) > 0)
				{
				s.bytes = Number(cl);
				sum += s.bytes;
				continue;
				}
			}
		catch
			{
			}
		// Fallback: tiny range GET to read Content-Range total
		try
			{
			const r = await fetch
				(s.url,
					{headers:
						{'Range': 'bytes=0-1'
						}
					}
				);
			const cr = r.headers.get('content-range'); // "bytes 0-1/123456"
			const m = cr && /\/(\d+)$/.exec(cr);
			if (m
			&& Number(m[1]) > 0)
				{
				s.bytes = Number(m[1]);
				sum += s.bytes; continue;
				}
			const cl2 = r.headers.get('content-length');
			if (cl2
			&& Number(cl2) > 0)
				{
				s.bytes = Number(cl2);
				sum += s.bytes;
				}
			}
		catch
			{
			}
		}
	return sum;
	}

function buildSegmentsFromSpeak(j)
{
  const topUrl = buildAudioUrlFromPaths(j.wav_path_win || j.wav_path || '');
  const topBytes = Number(j.wav_bytes || 0);
  const topDurMs = Number((j.wav_duration_sec || 0) * 1000);

  // Detect whether segments have their own files
  const segsHaveFiles = Array.isArray(j.segments) && j.segments.some(
    s => !!(s && (s.wav_path_win || s.wav_path))
  );

  if (segsHaveFiles) {
    // Multi-file: each segment has its own wav_path
    const segs = j.segments
      .filter(Boolean)
      .map(s => ({
        url: buildAudioUrlFromPaths(s.wav_path_win || s.wav_path || ''),
        bytes: Number(s.bytes || s.size || s.wav_bytes || 0) || 0,
        dur: Number(s.duration_ms || 0)
      }));
    return {segs, topUrl, topBytes, topDurMs, multi: true};
  }

  // Single-file: segments are timing only; use top-level wav
  const segs = [{
    url: topUrl,
    bytes: topBytes || 0,
    dur: topDurMs
  }];
  return {segs, topUrl, topBytes, topDurMs, multi: false};
}

function recomputeBuffer()
	{
	let sum = 0;
	let p = curP + 1;
	while (ready.has(p))
		{
		sum += (ready.get(p).totalBytes || 0);
		p++;
		}
	bufBytesAhead = sum;
console.log("recomputeBuffer: bufBytesAhead = " + sum + " at " + p);
	updatePills();
	}

/**
 * Robust Buffering: Fills gaps and stays ahead of the speaker
 */
async function ensureMinBuffer() {
    if (isBuffering) return; // Prevent overlapping buffer calls
    isBuffering = true;

    try {
        recomputeBuffer();
        
        while (bufBytesAhead < curBufferLimit) {
            if (!isPlaying && bufBytesAhead > CONF.bufferMin) break;

            // inflight control: Don't overwhelm the Piper server
            if (inflight.size >= CONF.maxInflight) {
                await new Promise(r => setTimeout(r, 400));
                recomputeBuffer();
                continue;
            }

            // GAP FILLER: Find the NEXT paragraph that isn't ready yet
            let target = curP;
            while (ready.has(target)) {
                target++;
            }

            // Don't fetch if we've reached the end of the book
            await fetchPara(target);
            await speakPara(target);
            
            recomputeBuffer();
            setState(bufBytesAhead > CONF.bufferMin ? 'Ready' : 'Buffering...');
        }
    } catch (e) {
        console.error("Buffer error:", e);
    } finally {
        isBuffering = false;
        curBufferLimit = CONF.bufferMin * 2; // Increase limit once we're moving
    }
}

/**
 * Handle playback with visual dwell for images
 */
async function playCurrent() {
    if (!ready.has(curP)) {
        await fetchPara(curP);
        await speakPara(curP);
    }

    const startTime = Date.now();
    const r = ready.get(curP);
    curSegIdx = 0;
    curSegList = r.segments.map(s => s.url);

    const playSeg = (idx) => {
        if (idx >= curSegList.length) {
            const duration = Date.now() - startTime;
            // Silent/Image blocks wait 1.5s
            const dwellTime = (duration < 500) ? 1500 : 0;

            setTimeout(() => {
                highlight(curP, false);
                curP++;

                // Keep only a small buffer of history
                pruneBefore(Math.max(0, curP - 2));

                if (isPlaying) {
                    playCurrent().catch(e => console.error(e));
                    ensureMinBuffer().catch(() => {});
                }
            }, dwellTime);
            return;
        }

        audio.src = curSegList[idx];
        audio.onended = () => playSeg(idx + 1);
        audio.onerror = () => { isPlaying = false; };
        
        highlight(curP, true);
        audio.play().catch(err => { isPlaying = false; });
    };

    playSeg(0);
}
// ---- Controls ----
elPlay.addEventListener('click', async () =>
	{
	btnPlay.textContent = "Playing";
	if (isPlaying)
		return;
	isPlaying = true;
	await ensureMinBuffer();
	playCurrent();
	});

//elPause.addEventListener('click', () =>
//	{
//	btnPlay.textContent = "Paused";
//	isPlaying = false;
//	audio.pause();
//	setState('Paused');
//	});
//elBack.addEventListener('click', () =>
//	{
//	// back one paragraph (no prioritization; may need to buffer)
//	if (curP > 0)
//		{
//		highlight(curP, false);
//		curP -= 1;
//		isPlaying = false;
//		audio.pause();
//		setState('Rewound');
//		// bring it into view within the shell
//		scrollActiveIntoView(curP);
//		}
//	});

elMenu.addEventListener('click', () =>
	{
	audio.pause();
	isPlaying = false;
	setState('Paused');
	// display Menu form
	f = document.getElementById('divMenu');
	f.style.display = 'block';
	f = document.getElementById('divMenu2');
	f.style.display = 'block';
	});

function increaseFontSize() {
    var element = document.getElementById("content");
    var currentSize = window.getComputedStyle(element, null).getPropertyValue('font-size');
    var newSize = parseFloat(currentSize) * 1.05; // Increase by 5%
    element.style.fontSize = newSize + 'px';
}
function decreaseFontSize() {
    var element = document.getElementById("content");
    var currentSize = window.getComputedStyle(element, null).getPropertyValue('font-size');
    var newSize = parseFloat(currentSize) * 0.95;
    // Removed parseInt to allow sub-pixel scaling for smoother display
    element.style.fontSize = newSize + 'px';
}
// Basic keyboard shortcuts
//document.addEventListener('keydown', (e) => {
//]  if (e.key === 'Enter')
//  {
//    (isPlaying ? elPause : elPlay).click();
//  }
//});

// Initial fetch of p=0 to render text
fetchPara(CONF.start).then(() => setState('Idle')).catch(e => {
  console.error(e);
  setState('Error');
});

</script>
</body>
</html>
