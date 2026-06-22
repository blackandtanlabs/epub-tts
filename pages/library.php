<?php
/**
 * library.php — list the ebooks in the library folder and offer to read them.
 *
 * Works from a plain folder of EPUBs laid out the Calibre way
 * (<Author>/<Title> (<id>)/<book>.epub). No catalog database is required;
 * if a Calibre metadata.db is present it is used elsewhere for richer browsing.
 */
require_once __DIR__ . '/config.php';

/** Find every book folder (one holding an .epub) under the library root. */
function find_books(string $root): array
	{
	$books = [];
	if (!is_dir($root))
		return $books;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($it as $file)
		{
		if (strtolower($file->getExtension()) !== 'epub')
			continue;
		$dir = $file->getPath();                       // folder holding the epub
		$rel = ltrim(substr($dir, strlen($root)), '/\\');
		$rel = str_replace('\\', '/', $rel);
		if ($rel === '')
			continue;
		$folder = basename($dir);                       // "Title (id)"
		$author = basename(dirname($dir));              // parent folder = author
		// A clean title: drop the trailing "(id)" Calibre appends.
		$title = trim(preg_replace('/\s*\(\d+\)\s*$/', '', $folder));
		if ($title === '')
			$title = $folder;
		$books[$rel] = ['path' => $rel, 'title' => $title, 'author' => $author];
		}
	// Sort by author, then title.
	uasort($books, function ($a, $b) {
		return [$a['author'], $a['title']] <=> [$b['author'], $b['title']];
	});
	return $books;
	}

$books = find_books(LIBRARY_ROOT);
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<link href="../favicon.ico" rel="icon" type="image/x-icon" />
<style>
html, body { padding:0; margin:0; width:100%; min-height:100%; background-color:#99ff99; }
body { font-family:serif; }
h1 { font-family:sans-serif; font-size:6vh; text-align:center; padding:1vh 0; margin:0; }
.book { background-image: radial-gradient(yellow,green); margin:1vh; padding:1.5vh; border-radius:6px; }
.book .title { font-size:3.2vh; font-weight:bold; }
.book .author { font-size:2.2vh; font-style:italic; padding-bottom:0.6vh; }
.book a { color:blue; font-size:2.4vh; margin-right:2vw; }
.empty { text-align:center; font-size:2.6vh; padding:4vh; }
.back { display:block; text-align:center; padding:1vh; font-size:2.4vh; }
</style>
<title>Library</title>
</head>
<body>
<h1>Library</h1>
<a class="back" href="../index.php">&larr; Browse Books</a>
<?php if (count($books) === 0): ?>
<p class="empty">No books found in the library folder yet.<br>
Add EPUBs under <code><?= htmlspecialchars(LIBRARY_ROOT, ENT_QUOTES) ?></code>
(laid out as <code>Author/Title (id)/book.epub</code>).</p>
<?php else: foreach ($books as $b):
	$bookParam = rawurlencode($b['path']); ?>
<div class="book">
	<div class="title"><?= htmlspecialchars($b['title'], ENT_QUOTES) ?></div>
	<div class="author">by <?= htmlspecialchars($b['author'], ENT_QUOTES) ?></div>
	<a href="readBook.php?book=<?= $bookParam ?>&output=FP&phase=1">Female Narrator</a>
	<a href="readBook.php?book=<?= $bookParam ?>&output=MP&phase=1">Male Narrator</a>
</div>
<?php endforeach; endif; ?>
</body>
</html>
