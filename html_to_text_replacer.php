<?php
/**
 * html_to_text_replacer.php — v5
 * Sequential HTML→Text replacer
 * - Private-use UTF‑8 marker constants (20 pairs)
 * - Display‑enhancement tags wrap children with markers (no duplication)
 * - Normal web UI (input + emitted text)
 * - Debug web UI (?mode=debug) with safe event log
 * - CLI: php html_to_text_replacer.php path/to/file.html
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// --------------------------- Marker Constants (PUA U+E000..E025) ---------------------------
const ASIDE_ITALIC_OPEN     = " \u{E000} "; const ASIDE_ITALIC_CLOSE     = " \u{E001} ";
const ASIDE_BOLD_OPEN       = " \u{E002} "; const ASIDE_BOLD_CLOSE       = " \u{E003} ";
const ASIDE_UNDER_OPEN      = " \u{E004} "; const ASIDE_UNDER_CLOSE      = " \u{E005} ";
const ASIDE_STRIKE_OPEN     = " \u{E006} "; const ASIDE_STRIKE_CLOSE     = " \u{E007} ";
const ASIDE_INS_OPEN        = " \u{E008} "; const ASIDE_INS_CLOSE        = " \u{E009} ";
const ASIDE_MARK_OPEN       = " \u{E00A} "; const ASIDE_MARK_CLOSE       = " \u{E00B} ";
const ASIDE_SMALL_OPEN      = " \u{E00C} "; const ASIDE_SMALL_CLOSE      = " \u{E00D} ";
const ASIDE_SUB_OPEN        = " \u{E00E} "; const ASIDE_SUB_CLOSE        = " \u{E00F} ";
const ASIDE_SUP_OPEN        = " \u{E010} "; const ASIDE_SUP_CLOSE        = " \u{E011} ";
const ASIDE_CODE_OPEN       = " \u{E012} "; const ASIDE_CODE_CLOSE       = " \u{E013} ";
const ASIDE_KBD_OPEN        = " \u{E014} "; const ASIDE_KBD_CLOSE        = " \u{E015} ";
const ASIDE_SAMP_OPEN       = " \u{E016} "; const ASIDE_SAMP_CLOSE       = " \u{E017} ";
const ASIDE_VAR_OPEN        = " \u{E018} "; const ASIDE_VAR_CLOSE        = " \u{E019} ";
const ASIDE_Q_OPEN          = " \u{E01A} "; const ASIDE_Q_CLOSE          = " \u{E01B} ";
const ASIDE_CITE_OPEN       = " \u{E01C} "; const ASIDE_CITE_CLOSE       = " \u{E01D} ";
const ASIDE_ABBR_OPEN       = " \u{E01E} "; const ASIDE_ABBR_CLOSE       = " \u{E01F} ";
const ASIDE_TIME_OPEN       = " \u{E020} "; const ASIDE_TIME_CLOSE       = " \u{E021} ";
const ASIDE_SPAN_OPEN       = ""; const ASIDE_SPAN_CLOSE       = "";
const ASIDE_CUSTOM_OPEN     = " \u{E024} "; const ASIDE_CUSTOM_CLOSE     = " \u{E025}";

// --------------------------- Configuration ---------------------------
// Toggle features in web UI by default (checkboxes can override)
const CFG_DEFAULT_NORMALIZE_ELLIPSES = true;
const CFG_DEFAULT_INSERT_SCENE_BREAKS = true;
const CFG_DEFAULT_EXPAND_ABBR = true;
const CFG_DEFAULT_EXPAND_GEO = true;

// Geo data sources (optional). If both are empty, an in-memory fallback map is used.
const GEO_CSV_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'geo_abbr.csv'; // columns: abbr,expansion,kind,country_context,is_ambiguous,priority
// For DB, set e.g.: sqlite: __DIR__ . '/geo_abbr.sqlite'
const GEO_PDO_DSN = '';
const GEO_PDO_USER = '';
const GEO_PDO_PASS = '';

// Emphasis bits (combine with |)
const EMPH_ITALIC    = 1 << 0;
const EMPH_BOLD      = 1 << 1;
const EMPH_UNDERLINE = 1 << 2;  // usually OFF for TTS
const EMPH_STRIKE    = 1 << 3;  // usually OFF for TTS
const EMPH_SMALLCAPS = 1 << 4;  // usually OFF for TTS

// Order for opening/closing (deterministic)
const EMPH_ORDER = [EMPH_BOLD, EMPH_ITALIC, EMPH_UNDERLINE, EMPH_STRIKE, EMPH_SMALLCAPS];

function emph_has(int $mask, int $bit): bool { return ($mask & $bit) !== 0; }
function emph_add(int $mask, int $bit): int  { return $mask | $bit; }
function emph_sub(int $mask, int $bit): int  { return $mask & (~$bit); }


// --------------------------- Public API ---------------------------
/**
 * @return array{0:string,1:array<int,array<string,mixed>>}
 */
function html_to_text(string $htmlOrPath, array $rules = null, array $opts = []): array {
    $rules ??= default_rules();
    $opts = array_merge(default_opts(), $opts);

    [$doc, $body, $errors] = load_dom_and_body(
        $htmlOrPath,
        (bool)$opts['treat_input_as_path'],
        (bool)$opts['suppress_libxml_errors']
    );
    if (!$doc) {
        $events = [];
        if ($opts['collect_events']) {
            $events[] = ['type'=>'meta','event'=>'parse_failed','tag'=>'','text'=>'','inner'=>'','emit'=>''];
        }
        return ['', $events];
    }

    $state = [];
    $events = [];
    $outBuf = '';

    $root = $body ?: $doc->documentElement;
    walk_node($root, $rules, $opts, $state, $events, $outBuf, 0, null);

    if (!empty($errors) && $opts['collect_events']) {
        $events[] = ['type'=>'meta','event'=>'libxml_warnings','warnings'=>$errors,'tag'=>'','text'=>'','inner'=>'','emit'=>''];
    }
    if (!$opts['collect_events']) { $events = []; }

    if (is_int($opts['max_output_len']) && $opts['max_output_len'] > 0 && strlen($outBuf) > $opts['max_output_len']) {
        $outBuf = substr($outBuf, 0, $opts['max_output_len']);
        if ($opts['collect_events']) {
            $events[] = ['type'=>'meta','event'=>'truncated','tag'=>'','text'=>'','inner'=>'','emit'=>''];
        }
    }

    return [$outBuf, $events];
}

function default_opts(): array {
    return [
        'treat_input_as_path'    => true,
        'suppress_libxml_errors' => true,
        'collapse_text_nodes'    => true,
        'max_output_len'         => 0,
        'collect_events'         => false,
    ];
}

// --------------------------- Rules ---------------------------
function default_rules(): array {
    $emitChildren     = fn(array $ctx): string => '';
    $blockWithNewline = fn(array $ctx): string => '';

    return [
        // node primitives
        '#text'    => fn(array $ctx): string => $ctx['text'],
        '#comment' => fn(array $ctx): string => '',

        // blocks (walker adds a newline on close)
        'p'  => $blockWithNewline,
        'h1' => $blockWithNewline, 'h2' => $blockWithNewline, 'h3' => $blockWithNewline,
        'h4' => $blockWithNewline, 'h5' => $blockWithNewline, 'h6' => $blockWithNewline,

        // inline display enhancers — emit only OPEN marker here; CLOSE is appended by walker
        'i'      => fn($ctx) => ASIDE_ITALIC_OPEN,
        'em'     => fn($ctx) => ASIDE_ITALIC_OPEN,
        'b'      => fn($ctx) => ASIDE_BOLD_OPEN,
        'strong' => fn($ctx) => ASIDE_BOLD_OPEN,
        'u'      => fn($ctx) => ASIDE_UNDER_OPEN,
        's'      => fn($ctx) => ASIDE_STRIKE_OPEN,
        'strike' => fn($ctx) => ASIDE_STRIKE_OPEN,
        'del'    => fn($ctx) => ASIDE_STRIKE_OPEN,
        'ins'    => fn($ctx) => ASIDE_INS_OPEN,
        'mark'   => fn($ctx) => ASIDE_MARK_OPEN,
        'small'  => fn($ctx) => ASIDE_SMALL_OPEN,
        'sub'    => fn($ctx) => ASIDE_SUB_OPEN,
        'sup'    => fn($ctx) => ASIDE_SUP_OPEN,
        'code'   => fn($ctx) => ASIDE_CODE_OPEN,
        'kbd'    => fn($ctx) => ASIDE_KBD_OPEN,
        'samp'   => fn($ctx) => ASIDE_SAMP_OPEN,
        'var'    => fn($ctx) => ASIDE_VAR_OPEN,
        'q'      => fn($ctx) => ASIDE_Q_OPEN,
        'cite'   => fn($ctx) => ASIDE_CITE_OPEN,
        'abbr'   => fn($ctx) => ASIDE_ABBR_OPEN,
        'acronym'=> fn($ctx) => ASIDE_ABBR_OPEN,
        'time'   => fn($ctx) => ASIDE_TIME_OPEN,
        'span'   => fn($ctx) => ASIDE_SPAN_OPEN,

        // containers pass-through
        'div'     => $emitChildren, 'section' => $emitChildren, 'article' => $emitChildren,
        'main'    => $emitChildren, 'aside'   => $emitChildren, 'header'  => $emitChildren,
        'footer'  => $emitChildren, 'nav'     => $emitChildren,

        // lists
        'ul' => $emitChildren, 'ol' => $emitChildren, 'li' => $blockWithNewline,

        // links — emit nothing (children text will appear naturally)
        'a' => fn($ctx) => '',

        // breaks
        'br' => fn($ctx) => "\n", 'hr' => fn($ctx) => "\n",

        // tables (minimal; children emit cells; rows end with newline via walker)
        'table' => $emitChildren, 'tr' => $blockWithNewline, 'th' => $emitChildren, 'td' => $emitChildren,

        // ignored
        'img'=>fn($ctx)=>'', 'video'=>fn($ctx)=>'', 'audio'=>fn($ctx)=>'', 'script'=>fn($ctx)=>'', 'style'=>fn($ctx)=>'',

        // fallback
        '*' => $emitChildren,
    ];
}

/** Map of inline tags → close marker (used by walker after children). */
function inline_close_marker_map(): array {
    return [
        'i'=>ASIDE_ITALIC_CLOSE, 'em'=>ASIDE_ITALIC_CLOSE,
        'b'=>ASIDE_BOLD_CLOSE,   'strong'=>ASIDE_BOLD_CLOSE,
        'u'=>ASIDE_UNDER_CLOSE,
        's'=>ASIDE_STRIKE_CLOSE, 'strike'=>ASIDE_STRIKE_CLOSE, 'del'=>ASIDE_STRIKE_CLOSE,
        'ins'=>ASIDE_INS_CLOSE,
        'mark'=>ASIDE_MARK_CLOSE,
        'small'=>ASIDE_SMALL_CLOSE,
        'sub'=>ASIDE_SUB_CLOSE,
        'sup'=>ASIDE_SUP_CLOSE,
        'code'=>ASIDE_CODE_CLOSE, 'kbd'=>ASIDE_KBD_CLOSE, 'samp'=>ASIDE_SAMP_CLOSE, 'var'=>ASIDE_VAR_CLOSE,
        'q'=>ASIDE_Q_CLOSE, 'cite'=>ASIDE_CITE_CLOSE,
        'abbr'=>ASIDE_ABBR_CLOSE, 'acronym'=>ASIDE_ABBR_CLOSE,
        'time'=>ASIDE_TIME_CLOSE,
        'span'=>ASIDE_SPAN_CLOSE,
    ];
}

// --------------------------- Walker ---------------------------
function walk_node(
    DOMNode $node,
    array $rules,
    array $opts,
    array &$state,
    array &$events,
    string &$outBuf,
    int $depth,
    ?string $parentTag
): void {
    // Ensure emphasis state exists
    if (!isset($state['emph_stack']))  $state['emph_stack']  = [];
    if (!isset($state['emph_active'])) $state['emph_active'] = 0;

    $nt = $node->nodeType;

    if ($nt === XML_TEXT_NODE) {
        [$text, $isPre] = normalize_text_node($node, $opts, $parentTag);
        if ($text === '') return;
        $ctx = [
            'type'=>'text','tag'=>null,'node'=>$node,'attrs'=>[],
            'inner_text'=>$text,'text'=>$text,'depth'=>$depth,
            'state'=>&$state,'parent_tag'=>$parentTag,'is_pre_context'=>$isPre
        ];
        $emit = call_rule($rules, '#text', $ctx);
        $outBuf .= $emit;

        if ($opts['collect_events']) {
            $row = event_row($ctx, $emit);
            // Optional: show active emphasis for text
            $row['emph_active'] = emph_bits_label((int)$state['emph_active']);
            $events[] = $row;
        }
        return;
    }

    if ($nt === XML_COMMENT_NODE) {
        $ctx = [
            'type'=>'comment','tag'=>null,'node'=>$node,'attrs'=>[],
            'inner_text'=>'','text'=>$node->nodeValue ?? '', 'depth'=>$depth,
            'state'=>&$state,'parent_tag'=>$parentTag,'is_pre_context'=>false
        ];
        $emit = call_rule($rules, '#comment', $ctx);
        $outBuf .= $emit;
        if ($opts['collect_events']) $events[] = event_row($ctx, $emit);
        return;
    }

    if ($nt === XML_ELEMENT_NODE) {
        $tag   = strtolower($node->nodeName);
        $attrs = element_attrs($node);
        $isPre = in_array($tag, ['pre','code','textarea'], true);
        $inner = collect_inner_text($node, $opts, $isPre);

        $ctx = [
            'type'=>'element','tag'=>$tag,'node'=>$node,'attrs'=>$attrs,
            'inner_text'=>$inner,'text'=>'','depth'=>$depth,
            'state'=>&$state,'parent_tag'=>$parentTag,'is_pre_context'=>$isPre
        ];

        // --- Emphasis detection BEFORE any emissions
        $parentMask = (int)$state['emph_active'];
        // If you haven't added detect_emphasis_for_element yet, paste that first.
        $det        = function_exists('detect_emphasis_for_element')
            ? detect_emphasis_for_element($tag, $attrs, $parentMask)
            : ['activated'=>0,'new'=>$parentMask];

        $activated  = (int)$det['activated'];
        $newActive  = (int)$det['new'];

        // Emit OPEN markers for bits turned ON here
        if (defined('EMPH_ORDER')) {
            $emphOpenEmit = '';
            foreach (EMPH_ORDER as $bit) {
                if (emph_has($activated, $bit)) {
                    switch ($bit) {
                        case EMPH_ITALIC:    $outBuf .= ASIDE_ITALIC_OPEN; $emphOpenEmit .= ASIDE_ITALIC_OPEN; break;
                        case EMPH_BOLD:      $outBuf .= ASIDE_BOLD_OPEN;   $emphOpenEmit .= ASIDE_BOLD_OPEN;   break;
                        case EMPH_UNDERLINE: $outBuf .= ASIDE_UNDER_OPEN;  $emphOpenEmit .= ASIDE_UNDER_OPEN;  break;
                        case EMPH_STRIKE:    $outBuf .= ASIDE_STRIKE_OPEN; $emphOpenEmit .= ASIDE_STRIKE_OPEN; break;
                        case EMPH_SMALLCAPS: $outBuf .= ASIDE_SMALL_OPEN;  $emphOpenEmit .= ASIDE_SMALL_OPEN;  break;
                    }
                }
            }
        }

        // Stash for close; update active
        $state['emph_stack'][] = $activated;
        $state['emph_active']  = $newActive;

        // OPEN emission from your rules (e.g., block wrappers etc.)
        $emitOpen = call_rule($rules, $tag, $ctx);
        if ($emitOpen !== '') {
            $outBuf .= $emitOpen;
        }

        // Debug row for element OPEN
        if ($opts['collect_events']) {
            $row = event_row($ctx + ['phase'=>'open'], $emitOpen . $emphOpenEmit);
            $row['emph_inherited']      = emph_bits_label($parentMask);
            $row['emph_activated_here'] = emph_bits_label($activated);
            $events[] = $row;
        }
        
        // Children (sequential)
        for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
            walk_node($child, $rules, $opts, $state, $events, $outBuf, $depth + 1, $tag);
        }

        // Prepare CLOSE info before closing emphasis
        $openedHere = (int)array_pop($state['emph_stack']);
        $activeBeforeClose = (int)$state['emph_active'];

        // Emit CLOSE markers for what this node opened (reverse order)
        if (defined('EMPH_ORDER')) {
            $emphCloseEmit = '';
            for ($i = count(EMPH_ORDER) - 1; $i >= 0; $i--) {
                $bit = EMPH_ORDER[$i];
                if (emph_has($openedHere, $bit)) {
                    switch ($bit) {
                        case EMPH_ITALIC:    $outBuf .= ASIDE_ITALIC_CLOSE; $emphCloseEmit .= ASIDE_ITALIC_CLOSE; break;
                        case EMPH_BOLD:      $outBuf .= ASIDE_BOLD_CLOSE;   $emphCloseEmit .= ASIDE_BOLD_CLOSE;   break;
                        case EMPH_UNDERLINE: $outBuf .= ASIDE_UNDER_CLOSE;  $emphCloseEmit .= ASIDE_UNDER_CLOSE;  break;
                        case EMPH_STRIKE:    $outBuf .= ASIDE_STRIKE_CLOSE; $emphCloseEmit .= ASIDE_STRIKE_CLOSE; break;
                        case EMPH_SMALLCAPS: $outBuf .= ASIDE_SMALL_CLOSE;  $emphCloseEmit .= ASIDE_SMALL_CLOSE;  break;
                    }
                }
            }
        }

        // Restore to parent active (remove what we opened)
        $state['emph_active'] = $state['emph_active'] & (~$openedHere);

        // CLOSE emission from your block logic (newline or inline close)
        $emitClose = '';
        $closeMap = inline_close_marker_map();
        if (isset($closeMap[$tag])) {
            $emitClose = $closeMap[$tag]; // usually empty because we already closed above for inline emphasis
        } elseif (in_array($tag, ['p','h1','h2','h3','h4','h5','h6','li','tr','pre','blockquote'], true)) {
            $emitClose = "\n";
        }
        if ($emitClose !== '') {
            $outBuf .= $emitClose;
        }

        // Debug row for element CLOSE
        if ($opts['collect_events']) {
            $row = event_row($ctx + ['phase'=>'close'], $emitClose . $emphCloseEmit);
            $row['emph_inherited']      = emph_bits_label($activeBeforeClose);
            $row['emph_activated_here'] = emph_bits_label($openedHere);
            $events[] = $row;
        }
    return;
    }
}

function call_rule(array $rules, string $selector, array $ctx): string {
    if ($ctx['type'] === 'text') {
        $fn = $rules['#text'] ?? (fn(array $c): string => $c['text']);
        return (string)$fn($ctx);
    }
    if ($ctx['type'] === 'comment') {
        $fn = $rules['#comment'] ?? (fn(array $c): string => '');
        return (string)$fn($ctx);
    }
    $tag = $ctx['tag'] ?? '';
    if (isset($rules[$tag]) && is_callable($rules[$tag])) {
        return (string)$rules[$tag]($ctx);
    }
    if (isset($rules['*']) && is_callable($rules['*'])) {
        return (string)$rules['*']($ctx);
    }
    return '';
}

// --------------------------- Helpers ---------------------------
// Pretty-print emphasis mask for debug table
//if (!function_exists('emph_bits_label')) {
    function emph_bits_label(int $mask): string {
        $labels = [];
        if (defined('EMPH_BOLD')      && ($mask & EMPH_BOLD))      $labels[] = 'bold';
        if (defined('EMPH_ITALIC')    && ($mask & EMPH_ITALIC))    $labels[] = 'italic';
        if (defined('EMPH_UNDERLINE') && ($mask & EMPH_UNDERLINE)) $labels[] = 'underline';
        if (defined('EMPH_STRIKE')    && ($mask & EMPH_STRIKE))    $labels[] = 'strike';
        if (defined('EMPH_SMALLCAPS') && ($mask & EMPH_SMALLCAPS)) $labels[] = 'smallcaps';
        return $labels ? implode('+', $labels) : '—';
    }
//}
function load_dom_and_body(string $htmlOrPath, bool $treatAsPath, bool $suppress): array {
    $errors = [];
    if ($suppress) libxml_use_internal_errors(true);

    $html = $treatAsPath ? @file_get_contents($htmlOrPath) : $htmlOrPath;
    if ($html === false || $html === '') {
        if ($suppress) { libxml_clear_errors(); libxml_use_internal_errors(false); }
        return [null, null, ['Empty input or unreadable file']];
    }

    if (!preg_match('~</?(html|body|head)\b~i', $html)) {
        $html = "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>{$html}</body></html>";
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $body = $doc->getElementsByTagName('body')->item(0);

    if ($suppress) {
        foreach (libxml_get_errors() as $err) { $errors[] = trim($err->message) . " (line {$err->line})"; }
        libxml_clear_errors(); libxml_use_internal_errors(false);
    }

    return [$doc, $body, $errors];
}

function normalize_text_node(DOMNode $node, array $opts, ?string $parentTag): array {
    $text = $node->nodeValue ?? '';
    $isPre = is_preformatted_context($node);
    if ($isPre) return [$text, true];
    if ($opts['collapse_text_nodes']) {
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        return [trim((string)$collapsed), false];
    }
    return [$text, false];
}

function is_preformatted_context(DOMNode $node): bool {
    for ($n = $node; $n !== null; $n = $n->parentNode) {
        if ($n->nodeType === XML_ELEMENT_NODE) {
            $t = strtolower($n->nodeName);
            if (in_array($t, ['pre','code','textarea','script','style'], true)) return true;
        }
    }
    return false;
}

function collect_inner_text(DOMNode $el, array $opts, bool $isPreCtx): string {
    // Only used for preview/rules that explicitly read it; children still emit their own text separately.
    $buf = '';
    for ($c = $el->firstChild; $c; $c = $c->nextSibling) {
        if ($c->nodeType === XML_TEXT_NODE) {
            [$t] = normalize_text_node($c, $opts, strtolower($el->nodeName));
            if ($t !== '') { $buf .= $t . ($isPreCtx ? '' : ' '); }
        }
    }
    return trim($buf);
}

function element_attrs(DOMNode $node): array {
    $attrs = [];
    if ($node instanceof DOMElement) {
        foreach ($node->attributes as $attr) { $attrs[strtolower($attr->name)] = $attr->value; }
    }
    return $attrs;
}

function event_row(array $ctx, string $emit): array {
    return [
        'type'  => $ctx['type'] ?? 'meta',
        'tag'   => $ctx['tag']  ?? '',
        'phase' => $ctx['phase'] ?? 'emit',
        'depth' => $ctx['depth'] ?? 0,
        'text'  => (string)($ctx['text'] ?? ''),
        'inner' => (string)($ctx['inner_text'] ?? ''),
        'emit'  => (string)$emit,
    ];
}
// --------------------------- Post-processing helpers (drop-in) ---------------------------

/** Collapse stray whitespace and limit blank lines. */
function tidy_whitespace(string $text): string {
    // replace 2+ spaces with just 1
    $text = preg_replace('/[ \t]{2,}/u', " ", $text);

    // The pattern uses negative lookarounds to match only a single newline.
    // (?<!\n)  - Negative lookbehind: asserts the character before is NOT a newline.
    // \n        - Matches the newline character itself.
    // (?!\n)   - Negative lookahead: asserts the character after is NOT a newline.
    $pattern = '/(?<!\n)\n(?!\n)/';
    $replacement = "\n\n";
    $text = preg_replace($pattern, $replacement, $text);
    
    // Remove spaces/tabs at line ends; collapse runs of blank lines to max 2
    $text = preg_replace('/[ \t]+\n/u', "\n", $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return $text;
}


/**
 * Normalize various dash-ish patterns to a spaced em dash:
 *   - Convert en dashes (–) and runs of hyphens (--, ---) to " — "
 *   - Treat single hyphen as dash when separated from words by spaces (or space on one side)
 *   - Keep true word hyphens (re-enter, twenty-two) intact
 *   - Keep list bullets ("- item") and numeric ranges ("1990-1999") intact
 *   - Ensure em dashes have exactly one space on each side
 */
/**
 * Normalize various dash-ish patterns to a spaced em dash:
 *   - Convert en dashes (–) and runs of hyphens (--, ---) to " — "
 *   - Treat single hyphen as dash when separated from words by spaces (or space on one side)
 *   - Keep true word hyphens (re-enter, twenty-two) intact
 *   - Keep list bullets ("- item") and numeric ranges ("1990-1999") intact
 *   - Ensure em dashes have exactly one space on each side
 */
function normalize_dashes(string $text): string {
    // --- 0) Protect bullets and numeric ranges with placeholders
    $placeholders = [];

    // A. bullets at start of line: "- item" or " - item"
    $text = preg_replace_callback('/(^|\R)([ \t]*)-\s+(?=\S)/u', function ($m) use (&$placeholders) {
        $k = "\x07BULLET" . count($placeholders) . "\x07";
        $placeholders[$k] = $m[0]; // entire match including leading newline/indent
        return $k;
    }, $text);

    // B. numeric ranges "123-456" (optional spaces around hyphen)
    $text = preg_replace_callback('/\b(\d+)\s*-\s*(\d+)\b/u', function ($m) use (&$placeholders) {
        $k = "\x07NUMRANGE" . count($placeholders) . "\x07";
        $placeholders[$k] = $m[1] . '-' . $m[2]; // keep as hyphen (you asked to ignore single "-")
        return $k;
    }, $text);

    // --- 1) Unify obvious dash forms to em dash
    // En dash → em dash
    $text = str_replace("–", "—", $text);

    // Runs of 2+ hyphens (with or without surrounding spaces) → em dash
    // Example: "word--word", "word --- word" → " — "
    $text = preg_replace('/\s*--+\s*/u', ' — ', $text);

    // Single hyphen surrounded by spaces → em dash
    // Example: " word - word " → " word — word "
    $text = preg_replace('/\s-\s/u', ' — ', $text);

    // Hyphen with space on one side and letters on both sides → em dash
    // Example: "word- word" or "word -word" → "word — word"
    $text = preg_replace('/(?<=\p{L})-\s(?=\p{L})/u', ' — ', $text);
    $text = preg_replace('/(?<=\p{L})\s-(?=\p{L})/u', ' — ', $text);

    // --- 2) Ensure exactly one space around em dashes
    // Any em dash with stray spacing → normalize to " — "
    $text = preg_replace('/\s*—\s*/u', ' — ', $text);

    // Optional tidy around punctuation following an em dash: " — , " → " —, "
    $text = preg_replace('/ — \s*([,.;:?!])\s*/u', ' — $1 ', $text);

    // --- 3) Restore placeholders
    foreach ($placeholders as $k => $v) {
        $text = str_replace($k, $v, $text);
    }

    return $text;
}

/** Normalize ellipses variants to a single style (…); keep decimals/IPs intact. */
function normalize_ellipses(string $text): string {
    // Protect dotted numbers/IPs like 3.1415, 10.0.0.1
    $placeholders = [];
    $i = 0;
    $text = preg_replace_callback(
        '/\b\d+\.(?:\d+\.)*\d+\b/u',
        function ($m) use (&$placeholders, &$i) {
            $k = '[[NUM$' . ($i++) . ']]';
            $placeholders[$k] = $m[0];
            return $k;
        },
        $text
    );

    // Overlong runs of dots → three; spaced triples ". . ." → "..."
    $text = preg_replace('/\.{4,}/u', '...', $text);
    $text = preg_replace('/(?:\s*\.\s*){3}/u', '...', $text);

    // Convert "..." to single glyph, but only when exactly 3 (no 4+)
    $text = preg_replace('/(?<!\.)\.{3}(?!\.)/u', '…', $text);

    // Tidy spacing: keep space on both sides when between words
    $text = preg_replace('/(\S)\s*…\s*(\S)/u', '$1 … $2', $text);
    // Tighten around closing punctuation or brackets
    $text = preg_replace('/…\s*([\)\]\}”’?!,:;])/u', '…$1', $text);
    // Tighten after opening quotes/brackets
    $text = preg_replace('/([“‘\("\[])\s*…/u', '$1…', $text);

    // Restore placeholders
    foreach ($placeholders as $k => $v) {
        $text = str_replace($k, $v, $text);
    }
    return $text;
}

/**
 * Detect dinkus/ornament scene breaks and replace their entire line with "<hr>".
 * Emits no spoken text later (you will strip <hr> before TTS and insert a long pause).
 */
function insert_scene_breaks_as_hr(string $text): string {
    $lines = preg_split('/\r?\n/', $text);
    if ($lines === false) return $text;

    $emit = [];
    $is_break = function (string $line): bool {
        $trim = trim($line);
        if ($trim === '') return false;

        // Already a break tag
        if (preg_match('/^<\s*hr\b[^>]*>$/i', $trim)) return true;

        // Reject if any letters/digits present
        if (preg_match('/[\p{L}\p{N}]/u', $trim)) return false;

        // Normalize inner spacing; enforce short ornament lines
        $plain = preg_replace('/\s+/u', ' ', $trim);
        if (mb_strlen(str_replace(' ', '', $plain)) > 10) return false;

        // Asterism
        if ($plain === '⁂') return true;

        // Triples (with optional spacing)
        $triples = [
            '/^(\*\s*){3}$/u',  // * * * or ***
            '/^(•\s*){3}$/u',   // • • •
            '/^(·\s*){3}$/u',   // · · ·
            '/^(∙\s*){3}$/u',   // ∙ ∙ ∙
            '/^(—\s*){3}$/u',   // — — — (em dash)
            '/^(–\s*){3}$/u',   // – – – (en dash)
            '/^(\.\s*){3}$/u',  // . . .
        ];
        foreach ($triples as $re) {
            if (preg_match($re, $plain)) return true;
        }

        // Standalone ellipsis as divider
        if ($plain === '…' || $plain === '...') return true;

        // Short ornament bars (1–5 copies of a single symbol family)
        if (preg_match('/^(?:[❧❦✻✶✴✧✤✥\*•·](?:\s*[❧❦✻✶✴✧✤✥\*•·]){0,4})$/u', $plain)) return true;

        return false;
    };

    $prevWasHr = false;
    foreach ($lines as $line) {
        if ($is_break($line)) {
            if (!$prevWasHr) { $emit[] = '<hr>'; $prevWasHr = true; }
        } else {
            $emit[] = $line;
            $prevWasHr = false;
        }
    }

    return implode("\n", $emit);
}

/**
 * Expand period’d abbreviations (titles, roads, Latinisms, months) + a.m./p.m.
 * Also includes conservative ALL-CAPS allowlist expansion (avoid acronyms).
 *
 * Options:
 * - am_pm_mode: 'labels' | 'symbols' (default 'labels': “in the morning/evening”)
 * - expand_months: bool (default true)
 */
function expand_abbreviations(string $text, array $opts = []): string {
    $cfg = array_merge([
        'am_pm_mode'   => 'labels',
        'expand_months'=> true,
    ], $opts);

    $preserve = function (string $src, string $replacement): string {
        if ($src === strtoupper($src)) return strtoupper($replacement);
        if ($src === strtolower($src)) return strtolower($replacement);
        return mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
    };

    // St. → Saint/Street (names vs addresses)
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

    // a.m./p.m.
    if ($cfg['am_pm_mode'] === 'labels') {
        $text = preg_replace('/\b([0-9]{1,2})(?::[0-5][0-9])?\s*a\.m\./i', '$1 in the morning', $text);
        $text = preg_replace('/\b([0-9]{1,2})(?::[0-5][0-9])?\s*p\.m\./i', '$1 in the evening', $text);
    } else {
        $text = preg_replace('/\ba\.m\./i', 'AM', $text);
        $text = preg_replace('/\bp\.m\./i', 'PM', $text);
    }

    // Period’d abbreviations
    $map = [
        'Mr\.'=>'Mister','Mrs\.'=>'Missus','Ms\.'=>'Miss','Mx\.'=>'Mix','Dr\.'=>'Doctor','Prof\.'=>'Professor','Rev\.'=>'Reverend',
        'Sr\.'=>'Senior','Jr\.'=>'Junior','Gen\.'=>'General','Col\.'=>'Colonel','Capt\.'=>'Captain','Lt\.'=>'Lieutenant','Sgt\.'=>'Sergeant',
        'Gov\.'=>'Governor','Sen\.'=>'Senator','Rep\.'=>'Representative',
        'Mt\.'=>'Mount','Ft\.'=>'Fort',
        'Ave\.'=>'Avenue','Blvd\.'=>'Boulevard','Rd\.'=>'Road','Ln\.'=>'Lane','Hwy\.'=>'Highway',
        'No\.'=>'Number','Dept\.'=>'Department','Co\.'=>'Company','Corp\.'=>'Corporation','Inc\.'=>'Incorporated','Ltd\.'=>'Limited',
        'Etc\.'=>'Et cetera','etc\.'=>'et cetera','e\.g\.'=>'for example','i\.e\.'=>'that is','vs\.'=>'versus',
    ];
    foreach ($map as $pat => $rep) {
        $text = preg_replace_callback("/\\b({$pat})/u", fn($m) => $preserve($m[1], $rep), $text);
    }

    if ($cfg['expand_months']) {
        $months = [
            'Jan\.'=>'January','Feb\.'=>'February','Mar\.'=>'March','Apr\.'=>'April',
            'Jun\.'=>'June','Jul\.'=>'July','Aug\.'=>'August',
            'Sep\.'=>'September','Sept\.'=>'September','Oct\.'=>'October','Nov\.'=>'November','Dec\.'=>'December'
        ];
        foreach ($months as $pat => $rep) {
            $text = preg_replace_callback("/\\b({$pat})/u", fn($m) => $preserve($m[1], $rep), $text);
        }
    }

    // Whitelisted ALL-CAPS expansions (avoid acronyms broadly)
    $allow = ['OK'=>'okay','TV'=>'television','ASAP'=>'as soon as possible'];
    $skip  = ['USA','UAE','EU','UK','UN','NATO','FBI','CIA','IRS','WHO','IMF','GPU','CPU','RAM','USB','AI','NASA','HTML','CSS','PDF','TTS','OR','AND','ME','MO','US'];
    $text = preg_replace_callback('/\b([A-Z]{2,6})\b/u', function ($m) use ($allow, $skip) {
        $w = $m[1];
        return (isset($allow[$w]) && !in_array($w, $skip, true)) ? $allow[$w] : $w;
    }, $text);

    return $text;
}

/** Load geo map from DB or CSV; otherwise use a conservative in-memory fallback. */
function load_geo_map(): array {
    // DB first if configured
    if (GEO_PDO_DSN !== '') {
        try {
            $pdo = new PDO(GEO_PDO_DSN, GEO_PDO_USER, GEO_PDO_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->query("SELECT abbr, expansion, kind, country_context, is_ambiguous, priority FROM geo_abbr");
            $map = [];
            foreach ($stmt as $row) {
                $map[strtoupper(trim($row['abbr']))] = [
                    'expansion'       => $row['expansion'],
                    'kind'            => $row['kind'] ?? '',
                    'country_context' => strtoupper((string)$row['country_context']),
                    'ambiguous'       => (int)$row['is_ambiguous'] === 1,
                    'priority'        => (int)($row['priority'] ?? 0),
                ];
            }
            if ($map) return $map;
        } catch (Throwable $e) {
            // fall through to CSV / fallback
        }
    }

    // CSV next
    if (is_file(GEO_CSV_PATH)) {
        $fh = fopen(GEO_CSV_PATH, 'r');
        if ($fh) {
            $map = []; $hdr = null;
            while (($row = fgetcsv($fh)) !== false) {
                if ($hdr === null) { $hdr = array_map('strtolower', $row); continue; }
                $rec  = @array_combine($hdr, $row);
                if (!$rec) continue;
                $abbr = strtoupper(trim((string)$rec['abbr']));
                if ($abbr === '') continue;
                $map[$abbr] = [
                    'expansion'       => $rec['expansion'] ?? '',
                    'kind'            => $rec['kind'] ?? '',
                    'country_context' => strtoupper((string)($rec['country_context'] ?? '')),
                    'ambiguous'       => (isset($rec['is_ambiguous']) && (int)$rec['is_ambiguous'] === 1),
                    'priority'        => (int)($rec['priority'] ?? 0),
                ];
            }
            fclose($fh);
            if ($map) return $map;
        }
    }

    // Minimal conservative fallback (exclude ambiguous ones like CA/GA/WA/ME/MO/OK)
    return [
        'AL'=>['expansion'=>'Alabama','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'AK'=>['expansion'=>'Alaska','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'AZ'=>['expansion'=>'Arizona','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'AR'=>['expansion'=>'Arkansas','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'CO'=>['expansion'=>'Colorado','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'CT'=>['expansion'=>'Connecticut','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'DE'=>['expansion'=>'Delaware','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'FL'=>['expansion'=>'Florida','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'HI'=>['expansion'=>'Hawaii','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'ID'=>['expansion'=>'Idaho','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'IL'=>['expansion'=>'Illinois','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'IN'=>['expansion'=>'Indiana','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'IA'=>['expansion'=>'Iowa','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'KS'=>['expansion'=>'Kansas','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'KY'=>['expansion'=>'Kentucky','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'LA'=>['expansion'=>'Louisiana','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MD'=>['expansion'=>'Maryland','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MA'=>['expansion'=>'Massachusetts','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MI'=>['expansion'=>'Michigan','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MN'=>['expansion'=>'Minnesota','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MS'=>['expansion'=>'Mississippi','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'MT'=>['expansion'=>'Montana','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NE'=>['expansion'=>'Nebraska','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NV'=>['expansion'=>'Nevada','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NH'=>['expansion'=>'New Hampshire','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NJ'=>['expansion'=>'New Jersey','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NM'=>['expansion'=>'New Mexico','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NY'=>['expansion'=>'New York','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'NC'=>['expansion'=>'North Carolina','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'ND'=>['expansion'=>'North Dakota','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'OH'=>['expansion'=>'Ohio','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'OR'=>['expansion'=>'Oregon','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'PA'=>['expansion'=>'Pennsylvania','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'RI'=>['expansion'=>'Rhode Island','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'SC'=>['expansion'=>'South Carolina','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'SD'=>['expansion'=>'South Dakota','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'TN'=>['expansion'=>'Tennessee','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'TX'=>['expansion'=>'Texas','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'UT'=>['expansion'=>'Utah','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'VA'=>['expansion'=>'Virginia','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'VT'=>['expansion'=>'Vermont','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'WA'=>['expansion'=>'Washington','kind'=>'state','country_context'=>'US','ambiguous'=>true,'priority'=>0], // keep as ambiguous if used
        'WI'=>['expansion'=>'Wisconsin','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'WV'=>['expansion'=>'West Virginia','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        'WY'=>['expansion'=>'Wyoming','kind'=>'state','country_context'=>'US','ambiguous'=>false,'priority'=>0],
        // Deliberately omitting CA, GA, ME, MO, OK from automatic fallback (ambiguous).
    ];
}

/**
 * Conservative geo expansion using context cues; respects 'ambiguous' flags and locale.
 *
 * - Expands in "City, ST" (optional ZIP) and "123 Street, ST" patterns.
 * - For standalone two-letter tokens, only expands non-ambiguous codes.
 */
function expand_geo_abbr(string $text, array $geoMap, string $locale = 'US'): string {
    $locale = strtoupper($locale);
    $zip = '(?:\d{5}(?:-\d{4})?)';

    // 1) City, ST[, ZIP]
    $cityCommaState = '/\b([A-Z][a-zA-Z’\.-]+)\s*,\s*([A-Z]{2})\b(?:\s*' . $zip . ')?/u';
    $text = preg_replace_callback($cityCommaState, function ($m) use ($geoMap, $locale) {
        $st = strtoupper($m[2]);
        if (!isset($geoMap[$st])) return $m[0];
        $rec = $geoMap[$st];
        if ($rec['ambiguous'] && $rec['country_context'] !== '' && $rec['country_context'] !== $locale) return $m[0];
        return $m[1] . ', ' . $rec['expansion'];
    }, $text);

    // 2) …, ST after street addresses (line-start)
    $streetThenState = '/^\s*\d+[^,\n]*,\s*([A-Z]{2})\b(?:\s*' . $zip . ')?/mu';
    $text = preg_replace_callback($streetThenState, function ($m) use ($geoMap, $locale) {
        $st = strtoupper($m[1]);
        if (!isset($geoMap[$st])) return $m[0];
        $rec = $geoMap[$st];
        if ($rec['ambiguous'] && $rec['country_context'] !== '' && $rec['country_context'] !== $locale) return $m[0];
        // Replace first occurrence of ST with expansion within the matched substring
        return preg_replace('/\b' . preg_quote($st, '/') . '\b/u', $rec['expansion'], $m[0], 1);
    }, $text);

    // 3) Standalone safe codes (non-ambiguous only)
    $text = preg_replace_callback('/\b([A-Z]{2})\b/u', function ($m) use ($geoMap) {
        $st = strtoupper($m[1]);
        if (!isset($geoMap[$st])) return $m[0];
        $rec = $geoMap[$st];
        if ($rec['ambiguous']) return $m[0];
        return $rec['expansion'];
    }, $text);

    return $text;
}
/**
 * Derive emphasis mask for this element from tag, style, and class.
 * @param string      $tag         lowercase tag name
 * @param array       $attrs       element attributes (lowercased keys)
 * @param int         $parentMask  active emphasis inherited from parent
 * @return array{activated:int,new:int,dbg?:array} activated: bits turned ON here; new: final active mask for this node
 */
function detect_emphasis_for_element(string $tag, array $attrs, int $parentMask): array {
    // 0) Start with parent state
    $mask = $parentMask;

    // 1) Tag-based defaults
//    if ($tag === 'i' || $tag === 'em')            { $mask = emph_add($mask, EMPH_ITALIC); }
//    if ($tag === 'b' || $tag === 'strong')        { $mask = emph_add($mask, EMPH_BOLD);   }

    // 2) Inline style (can turn ON or explicitly OFF)
    $styleOn  = 0;
    $styleOff = 0;
    if (isset($attrs['style']) && is_string($attrs['style'])) {
        $style = strtolower($attrs['style']);

        // font-style
        if (preg_match('/font-style\s*:\s*(italic|oblique|normal)\b/i', $style, $m)) {
            if ($m[1] === 'normal') { $styleOff |= EMPH_ITALIC; } else { $styleOn |= EMPH_ITALIC; }
        }
        // font-weight (keywords)
        if (preg_match('/font-weight\s*:\s*(bold|bolder|normal)\b/i', $style, $m)) {
            if ($m[1] === 'normal') { $styleOff |= EMPH_BOLD; } else { $styleOn |= EMPH_BOLD; }
        }
        // font-weight (numeric)
        if (preg_match('/font-weight\s*:\s*([1-9]00)\b/i', $style, $m)) {
            $w = (int)$m[1];
            if     ($w >= 600) { $styleOn  |= EMPH_BOLD; }
            elseif ($w === 400) { $styleOff |= EMPH_BOLD; }
        }
        // font-variant
        if (preg_match('/font-variant\s*:\s*(small-caps|normal)\b/i', $style, $m)) {
            if ($m[1] === 'small-caps') { $styleOn  |= EMPH_SMALLCAPS; }
            else                        { $styleOff |= EMPH_SMALLCAPS; }
        }
        // text-decoration(-line)
        if (preg_match('/text-decoration(?:-line)?\s*:\s*([^;]+)/i', $style, $m)) {
            $td = strtolower($m[1]);
            if (preg_match('/none\b/', $td)) {
                $styleOff |= (EMPH_UNDERLINE | EMPH_STRIKE);
            } else {
                if (preg_match('/underline\b/', $td))    { $styleOn |= EMPH_UNDERLINE; }
                if (preg_match('/line-through\b/', $td)) { $styleOn |= EMPH_STRIKE;    }
            }
        }
        // font shorthand (best-effort substring checks)
        if (preg_match('/font\s*:\s*([^;]+)/i', $style, $m)) {
            $fv = strtolower($m[1]);
            if (preg_match('/italic|oblique/', $fv)) { $styleOn |= EMPH_ITALIC; }
            if (preg_match('/\bbold\b/', $fv))       { $styleOn |= EMPH_BOLD;   }
            if (preg_match('/small-caps/', $fv))     { $styleOn |= EMPH_SMALLCAPS; }
        }
    }

    // Apply style resets then ons
    $mask = $mask & (~$styleOff);
    $mask = $mask | $styleOn;

    // 3) Class hints (turn ON only; never OFF)
    if (isset($attrs['class']) && is_string($attrs['class'])) {
        $cls = strtolower($attrs['class']);
        $tokens = preg_split('/\s+/', $cls, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $tok) {
            $parts = preg_split('/[-_]/', $tok) ?: [$tok];
            foreach ($parts as $p) {
                if ($p === 'italic' || $p === 'oblique' || $p === 'em' || $p === 'emphasis' || $p === 'font' /*later checked*/ ) {
                    $mask |= EMPH_ITALIC;
                }
                if ($p === 'bold' || $p === 'strong' || $p === 'heavy' || $p === 'semibold' || $p === 'semi' || $p === 'sb') {
                    $mask |= EMPH_BOLD;
                }
                if ($p === 'underline' || $p === 'u') {
                    $mask |= EMPH_UNDERLINE;
                }
                if ($p === 'strike' || $p === 'strikethrough' || $p === 'del' || $p === 's' || $p === 'linethrough') {
                    $mask |= EMPH_STRIKE;
                }
                if ($p === 'smallcaps' || $p === 'small' || $p === 'sc') {
                    $mask |= EMPH_SMALLCAPS;
                }
                // numeric weight classes like fw700, w600, weight-700
                if (preg_match('/^(?:fw|w|weight|fontweight)?-?([1-9]00)$/', $p, $wm)) {
                    if ((int)$wm[1] >= 600) { $mask |= EMPH_BOLD; }
                }
            }
        }
    }

    // 4) Compute which bits this node ACTIVATES (transition from parent OFF→ON)
    $activated = ($mask & (~$parentMask));

    // 5) (Optional) mute bits you don’t want for TTS
    // e.g., to ignore underline/strike/smallcaps by default:
    // $activated = $activated & ~(EMPH_UNDERLINE | EMPH_STRIKE | EMPH_SMALLCAPS);
    // $mask      = $mask      & ~(EMPH_UNDERLINE | EMPH_STRIKE | EMPH_SMALLCAPS);

    return ['activated' => $activated, 'new' => $mask];
}

// --------------------------- Web UI ---------------------------
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $mode = $_GET['mode'] ?? '';
    if ($mode === 'debug') { debug_page(); } else { normal_page(); }
    exit;
}

function normal_page(): void {
    $path = $_POST['path'] ?? ($_GET['path'] ?? '');
    $raw  = $_POST['raw_html'] ?? '';
    $input = $path !== '' ? $path : $raw;

    // UI toggles
    $doEllipses = isset($_POST['do_ellipses']) ? (bool)$_POST['do_ellipses'] : CFG_DEFAULT_NORMALIZE_ELLIPSES;
    $doScene    = isset($_POST['do_scene']) ? (bool)$_POST['do_scene'] : CFG_DEFAULT_INSERT_SCENE_BREAKS;
    $doAbbr     = isset($_POST['do_abbr']) ? (bool)$_POST['do_abbr'] : CFG_DEFAULT_EXPAND_ABBR;
    $doGeo      = isset($_POST['do_geo']) ? (bool)$_POST['do_geo'] : CFG_DEFAULT_EXPAND_GEO;
    $locale     = $_POST['locale'] ?? 'US';

    $opts = default_opts();
    $opts['treat_input_as_path'] = ($path !== '');

    [$text] = $input !== '' ? html_to_text($input, default_rules(), $opts) : [''];

    // --- Post-processing pipeline ---
    if ($text !== '') {
        if ($doEllipses) {
            $text = normalize_ellipses($text);
        }
        if ($doScene) {
            $text = insert_scene_breaks_as_hr($text);
        }
        $text = normalize_dashes($text);
        if ($doAbbr) {
            $text = expand_abbreviations($text, [ 'am_pm_mode' => 'labels', 'expand_months' => true ]);
        }
        if ($doGeo) {
            $geo = load_geo_map();
            $text = expand_geo_abbr($text, $geo, $locale);
        }
        $text = tidy_whitespace($text);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><meta charset='utf-8'><title>HTML→Plain Text</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:20px} textarea{width:100%;height:160px;font-family:ui-monospace,Consolas,monospace} pre {white-space: pre-wrap;background: #f6f8fa;padding: 10px;border-radius: 8px;font-family: 'Georgia', 'Times New Roman', serif;font-size: 16px;line-height: 1.4;} fieldset{border:1px solid #ddd;border-radius:8px;padding:10px;margin:10px 0}</style>";
    echo "<h1>HTML→Plain Text</h1>";
    echo "<form method='post'>";
    echo "<p>File path:<br><input name='path' style='width:100%' value='".htmlspecialchars($path, ENT_QUOTES)."'>";
    echo "<p>Or paste HTML:<br><textarea name='raw_html'>".htmlspecialchars($raw, ENT_QUOTES)."</textarea>";
    echo "<fieldset><legend>Post-processing</legend>";
    echo "<label><input type='checkbox' name='do_ellipses' ".($doEllipses?'checked':'')."> Normalize ellipses</label><br>";
    echo "<label><input type='checkbox' name='do_scene' ".($doScene?'checked':'')."> Insert scene breaks as &lt;hr&gt;</label><br>";
    echo "<label><input type='checkbox' name='do_abbr' ".($doAbbr?'checked':'')."> Expand abbreviations</label><br>";
    echo "<label><input type='checkbox' name='do_geo' ".($doGeo?'checked':'')."> Expand geo abbreviations</label> &nbsp; Locale: <input name='locale' value='".htmlspecialchars($locale, ENT_QUOTES)."' size='4' maxlength='5'>";
    echo "</fieldset>";
    echo "<p><button>Emit Text</button> <a href='?mode=debug'>Switch to Debug</a></form>";
    echo "<h3>Output</h3><pre>".htmlspecialchars($text, ENT_QUOTES)."</pre>";
    echo "<h1>HTML→Plain Text</h1>";
    echo "<form method='post'><p>File path:<br><input name='path' style='width:100%' value='".htmlspecialchars($path, ENT_QUOTES)."'>";
    echo "<p>Or paste HTML:<br><textarea name='raw_html'>".htmlspecialchars($raw, ENT_QUOTES)."</textarea>";
    echo "<p><button>Emit Text</button> <a href='?mode=debug'>Switch to Debug</a></form>";
    echo "<h3>Output</h3><pre>".htmlspecialchars($text, ENT_QUOTES)."</pre>";
}

function debug_page(): void {
    $path = $_POST['path'] ?? ($_GET['path'] ?? '');
    $raw  = $_POST['raw_html'] ?? '';
    $input = $path !== '' ? $path : $raw;

    // Toggles (debug form mirrors normal)
    $doEllipses = isset($_POST['do_ellipses']) ? (bool)$_POST['do_ellipses'] : CFG_DEFAULT_NORMALIZE_ELLIPSES;
    $doScene    = isset($_POST['do_scene']) ? (bool)$_POST['do_scene'] : CFG_DEFAULT_INSERT_SCENE_BREAKS;
    $doAbbr     = isset($_POST['do_abbr']) ? (bool)$_POST['do_abbr'] : CFG_DEFAULT_EXPAND_ABBR;
    $doGeo      = isset($_POST['do_geo']) ? (bool)$_POST['do_geo'] : CFG_DEFAULT_EXPAND_GEO;
    $locale     = $_POST['locale'] ?? 'US';

    $opts = default_opts();
    $opts['treat_input_as_path'] = ($path !== '');
    $opts['collect_events'] = true;

    [$rawText, $events] = $input !== '' ? html_to_text($input, default_rules(), $opts) : ['', []];

    // Post-process for display (events show raw emissions)
    $postText = $rawText;
    if ($postText !== '') {
        if ($doEllipses) { $postText = normalize_ellipses($postText); }
        $postText = normalize_dashes($postText);
        if ($doScene)    { $postText = insert_scene_breaks_as_hr($postText); }
        if ($doAbbr)     { $postText = expand_abbreviations($postText, [ 'am_pm_mode' => 'labels', 'expand_months' => true ]); }
        if ($doGeo)      { $geo = load_geo_map(); $postText = expand_geo_abbr($postText, $geo, $locale); }
        $postText = tidy_whitespace($postText);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><meta charset='utf-8'><title>HTML→Text Debug</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:20px} table{border-collapse:collapse;width:100%} th,td{border:1px solid #ddd;padding:6px 8px;font-size:13px} pre {white-space: pre-wrap;background: #f6f8fa;padding: 10px;border-radius: 8px;font-family: 'Georgia', 'Times New Roman', serif;font-size: 16px;line-height: 1.4;} fieldset{border:1px solid #ddd;border-radius:8px;padding:10px;margin:10px 0}</style>";
    echo "<h1>Debug Inspector</h1>";
    echo "<form method='post'><p>File path:<br><input name='path' style='width:100%' value='".htmlspecialchars($path, ENT_QUOTES)."'>";
    echo "<p>Or paste HTML:<br><textarea name='raw_html' style='width:100%;height:140px'>".htmlspecialchars($raw, ENT_QUOTES)."</textarea>";
    echo "<fieldset><legend>Post-processing</legend>";
    echo "<label><input type='checkbox' name='do_ellipses' ".($doEllipses?'checked':'')."> Normalize ellipses</label><br>";
    echo "<label><input type='checkbox' name='do_scene' ".($doScene?'checked':'')."> Insert scene breaks as &lt;hr&gt;</label><br>";
    echo "<label><input type='checkbox' name='do_abbr' ".($doAbbr?'checked':'')."> Expand abbreviations</label><br>";
    echo "<label><input type='checkbox' name='do_geo' ".($doGeo?'checked':'')."> Expand geo abbreviations</label> &nbsp; Locale: <input name='locale' value='".htmlspecialchars($locale, ENT_QUOTES)."' size='4' maxlength='5'>";
    echo "</fieldset>";
    echo "<p><button>Analyze</button> <a href='?'>Back to Plain</a></form>";
    echo "<h3>Final Emitted Text (post-processed)</h3><pre>".htmlspecialchars($postText, ENT_QUOTES)."</pre>";

    echo "<h3>Events (raw emissions)</h3><table><tr>
    <th>#</th><th>Type</th><th>Tag</th><th>Phase</th><th>Depth</th>
    <th>Text</th><th>Inner</th><th>Emitted</th>
    <th>emph_inherited</th><th>emph_activated_here</th>
    </tr>";

    $i = 0;
    foreach ($events as $ev) {
        $i++;
        $type  = (string)($ev['type']  ?? '');
        $tag   = (string)($ev['tag']   ?? '');
        $phase = (string)($ev['phase'] ?? '');
        $depth = (int)($ev['depth'] ?? 0);
        $text  = (string)($ev['text']  ?? '');
        $inner = (string)($ev['inner'] ?? '');
        $emit  = (string)($ev['emit']  ?? '');
        echo "<tr><td>$i</td><td>".htmlspecialchars($type, ENT_QUOTES)."</td><td>".htmlspecialchars($tag, ENT_QUOTES)."</td><td>".htmlspecialchars($phase, ENT_QUOTES)."</td><td>$depth</td><td>".htmlspecialchars($text, ENT_QUOTES)."</td><td>".htmlspecialchars($inner, ENT_QUOTES)."</td><td>".htmlspecialchars($emit, ENT_QUOTES)."</td></tr>";
    }
    echo "</table>";
    echo "<h1>Debug Inspector</h1>";
    echo "<form method='post'><p>File path:<br><input name='path' style='width:100%' value='".htmlspecialchars($path, ENT_QUOTES)."'>";
    echo "<p>Or paste HTML:<br><textarea name='raw_html' style='width:100%;height:140px'>".htmlspecialchars($raw, ENT_QUOTES)."</textarea>";
    echo "<p><button>Analyze</button> <a href='?'>Back to Plain</a></form>";

    echo "<h3>Event Log (sequential)</h3><table><tr><th>#</th><th>Type</th><th>Tag</th><th>Phase</th><th>Depth</th><th>Text</th><th>Inner</th><th>Emitted</th></tr>";
    $i = 0;
    foreach ($events as $ev) {
        $i++;
        $type  = (string)($ev['type']  ?? '');
        $tag   = (string)($ev['tag']   ?? '');
        $phase = (string)($ev['phase'] ?? '');
        $depth = (int)($ev['depth'] ?? 0);
        $text  = (string)($ev['text']  ?? '');
        $inner = (string)($ev['inner'] ?? '');
        $emit  = (string)($ev['emit']  ?? '');
$inh = (string)($ev['emph_inherited'] ?? '');
$act = (string)($ev['emph_activated_here'] ?? '');
$activeNow = (string)($ev['emph_active'] ?? ''); // only set on text rows if you enabled it

echo "<tr>
<td>$i</td>
<td>".htmlspecialchars($type, ENT_QUOTES)."</td>
<td>".htmlspecialchars($tag, ENT_QUOTES)."</td>
<td>".htmlspecialchars($phase, ENT_QUOTES)."</td>
<td>$depth</td>
<td>".htmlspecialchars($text, ENT_QUOTES)."</td>
<td>".htmlspecialchars($inner, ENT_QUOTES)."</td>
<td>".htmlspecialchars($emit, ENT_QUOTES)."</td>
<td>".htmlspecialchars($inh, ENT_QUOTES)."</td>
<td>".htmlspecialchars($act, ENT_QUOTES)."</td>
</tr>";
    }
    echo "</table>";
}

// --------------------------- CLI ---------------------------
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $file = $argv[1] ?? '';
    if (!$file) { fwrite(STDERR, "Usage: php ".basename(__FILE__)." path/to/file.html\n"); exit(1); }
    [$t] = html_to_text($file, default_rules(), default_opts());
    echo $t; exit(0);
}
