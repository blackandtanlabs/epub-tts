<?php

declare(strict_types=1);

/**
 * ChapterMarker — spine/file-boundary chapter markers with light heuristics.
 *
 * Usage (server side, in your main client):
 *   $cm = new ChapterMarker(['pause_ms'=>1200, 'keep_back'=>3]);
 *   // when you enter a new spine doc:
 *   $cm->onSpineEnter($bookId, $spineId, $spineHtmlString);
 *   // when you emit the FIRST paragraph from that spine file:
 *   $directive = $cm->chapterDirectiveForFirstPara();
 *   // prepend the directive (if non-empty) to PRE and TTS before sending
 *
 * Notes:
 * - No TOC needed. Works purely from file boundaries + early-file header sniffing.
 * - Title sources (priority order):
 *     1) <h1> text (trimmed), else <h2>
 *     2) Early block with regex like "Chapter 12", "Book II", "Part 3", "Prologue", "Epilogue"
 *     3) Fallback: "File N" (N is running count in the book)
 * - Level:
 *     - h1 → level=1, h2 → level=2, regex hits default to level=1
 * - You can opt to hard-reset at big breaks (Book/Part) via $this->bigBreakResets
 */
final class ChapterMarker
	{

	private array $defaults = [
		'pause_ms' => 1000,
		'keep_back' => 2,
	];

	/** At these keywords, we consider it a “big break” (optionally reset=1). */
	private array $bigBreakKeywords = ['book', 'part'];
	private bool $bigBreakResets = false; // set true to emit reset=1 on big breaks

	/** State per book */
	private array $state = [
		// $bookId => [
		//     'file_seq' => 0,       // count spine files seen
		//     'last_spine' => null,  // last spineId
		//     'pending_directive' => '', // set when entering a new file, consumed at first para
		// ]
	];

	public function __construct(array $defaults = [], bool $bigBreakResets = false, array $bigBreakKeywords = [])
		{
		if (!empty($defaults))
			{
			$this->defaults = array_merge($this->defaults, $defaults);
			}
		if (!empty($bigBreakKeywords))
			{
			$this->bigBreakKeywords = array_map('strval', $bigBreakKeywords);
			}
		$this->bigBreakResets = $bigBreakResets;
		}

	/**
	 * Call this exactly once when you start a new spine document (file).
	 * $spineHtml should be the raw (X)HTML of that file (or just the first ~20 KB).
	 */
	public function onSpineEnter(string $bookId, string $spineId, string $spineHtml): void
		{
		$s = & $this->state[$bookId];
		if (!isset($s))
			{
			$s = ['file_seq' => 0, 'last_spine' => null, 'pending_directive' => ''];
			}

		if ($s['last_spine'] === $spineId)
			{
			// Already entered; do nothing
			return;
			}

		$s['file_seq']++;
		$s['last_spine'] = $spineId;

		// Derive a title + level from the early HTML
		[$title, $level, $isBig] = $this->deriveTitleAndLevel($spineHtml, $s['file_seq']);

		// Build directive (strip quotes safely)
		$titleAttr = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
		$levelAttr = ' level=' . max(1, $level);

		$pause = (int) ($this->defaults['pause_ms'] ?? 1000);
		$keep = (int) ($this->defaults['keep_back'] ?? 2);

		$reset = ($this->bigBreakResets && $isBig) ? ' reset=1' : '';

		$directive = "⦃READER:CHAPTER{$titleAttr}{$levelAttr} pause_ms={$pause} keep_back={$keep}{$reset}⦄";

		// Stash it; you’ll prepend it to the first paragraph of this spine file
		$s['pending_directive'] = $directive;
		}

	/**
	 * Call right before you emit the FIRST paragraph from the current spine file.
	 * Returns '' if nothing is pending (e.g., mid-file paragraphs).
	 */
	public function chapterDirectiveForFirstPara(string $bookId = ''): string
		{
		if ($bookId === '' || !isset($this->state[$bookId]))
			return '';
		$s = & $this->state[$bookId];
		$d = $s['pending_directive'] ?? '';
		$s['pending_directive'] = '';
		return $d;
		}

	// ----------------- internals -----------------

	private function deriveTitleAndLevel(string $html, int $fileSeq): array
		{
		// Limit to early section for speed
		$sample = mb_substr($html, 0, 50_000, 'UTF-8');

		// Remove scripts/styles to reduce noise
		$sample = preg_replace('~<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>~i', '', $sample) ?? $sample;
		$sample = preg_replace('~<style\b[^<]*(?:(?!</style>)<[^<]*)*</style>~i', '', $sample) ?? $sample;

		// 1) Prefer H1, else H2
		$h1 = $this->firstTagText($sample, 'h1');
		if ($h1 !== '')
			{
			$title = $this->cleanLine($h1);
			if ($title !== '')
				return [$title, 1, $this->looksLikeBigBreak($title)];
			}
		$h2 = $this->firstTagText($sample, 'h2');
		if ($h2 !== '')
			{
			$title = $this->cleanLine($h2);
			if ($title !== '')
				return [$title, 2, $this->looksLikeBigBreak($title)];
			}

		// 2) Look for common “Chapter/Book/Part/Prologue/Epilogue …” in early blocks
		$cand = $this->firstHeaderishLine($sample);
		if ($cand !== '')
			{
			$title = $this->cleanLine($cand);
			return [$title, 1, $this->looksLikeBigBreak($title)];
			}

		// 3) Fallback: File N
		return ["File {$fileSeq}", 1, false];
		}

	private function firstTagText(string $html, string $tag): string
		{
		$re = sprintf('~<%1$s\b[^>]*>(.*?)</%1$s>~is', preg_quote($tag, '~'));
		if (preg_match($re, $html, $m))
			{
			return $this->stripTagsKeepText($m[1] ?? '');
			}
		return '';
		}

	private function firstHeaderishLine(string $html): string
		{
		// Extract first few block-ish elements’ text nodes
		$re = '~<(?:h[1-6]|p|div|section|article|center)\b[^>]*>(.*?)</(?:h[1-6]|p|div|section|article|center)>~is';
		if (!preg_match_all($re, $html, $mm))
			return '';
		foreach ($mm[1] as $raw)
			{
			$txt = $this->cleanLine($this->stripTagsKeepText($raw));
			if ($txt === '')
				continue;

			// Patterns: “Chapter 12”, “Chapter XII”, “Book II”, “Part 3”, “Prologue”, “Epilogue”
			if (preg_match('~^\s*(chapter|book|part)\s+([ivxlcdm]+|\d+)\b~i', $txt))
				return $txt;
			if (preg_match('~^\s*(prologue|epilogue)\b~i', $txt))
				return $txt;

			// Lone roman numeral line (short and title-ish)
			if (preg_match('~^\s*[IVXLCDM]{1,8}\s*$~', $txt))
				return $txt;
			}
		return '';
		}

	private function stripTagsKeepText(string $s): string
		{
		// Remove tags, keep entities decoded lightly
		$s = strip_tags($s);
		$s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return $s;
		}

	private function cleanLine(string $s): string
		{
		// Collapse whitespace; trim brackets/dashes often used around headings
		$s = preg_replace('~\s+~u', ' ', $s) ?? $s;
		$s = trim($s);
		$s = trim($s, " \t\n\r\0\x0B-–—[]()«»“”\"'");
		// Keep a sane length
		if (mb_strlen($s, 'UTF-8') > 120)
			$s = mb_substr($s, 0, 120, 'UTF-8');
		return $s;
		}

	private function looksLikeBigBreak(string $title): bool
		{
		$lo = mb_strtolower($title, 'UTF-8');
		foreach ($this->bigBreakKeywords as $kw)
			{
			if (mb_strpos($lo, $kw) !== false)
				return true;
			}
		// Prologue/Epilogue also feel “big”
		if (preg_match('~\b(prologue|epilogue)\b~i', $title))
			return true;
		return false;
		}
	}
