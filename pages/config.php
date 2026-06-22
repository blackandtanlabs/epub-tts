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
 * Shared paths and endpoints for the reader pages.
 *
 * Everything that used to be a fixed Windows path now comes from the
 * environment, with sensible defaults so the program runs from wherever it
 * is checked out. Set these in the container (see docker-compose.yml) or in
 * the shell before starting the web server.
 */

// The application directory (this file lives in <app>/pages).
if (!defined('APP_DIR'))
	define('APP_DIR', dirname(__DIR__));

// The program's own database: voices, recipes, pronunciations, name lists.
if (!defined('APP_DB'))
	define('APP_DB', getenv('APP_DB') ?: APP_DIR . '/labelCheck.db');

// The ebook library. A Calibre library folder works as-is; point LIBRARY_ROOT
// at it (the folder that contains metadata.db and the per-book subfolders).
if (!defined('LIBRARY_ROOT'))
	define('LIBRARY_ROOT', rtrim(getenv('LIBRARY_ROOT') ?: APP_DIR . '/library', '/\\'));

// The library catalog. Calibre writes this as metadata.db at the library root.
if (!defined('CALIBRE_DB'))
	define('CALIBRE_DB', getenv('CALIBRE_DB') ?: LIBRARY_ROOT . '/metadata.db');

// Where the speech engine listens. The web side reaches it over the network.
if (!defined('ENGINE_URL'))
	define('ENGINE_URL', getenv('FLASK_SPEAK_URL') ?: 'http://127.0.0.1:8077/speak');

// PDO handle to the Calibre catalog, or null when no catalog is present.
function calibre_db(): ?PDO
	{
	static $pdo = null;
	if ($pdo !== null)
		return $pdo;
	if (!is_file(CALIBRE_DB))
		return null;
	return $pdo = new PDO('sqlite:' . CALIBRE_DB);
	}

// Same, but for the catalog browse pages: if there is no Calibre catalog,
// show a friendly note (with a link to the folder-based Library) and stop.
function calibre_db_or_notice(): PDO
	{
	$db = calibre_db();
	if ($db instanceof PDO)
		return $db;
	http_response_code(200);
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>No catalog</title>'
		. '<style>body{font-family:serif;background:#99ff99;text-align:center;padding:6vh;font-size:2.6vh}'
		. 'a{color:blue}</style></head><body>'
		. '<h1 style="font-family:sans-serif">No Calibre catalog</h1>'
		. '<p>These browse-by-author/genre/series pages read a Calibre <code>metadata.db</code>.</p>'
		. '<p>None was found, but you can still browse and read everything from the '
		. '<a href="library.php">Library</a>.</p>'
		. '<p><a href="../index.php">&larr; Browse Books</a></p>'
		. '</body></html>';
	exit;
	}
