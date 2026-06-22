<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Clean Database</title>
<style>
html, body {
    padding:0; margin:0;
    width:100%;
    height:100%;
    text-align:center;
    overflow:hidden;}
body {
    font-family:serif;
    font-size:4vh;
    }
</style>
</head>
	<body>
		<div style="height: 100%;background-image: radial-gradient(yellow,green);">
			FINISHED<br><br><a href="../index.php"><h2>Return</h2></a>
<?php
function accessDB($DB, $sql)
	{
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute();
	if (!$ret)
		exit();
	return ($preparedSQL->fetchAll());
	}
$readBookDBname = "sqlite:" . __DIR__ . "\..\..\TTS\labelCheck.db";
$readBookDB = new PDO($readBookDBname);
$curTimeStamp = time();
$tables = accessDB($readBookDB, "SELECT name FROM sqlite_master WHERE type='table'");
for ($x=count($tables)-1; $x>=0; $x--)
	{
	$tableName = $tables[$x][0];
	
//	$POS = strpos($tableName, "REGX");
//	if ($POS !== false)
//		continue;			// do not delete
//	
//	$POS = strpos($tableName, "fixes");
//	if ($POS !== false)
//		continue;			// do not delete
//	
	$lastChar = substr($tableName, -1, 1);
	if ($lastChar >=0 AND $lastChar <= 9)
		// delete the table
		accessDB($readBookDB, "DROP TABLE $tableName");
	}
accessDB($readBookDB, "DROP TABLE IF EXISTS bookTitle");
$sql = 'CREATE TABLE bookTitle (
	"ID"	INTEGER,
	"title"	TEXT,
	"author"	TEXT,
	"lastParagraph"	INTEGER DEFAULT 0,
	"timeStamp"	INTEGER,
  "queryString" TEXT,
  "narrVoice" TEXT,
	PRIMARY KEY("ID")
	);';
accessDB($readBookDB, $sql);
//accessDB($readBookDB, "INSERT INTO bookTitle VALUES('1234567', 'START', '',0, 0)");
exit();
?>

</body>
</html>
