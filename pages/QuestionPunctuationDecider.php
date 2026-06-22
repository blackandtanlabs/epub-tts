<?php

//declare(strict_types=1);

/**
 * QuestionPunctuationDecider.php — v1.1
 * Adds isFirmEcho() to down-bias “firm check” echo-questions (e.g., “So you did what.”).
 * Also tidies tag detection return values.
 */
final class QuestionPunctuationDecider
	{

	/** @var array<string, mixed> */
	private array $cfg;

	/** @var string[] */
	private array $whWords = [
		'who', 'what', 'when', 'where', 'why', 'which', 'how', 'whose', 'whom'
	];

	/** @var string[] */
	private array $auxStarts = [
		'do', 'does', 'did', 'is', 'are', 'was', 'were', 'have', 'has', 'had',
		'can', 'could', 'will', 'would', 'shall', 'should', 'may', 'might', 'must'
	];

	/** @var string[] */
	private array $negations = ["n't", "not", "never", "no"];

	/** @var string[] */
	private array $uncertaintyHedges = [
		'maybe', 'perhaps', 'i guess', 'i suppose', 'i’m not sure', 'im not sure',
		'not sure', 'do you think', 'by any chance', 'at all', 'possibly',
		'i wonder', 'kind of', 'sort of', 'could it be', 'i think?', 'i think ?'
	];

	/** @var string[] */
	private array $confirmers = [
		'obviously', 'clearly', 'of course', 'surely', 'after all', 'exactly', 'already', 'just'
	];

	/** @var string[] */
	private array $leadingDiscourse = ['well', 'so', 'look', 'you know', 'i mean', 'uh', 'hey', 'okay', 'ok', 'alright'];

	/** @var array<string,string> */
	private array $tagMap = [
		"isn't it" => "isn't it",
		"isnt it" => "isn't it",
		"aren't you" => "aren't you",
		"arent you" => "aren't you",
		"don't you" => "don't you",
		"dont you" => "don't you",
		"didn't you" => "didn't you",
		"didnt you" => "didn't you",
		"right" => "right",
		"correct" => "correct",
		"okay" => "okay",
		"ok" => "ok",
		"yeah" => "yeah",
		"ya" => "ya",
		"eh" => "eh"
	];

	/**
	 * @param array{keep_threshold?:int, remove_threshold?:int} $cfg
	 */
	public function __construct(array $cfg = [])
		{
		$this->cfg = [
			'keep_threshold' => $cfg['keep_threshold'] ?? 1,
			'remove_threshold' => $cfg['remove_threshold'] ?? 0,
		];
		}

	/**
	 * Analyze a single sentence ending with a question mark.
	 *
	 * @return array{
	 *   action:'keep'|'remove'|'ask'|'split',
	 *   score:int,
	 *   type:string,
	 *   rationale:string[],
	 *   parts:string[],
	 *   original:string,
	 *   emphatic:bool,
	 *   tag_question:?string
	 * }
	 */
	public function analyzeQuestion(string $sentence): array
		{
		$orig = $sentence;
		$s = trim($sentence);

		if (!preg_match('/\?+\s*["’”\)\]]*$/u', $s))
			{
			return [
				'action' => 'remove',
				'score' => 0,
				'type' => 'non_question',
				'rationale' => ['no_trailing_question_mark'],
				'parts' => [$this->withPeriod($this->stripTerminalPunct($s))],
				'original' => $orig,
				'emphatic' => false,
				'tag_question' => null,
			];
			}

		[$core, $emphatic] = $this->normalizeTerminal($s);

		// Split leading discourse like "Well," → separate sentence
		$leadSplit = $this->splitLeadingDiscourse($core);
		if ($leadSplit !== null)
			{
			[$lead, $rest] = $leadSplit;
			$decision = $this->analyzeQuestion($this->ensureQM($rest));
			array_unshift($decision['parts'], $this->withPeriod($this->stripTerminalPunct($lead)));
			$decision['action'] = 'split';
			$decision['rationale'][] = 'split:leading_discourse';
			$decision['emphatic'] = $decision['emphatic'] || $emphatic;
			$decision['original'] = $orig;
			return $decision;
			}

		// Tag question?
		$tagInfo = $this->detectTagQuestion($core);
		if ($tagInfo['tag'] !== null)
			{
			$baseClause = $tagInfo['base'];
			$tagClause = $tagInfo['tag'];

			[$type, $score, $rationale] = $this->scoreByTypeAndModifiers($core, 'tag', $emphatic);

			if ($this->containsAny($baseClause, $this->confirmers))
				{
				$score -= 1;
				$rationale[] = 'neg:confirmers_in_base';
				}

			$action = $this->actionFromScore($score);

			$parts = [$this->withPeriod($this->stripTerminalPunct($baseClause))];
			if ($action === 'keep')
				{
				$parts[] = $this->ensureQM($this->stripTerminalPunct($tagClause));
				}
			elseif ($action === 'remove')
				{
				$parts[] = $this->withPeriod($this->stripTerminalPunct($tagClause));
				}
			else
				{
				$parts[] = $this->stripTerminalPunct($tagClause) . ' (?)';
				}

			return [
				'action' => 'split',
				'score' => $score,
				'type' => 'tag',
				'rationale' => $rationale,
				'parts' => $parts,
				'original' => $orig,
				'emphatic' => $emphatic,
				'tag_question' => $tagInfo['tag_key'],
			];
			}

		// Embedded (statements posing as questions)
		if ($this->isEmbedded($core))
			{
			return [
				'action' => 'remove',
				'score' => -2,
				'type' => 'embedded',
				'rationale' => ['embedded_statement_wh'],
				'parts' => [$this->withPeriod($this->stripTerminalPunct($core))],
				'original' => $orig,
				'emphatic' => $emphatic,
				'tag_question' => null,
			];
			}

		$type = $this->classify($core);
		[$type, $score, $rationale] = $this->scoreByTypeAndModifiers($core, $type, $emphatic);
		$action = $this->actionFromScore($score);

		$splitParts = $this->maybeSplitParenthetical($core);
		$parts = [];

		if (!empty($splitParts))
			{
			foreach ($splitParts as $chunk)
				{
				if ($this->endsWithQM($chunk))
					{
					$bare = $this->stripTerminalPunct($chunk);
					if ($action === 'keep')
						$parts[] = $this->ensureQM($bare);
					elseif ($action === 'remove')
						$parts[] = $this->withPeriod($bare);
					else
						$parts[] = $bare . ' (?)';
					}
				else
					{
					$parts[] = $this->withPeriod($this->stripTerminalPunct($chunk));
					}
				}
			$finalAction = 'split';
			}
		else
			{
			$bare = $this->stripTerminalPunct($core);
			if ($action === 'keep')
				$parts[] = $this->ensureQM($bare);
			elseif ($action === 'remove')
				$parts[] = $this->withPeriod($bare);
			else
				$parts[] = $bare . ' (?)';
			$finalAction = $action;
			}

		return [
			'action' => $finalAction,
			'score' => $score,
			'type' => $type,
			'rationale' => $rationale,
			'parts' => $parts,
			'original' => $orig,
			'emphatic' => $emphatic,
			'tag_question' => null,
		];
		}

	// ---------------------------- Scoring ----------------------------

	/** @return array{0:string,1:int,2:string[]} */
	private function scoreByTypeAndModifiers(string $core, string $type, bool $emphatic): array
		{
		$rationale = [];
		$score = 0;

		switch ($type)
			{
			case 'wh': $score += 0;
				$rationale[] = 'base:wh=0';
				break;
			case 'wh_neg': $score += 0;
				$rationale[] = 'base:wh_neg=0';
				break;
			case 'yn': $score += 1;
				$rationale[] = 'base:yn=+1';
				break;
			case 'yn_neg': $score += 0;
				$rationale[] = 'base:yn_neg=0';
				break;
			case 'declarative': $score += 1;
				$rationale[] = 'base:declarative=+1';
				break;
			case 'tag': $score += 1;
				$rationale[] = 'base:tag=+1';
				break;
			default: $rationale[] = 'base:unknown=0';
			}

		$lc = $this->lcNoQuotes($core);

		// Positive
		if ($this->containsAny($lc, $this->uncertaintyHedges))
			{
			$score += 1;
			$rationale[] = 'pos:uncertainty_hedge=+1';
		}
		if ($emphatic)
			{
			$score += 1;
			$rationale[] = 'pos:emphatic_punct=+1';
		}
		if (preg_match('/\b(or not|or what|right|okay|ok)\s*$/ui', $lc))
			{
			$score += 1;
			$rationale[] = 'pos:rising_cue=+1';
		}
		if ($this->looksElliptical($lc))
			{
			$score += 1;
			$rationale[] = 'pos:elliptical=+1';
		}

		// Negative
		if ($this->containsAny($lc, $this->confirmers))
			{
			$score -= 1;
			$rationale[] = 'neg:confirmers=−1';
		}
		if ($type === 'yn_neg' && preg_match('/\b(already|exactly|after all|just)\b/u', $lc))
			{
			$score -= 1;
			$rationale[] = 'neg:yn_neg_confirmatory=−1';
			}
		if (preg_match('/\b(why bother|who cares|anyway)\b/u', $lc))
			{
			$score -= 1;
			$rationale[] = 'neg:rhetorical=−1';
			}
		if ($this->isImperativeWh($lc))
			{
			$score -= 2;
			$rationale[] = 'neg:imperative_wh=−2';
			}
		if ($this->isFirmEcho($lc))
			{ // <-- now implemented
			$score -= 1;
			$rationale[] = 'neg:firm_echo=−1';
			}

		return [$type, $score, $rationale];
		}

	private function actionFromScore(int $score)
		{
		if ($score >= (int) $this->cfg['keep_threshold'])
			return 'keep';
		if ($score <= (int) $this->cfg['remove_threshold'])
			return 'remove';
		return 'ask';
		}

	// ---------------------- Classification & detect ----------------------

	private function classify(string $core): string
		{
		$lc = $this->lcNoQuotes($core);

		if (preg_match('/^\s*(?:["“‘\(\[]\s*)?(who|what|when|where|why|which|how|whose|whom)\b/ui', $lc))
			{
			if ($this->containsAny($lc, $this->negations))
				return 'wh_neg';
			return 'wh';
			}

		if (preg_match('/^\s*(?:["“‘\(\[]\s*)?(do|does|did|is|are|was|were|have|has|had|can|could|will|would|shall|should|may|might|must)\b/ui', $lc))
			{
			if ($this->containsAny($lc, $this->negations))
				return 'yn_neg';
			return 'yn';
			}

		return 'declarative';
		}

	private function isEmbedded(string $core): bool
		{
		$lc = $this->lcNoQuotes($core);
		if (preg_match('/^\s*(i|we|they|he|she)\s+(wonder|asked?|know|knew|remember|remembered|suppose|think|thought)\b/ui', $lc))
			{
			return true;
			}
		if ($this->isImperativeWh($lc))
			return true;
		return false;
		}

	/**
	 * Detect trailing tag questions: ", <tag>?"
	 * @return array{base:string, tag:?string, tag_key:?string}
	 */
	private function detectTagQuestion(string $core): array
		{
		if (!preg_match('/,(.+?)\?+\s*["’”\)\]]*$/u', $core, $m))
			{
			return ['base' => $core, 'tag' => null, 'tag_key' => null];
			}
		$rawTag = trim($m[1]);
		$tagKey = $this->normalizeTagKey($rawTag);
		if ($tagKey === null)
			{
			return ['base' => $core, 'tag' => null, 'tag_key' => null];
			}
		$cutPos = strrpos($core, ',' . $m[1]);
		$base = $cutPos !== false ? trim(substr($core, 0, $cutPos)) : $core;
		return ['base' => $base, 'tag' => $this->tagMap[$tagKey] ?? $rawTag, 'tag_key' => $tagKey];
		}

	private function normalizeTagKey(string $tag): ?string
		{
		$k = strtolower(trim($this->stripQuotes($tag)));
		$k = preg_replace('/\s+/', ' ', $k ?? '');
		return array_key_exists($k, $this->tagMap) ? $k : null;
		}

	private function isImperativeWh(string $lc): bool
		{
		return (bool) preg_match(
				'/^\s*(tell|explain|show|describe|demonstrate|clarify|prove|indicate|outline|list)\s+(me\s+)?(why|how|where|when|what|which|who|whom|whose)\b/u',
				$lc
			);
		}

	/**
	 * Heuristic: detect “firm check” echo-questions that tend to take a falling contour.
	 * Examples: “So you did what?”, “And he went where?”, “You said what?”
	 * Returns true only when BOTH:
	 *  (a) it ends in a bare wh-word (what/where/who/when/why/how), and
	 *  (b) the pre-wh clause looks like a declarative/accusatory frame (aux+verb/past verb/copula)
	 *      with firmness cues like “so/and/then”, “really/just/actually/already/exactly”.
	 */
	private function isFirmEcho(string $lc): bool
		{
		// Must end with bare wh-word (no trailing content after it)
		if (!preg_match('/\b(what|where|who|when|why|how)\s*$/u', $lc))
			{
			return false;
			}

		// Declarative-like frame with verb/copula before the wh-word
		$hasDeclarativeFrame = preg_match('/\b(you|he|she|they|we|i)\s+(?:really\s+|just\s+|actually\s+)?' .
				'(?:did|said|told|went|was|were|are|is|have|has|had|took|bought|spent|paid|brought|made|broke|lied|cheated|started|stopped|kept|forgot|knew|realized|ate|drank|slept|thought|left|called|texted|emailed)\b/u', $lc
			) ||
			preg_match('/\b(you|he|she|they|we|i)\s+(?:really\s+|just\s+|actually\s+)?' .
				'(?:are|is|was|were)\s+\b(who|what|where|when|why|how)\b/u', $lc
			);

		if (!$hasDeclarativeFrame)
			return false;

		// Firm/accusatory cues anywhere in clause
		$firmCues = '/\b((so|and|then)\s+(you|he|she|they)|really|just|actually|already|exactly|after all|right now|so to be clear|to be clear|let me get this straight)\b/u';
		if (preg_match($firmCues, $lc))
			return true;

		// Also treat patterns that begin with “So/And/Then” + pronoun as firm even without extra adverbs
		if (preg_match('/^\s*(so|and|then)\s+(you|he|she|they)\b/u', $lc))
			return true;

		return false;
		}

	private function looksElliptical(string $lc): bool
		{
		$wcount = str_word_count($lc);
		if ($wcount <= 2)
			return true;
		if (preg_match('/^\s*(who|what|when|where|why|which|how)\s*$/u', $lc))
			return true;
		if (preg_match('/^\s*(really|seriously|honestly)\s*$/u', $lc))
			return true;
		return false;
		}

	private function endsWithQM(string $s): bool
		{
		return (bool) preg_match('/\?+\s*["’”\)\]]*$/u', trim($s));
		}

	// ---------------------------- Splitting ----------------------------

	private function splitLeadingDiscourse(string $core): ?array
		{
		$lc = $this->lcNoQuotes($core);
		foreach ($this->leadingDiscourse as $lead)
			{
			$pattern = '/^\s*(?:["“‘\(\[]\s*)?' . preg_quote($lead, '/') . '\s*,\s*/ui';
			if (preg_match($pattern, $lc, $m))
				{
				if (preg_match($pattern, $core, $m2))
					{
					$splitPos = strlen($m2[0]);
					$leadPart = trim(substr($core, 0, $splitPos));
					$rest = trim(substr($core, $splitPos));
					return [$leadPart, $rest];
					}
				}
			}
		return null;
		}

	private function maybeSplitParenthetical(string $core): array
		{
		$parts = [];
		$s = $core;

		if (preg_match('/^(.*)—(.*)—(.*)$/u', $s, $m))
			{
			$pre = trim($m[1]);
			$paren = trim($m[2]);
			$post = trim($m[3]);
			if (str_word_count($paren) >= 8)
				{
				$main = trim($pre . ' ' . $post);
				if ($main !== '')
					$parts[] = $this->ensureQM($this->stripTerminalPunct($main));
				if ($paren !== '')
					$parts[] = $this->withPeriod($this->stripTerminalPunct($paren));
				return $parts;
				}
			}

		if (preg_match('/^(.*),([^,]{15,}),(.*)$/u', $s, $m))
			{
			$pre = trim($m[1]);
			$paren = trim($m[2]);
			$post = trim($m[3]);
			if (str_word_count($paren) >= 8)
				{
				$main = trim($pre . ' ' . $post);
				if ($main !== '')
					$parts[] = $this->ensureQM($this->stripTerminalPunct($main));
				if ($paren !== '')
					$parts[] = $this->withPeriod($this->stripTerminalPunct($paren));
				return $parts;
				}
			}

		return [];
		}

	// ---------------------------- Utilities ----------------------------

	private function stripTerminalPunct(string $s): string
		{
		return rtrim(preg_replace('/[?!\.\s"’”\)\]]+$/u', '', $s) ?? '');
		}

	private function withPeriod(string $s): string
		{
		$s = rtrim($s);
		return $s === '' ? $s : $s . '.';
		}

	private function ensureQM(string $s): string
		{
		$s = rtrim($s);
		return $s === '' ? $s : $s . '?';
		}

	/** @return array{0:string,1:bool} */
	private function normalizeTerminal(string $s): array
		{
		$emphatic = (bool) preg_match('/(\?\?|\?\!|\!\?)\s*["’”\)\]]*$/u', $s);
		$core = $this->stripTerminalPunct($s);
		return [$core, $emphatic];
		}

	private function stripQuotes(string $s): string
		{
		return trim($s, " \t\n\r\0\x0B\"'“”‘’()[]");
		}

	private function lcNoQuotes(string $s): string
		{
		return mb_strtolower($this->stripQuotes($s));
		}

	private function containsAny(string $hay, array $needles): bool
		{
		foreach ($needles as $n)
			{
			$nq = preg_quote($n, '/');
			if (preg_match('/\b' . $nq . '\b/u', $hay))
				return true;
			}
		return false;
		}

	// ---------------------------- Batch helper ----------------------------

	public function analyzeMany(array $sentences): array
		{
		return array_map(fn($s) => $this->analyze((string) $s), $sentences);
		}
	}
