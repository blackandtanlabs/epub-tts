<?php
class DigitToWordsConverter
	{
	// note that "mixed" pronunciation goes in pronounce database
//	private $mixed= [
//		'F-150' => 'F one fifty',
//		'F-250' => 'F two fifty',
//		'F-350' => 'F three fifty',
//		'M16' => 'M sixteen',
//		'AK-47' => 'eh K forty seven',
//		'B-52' => 'B fifty two',
//		'24/7' => 'twenty four seven',
//		'7-11' => 'seven eleven',
//		'7-eleven' => 'seven eleven'
//		];
	private $specialNumbers = [
		'911' => 'nine one one',
		'311' => 'three one one',
		'411' => 'four one one',
		'511' => 'five one one',
		'211' => 'two one one',
		'988' => 'nine eight eight',
		'007' => 'double oh seven',
		'707' => 'seven oh seven',
		'747' => 'seven forty seven',
		'757' => 'seven fifty seven',
		'767' => 'seven sixty seven',
		'777' => 'seven seventy seven'
	];
	private $ones = [
		0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
		5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
		10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen',
		14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen',
		18 => 'eighteen', 19 => 'nineteen'
	];
	private $tens = [
		20 => 'twenty', 30 => 'thirty', 40 => 'forty', 50 => 'fifty',
		60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety'
	];
	private $ordinal_ones = [
		1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth',
		6 => 'sixth', 7 => 'seventh', 8 => 'eighth', 9 => 'ninth', 10 => 'tenth',
		11 => 'eleventh', 12 => 'twelfth', 13 => 'thirteenth', 14 => 'fourteenth',
		15 => 'fifteenth', 16 => 'sixteenth', 17 => 'seventeenth', 18 => 'eighteenth',
		19 => 'nineteenth'
	];
	private $ordinal_tens = [
		20 => 'twentieth', 30 => 'thirtieth', 40 => 'fortieth', 50 => 'fiftieth',
		60 => 'sixtieth', 70 => 'seventieth', 80 => 'eightieth', 90 => 'ninetieth'
	];
	private $months = [
		1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
		5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
		9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
	];
	public function convertSentence($sentence)
		{
		$patterns = [
			// Currency - high certainty
			'/\$([0-9,]+)\.([0-9]{2})/u' => [$this, 'convertCurrency'],
			'/\$([0-9,]+)/u' => [$this, 'convertDollarsOnly'],
			// Highway numbers - medium to high certainty
			'/\bI-([0-9]+)\b/' => [$this, 'convertInterstate'],
			'/\bUS-([0-9]+)\b/' => [$this, 'convertUSRoute'],
			'/\bRoute\s+([0-9]+)\b/i' => [$this, 'convertRoute'],
			'/\bSR-([0-9]+)\b/' => [$this, 'convertStateRoute'],
			// Ordinals - high certainty
			'/\b([0-9]+)(st|nd|rd|th)\b/' => [$this, 'convertOrdinal'],
			// Calibre - high certainty
			'/\ \.(\d{2})/u' => [$this, 'convertCalibre'],
			'/\ (\d+)\-*?mm/iu' => [$this, 'convertCalibreMM'],
			// Decimal numbers - medium certainty
			'/\ (\d*?)\.([0-9]+)\b/u' => [$this, 'convertDecimal'],
			// Dates - high certainty for full dates, medium for partial
			'/([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{2,4})/u' => [$this, 'convertDate'],
			'/([0-9]{1,2})\/([0-9]{1,2})/u' => [$this, 'convertPartialDate'],
			// Comma-separated numbers - medium certainty
			'/\b([0-9]{1,3}(?:,[0-9]{3})+)\b/u' => [$this, 'convertCommaNumber'],
			// Time - medium certainty
			'/\b([0-9]{1,2}):([0-9]{2})\s*(A\.*?M|P\.*?M|a\.*?m|p\.*?m)?\b/' => [$this, 'convertTime'],
			// Phone numbers - high certainty
			'/\(([0-9]{3})\)\s*([0-9]{3})-([0-9]{4})/u' => [$this, 'convertPhone'],
			'/([0-9]{3})-([0-9]{3})-([0-9]{4})/u' => [$this, 'convertPhone2'],
			// Percentages - high certainty
			'/\b([0-9]+(?:\.[0-9]+)?)%\b/u' => [$this, 'convertPercentage'],
			// 4-digit number /(\b\d{4})/u
			'/(\b\d{4})/u' => [$this, 'four_digit'],
			// 3-digit number
			'/(\b[0-9]{3})/u' => [$this, 'three_digit'],
			// Plain numbers - low certainty (catch-all)
			'/\s([0-9]+)\s/u' => [$this, 'convertPlainNumber'],
			];
		$result = $sentence;
		$conversions = [];
		foreach ($patterns as $pattern => $callback)
			{
			$result = preg_replace_callback($pattern, function ($matches) use ($callback, &$conversions)
				{
				$conversion = call_user_func($callback, $matches);
				$original = $matches[0];
				$pronounce = $conversion['text'];
				// Two paired commands: ⦃p:<pronounce>⦄⦃d:<display>⦄
				$wrapped = '⦃p:' . $pronounce . '⦄';
				// Log it
				$conversions[] = [
					'original' => $original,
					'converted' => $pronounce,
					'certainty' => $conversion['certainty'],
					'type' => $conversion['type'],
				];
				return $wrapped;
				}, $result);
			}

		return [
			'converted_sentence' => $result,
			'conversions' => $conversions
		];
		}
	private function convertCalibre($matches)
		{
		$calibre = $matches[1];
		$text = $this->numberToWords($calibre);
		return ['text' => $text, 'certainty' => 95, 'type' => 'calibre'];
		}
	private function convertCalibreMM($matches)
		{
		$calibre = $matches[1];
		$text = $this->numberToWords($calibre) . " millimeter ";
		return ['text' => $text, 'certainty' => 95, 'type' => 'calibre'];
		}
	private function convertCurrency($matches)
		{
		$dollars = str_replace(',', '', $matches[1]);
		$cents = $matches[2];
		$text = $this->numberToWords($dollars);
//		if ($dollars != 1)
//			$text .= 's';
		if ($cents != '00')
			$centsSuffix = ' ' . $this->numberToWords($cents) . " ";
		else
			{
			$centsSuffix = " dollar";
			if ($dollars>0)
				$centsSuffix .= "s";
			$centsSuffix .= " ";
			}
		if ($dollars > 0)
			$text .= $centsSuffix;
		else
			{
			$text = $centsSuffix . "cent";
			if ($cents > 1)
				$text .= "s";
			$text .= " ";
			}
		return ['text' => $text, 'certainty' => 95, 'type' => 'currency'];
		}
	private function convertDollarsOnly($matches)
		{
		$dollars = str_replace(',', '', $matches[1]);
		$text = $this->numberToWords($dollars) . ' dollar';
		if ($dollars != 1)
			$text .= 's';
		return ['text' => $text, 'certainty' => 90, 'type' => 'currency'];
		}
	private function convertDate($matches)
		{
		$month = (int) $matches[1];
		$day = (int) $matches[2];
		$year = (int) $matches[3];
		if ($month > 12 || $day > 31)
			{
			return ['text' => $matches[0], 'certainty' => 10, 'type' => 'unknown'];
			}

		$text = $this->months[$month] . ' ' . $this->numberToOrdinal($day) . ', ' . $this->numberToWords($year);
		return ['text' => $text, 'certainty' => 85, 'type' => 'date'];
		}
	private function convertPartialDate($matches)
		{
		$first = (int) $matches[1];
		$second = (int) $matches[2];
		// Assume month/day format if first number <= 12
		if ($first <= 12 && $second <= 31)
			{
			$text = $this->months[$first] . ' ' . $this->numberToOrdinal($second);
			return ['text' => $text, 'certainty' => 60, 'type' => 'date'];
			}

		// Could be a fraction or route - low certainty
		return ['text' => $matches[0], 'certainty' => 30, 'type' => 'ambiguous'];
		}
	private function four_digit($matches)
		{
		$first = $matches[1];
		$first2 = (int) mb_substr($first, 0, 2);
		$last2 = (int) mb_substr($first, 2, 4);
		if ($last2 === 0)
			$text = $this->numberToWords($first2) . ' hundred';
		elseif ($last2 < 10)
			$text = $this->numberToWords($first2) . ' o ' . $this->numberToWords($last2);
		else
			$text = $this->numberToWords($first2) . ' ' . $this->numberToWords($last2);
		return ['text' => $text, 'certainty' => 75, 'type' => 'date'];
		}
	private function three_digit($matches)
		{
		$first = $matches[1];
		// First check hard-coded exceptions
		if (isset($this->specialNumbers[$first]))
			{
			$txt = $this->specialNumbers[$first];
			return ['text' =>$txt, 'certainty' => 100, 'type' => 'special'];
			}

		// Then check for repdigits (111, 222, …, 999)
		if (preg_match('/^(\d)\1{2}$/', $first))
			{
			$txt = implode(' ', array_map([$this, 'digitToWord'], str_split($first)));
			return ['text' => $txt, 'certainty' => 90, 'type' => 'repdigit'];
			}

		$first2 = (int) mb_substr($first, 0, 1);
		$last2 = (int) mb_substr($first, 1, 2);
		$second = (int) mb_substr($first, 1, 1);
		if ($last2 === 0)
			$text = $this->numberToWords($first2) . ' hundred';
		elseif ($second === 0)
			$text = $this->numberToWords($first2) . ' oh ' . $this->numberToWords($last2);
		else
			$text = $this->numberToWords($first2) . ' ' . $this->numberToWords($last2);
		return ['text' => $text, 'certainty' => 75, 'type' => 'date'];
		}
	private function convertInterstate($matches)
		{
		$number = $matches[1];
		$text = 'I ' . $this->numberToWords($number, true);
		return ['text' => $text, 'certainty' => 90, 'type' => 'highway'];
		}
	private function convertUSRoute($matches)
		{
		$number = $matches[1];
		$text = 'Route ' . $this->numberToWords($number, true);
		return ['text' => $text, 'certainty' => 90, 'type' => 'highway'];
		}
	private function convertRoute($matches)
		{
		$number = $matches[1];
		$text = 'Route ' . $this->numberToWords($number, true);
		return ['text' => $text, 'certainty' => 75, 'type' => 'highway'];
		}
	private function convertStateRoute($matches)
		{
		$number = $matches[1];
		$text = 'Route ' . $this->numberToWords($number, true);
		return ['text' => $text, 'certainty' => 85, 'type' => 'highway'];
		}
	private function convertOrdinal($matches)
		{
		$number = (int) $matches[1];
		$text = $this->numberToOrdinal($number);
		return ['text' => $text, 'certainty' => 95, 'type' => 'ordinal'];
		}
	private function convertTime($matches)
		{
		$hour = (int) $matches[1];
		$minute = (int) $matches[2];
		$ampm = isset($matches[3]) ? ' ' . strtoupper($matches[3]) : '';
		$hourText = $this->numberToWords($hour);
		if ($minute == 0)
			{
			$text = $hourText . " o'clock" . $ampm;
			}
		else
			{
			$minuteText = $this->numberToWords($minute);
			$text = $hourText . ' ' . $minuteText . $ampm;
			}

		return ['text' => $text, 'certainty' => 80, 'type' => 'time'];
		}
	private function convertPhone($matches)
		{
		$area = str_split($matches[1]);
		$prefix = str_split($matches[2]);
		$number = str_split($matches[3]);
		$text = implode(' ', array_map([$this, 'digitToWord'], $area)) . ', ' .
			implode(' ', array_map([$this, 'digitToWord'], $prefix)) . ', ' .
			implode(' ', array_map([$this, 'digitToWord'], $number));
		return ['text' => $text, 'certainty' => 95, 'type' => 'phone'];
		}
	private function convertPhone2($matches)
		{
		$area = str_split($matches[1]);
		$prefix = str_split($matches[2]);
		$number = str_split($matches[3]);
		$text = implode(' ', array_map([$this, 'digitToWord'], $area)) . ', ' .
			implode(' ', array_map([$this, 'digitToWord'], $prefix)) . ', ' .
			implode(' ', array_map([$this, 'digitToWord'], $number));
		return ['text' => $text, 'certainty' => 90, 'type' => 'phone'];
		}
	private function convertPercentage($matches)
		{
		$number = $matches[1];
		$text = $this->numberToWords($number) . ' percent';
		return ['text' => $text, 'certainty' => 95, 'type' => 'percentage'];
		}
	private function convertDecimal($matches)
		{
		$whole = $matches[1];
		$decimal = $matches[2];
		if ($whole === "")
			$whole = "0";
		$text = $this->numberToWords($whole) . ' point ' .
			implode(' ', array_map([$this, 'digitToWord'], str_split($decimal)));
		return ['text' => $text, 'certainty' => 70, 'type' => 'decimal'];
		}
	private function convertCommaNumber($matches)
		{
		$number = str_replace(',', '', $matches[1]);
		$text = $this->numberToWords($number);
		return ['text' => $text, 'certainty' => 75, 'type' => 'number'];
		}
	private function convertPlainNumber($m)
		{
		$original = $m[0];
		$num = $m[1];
		// First check hard-coded exceptions
		if (isset($this->specialNumbers[$num]))
			{
			$txt = $this->specialNumbers[$num];
			return ['text' =>$txt, 'certainty' => 100, 'type' => 'special'];
			}

		// Then check for repdigits (111, 222, …, 999)
		if (preg_match('/^(\d)\1{2}$/', $num))
			{
			$txt = implode(' ', array_map([$this, 'digitToWord'], str_split($num)));
			return ['text' => $txt, 'certainty' => 90, 'type' => 'repdigit'];
			}

		// Default: spell normally
		$txt = $this->numberToWords($num) . " ";
		return ['text' => $txt, 'certainty' => 40, 'type' => 'number'];
		}
//	private function convertPlainNumber($matches)
//		{
//		$number = $matches[1];
//		if ($number === "911")
//			$text = "nine one one";
//		else
//			$text = $this->numberToWords($number);
//		return ['text' => $text, 'certainty' => 40, 'type' => 'number'];
//		}
	private function numberToWords($number, $isHighway = false)
		{
		$n = (string)$number;
		$num = (int) $number;
		if ($num == 0)
			return 'zero ';

		// For highways, some special cases
		if (strlen($n) == 3 && $n[1] == '0')
			{
			// Like 101 -> "one oh one"
			return $this->digitToWord($n[0]) . ' oh ' . $this->digitToWord($n[2]);
			}

		$result = ' ';
		if ($num >= 1000000)
			{
			$millions = intval($num / 1000000);
			$result .= $this->numberToWords($millions) . ' million ';
			$num %= 1000000;
			}

		if ($num >= 1000)
			{
			$thousands = intval($num / 1000);
			$result .= $this->numberToWords($thousands) . ' thousand ';
			$num %= 1000;
			}

		if ($num >= 100)
			{
			$hundreds = intval($num / 100);
//			$result .= $this->ones[$hundreds] . ' hundred ';
			$result .= $this->ones[$hundreds] . ' ';
			$num %= 100;
			}

		if ($num >= 20)
			{
			$tensDigit = intval($num / 10) * 10;
			$result .= $this->tens[$tensDigit];
			$num %= 10;
			if ($num > 0)
				{
				$result .= ' ' . $this->ones[$num] . ' ';
				}
			}
		elseif ($num > 0)
			{
			$result .= $this->ones[$num] . " ";
			}

		return $result;
		}
	private function numberToOrdinal($number)
		{
		$num = (int) $number;
		// Handle special cases for ordinals
		if ($num < 20 && isset($this->ordinal_ones[$num]))
			return $this->ordinal_ones[$num];
		if ($num >= 20
		AND $num <= 99)
			{
			$tensDigit = intval($num / 10) * 10;
			$onesDigit = $num % 10;
			if ($onesDigit == 0 && isset($this->ordinal_tens[$tensDigit]))
				return $this->ordinal_tens[$tensDigit] . " ";
			else
				return $this->tens[$tensDigit] . ' ' . $this->ordinal_ones[$onesDigit] . " ";
			}

		$num = (int) mb_substr($number, -2);
		$base = $this->numberToOrdinal($num);
		$upper = (string)(($number - $num) / 100);
		$upperBase = $this->numberToWords($upper);
		if ($num < 10)
			$upperBase .= " hundred ";
		return($upperBase . $base);
		}
	private function digitToWord($digit)
		{
		$digits = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
		return $digits[(int) $digit] . " ";
		}
	}
?>