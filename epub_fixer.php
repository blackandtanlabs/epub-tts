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

/**
 * EPUB ID Scanner & Fixer
 * Deep-dive version to ensure we hit every internal file.
 */
$inputFile = "1776.epub";
$outputFile = str_replace('.epub', '_FIXED.epub', $inputFile);

$zip = new ZipArchive;
if ($zip->open($inputFile) !== TRUE)
	die("Failed to open $inputFile\n");

$tempDir = sys_get_temp_dir() . '/epub_fix_' . uniqid();
mkdir($tempDir);
$zip->extractTo($tempDir);

// 1. IMPROVED FILE DISCOVERY
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
$processedCount = 0;

echo "Scanning for content files...\n";

foreach ($it as $file)
	{
	if ($file->isDir())
		continue;

	$path = $file->getPathname();
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

	// EPUB content can be .html, .xhtml, .htm, or even .xml
	if (in_array($ext, ['html', 'xhtml', 'htm', 'xml']))
		{
		processHtmlFile($path, $tempDir);
		$processedCount++;
		}
	}

// 2. REPACK
$newZip = new ZipArchive;
$newZip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir)) as $file)
	{
	if (!$file->isDir())
		{
		$newZip->addFile($file->getRealPath(), substr($file->getRealPath(), strlen($tempDir) + 1));
		}
	}
$newZip->close();

echo "\nFinished. Processed $processedCount files.\nSaved to: $outputFile\n";

// --- THE DISCOVERY ENGINE ---

function processHtmlFile($filePath, $baseDir)
	{
	$content = file_get_contents($filePath);
	$displayPath = substr($filePath, strlen($baseDir) + 1);

	// Pattern to find any tag with an ID
	// Matches: <tag id="val">content</tag>  OR <tag id="val" />
	$pattern = '/(<([a-z0-9]+)\b[^>]*\bid=["\']([^"\']+)["\'][^>]*>)(.*?)(<\/\2>|(?<=\/)>)/is';

	if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER))
		{
		echo "\n[FILE] $displayPath (" . count($matches) . " IDs found)\n";

		$modified = false;
		$newContent = preg_replace_callback($pattern, function ($m) use (&$modified)
			{
			$fullOpeningTag = $m[1];
			$tagName = $m[2];
			$idValue = $m[3];
			$innerHtml = trim($m[4]);
			$isSelfClosing = (strpos($fullOpeningTag, '/>') !== false);

			// If it's truly empty, or just contains whitespace/junk
			if (empty($innerHtml) || $innerHtml == '&nbsp;')
				{
				$label = ucwords(str_replace(['_', '-'], ' ', $idValue));
				$modified = true;

				if ($isSelfClosing)
					{
					$cleanOpen = str_replace('/>', '>', $fullOpeningTag);
					return "$cleanOpen$label</$tagName>";
					}
				return "$fullOpeningTag$label{$m[5]}";
				}

			return $m[0]; // No change
			}, $content);

		if ($modified)
			{
			file_put_contents($filePath, $newContent);
			echo "   -> Modified empty tags in this file.\n";
			}
		}
	else
		{
		// Just a status update for files with no IDs
		echo "   (Skipping $displayPath - no IDs)\n";
		}
	}
