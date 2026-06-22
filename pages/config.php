<?php
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

// PDO handle to the Calibre catalog, or null when no library is present yet.
function calibre_db(): ?PDO
	{
	if (!is_file(CALIBRE_DB))
		return null;
	return new PDO('sqlite:' . CALIBRE_DB);
	}
