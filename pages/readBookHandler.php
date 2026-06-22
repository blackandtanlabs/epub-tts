<?php
	{
	
	header("Cache-Control: max-age=6"); //1 hour = (60sec * 60min)
	$command = '';
	$table = '';
	$word = '';
	$count = 0;
	$usageCount = 0;
	$bookID = -2;

//$path = rawurldecode($_GET["book"]);
	$postDelim = ':';
	$serverDelim = '=\&';
	$readBookDB = new PDO("sqlite:" . __DIR__ . "/../labelCheck.db");
//	$AllVoices = accessDB($readBookDB, "SELECT voice_number, volume, sex, assigned FROM voice_params");

	$data = $_POST['data'];
	$temp = substr($data, 0, 6);
//	logMsg("substr(data, 0, 6)= $temp");
	if ($temp === "CHANGE")
		changeVoice($data);
	$stmt = $readBookDB->prepare($data); // recived an SQL
	if (!$stmt->execute())
		epubWarning("Database error 1 acessing ReadBookDB: '$data')");
	return;
	}

function accessDB($DB, $sql, ...$parms)
	{
	$data = array();
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
//	if (!$ret)
//		logMsg("Software error: $DB: $sql failed at readBook line " . __LINE__, "Error");
//	else
	$data = $preparedSQL->fetchAll();
	return $data;
	}
function changeVoice($data)
	{
//$dataExample = "CHANGEVOICE(70241,Monica,newWords70241,females70241, female)";
//
// the existing voice for $speaker is known to be narrator and it's voice must remain intact
// but a new voice for $speaker of $sex must be obtained and assigned
	$parms = preg_split('/(,)/u', $data);
	if (count($parms) > 1)
		{
		$bookID = $parms[1];
		$speaker = $parms[2];
		$fromTable = $parms[3];
		$toTable = $parms[4];
		$sex = trim($parms[5]);
		if ($sex[0] === "(")		// indicates verb
			assignWord($bookID, $sex, $speaker, $fromTable, $toTable);
		else
			assignVoice($bookID, $sex, $speaker, $fromTable, $toTable);
		}
//	sql = `UPDATE ${where} SET voice =  (SELECT voice_number FROM voice_params WHERE sex = '${sex}' AND assigned = 0 ORDER BY sexConfidence DESC) WHERE label = '${lastLabel}`;
//	sql = `UPDATE voice_params SET assigned = 1 WHERE voice_number =
//		(SELECT voice_number FROM voice_params WHERE sex = '${sex}' AND assigned = 0 ORDER BY sexConfidence DESC) WHERE label = '${lastLabel}`;
//	sql = `DELETE FROM ${newWords} WHERE label = '${lastLabel}'`;
//	sql = `UPDATE ${where} SET context = ""  WHERE label = '${lastLabel}'`;
	}
function assignWord($bookID, $usage, $name, $fromTable, $toTable)
	{
	global $readBookDB;

	$usage = strtolower($usage);
	if ($usage === "(not)")
		accessDB($readBookDB, "REPLACE INTO $toTable VALUES(?, ?)", $name, 1);
	else
		accessDB($readBookDB, "REPLACE INTO $toTable VALUES(?, ?, ?)", $name, $usage, 1);
	accessDB($readBookDB, "DELETE FROM  $fromTable WHERE label = '$name'");
	}
function assignVoice($bookID, $sex, $name, $fromTable, $toTable)
	{
//CONST Lbrack = "⦃";   // ⦃⦄		©
//CONST Rbrack = "⦄";
	global $readBookDB;

	$sex = strtolower($sex);
	if ($sex === 'not')
		{	
		accessDB($readBookDB, "REPLACE INTO $toTable VALUES(?, ?)", $name, 1);
		accessDB($readBookDB, "DELETE FROM  $fromTable WHERE label = '$name'");
		}
	else
		{
		$data = accessDB($readBookDB, 
			"SELECT voice_number FROM voice_params WHERE sex = '$sex' AND assigned = 0 ORDER BY sexConfidence DESC");
		if (count($data) > 0)
			{
			// there is an available voice
			$voiceNumber = $data[mt_rand(0, count($data))]['voice_number'];
			$voice = '⦃v:' . $voiceNumber . '⦄';
			// assign it
			accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $voiceNumber");
			if (str_contains($toTable, $bookID))
				{
				accessDB($readBookDB, "REPLACE INTO $toTable SELECT * FROM $fromTable WHERE label = '$name'");
				accessDB($readBookDB, "UPDATE $toTable SET voice = '$voice', context = '' WHERE label = '$name'");
				}
			else
				accessDB($readBookDB, "REPLACE INTO $toTable VALUES(?, ?, ?)",  "$name", 1, "");
			accessDB($readBookDB, "DELETE FROM  $fromTable WHERE label = '$name'");
			}
		}
	}
function logMsg($txt)
	{
	error_log($txt . "\n", 4, "C:/xampp/htdocs/EPUB/pages/readBook.log");
	}

function epubWarning($msg)
	{
	if (strlen($msg) > 0)
		{
		if (empty($_POST['data']))
			echo "$msg<br><br>Use the back button to go back to normal page.";
		else
			echo "$msg";
		logMsg("Warning: " . $msg);
		}
	$msg = ' ';
	}

function epubInformation($msg)
	{
	if (strlen($msg) > 0)
		{
		if (empty($_POST['data']))
			echo "$msg<br><br>Use the back button to go back to normal page.";
		logMsg("Warning: " . $msg);
		}
	$msg = ' ';
	}

function var_dump_pre($mixed = null)
	{
	epubWarning('<pre>' . var_dump($mixed) . '</pre>');
	return null;
	}

//function get_unused_voice($sex)
//	{
//	global $AllVoices;
//	global $readBookDB;
//	for ($x = 4; $x < count($AllVoices); $x++) // start at 4 because 0-3 are screwed up
//		{
//		if ($AllVoices[$x]['sex'] !== $sex)
//			continue;
//		if ($AllVoices[$x]['assigned'] === 1)
//			continue;
//		$v = $AllVoices[$x]['voice_number'];
//		$AllVoices[$v]['assigned'] = 1;
//		accessDB($readBookDB, "UPDATE voice_params SET assigned = 1 WHERE voice_number = $v");
//		return $v;
//		}
//	}

//function assignVoice($sex)
//	{
//	$v = get_unused_voice($sex);
//	return "⦃v:" . $v . "⦄";
//	}

//function autoAssignVoices($data) // $data = "Voices($bookdID)"
//	{
//	global $readBookDB;
//	global $AllVoices;
//	
//	$bookID = substr($data, 7, 7);
//	$theseMales = "males$bookID";
//	$theseFemales = "females$bookID";
//
//	$sql = "SELECT * FROM $theseMales ORDER BY count DESC";
//	$stmt = $readBookDB->prepare($sql);
//	if ($stmt->execute())
//		$males = $stmt->fetchAll();
//	else
//		logMsg("Software error: readBookHandler line " . __LINE__, "Error");
//	$malesCount = count($males);
//
//	$sql = "SELECT * FROM $theseFemales ORDER BY count DESC";
//	$stmt = $readBookDB->prepare($sql);
//	if ($stmt->execute())
//		$females = $stmt->fetchAll();
//	else
//		logMsg("Software error: readBookHandler line " . __LINE__, "Error");
//	$femalesCount = count($females);
//
//	// do males
//	for ($mostUsedSpeaker = 0;
//	$mostUsedSpeaker < $malesCount;
//	$mostUsedSpeaker++
//	)
//		{
//		$speaker = $males[$mostUsedSpeaker];
//		$label = $speaker['label'];
//		$v = assignVoice('male');
//		$updated = "Hndlr 194";
//		$sql = "UPDATE $theseMales SET voice = ?, updatingLines=? WHERE label = ?";
//		$stmt = $readBookDB->prepare($sql);
//		$ret = $stmt->execute(array($v, $updated, $label));
//		if (!$ret)
//			logMsg("Software error: readBookHandler line " . __LINE__, "Error");
//		}
//
//	// do females
//	for ($mostUsedSpeaker = 0;
//	$mostUsedSpeaker < $femalesCount;
//	$mostUsedSpeaker++
//	)
//		{
//		$speaker = $females[$mostUsedSpeaker];
//		$label = $speaker['label'];
//		$v = assignVoice('female');
//		$updated = "Hndlr 211";
//		$sql = "UPDATE $theseFemales SET voice = ?, updatingLines=? WHERE label = ?";
//		$stmt = $readBookDB->prepare($sql);
//		$ret = $stmt->execute(array($v, $updated, $label));
//		if (!$ret)
//			logMsg("Software error: readBookHandler line " . __LINE__, "Error");
//		}
//	}
