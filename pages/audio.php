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

declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
$readBookDBname = "sqlite:" . APP_DB;
$readBookDB = new PDO($readBookDBname);

function accessDB($DB, $sql, ...$parms)
	{
//accessTable($DB, $sql, ...$parms);
	$data = array();
	$preparedSQL = $DB->prepare($sql);
	$ret = $preparedSQL->execute($parms);
	if (!$ret)
		error_log("audio.php: query failed: $sql");
	else
		$data = $preparedSQL->fetchAll();
	return $data;
	}

// ---- accept path via base64 (?path=) OR raw windows path (?win=) OR WSL path (?wsl=) ----
$rawPath = '';
if (isset($_GET['path']) && $_GET['path'] !== '')
	{
	$dec = base64_decode($_GET['path'], true);
	if ($dec !== false)
		$rawPath = $dec;
	}
elseif (isset($_GET['win']) && $_GET['win'] !== '')
	{
	$rawPath = (string) $_GET['win'];
	}
elseif (isset($_GET['wsl']) && $_GET['wsl'] !== '')
	{
	$rawPath = (string) $_GET['wsl'];
	}

if ($rawPath === '')
	{
	http_response_code(400);
	header('Content-Type: text/plain');
	echo "Missing path";
	exit;
	}

// Convert WSL form /mnt/c/... to Windows if needed
if (!preg_match('/^[A-Za-z]:\\\\/', $rawPath) && strpos($rawPath, '\\\\') !== 0)
	{
	if (preg_match('#^/mnt/([a-z])/(.*)$#i', $rawPath, $m))
		{
		$rawPath = strtoupper($m[1]) . ':\\' . str_replace('/', '\\', $m[2]);
		}
	}
	
$path = $rawPath;
$size = filesize($path);
$fp = fopen($path, 'rb');
// Remember the listener's position from the file name, e.g. 22611_p1744.wav.
// Only when it clearly carries a book id and paragraph number.
$base = basename($path);
if (preg_match('/^(\d+)_p(\d+)\./', $base, $m))
	accessDB($readBookDB, "UPDATE bookTitle SET lastParagraph = ? WHERE ID = ?", (int) $m[2], (int) $m[1]);

if (!$fp)
	{
	http_response_code(500);
	echo "Cannot open file";
			ob_flush();
			flush();

	exit;
	}

/* HEAD support for size probing */
if ($_SERVER['REQUEST_METHOD'] === 'HEAD')
	{
	header('Content-Type: audio/wav');
	header('Accept-Ranges: bytes');
	header('Content-Length: ' . $size);
	fclose($fp);
	exit;
	}

$range = null;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $m))
	{
	$start = (int) $m[1];
	$end = isset($m[2]) ? (int) $m[2] : ($size - 1);
	if ($start <= $end && $end < $size)
		$range = [$start, $end];
	}

header('Content-Type: audio/wav');
header('Accept-Ranges: bytes');

if ($range)
	{
	[$start, $end] = $range;
	$len = $end - $start + 1;
	header('HTTP/1.1 206 Partial Content');
	header("Content-Range: bytes $start-$end/$size");
	header("Content-Length: $len");
	fseek($fp, $start);
	$left = $len;
	}
else
	{
	header("Content-Length: $size");
	$left = $size;
	}

$chunk = 8192;
while (!feof($fp) && $left > 0)
	{
	$read = ($left > $chunk) ? $chunk : $left;
	$buf = fread($fp, $read);
	if ($buf === false)
		break;
	echo $buf;
	$left -= strlen($buf);
	ob_flush();
	flush();
	}
fclose($fp);
removeAudioFilesFrom($path, $bookID);

function removeAudioFilesFrom($src, $bookID)
	{
	if (file_exists($src))
		{
		// we only delete one because it's pretty slow
		// the wasted disk space will eventually be reclaimed
		$dirName = dirname($src);
		$oldestFile = findOldestFileContainingString($dirName, $bookID);
		if ($oldestFile !== null)
			unlink($oldestFile);
		}
	}

function findOldestFileContainingString(string $dirName, string $searchString): ?string
	{
	if (!is_dir($dirName))
		return null;
	$files = glob($dirName . "/$bookID*"); // Get all files/directories in the path containing $bookID*
	$oldestFile = null;
	$oldestTime = PHP_INT_MAX; // Initialize with a very large number

	if (count($files) > 10)		// don't get in the way of reader.php wanting to rewind a little
		{
		foreach ($files as $filePath)
			{
			$fileTime = filemtime($filePath);
			// Compare with the current oldest file time
			if ($fileTime !== false && $fileTime < $oldestTime)
				{
				$oldestTime = $fileTime;
				$oldestFile = $filePath;
				}
			}
		// Returns the full path to the oldest file, or null if none was found
		return $oldestFile;
		}
	return null;
	}
