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

function processAllNames(&$txt)
	{
// find names like O’Reilly and d’Antonio -- first char can be upper or lower, second is ’, third is capitalized
	$splits = preg_split('/([A-Za-z]’[A-Za-z]{3,}) /u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	if (count($splits) > 1)
		processNamesHavingQuotes($splits);

// find normal names
	$splits = preg_split('/([^’][A-Z][a-z]) /u', $txt, NULL, PREG_SPLIT_DELIM_CAPTURE);
	if (count($splits) > 1)
		processSingleNames($splits);
	}

function processTwoNames(&$splits)
	{
	global $readBookDB;
	$c = count($splits);
	for ($x = 1; $x < $c; $x += 2)
		{
		$pos = mb_strpos($splits[$x], ' ');
		if ($pos !== false)
			{
			$wholeName = trim($splits[$x]);
			$firstName = mb_substr($wholeName, 0, $pos);
			$lastName = mb_substr($wholeName, $pos + 1);
			$lowercaseWord1 = mb_strtolower($firstName);
			if (in_DB($readBookDB, $lowercaseWord1, "verbs")
				OR in_DB($readBookDB, $lowercaseWord1, "notName"))
				continue;
			$lowercaseWord2 = mb_strtolower($lastName);
			if (in_DB($readBookDB, $lowercaseWord2, "verbs")
				OR in_DB($readBookDB, $lowercaseWord2, "notName"))
				continue;
			handleName($firstName, $lastName, $wholeName);
			handleName($lastName, "", $wholeName);
			}
		}
	}

function handleName($name, $last, $whole)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	if (str_contains($name, "Other:"))
		return;
	if ($name === "She"
		OR $name === "He")
		return;
	if (in_DB($readBookDB, $name, "males")
		OR in_DB($readBookDB, $name, $theseMales))
		{
		if (!in_DB($readBookDB, $name, $theseMales))
			{
			accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?)", $name, $last, $whole, 0, "", __LINE__, "");
			if ($last > "")
				accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?,?,?)", $last, "", $whole, 0, "", __LINE__, "");
			}
		else
			{
			$line = __LINE__;
			accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $name);
			if ($last > "")
				accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $last);
			}
		}
	elseif (in_DB($readBookDB, $name, "females")
		OR in_DB($readBookDB, $name, $theseFemales))
		{
		if (!in_DB($readBookDB, $name, $theseFemales))
			{
			accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $name, $last, $whole, 0, "", __LINE__, '');
			if ($last > "")
				accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $last, "", $whole, 0, "", __LINE__, "");
			}
		else
			{
			$line = __LINE__;
			accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $name);
			if ($last > "")
				accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $last);
			}
		}
	}

function processNamesHavingQuotes(&$splits)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	$c = count($splits);
	for ($x = 1; $x < $c; $x += 2)
		{
		$wholeName = trim($splits[$x]);
		if (str_contains($wholeName, "Other:"))
			$breakpoint = 1;
		if (mb_strpos($wholeName, "’") === false)
			continue;
		$lowercaseWord1 = mb_strtolower($wholeName);
		if (in_DB($readBookDB, $lowercaseWord1, "verbs")
			OR in_DB($readBookDB, $lowercaseWord1, "notName"))
			continue;
		if (in_DB($readBookDB, $wholeName, "males"))
			{
			if (!in_DB($readBookDB, $wholeName, $theseMales))
				accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				{
				$line = __LINE__;
				accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
				}
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			if (!in_DB($readBookDB, $wholeName, $theseFemales))
				accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		}
	}

function processSingleNames(&$splits)
	{
	global $readBookDB;
	global $theseMales;
	global $theseFemales;
	$c = count($splits);
	for ($x = 1; $x < $c; $x += 2)
		{
		$wholeName = trim($splits[$x]);
		if (str_contains($wholeName, "Other:"))
			$breakpoint = 1;
		if ($wholeName === "She"
			OR $wholeName === "He")
			continue;
		$lowercaseWord1 = mb_strtolower($wholeName);
		if (in_DB($readBookDB, $lowercaseWord1, "verbs")
			OR in_DB($readBookDB, $lowercaseWord1, "notName"))
			continue;
		if (in_DB($readBookDB, $wholeName, "males"))
			{
			if (!in_DB($readBookDB, $wholeName, $theseMales))
				accessDB($readBookDB, "REPLACE INTO $theseMales VALUES(?, ?, ?, ?, ?, ?, ?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				{
				$line = __LINE__;
				accessDB($readBookDB, "UPDATE $theseMales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
				}
			continue;
			}
		elseif (in_DB($readBookDB, $wholeName, "females"))
			{
			$line = __LINE__;
			if (!in_DB($readBookDB, $wholeName, $theseFemales))
				accessDB($readBookDB, "REPLACE INTO $theseFemales VALUES(?, ?, ?, ?, ?,?,?)", $wholeName, "", $wholeName, 0, "", __LINE__, "");
			else
				accessDB($readBookDB, "UPDATE $theseFemales SET count=count+1, updatingLines=$line WHERE label = ?", $wholeName);
			continue;
			}
		}
	}
