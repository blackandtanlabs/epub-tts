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
 * TTS3 EPUB Heuristic Rebuilder
 * Uses Calibre's built-in Heurisitic logic to detect chapters
 * Actually runs whatever is specified in Calibre's Preferences
 * Mess with them at your own peril
 */

$convertTool = '"C:\Program Files\Calibre2\ebook-convert.exe"';
$absEpub = realpath($epubFile);
$tempEpub = "test.epub";

echo "--- Running Heuristic Repair for: " . basename($absEpub) . " ---\n";

/**
 * THE COMMAND:
 * --enable-heuristics: This is the master switch for the "Heuristic Processing" tab.
 * --html-unwrap-factor: (Optional) Helps clean up "junk" books with weird line breaks.
 */
$cmd = "$convertTool " . escapeshellarg($absEpub) . " " . escapeshellarg($tempEpub) . 
       " --enable-heuristics";

$output = [];
$status = 0;
exec($cmd . " 2>&1", $output, $status);
$aRes = implode("\n", $output);
if ($status === 0 && file_exists($tempEpub)) {
    unlink($absEpub);
    rename($tempEpub, $absEpub);
    echo "SUCCESS: Heuristic engine has rebuilt the book structure.\n";
} else {
    echo "ENGINE FAILURE (Status $status):\n";
    echo implode("\n", $output);
}
