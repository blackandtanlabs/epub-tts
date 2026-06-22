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
function emitClosingEntities ($haystack, $openNeedle, $closeNeedle)
    {
    $open = substr_count($haystack, $openNeedle);
    $closed = substr_count($haystack, $closeNeedle);
    $needed = $open - $closed;
    while ($needed)
        {
        $needed--;
        echo $closeNeedle; 
        }
    }
function cleanSomeHTML ($str)
    {
    // remove class="font-size:*.px";
    if (substr_count($str,"font-family", 0) > 0)
        {                                    
        $whatHappened = 1;
        }
    $text = preg_replace('~(font-size:.*px;)~', "", $str);
    $text = preg_replace('~(font-size:.*em;)~', "", $text);
    $text = str_replace('font-family', "funt-famoly", $text);
    // replace <h> tags
    $Hs = array("<h1>", "<h2>", "<h3>", "<h4>", "<h5>", "<h6>");
    $new = str_replace($Hs, "<h3>", $text);
    $endHs = array("</h1>", "</h2>", "</h3>", "</h4>", "</h5>", "</h6>");
    $new = str_replace($endHs, "</h3>", $new);
    $new = str_replace ('<code>', '', $new);
    $new = str_replace ('</code>', '', $new);
    // following are to be able to set a breakpoint          
    if (strlen($new) < 50)
        {
        $whatHappened = 1;
        }
    if ($new != $str)
        {
        return $new;
        }
    else
        {
        return $str;
        }
    }
function outputMissingEntities($comment)
    {
//    emitClosingEntities($comment, "<em>", "</em>");
    emitClosingEntities($comment, "<i>", "</i>");
    emitClosingEntities($comment, "<strong>", "</strong>");
//  emitClosingEntities($comment, "<p>", "</p>");
//  emitClosingEntities($comment, "<h3>", "</h3>");
    emitClosingEntities($comment, "<div>", "</div>");
    }
?>
