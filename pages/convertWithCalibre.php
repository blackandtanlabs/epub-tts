<?php
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
