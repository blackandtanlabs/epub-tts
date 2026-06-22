<?php
//declare(strict_types=1);
/**
 * body_html_to_plain_text_full.php
 *
 * Callable: Convert a BODY fragment (inner HTML of <body>) → processed plain text.
 * Includes:
 *  - Full marker constants (20 pairs, U+E000..U+E025)
 *  - Full rules map (all tags we had), inline close-map
 *  - DOM walker (sequential)
 *  - Emphasis detector (tag + style + class) WITHOUT duplication
 *  - Post-processors: ellipses, dashes, scene breaks, abbreviations, geo expansion, whitespace tidy
 *
 * Usage:
 *   require 'body_html_to_plain_text_full.php';
 *   $plain = body_html_to_plain_text($bodyInnerHtml, 'US');
 */
/* ===================== Private-use UTF-8 marker constants (20 pairs) ===================== */
const ITALIC_OPEN = " \u{E000} ";
const ITALIC_CLOSE = " \u{E001} ";
const BOLD_OPEN = " \u{E002} ";
const BOLD_CLOSE = " \u{E003} ";
const UNDER_OPEN = " \u{E004} ";
const UNDER_CLOSE = " \u{E005} ";
const STRIKE_OPEN = " \u{E006} ";
const STRIKE_CLOSE = " \u{E007} ";
const INS_OPEN = " \u{E008} ";
const INS_CLOSE = " \u{E009} ";
const MARK_OPEN = " \u{E00A} ";
const MARK_CLOSE = " \u{E00B} ";
const SMALL_OPEN = " \u{E00C} ";
const SMALL_CLOSE = " \u{E00D} ";
const SUB_OPEN = " \u{E00E} ";
const SUB_CLOSE = " \u{E00F} ";
const SUP_OPEN = " \u{E010} ";
const SUP_CLOSE = " \u{E011} ";
const CODE_OPEN = " \u{E012} ";
const CODE_CLOSE = " \u{E013} ";
const KBD_OPEN = " \u{E014} ";
const KBD_CLOSE = " \u{E015} ";
const SAMP_OPEN = " \u{E016} ";
const SAMP_CLOSE = " \u{E017} ";
const VAR_OPEN = " \u{E018} ";
const VAR_CLOSE = " \u{E019} ";
const Q_OPEN = " \u{E01A} ";
const Q_CLOSE = " \u{E01B} ";
const CITE_OPEN = " \u{E01C} ";
const CITE_CLOSE = " \u{E01D} ";
const ABBR_OPEN = " \u{E01E} ";
const ABBR_CLOSE = " \u{E01F} ";
const TIME_OPEN = " \u{E020} ";
const TIME_CLOSE = " \u{E021} ";
const SPAN_OPEN = "";
const SPAN_CLOSE = "";
const CUSTOM_OPEN = " \u{E024} ";
const CUSTOM_CLOSE = " \u{E025} ";
const EM_OPEN = " \u{E026} ";
const EM_CLOSE = " \u{E027} ";
const STRONG_OPEN = " \u{E028} ";
const STRONG_CLOSE = " \u{E029} ";

/* ===================== Public entrypoint ===================== */
/**
 * Convert BODY inner HTML → processed plain text (no debug/UI).
 *
 * @param string $bodyHtml BODY fragment (inner markup of <body>)
 * @param string $locale   Locale code for geo expansions (e.g., 'US')
 * @return string processed plain text
 */
function body_html_to_plain_text(string $bodyHtml, string $locale = 'US', string $internalDir = ''): string
	{
	$bodyHtml = preg_replace('/\n/', ' ', $bodyHtml);

	$html = "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>{$bodyHtml}</body></html>";

	$raw = html_to_text_core($html, $internalDir); 
	if ($raw === '')
		return '';

	// Post-processing pipeline (same order we used in the app)
	$text = normalize_ellipses($raw);
	$text = normalize_dashes($text);
//	$text = insert_scene_breaks_as_hr($text);
	$text = expand_abbreviations($text, ['am_pm_mode' => 'labels', 'expand_months' => true]);
//	$geo = load_geo_map();
//	$text = expand_geo_abbr($text, $geo, $locale);
	return $text;
	}
/* ===================== Core DOM → Text (rules + walker) ===================== */
function html_to_text_core(string $html, string $internalDir = ''): string
	{
	libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = true;
	$doc->formatOutput = false;
	@$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();
	libxml_use_internal_errors(false);
	$body = $doc->getElementsByTagName('body')->item(0) ?: $doc->documentElement;
	if (!$body)
		return '';

	$rules = default_rules($internalDir); // Pass it to the rules
	$state = ['emph_active' => 0, 'emph_stack' => []];
	$out = '';
	walk_node($body, $rules, $state, $out, 0, null);
	return $out;
	}
/** Rules map (all tags we had). Note: i/em/b/strong are NO-OP here to avoid duplication with detector. */
function default_rules(string $internalDir = ''): array
	{
	global $bookID; 
	$emitChildren = fn(array $ctx): string => '';
	$blockWithNewline = fn(array $ctx): string => '';
	return [
		// node primitives
		'#text' => fn(array $ctx): string => $ctx['text'],
		'#comment' => fn(array $ctx): string => '',
		// blocks (walker appends newline on close)
		'p' => $blockWithNewline,
		'h1' => $blockWithNewline, 'h2' => $blockWithNewline, 'h3' => $blockWithNewline,
		'h4' => $blockWithNewline, 'h5' => $blockWithNewline, 'h6' => $blockWithNewline,
		// inline display enhancers
		// We keep the full list here for completeness, but to prevent duplication
		// the four core emphasis tags are set to no-op (handled by detector).
		'i' => fn($ctx) =>ITALIC_OPEN,
		'em' => fn($ctx) => EM_OPEN,
		'b' => fn($ctx) => BOLD_OPEN,
		'strong' => fn($ctx) => STRONG_OPEN,
		'u' => fn($ctx) => UNDER_OPEN,
		's' => fn($ctx) => STRIKE_OPEN,
		'strike' => fn($ctx) => STRIKE_OPEN,
		'del' => fn($ctx) => STRIKE_OPEN,
		'ins' => fn($ctx) => INS_OPEN,
		'mark' => fn($ctx) => MARK_OPEN,
		'small' => fn($ctx) => SMALL_OPEN,
		'sub' => fn($ctx) => SUB_OPEN,
		'sup' => fn($ctx) => SUP_OPEN,
		'code' => fn($ctx) => CODE_OPEN,
		'kbd' => fn($ctx) => KBD_OPEN,
		'samp' => fn($ctx) => SAMP_OPEN,
		'var' => fn($ctx) => VAR_OPEN,
		'q' => fn($ctx) => Q_OPEN,
		'cite' => fn($ctx) => CITE_OPEN,
		'abbr' => fn($ctx) => ABBR_OPEN,
		'acronym' => fn($ctx) => ABBR_OPEN,
		'time' => fn($ctx) => TIME_OPEN,
		'span' => fn($ctx) => SPAN_OPEN,
		// containers pass-through
		'div' => $emitChildren, 'section' => $emitChildren, 'article' => $emitChildren,
		'main' => $emitChildren, 'aside' => $emitChildren, 'header' => $emitChildren,
		'footer' => $emitChildren, 'nav' => $emitChildren,
		// lists
		'ul' => $emitChildren, 'ol' => $emitChildren, 'li' => $blockWithNewline,
		// links — emit nothing (children text will show)
		'a' => $emitChildren,
		// breaks
		'br' => fn($ctx) => "\n", 'hr' => fn($ctx) => "\n",
		// tables (minimal)
		'table' => $emitChildren, 'tr' => $blockWithNewline, 'th' => $emitChildren, 'td' => $emitChildren,
'img' => function($ctx) use ($internalDir) {
    $node = $ctx['node'];
    $src = $node->getAttribute('src');

    // Just use the internal path (e.g., OEBPS/Images/2.jpg)
    $newSrc = ($internalDir ? $internalDir . '/' : '') . $src;
    $node->setAttribute('src', $newSrc);

    return $node->ownerDocument->saveHTML($node);
},
		// ignored
		'figure' => fn($ctx) => '', 'video' => fn($ctx) => '', 'audio' => fn($ctx) => '', 'script' => fn($ctx) => '', 'style' => fn($ctx) => '',
		// fallback
		'*' => $emitChildren,
	];
	}
/** Map of inline tags → close marker (walker appends on element close). */
function inline_close_marker_map(): array
	{
	return [
		'i' => ITALIC_CLOSE, 'em' => EM_CLOSE,
		'b' => BOLD_CLOSE, 'strong' => STRONG_CLOSE,
		'u' => UNDER_CLOSE,
		's' => STRIKE_CLOSE, 'strike' => STRIKE_CLOSE, 'del' => STRIKE_CLOSE,
		'ins' => INS_CLOSE,
		'mark' => MARK_CLOSE,
		'small' => SMALL_CLOSE,
		'sub' => SUB_CLOSE,
		'sup' => SUP_CLOSE,
		'code' => CODE_CLOSE, 'kbd' => KBD_CLOSE, 'samp' => SAMP_CLOSE, 'var' => VAR_CLOSE,
		'q' => Q_CLOSE, 'cite' => CITE_CLOSE,
		'abbr' => ABBR_CLOSE, 'acronym' => ABBR_CLOSE,
		'time' => TIME_CLOSE,
		'span' => SPAN_CLOSE,
	];
	}
/* ===================== Walker (sequential) ===================== */
function walk_node(
    DOMNode $node,
    array $rules,
    array &$state,
    string &$outBuf,
    int $depth,
    ?string $parentTag
): void
    {
if ($node->nodeName === 'figure')
$breakpoint=1;
    $nt = $node->nodeType;
    if ($nt === XML_TEXT_NODE)
        {
        [$text] = normalize_text_node($node, $parentTag);
        if ($text !== '')
            $outBuf .= $text;
        return;
        }

    if ($nt === XML_COMMENT_NODE)
        {
        return; // ignore
        }

    if ($nt === XML_ELEMENT_NODE)
        {
        $tag = strtolower($node->nodeName);
        $attrs = element_attrs($node);
        
 // Shield if it's a custom tag OR if it has an 'id' attribute
//if ($node->hasAttribute('id'))
//$breakpoint=1;
//$isShielded = ($node->hasAttribute('id') || str_starts_with($tag, 'my-'));
$isShielded = false;
        // Ignore noisy elements early
        if (!$isShielded && in_array($tag, ['script', 'style', 'video', 'audio'], true))
            return;

        // Standalone breaks
        if ($tag === 'br' || $tag === 'hr')
            { $outBuf .= "\n";
            return; }

        // If shielded, manually write the opening tag into the output buffer
        if ($isShielded) {
            $attrStr = "";
            foreach ($attrs as $name => $val) {
                $attrStr .= " $name=\"" . htmlspecialchars($val) . "\"";
            }
            $outBuf .= "<$tag$attrStr>";
        }

        $ctx = [
            'type' => 'element', 
            'tag' => $tag, 
            'attrs' => $attrs, 
            'depth' => $depth, 
            'node' => $node 
        ];

        // Process rules for standard tags (Shielded tags will likely fall through to fallback)
        if (isset($rules[$tag]) && is_callable($rules[$tag]))
            {
            $emitOpen = (string) $rules[$tag]($ctx);
            if ($emitOpen !== '')
                $outBuf .= $emitOpen;
            }
        elseif (!$isShielded && isset($rules['*']) && is_callable($rules['*']))
            {
            $emitOpen = (string) $rules['*']($ctx);
            if ($emitOpen !== '')
                $outBuf .= $emitOpen;
            }

        // Children: Process the actual text inside the tags
        for ($child = $node->firstChild; $child; $child = $child->nextSibling)
            {
            walk_node($child, $rules, $state, $outBuf, $depth + 1, $tag);
            }

        // CLOSE: Handle our custom tags first
        if ($isShielded) {
            $outBuf .= "</$tag>";
        } else {
            // Standard close logic
            $closeMap = inline_close_marker_map();
            if (isset($closeMap[$tag]))
                {
                $outBuf .= $closeMap[$tag];
                }
            elseif (in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'pre', 'blockquote'], true))
                {
                $outBuf .= "\n";
                }
        }

        return;
        }
    }
	
/* ===================== Small DOM helpers ===================== */
function normalize_text_node(DOMNode $node, ?string $parentTag): array
	{
	$text = $node->nodeValue ?? '';
return [$text, true];
//	// pre/code/textarea/script/style → preserve whitespace
//	for ($n = $node; $n; $n = $n->parentNode)
//		{
//		if ($n->nodeType === XML_ELEMENT_NODE)
//			{
//			$t = strtolower($n->nodeName);
//			if (in_array($t, ['pre', 'code', 'textarea', 'script', 'style'], true))
//				{
//				return [$text, true];
//				}
//			}
//		}
//	$collapsed = preg_replace('/\s+/u', ' ', $text);
//	return [trim((string) $collapsed), false];
	}
function element_attrs(DOMNode $node): array
	{
	$attrs = [];
	if ($node instanceof DOMElement)
		{
		foreach ($node->attributes as $attr)
			{ $attrs[strtolower($attr->name)] = $attr->value; }
		}
	return $attrs;
	}
/* ===================== Post-processing helpers ===================== */
function normalize_dashes(string $text): string
	{
	// Protect bullets and numeric ranges
//	$ph = [];
//	$text = preg_replace_callback('/(^|\R)([ \t]*)-\s+(?=\S)/u', function ($m) use (&$ph)
//		{
//		$k = "\x07BULLET" . count($ph) . "\x07";
//		$ph[$k] = $m[0];
//		return $k;
//		}, $text);
//	$text = preg_replace_callback('/\b(\d+)\s*-\s*(\d+)\b/u', function ($m) use (&$ph)
//		{
//		$k = "\x07NUMRANGE" . count($ph) . "\x07";
//		$ph[$k] = $m[1] . '-' . $m[2];
//		return $k;
//		}, $text);
	// Normalize all dash-like forms to spaced em dash
	//$text = str_replace(" – ", "—", $text);
	//$text = str_replace("– ", "—", $text);
	//$text = str_replace(" –", "—", $text);
	//$text = preg_replace('/\s*--+\s*/u', ' — ', $text);
//	$text = preg_replace('/\s-\s/u', ' — ', $text);
//	$text = preg_replace('/(?<=\p{L})-\s(?=\p{L})/u', ' — ', $text);
//	$text = preg_replace('/(?<=\p{L})\s-(?=\p{L})/u', ' — ', $text);
//	$text = preg_replace('/\s*—\s*/u', ' — ', $text);
//	$text = preg_replace('/ — \s*([,.;:?!])\s*/u', ' — $1 ', $text);
//	foreach ($ph as $k => $v)
//		$text = str_replace($k, $v, $text);
	return $text;
	}
function normalize_ellipses(string $text): string
	{
//	$ph = [];
//	$i = 0;
//	// Protect dotted numbers/IP-like
//	$text = preg_replace_callback('/\b\d+\.(?:\d+\.)*\d+\b/u', function ($m) use (&$ph, &$i)
//		{
//		$k = '[[NUM$' . ($i++) . ']]';
//		$ph[$k] = $m[0];
//		return $k;
//		}, $text);
	// Overlong runs / spaced dot triples
	$text = preg_replace('/\.{4,}/u', '...', $text);
	$text = preg_replace('/(?:\s*\.\s*){3}/u', '...', $text);
	// Convert exactly "..." to ellipsis
	$text = preg_replace('/(?<!\.)\.{3}(?!\.)/u', '…', $text);
	// Convert exactly "         .         .         ." to ellipsis
	$text = preg_replace('/         \.         \.         \./u', '…', $text);
	// Tidy spacing around ellipsis
//	$text = preg_replace('/(\S)\s*…\s*(\S)/u', '$1 … $2', $text);
//	$text = preg_replace('/…\s*([\)\]\}”’?!,:;])/u', '…$1', $text);
//	$text = preg_replace('/([“‘\("\[])\s*…/u', '$1…', $text);
//	foreach ($ph as $k => $v)
//		$text = str_replace($k, $v, $text);
	return $text;
	}
/**
 * Replace ornament/dinkus lines with "<hr>" (visual scene break for your UI; you strip before TTS).
 */
function insert_scene_breaks_as_hr(string $text): string
	{
	$lines = preg_split('/\r?\n/', $text);
	if ($lines === false)
		return $text;
	$out = [];
	$prevHr = false;
	$is_break = function (string $line): bool
		{
		$t = trim($line);
		if ($t === '')
			return false;
		if (preg_match('/^<\s*hr\b[^>]*>$/i', $t))
			return true;
		if (preg_match('/[\p{L}\p{N}]/u', $t))
			return false;
		$plain = preg_replace('/\s+/u', ' ', $t);
		if (mb_strlen(str_replace(' ', '', $plain)) > 10)
			return false;
		if ($plain === '⁂')
			return true;
		$triples = [
			'/^(\*\s*){3}$/u', '/^(•\s*){3}$/u', '/^(·\s*){3}$/u', '/^(∙\s*){3}$/u',
			'/^(—\s*){3}$/u', '/^(–\s*){3}$/u', '/^(\.\s*){3}$/u'
		];
		foreach ($triples as $re)
			if (preg_match($re, $plain))
				return true;
		if ($plain === '…' || $plain === '...')
			return true;
		if (preg_match('/^(?:[❧❦✻✶✴✧✤✥\*•·](?:\s*[❧❦✻✶✴✧✤✥\*•·]){0,4})$/u', $plain))
			return true;
		return false;
		};
	foreach ($lines as $line)
		{
		if ($is_break($line))
			{
			if (!$prevHr)
				{ $out[] = CUSTOM_OPEN;
				$prevHr = true; }
			}
		else
			{
			$out[] = $line;
			$prevHr = false;
			}
		}
	return implode("\n", $out);
	}
/* ===================== Abbreviations & Geo ===================== */
function expand_abbreviations(string $text, array $opts = []): string
	{
	$cfg = array_merge(['am_pm_mode' => 'labels', 'expand_months' => true], $opts);
	$preserve = function (string $src, string $replacement): string
		{
		if ($src === strtoupper($src))
			return strtoupper($replacement);
		if ($src === strtolower($src))
			return strtolower($replacement);
		return mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
		};
	// St. → Saint/Street
	$text = preg_replace_callback(
		'/\b(St\.)\s+([A-Z][\p{L}\']+(?:[- ][A-Z][\p{L}\']+)*)/u',
		fn($m) => $preserve($m[1], 'Saint') . ' ' . $m[2],
		$text
	);
	$text = preg_replace_callback(
		'/\b(St\.)\b(?!\s+[A-Z][\p{L}\'])/u',
		fn($m) => $preserve($m[1], 'Street'),
		$text
	);
	// a.m. / p.m.
	if ($cfg['am_pm_mode'] === 'labels')
		{
		$text = preg_replace('/\b([0-9]{1,2})(?::[0-5][0-9])?\s*a\.m\./i', '$1 in the morning', $text);
		$text = preg_replace('/\b([0-9]{1,2})(?::[0-5][0-9])?\s*p\.m\./i', '$1 in the evening', $text);
		}
	else
		{
		$text = preg_replace('/\ba\.m\./i', 'AM', $text);
		$text = preg_replace('/\bp\.m\./i', 'PM', $text);
		}

	// Common period’d abbreviations
	$map = [
		'Mr\.' => 'Mister', 'Mrs\.' => 'Missus', 'Ms\.' => 'Miss', 'Mx\.' => 'Mix', 'Dr\.' => 'Doctor', 'Prof\.' => 'Professor', 'Rev\.' => 'Reverend',
		'Sr\.' => 'Senior', 'Jr\.' => 'Junior', 'Gen\.' => 'General', 'Col\.' => 'Colonel', 'Capt\.' => 'Captain', 'Lt\.' => 'Lieutenant', 'Sgt\.' => 'Sergeant',
		'Gov\.' => 'Governor', 'Sen\.' => 'Senator', 'Rep\.' => 'Representative',
		'Mt\.' => 'Mount', 'Ft\.' => 'Fort',
		'Ave\.' => 'Avenue', 'Blvd\.' => 'Boulevard', 'Rd\.' => 'Road', 'Ln\.' => 'Lane', 'Hwy\.' => 'Highway',
		'Dept\.' => 'Department', 'Co\.' => 'Company', 'Corp\.' => 'Corporation', 'Inc\.' => 'Incorporated', 'Ltd\.' => 'Limited',
		'Etc\.' => 'Et cetera', 'etc\.' => 'et cetera', 'e\.g\.' => 'for example', 'i\.e\.' => 'that is', 'vs\.' => 'versus',
	];
	foreach ($map as $pat => $rep)
		{
		$text = preg_replace_callback("/\\b({$pat})/u", fn($m) => $preserve($m[1], $rep), $text);
		}

	if (!empty($cfg['expand_months']))
		{
		$months = [
			'Jan\.' => 'January', 'Feb\.' => 'February', 'Mar\.' => 'March', 'Apr\.' => 'April',
			'Jun\.' => 'June', 'Jul\.' => 'July', 'Aug\.' => 'August',
			'Sep\.' => 'September', 'Sept\.' => 'September', 'Oct\.' => 'October', 'Nov\.' => 'November', 'Dec\.' => 'December'
		];
		foreach ($months as $pat => $rep)
			{
			$text = preg_replace_callback("/\\b({$pat})/u", fn($m) => $preserve($m[1], $rep), $text);
			}
		}

	// Conservative ALL-CAPS expansions (avoid acronyms)
	$allow = ['OK' => 'okay', 'TV' => 'television', 'ASAP' => 'as soon as possible'];
	$skip = ['USA', 'UAE', 'EU', 'UK', 'UN', 'NATO', 'FBI', 'CIA', 'IRS', 'WHO', 'IMF', 'GPU', 'CPU', 'RAM', 'USB', 'AI', 'NASA', 'HTML', 'CSS', 'PDF', 'TTS', 'OR', 'AND', 'ME', 'MO', 'US'];
	$text = preg_replace_callback('/\b([A-Z]{2,6})\b/u', function ($m) use ($allow, $skip)
		{
		$w = $m[1];
		return (isset($allow[$w]) && !in_array($w, $skip, true)) ? $allow[$w] : $w;
		}, $text);
	return $text;
	}
/** CSV file is optional; fallback map included. */
const GEO_CSV_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'geo_abbr.csv';
$onOne1 = true;
$onOne2 = true;
function load_geo_map(): array
	{
	global $onOne1;
	global $onOne2;
	$gsv_path=GEO_CSV_PATH;
	
	if (is_file(GEO_CSV_PATH))
		{
//		if ($onOne1)
//			{
//			echo "$gsv_path found overiding default table. Is this correct?<br>";
//			$onOne1 = false;
//			}
		$fh = fopen(GEO_CSV_PATH, 'r');
		if ($fh)
			{
			$map = [];
			$hdr = null;
			while (($row = fgetcsv($fh)) !== false)
				{
				if ($hdr === null)
					{ $hdr = array_map('strtolower', $row);
					continue; }
				$rec = @array_combine($hdr, $row);
				if (!$rec)
					continue;
				$abbr = strtoupper(trim((string) $rec['abbr']));
				if ($abbr === '')
					continue;
				$map[$abbr] = [
					'expansion' => $rec['expansion'] ?? '',
					'kind' => $rec['kind'] ?? '',
					'country_context' => strtoupper((string) ($rec['country_context'] ?? '')),
					'ambiguous' => (isset($rec['is_ambiguous']) && (int) $rec['is_ambiguous'] === 1),
					'priority' => (int) ($rec['priority'] ?? 0),
				];
				}
			fclose($fh);
			if ($map)
				return $map;
			}
		}
	elseif ($onOne2)
		{
		echo "$gsv_path not found. Is this correct?<br>";
		$onOne2 = false;
		}
	// Conservative fallback (omit ambiguous CA/GA/MO/OK; WA kept ambiguous)
	return [
		'AL' => ['expansion' => 'Alabama', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'AK' => ['expansion' => 'Alaska', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'AZ' => ['expansion' => 'Arizona', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'AR' => ['expansion' => 'Arkansas', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'CO' => ['expansion' => 'Colorado', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'CT' => ['expansion' => 'Connecticut', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'DE' => ['expansion' => 'Delaware', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'FL' => ['expansion' => 'Florida', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'GA' => ['expansion' => 'Georgia', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'HI' => ['expansion' => 'Hawaii', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'ID' => ['expansion' => 'Idaho', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'IL' => ['expansion' => 'Illinois', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'IN' => ['expansion' => 'Indiana', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'IA' => ['expansion' => 'Iowa', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'KS' => ['expansion' => 'Kansas', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'KY' => ['expansion' => 'Kentucky', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'LA' => ['expansion' => 'Louisiana', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'MD' => ['expansion' => 'Maryland', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'MA' => ['expansion' => 'Massachusetts', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'ME' => ['expansion' => 'Maine', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'MI' => ['expansion' => 'Michigan', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'MN' => ['expansion' => 'Minnesota', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'MO' => ['expansion' => 'Missouri', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'MS' => ['expansion' => 'Mississippi', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'MT' => ['expansion' => 'Montana', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NE' => ['expansion' => 'Nebraska', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NV' => ['expansion' => 'Nevada', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NH' => ['expansion' => 'New Hampshire', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NJ' => ['expansion' => 'New Jersey', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NM' => ['expansion' => 'New Mexico', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NY' => ['expansion' => 'New York', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'NC' => ['expansion' => 'North Carolina', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'ND' => ['expansion' => 'North Dakota', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'OH' => ['expansion' => 'Ohio', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'OK' => ['expansion' => 'Oklahoma', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'OR' => ['expansion' => 'Oregon', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'PA' => ['expansion' => 'Pennsylvania', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'RI' => ['expansion' => 'Rhode Island', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'SC' => ['expansion' => 'South Carolina', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'SD' => ['expansion' => 'South Dakota', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'TN' => ['expansion' => 'Tennessee', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'TX' => ['expansion' => 'Texas', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'UT' => ['expansion' => 'Utah', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'VA' => ['expansion' => 'Virginia', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'VT' => ['expansion' => 'Vermont', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'WA' => ['expansion' => 'Washington', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => true, 'priority' => 0],
		'WI' => ['expansion' => 'Wisconsin', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'WV' => ['expansion' => 'West Virginia', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
		'WY' => ['expansion' => 'Wyoming', 'kind' => 'state', 'country_context' => 'US', 'ambiguous' => false, 'priority' => 0],
	];
	}
function expand_geo_abbr(string $text, array $geoMap, string $locale = 'US'): string
	{
	$locale = strtoupper($locale);
	$zip = '(?:\d{5}(?:-\d{4})?)';
	// 1) City, ST[, ZIP]
	$text = preg_replace_callback('/\b([A-Z][a-zA-Z’\.-]+)\s*,\s*([A-Z]{2})\b(?:\s*' . $zip . ')?/u',
		function ($m) use ($geoMap, $locale)
			{
			$st = strtoupper($m[2]);
			if (!isset($geoMap[$st]))
				return $m[0];
			$rec = $geoMap[$st];
			if ($rec['ambiguous'] && $rec['country_context'] !== '' && $rec['country_context'] !== $locale)
				return $m[0];
			return $m[1] . ', ' . $rec['expansion'];
			}, $text);
	// 2) Line-start address: "... , ST"
	$text = preg_replace_callback('/^\s*\d+[^,\n]*,\s*([A-Z]{2})\b(?:\s*' . $zip . ')?/mu',
		function ($m) use ($geoMap, $locale)
			{
			$st = strtoupper($m[1]);
			if (!isset($geoMap[$st]))
				return $m[0];
			$rec = $geoMap[$st];
			if ($rec['ambiguous'] && $rec['country_context'] !== '' && $rec['country_context'] !== $locale)
				return $m[0];
			return preg_replace('/\b' . preg_quote($st, '/') . '\b/u', $rec['expansion'], $m[0], 1);
			}, $text);
	// 3) Standalone safe codes
	$text = preg_replace_callback('/\b([A-Z]{2})\b/u', function ($m) use ($geoMap)
		{
		$st = strtoupper($m[1]);
		if (!isset($geoMap[$st]))
			return $m[0];
		$rec = $geoMap[$st];
		if ($rec['ambiguous'])
			return $m[0];
		return $rec['expansion'];
		}, $text);
	return $text;
	}
